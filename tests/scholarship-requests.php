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
if(!defined('EDERP_VERSION'))define('EDERP_VERSION','0.19.57');
require_once dirname(__DIR__).'/autoload.php';
require __DIR__.'/fixtures/scholarships.php';

use EducacionalERP\Application\{Coligadas,ScholarshipRequests};
use EducacionalERP\Domain\RuleViolation;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{AdmissionGuard,MenuPolicy,SchemaMigration};
use EducacionalERP\Presentation\Portal\ScholarshipRequestPage;
use EducacionalERP\Presentation\Rest\ScholarshipRequestController;

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
$db=new Database($wpdb);
$wpdb->prefix='scholarship_'.bin2hex(random_bytes(4)).'_';
delete_option('ederp_schema_version');delete_option('ederp_schema_error');
SchemaMigration::run($db);
ScholarshipRequestPage::setup();
update_option('ederp_pontualidade','103.00');
$profile=MenuPolicy::create(['nome'=>'Análise de bolsas QA'])['slug'];
MenuPolicy::save(['role'=>$profile,'menus'=>['requerimentos_bolsa']]);
$reviewer=wp_insert_user(['user_login'=>'bolsa_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(),'role'=>$profile]);
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

wp_set_current_user($reviewer);
$item=$service->listing('pendente',1)['items'][0];
$id=(int)$item['idpedido'];
$review=$service->detail($id);
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
scholarshipCheck($service->candidates($id,'BOLSISTA')!==[],'pesquisa manual encontra o aluno pelo RA');
$service->link($id,['idaluno'=>$student['idaluno'],'versao'=>1],$key());
$service->decide($id,['versao'=>2,'situacao'=>'rejeitado','observacao'=>'Motivo informado pela escola.'],$key());
scholarshipCheck($service->detail($id)['situacao']==='rejeitado'&&$service->detail($id)['idbolsa_aluno']===null
    &&str_contains(end($messages)['subject'],'rejeitado'),'vínculo manual, rejeição e e-mail do resultado');
$other=$db->insert('coligadas',['nome'=>'Outra escola','razao_social'=>'Outra escola']);
Coligadas::within($other,fn()=>scholarshipDenied(fn()=>$service->detail($id),'indisponível nesta coligada'));
echo "SCHOLARSHIP_REQUESTS_OK\n";
