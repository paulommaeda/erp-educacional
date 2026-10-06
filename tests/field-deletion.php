<?php
require __DIR__.'/field-groups.php';
wp_set_current_user(1);
$g2=$s->saveGroup(['nome'=>'Dados de Saúde'],kk());check($g2['nome']==='Dados de Saúde','group label preserves capitalization');
$f=$s->save(['idgrupo'=>$g2['idgrupo'],'nome'=>'Observação médica','chave'=>'observacao_medica','tipo'=>'texto'],kk());check($f['nome']==='Observação médica','field label preserves capitalization');
$cat->updatePerson($p,['campos_adicionais'=>['observacao_medica'=>'teste']],kk());
reject(fn()=>$s->remove('grupos_campos',(int)$g2['idgrupo'],['versao'=>$g2['versao']],kk()),'group with fields cannot be deleted');
reject(fn()=>$s->remove('campos_adicionais',(int)$f['idcampo'],['versao'=>$f['versao']],kk()),'field deletion needs explicit confirmation');
reject(fn()=>$s->remove('campos_adicionais',(int)$f['idcampo'],['versao'=>999,'confirmar_exclusao'=>true],kk()),'stale deletion denied');
$k=kk();$d=['versao'=>$f['versao'],'confirmar_exclusao'=>true];$result=$s->remove('campos_adicionais',(int)$f['idcampo'],$d,$k);check($result['valores_excluidos']===1,'field deletion removes its values');check($s->remove('campos_adicionais',(int)$f['idcampo'],$d,$k)===$result,'delete retry idempotent');
check(!$db->row('SELECT * FROM '.$db->table('pessoa_campos_adicionais').' WHERE idcampo=%d',[$f['idcampo']]),'no orphan values');
$s->remove('grupos_campos',(int)$g2['idgrupo'],['versao'=>$g2['versao']],kk());check(!$db->row('SELECT * FROM '.$db->table('grupos_campos').' WHERE idgrupo=%d',[$g2['idgrupo']]),'empty group deleted');
echo "FIELD_DELETION_OK\n";
