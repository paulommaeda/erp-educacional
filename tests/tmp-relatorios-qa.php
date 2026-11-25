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
$fixture=seedFinancialDashboard($db);
$role=MenuPolicy::create(['nome'=>'Relatório Parcelas QA'])['slug'];
MenuPolicy::save(['role'=>$role,'menus'=>[],'reports'=>['parcelas']]);
$account=static function(string $role,string $login):array{
    $password=wp_generate_password(24,false,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'role'=>$role]);
    if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
    return compact('login','password');
};
file_put_contents(dirname(getenv('WP_QA_DIR')).'/fixture.json',wp_json_encode([
    'portal'=>add_query_arg('relatorio','parcelas',Portal::url('relatorios')),
    'student'=>$fixture['a']['idaluno'],
    'authorized'=>$account($role,'relatorio_qa'),
    'denied'=>$account('erp_financeiro','financeiro_qa'),
],JSON_UNESCAPED_UNICODE));
echo "Parcelas fictícias e perfis criados.\n";
