<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts,MenuPolicy};
use EducacionalERP\Application\{Operations,CatalogService,AcademicService,StudentWorkflow,SchoolSettings,RenewalService,FinanceService,DeletionService};
use EducacionalERP\Domain\RuleViolation;
Access::install();$db=new Database($wpdb);$ops=new Operations($db);$cat=new CatalogService($db,$ops);$academic=new AcademicService($db,$ops);$flow=new StudentWorkflow($db,$ops,$cat,$academic);$access=new Access($db);$renew=new RenewalService($db,$ops,$academic,$access);$n=0;
function keyP():string{return bin2hex(random_bytes(16));}
function testP(bool $condition,string $label):void{global $n;if(!$condition)throw new RuntimeException($label);$n++;echo "PASS: $label\n";}
function deniedP(callable $fn,string $label):void{try{$fn();}catch(RuleViolation $e){testP(true,$label);return;}throw new RuntimeException($label);}
function addP(string $type,array $data):int{global $cat;return (int)$cat->create($type,$data,keyP())['id'];}
function parcelsP(int $id):array{global $db;return $db->rows('SELECT * FROM '.$db->table('parcelas').' WHERE idcontrato=%d ORDER BY numero',[$id]);}
$period=addP('periodos_letivos',['codigo'=>'2026','descricao'=>'Atual','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']);$next=addP('periodos_letivos',['codigo'=>'2027','descricao'=>'Próximo','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31']);update_option('ederp_current_period',$period);
deniedP(fn()=>$cat->edit('periodos_letivos',$period,['codperiodo_proximo'=>$period],keyP()),'próximo período não pode ser o próprio');
deniedP(fn()=>$cat->edit('periodos_letivos',$next,['codperiodo_proximo'=>$period],keyP()),'próximo período não pode retroceder');
$cat->edit('periodos_letivos',$period,['codperiodo_proximo'=>$next],keyP());
testP((int)$db->get('periodos_letivos',$period)['codperiodo_proximo']===$next,'próximo período salvo');
deniedP(fn()=>$cat->edit('periodos_letivos',$next,['data_inicio'=>'2025-01-01'],keyP()),'mudança de data não invalida período de origem');
$plan=addP('planos_pagamento',['codperiodo'=>$period,'codigo'=>'AN','nome'=>'Anuidade regular','valor_anuidade'=>'1000.01']);$course=addP('cursos',['codigo'=>'EF','nome'=>'Fundamental']);$shift=addP('turnos',['codigo'=>'M','nome'=>'Manhã']);
$base=['idplano'=>$plan,'idcurso'=>$course,'idturno'=>$shift,'capacidade'=>10];$t=addP('turmas',$base+['codperiodo'=>$period,'codigo'=>'A','nome'=>'Turma A']);$futurePlan=addP('planos_pagamento',['codperiodo'=>$next,'codigo'=>'AN27','nome'=>'Anuidade 2027','valor_anuidade'=>'1500.00']);$future=addP('turmas',array_merge($base,['idplano'=>$futurePlan])+['codperiodo'=>$next,'codigo'=>'B','nome'=>'Turma B']);
deniedP(fn()=>addP('planos_pagamento',['codigo'=>'SEM','nome'=>'Sem período','valor_anuidade'=>'1000.00']),'plano exige período');
deniedP(fn()=>addP('turmas',$base+['codperiodo'=>$next,'codigo'=>'ERR','nome'=>'Errada']),'turma rejeita plano de outro período');
deniedP(fn()=>$cat->edit('planos_pagamento',$plan,['codperiodo'=>$next],keyP()),'plano em uso não muda para outro período');
deniedP(fn()=>$cat->edit('turmas',$t,['idturma_proxima'=>$t],keyP()),'destino não pode ser a própria turma');
deniedP(fn()=>$cat->edit('turmas',$future,['idturma_proxima'=>$t],keyP()),'destino deve ser de período posterior');
$cat->edit('turmas',$t,['idturma_proxima'=>$future],keyP());
deniedP(fn()=>$cat->edit('periodos_letivos',$period,['codperiodo_proximo'=>null],keyP()),'vínculo de próxima turma impede remover próximo período');

$cat->edit('planos_pagamento',$futurePlan,['valor_anuidade'=>'1500.00'],keyP());
$rf=addP('pessoas',['nome'=>'Responsavel Plano','data_nascimento'=>'1980-01-01']);$rf2=addP('pessoas',['nome'=>'Outro Responsavel','data_nascimento'=>'1981-01-01']);$a=$flow->createStudent(['nome'=>'Aluno Plano','data_nascimento'=>'2015-01-01','ra'=>'00077'],keyP());$student=(int)$a['idaluno'];
$cat->link($student,['codpessoa_responsavel'=>$rf,'parentesco'=>'mae','responsavel_financeiro'=>1,'responsavel_academico'=>1,'pode_rematricular'=>1],keyP());$cat->link($student,['codpessoa_responsavel'=>$rf2,'parentesco'=>'pai','responsavel_academico'=>1],keyP());
$initial=$flow->batch(['codperiodo'=>$period,'idturma'=>$t,'alunos'=>[$student],'quantidade_parcelas'=>3,'primeiro_vencimento'=>'2026-01-31','valor_original_total'=>'0.01','idplano'=>$plan,'plano_versao'=>1],keyP())['items'][0];$ct=(int)$initial['idcontrato'];$snapshot=$db->get('contratos',$ct);$parcels=parcelsP($ct);
testP($snapshot['valor_original_total']==='1000.01','anuidade vem do plano mesmo se cliente envia valor diferente');
testP(count($parcels)===3&&$parcels[0]['valor_original']==='333.34'&&$parcels[2]['valor_original']==='333.33','número escolhido divide anuidade preservando centavos');
testP($parcels[1]['vencimento_original']==='2026-02-28','parcelas respeitam fim do mês');
testP((int)$snapshot['idplano']===$plan&&$snapshot['plano_nome_snapshot']==='ANUIDADE REGULAR','contrato guarda referência e cópia do plano');
$cat->edit('planos_pagamento',$plan,['versao'=>1,'valor_anuidade'=>'1500.00'],keyP());testP($db->get('contratos',$ct)['valor_original_total']==='1000.01','alteração de plano não reescreve contrato existente');
deniedP(fn()=>(new DeletionService($db,$ops))->remove('planos_pagamento',$plan,['versao'=>2,'motivo'=>'Teste'],keyP()),'plano vinculado não pode ser excluído');
$offer=$renew->createOffer(['codperiodo_destino'=>$next,'idcurso_origem'=>$course,'idcurso_destino'=>$course,'data_abertura'=>'2026-01-01','data_encerramento'=>'2028-12-31','numero_parcelas'=>10,'primeiro_vencimento'=>'2027-01-31','turmas'=>[$future],'texto_termo'=>'Termo de renovação','versao_termo'=>'1'],keyP());
$rfUser=(int)(new Accounts($db))->summary($rf)['wp_user_id'];wp_set_current_user($rfUser);
testP(SchoolSettings::forViewer('todos')===$period&&SchoolSettings::forViewer($next,'finance')===$period,'responsável não contorna período vigente pela API');
testP(count((new SchoolSettings($db))->read()['periodos'])===1,'responsável só recebe configuração do período vigente');
$db->update('turmas',$t,['idturma_proxima'=>null]);testP($renew->offers($student)===[],'sem próxima turma não oferece escolha livre');$db->update('turmas',$t,['idturma_proxima'=>$future]);$offers=$renew->offers($student);testP(count($offers[0]['turmas'])===1&&(int)$offers[0]['turmas'][0]['idturma']===$future,'oferta só retorna o destino definido');testP($offers[0]['turmas'][0]['plano']['valor_anuidade']==='1500.00','rematrícula apresenta anuidade do plano da turma futura');
$db->update('periodos_letivos',$period,['codperiodo_proximo'=>null]);testP($renew->offers($student)===[],'sem próximo período não exibe oferta');$db->update('periodos_letivos',$period,['codperiodo_proximo'=>$next]);
$payload=['idoferta'=>$offer['idoferta'],'idmatricula_origem'=>$initial['idmatricula'],'idturma'=>$future,'aceite'=>true,'versao_termo'=>'1','idplano'=>$futurePlan,'plano_versao'=>1,'quantidade_parcelas'=>5];
testP($offers[0]['indisponivel']===true,'saldo em aberto bloqueia início');
deniedP(fn()=>$renew->renew($payload,keyP()),'API bloqueia confirmação com parcelas em aberto');
// Temporarily clear the ledger to exercise renewal; restore it for downstream finance fixtures.
$openFixture=$db->rows('SELECT * FROM '.$db->table('lancamentos'));
foreach($openFixture as $title)$db->update('lancamentos',(int)$title['idlancamento'],['saldo_aberto'=>'0.00']);
testP($renew->offers($student)[0]['indisponivel']===false,'regularização libera oferta');
$sample=$openFixture[0];$db->update('lancamentos',(int)$sample['idlancamento'],['saldo_aberto'=>'0.01','vencimento'=>'2030-01-01','status'=>'parcial']);
testP($renew->offers($student)[0]['indisponivel']===true,'parcela futura com saldo parcial também bloqueia');
$db->update('lancamentos',(int)$sample['idlancamento'],['status'=>'cancelado']);
testP($renew->offers($student)[0]['indisponivel']===false,'lançamento cancelado não bloqueia');
$db->update('lancamentos',(int)$sample['idlancamento'],['saldo_aberto'=>'0.00','status'=>$sample['status'],'vencimento'=>$sample['vencimento']]);

$db->update('periodos_letivos',$period,['codperiodo_proximo'=>null]);deniedP(fn()=>$renew->renew($payload,keyP()),'API exige próximo período configurado');$db->update('periodos_letivos',$period,['codperiodo_proximo'=>$next]);
deniedP(fn()=>$renew->renew(array_merge($payload,['idturma'=>$t]),keyP()),'API rejeita troca do destino pelo responsável');
deniedP(fn()=>$renew->renew($payload,keyP()),'plano alterado desde a consulta impede aceite desatualizado');$payload['plano_versao']=2;$key=keyP();$result=$renew->renew($payload,$key);$pending=(int)$result['idcontrato'];
foreach($openFixture as $title)$db->update('lancamentos',(int)$title['idlancamento'],['saldo_aberto'=>$title['saldo_aberto']]);
testP($renew->renew($payload,$key)===$result,'reenvio de rematrícula não duplica contrato');
testP(count(parcelsP($pending))===0&&(int)$db->get('contratos',$pending)['parcelas_geradas']===0,'rematrícula cria contrato sem parcelas');
testP($db->get('contratos',$pending)['quantidade_parcelas']==='5','rematrícula preserva quantidade escolhida');
testP(count($db->rows('SELECT * FROM '.$db->table('lancamentos')))===3,'rematrícula não cria lançamentos financeiros');
deniedP(fn()=>$academic->generateInstallments($pending,keyP()),'responsável financeiro não é operador Financeiro');
wp_set_current_user(1);$staff=wp_insert_user(['user_login'=>'secretaria.teste','role'=>'erp_secretaria']);wp_set_current_user($staff);deniedP(fn()=>$academic->generateInstallments($pending,keyP()),'Secretaria não gera parcelas de rematrícula');
wp_set_current_user(1);(new FinanceService($db,$ops))->changeGuardian($student,['codpessoa_nova'=>$rf2,'motivo'=>'Troca antes da geração'],keyP());
$cat->edit('planos_pagamento',$plan,['versao'=>2,'valor_anuidade'=>'1800.00'],keyP());$employee=wp_insert_user(['user_login'=>'financeiro.teste','role'=>'erp_financeiro']);wp_set_current_user($employee);
testP(Access::canGenerate()&&MenuPolicy::can('financeiro'),'perfil Financeiro tem acesso à geração');$key=keyP();$generated=$academic->generateInstallments($pending,$key);
testP(count(parcelsP($pending))===5,'Financeiro gera quantidade contratada');testP(parcelsP($pending)[0]['valor_original']==='300.00','geração posterior usa valor contratado e não plano alterado');
$rows=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d',[$pending]);testP(count($rows)===5&&(int)$rows[0]['codpessoa_rf_atual']===$rf2,'lançamentos pertencem ao responsável atual após troca');
testP($academic->generateInstallments($pending,$key)===$generated,'reenvio da geração é idempotente');$again=$academic->generateInstallments($pending,keyP());testP($again['ja_geradas']&&count(parcelsP($pending))===5,'nova solicitação também não duplica parcelas');
wp_set_current_user((int)(new Accounts($db))->summary((int)$a['codpessoa'])['wp_user_id']);testP(SchoolSettings::forViewer($next)===$period,'aluno também fica restrito ao período vigente');update_option('ederp_current_period',0);deniedP(fn()=>SchoolSettings::forViewer('todos'),'sem período vigente não libera histórico ao portal');
wp_set_current_user(1);update_option('ederp_current_period',$period);$bare=addP('turmas',['idcurso'=>$course,'idturno'=>$shift,'codperiodo'=>$next,'codigo'=>'SEM','nome'=>'Sem plano','capacidade'=>10]);deniedP(fn()=>$academic->planForClass($bare),'turma sem plano não permite orçamento');
$other=$flow->createStudent(['nome'=>'Sem Responsavel','data_nascimento'=>'2015-01-01','ra'=>'00078'],keyP());$before=count($db->rows('SELECT * FROM '.$db->table('matriculas')));deniedP(fn()=>$flow->batch(['codperiodo'=>$period,'idturma'=>$t,'alunos'=>[$other['idaluno']],'quantidade_parcelas'=>3,'primeiro_vencimento'=>'2026-01-01'],keyP()),'matrícula sem responsável financeiro é bloqueada');testP(count($db->rows('SELECT * FROM '.$db->table('matriculas')))===$before,'falha financeira reverte matrícula inicial');
echo "PASS: $n verificações de planos e geração financeira\n";
