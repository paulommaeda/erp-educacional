<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,AdmissionForm,StudentFiles};
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy,StudentRecordPolicy,StudentDocumentFiles,Accounts,AdmissionNotifications};
final class PublicAdmissions
{
    private StudentFiles $files;
    public function __construct(private Store $db,private CatalogService $catalog,?StudentFiles $files=null){$this->files=$files??new StudentDocumentFiles();}
    public static function canReview():bool {return Access::isAdmin()||(current_user_can('erp_gerenciar_pessoas')&&MenuPolicy::can('requerimentos'));}
    private function staff():void {if(!self::canReview())throw new RuleViolation('Sem permissão para conferir requerimentos.');}
    private function sensitive():void
    {
        foreach(['dados_pessoais','ficha_medica','dificuldades_aprendizagem'] as $area)if(!StudentRecordPolicy::can($area))throw new RuleViolation('A conferência deste requerimento exige acesso a dados pessoais, ficha médica e dificuldades de aprendizagem. Solicite a liberação ao administrador em Perfis e acessos.');
        if(Access::isAdmin())return;$permitted=array_column((new AdditionalFields($this->db))->all(),null,'chave');$keys=[...array_column(AdmissionForm::fields(),'chave'),'contatos_emergencia'];
        foreach($this->db->rows('SELECT chave,idgrupo,ativo FROM '.$this->db->table('campos_adicionais')) as $def)if((int)$def['ativo']&&in_array($def['chave'],$keys,true)&&!isset($permitted[$def['chave']]))throw new RuleViolation('Há campos de grupos sem acesso para seu perfil. Solicite autorização ao administrador em Perfis e acessos.');
    }
    public function checkReviewAccess():void {$this->staff();$this->sensitive();}
    private function request(int $id,bool $lock=false):array {$this->staff();$r=$this->db->get('requerimentos_publicos',$id,$lock);if((int)$r['codcoligada']!==Coligadas::current())throw new RuleViolation('Requerimento de outra coligada.');return $r;}
    public function submit(array $data,array $uploads,string $key):array
    {
        if(!(int)AdmissionSettings::read()['ativo'])throw new RuleViolation('O recebimento de requerimentos está encerrado.');
        $data=AdmissionForm::validate($data,false,AdmissionForm::fields($this->db));foreach($data['pessoas'] as $p)(new CivilStatus($this->db))->resolve($p['idestado_civil']);
        if(array_diff(array_keys($uploads),['foto','guarda','adocao']))throw new RuleViolation('Anexo desconhecido.');
        $hashes=[];foreach($uploads as $field=>$file){if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;if(!is_array($file)||empty($file['tmp_name'])||!is_file($file['tmp_name']))throw new RuleViolation('Anexo inválido.');if($field==='guarda'&&!in_array($data['campos']['familia_situacao_conjugal_pais']??'',['Divorciados','Separados'],true))throw new RuleViolation('Documento de guarda não se aplica às respostas.');if($field==='adocao'&&($data['campos']['familia_adocao']??'')!=='Sim')throw new RuleViolation('Documento de adoção não se aplica às respostas.');$hashes[$field]=hash_file('sha256',$file['tmp_name']);}
        Input::key($key);$hash=hash('sha256',wp_json_encode([$data,$hashes]));$lock='erp_req_'.substr(hash('sha256',$this->db->table('requerimentos_publicos').Coligadas::current().$key),0,40);
        if((int)($this->db->row('SELECT GET_LOCK(%s,10) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Envio em processamento. Aguarde e tente novamente.');
        try{return $this->db->atomic(function()use($data,$uploads,$key,$hash,$hashes){
            $old=$this->db->row('SELECT protocolo,hash_envio FROM '.$this->db->table('requerimentos_publicos').' WHERE codcoligada=%d AND chave_envio=%s FOR UPDATE',[Coligadas::current(),hash('sha256',$key)]);
            if($old){if(!hash_equals($old['hash_envio'],$hash))throw new RuleViolation('Este envio já foi utilizado com outros dados. Recarregue o formulário.');return ['protocolo'=>$old['protocolo'],'recebido'=>true];}
            $protocol='REQ-'.strtoupper(bin2hex(random_bytes(10)));$id=$this->db->insert('requerimentos_publicos',['codcoligada'=>Coligadas::current(),'protocolo'=>$protocol,'chave_envio'=>hash('sha256',$key),'hash_envio'=>$hash,'nome_aluno'=>$data['pessoas']['aluno']['nome'],'declaracao_texto'=>AdmissionSettings::read()['declaracao'],'privacidade_texto'=>AdmissionSettings::read()['privacidade'],'dados_json'=>wp_json_encode($data)]);
            foreach($hashes as $field=>$unused){$stored=$this->files->put($uploads[$field]);$this->db->onCompletion(function($ok)use($stored){if(!$ok)$this->files->delete($stored['arquivo_token']);});if($field==='foto'&&!in_array($stored['arquivo_mime'],['image/jpeg','image/png','image/webp'],true))throw new RuleViolation('A foto deve ser JPG, PNG ou WebP.');$this->db->insert('requerimento_arquivos',$stored+['idrequerimento'=>$id,'campo'=>$field]);}
            (new AdmissionNotifications($this->db))->queue($id,$data,$protocol);
            $this->db->audit('requerimentos_publicos',$id,'receber',null,['status'=>'pendente','protocolo'=>$protocol],$key);return ['protocolo'=>$protocol,'recebido'=>true];
        });}finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
    public function listing(string $status,int $page,string $search=''):array
    {
        $this->staff();if(!in_array($status,['pendente','aprovado','rejeitado'],true))throw new RuleViolation('Situação inválida.');$page=max(1,min(100000,$page));$where='codcoligada=%d AND status=%s';$args=[Coligadas::current(),$status];
        if(trim($search)!==''){$where.=' AND (nome_aluno LIKE %s OR protocolo LIKE %s)';$term='%'.addcslashes(substr($search,0,120),'_%\\').'%';$args[]=$term;$args[]=$term;}
        $t=$this->db->table('requerimentos_publicos');return ['items'=>$this->db->rows("SELECT idrequerimento,protocolo,nome_aluno,status,criado_em,conferido_em,versao FROM $t WHERE $where ORDER BY idrequerimento DESC LIMIT 20 OFFSET %d",[...$args,($page-1)*20]),'total'=>(int)$this->db->row("SELECT COUNT(*) AS n FROM $t WHERE $where",$args)['n'],'page'=>$page];
    }
    public function detail(int $id):array
    {
        $r=$this->request($id);$this->sensitive();$data=json_decode($r['aprovados_json']?:$r['dados_json'],true);$r['dados']=AdmissionForm::fromStored($data);$r['resultado']=json_decode($r['resultado_json']??'null',true);$suggestions=[];$t=$this->db->table('pessoas');
        foreach($data['pessoas'] as $slot=>$p){$args=[$p['nome'],$p['data_nascimento']];$where='(nome=%s AND data_nascimento=%s)';if(!empty($p['cpf'])){$where.=' OR cpf=%s';$args[]=$p['cpf'];}$suggestions[$slot]=$this->db->rows("SELECT codpessoa,codigo_pessoa,nome,data_nascimento FROM $t WHERE ativo=1 AND ($where) ORDER BY nome LIMIT 20",$args);}
        $r['sugestoes']=$suggestions;$r['arquivos']=$this->db->rows('SELECT idarquivo,campo,arquivo_nome,arquivo_mime FROM '.$this->db->table('requerimento_arquivos').' WHERE idrequerimento=%d AND arquivo_token IS NOT NULL',[$id]);unset($r['dados_json'],$r['aprovados_json'],$r['resultado_json'],$r['hash_envio'],$r['chave_envio']);$r['confirmacao_email']=(new AdmissionNotifications($this->db))->summary($id);return $r;
    }
    private function approvalLock(callable $fn):array
    {
        return (new AdmissionAcceptance($this->db,$this->catalog))->locked($fn);
    }
    public function approve(int $id,array $d,string $key):array
    {
        $this->request($id);$this->sensitive();return $this->approvalLock(fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'aprovar_requerimento_publico',['id'=>$id]+$d,function()use($id,$d,$key){
            $r=$this->request($id,true);if($r['status']==='aprovado')return json_decode($r['resultado_json'],true);if($r['status']!=='pendente')throw new RuleViolation('Este requerimento não está pendente.');if((string)($d['versao']??'')!==(string)$r['versao'])throw new RuleViolation('Requerimento alterado. Recarregue a conferência.');
            if(!is_array($d['dados']??null))throw new RuleViolation('Confira os dados do requerimento.');$original=json_decode($r['dados_json'],true);$data=AdmissionForm::validate($d['dados'],true,AdmissionForm::fields($this->db),$original);$data['declaracao_nome']=$original['declaracao_nome'];$data['declaracao_versao']=$original['declaracao_versao'];$choices=$d['pessoas']??[];if(!is_array($choices)||array_diff(array_keys($choices),array_keys($data['pessoas'])))throw new RuleViolation('Confira todas as pessoas.');$people=[];
            $accepted=(new AdmissionAcceptance($this->db,$this->catalog))->inside($data,$choices,$key);$student=$accepted['idaluno'];$people=$accepted['pessoas'];$pid=$people['aluno']['codpessoa'];$data['pessoas']['aluno']['email']=$this->db->get('pessoas',$pid)['email'];
            if(($d['aplicar_foto']??false)===true){$photo=$this->db->row('SELECT * FROM '.$this->db->table('requerimento_arquivos')." WHERE idrequerimento=%d AND campo='foto'",[$id]);if($photo){$bytes=$this->files->read($photo['arquivo_token']);if(!hash_equals($photo['sha256'],hash('sha256',$bytes)))throw new RuleViolation('Foto sem integridade.');$attachment=\EducacionalERP\Infrastructure\WordPress\AdmissionPhoto::create($this->db,$bytes,$photo['arquivo_mime']);$beforePhoto=$this->db->get('pessoas',$pid);$this->db->update('pessoas',$pid,['foto_attachment_id'=>$attachment]);$this->db->audit('pessoas',$pid,'foto_requerimento',['foto_attachment_id'=>$beforePhoto['foto_attachment_id']],['foto_attachment_id'=>$attachment],$key);}}
            $result=['idaluno'=>$student,'ra'=>$this->db->get('alunos',$student)['ra'],'pessoas'=>$people,'protocolo'=>$r['protocolo']];
            $this->db->update('requerimentos_publicos',$id,['idaluno'=>$student,'nome_aluno'=>$this->db->get('pessoas',$pid)['nome'],'status'=>'aprovado','aprovados_json'=>wp_json_encode($data),'resultado_json'=>wp_json_encode($result),'conferido_por'=>get_current_user_id(),'conferido_em'=>gmdate('Y-m-d H:i:s')]);$this->db->audit('requerimentos_publicos',$id,'aprovar',['status'=>'pendente'],$result,$key);return $result;
        })));
    }
    public function reject(int $id,array $d,string $key):array
    {
        $this->request($id);return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'rejeitar_requerimento_publico',['id'=>$id]+$d,function()use($id,$d,$key){$r=$this->request($id,true);if($r['status']!=='pendente'||(string)($d['versao']??'')!==(string)$r['versao'])throw new RuleViolation('Requerimento já conferido ou alterado.');$reason=trim(strip_tags(Input::text($d['motivo']??null,1000)));$this->db->update('requerimentos_publicos',$id,['status'=>'rejeitado','motivo'=>$reason,'conferido_por'=>get_current_user_id(),'conferido_em'=>gmdate('Y-m-d H:i:s')]);$this->db->audit('requerimentos_publicos',$id,'rejeitar',['status'=>'pendente'],['status'=>'rejeitado','motivo'=>$reason],$key);return ['status'=>'rejeitado'];}));
    }
    public function file(int $id):array
    {
        $this->staff();$this->sensitive();$f=$this->db->get('requerimento_arquivos',$id);$this->request((int)$f['idrequerimento']);if(!$f['arquivo_token'])throw new RuleViolation('Arquivo indisponível.');$bytes=$this->files->read($f['arquivo_token']);if(!hash_equals($f['sha256'],hash('sha256',$bytes)))throw new RuleViolation('Arquivo sem integridade.');return ['nome'=>$f['arquivo_nome'],'mime'=>$f['arquivo_mime'],'conteudo'=>base64_encode($bytes)];
    }
    public function pdf(int $id):array
    {
        $r=$this->detail($id);$school=\EducacionalERP\Infrastructure\WordPress\SchoolIdentity::read();$company=$this->db->get('coligadas',Coligadas::current());$school['cnpj']=$company['cnpj']??$school['cnpj'];$school['logo_padrao']=get_option(\EducacionalERP\Infrastructure\WordPress\SchoolIdentity::option(),false)===false;$logo=\EducacionalERP\Infrastructure\Pdf\ReceiptLogo::load($school);
        $photo=['path'=>null,'cleanup'=>[]];try{$image=$this->db->row('SELECT * FROM '.$this->db->table('requerimento_arquivos')." WHERE idrequerimento=%d AND campo='foto' AND arquivo_token IS NOT NULL",[$id]);if($image){$raw=$this->files->read($image['arquivo_token']);if(!hash_equals($image['sha256'],hash('sha256',$raw)))throw new RuleViolation('Foto sem integridade.');$photo=\EducacionalERP\Infrastructure\WordPress\AdmissionPhoto::forPdf($raw,$image['arquivo_mime']);}foreach($r['dados']['pessoas'] as &$person){$civil=(new CivilStatus($this->db))->resolve($person['idestado_civil']??null);$person['estado_civil_nome']=$civil['estado_civil'];}unset($person);$bytes=\EducacionalERP\Infrastructure\Pdf\AdmissionPdf::render($r,$school,$logo['path'],$photo['path']);}finally{foreach([...$logo['cleanup'],...$photo['cleanup']] as $p)if(is_file($p))@unlink($p);}return ['nome'=>strtolower($r['protocolo']).'.pdf','mime'=>'application/pdf','conteudo'=>base64_encode($bytes)];
    }
    public function studentRequests(int $student):array
    {
        if(!StudentPickup::permitted())throw new RuleViolation('Sem acesso aos documentos de origem.');$a=$this->db->get('alunos',$student);if((int)$a['codcoligada']!==Coligadas::current())throw new RuleViolation('Aluno de outra coligada.');$this->sensitive();$out=[];
        foreach($this->db->rows('SELECT idrequerimento,protocolo,conferido_em FROM '.$this->db->table('requerimentos_publicos')." WHERE codcoligada=%d AND idaluno=%d AND status='aprovado' ORDER BY idrequerimento DESC",[Coligadas::current(),$student]) as $r){$r['arquivos']=$this->db->rows('SELECT idarquivo,campo,arquivo_nome,arquivo_mime FROM '.$this->db->table('requerimento_arquivos').' WHERE idrequerimento=%d AND arquivo_token IS NOT NULL',[(int)$r['idrequerimento']]);$out[]=$r;}return $out;
    }
}
