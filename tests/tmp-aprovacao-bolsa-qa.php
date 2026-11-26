<?php
declare(strict_types=1);
define('WP_INSTALLING',true);
$_SERVER['HTTP_HOST']='127.0.0.1:38547';
$_SERVER['REQUEST_URI']='/';
require getenv('WP_QA_DIR').'/wp-load.php';
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true)throw new RuntimeException('Exige base descartável.');
require_once dirname(__DIR__).'/erp-educacional.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once __DIR__.'/fixtures/financial-dashboard.php';

use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{MenuPolicy,SchemaMigration};
use EducacionalERP\Presentation\Portal\Portal;

add_filter('pre_wp_mail',static fn()=>true);
if(!is_blog_installed())wp_install('Escola QA','gestor_teste','local@example.test',false,'',wp_generate_password(24));
$db=new Database($wpdb);
SchemaMigration::run($db);
$admin=get_users(['role'=>'administrator','number'=>1])[0];
wp_set_current_user($admin->ID);
Portal::setup();
update_option('active_plugins',['erp-educacional/erp-educacional.php']);
update_option('ederp_pontualidade','103.00');
\EducacionalERP\Presentation\Portal\ScholarshipRequestPage::setup();
\EducacionalERP\Application\ScholarshipRequests::saveSettings(['ativo'=>true,'emails'=>"administracao@example.test\nfinanceiro@example.test"]);
$fixture=seedScholarships($db);
createScholarshipContract($db,$fixture,'BOLSISTA');
$role=MenuPolicy::create(['nome'=>'Gerente financeiro QA'])['slug'];
MenuPolicy::save(['role'=>$role,'menus'=>['requerimentos_bolsa'],'decidir_requerimentos_bolsa'=>true]);
$reviewer=wp_insert_user(['user_login'=>'gestor_bolsas_teste','user_pass'=>wp_generate_password(24),'role'=>$role,
    'user_email'=>'gerente@example.test']);
if(is_wp_error($reviewer))throw new RuntimeException($reviewer->get_error_message());
\EducacionalERP\Application\ScholarshipRequests::saveSettings(['ativo'=>true,'emails'=>'administracao@example.test',
    'aprovador_id'=>$reviewer]);
$approvalUrl='';
add_filter('pre_wp_mail',static function($previous,$mail)use(&$approvalUrl){
    if($mail['to']==='gerente@example.test'&&preg_match('~https?://\S+\#[1-9][0-9]*\.[a-f0-9]{64}~',$mail['message'],$matches)){
        $approvalUrl=$matches[0];
    }
    return true;
},PHP_INT_MAX,2);
wp_set_current_user(0);
(new \EducacionalERP\Application\ScholarshipRequests($db))->submit([
    'nivel'=>'Ensino Fundamental I','turma_fundamental_1'=>'3º Ano','aluno'=>'Aluno Bolsista','nascimento'=>'2015-01-01',
    'vive_com'=>'Mãe e Pai','situacao_pais'=>'Casados','numero_filhos'=>'2','idade_filhos'=>'10 e 11',
    'profissao_mae'=>'Analista','renda_mae'=>'R$ 1.200','profissao_pai'=>'Comerciante','renda_pai'=>'R$ 2.500',
    'solicitante'=>'Pessoa Solicitante','email'=>'familia@example.test','telefone'=>'41999999999',
    'percentual'=>'11%','justificativa'=>'Justificativa do teste no navegador.',
],bin2hex(random_bytes(20)));
if(!$approvalUrl)throw new RuntimeException('O e-mail de aprovação não foi capturado.');
file_put_contents(dirname(getenv('WP_QA_DIR')).'/approval-fixture.json',wp_json_encode(['url'=>$approvalUrl]));
echo "Requerimento de bolsa para teste no navegador preparado.\n";
