<?php
/**
 * Execute only in an isolated WordPress test database:
 * EDERP_TEST_BOOTSTRAP=/absolute/path/wp-load.php php tests/wordpress-integration.php
 * Creates fictitious records and leaves them available for inspection. Never run on production.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli') { exit; }
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');
if(!$bootstrap || !is_file($bootstrap)) { fwrite(STDERR,"Defina EDERP_TEST_BOOTSTRAP para um WordPress descartável.\n"); exit(2); }
require $bootstrap;
require_once dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Application\{Operations,CatalogService,AcademicService,FinanceService,ExportService};
if(!defined('EDERP_TEST_DATABASE') || EDERP_TEST_DATABASE!==true) { throw new RuntimeException('Defina EDERP_TEST_DATABASE=true no wp-config do ambiente descartável.'); }
$admins=get_users(['role'=>'administrator','number'=>1]);
if(!$admins) { throw new RuntimeException('Administrador de teste ausente.'); }
wp_set_current_user($admins[0]->ID);
if(!defined('EDERP_SQLITE_TEST')) {
    (new Installer(new Database($wpdb)))->install();
    (new Installer(new Database($wpdb)))->install(); // Verify repeatable migrations.
}
Access::install();
$db=new Database($wpdb); $ops=new Operations($db); $catalog=new CatalogService($db,$ops); $academic=new AcademicService($db,$ops); $finance=new FinanceService($db,$ops);
$tag=bin2hex(random_bytes(8));
function keyTest(): string { return bin2hex(random_bytes(16)); }
function verify(bool $value,string $message): void { if(!$value) { throw new RuntimeException('FAIL: '.$message); } echo "PASS: $message\n"; }
$person=$catalog->create('pessoas',['data_nascimento'=>'1980-08-15','nome'=>'Aluno Teste '.$tag],keyTest())['id'];
$old=$catalog->create('pessoas',['data_nascimento'=>'1980-08-15','nome'=>'Responsável A '.$tag],keyTest())['id'];
$new=$catalog->create('pessoas',['data_nascimento'=>'1980-08-15','nome'=>'Responsável B '.$tag],keyTest())['id'];
$student=$catalog->create('alunos',['codpessoa'=>$person,'ra'=>'T'.$tag],keyTest())['id'];
$catalog->link((int)$student,['codpessoa_responsavel'=>$old,'parentesco'=>'mae','responsavel_financeiro'=>1,'responsavel_academico'=>1],keyTest());
$catalog->link((int)$student,['codpessoa_responsavel'=>$new,'parentesco'=>'pai','responsavel_academico'=>1,'pode_rematricular'=>1],keyTest());
$period=$catalog->create('periodos_letivos',['codigo'=>'P'.$tag,'descricao'=>'Teste','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31'],keyTest())['id'];
$course=$catalog->create('cursos',['codigo'=>'C'.$tag,'nome'=>'Curso teste'],keyTest())['id'];
$shift=$catalog->create('turnos',['codigo'=>'T'.$tag,'nome'=>'Turno teste'],keyTest())['id'];
$plan=$catalog->create('planos_pagamento',['codigo'=>'PL'.$tag,'nome'=>'Plano teste','valor_anuidade'=>'2400.00'],keyTest())['id'];
update_option('ederp_current_period',(int)$period);
$class1=$catalog->create('turmas',['idplano'=>$plan,'codperiodo'=>$period,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'A'.$tag,'nome'=>'Turma A','capacidade'=>10],keyTest())['id'];
$class2=$catalog->create('turmas',['idplano'=>$plan,'codperiodo'=>$period,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'B'.$tag,'nome'=>'Turma B','capacidade'=>10],keyTest())['id'];
$data=['idaluno'=>$student,'idturma'=>$class1,'valor_original_total'=>'2400.00','desconto_incondicional_total'=>'400.00','quantidade_parcelas'=>2,'primeiro_vencimento'=>'2026-01-31'];
$key=keyTest(); $enrollment=$academic->enroll($data,$key);
verify($academic->enroll($data,$key)===$enrollment,'matrícula idempotente');
try { $academic->enroll($data+['termo'=>'alterado'],$key); verify(false,'reutilização divergente'); } catch(\EducacionalERP\Domain\RuleViolation $e) { verify(true,'reutilização divergente rejeitada'); }
$l=$db->table('lancamentos'); $p=$db->table('parcelas');
$titles=$db->rows("SELECT l.* FROM $l l JOIN $p p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY p.numero",[(int)$enrollment['idcontrato']]);
verify($titles[1]['vencimento']==='2026-02-28','vencimento no fim do mês');
$paymentKey=keyTest(); $payment=$finance->pay((int)$titles[0]['idlancamento'],['valor_pago'=>'400.00','forma_pagamento'=>'pix','data_pagamento'=>'2026-01-10'],$paymentKey);
verify($finance->pay((int)$titles[0]['idlancamento'],['valor_pago'=>'400.00','forma_pagamento'=>'pix','data_pagamento'=>'2026-01-10'],$paymentKey)===$payment,'baixa idempotente');
$paid=$finance->pay((int)$titles[1]['idlancamento'],['valor_pago'=>'1000.00','forma_pagamento'=>'pix','data_pagamento'=>'2026-01-10'],keyTest());
$change=$finance->changeGuardian((int)$student,['codpessoa_nova'=>$new,'motivo'=>'Teste'],keyTest());
verify($change['saldo_transferido']==='600.00' && $change['lancamentos_transferidos']===1,'transfere somente saldo aberto');
verify($db->get('lancamentos',(int)$titles[1]['idlancamento'])['codpessoa_rf_atual']===$old,'título quitado conserva responsável');
verify($db->get('baixas',(int)$payment['idbaixa'])['codpessoa_devedor']===$old,'baixa anterior preserva devedor');
$academic->transfer((int)$enrollment['idmatricula'],['idturma_destino'=>$class2,'motivo'=>'Teste'],keyTest());
verify($db->get('contratos',(int)$enrollment['idcontrato'])['idmatricula']===$enrollment['idmatricula'],'transferência mantém contrato');
$before=$db->get('lancamentos',(int)$titles[0]['idlancamento']);
try { $finance->pay((int)$titles[0]['idlancamento'],['valor_pago'=>'601.00','forma_pagamento'=>'pix'],keyTest()); verify(false,'sobrepagamento'); } catch(\EducacionalERP\Domain\RuleViolation $e) { verify($db->get('lancamentos',(int)$titles[0]['idlancamento'])===$before,'sobrepagamento reverte integralmente'); }
$finance->reverse((int)$paid['idbaixa'],['motivo'=>'Teste estorno'],keyTest());
verify($db->get('lancamentos',(int)$titles[1]['idlancamento'])['codpessoa_rf_atual']===$new,'saldo reaberto assume responsável atual');
$export=(new ExportService($db))->student((int)$student,(int)$period);
verify(count($export['matriculas'][0]['contratos'][0]['parcelas'])===2,'exportação reúne parcelas');
// Foreign keys are enforced by the actual database.
$errors=$wpdb->suppress_errors(true);
try { $db->insert('alunos',['codpessoa'=>999999999999999,'ra'=>'invalid'.$tag]); verify(false,'FK'); } catch(RuntimeException $e) { verify(true,'FK rejeita pessoa inexistente'); }
$wpdb->suppress_errors($errors);

// Authorization and renewal use the same application services as REST and portal.
$adminId=get_current_user_id();
$guardianUser=(int)(new \EducacionalERP\Infrastructure\WordPress\Accounts($db))->summary((int)$new)['wp_user_id'];
if(is_wp_error($guardianUser)) { throw new RuntimeException('Falha ao criar conta de teste.'); }
// Account was provisioned automatically during person creation.
$access=new Access($db);
wp_set_current_user((int)$guardianUser);
verify($access->canStudent((int)$student,'finance'),'responsável atual acessa financeiro');
verify(!$access->canStudent(999999999,'academic'),'responsável não acessa aluno alheio');
wp_set_current_user($adminId);
$nextPeriod=$catalog->create('periodos_letivos',['codigo'=>'N'.$tag,'descricao'=>'Próximo período','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31'],keyTest())['id'];
$nextClass=$catalog->create('turmas',['idplano'=>$plan,'codperiodo'=>$nextPeriod,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'N'.$tag,'nome'=>'Turma futura','capacidade'=>1],keyTest())['id'];
$renewalService=new \EducacionalERP\Application\RenewalService($db,$ops,$academic,$access);
$offer=$renewalService->createOffer(['codperiodo_destino'=>$nextPeriod,'idcurso_origem'=>$course,'idcurso_destino'=>$course,'data_abertura'=>wp_date('Y-m-d'),'data_encerramento'=>wp_date('Y-m-d'),
    'valor_total'=>'1200.00','numero_parcelas'=>3,'primeiro_vencimento'=>'2027-01-31','texto_termo'=>'Termo fictício de teste','versao_termo'=>'1','turmas'=>[$nextClass]],keyTest())['idoferta'];
wp_set_current_user((int)$guardianUser);
verify(count($renewalService->offers((int)$student))===1,'oferta elegível no portal');
$renewData=['idoferta'=>$offer,'idmatricula_origem'=>$enrollment['idmatricula'],'idturma'=>$nextClass,'aceite'=>true,'versao_termo'=>'1'];
$renewKey=keyTest(); $renewed=$renewalService->renew($renewData,$renewKey);
verify($renewed===$renewalService->renew($renewData,$renewKey),'rematrícula idempotente');
verify(count($renewalService->offers((int)$student))===0,'oferta concluída deixa de ser elegível');
verify($db->get('matriculas',(int)$renewed['idmatricula'])['idmatricula_origem']===$enrollment['idmatricula'],'renovação preserva origem');
wp_set_current_user($adminId);
$finance->adjust((int)$titles[0]['idlancamento'],['componente'=>'juros_aplicados','valor_delta'=>'10.00','motivo'=>'Teste'],keyTest());
verify($db->get('lancamentos',(int)$titles[0]['idlancamento'])['saldo_aberto']==='610.00','juros separados do principal');
$last=$finance->pay((int)$titles[0]['idlancamento'],['valor_pago'=>'610.00','forma_pagamento'=>'pix'],keyTest());
verify($db->get('baixas',(int)$last['idbaixa'])['juros_pagos']==='10.00','baixa discrimina juros');
$badKey=keyTest();
try { $finance->adjust((int)$titles[0]['idlancamento'],['componente'=>'juros_aplicados','valor_delta'=>'-10.00','motivo'=>'Ajuste inválido'], $badKey); verify(false,'reduzir juros já pagos'); }
catch(\EducacionalERP\Domain\RuleViolation $e) { verify(true,'não reduz juros já liquidados'); }
verify(!$db->row('SELECT idoperacao FROM '.$db->table('operacoes').' WHERE chave=%s',[$badKey]),'falha não persiste recibo de idempotência');
$before=$db->get('matriculas',(int)$enrollment['idmatricula']);
$errors=$wpdb->suppress_errors(true);
try { $db->update('matriculas',(int)$enrollment['idmatricula'],['idturma_atual'=>$nextClass]); verify(false,'FK composta'); }
catch(RuntimeException $e) { verify($db->get('matriculas',(int)$enrollment['idmatricula'])===$before,'FK composta rejeita turma de outro período'); }
$wpdb->suppress_errors($errors);

if(getenv('EDERP_TEST_EXPORT')) { file_put_contents(getenv('EDERP_TEST_EXPORT'),json_encode((new ExportService($db))->student((int)$student,(int)$period),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
echo "Teste concluído. Registros fictícios identificados por $tag.\n";
