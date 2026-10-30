<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\Educbank\{Client,Settings,ReturnSettings,ResponseError};
use EducacionalERP\Infrastructure\WordPress\Access;

/** Durable inbox plus bounded recovery queries. All money is verified through the authenticated API. */
final class EducbankReturns
{
    public function __construct(private Store $db){}
    private function enabled(int $company,array $s):bool
    {
        $r=ReturnSettings::read($company,$s['ambiente']);return (bool)$s['ativo']&&(bool)$r['ativo']&&$r['tenant']===Settings::scope($s)['tenant']&&(bool)$this->db->get('coligadas',$company)['ativo'];
    }
    private function locked(int $company,array $s,callable $work):array
    {
        $key='ederp_returns_'.substr(hash('sha256',$company.'|'.$s['ambiente'].'|'.Settings::scope($s)['tenant']),0,35);
        if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$key])['acquired']??0)!==1)return ['ocupado'=>true];
        try{return $work();}finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$key]);}
    }
    public function configure(array $data):array
    {
        Access::requireAdmin();$company=Coligadas::current();$s=Settings::read($company);
        if(!in_array($data['ativo']??null,[0,1,false,true,'0','1'],true))throw new RuleViolation('Selecione a situação do retorno automático.');
        return $this->locked($company,$s,function()use($company,$s,$data){
            if(empty($data['ativo'])){ReturnSettings::write($company,$s['ambiente'],['ativo'=>0]);return ['retorno'=>ReturnSettings::publicData($company,$s),'mensagem'=>'Retorno automático desativado neste ambiente.'];}
            if(!$s['ativo'])throw new RuleViolation('Ative a integração Educbank antes de configurar o retorno.');
            Settings::token($s);$url=ReturnSettings::url($company,$s['ambiente']);
            if($s['ambiente']==='producao'&&parse_url($url,PHP_URL_SCHEME)!=='https')throw new RuleViolation('O endereço do ERP deve usar HTTPS para o retorno em produção.');
            $r=ReturnSettings::ensure($company,$s);$r=ReturnSettings::write($company,$s['ambiente'],['ativo'=>1,'erro'=>null]);$authorization=ReturnSettings::authorization($r);
            try{
                $client=new Client($s);$list=$client->request('GET','/webhook',null,['maxResultCount'=>100]);
                if(array_key_exists('items',$list)&&$list['items']===null&&(int)($list['totalCount']??-1)===0)$list['items']=[];
                if(!array_key_exists('items',$list)||!is_array($list['items']??[])||(int)($list['totalCount']??101)>100)throw new RuleViolation('Lista de webhooks incompleta. Confira no Educbank antes de cadastrar outro retorno.');
                $matches=array_values(array_filter($list['items']??[],fn($w)=>($w['url']??'')===$url||($r['webhook_id']&&($w['id']??'')===$r['webhook_id'])));
                if(count($matches)>1)throw new RuleViolation('Há mais de um webhook deste ERP. Confira as duplicidades no Educbank.');
                $body=['url'=>$url,'authorization'=>$authorization,'event'=>'Invoice.Changed'];
                $response=$matches?$client->request('PUT','/webhook/'.Client::uuid($matches[0]['id']),array_diff_key($body,['event'=>true])+['isEnable'=>true]):$client->request('POST','/webhook',$body);
                $id=Client::uuid($response['id']??null);if(($response['url']??null)!==$url||empty($response['isEnable']))throw new RuleViolation('Educbank não confirmou o webhook ativo para o endereço deste ERP.');
                ReturnSettings::write($company,$s['ambiente'],['webhook_id'=>$id,'webhook_confirmado'=>true,'erro'=>null]);
                $message='Retorno automático ativado. Webhook confirmado e recuperação por consulta habilitada.';
            }catch(RuleViolation $e){
                $error=ResponseError::clip(str_replace($authorization,'[credencial omitida]',$e->getMessage()),400);ReturnSettings::write($company,$s['ambiente'],['webhook_confirmado'=>false,'erro'=>$error]);
                $message='Baixa por consulta ativada. Webhook pendente: '.$error;
            }
            return ['retorno'=>ReturnSettings::publicData($company,$s),'mensagem'=>$message];
        });
    }
    /** Called only after callback authentication; no HTTP or financial write in this request. */
    public function receive(int $company,string $environment,array $payload):array
    {
        $s=Settings::read($company);$r=ReturnSettings::read($company,$environment);
        if($s['ambiente']!==$environment||!$s['ativo']||!$r['ativo'])return ['ok'=>true,'ignorado'=>true];
        $event=EducbankPayment::event($payload);$job=$this->job($company,$environment,$r['tenant'],$event['InvoiceId'],$event['ExternalId']);
        if(!$job)throw new RuleViolation('InvoiceId e ExternalId ainda não foram vinculados a esta coligada/ambiente.');
        $id=$this->enqueue($job,$event,'webhook');
        // The same Paid/Pending payload can recur after a refund and repayment.
        // Re-verify it without adding another inbox row or duplicating a receipt.
        $this->db->query('UPDATE '.$this->db->table('educbank_retornos')." SET status='pendente',proxima_tentativa=NULL WHERE idretorno=%d AND status='processado'",[$id]);
        return ['ok'=>true,'idretorno'=>$id];
    }
    private function job(int $company,string $environment,string $tenant,string $invoice,string $external):?array
    {
        return $this->db->row('SELECT * FROM '.$this->db->table('educbank_fila')." WHERE codcoligada=%d AND ambiente=%s AND tenant=%s AND tipo='fatura' AND idremoto=%s AND external_id=%s",[$company,$environment,$tenant,$invoice,$external]);
    }
    private function enqueue(array $job,array $event,string $origin):int
    {
        $json=wp_json_encode($event);$hash=hash('sha256',wp_json_encode([$job['codcoligada'],$job['ambiente'],$job['tenant'],$event]));$q=$this->db->table('educbank_retornos');
        return $this->db->atomic(function()use($job,$event,$origin,$json,$hash,$q){
            $this->db->query("INSERT INTO $q (codcoligada,ambiente,tenant,idintegracao,invoice_id,external_id,status_remoto,origem,hash_evento,payload_json) VALUES (%d,%s,%s,%d,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE hash_evento=hash_evento",[$job['codcoligada'],$job['ambiente'],$job['tenant'],$job['idintegracao'],$event['InvoiceId'],$event['ExternalId'],$event['Status'],$origin,$hash,$json]);
            return (int)$this->db->row("SELECT idretorno FROM $q WHERE hash_evento=%s",[$hash])['idretorno'];
        });
    }
    /** A provider API response, never an incoming webhook body. */
    public function consume(array $job,array $remote,string $origin='consulta'):array
    {
        $identity=EducbankPayment::event(['InvoiceId'=>$remote['id']??null,'ExternalId'=>$remote['externalId']??null,'Status'=>$remote['state']??null]);
        if($identity['InvoiceId']!==$job['idremoto']||$identity['ExternalId']!==$job['external_id'])throw new RuleViolation('Identificadores da fatura divergem do vínculo local.');
        try{$event=EducbankPayment::fromInvoice($remote);}
        catch(RuleViolation $e){$id=$this->enqueue($job,$identity,$origin);$this->failure($id,$e);throw $e;}
        $id=$this->enqueue($job,$event,$origin);
        try{
            $result=(new EducbankSettlement($this->db))->apply($job,$remote);
            $this->db->update('educbank_retornos',$id,['status'=>'processado','erro'=>null,'proxima_tentativa'=>null,'processado_em'=>gmdate('Y-m-d H:i:s'),'idbaixa'=>$result['idbaixa']??null,'resultado_json'=>wp_json_encode($result)]);
            return $result+['idretorno'=>$id];
        }catch(\Throwable $e){$this->failure($id,$e);throw $e;}
    }
    private function failure(int $id,\Throwable $e):void
    {
        $row=$this->db->get('educbank_retornos',$id);$error=$e instanceof RuleViolation?ResponseError::clip($e->getMessage()):'Falha interna no retorno. Nenhuma baixa parcial foi confirmada; tente reprocessar.';
        $retry=$e instanceof RuleViolation&&(str_starts_with($error,'Sem confirmação de resposta')||preg_match('/HTTP (404|408|429|5\d\d)\b/',$error));
        $this->db->update('educbank_retornos',$id,['status'=>$retry&&(int)$row['tentativas']<8?'erro':'revisao','erro'=>$error,'proxima_tentativa'=>gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min(6,(int)$row['tentativas']))))]);
    }
    public function process(int $company,int $limit=3):array
    {
        $s=Settings::read($company);if(!$this->enabled($company,$s))return ['processados'=>0,'desativado'=>true];
        return $this->locked($company,$s,function()use($company,$s,$limit){
            $rows=$this->db->rows('SELECT * FROM '.$this->db->table('educbank_retornos')." WHERE codcoligada=%d AND ambiente=%s AND tenant=%s AND status IN ('pendente','erro') AND (proxima_tentativa IS NULL OR proxima_tentativa<=%s) ORDER BY idretorno LIMIT %d",[$company,$s['ambiente'],Settings::scope($s)['tenant'],gmdate('Y-m-d H:i:s'),max(1,min(10,$limit))]);
            $client=new Client($s);$start=microtime(true);$count=0;
            foreach($rows as $row){if($count&&microtime(true)-$start>15)break;$id=(int)$row['idretorno'];$this->db->update('educbank_retornos',$id,['tentativas'=>(int)$row['tentativas']+1]);
                try{$job=$this->db->get('educbank_fila',(int)$row['idintegracao']);$remote=$client->request('GET','/invoice/'.Client::uuid($row['invoice_id']));$result=(new EducbankSettlement($this->db))->apply($job,$remote);$this->db->update('educbank_retornos',$id,['status'=>'processado','erro'=>null,'proxima_tentativa'=>null,'processado_em'=>gmdate('Y-m-d H:i:s'),'idbaixa'=>$result['idbaixa']??null,'resultado_json'=>wp_json_encode($result)]);}
                catch(\Throwable $e){$this->failure($id,$e);}$count++;
            }
            return ['processados'=>$count];
        });
    }
    /** One page per invocation; the next invocation continues or wraps. No client-side polling loop. */
    public function reconcile(int $company):array
    {
        $s=Settings::read($company);if(!$this->enabled($company,$s))return ['consultadas'=>0,'desativado'=>true];
        return $this->locked($company,$s,function()use($company,$s){
            $r=ReturnSettings::read($company,$s['ambiente']);$client=new Client($s);$start=microtime(true);
            try{$list=$client->request('GET','/invoice',null,['state'=>'Paid','showCanceledInvoices'=>true,'maxResultCount'=>50,'skipCount'=>(int)$r['cursor'],'sorting'=>'duedate','sortType'=>'asc']);}
            catch(RuleViolation $e){ReturnSettings::write($company,$s['ambiente'],['erro_consulta'=>ResponseError::clip($e->getMessage(),400)]);throw $e;}
            if(array_key_exists('items',$list)&&$list['items']===null&&(int)($list['totalCount']??-1)===0)$list['items']=[];
            if(!array_key_exists('items',$list)||!is_array($list['items']??[])||!isset($list['totalCount']))throw new RuleViolation('Educbank não confirmou uma lista de pagamentos válida.');
            $rows=$list['items']??[];$count=0;$settled=0;$review=0;
            foreach($rows as $remote){$count++;
                // Other systems can have invoices in this tenant. They must not stop this ERP's page.
                if(!is_array($remote)||!is_string($remote['externalId']??null)||$remote['externalId']==='')continue;
                try{$invoice=Client::uuid($remote['id']??null);}catch(RuleViolation $e){continue;}
                $job=$this->job($company,$s['ambiente'],Settings::scope($s)['tenant'],$invoice,$remote['externalId']);if(!$job)continue;
                try{$result=$this->consume($job,$remote);if(($result['acao']??'')==='baixado')$settled++;}catch(\Throwable $e){$review++;}
            }
            $next=(int)$r['cursor']+count($rows);$more=$next<(int)$list['totalCount'];
            ReturnSettings::write($company,$s['ambiente'],['cursor'=>$more?$next:0,'ultima_consulta'=>gmdate('Y-m-d H:i:s'),'erro_consulta'=>null]);
            // Recheck a previously settled invoice too, so missed refund notifications can be recovered.
            if(microtime(true)-$start<15){
                $q=$this->db->table('educbank_recebimentos');$args=[$company,$s['ambiente'],Settings::scope($s)['tenant']];$where="codcoligada=%d AND ambiente=%s AND tenant=%s AND estado_remoto='Paid'";
                $old=$this->db->row("SELECT * FROM $q WHERE $where AND idrecebimento>%d ORDER BY idrecebimento LIMIT 1",[...$args,(int)$r['revisao_cursor']]);
                if(!$old)$old=$this->db->row("SELECT * FROM $q WHERE $where ORDER BY idrecebimento LIMIT 1",$args);
                if($old){$job=$this->db->get('educbank_fila',(int)$old['idintegracao']);try{$remote=$client->request('GET','/invoice/'.Client::uuid($job['idremoto']));$this->consume($job,$remote);}catch(\Throwable $e){$review++;}ReturnSettings::write($company,$s['ambiente'],['revisao_cursor'=>(int)$old['idrecebimento']]);}
            }
            return ['consultadas'=>$count,'baixadas'=>$settled,'conferir'=>$review,'mais'=>$more,'retorno'=>ReturnSettings::publicData($company,$s)];
        });
    }
    public function listing(int $page=1,string $status=''):array
    {
        Access::requireAdmin();$s=Settings::read(Coligadas::current());$args=[Coligadas::current(),$s['ambiente'],Settings::scope($s)['tenant']];$where='r.codcoligada=%d AND r.ambiente=%s AND r.tenant=%s';
        if($status!==''){if(!in_array($status,['pendente','processado','erro','revisao'],true))throw new RuleViolation('Filtro de retorno inválido.');$where.=' AND r.status=%s';$args[]=$status;}
        $table=$this->db->table('educbank_retornos');$total=(int)$this->db->row("SELECT COUNT(*) AS n FROM $table r WHERE $where",$args)['n'];$page=max(1,min(100000,$page));
        $rows=$this->db->rows("SELECT r.*,a.ra,p.nome AS aluno FROM $table r JOIN ".$this->db->table('educbank_fila').' q ON q.idintegracao=r.idintegracao JOIN '.$this->db->table('matriculas').' m ON m.idmatricula=q.idmatricula JOIN '.$this->db->table('alunos').' a ON a.idaluno=m.idaluno JOIN '.$this->db->table('pessoas')." p ON p.codpessoa=a.codpessoa WHERE $where ORDER BY r.idretorno DESC LIMIT 20 OFFSET %d",[...$args,($page-1)*20]);
        foreach($rows as &$row){$row['aviso']=json_decode($row['payload_json'],true);$row['resultado']=$row['resultado_json']?json_decode($row['resultado_json'],true):null;unset($row['payload_json'],$row['resultado_json'],$row['hash_evento']);}unset($row);
        return ['items'=>$rows,'total'=>$total,'page'=>$page];
    }
    public function retry(int $id):array
    {
        Access::requireAdmin();$row=$this->db->get('educbank_retornos',$id);$s=Settings::read(Coligadas::current());
        if((int)$row['codcoligada']!==Coligadas::current()||$row['ambiente']!==$s['ambiente']||$row['tenant']!==Settings::scope($s)['tenant'])throw new RuleViolation('Retorno de outra coligada ou ambiente.');
        $this->db->update('educbank_retornos',$id,['status'=>'pendente','erro'=>null,'proxima_tentativa'=>null,'tentativas'=>0]);return ['enfileirado'=>true];
    }
}
