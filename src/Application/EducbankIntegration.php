<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,Money,RuleViolation};
use EducacionalERP\Infrastructure\Educbank\{Settings,Client,ResponseError};
use EducacionalERP\Infrastructure\WordPress\Access;
final class EducbankIntegration
{
    private const WAITING_ENROLLMENT='Aguardando a matrícula ser sincronizada no Educbank.';
    public function __construct(private Store $db){}
    public function settings():array{return ['configuracao'=>Settings::publicData(Coligadas::current()),'programas'=>Settings::PROGRAMS,'planos'=>Settings::PLANS];}
    public function save(array $d):array{$result=Settings::save($this->db,$d);EducbankOutbox::refresh($this->db,Coligadas::current());return $result;}
    public function mappings(int $period):array
    {
        Access::requireAdmin();$company=Coligadas::current();$periods=$this->db->rows('SELECT codperiodo,codigo,descricao,data_inicio FROM '.$this->db->table('periodos_letivos').' WHERE codcoligada=%d ORDER BY data_inicio DESC',[$company]);
        if($period&&(int)$this->db->get('periodos_letivos',$period)['codcoligada']!==$company)throw new RuleViolation('Período de outra coligada.');
        return ['periodos'=>$periods,'turmas'=>$period?$this->db->rows('SELECT t.idturma,t.nome,t.codperiodo,c.nome AS curso FROM '.$this->db->table('turmas').' t JOIN '.$this->db->table('cursos').' c ON c.idcurso=t.idcurso WHERE t.codcoligada=%d AND t.codperiodo=%d ORDER BY c.nome,t.nome',[$company,$period]):[]];
    }
    private function scope(array $s,int $company):array{return [$company,$s['ambiente'],Settings::scope($s)['tenant']];}
    public function listing(int $page=1,string $status=''):array
    {
        Access::requireAdmin();$s=Settings::read(Coligadas::current());$args=$this->scope($s,Coligadas::current());$where='q.codcoligada=%d AND q.ambiente=%s AND q.tenant=%s';
        if($status!==''){if(!in_array($status,['pendente','sincronizado','erro','revisao','ignorado'],true))throw new RuleViolation('Filtro inválido.');$where.=' AND q.status=%s';$args[]=$status;}
        $page=max(1,min(100000,$page));$q=$this->db->table('educbank_fila');$total=(int)$this->db->row("SELECT COUNT(*) AS n FROM $q q WHERE $where",$args)['n'];$args[]=($page-1)*20;
        $rows=$this->db->rows("SELECT q.*,a.ra,p.nome AS aluno FROM $q q JOIN ".$this->db->table('matriculas').' m ON m.idmatricula=q.idmatricula JOIN '.$this->db->table('alunos').' a ON a.idaluno=m.idaluno JOIN '.$this->db->table('pessoas')." p ON p.codpessoa=a.codpessoa WHERE $where ORDER BY q.idintegracao DESC LIMIT 20 OFFSET %d",$args);
        return ['items'=>$rows,'total'=>$total,'page'=>$page];
    }
    public function test():array
    {
        Access::requireAdmin();$s=Settings::read(Coligadas::current());$client=new Client($s);
        foreach(['/enrollment'=>'showCanceledEnrollments','/invoice'=>'showCanceledInvoices'] as $path=>$flag){$result=$client->request('GET',$path,null,['maxResultCount'=>1,$flag=>true]);if(!array_key_exists('items',$result)||(!is_array($result['items'])&&$result['items']!==null)||!isset($result['totalCount']))throw new RuleViolation('Educbank não confirmou uma lista válida ao consultar '.($path==='/invoice'?'faturas':'matrículas').'.');}
        return ['conectado'=>true,'ambiente'=>$s['ambiente'],'consultas'=>['matriculas'=>true,'faturas'=>true]];
    }
    public function backlog(array $d,string $key):array
    {
        Access::requireAdmin();$period=Input::id($d['codperiodo']??null);$cursor=max(0,(int)($d['cursor']??0));$company=Coligadas::current();
        if((int)$this->db->get('periodos_letivos',$period)['codcoligada']!==$company)throw new RuleViolation('Período de outra coligada.');if(!Settings::read($company)['ativo'])throw new RuleViolation('Ative a integração desta coligada antes de enfileirar.');
        return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'educbank_enfileirar',$d,function()use($period,$cursor,$company){
            $rows=$this->db->rows('SELECT idmatricula FROM '.$this->db->table('matriculas').' WHERE codcoligada=%d AND codperiodo=%d AND idmatricula>%d ORDER BY idmatricula LIMIT 21',[$company,$period,$cursor]);$more=count($rows)>20;$rows=array_slice($rows,0,20);$outbox=new EducbankOutbox($this->db);foreach($rows as $r)$outbox->enrollment((int)$r['idmatricula'],true);return ['enfileiradas'=>count($rows),'proximo_cursor'=>$more?(int)end($rows)['idmatricula']:null];
        }));
    }
    public function retry(int $id):array
    {
        Access::requireAdmin();$job=$this->db->get('educbank_fila',$id);$s=Settings::read(Coligadas::current());if($this->scope($s,Coligadas::current())!==[(int)$job['codcoligada'],$job['ambiente'],$job['tenant']])throw new RuleViolation('Registro de outra coligada ou ambiente.');
        $this->db->update('educbank_fila',$id,['status'=>'pendente','proxima_tentativa'=>null,'erro'=>null,'tentativas'=>0,'geracao'=>(int)$job['geracao']+1]);return ['enfileirado'=>true];
    }
    /** May be called by WP-Cron; authenticated admin checks live at the REST boundary. */
    public function process(int $company,int $limit=3):array
    {
        $s=Settings::read($company);if(!$s['ativo']||!(int)$this->db->get('coligadas',$company)['ativo'])return ['processados'=>0,'desativado'=>true];$scope=$this->scope($s,$company);$lock='ederp_educbank_'.substr(hash('sha256',implode('|',$scope)),0,35);
        if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)return ['processados'=>0,'ocupado'=>true];
        $count=0;$start=microtime(true);try{
            $q=$this->db->table('educbank_fila');$args=$scope;$args[]=gmdate('Y-m-d H:i:s');$args[]=max(1,min(10,$limit));
            $rows=$this->db->rows("SELECT * FROM $q WHERE codcoligada=%d AND ambiente=%s AND tenant=%s AND status IN ('pendente','erro') AND (proxima_tentativa IS NULL OR proxima_tentativa<=%s) ORDER BY CASE WHEN tipo='matricula' THEN 0 ELSE 1 END,idintegracao LIMIT %d",$args);
            foreach($rows as $row){if($count&&microtime(true)-$start>20)break;$id=(int)$row['idintegracao'];$job=$this->db->get('educbank_fila',$id);$this->db->update('educbank_fila',$id,['tentativas'=>(int)$job['tentativas']+1]);
                try{Coligadas::within($company,fn()=>$this->work($job,$s));}
                catch(RuleViolation $e){$this->db->update('educbank_fila',$id,['status'=>(int)$job['tentativas']>=7?'revisao':'erro','erro'=>ResponseError::clip($e->getMessage()),'proxima_tentativa'=>gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min(6,(int)$job['tentativas']))))]);$this->preserveNewer($job);}
                catch(\Throwable $e){$this->db->update('educbank_fila',$id,['status'=>'revisao','erro'=>'Falha interna na integração. Nenhuma confirmação foi descartada; consulte o diagnóstico do ERP.']);$this->preserveNewer($job);}
                $count++;
            }
        }finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}return ['processados'=>$count];
    }
    private function preserveNewer(array $job):void
    {
        $this->db->query('UPDATE '.$this->db->table('educbank_fila')." SET status='pendente',proxima_tentativa=NULL WHERE idintegracao=%d AND geracao>%d",[(int)$job['idintegracao'],(int)$job['geracao']]);
    }
    private function finish(array $job,string $status,array $extra=[]):void
    {
        $id=(int)$job['idintegracao'];$this->db->update('educbank_fila',$id,$extra+['erro'=>null,'proxima_tentativa'=>null,'sincronizado_em'=>gmdate('Y-m-d H:i:s')]);
        // A concurrent edit must remain pending, even when an older HTTP request succeeds.
        $this->db->query('UPDATE '.$this->db->table('educbank_fila')." SET status=CASE WHEN geracao=%d THEN %s ELSE 'pendente' END WHERE idintegracao=%d",[(int)$job['geracao'],$status,$id]);
        if($status==='sincronizado'&&$job['tipo']==='matricula'&&$this->db->get('educbank_fila',$id)['status']==='sincronizado'){
            // Dependency waits are not invoice failures. Resume only those exact waits.
            $this->db->query('UPDATE '.$this->db->table('educbank_fila')." SET status='pendente',proxima_tentativa=NULL,erro=NULL,tentativas=0,geracao=geracao+1,versao=versao+1 WHERE codcoligada=%d AND ambiente=%s AND tenant=%s AND idmatricula=%d AND tipo='fatura' AND status IN ('erro','revisao') AND erro=%s",[$job['codcoligada'],$job['ambiente'],$job['tenant'],$job['idmatricula'],self::WAITING_ENROLLMENT]);
        }
    }
    private function remember(array &$job,string $id):void{$job['idremoto']=Client::uuid($id);$this->db->update('educbank_fila',(int)$job['idintegracao'],['idremoto'=>$job['idremoto']]);}
    private function path(array $job):string{return $job['tipo']==='matricula'?'/enrollment':'/invoice';}
    /** A postal lookup failure concerns the payer, not the student's address or API token. */
    private function send(Client $client,string $method,string $path,array $payload,array $payer,string $environment):array
    {
        try{return $client->request($method,$path,$payload);}
        catch(RuleViolation $e){
            if(!preg_match('/\bCEP\b|\bzipCode\b/iu',$e->getMessage()))throw $e;
            $code=ResponseError::clip(trim(strip_tags((string)($payer['codigo_pessoa']??''))),80);
            $person=$code!==''?'código '.$code:'ID pessoa '.(int)$payer['codpessoa'];
            $hint='Responsável financeiro: '.$person.'. Campo CEP em Pessoas > Endereço. Confira nos Correios; se correto, peça ao Educbank a revisão da base de CEPs em '.($environment==='sandbox'?'sandbox':'produção').'.';
            $message=$e->getMessage();$limit=480-strlen($hint)-3;
            if(strlen($message)>$limit)$message=ResponseError::clip($message,$limit-3).'...';
            throw new RuleViolation($message.' | '.$hint,0,$e);
        }
    }
    private function find(array &$job,Client $client,?array $payload):?array
    {
        if($job['idremoto']){
            $r=$client->request('GET',$this->path($job).'/'.Client::uuid($job['idremoto']));if((string)($r['externalId']??'')!==$job['external_id'])throw new RuleViolation('Identificador externo divergente no Educbank. Confira o vínculo antes de continuar.');return $r;
        }
        $params=['externalId'=>$job['external_id'],'maxResultCount'=>100,$job['tipo']==='matricula'?'showCanceledEnrollments':'showCanceledInvoices'=>true];
        $list=$client->request('GET',$this->path($job),null,$params);if(array_key_exists('items',$list)&&$list['items']===null&&(int)($list['totalCount']??-1)===0)$list['items']=[];
        if(!array_key_exists('items',$list)||!is_array($list['items'])||!isset($list['totalCount'])||(int)$list['totalCount']>100)throw new RuleViolation('Consulta Educbank incompleta. Não foi criada outra cobrança.');
        $matches=array_values(array_filter($list['items'],fn($r)=>(string)($r['externalId']??'')===$job['external_id']));
        if(!$matches)return null;if(count($matches)!==1)throw new RuleViolation('Mais de um registro Educbank usa este identificador externo. Confira os vínculos.');$found=$matches[0];
        $expected=$job['identidade_hash']?:($payload?EducbankPayload::identity($job['tipo'],$payload):'');if($expected===''||!hash_equals($expected,EducbankPayload::identity($job['tipo'],$found)))throw new RuleViolation('Identificador externo já pertence a dados diferentes no Educbank. Vínculo não foi reaproveitado.');
        $this->remember($job,$found['id']??'');return $found;
    }
    private function work(array $job,array $s):void
    {
        $client=new Client($s);$builder=new EducbankPayload($this->db);$m=$this->db->get('matriculas',(int)$job['idmatricula']);
        if($job['tipo']==='matricula'&&in_array($m['status'],['cancelada','transferencia_externa'],true)){$this->finish($job,($job['idremoto']||$job['identidade_hash'])?'revisao':'ignorado',['erro'=>($job['idremoto']||$job['identidade_hash'])?'Matrícula encerrada no ERP. Confira a situação acadêmica no Educbank; cancelamentos de faturas são enviados separadamente.':null]);return;}
        if($job['tipo']==='fatura'){
            $title=$this->db->get('lancamentos',(int)$job['idlancamento']);$cancel=$title['status']==='cancelado'||Money::cents($title['valor_liquido'])===0;
            if($cancel){if(!$job['idremoto']&&!$job['identidade_hash']){$this->finish($job,'ignorado');return;}$remote=$this->find($job,$client,null);if(!$remote)throw new RuleViolation('Criação anterior sem confirmação. Aguardando localizar a fatura no Educbank para cancelar com segurança.');
                if(($remote['state']??'')==='Paid')throw new RuleViolation('A fatura foi paga no Educbank. Concilie o pagamento antes de cancelar.');
                if(($remote['state']??'')!=='Canceled')$remote=$client->request('POST','/invoice/'.$job['idremoto'].'/cancel',['reasonCancellation'=>$title['status']==='cancelado'?'EnrollmentCanceled':'Other','comment'=>'Cancelamento/zeramento do lançamento no ERP.']);
                $this->finish($job,'sincronizado',['status_remoto'=>$remote['state']??'Canceled']);return;
            }
            if(Money::cents($title['valor_baixa'])>0&&!$job['idremoto']&&!$job['identidade_hash']){$this->finish($job,$title['status']==='quitado'?'ignorado':'revisao',['erro'=>'Lançamento com baixa local. Não foi criada nova cobrança.']);return;}
            if(in_array($m['status'],['cancelada','transferencia_externa'],true)&&!$job['idremoto']&&!$job['identidade_hash']){$this->finish($job,'revisao',['erro'=>'Matrícula encerrada: confira os débitos anteriores antes de enviar novas faturas.']);return;}
            $enrollment=$this->db->row('SELECT * FROM '.$this->db->table('educbank_fila')." WHERE codcoligada=%d AND ambiente=%s AND tenant=%s AND tipo='matricula' AND idlocal=%d",[$job['codcoligada'],$job['ambiente'],$job['tenant'],$job['idmatricula']]);
            if(!$enrollment||!$enrollment['idremoto']||$enrollment['status']!=='sincronizado')throw new RuleViolation(self::WAITING_ENROLLMENT);
        }
        $context=$builder->context((int)$job['idmatricula'],$s);$payload=$job['tipo']==='matricula'?$builder->enrollment($context):$builder->invoice($context,$title,$enrollment['idremoto'],$s);$hash=hash('sha256',wp_json_encode($payload));
        if($job['idremoto']&&$job['hash_sincronizado']===$hash){$this->finish($job,'sincronizado');return;}
        $remote=$this->find($job,$client,$payload);
        if($remote){
            $document=preg_replace('/\D/','',(string)($remote[$job['tipo']==='matricula'?'companyDocument':'companyCnpj']??''));
            if($document!==$context['cnpj'])throw new RuleViolation('CNPJ do vínculo Educbank difere da coligada. É necessária conferência antes de alterar.');
            if($job['tipo']==='matricula'&&((int)($remote['academicYear']??0)!==(int)$context['year']['ano']||(int)($remote['term']??1)!==(int)($context['year']['semestre']??1)))throw new RuleViolation('Ano/semestre diverge da matrícula já criada no Educbank. Confira o mapeamento; não foi criada outra matrícula.');
            if($job['tipo']==='fatura'&&in_array($remote['state']??'',['Paid','Canceled'],true))throw new RuleViolation('Fatura paga ou cancelada no Educbank. Concilie antes de alterar.');
            if($job['tipo']==='matricula'&&in_array($remote['enrollmentStatus']??'',['Canceled','Evaded'],true))throw new RuleViolation('Matrícula cancelada/evadida no Educbank. Confira antes de alterar.');
            $update=$payload;if($job['tipo']==='matricula')$update=array_intersect_key($payload,array_flip(['student','academicClassName','academicProgram','guardians']));
            else{unset($update['companyCnpj'],$update['closeInvoice'],$update['referenceDate']);$update['reason']='Atualização pelo ERP Educacional';}
            $remote=$this->send($client,'PUT',$this->path($job).'/'.$job['idremoto'],$update,$context['rf'],$s['ambiente']);
        }else{
            // Persist before HTTP: a timeout or crash is recovered by GET externalId, never a blind POST.
            $identity=EducbankPayload::identity($job['tipo'],$payload);$this->db->update('educbank_fila',(int)$job['idintegracao'],['identidade_hash'=>$identity]);
            $remote=$this->send($client,'POST',$this->path($job),$payload,$context['rf'],$s['ambiente']);$this->remember($job,$remote['id']??'');
        }
        if(!empty($remote['errors']))throw new RuleViolation('Educbank criou o registro com pendências. Confira o produto e os dados na plataforma.');
        $url=$remote['url']??null;if(!is_string($url)||!preg_match('~^https://[^\s<>]+$~D',$url))$url=null;
        $this->finish($job,'sincronizado',['hash_sincronizado'=>$hash,'status_remoto'=>$remote['state']??$remote['enrollmentStatus']??null,'url_fatura'=>$url]);
    }
}
