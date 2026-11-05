<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Store,Input,Money,FinancialStatus,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;

/** Operational removal preserves payments, foreign keys and the audit trail. Never refunds a payer. */
final class FinancialDeletion
{
    public function __construct(private Store $db,private Operations $operations) {}

    public function remove(int $id,array $data,string $key):array
    {
        if(!Access::canDeleteFinancial())throw new RuleViolation('Sem permissão para excluir lançamentos financeiros.');
        if(array_diff(array_keys($data),['versao','motivo']))throw new RuleViolation('Envie apenas a versão e o motivo da exclusão.');
        $version=Input::id($data['versao']??null);$reason=Input::text($data['motivo']??null,2000);
        return $this->db->atomic(fn()=>$this->operations->run($key,'excluir_lancamento',['idlancamento'=>$id]+$data,function()use($id,$version,$reason,$key){
            // The same lock order as settlement prevents a payment racing an unpaid deletion.
            [$title]=(new FinanceService($this->db,$this->operations))->lockTitle($id,true);
            if((int)$title['codcoligada']!==Coligadas::current())throw new RuleViolation('Lançamento de outra coligada.');
            if((string)$title['versao']!==(string)$version)throw new RuleViolation('Lançamento alterado. Atualize a listagem antes de excluir.');
            $paid=FinancialStatus::value($title)==='baixado'||Money::cents($title['valor_baixa'])>0||in_array($title['status'],['quitado','baixado','pago'],true);
            $payment=$this->db->row('SELECT b.idbaixa FROM '.$this->db->table('baixas').' b WHERE b.idlancamento=%d AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('baixa_estornos').' e WHERE e.idbaixa=b.idbaixa) LIMIT 1',[$id]);
            if(($paid||$payment)&&!Access::isAdmin())throw new RuleViolation('Somente o administrador do WordPress pode excluir lançamentos pagos ou com baixa parcial.');
            $when=gmdate('Y-m-d H:i:s');
            EducbankOutbox::without(fn()=>$this->db->update('lancamentos',$id,['excluido_em'=>$when,'excluido_por_wp_user_id'=>get_current_user_id(),'motivo_exclusao'=>$reason]));
            $jobs=$this->db->rows('SELECT * FROM '.$this->db->table('educbank_fila')." WHERE tipo='fatura' AND idlancamento=%d ORDER BY idintegracao FOR UPDATE",[$id]);
            foreach($jobs as $job)self::ignoreJob($this->db,$job);
            $this->db->audit('lancamentos',$id,'excluir',$title,$this->db->get('lancamentos',$id),$key);
            return ['excluido'=>true,'idlancamento'=>(string)$id,'excluido_em'=>$when];
        }));
    }

    public static function excludedJob(Store $db,array $job):bool
    {
        return $job['tipo']==='fatura'&&!empty($job['idlancamento'])&&!empty($db->get('lancamentos',(int)$job['idlancamento'])['excluido_em']);
    }

    /** Also used after in-flight workers finish; a stale job must not reactivate this title. */
    public static function ignoreJob(Store $db,array $job):void
    {
        $db->query('UPDATE '.$db->table('educbank_fila')." SET status='ignorado',geracao=geracao+1,proxima_tentativa=NULL,erro=%s,versao=versao+1 WHERE idintegracao=%d",['Lançamento excluído no ERP. A exclusão não cancela nem estorna a fatura no Educbank.',(int)$job['idintegracao']]);
        $db->query('UPDATE '.$db->table('educbank_retornos')." SET status='processado',erro=NULL,proxima_tentativa=NULL,processado_em=%s,resultado_json=%s,versao=versao+1 WHERE idintegracao=%d AND status IN ('pendente','erro','revisao')",[gmdate('Y-m-d H:i:s'),wp_json_encode(['acao'=>'lancamento_excluido','ignorado'=>true]),(int)$job['idintegracao']]);
    }
}
