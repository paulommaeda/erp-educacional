<?php
declare(strict_types=1);
/** Reconstructs pre-schema45 records to keep coverage of the grandfathered pricing contract. */
function makeLegacyPricing(\EducacionalERP\Domain\Store $db,int $id):void
{
    $c=$db->get('contratos',$id);$base=$c['mensalidade_base_total']??$c['valor_original_total'];$manual=$c['desconto_base_total']??$c['desconto_incondicional_total'];$db->update('contratos',$id,['valor_original_total'=>$base,'desconto_incondicional_total'=>$manual,'valor_liquido_total'=>\EducacionalERP\Domain\Money::format(\EducacionalERP\Domain\Money::cents($base)-\EducacionalERP\Domain\Money::cents($manual)),'mensalidade_base_total'=>null,'desconto_base_total'=>null,'pontualidade_snapshot'=>null]);
    foreach($db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d',[$id]) as $l){$p=\EducacionalERP\Domain\Money::cents($l['pontualidade_acrescimo']);$fields=['valor_original'=>\EducacionalERP\Domain\Money::format(\EducacionalERP\Domain\Money::cents($l['valor_original'])-$p),'valor_liquido'=>\EducacionalERP\Domain\Money::format(\EducacionalERP\Domain\Money::cents($l['valor_liquido'])-$p),'pontualidade_acrescimo'=>'0.00','pontualidade_snapshot'=>null,'bolsas_json'=>null,'bolsa_aplicada'=>'0.00'];$db->update('lancamentos',(int)$l['idlancamento'],$fields+['saldo_aberto'=>\EducacionalERP\Domain\Money::format(\EducacionalERP\Domain\Money::cents($l['saldo_aberto'])-$p)]);$db->update('parcelas',(int)$l['idparcela'],$fields);}
}
