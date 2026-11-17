<?php
declare(strict_types=1);
/** Reconstructs pre-schema45 records to keep coverage of the grandfathered pricing contract. */
function makeLegacyPricing(\EducacionalERP\Domain\Store $db,int $id):void
{
    $c=$db->get('contratos',$id);$manual=$c['desconto_base_total']??$c['desconto_incondicional_total'];$db->update('contratos',$id,['desconto_incondicional_total'=>$manual,'mensalidade_base_total'=>null,'desconto_base_total'=>null,'pontualidade_snapshot'=>null]);
    foreach($db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d',[$id]) as $l){$fields=['pontualidade_acrescimo'=>'0.00','pontualidade_snapshot'=>null,'bolsas_json'=>null,'bolsa_aplicada'=>'0.00'];$db->update('lancamentos',(int)$l['idlancamento'],$fields);$db->update('parcelas',(int)$l['idparcela'],$fields);}
}
