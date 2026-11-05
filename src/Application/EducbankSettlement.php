<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Money,RuleViolation};
use EducacionalERP\Infrastructure\Educbank\{Client,Settings,ReturnSettings};

/** One atomic, auditable settlement per linked invoice; notification delivery never authorizes money. */
final class EducbankSettlement
{
    private const FIELDS=['valor_original','desconto_incondicional','valor_liquido','desconto_condicional_aplicado','juros_aplicados','multa_aplicada','valor_baixa','saldo_aberto','status','codpessoa_rf_atual'];
    public function __construct(private Store $db){}
    public static function fingerprint(array $title):string {return hash('sha256',wp_json_encode(array_intersect_key($title,array_flip(self::FIELDS))));}
    public function apply(array $job,array $remote):array
    {
        $actor=get_current_user_id();wp_set_current_user(0);
        try{return Coligadas::within((int)$job['codcoligada'],fn()=>EducbankOutbox::without(fn()=>$this->db->atomic(fn()=>$this->inside($job,$remote))));}
        finally{wp_set_current_user($actor);}
    }
    private function inside(array $job,array $remote):array
    {
        $finance=new FinanceService($this->db,new Operations($this->db));
        [$title,$contract]=$finance->lockTitle((int)$job['idlancamento'],true,true);
        if(!empty($title['excluido_em'])){FinancialDeletion::ignoreJob($this->db,$job);return ['acao'=>'lancamento_excluido','ignorado'=>true];}
        $job=$this->db->get('educbank_fila',(int)$job['idintegracao'],true);$company=(int)$job['codcoligada'];
        $settings=Settings::read($company);$returns=ReturnSettings::read($company,$job['ambiente']);
        if(!$returns['ativo']||!$settings['ativo']||$settings['ambiente']!==$job['ambiente']||Settings::scope($settings)['tenant']!==$job['tenant'])throw new RuleViolation('O retorno está desativado ou pertence a outro ambiente/tenant.');
        if($job['tipo']!=='fatura'||Client::uuid($remote['id']??null)!==$job['idremoto']||(string)($remote['externalId']??'')!==$job['external_id']||(int)$title['codcoligada']!==$company)throw new RuleViolation('InvoiceId e ExternalId não correspondem ao lançamento vinculado.');
        $owner=$this->db->get('coligadas',$company);
        if(!$owner['ativo'])throw new RuleViolation('Coligada inativa. Confira o retorno antes de baixar.');
        $cnpj=preg_replace('/\D/','',(string)$owner['cnpj']);
        if(strlen($cnpj)!==14||preg_replace('/\D/','',(string)($remote['companyCnpj']??''))!==$cnpj)throw new RuleViolation('CNPJ do retorno não corresponde à coligada da fatura.');
        $state=$remote['state']??'';if(!in_array($state,['Paid','Pending','Expired','Canceled','Generated','Overdue'],true))throw new RuleViolation('Status da fatura não reconhecido no retorno Educbank.');
        $table=$this->db->table('educbank_recebimentos');$tracking=$this->db->row("SELECT * FROM $table WHERE idintegracao=%d FOR UPDATE",[(int)$job['idintegracao']]);
        if(!$tracking){$id=$this->db->insert('educbank_recebimentos',['codcoligada'=>$company,'ambiente'=>$job['ambiente'],'tenant'=>$job['tenant'],'idintegracao'=>$job['idintegracao']]);$tracking=$this->db->get('educbank_recebimentos',$id,true);}
        $result=['acao'=>'sem_baixa','idbaixa'=>null];
        if($state==='Paid'){
            $this->identity($job,$remote,$title,$contract);
            $payment=EducbankPayment::parse($remote,$title);$hash=hash('sha256',wp_json_encode($payment));
            if($tracking['idbaixa']){
                $this->assertUnchanged($tracking,$title);
                if($tracking['snapshot_hash']===$hash)$result=['acao'=>'ja_processado','idbaixa'=>(int)$tracking['idbaixa'],'valor_pago'=>$payment['valor_pago']];
                else{$this->restore($tracking,$title,$contract,$finance);[$title]=$finance->lockTitle((int)$job['idlancamento']);$tracking=$this->db->get('educbank_recebimentos',(int)$tracking['idrecebimento'],true);}
            }
            if(!$tracking['idbaixa']){
                if($this->manualMatches($title,$payment)){
                    $result=['acao'=>'baixa_local_conciliada','idbaixa'=>null,'valor_pago'=>$payment['valor_pago']];
                    $this->db->update('educbank_recebimentos',(int)$tracking['idrecebimento'],['antes_json'=>wp_json_encode(['origem'=>'manual']),'snapshot_hash'=>$hash,'snapshot_json'=>wp_json_encode($payment),'depois_hash'=>self::fingerprint($title)]);
                }else{
                    if($tracking['estado_remoto']==='Paid'&&$tracking['antes_json']===wp_json_encode(['origem'=>'manual']))throw new RuleViolation('O pagamento conciliado foi alterado localmente. Confira antes de reaplicar.');
                    $before=array_intersect_key($title,array_flip(self::FIELDS));
                    $key=hash('sha256','educbank-baixa|'.$job['idintegracao'].'|'.$tracking['versao'].'|'.$hash);
                    $result=$finance->receiveExternalInside((int)$title['idlancamento'],$payment,$key,'Educbank '.$job['idremoto'])+['acao'=>'baixado'];
                    $after=$this->db->get('lancamentos',(int)$title['idlancamento']);
                    $this->db->update('educbank_recebimentos',(int)$tracking['idrecebimento'],['idbaixa'=>$result['idbaixa'],'snapshot_hash'=>$hash,'snapshot_json'=>wp_json_encode($payment),'antes_json'=>wp_json_encode($before),'depois_hash'=>self::fingerprint($after)]);
                }
            }
        }elseif($tracking['idbaixa']){
            if($state==='Pending'&&in_array($remote['statusReason']??'',['Refund','Chargeback'],true)){
                $this->assertUnchanged($tracking,$title);$this->restore($tracking,$title,$contract,$finance);$result=['acao'=>'estornado','idbaixa'=>null];
            }else throw new RuleViolation('A fatura deixou de estar paga sem confirmação de Refund/Chargeback. Confira a conciliação.');
        }elseif($tracking['estado_remoto']==='Paid'&&$tracking['antes_json']===wp_json_encode(['origem'=>'manual']))throw new RuleViolation('O Educbank desfez um pagamento conciliado com baixa manual. O financeiro precisa conferir o estorno.');
        $this->db->update('educbank_recebimentos',(int)$tracking['idrecebimento'],['estado_remoto'=>$state,'conferido_em'=>gmdate('Y-m-d H:i:s')]);
        $changes=['status_remoto'=>$state];
        if($state==='Paid')$changes+=['status'=>'sincronizado','erro'=>null,'proxima_tentativa'=>null,'sincronizado_em'=>gmdate('Y-m-d H:i:s')];
        $this->db->update('educbank_fila',(int)$job['idintegracao'],$changes);
        return $result+['status_remoto'=>$state];
    }
    private function identity(array $job,array $remote,array $title,array $contract):void
    {
        $m=$this->db->get('matriculas',(int)$contract['idmatricula']);$a=$this->db->get('alunos',(int)$m['idaluno']);$items=$remote['items']??[];$item=is_array($items)&&count($items)===1?$items[0]:[];
        if((int)$job['idmatricula']!==(int)$m['idmatricula'])throw new RuleViolation('A matrícula da fila Educbank difere da matrícula do contrato do lançamento.');
        if(!is_array($items)||count($items)!==1)throw new RuleViolation('A fatura Educbank deve conter exatamente um item vinculado à parcela.');
        $enrollment=$this->db->row('SELECT idremoto FROM '.$this->db->table('educbank_fila')." WHERE codcoligada=%d AND ambiente=%s AND tenant=%s AND tipo='matricula' AND idlocal=%d",[$job['codcoligada'],$job['ambiente'],$job['tenant'],$job['idmatricula']]);
        if(!$enrollment||!$enrollment['idremoto']||strtolower((string)($item['enrollmentId']??''))!==$enrollment['idremoto'])throw new RuleViolation('O UUID da matrícula na fatura Educbank difere da matrícula vinculada ao lançamento.');
        if((string)($item['externalOrderId']??'')!==(string)$contract['idcontrato'])throw new RuleViolation('O contrato (externalOrderId) da fatura Educbank difere do contrato do lançamento.');
        if(!EducbankPayload::studentReference($item['studentExternalId']??null,(string)$a['ra'],(string)$m['idmatricula']))throw new RuleViolation('O identificador do aluno (studentExternalId) não corresponde ao RA nem ao identificador externo da matrícula vinculada.');
        $rf=$this->db->get('pessoas',(int)$title['codpessoa_rf_atual']);
        $cpf=preg_replace('/\D/','',(string)$rf['cpf']);
        if(strlen($cpf)!==11||preg_replace('/\D/','',(string)($remote['payer']['cpfCnpj']??''))!==$cpf)throw new RuleViolation('Responsável da fatura difere do responsável financeiro atual. Confira a troca antes da baixa.');
    }
    private function assertUnchanged(array $tracking,array $title):void
    {
        $payment=$this->db->get('baixas',(int)$tracking['idbaixa'],true);
        if((int)$payment['idlancamento']!==(int)$title['idlancamento']||$this->db->row('SELECT idestorno FROM '.$this->db->table('baixa_estornos').' WHERE idbaixa=%d',[(int)$tracking['idbaixa']])||!hash_equals((string)$tracking['depois_hash'],self::fingerprint($title)))throw new RuleViolation('A baixa Educbank foi alterada ou estornada no ERP. Confira antes de reaplicar o retorno.');
    }
    private function restore(array $tracking,array $title,array $contract,FinanceService $finance):void
    {
        $before=json_decode((string)$tracking['antes_json'],true);
        if(!is_array($before)||array_keys($before)!==array_keys(array_intersect_key($title,array_flip(self::FIELDS))))throw new RuleViolation('Não há composição anterior íntegra para restaurar o lançamento.');
        $key=hash('sha256','educbank-estorno|'.$tracking['idrecebimento'].'|'.$tracking['versao'].'|'.$tracking['idbaixa']);
        $finance->reverseInside((int)$tracking['idbaixa'],['motivo'=>'Estorno/correção confirmado no Educbank.'],$key);
        $afterReverse=$this->db->get('lancamentos',(int)$title['idlancamento']);$this->db->update('lancamentos',(int)$title['idlancamento'],$before);
        $this->db->audit('lancamentos',(int)$title['idlancamento'],'estorno_educbank',$afterReverse,$this->db->get('lancamentos',(int)$title['idlancamento']),$key);
        $this->db->update('educbank_recebimentos',(int)$tracking['idrecebimento'],['idbaixa'=>null,'snapshot_hash'=>null,'snapshot_json'=>null,'depois_hash'=>null]);
        EnrollmentLifecycle::syncFirstPayment($this->db,(int)$contract['idcontrato'],$key);
    }
    private function manualMatches(array $title,array $p):bool
    {
        if($p['forma_pagamento']!=='escola'||$title['status']!=='quitado'||Money::cents($title['saldo_aberto'])!==0)return false;
        foreach(['valor_baixa'=>'valor_pago','desconto_condicional_aplicado'=>'desconto_condicional','juros_aplicados'=>'juros','multa_aplicada'=>'multa'] as $local=>$remote)if(Money::cents($title[$local])!==Money::cents($p[$remote]))return false;
        $b=$this->db->table('baixas');$e=$this->db->table('baixa_estornos');$rows=$this->db->rows("SELECT data_pagamento,valor_pago FROM $b b WHERE idlancamento=%d AND NOT EXISTS (SELECT 1 FROM $e e WHERE e.idbaixa=b.idbaixa) ORDER BY data_pagamento,idbaixa",[(int)$title['idlancamento']]);
        $sum=0;foreach($rows as $r)$sum+=Money::cents($r['valor_pago']);return $rows&&Money::format($sum)===$p['valor_pago']&&end($rows)['data_pagamento']===$p['data_pagamento'];
    }
}
