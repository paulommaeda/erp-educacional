<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Store,Money,FinancialStatus,Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy,SchoolIdentity};
use EducacionalERP\Infrastructure\Pdf\PaymentReceiptPdf;

/** Receipts describe effective local payments; canceled/reversed payments are never inferred. */
final class PaymentReceipt
{
    public function __construct(private Store $db,private Access $access) {}

    public function details(int $id):array
    {
        return $this->db->atomic(fn()=>$this->snapshot($id));
    }

    private function snapshot(int $id):array
    {
        if(!is_user_logged_in()||!MenuPolicy::any(['financeiro','meu_financeiro','alunos']))throw new RuleViolation('Sem autorização para emitir este recibo.');
        $l=$this->db->get('lancamentos',$id);
        $parcel=$this->db->get('parcelas',(int)$l['idparcela']);
        $contract=$this->db->get('contratos',(int)$parcel['idcontrato']);
        $enrollment=$this->db->get('matriculas',(int)$contract['idmatricula']);
        $student=$this->db->get('alunos',(int)$enrollment['idaluno']);
        $company=(int)$l['codcoligada'];
        if(current_user_can('erp_consultar_financeiro')){
            if($company!==Coligadas::current())throw new RuleViolation('Recibo de outra coligada. Selecione a coligada correspondente.');
        }elseif((int)$l['codpessoa_rf_atual']!==$this->access->person()||!$this->access->canStudent((int)$student['idaluno'],'finance')){
            throw new RuleViolation('Sem autorização para emitir este recibo.');
        }
        if(FinancialStatus::value($l)!=='baixado')throw new RuleViolation('O recibo está disponível somente para parcelas pagas. Atualize a consulta.');
        $b=$this->db->table('baixas');$e=$this->db->table('baixa_estornos');$p=$this->db->table('pessoas');
        $payments=$this->db->rows("SELECT b.idbaixa,b.data_pagamento,b.valor_pago,b.forma_pagamento,b.referencia_externa,COALESCE(p.nome,d.nome) AS pagador FROM $b b LEFT JOIN $p p ON p.codpessoa=b.codpessoa_pagador JOIN $p d ON d.codpessoa=b.codpessoa_devedor WHERE b.idlancamento=%d AND b.codcoligada=%d AND NOT EXISTS (SELECT 1 FROM $e e WHERE e.idbaixa=b.idbaixa) ORDER BY b.data_pagamento,b.idbaixa",[$id,$company]);
        $total=0;
        foreach($payments as $payment){Input::date($payment['data_pagamento']);$value=Money::cents($payment['valor_pago']);if($value<0)throw new RuleViolation('Há uma baixa inconsistente. Procure o setor financeiro.');$total+=$value;}
        if(!$payments||$total!==Money::cents($l['valor_baixa']))throw new RuleViolation('Os detalhes dos pagamentos desta parcela precisam de conferência. Procure o setor financeiro para emitir o recibo.');
        $person=$this->db->get('pessoas',(int)$student['codpessoa']);
        $guardian=$this->db->get('pessoas',(int)$l['codpessoa_rf_atual']);
        $owner=$this->db->get('coligadas',$company);
        $identity=Coligadas::within($company,static function()use($owner){
            $saved=get_option(SchoolIdentity::option(),false);$school=SchoolIdentity::read();
            if($saved===false)$school['nome']=$owner['nome'];
            $school['cnpj']=$owner['cnpj'];$school['razao_social']=$owner['razao_social'];
            $school['logo_padrao']=$saved===false&&(int)$owner['codcoligada']===1;
            return $school;
        });
        return ['numero'=>'REC-'.$company.'-'.$id,'emitido_em'=>function_exists('wp_date')?wp_date('d/m/Y H:i'):date('d/m/Y H:i'),
            'colegio'=>$identity,'aluno'=>['nome'=>$person['nome'],'ra'=>$student['ra']],
            'responsavel'=>['nome'=>$guardian['nome'],'cpf'=>$guardian['cpf']],
            'contrato'=>$contract['numero'],'parcela'=>(int)$parcel['numero'],'quantidade_parcelas'=>(int)$contract['quantidade_parcelas'],
            'vencimento'=>Input::date($l['vencimento']),'valor_original'=>$l['valor_original'],'desconto_incondicional'=>$l['desconto_incondicional'],
            'desconto_condicional'=>$l['desconto_condicional_aplicado'],'valor_liquido'=>$l['valor_liquido'],
            'juros'=>$l['juros_aplicados'],'multa'=>$l['multa_aplicada'],'valor_pago'=>Money::format($total),'pagamentos'=>$payments];
    }

    public function download(int $id):array
    {
        $data=$this->details($id);
        return ['nome'=>'recibo-'.$data['numero'].'.pdf','mime'=>'application/pdf','conteudo'=>base64_encode(PaymentReceiptPdf::render($data))];
    }
}
