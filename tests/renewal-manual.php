<?php
require __DIR__.'/payment-plans.php';
function get_post_type($id){return $id===99?'attachment':'post';}
function get_post_mime_type($id){return 'application/pdf';}
function get_attached_file($id){return __FILE__;}
function wp_get_attachment_url($id){return 'https://example.test/manual.pdf';}
wp_set_current_user(1);$settings=new EducacionalERP\Application\SchoolSettings($db);
$settings->save(['codperiodo'=>$period,'manual_aluno_id'=>99],keyP());$manual=$settings->read()['manual_aluno'];testP($manual['id']===99&&strlen($manual['versao'])===64,'manual configurado com versão do arquivo');
$new=$flow->createStudent(['nome'=>'Aluno Manual','data_nascimento'=>'2010-01-01','ra'=>'MANUAL-TEST'],keyP());$cat->link((int)$new['idaluno'],['codpessoa_responsavel'=>$rf,'parentesco'=>'pai','responsavel_financeiro'=>1,'responsavel_academico'=>1,'pode_rematricular'=>1],keyP());
$enrolled=$academic->enroll(['idaluno'=>$new['idaluno'],'idturma'=>$t,'quantidade_parcelas'=>3,'primeiro_vencimento'=>'2030-01-01'],keyP());
wp_set_current_user($rfUser);$available=$renew->offers((int)$new['idaluno']);testP(!$available[0]['indisponivel']&&$available[0]['manual_aluno']['versao']===$manual['versao'],'parcelas futuras liberam fluxo com manual');
$body=['idoferta'=>$offer['idoferta'],'idmatricula_origem'=>$enrolled['idmatricula'],'idturma'=>$future,'aceite'=>true,'versao_termo'=>'1'];
deniedP(fn()=>$renew->renew($body,keyP()),'API exige aceite do manual');$body+=['aceite_manual'=>true,'manual_versao'=>$manual['versao']];
deniedP(fn()=>$renew->renew(array_replace($body,['manual_versao'=>'arquivo-antigo']),keyP()),'API rejeita manual desatualizado');
deniedP(fn()=>$renew->renew($body+['quantidade_parcelas'=>2],keyP()),'responsável não altera parcelamento');
$result=$renew->renew($body,keyP());testP($db->get('contratos',(int)$result['idcontrato'])['quantidade_parcelas']==='10','contrato usa parcelas da oferta');
$log=$db->rows('SELECT * FROM '.$db->table('auditoria')." WHERE entidade='rematriculas' AND entidade_id=%d AND acao='aceite_manual'",[(int)$result['idrematricula']]);testP(count($log)===1,'aceite manual registrado em auditoria');
