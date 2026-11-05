<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Application\{Operations,CatalogService,AcademicService,StudentWorkflow,FinanceService,FinancialDeletion,ExportService,IntegrationService,RenewalService,PaymentReceipt,ContractDiscounts,EnrollmentLifecycle,Coligadas,DataExport};
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts,MenuPolicy};
use EducacionalERP\Presentation\Rest\Controller;
use EducacionalERP\Domain\{RuleViolation,Input};

Access::install();$db=new Database($wpdb);$ops=new Operations($db);$cat=new CatalogService($db,$ops);$academic=new AcademicService($db,$ops);$workflow=new StudentWorkflow($db,$ops,$cat,$academic);$finance=new FinanceService($db,$ops);$delete=new FinancialDeletion($db,$ops);$access=new Access($db);$renew=new RenewalService($db,$ops,$academic,$access);$export=new ExportService($db);$integration=new IntegrationService($db,$ops,$cat);$checks=0;
function dk():string{return bin2hex(random_bytes(16));}
function dc(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
function dd(callable $work,string $label):void{try{$work();}catch(RuleViolation $e){dc(true,$label);return;}throw new RuntimeException($label);}
function da(string $type,array $data):int{global $cat;return (int)$cat->create($type,$data,dk())['id'];}
function deletionFixture(int $guardian,string $suffix='',int $installments=4):array{
    global $db,$workflow,$cat;
    $period=da('periodos_letivos',['codigo'=>'2026'.$suffix,'descricao'=>'Período de teste','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']);
    $course=da('cursos',['codigo'=>'EF'.$suffix,'nome'=>'Fundamental']);$shift=da('turnos',['codigo'=>'M'.$suffix,'nome'=>'Manhã']);
    $plan=da('planos_pagamento',['codperiodo'=>$period,'codigo'=>'AN'.$suffix,'nome'=>'Anuidade','valor_anuidade'=>'4000.00']);
    $class=da('turmas',['codigo'=>'6ANO'.$suffix,'nome'=>'6º ANO','idcurso'=>$course,'idturno'=>$shift,'codperiodo'=>$period,'idplano'=>$plan,'capacidade'=>30]);
    $student=$workflow->createStudent(['email'=>'aluno'.$suffix.'@example.test','nome'=>'Aluno Exclusão '.$suffix,'data_nascimento'=>'2015-01-01'],dk());
    $cat->link((int)$student['idaluno'],['codpessoa_responsavel'=>$guardian,'parentesco'=>'mae','responsavel_financeiro'=>1,'responsavel_academico'=>1],dk());
    $enrollment=$workflow->batch(['codperiodo'=>$period,'idturma'=>$class,'alunos'=>[$student['idaluno']],'quantidade_parcelas'=>$installments,'primeiro_vencimento'=>Input::today()],dk())['items'][0];
    $titles=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY p.numero',[(int)$enrollment['idcontrato']]);
    return [$student,$enrollment,$titles,$period];
}
$guardian=da('pessoas',['email'=>'responsavel@example.test','nome'=>'Maria Exclusão','data_nascimento'=>'1980-01-01']);
[$student,$enrollment,$titles,$period]=deletionFixture($guardian);update_option('ederp_current_period',$period);
$sid=(int)$student['idaluno'];$cid=(int)$enrollment['idcontrato'];$paid=(int)$titles[0]['idlancamento'];$partial=(int)$titles[1]['idlancamento'];$unpaid=(int)$titles[2]['idlancamento'];$canceled=(int)$titles[3]['idlancamento'];
$payment=$finance->pay($paid,['valor_pago'=>'1000.00','forma_pagamento'=>'pix'],dk());$finance->pay($partial,['valor_pago'=>'100.00','forma_pagamento'=>'boleto'],dk());$db->update('lancamentos',$unpaid,['vencimento'=>(new DateTimeImmutable(Input::today()))->modify('-1 day')->format('Y-m-d')]);$db->update('lancamentos',$canceled,['status'=>'cancelado','saldo_aberto'=>'0.00','valor_cancelado'=>'1000.00']);
$employee=wp_insert_user(['user_login'=>'financeiro.delecao','role'=>'erp_financeiro']);$secretary=wp_insert_user(['user_login'=>'secretaria.delecao','role'=>'erp_secretaria']);$custom=wp_insert_user(['user_login'=>'custom.delecao','role'=>'erp_custom_test']);
$GLOBALS['test_roles']['erp_custom_test']['capabilities']=['read'=>true,'manage_options'=>true,'erp_excluir_lancamentos'=>true,'erp_consultar_financeiro'=>true];
$family=(int)(new Accounts($db))->summary($guardian)['wp_user_id'];
$request=fn(int $id)=>['versao'=>$db->get('lancamentos',$id)['versao'],'motivo'=>'Correção de cadastro financeiro'];
foreach([$secretary,$custom,$family,0] as $user){wp_set_current_user($user);dc(!Access::canDeleteFinancial(),'perfil '.$user.' sem permissão de exclusão');dd(fn()=>$delete->remove($unpaid,$request($unpaid),dk()),'serviço bloqueia perfil '.$user);}
wp_set_current_user($employee);dc(Access::canDeleteFinancial(),'Financeiro recebe permissão para excluir sem pagamento');
dd(fn()=>$delete->remove($paid,$request($paid),dk()),'Financeiro não exclui pago');dd(fn()=>$delete->remove($partial,$request($partial),dk()),'Financeiro não exclui baixa parcial');
dd(fn()=>$delete->remove($paid,$request($paid)+['status'=>'em_aberto'],dk()),'situação enviada pelo cliente não autoriza exclusão');
$policy=get_option('ederp_menu_policy',[]);update_option('ederp_menu_policy',['erp_financeiro'=>[]]);dd(fn()=>$delete->remove($unpaid,$request($unpaid),dk()),'revogação do menu bloqueia exclusão');update_option('ederp_menu_policy',$policy);
$GLOBALS['test_roles']['erp_financeiro']['capabilities']['erp_excluir_lancamentos']=false;dd(fn()=>$delete->remove($unpaid,$request($unpaid),dk()),'revogação da capacidade bloqueia exclusão');$GLOBALS['test_roles']['erp_financeiro']['capabilities']['erp_excluir_lancamentos']=true;
dd(fn()=>$delete->remove($unpaid,['versao'=>999,'motivo'=>'Teste de concorrência'],dk()),'versão antiga impede excluir lançamento alterado');dd(fn()=>$delete->remove($unpaid,['versao'=>$titles[2]['versao'],'motivo'=>' '],dk()),'motivo obrigatório');
$debt=new ReflectionMethod($renew,'hasOverduePayments');dc($debt->invoke($renew,$sid),'débito vencido ativo impede rematrícula');
$before=$db->get('lancamentos',$unpaid);$key=dk();$data=$request($unpaid);$result=$delete->remove($unpaid,$data,$key);$after=$db->get('lancamentos',$unpaid);
dc($result['excluido']&&$after['excluido_em']!==null&&(int)$after['excluido_por_wp_user_id']===$employee,'Financeiro exclui lançamento em aberto registrando ator e data');
dc($before['saldo_aberto']===$after['saldo_aberto']&&$before['status']===$after['status']&&$before['valor_original']===$after['valor_original'],'exclusão preserva composição monetária original');dc($delete->remove($unpaid,$data,$key)===$result,'reenvio idempotente da exclusão');dd(fn()=>$delete->remove($unpaid,$request($unpaid),dk()),'nova operação não exclui novamente o mesmo título');
dc(!$debt->invoke($renew,$sid),'título vencido excluído não bloqueia rematrícula');
$delete->remove($canceled,$request($canceled),dk());dc(!empty($db->get('lancamentos',$canceled)['excluido_em']),'Financeiro exclui cancelado sem pagamentos');
wp_set_current_user(1);$paidBefore=$db->get('lancamentos',$paid);$baixasBefore=$db->rows('SELECT * FROM '.$db->table('baixas'));$mBefore=$db->get('matriculas',(int)$enrollment['idmatricula']);$cBefore=$db->get('contratos',$cid);$parcelsBefore=$db->rows('SELECT * FROM '.$db->table('parcelas').' WHERE idcontrato=%d',[$cid]);
$delete->remove($paid,$request($paid),dk());$paidAfter=$db->get('lancamentos',$paid);
dc($paidAfter['status']==='quitado'&&$paidAfter['valor_baixa']===$paidBefore['valor_baixa']&&!empty($paidAfter['excluido_em']),'administrador exclui pago sem estorno fictício');
dc($db->rows('SELECT * FROM '.$db->table('baixas'))===$baixasBefore,'baixas são preservadas com FKs íntegras');dc($db->get('matriculas',(int)$enrollment['idmatricula'])===$mBefore&&$db->get('contratos',$cid)===$cBefore&&$db->rows('SELECT * FROM '.$db->table('parcelas').' WHERE idcontrato=%d',[$cid])===$parcelsBefore,'exclusão não altera matrícula, contrato nem cronograma');
$audit=$db->row('SELECT * FROM '.$db->table('auditoria')." WHERE entidade='lancamentos' AND entidade_id=%d AND acao='excluir'",[$paid]);dc((int)$audit['ator_wp_user_id']===1&&json_decode($audit['depois_json'],true)['motivo_exclusao']==='Correção de cadastro financeiro'&&json_decode($audit['antes_json'],true)['excluido_em']===null,'auditoria registra antes, depois, motivo, horário e administrador');
dd(fn()=>$finance->pay($paid,['valor_pago'=>'10.00','forma_pagamento'=>'pix'],dk()),'não recebe baixa em lançamento excluído');dd(fn()=>$finance->reverse((int)$payment['idbaixa'],['motivo'=>'Teste'],dk()),'não estorna baixa de lançamento excluído');dd(fn()=>$finance->edit($unpaid,['versao'=>$after['versao'],'valor_original'=>'1200.00','motivo'=>'Teste'],dk()),'não edita lançamento excluído');dd(fn()=>(new PaymentReceipt($db,$access))->download($paid),'recibo antigo de lançamento excluído é bloqueado');
$batch=$academic->generateInstallments($cid,dk());dc($batch['ja_geradas']&&count($db->rows('SELECT * FROM '.$db->table('lancamentos')))===4,'geração posterior não recria lançamentos excluídos');
$db->atomic(fn()=>EnrollmentLifecycle::syncFirstPayment($db,$cid,dk()));dc($db->get('matriculas',(int)$enrollment['idmatricula'])===$mBefore,'exclusão da primeira parcela não rebaixa situação acadêmica');
$discountBefore=$db->get('lancamentos',$unpaid);(new ContractDiscounts($db,$ops))->add($cid,['nome'=>'Nova bolsa','valor'=>'25.00','parcela_inicio'=>1,'parcela_fim'=>4],dk());dc($db->get('lancamentos',$unpaid)===$discountBefore,'bolsas posteriores não alteram títulos excluídos');
$api=$integration->collection('financeiro',[]);dc(count($api['items'])===1&&(int)$api['items'][0]['lancamento']['idlancamento']===$partial,'API financeira omite títulos excluídos');dd(fn()=>$integration->detail('financeiro',$paid),'detalhe direto da API não expõe título excluído');
$unified=$export->student($sid,$period);$exported=$unified['matriculas'][0]['contratos'][0]['parcelas'];dc($exported[0]['lancamento']===null&&$exported[2]['lancamento']===null,'exportação operacional preserva cronograma sem expor títulos excluídos');
$backup=(new DataExport($db))->page(['tabela'=>'lancamentos','coligadas'=>[1]]);dc(count($backup['registros'])===4&&$backup['registros'][0]['excluido_em']!==null,'backup administrativo conserva títulos e metadados de exclusão');
function register_rest_route($ns,$path,$args){$GLOBALS['deletion_routes'][$path][$args['methods']]=$args;}
update_option('ederp_schema_version',Installer::VERSION);update_option('ederp_schema_error',false);$controller=new Controller($db,$access,$academic,$cat,$finance,$export,$renew,$workflow);$controller->register();
$path='/lancamentos/(?P<id>[1-9][0-9]{0,17})/excluir';$route=$GLOBALS['deletion_routes'][$path]['POST'];dc(isset($route),'REST registra exclusão autenticada via POST');
foreach([$family,$secretary,$custom,0] as $user){wp_set_current_user($user);dc($route['permission_callback'](null) instanceof WP_Error,'REST bloqueia exclusão pelo perfil '.$user);}
foreach([1,$employee] as $user){wp_set_current_user($user);dc($route['permission_callback'](null)===true,'REST autoriza administrador e Financeiro com permissão');}
$view=new ReflectionMethod($controller,'financeView');$options=new ReflectionMethod($controller,'financialOptions');wp_set_current_user(1);dc(count($view->invoke($controller,$sid,$period))===1,'gestão financeira omite títulos excluídos');dc(count($options->invoke($controller,$sid)['baixas'])===1,'operações financeiras omitem baixas de títulos excluídos');wp_set_current_user($family);dc(count($view->invoke($controller,$sid,$period))===1,'portal do responsável omite títulos excluídos');
wp_set_current_user(1);$company=$db->insert('coligadas',['nome'=>'OUTRO COLÉGIO','razao_social'=>'OUTRO COLÉGIO LTDA','cnpj'=>'11444777000161']);[$otherStudent,$otherEnrollment,$otherTitles]=Coligadas::within($company,fn()=>deletionFixture($guardian,'-B'));$otherId=(int)$otherTitles[0]['idlancamento'];dd(fn()=>$delete->remove($otherId,$request($otherId),dk()),'administrador respeita coligada de trabalho');
Coligadas::within($company,function()use($otherId,$otherTitles,$finance,$delete,$request,$employee){$old=$request($otherId);$finance->pay($otherId,['valor_pago'=>'1000.00','forma_pagamento'=>'pix'],dk());wp_set_current_user($employee);dd(fn()=>$delete->remove($otherId,$old,dk()),'pagamento posterior à consulta impede exclusão com versão antiga');dd(fn()=>$delete->remove($otherId,$request($otherId),dk()),'Financeiro continua bloqueado após atualizar versão do título pago');wp_set_current_user(1);$otherUnpaid=(int)$otherTitles[1]['idlancamento'];$delete->remove($otherUnpaid,$request($otherUnpaid),dk());});dc(!empty($db->get('lancamentos',(int)$otherTitles[1]['idlancamento'])['excluido_em']),'administrador também exclui sem pagamentos na coligada correta');
$delete->remove($partial,$request($partial),dk());dc(!empty($db->get('lancamentos',$partial)['excluido_em']),'administrador também exclui baixa parcial');dc($wpdb->pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'todas as chaves estrangeiras continuam íntegras');
echo "PASS: $checks verificações de exclusão financeira\n";
