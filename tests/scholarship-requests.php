<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli')exit(2);
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');
if(!$bootstrap)exit(2);
$_SERVER['HTTP_HOST']='erp-test.local';
$_SERVER['REQUEST_URI']='/';
require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true)throw new RuntimeException('Exige WordPress de teste.');
if(!defined('EDERP_FILE'))define('EDERP_FILE',dirname(__DIR__).'/erp-educacional.php');
if(!defined('EDERP_VERSION'))define('EDERP_VERSION','0.19.58');
require_once dirname(__DIR__).'/autoload.php';
require __DIR__.'/fixtures/scholarships.php';

use EducacionalERP\Application\{AcademicService,CatalogService,Coligadas,ExportService,FinanceService,Operations,RenewalService,ScholarshipRequests,StudentWorkflow};
use EducacionalERP\Domain\RuleViolation;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,AdmissionGuard,MenuPolicy,SchemaMigration};
use EducacionalERP\Presentation\Portal\ScholarshipRequestPage;
use EducacionalERP\Presentation\Rest\{Controller,ScholarshipRequestController};

function scholarshipCheck(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    echo "PASS: $label\n";
}

function scholarshipDenied(callable $action,string $expected): void
{
    try{$action();}catch(RuleViolation $e){scholarshipCheck(str_contains($e->getMessage(),$expected),$e->getMessage());return;}
    throw new RuntimeException('A operação deveria ser recusada.');
}

$admin=get_users(['role'=>'administrator','number'=>1])[0]->ID;
wp_set_current_user($admin);
$basePrefix=$wpdb->prefix;
$options=[];
foreach(['ederp_scholarship_requests','ederp_schema_version','ederp_schema_error','ederp_schema_integrity','ederp_schema_attempt_release',
    'ederp_pontualidade','ederp_menu_policy','ederp_report_policy'] as $option)$options[$option]=get_option($option,null);
$users=[];
$roles=[];
register_shutdown_function(static function()use(&$users,&$roles,$basePrefix,$options,$admin){
    global $wpdb;
    wp_set_current_user($admin);
    $wpdb->prefix=$basePrefix;
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach($users as $user)wp_delete_user($user);
    foreach($roles as $role)remove_role($role);
    foreach($options as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
});
$db=new Database($wpdb);
$wpdb->prefix='scholarship_'.bin2hex(random_bytes(4)).'_';
delete_option('ederp_schema_version');delete_option('ederp_schema_error');
SchemaMigration::run($db);
ScholarshipRequestPage::setup();
update_option('ederp_pontualidade','103.00');
$account=static function(string $name,bool $canDecide)use(&$roles,&$users){
    $profile=MenuPolicy::create(['nome'=>$name.' '.bin2hex(random_bytes(4))])['slug'];
    $roles[]=$profile;
    MenuPolicy::save(['role'=>$profile,'menus'=>['requerimentos_bolsa'],'decidir_requerimentos_bolsa'=>$canDecide]);
    $id=wp_insert_user(['user_login'=>'bolsa_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(),'role'=>$profile]);
    if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
    $users[]=$id;
    return $id;
};
$reviewer=$account('Análise de bolsas',true);
$commercial=$account('Comercial de bolsas',false);
scholarshipCheck(!MenuPolicy::canDecideScholarshipRequests(),'administrador sem perfil designado configura a permissão sem poder decidir');
$fixture=seedScholarships($db);
$student=createScholarshipContract($db,$fixture,'BOLSISTA');
$service=new ScholarshipRequests($db);
ScholarshipRequests::saveSettings(['ativo'=>true,'emails'=>"equipe@example.test\nEQUIPE@example.test\nfinanceiro@example.test"]);
scholarshipCheck(count(ScholarshipRequests::settings()['emails'])===2&&str_contains(ScholarshipRequests::settings()['url'],'colegio=1'),
    'configuração de destinatários por coligada e página pública');
$messages=[];
add_filter('pre_wp_mail',static function($old,$mail)use(&$messages){$messages[]=$mail;return true;},PHP_INT_MAX,2);
$key=static fn()=>bin2hex(random_bytes(16));
$data=['nivel'=>'Ensino Fundamental I','turma_fundamental_1'=>'3º Ano','turma_fundamental_2'=>'9º Ano',
    'aluno'=>'Aluno bolsista','nascimento'=>'2015-01-01','vive_com'=>'Mãe e Pai','situacao_pais'=>'Casados','numero_filhos'=>'2','idade_filhos'=>'10 e 11',
    'profissao_mae'=>'Analista','renda_mae'=>'R$ 1.200','profissao_pai'=>'Comerciante','renda_pai'=>'R$ 2.500','solicitante'=>'Pessoa Solicitante',
    'email'=>'solicitante@example.test','telefone'=>'41999999999','percentual'=>'10,50%','justificativa'=>'Explicação financeira reservada.'];

wp_set_current_user(0);
scholarshipDenied(fn()=>$service->listing('todos',1),'Sem acesso');
add_action('rest_api_init',[(new ScholarshipRequestController($db)),'register']);
$ops=new Operations($db);$access=new Access($db);$academic=new AcademicService($db,$ops);$catalog=new CatalogService($db,$ops);
$controller=new Controller($db,$access,$academic,$catalog,new FinanceService($db,$ops),new ExportService($db),
    new RenewalService($db,$ops,$academic,$access),new StudentWorkflow($db,$ops,$catalog,$academic));
add_action('rest_api_init',[$controller,'register']);
// The local fixture uses the same public route as the browser.
$route='/erp-educacional/v1/publico/requerimentos-bolsa/1';
$get=rest_do_request(new WP_REST_Request('GET',$route));
scholarshipCheck($get->get_status()===200&&count($get->get_data()['etapas'])===4&&!isset($get->get_data()['emails']),
    'formulário público carrega as quatro etapas sem expor destinatários');
$token=$get->get_data()['token'];
$send=static function(array $body,string $nonce,string $submission)use($route){
    $request=new WP_REST_Request('POST',$route);
    $request->set_header('Content-Type','application/json');
    $request->set_header('Idempotency-Key',$submission);
    $request->set_body(wp_json_encode(['dados'=>$body,'token'=>$nonce,'website'=>'']));
    return rest_do_request($request);
};
$otherToken=(new AdmissionGuard($db))->token(1);
usleep(3200000);
scholarshipCheck($send($data,$otherToken,$key())->get_status()===422,'desafio da matrícula não autoriza requerimento de bolsa');
$invalid=array_replace($data,['nivel'=>'Ensino Fundamental II']);
scholarshipCheck($send($invalid,$token,$key())->get_status()===422,'turno condicional é exigido pelo servidor');
$submission=$key();
$received=$send($data,$token,$submission);
scholarshipCheck($received->get_status()===202&&array_keys($received->get_data())===['protocolo']
    &&$send($data,$token,$submission)->get_data()===$received->get_data(),'envio público devolve protocolo e evita duplicação');
scholarshipCheck(count($messages)===3&&!str_contains(wp_json_encode($messages),'Explicação financeira'),
    'solicitante e dois destinatários recebem avisos sem a justificativa privada');
scholarshipCheck($messages[0]['to']==='solicitante@example.test'&&str_contains($messages[0]['subject'],'Confirmação de recebimento')
    &&str_contains($messages[0]['message'],'10,50%')&&str_contains($messages[0]['message'],$received->get_data()['protocolo']),
    'confirmação ao solicitante contém o percentual pedido e o protocolo');

wp_set_current_user($commercial);
$item=$service->listing('pendente',1)['items'][0];
$id=(int)$item['idpedido'];
$review=$service->detail($id);
$decisionRoute='/erp-educacional/v1/requerimentos-bolsa/'.$id.'/decidir';
$decide=static function(array $body)use($decisionRoute,$key){
    $request=new WP_REST_Request('POST',$decisionRoute);
    $request->set_header('Content-Type','application/json');
    $request->set_header('Idempotency-Key',$key());
    $request->set_body(wp_json_encode($body));
    return rest_do_request($request);
};
scholarshipCheck(!$review['pode_decidir']&&$decide(['versao'=>$review['versao'],'situacao'=>'aprovado','percentual'=>'15'])->get_status()===403
    &&$decide(['versao'=>$review['versao'],'situacao'=>'rejeitado'])->get_status()===403&&count($messages)===3,
    'comercial consulta o pedido sem poder aprovar ou rejeitar pela API');
scholarshipDenied(fn()=>$service->decide($id,['versao'=>$review['versao'],'situacao'=>'rejeitado'],$key()),'Seu perfil não permite');
wp_set_current_user($reviewer);
scholarshipCheck($service->detail($id)['pode_decidir'],'perfil designado recebe a opção de decidir');
scholarshipCheck((int)$item['idaluno']===(int)$student['idaluno']&&$review['dados']['percentual']==='10.50'
    &&!isset($review['dados']['turma_fundamental_2'])&&$review['notificacoes']['enviado']===3,'nome e nascimento vinculam e a revisão exibe respostas aplicáveis');
scholarshipDenied(fn()=>ScholarshipRequests::settings(),'administradores');
$decision=['versao'=>$review['versao'],'situacao'=>'aprovado','percentual'=>'15%','observacao'=>'Bolsa concedida.'];
$approved=$service->decide($id,$decision,$operation=$key());
scholarshipCheck($approved['bolsa']['contratos_atualizados']===1&&$service->detail($id)['percentual_aprovado']==='15.00'
    &&$service->decide($id,$decision,$operation)===$approved&&count($messages)===4&&str_contains($messages[3]['message'],'15,00%'),
    'aprovação ajusta o percentual, cadastra bolsa, atualiza contrato e envia e-mail uma vez');

wp_set_current_user(0);
$manual=$send(array_replace($data,['aluno'=>'Nome sem correspondência']),$token,$key());
scholarshipCheck($manual->get_status()===202,'pedido sem correspondência é recebido');
wp_set_current_user($reviewer);
$item=$service->listing('pendente',1)['items'][0];
$id=(int)$item['idpedido'];
scholarshipDenied(fn()=>$service->decide($id,['versao'=>1,'situacao'=>'aprovado','percentual'=>'15'],$key()),'Vincule o aluno');
wp_set_current_user($commercial);
scholarshipCheck($service->candidates($id,'BOLSISTA')!==[],'pesquisa manual encontra o aluno pelo RA');
$service->link($id,['idaluno'=>$student['idaluno'],'versao'=>1],$key());
$linked=$service->detail($id);
scholarshipCheck((int)$linked['idaluno']===(int)$student['idaluno']&&!$linked['pode_decidir']&&$linked['situacao']==='pendente',
    'comercial vincula o aluno e o requerimento continua pendente de decisão');
wp_set_current_user($reviewer);
$service->decide($id,['versao'=>2,'situacao'=>'rejeitado','observacao'=>'Motivo informado pela escola.'],$key());
scholarshipCheck($service->detail($id)['situacao']==='rejeitado'&&$service->detail($id)['idbolsa_aluno']===null
    &&str_contains(end($messages)['subject'],'rejeitado'),'vínculo manual, rejeição e e-mail do resultado');
$other=$db->insert('coligadas',['nome'=>'Outra escola','razao_social'=>'Outra escola']);
Coligadas::within($other,fn()=>scholarshipDenied(fn()=>$service->detail($id),'indisponível nesta coligada'));

wp_set_current_user($admin);
foreach([$reviewer,$commercial] as $user){
    wp_update_user(['ID'=>$user,'user_email'=>'bolsa-'.$user.'@example.test']);
}
scholarshipDenied(fn()=>ScholarshipRequests::saveSettings([
    'ativo'=>true,'emails'=>'equipe@example.test','aprovador_id'=>$commercial,
]),'permissão para decidir');
$settings=ScholarshipRequests::saveSettings([
    'ativo'=>true,'emails'=>'equipe@example.test','aprovador_id'=>$reviewer,
]);
scholarshipCheck(array_column($settings['aprovadores'],'id')===[$reviewer],'configuração oferece somente o usuário com perfil de análise');

$approvalApi=static function(string $action,string $token,array $body=[],int $company=1,string $method='POST')use($route){
    $request=new WP_REST_Request($method,preg_replace('~/1$~','/'.$company,$route).'/aprovacao/'.$action);
    $request->set_header('Content-Type','application/json');
    $request->set_body(wp_json_encode(['token'=>$token,'decisao'=>$body]));
    return rest_do_request($request);
};
$approvalToken=static function(array $mail){
    if(!preg_match('~(https?://\S+\#([1-9][0-9]*\.[a-f0-9]{64}))~',$mail['message'],$matches)){
        throw new RuntimeException('Link de aprovação não encontrado.');
    }
    $url=$matches[1];
    parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
    scholarshipCheck(($query['aprovar_bolsa']??null)==='1'&&!str_contains($query['colegio']??'',$matches[2]),
        'o token é enviado no fragmento do link');
    return $matches[2];
};

$studentByEmail=createScholarshipContract($db,$fixture,'VIAEMAIL');
$identity=$db->get('pessoas',(int)$studentByEmail['codpessoa']);
$first=count($messages);
wp_set_current_user(0);
$sent=$service->submit(array_replace($data,['aluno'=>$identity['nome'],'nascimento'=>$identity['data_nascimento']]),$key());
$managerMail=$messages[$first+2];
scholarshipCheck(count($messages)===$first+3&&$managerMail['to']==='bolsa-'.$reviewer.'@example.test'
    &&!str_contains($managerMail['message'],'Explicação financeira'),'link individual é enviado apenas ao gerente e omite as respostas');
$linkToken=$approvalToken($managerMail);
$wrong=substr($linkToken,0,-1).(str_ends_with($linkToken,'a')?'b':'a');
$summary=$approvalApi('resumo',$linkToken);
$summaryData=$summary->get_data();
scholarshipCheck($summary->get_status()===200&&$summaryData['protocolo']===$sent['protocolo']
    &&$summaryData['aluno_vinculado']['ra']==='VIAEMAIL'
    &&!array_intersect(['email','telefone','nascimento'],array_keys($summaryData))
    &&str_contains((array_change_key_case($summary->get_headers())['cache-control']??''),'no-store'),'resumo público usa token válido e omite os contatos');
scholarshipCheck($approvalApi('resumo',$wrong)->get_status()===422
    &&$approvalApi('resumo',$linkToken,[],$other)->get_status()===422
    &&$approvalApi('decisao',$linkToken,[],1,'GET')->get_status()===404,
    'assinatura alterada, coligada incorreta e decisão por GET são recusadas');

$entryTable=$db->table('requerimento_bolsa_emails');
$linkId=(int)explode('.',$linkToken)[0];
$linkRow=$db->get('requerimento_bolsa_emails',$linkId);
$claims=[$linkId,$linkRow['codcoligada'],$linkRow['idpedido'],$linkRow['aprovador_id'],
    $linkRow['destinatario'],$linkRow['link_referencia']];
$past=gmdate('Y-m-d H:i:s',time()-60);
$expired=$linkId.'.'.hash_hmac('sha256','ederp:scholarship-approval:v1|'.implode('|',[...$claims,$past]),wp_salt('auth'));
$db->query("UPDATE $entryTable SET link_expira_em=%s WHERE idemail=%d",[$past,$linkId]);
scholarshipCheck($approvalApi('resumo',$expired)->get_status()===422,'link expirado é recusado mesmo com assinatura válida');
$db->query("UPDATE $entryTable SET link_expira_em=%s WHERE idemail=%d",[$linkRow['link_expira_em'],$linkId]);

wp_set_current_user($admin);
MenuPolicy::save(['role'=>$roles[0],'menus'=>['requerimentos_bolsa'],'decidir_requerimentos_bolsa'=>false]);
wp_set_current_user(0);
scholarshipCheck($approvalApi('resumo',$linkToken)->get_status()===422,'link perde acesso se a permissão do gerente é revogada');
wp_set_current_user($admin);
MenuPolicy::save(['role'=>$roles[0],'menus'=>['requerimentos_bolsa'],'decidir_requerimentos_bolsa'=>true]);
wp_set_current_user(0);
$body=['situacao'=>'aprovado','percentual'=>'18,25','observacao'=>'Aprovado pelo financeiro.','versao'=>$summaryData['versao']];
$viaEmail=$approvalApi('decisao',$linkToken,$body);
$idEmailRequest=(int)$linkRow['idpedido'];
$after=$db->get('requerimentos_bolsa',$idEmailRequest);
$audit=$db->row('SELECT * FROM '.$db->table('auditoria')." WHERE entidade='requerimentos_bolsa' AND entidade_id=%d AND acao='aprovado'",[$idEmailRequest]);
$meta=json_decode($audit['depois_json'],true,32,JSON_THROW_ON_ERROR);
scholarshipCheck($viaEmail->get_status()===200&&$viaEmail->get_data()['percentual']==='18.25'
    &&$after['idbolsa_aluno']&&(int)$after['decidido_por']===$reviewer&&get_current_user_id()===0
    &&(int)$audit['ator_wp_user_id']===$reviewer&&$meta['link_decisao']===$linkRow['link_referencia']
    &&$meta['origem']==='link_email'&&!str_contains(wp_json_encode($audit),$linkToken)
    &&str_contains(end($messages)['message'],'18,25%'),'aprovação anônima cria bolsa e audita gerente e referência sem divulgar token');
$sentCount=count($messages);
scholarshipCheck($approvalApi('resumo',$linkToken)->get_status()===422
    &&$approvalApi('decisao',$linkToken,$body)->get_status()===422&&count($messages)===$sentCount,
    'link não abre o resumo nem reaplica uma decisão registrada');

echo "SCHOLARSHIP_REQUESTS_OK\n";
