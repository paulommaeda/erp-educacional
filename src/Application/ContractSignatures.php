<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{ContractText,Cpf,Input,RuleViolation,Store};
use EducacionalERP\Infrastructure\Signature\{Client,Settings};
use EducacionalERP\Infrastructure\WordPress\Access;

final class ContractSignatures
{
    private const OPEN=['preparado','draft','sent','viewed'];
    private const TERMINAL=['signed','expired','cancelled'];
    private const PUBLIC_FIELDS='idenvio,revisao,referencia,uuid,situacao,original_hash,final_hash,assinado_em,vinculado,criado_em';

    public function __construct(private Store $db) {}

    private function contract(int $id,bool $lock=false):array
    {
        $c=$this->db->get('contratos',$id,$lock);
        if((int)$c['codcoligada']!==Coligadas::current()||!empty($c['excluido_em']))throw new RuleViolation('Contrato indisponível nesta coligada.');
        return $c;
    }

    private static function fingerprint(array $c):string
    {
        $fields=['numero','termo_snapshot','formato_texto','versao_termo','idmodelo','modelo_nome_snapshot','modelo_versao_snapshot','codpessoa_rf_atual'];
        return hash('sha256',wp_json_encode(array_map(static fn($key)=>(string)($c[$key]??''),$fields)));
    }

    public function listing(int $id):array
    {
        Access::requireManageContracts();
        $this->contract($id);
        $s=Settings::read();
        $rows=$this->db->rows('SELECT '.self::PUBLIC_FIELDS.' FROM '.$this->db->table('assinatura_envios').' WHERE idcontrato=%d AND codcoligada=%d ORDER BY revisao DESC',[$id,Coligadas::current()]);
        return ['configurado'=>$s['base']!==''&&$s['senha']!=='','items'=>$rows];
    }

    private function attempt(int $id,int $attempt):array
    {
        Access::requireManageContracts();
        $this->contract($id);
        $row=$this->db->get('assinatura_envios',$attempt);
        if((int)$row['idcontrato']!==$id||(int)$row['codcoligada']!==Coligadas::current())throw new RuleViolation('Envio indisponível neste contrato.');
        return $row;
    }

    public function send(int $id,array $data,string $key):array
    {
        Access::requireManageContracts();
        $version=Input::id($data['versao']??null);
        $prepared=ContractTextLock::run($this->db,fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'preparar_assinatura',['contrato'=>$id,'versao'=>$version],function()use($id,$version,$key){
            $c=$this->contract($id,true);
            if(ContractText::signed($c)||$c['status']!=='ativo')throw new RuleViolation('Selecione um contrato ativo que ainda aguarda assinatura.');
            $latest=$this->db->row('SELECT idenvio,revisao,situacao,revisao_hash FROM '.$this->db->table('assinatura_envios').' WHERE idcontrato=%d ORDER BY revisao DESC LIMIT 1 FOR UPDATE',[$id]);
            if($latest&&in_array($latest['situacao'],self::OPEN,true)){
                if($latest['revisao_hash']!==self::fingerprint($c))throw new RuleViolation('Há uma revisão anterior em andamento. Consulte ou cancele o envio antes de emitir o texto atualizado.');
                return ['idenvio'=>(int)$latest['idenvio']];
            }
            if((int)$c['versao']!==$version)throw new RuleViolation('O contrato mudou. Reabra a ficha e confira o texto.');
            $settings=Settings::read();
            if(!$settings['base']||!$settings['senha'])throw new RuleViolation('Configure a assinatura eletrônica desta coligada.');
            $person=$this->db->get('pessoas',(int)$c['codpessoa_rf_atual']);
            $cpf=preg_replace('/\D/','',(string)$person['cpf']);
            if(!Cpf::valid($cpf)||!is_email($person['email']??'')||empty($person['data_nascimento']))throw new RuleViolation('Confira CPF, data de nascimento e e-mail do responsável financeiro na ficha da pessoa.');
            $birth=Input::date($person['data_nascimento']);
            $contracts=new Contracts($this->db,new Operations($this->db));
            $detail=$contracts->detail($id);
            if((string)$detail['versao']!==(string)$c['versao'])throw new RuleViolation('O contrato mudou durante a emissão. Reabra a ficha e confira o texto.');
            $pdf=(new ContractPrint($this->db,$contracts))->download($id);
            $bytes=base64_decode($pdf['conteudo'],true);
            if($bytes===false||strlen($bytes)>8*1024*1024)throw new RuleViolation('O PDF original deve ter até 8 MiB.');
            $hash=hash('sha256',$bytes);
            $revision=(int)($latest['revisao']??0)+1;
            $company=(int)$c['codcoligada'];
            $ref='erp-'.substr(hash('sha256',home_url('/')),0,16).'/coligada-'.$company.'/contrato-'.$id.'/rev-'.$revision;
            $payload=['external_ref'=>$ref,'student_ref'=>(string)$detail['ra'],'student_name'=>$detail['aluno'],'guardian_name'=>$person['nome'],
                'guardian_email'=>$person['email'],'guardian_cpf'=>$cpf,'guardian_birth_date'=>$birth,'mode'=>'send',
                'document'=>['title'=>'Contrato '.$c['numero'],'institution'=>$this->db->get('coligadas',$company)['nome'],'pdf_base64'=>$pdf['conteudo'],'sha256'=>$hash]];
            $attempt=$this->db->insert('assinatura_envios',['idcontrato'=>$id,'revisao'=>$revision,'api_base'=>$settings['base'],'referencia'=>$ref,
                'original_hash'=>$hash,'revisao_hash'=>self::fingerprint($c),'responsavel_nome'=>$person['nome'],'pedido_json'=>wp_json_encode($payload),'consultar_apos'=>gmdate('Y-m-d H:i:s',time()+300)]);
            $this->db->audit('assinatura_envios',$attempt,'preparar_pdf',null,['idcontrato'=>$id,'referencia'=>$ref,'sha256'=>$hash],$key);
            return ['idenvio'=>$attempt];
        })));
        $row=$this->attempt($id,(int)$prepared['idenvio']);
        if($row['situacao']==='preparado'){
            try{$summary=$this->lookup($row);}
            catch(RuleViolation $e){
                if($e->getCode()!==404)throw $e;
                try{$summary=Client::request($row['api_base'],'/contracts','POST',$row['pedido_json']);}
                catch(RuleViolation $creation){
                    if(in_array($creation->getCode(),[400,413,422],true))$this->reject($row);
                    throw $creation;
                }
            }
            $this->apply($row,$summary);
        }
        return $this->listing($id);
    }

    private function reject(array $row):void
    {
        $this->db->atomic(function()use($row){
            $id=(int)$row['idenvio'];$now=$this->db->get('assinatura_envios',$id,true);
            if($now['situacao']==='preparado')$this->db->update('assinatura_envios',$id,['situacao'=>'rejeitado','pedido_json'=>null]);
        });
    }

    private function lookup(array $row):array
    {
        $path=$row['uuid']?'/contracts/'.$row['uuid']:'/contracts?external_ref='.rawurlencode($row['referencia']);
        return Client::request($row['api_base'],$path);
    }

    public function action(int $id,int $attempt,string $action):array
    {
        $row=$this->attempt($id,$attempt);
        try{$summary=$this->lookup($row);}
        catch(RuleViolation $failure){
            if($action!=='cancelar'||$row['situacao']!=='preparado'||$failure->getCode()!==404)throw $failure;
            // Reserve the same reference without inviting the family. A late send cannot reuse it.
            $draft=json_decode($row['pedido_json'],true,512,JSON_THROW_ON_ERROR);
            $draft['mode']='draft';
            try{$summary=Client::request($row['api_base'],'/contracts','POST',wp_json_encode($draft));}
            catch(RuleViolation $creation){
                if(in_array($creation->getCode(),[400,413,422],true)){$this->reject($row);return $this->listing($id);}
                if($creation->getCode()!==409)throw $creation;
                $summary=$this->lookup($row);
            }
        }
        $this->apply($row,$summary);
        if($action==='cancelar'&&in_array($summary['status'],['draft','sent','viewed'],true)){
            $summary=Client::request($row['api_base'],'/contracts/'.$summary['uuid'].'/cancel','POST');
            $this->apply($row,$summary);
        }
        return $this->listing($id);
    }

    private function apply(array $known,array $result,?string $event=null):void
    {
        $uuid=$result['uuid']??null;$status=$result['status']??null;
        if(!is_string($uuid)||!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$uuid)
            ||($result['external_ref']??null)!==$known['referencia']||($result['issued_sha256']??null)!==$known['original_hash']
            ||($event===null&&($result['source']??null)!=='erp_pdf')||!in_array($status,['draft','sent','viewed',...self::TERMINAL],true)
            ||($result['is_signed']??null)!==($status==='signed'))throw new RuleViolation('A referência, o estado ou o PDF informado pelo serviço diverge deste envio.');
        $signed=$result['signed_at']??null;$final=$result['pdf_sha256']??null;
        if($status==='signed'){
            $date=is_string($signed)?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$signed,new \DateTimeZone('UTC')):false;
            if(!$date||$date->format('Y-m-d H:i:s')!==$signed||!is_string($final)||!preg_match('/^[a-f0-9]{64}$/D',$final))throw new RuleViolation('Confirmação de assinatura sem data UTC ou hash final válido.');
        }elseif($signed!==null||$final!==null)throw new RuleViolation('A situação recebida não corresponde aos dados de assinatura.');
        ContractTextLock::run($this->db,fn()=>$this->db->atomic(function()use($known,$uuid,$status,$event,$signed,$final){
            $id=(int)$known['idenvio'];$cid=(int)$known['idcontrato'];$c=$this->db->get('contratos',$cid,true);$row=$this->db->get('assinatura_envios',$id,true);
            if((int)$row['codcoligada']!==Coligadas::current()||($row['uuid']!==null&&$row['uuid']!==$uuid)||($event!==null&&$row['uuid']===null))
                throw new RuleViolation('O protocolo ainda não foi conciliado com esta revisão.');
            if(in_array($row['situacao'],self::TERMINAL,true)){
                if(!in_array($status,self::TERMINAL,true))return;
                if($row['situacao']!==$status||$row['assinado_em']!==$signed||$row['final_hash']!==$final)throw new RuleViolation('A situação final diverge do histórico desta revisão.');
                if($event!==null&&$row['evento']===null)$this->db->update('assinatura_envios',$id,['evento'=>$event]);
                return;
            }
            $order=['preparado'=>0,'draft'=>1,'sent'=>2,'viewed'=>3];
            if(isset($order[$status])&&$order[$status]<($order[$row['situacao']]??0))return;
            $values=['uuid'=>$uuid,'situacao'=>$status,'assinado_em'=>$signed,'final_hash'=>$final,'pedido_json'=>null,'consultar_apos'=>gmdate('Y-m-d H:i:s',time()+3600)];
            if($event!==null)$values['evento']=$event;
            if($status==='signed'&&!ContractText::signed($c)&&empty($c['excluido_em'])&&$c['status']==='ativo'&&hash_equals($row['revisao_hash'],self::fingerprint($c))){
                $mark=['assinado_em'=>$signed,'assinado_por_wp_user_id'=>null,'assinado_por_nome'=>$row['responsavel_nome'],
                    'assinatura_texto_hash'=>hash('sha256',$c['termo_snapshot']),'assinatura_numero_snapshot'=>$c['numero']];
                EducbankOutbox::without(fn()=>$this->db->update('contratos',$cid,$mark));
                $values['vinculado']=1;
                $this->db->audit('contratos',$cid,'assinatura_eletronica',null,['idenvio'=>$id,'original_hash'=>$known['original_hash'],'final_hash'=>$final],hash('sha256',$row['referencia'].':'.$status));
            }
            $this->db->update('assinatura_envios',$id,$values);
            if($row['situacao']!==$status)$this->db->audit('assinatura_envios',$id,'situacao_assinatura',['situacao'=>$row['situacao']],['situacao'=>$status,'uuid'=>$uuid],hash('sha256',$row['referencia'].':'.$status));
        }));
    }

    public function receive(array $known,array $message):void
    {
        $status=$message['status']??null;
        if(!in_array($status,self::TERMINAL,true)||($message['event']??null)!=='contract.'.$status
            ||!is_string($message['uuid']??null)||($message['event_id']??null)!==$message['uuid'].':'.$status)
            throw new RuleViolation('Evento de assinatura inválido.');
        $this->apply($known,$message,$message['event_id']);
    }

    public function pdf(int $id,int $attempt):array
    {
        $row=$this->attempt($id,$attempt);
        if($row['situacao']!=='signed'||!$row['final_hash'])throw new RuleViolation('O PDF assinado ainda não está disponível.');
        $result=Client::request($row['api_base'],'/contracts/'.$row['uuid'].'/document/signed','GET',null,true);
        if(!hash_equals($row['final_hash'],hash('sha256',$result['bytes'])))throw new RuleViolation('O PDF recebido diverge do hash final registrado.');
        return ['nome'=>'contrato-'.$id.'-rev-'.$row['revisao'].'-assinado.pdf','mime'=>'application/pdf','conteudo'=>base64_encode($result['bytes'])];
    }

    public function reconcile():void
    {
        $now=gmdate('Y-m-d H:i:s');$stop=time()+25;
        $rows=$this->db->rows('SELECT idenvio,codcoligada FROM '.$this->db->table('assinatura_envios')." WHERE situacao IN ('preparado','draft','sent','viewed') AND consultar_apos<=%s ORDER BY consultar_apos,idenvio LIMIT 12",[$now]);
        foreach($rows as $item){
            if(time()>=$stop)break;
            Coligadas::within((int)$item['codcoligada'],function()use($item,$now){
                $id=(int)$item['idenvio'];
                $lease=$this->db->query('UPDATE '.$this->db->table('assinatura_envios').' SET consultar_apos=%s WHERE idenvio=%d AND consultar_apos<=%s',[gmdate('Y-m-d H:i:s',time()+3600),$id,$now]);
                if(!$lease)return;
                $row=$this->db->get('assinatura_envios',$id);
                if(!in_array($row['situacao'],self::OPEN,true))return;
                try{$this->apply($row,$this->lookup($row));}catch(RuleViolation $ignored){/* A later query retries the same revision. */}
            });
        }
    }
}
