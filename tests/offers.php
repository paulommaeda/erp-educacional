<?php
require __DIR__.'/payment-plans.php';
wp_set_current_user(1);
$payload=['codperiodo_destino'=>$next,'idcurso_origem'=>$course,'idcurso_destino'=>$course,'data_abertura'=>'2026-01-01','data_encerramento'=>'2028-12-31','numero_parcelas'=>10,'primeiro_vencimento'=>'2027-01-31','turmas'=>[$future],'texto_termo'=>'Termo de renovação','versao_termo'=>'1','ativo'=>1];
$used=$renew->listOffers(['codperiodo'=>$next])['items'][0];
testP($used['utilizacoes']===1&&count($used['turmas'])===1,'listagem inclui utilização, turmas e período');
deniedP(fn()=>$renew->changeOffer((int)$used['idoferta'],['version'=>$used['version'],'motivo'=>'Teste'],keyP(),true),'exclusão de oferta utilizada bloqueada');
deniedP(fn()=>$renew->changeOffer((int)$used['idoferta'],array_merge($payload,['version'=>$used['version'],'numero_parcelas'=>11]),keyP()),'oferta utilizada preserva condições aceitas');
$renew->changeOffer((int)$used['idoferta'],array_merge($payload,['version'=>$used['version'],'ativo'=>0]),keyP());testP(!(int)$db->get('ofertas_rematricula',(int)$used['idoferta'])['ativo'],'oferta utilizada pode ser desativada');
$extra=addP('turmas',['codperiodo'=>$next,'idcurso'=>$course,'idturno'=>$shift,'idplano'=>$futurePlan,'codigo'=>'C','nome'=>'Turma C','capacidade'=>10]);$payload['turmas']=[$future,$extra];$key=keyP();$created=$renew->createOffer($payload,$key);testP($renew->createOffer($payload,$key)===$created,'reenvio de lote não duplica oferta');$id=(int)$created['idoferta'];$item=$renew->listOffers(['codperiodo'=>$next])['items'][0];testP(count($item['turmas'])===2,'lote vincula várias turmas em uma transação');
$before=$renew->listOffers([])['total'];deniedP(fn()=>$renew->createOffer(array_merge($payload,['turmas'=>[$future,$t]]),keyP()),'lote rejeita turma de outro período');testP($renew->listOffers([])['total']===$before,'lote inválido não deixa oferta parcial');
$renew->changeOffer($id,array_merge($payload,['version'=>$item['version'],'turmas'=>[$extra],'data_encerramento'=>'2029-01-01']),keyP());deniedP(fn()=>$renew->changeOffer($id,array_merge($payload,['version'=>$item['version']]),keyP()),'edição desatualizada é bloqueada');$item=$renew->listOffers([])['items'][0];testP(count($item['turmas'])===1&&$item['data_encerramento']==='2029-01-01','edição atualiza turmas e datas');
wp_set_current_user($staff);deniedP(fn()=>$renew->changeOffer($id,array_merge($payload,['version'=>$item['version']]),keyP()),'secretaria não edita oferta existente');wp_set_current_user($rfUser);deniedP(fn()=>$renew->listOffers([]),'responsável não consulta gestão de ofertas');wp_set_current_user(1);
$key=keyP();$d=['version'=>$item['version'],'motivo'=>'Teste de exclusão'];$deleted=$renew->changeOffer($id,$d,$key,true);testP($renew->changeOffer($id,$d,$key,true)===$deleted,'exclusão é idempotente');testP(!$db->row('SELECT idturma FROM '.$db->table('oferta_turmas').' WHERE idoferta=%d',[$id]),'exclusão remove vínculos sem deixar órfãos');
echo "PASS: gestão de ofertas concluída\n";
