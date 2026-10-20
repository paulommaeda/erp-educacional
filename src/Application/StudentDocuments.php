<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,StudentFiles};
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy,FamilyAccess,StudentDocumentFiles};
final class StudentDocuments
{
    private StudentFiles $files;
    public function __construct(private Store $db,?StudentFiles $files=null){$this->files=$files??new StudentDocumentFiles();}
    public static function canReview():bool {return Access::isAdmin()||(MenuPolicy::can('documentos')&&current_user_can('erp_gerenciar_pessoas'));}
    private function staff():void {if(!self::canReview())throw new RuleViolation('Seu perfil não pode conferir documentos dos alunos.');}
    private function student(int $id,bool $staff,bool $lock=false):array
    {
        if(!$staff)return (new FamilyAccess($this->db))->student($id,$lock);
        $this->staff();$a=$this->db->get('alunos',$id,$lock);if((int)$a['codcoligada']!==Coligadas::current())throw new RuleViolation('Aluno não pertence à coligada de trabalho.');return $a;
    }
    public function types():array
    {
        Access::requireAdmin();return $this->db->rows('SELECT * FROM '.$this->db->table('tipos_documento_aluno').' WHERE codcoligada=%d ORDER BY ordem,nome,idtipo_documento',[Coligadas::current()]);
    }
    public function saveType(array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'tipo_documento_aluno',$data,function()use($data,$key){
            $before=null;$id=empty($data['idtipo_documento'])?0:Input::id($data['idtipo_documento']);
            if($id){$before=$this->db->get('tipos_documento_aluno',$id,true);if((int)$before['codcoligada']!==Coligadas::current())throw new RuleViolation('Tipo de documento de outro colégio.');$this->version($before,$data);}
            $name=trim(sanitize_text_field(Input::text($data['nome']??null,120)));if($name==='')throw new RuleViolation('Informe o nome do documento.');
            $valid=['nome'=>$name,'orientacoes'=>empty($data['orientacoes'])?null:Input::text($data['orientacoes'],1000)];
            foreach(['ativo','obrigatorio'] as $field){$value=$data[$field]??($field==='ativo'?1:0);if(!in_array($value,[0,1,'0','1',false,true],true))throw new RuleViolation('Situação inválida.');$valid[$field]=(int)$value;}
            $order=filter_var($data['ordem']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);if($order===false)throw new RuleViolation('Ordem inválida.');$valid['ordem']=$order;
            if($id)$this->db->update('tipos_documento_aluno',$id,$valid);else $id=$this->db->insert('tipos_documento_aluno',$valid);
            $this->db->audit('tipos_documento_aluno',$id,$before?'editar':'criar',$before,$valid,$key);return ['idtipo_documento'=>$id];
        }));
    }
    public function removeType(int $id,array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'excluir_tipo_documento',['idtipo_documento'=>$id]+$data,function()use($id,$data,$key){
            $row=$this->db->get('tipos_documento_aluno',$id,true);if((int)$row['codcoligada']!==Coligadas::current())throw new RuleViolation('Tipo de outro colégio.');$this->version($row,$data);
            if($this->db->row('SELECT iddocumento FROM '.$this->db->table('aluno_documentos').' WHERE idtipo_documento=%d LIMIT 1',[$id]))throw new RuleViolation('Este tipo já possui envios. Altere a situação para Inativo para preservar o histórico.');
            $this->db->audit('tipos_documento_aluno',$id,'excluir',$row,[],$key);$this->db->query('DELETE FROM '.$this->db->table('tipos_documento_aluno').' WHERE idtipo_documento=%d',[$id]);return ['excluido'=>true];
        }));
    }
    private function version(array $row,array $data):void {if(!isset($data['versao'])||(string)$data['versao']!==(string)$row['versao'])throw new RuleViolation('Registro alterado. Atualize a tela antes de continuar.');}
    private function publicRow(array $row):array {unset($row['arquivo_token'],$row['sha256']);return $row;}
    public function listing(int $student,bool $staff=false):array
    {
        $a=$this->student($student,$staff);$ids=$staff?[$student]:(new FamilyAccess($this->db))->localStudents($student);$out=[];
        foreach($ids as $local){$aluno=$this->db->get('alunos',$local);$company=$this->db->get('coligadas',(int)$aluno['codcoligada']);$types=$this->db->rows('SELECT * FROM '.$this->db->table('tipos_documento_aluno').' WHERE codcoligada=%d ORDER BY ordem,nome',[(int)$aluno['codcoligada']]);
            $table=$this->db->table('aluno_documentos');$latest=array_column($this->db->rows("SELECT d.* FROM $table d JOIN (SELECT idtipo_documento,MAX(iddocumento) AS ultimo FROM $table WHERE idaluno=%d GROUP BY idtipo_documento) u ON u.ultimo=d.iddocumento",[$local]),null,'idtipo_documento');
            foreach($types as $type){$row=$latest[$type['idtipo_documento']]??null;if(!(int)$type['ativo']&&!$row)continue;
                $out[]=['idaluno'=>$local,'idtipo_documento'=>$type['idtipo_documento'],'nome'=>$type['nome'],'orientacoes'=>$type['orientacoes'],'obrigatorio'=>(bool)$type['obrigatorio'],'ativo'=>(bool)$type['ativo'],'colegio'=>$company['nome'],'pode_enviar'=>(int)$type['ativo']&&(!$row||$row['status']==='rejeitado'),'documento'=>$row?$this->publicRow($row):null];
            }
        }return $out;
    }
    public function upload(int $student,int $type,array $file,string $key):array
    {
        $a=$this->student($student,false);$result=Coligadas::within((int)$a['codcoligada'],fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'enviar_documento_aluno',['idaluno'=>$student,'idtipo_documento'=>$type,'nome'=>(string)($file['name']??''),'hash'=>!empty($file['tmp_name'])&&is_file($file['tmp_name'])?hash_file('sha256',$file['tmp_name']):''],function()use($student,$type,$file,$key){
            $a=$this->student($student,false,true);$t=$this->db->get('tipos_documento_aluno',$type,true);
            if((int)$t['codcoligada']!==(int)$a['codcoligada']||!(int)$t['ativo'])throw new RuleViolation('Documento não solicitado por este colégio.');
            $slot=$this->db->row('SELECT iddocumento FROM '.$this->db->table('aluno_documentos').' WHERE idaluno=%d AND idtipo_documento=%d AND ativo_unico=1 FOR UPDATE',[$student,$type]);if($slot)throw new RuleViolation('Este documento já foi enviado e está aguardando conferência ou foi aprovado.');
            $stored=$this->files->put($file);$this->db->onCompletion(function($committed)use($stored){if(!$committed)$this->files->delete($stored['arquivo_token']);});
            $id=$this->db->insert('aluno_documentos',$stored+['codcoligada'=>$a['codcoligada'],'idaluno'=>$student,'idtipo_documento'=>$type,'enviado_por_wp_user_id'=>get_current_user_id()]);
            $this->db->audit('aluno_documentos',$id,'enviar',null,['idaluno'=>$student,'idtipo_documento'=>$type,'arquivo_nome'=>$stored['arquivo_nome'],'status'=>'pendente'],$key);return ['iddocumento'=>$id,'status'=>'pendente'];
        })));return $result;
    }
    public function queue(string $status,string $search,int $page=1,int $type=0):array
    {
        $this->staff();if(!in_array($status,['pendente','aprovado','rejeitado','todos'],true))throw new RuleViolation('Situação inválida.');$page=max(1,min(100000,$page));
        $d=$this->db->table('aluno_documentos');$a=$this->db->table('alunos');$p=$this->db->table('pessoas');$t=$this->db->table('tipos_documento_aluno');$where='d.codcoligada=%d';$args=[Coligadas::current()];
        if($status!=='todos'){$where.=' AND d.status=%s';$args[]=$status;}if($type){$where.=' AND d.idtipo_documento=%d';$args[]=$type;}
        if(trim($search)!==''){$where.=' AND (p.nome LIKE %s OR a.ra LIKE %s)';$term='%'.addcslashes(substr($search,0,120),'_%\\').'%';$args[]=$term;$args[]=$term;}
        $join=" FROM $d d JOIN $a a ON a.idaluno=d.idaluno JOIN $p p ON p.codpessoa=a.codpessoa JOIN $t t ON t.idtipo_documento=d.idtipo_documento WHERE $where";
        $total=(int)$this->db->row('SELECT COUNT(*) AS n'.$join,$args)['n'];$rows=$this->db->rows('SELECT d.*,p.nome AS aluno,a.ra,t.nome AS tipo_documento'.$join.' ORDER BY d.iddocumento DESC LIMIT 20 OFFSET %d',[...$args,($page-1)*20]);
        return ['items'=>array_map(fn($r)=>$this->publicRow($r),$rows),'total'=>$total,'page'=>$page,'tipos'=>$this->db->rows('SELECT idtipo_documento,nome FROM '.$t.' WHERE codcoligada=%d ORDER BY nome',[Coligadas::current()])];
    }
    public function review(int $id,array $data,string $key):array
    {
        $this->staff();$result=$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'conferir_documento_aluno',['iddocumento'=>$id]+$data,function()use($id,$data,$key){
            $initial=$this->db->get('aluno_documentos',$id);$this->student((int)$initial['idaluno'],true,true);$row=$this->db->get('aluno_documentos',$id,true);$this->version($row,$data);
            if($row['status']!=='pendente')throw new RuleViolation('Este documento já foi conferido.');
            $status=$data['status']??'';if(!in_array($status,['aprovado','rejeitado'],true))throw new RuleViolation('Selecione Aprovar ou Rejeitar.');
            $reason=$status==='rejeitado'?trim(sanitize_textarea_field(Input::text($data['motivo']??null,1000))):null;if($status==='rejeitado'&&$reason==='')throw new RuleViolation('Informe o motivo da rejeição para orientar o responsável.');
            $valid=['status'=>$status,'motivo'=>$reason,'ativo_unico'=>$status==='aprovado'?1:null,'revisado_por_wp_user_id'=>get_current_user_id(),'revisado_em'=>gmdate('Y-m-d H:i:s')];
            if($status==='aprovado'){$bytes=$this->files->read((string)$row['arquivo_token']);if(!hash_equals($row['sha256'],hash('sha256',$bytes)))throw new RuleViolation('Arquivo sem integridade. Rejeite o documento e solicite um novo envio.');}
            $this->db->update('aluno_documentos',$id,$valid);$this->db->audit('aluno_documentos',$id,'conferir',['status'=>$row['status']],$valid,$key);return ['iddocumento'=>$id,'status'=>$status];
        }));try{$this->cleanupRejected();}catch(\Throwable $ignored){}return $result;
    }
    public function download(int $id,bool $staff=false):array
    {
        $row=$this->db->get('aluno_documentos',$id);$this->student((int)$row['idaluno'],$staff);
        if($row['status']==='rejeitado'||!$row['arquivo_token'])throw new RuleViolation('Este arquivo foi rejeitado e não está disponível.');
        $bytes=$this->files->read($row['arquivo_token']);if(!hash_equals($row['sha256'],hash('sha256',$bytes)))throw new RuleViolation('Não foi possível verificar a integridade do documento.');
        return ['nome'=>$row['arquivo_nome'],'mime'=>$row['arquivo_mime'],'conteudo'=>base64_encode($bytes)];
    }
    /** Persisted rejected tokens allow retry if disk cleanup fails after the review transaction. */
    public function cleanupRejected():void
    {
        $table=$this->db->table('aluno_documentos');foreach($this->db->rows("SELECT iddocumento,arquivo_token FROM $table WHERE status='rejeitado' AND arquivo_token IS NOT NULL ORDER BY iddocumento LIMIT 50") as $row){try{if($this->files->delete($row['arquivo_token']))$this->db->query("UPDATE $table SET arquivo_token=NULL WHERE iddocumento=%d AND status='rejeitado' AND arquivo_token=%s",[(int)$row['iddocumento'],$row['arquivo_token']]);}catch(\Throwable $ignored){/* Scheduled cleanup retries; rejected files cannot be downloaded. */}}
    }
}
