<?php
require __DIR__.'/payment-plans.php';
use EducacionalERP\Application\{Coligadas,EnrollmentDirectory,IntegrationService,SchoolSettings};
wp_set_current_user(1);$companies=new Coligadas($db);
$second=(int)$companies->save(['nome'=>'COLÉGIO SEGUNDA UNIDADE','razao_social'=>'ESCOLA SEGUNDA LTDA','cnpj'=>'04.252.011/0001-10'],keyP())['codcoligada'];
$peopleBefore=count($db->rows('SELECT * FROM '.$db->table('pessoas')));$usersBefore=(int)$wpdb->pdo->query('SELECT COUNT(*) FROM mock_users')->fetchColumn();
[$destPeriod,$destCourse,$destShift,$destPlan,$destClass]=Coligadas::within($second,function()use($next,$db,$cat){
    $period=addP('periodos_letivos',['codigo'=>'2027','descricao'=>'Próximo','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31']);
    $course=addP('cursos',['codigo'=>'EF','nome'=>'Fundamental 2']);$shift=addP('turnos',['codigo'=>'M','nome'=>'Manhã']);$plan=addP('planos_pagamento',['codperiodo'=>$period,'codigo'=>'AN27','nome'=>'Anuidade destino','valor_anuidade'=>'2400.00']);
    $class=addP('turmas',['codperiodo'=>$period,'idcurso'=>$course,'idturno'=>$shift,'idplano'=>$plan,'codigo'=>'6ANOV','nome'=>'6ANOV','capacidade'=>10]);
    return [$period,$course,$shift,$plan,$class];
});
testP((int)$db->get('turmas',$destClass)['codcoligada']===$second,'turma pertence à segunda coligada');
deniedP(fn()=>addP('turmas',['codperiodo'=>$period,'idcurso'=>$destCourse,'idturno'=>$shift,'codigo'=>'ERR','nome'=>'Errada','capacidade'=>10]),'curso de outra coligada rejeitado');
deniedP(fn()=>$db->update('alunos',$student,['codcoligada'=>$second]),'propriedade da ficha de aluno é imutável');
$cat->classRenewal($t,['idturma_proxima'=>$destClass,'versao'=>$db->get('turmas',$t)['versao']],keyP());
testP($companies->nextPeriod($period,$second)['codperiodo']===(string)$destPeriod,'período equivalente resolvido no destino');
$o=Coligadas::within($second,fn()=>$renew->createOffer(['codperiodo_destino'=>$destPeriod,'idcurso_origem'=>$course,'idcurso_destino'=>$destCourse,'data_abertura'=>'2026-01-01','data_encerramento'=>'2028-12-31','numero_parcelas'=>12,'primeiro_vencimento'=>'2027-01-05','turmas'=>[$destClass],'texto_termo'=>'Contrato segunda coligada','versao_termo'=>'1'],keyP()));
// Preserve every source title, clearing only its balance temporarily for renewal eligibility.
$titles=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$db->table('contratos').' c ON c.idcontrato=p.idcontrato JOIN '.$db->table('matriculas').' m ON m.idmatricula=c.idmatricula WHERE m.idaluno=%d',[$student]);foreach($titles as $title)$db->update('lancamentos',(int)$title['idlancamento'],['saldo_aberto'=>'0.00']);
$oldContract=$db->get('contratos',$ct);wp_set_current_user($rfUser);$offers=$renew->offers($student);$selected=array_values(array_filter($offers,fn($v)=>(int)$v['idoferta']===(int)$o['idoferta']));testP(count($selected)===1&&$selected[0]['muda_coligada']===true,'responsável recebe oferta com CNPJ de destino');
$body=['idoferta'=>$o['idoferta'],'idmatricula_origem'=>$initial['idmatricula'],'idturma'=>$destClass,'aceite'=>true,'versao_termo'=>'1'];$key=keyP();$result=$renew->renew($body,$key);testP($renew->renew($body,$key)===$result,'rematrícula entre coligadas é idempotente');
$newStudent=$db->get('alunos',(int)$result['idaluno']);$newEnrollment=$db->get('matriculas',(int)$result['idmatricula']);$newContract=$db->get('contratos',(int)$result['idcontrato']);
testP((int)$newStudent['codcoligada']===$second&&(int)$newStudent['codpessoa']===(int)$a['codpessoa']&&$newStudent['ra']==='00077','destino tem ficha própria, mesma pessoa e mesmo RA');
testP((int)$newEnrollment['codcoligada']===$second&&(int)$newEnrollment['idmatricula_origem']===(int)$initial['idmatricula'],'nova matrícula mantém referência à origem');
testP((int)$newContract['codcoligada']===$second&&$newContract['valor_original_total']==='2400.00'&&parcelsP((int)$result['idcontrato'])===[],'contrato pertence ao destino e aguarda geração financeira');
testP($db->get('contratos',$ct)===$oldContract,'contrato de origem preservado integralmente');
testP(count($db->rows('SELECT * FROM '.$db->table('pessoas')))===$peopleBefore&&(int)$wpdb->pdo->query('SELECT COUNT(*) FROM mock_users')->fetchColumn()===$usersBefore,'troca não duplica pessoas nem usuários');
testP(count($access->students())===1&&count($access->students()[0]['idalunos'])===2,'mesmo responsável visualiza aluno único com fichas vinculadas nas duas coligadas');
wp_set_current_user(1);$academic->generateInstallments((int)$result['idcontrato'],keyP());$generated=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d',[(int)$result['idcontrato']]);testP(count($generated)===12&&count(array_filter($generated,fn($v)=>(int)$v['codcoligada']===$second))===12,'parcelas e lançamentos herdam coligada do contrato');
$dir=new EnrollmentDirectory($db);$onlySecond=Coligadas::within($second,fn()=>$dir->search(['codperiodo'=>'todos']));testP(count($onlySecond['items'])===1&&(int)$onlySecond['items'][0]['codcoligada']===$second,'consulta de matrículas separa coligadas');
$api=new IntegrationService($db,$ops,$cat);$items=Coligadas::within($second,fn()=>$api->collection('alunos',[]))['items'];testP(count($items)===1&&(int)$items[0]['codcoligada']===$second,'integração filtra alunos por coligada');
// DB itself rejects incompatible same-company references without the application guard.
$bad=$wpdb->update($db->table('matriculas'),['idcurso'=>$course],['idmatricula'=>(int)$result['idmatricula']]);testP($bad===false,'FK composta bloqueia curso de outra coligada no banco');
Coligadas::within($second,fn()=>(new SchoolSettings($db))->save(['codperiodo'=>$destPeriod],keyP()));testP(Coligadas::within($second,fn()=>SchoolSettings::current())===$destPeriod&&SchoolSettings::current()===$period,'período vigente independente em cada coligada');
$lifecycle=new EducacionalERP\Application\EnrollmentLifecycle($db,$ops,$academic);
$fixtureFuture=$db->get('matriculas',(int)$result['idmatricula']);
foreach($db->rows('SELECT * FROM '.$db->table('matriculas').' WHERE idmatricula_origem=%d AND ativo_unico=1 AND idmatricula<>%d',[(int)$initial['idmatricula'],(int)$result['idmatricula']]) as $oldRenewal)$lifecycle->cancel((int)$oldRenewal['idmatricula'],['versao'=>$oldRenewal['versao']],keyP());
addP('turmas',['codperiodo'=>$next,'idcurso'=>$course,'idturno'=>$shift,'idplano'=>$futurePlan,'codigo'=>'A','nome'=>'Repetição A','capacidade'=>10]);
$repeated=$lifecycle->outcome((int)$initial['idmatricula'],['status'=>'reprovado','versao'=>$db->get('matriculas',(int)$initial['idmatricula'])['versao']],keyP());
$replacement=$db->get('matriculas',(int)$repeated['rematricula_substituta']['idmatricula']);testP((int)$replacement['codcoligada']===1,'reprovação retorna aluno à coligada original no próximo período');
testP($db->get('contratos',(int)$result['idcontrato'])['idmatricula']===$newEnrollment['idmatricula'],'reprovação não transfere contrato entre CNPJs');
$replacementContract=$db->get('contratos',(int)$repeated['rematricula_substituta']['idcontrato']);testP((int)$replacementContract['codcoligada']===1&&!(int)$replacementContract['parcelas_geradas'],'novo contrato de repetição aguarda financeiro na origem');
$companies->choose(['codcoligada'=>$second]);testP(Coligadas::current()===$second,'coligada de trabalho por usuário');wp_set_current_user($rfUser);deniedP(fn()=>$companies->choose(['codcoligada'=>$second]),'responsável não altera contexto da equipe');
echo "PASS: coligadas, isolamento relacional e rematrícula entre CNPJs\n";
