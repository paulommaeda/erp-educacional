<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{ContractText,EnrollmentStatus,Input,Money,RuleViolation,Store};
use EducacionalERP\Infrastructure\WordPress\Access;

final class ContractRepricing
{
    public function __construct(private Store $db,private Operations $ops) {}

    public function options(int $id):array
    {
        Access::requireManageContracts();
        $contract=$this->contract($id);
        $enrollment=$this->db->get('matriculas',(int)$contract['idmatricula']);
        $table=$this->db->table('planos_pagamento');
        $plans=$this->db->rows("SELECT idplano,nome,valor_anuidade,versao FROM $table WHERE codcoligada=%d AND codperiodo=%d AND ativo=1 ORDER BY nome,idplano",
            [Coligadas::current(),$enrollment['codperiodo']]);
        return ['versao'=>$contract['versao'],'plano'=>$contract['plano_nome_snapshot'],'idplano'=>$contract['idplano'],
            'assinado'=>ContractText::signed($contract),'desconto_manual'=>$this->manualDiscount($contract),'planos'=>$plans];
    }

    public function run(int $id,array $data,string $key):array
    {
        Access::requireManageContracts();
        if(array_diff(array_keys($data),['versao','motivo','confirmado','idplano','plano_versao','desconto_manual'])||($data['confirmado']??false)!==true){
            throw new RuleViolation('Confirme o recálculo deste contrato.');
        }
        $version=Input::id($data['versao']??null);
        $reason=Input::text($data['motivo']??null,2000);
        $planId=isset($data['idplano'])?Input::id($data['idplano']):null;
        return ContractTextLock::run($this->db,fn()=>$this->db->atomic(fn()=>$this->ops->run($key,'regerar_contrato',['idcontrato'=>$id]+$data,
            fn()=>$this->rebuild($id,$data,$version,$reason,$planId,$key))));
    }

    private function rebuild(int $id,array $data,int $version,string $reason,?int $planId,string $key):array
    {
        $contract=$this->contract($id);
        $enrollment=$this->db->get('matriculas',(int)$contract['idmatricula']);
        $this->db->get('alunos',(int)$enrollment['idaluno'],true);
        $enrollment=$this->db->get('matriculas',(int)$enrollment['idmatricula'],true);
        $contract=$this->db->get('contratos',$id,true);
        if((int)$contract['versao']!==$version)throw new RuleViolation('O contrato foi alterado. Reabra o resumo antes de prosseguir.');
        if($contract['status']!=='ativo'||$contract['excluido_em']||$enrollment['excluido_em']||!EnrollmentStatus::open($enrollment['status'])){
            throw new RuleViolation('Selecione um contrato e uma matrícula ativos.');
        }
        $signed=ContractText::signed($contract);
        if($planId!==null)ContractText::requireEditable($contract);
        $signatures=$this->db->table('assinatura_envios');
        if(!$signed&&$this->db->row("SELECT idenvio FROM $signatures WHERE idcontrato=%d AND situacao IN ('preparado','draft','sent','viewed','signed') LIMIT 1 FOR UPDATE",[$id])){
            throw new RuleViolation('Confira ou encerre o envio para assinatura antes de regerar o contrato.');
        }
        $generated=(bool)$contract['parcelas_geradas'];
        if($generated&&!current_user_can('erp_ajustar_lancamentos'))throw new RuleViolation('Seu perfil não pode alterar lançamentos já gerados.');
        $titles=$this->titles($id);
        $count=(int)$contract['quantidade_parcelas'];
        $eligible=array_values(array_filter($titles,static fn($title)=>$title['idlancamento']!==null&&!$title['excluido_em']
            &&$title['status']!=='cancelado'&&!$title['tem_baixa']&&Money::cents($title['valor_baixa'])===0&&!$title['fatura_encerrada']));
        $preserved=count($titles)-count($eligible);
        if($generated&&!$eligible)throw new RuleViolation('Este contrato não tem parcelas disponíveis para recálculo.');
        if((!$generated&&$titles)||($planId!==null&&$generated&&($preserved||count($titles)!==$count))){
            throw new RuleViolation('A troca integral do plano requer que todas as parcelas geradas estejam disponíveis e sem histórico de baixa.');
        }
        $existingManual=$this->manualDiscount($contract);
        $manual=$data['desconto_manual']??$existingManual;
        if($manual===null)throw new RuleViolation('Informe o desconto manual total previsto no contrato, inclusive zero, para este cadastro antigo.');
        $manual=Money::cents($manual);
        if($manual<0)throw new RuleViolation('O desconto manual não pode ser negativo.');
        if($signed&&$existingManual!==null&&$manual!==Money::cents($existingManual))throw new RuleViolation('O desconto manual acordado no contrato assinado está bloqueado.');
        if($preserved&&$existingManual!==null&&$manual!==Money::cents($existingManual))throw new RuleViolation('O desconto manual total envolve parcelas com histórico preservado. Use o recálculo mantendo o desconto contratual.');

        $face=Money::positive($contract['valor_original_total']);
        $punctuality=ContractDiscounts::punctuality($contract);
        $fields=[];
        if($planId!==null){
            $plan=$this->db->get('planos_pagamento',$planId,true);
            if((int)$plan['codcoligada']!==Coligadas::current()||(int)$plan['codperiodo']!==(int)$enrollment['codperiodo']||!(int)$plan['ativo']){
                throw new RuleViolation('Selecione um plano ativo do período letivo e da coligada do contrato.');
            }
            if((int)$plan['versao']!==Input::id($data['plano_versao']??null))throw new RuleViolation('O plano de pagamento mudou. Reabra a seleção.');
            $face=Money::positive($plan['valor_anuidade']);
            $punctuality=ContractDiscounts::punctuality(['codcoligada'=>$contract['codcoligada']]);
            $fields=['idplano'=>$planId,'plano_nome_snapshot'=>$plan['nome'],'plano_versao_snapshot'=>$plan['versao']];
        }
        $base=$planId===null&&$contract['mensalidade_base_total']!==null?Money::cents($contract['mensalidade_base_total']):$face-$punctuality*$count;
        if($base<=$manual||$punctuality<0)throw new RuleViolation('O valor do contrato deve superar a pontualidade e o desconto manual.');
        Money::split($base-$manual,$count);
        $fields+=['desconto_base_total'=>Money::format($manual),'mensalidade_base_total'=>Money::format($base),'pontualidade_snapshot'=>Money::format($punctuality)];
        if($planId!==null)$fields['valor_original_total']=Money::format($face);

        $changed=EducbankOutbox::without(function()use($id,$fields,$contract,$eligible,$manual,$planId,$key){
            $this->db->update('contratos',$id,$fields);
            $scholarships=new Scholarships($this->db);
            $changed=$scholarships->rebuildTitles(array_replace($contract,$fields),$eligible,$manual,$planId!==null,$key);
            $scholarships->totalsInside($id);
            (new ContractTemplates($this->db,$this->ops))->refreshInside($id);
            return $changed;
        });
        if($changed)EnrollmentLifecycle::syncFirstPayment($this->db,$id,$key);
        foreach($changed as $title)EducbankOutbox::changed($this->db,'lancamentos',$title);
        $this->db->audit('contratos',$id,$planId===null?'regerar_contrato':'trocar_plano_pagamento',$contract,
            ['motivo'=>$reason,'parcelas_alteradas'=>$changed,'parcelas_preservadas'=>$preserved,'contrato'=>$this->db->get('contratos',$id)],$key);
        return ['parcelas_alteradas'=>count($changed),'parcelas_preservadas'=>$preserved,'plano_alterado'=>$planId!==null];
    }

    private function contract(int $id):array
    {
        $contract=$this->db->get('contratos',$id);
        if((int)$contract['codcoligada']!==Coligadas::current()||$contract['excluido_em'])throw new RuleViolation('Contrato indisponível nesta coligada.');
        return $contract;
    }

    private function manualDiscount(array $contract):?string
    {
        if($contract['desconto_base_total']!==null)return $contract['desconto_base_total'];
        $audit=$this->db->table('auditoria');
        $event=$this->db->row("SELECT depois_json FROM $audit WHERE codcoligada=%d AND entidade='contratos' AND entidade_id=%d AND acao='criar' ORDER BY idauditoria LIMIT 1",
            [Coligadas::current(),$contract['idcontrato']]);
        $original=json_decode($event['depois_json']??'null',true);
        return $original['desconto_base_total']??$original['desconto_incondicional_total']??null;
    }

    private function titles(int $id):array
    {
        $installments=$this->db->table('parcelas');
        $titles=$this->db->table('lancamentos');
        $adjustments=$this->db->table('lancamento_ajustes');
        $payments=$this->db->table('baixas');
        $invoices=$this->db->table('educbank_fila');
        $rows=$this->db->rows("SELECT l.*,p.numero FROM $installments p LEFT JOIN $titles l ON l.idparcela=p.idparcela
            WHERE p.idcontrato=%d ORDER BY p.numero FOR UPDATE",[$id]);
        $ids=array_values(array_filter(array_column($rows,'idlancamento')));
        if(!$ids)return $rows;
        $slots=implode(',',array_fill(0,count($ids),'%d'));
        $paid=array_fill_keys(array_column($this->db->rows("SELECT idlancamento FROM $payments WHERE idlancamento IN ($slots) FOR UPDATE",$ids),'idlancamento'),true);
        $closed=array_fill_keys(array_column($this->db->rows("SELECT idlancamento FROM $invoices WHERE idlancamento IN ($slots) AND status_remoto IN ('Paid','Canceled')",$ids),'idlancamento'),true);
        $manual=[];
        foreach($this->db->rows("SELECT idlancamento,valor_delta FROM $adjustments WHERE idlancamento IN ($slots) AND componente='desconto_incondicional' FOR UPDATE",$ids) as $row){
            $title=$row['idlancamento'];
            $manual[$title]=($manual[$title]??0)+Money::cents((string)$row['valor_delta']);
        }
        foreach($rows as &$row){
            $title=$row['idlancamento']??0;
            $row['tem_baixa']=isset($paid[$title]);
            $row['fatura_encerrada']=isset($closed[$title]);
            $row['ajuste_incondicional']=$manual[$title]??0;
        }
        unset($row);
        return $rows;
    }
}
