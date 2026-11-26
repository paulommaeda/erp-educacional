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
$db->update('lancamentos',$fixture['titles']['a'][5],['vencimento'=>'2027-02-01']);
$contractId=(int)$fixture['a']['idcontrato'];
$contracts=new \EducacionalERP\Application\Contracts($db,new \EducacionalERP\Application\Operations($db));
$contract=$db->get('contratos',$contractId);
$contracts->sign($contractId,['versao'=>(string)$contract['versao'],'confirmado'=>true],bin2hex(random_bytes(16)));
$summary=$contracts->financialSummary($contractId);
if(count($summary['parcelas'])!==6||$summary['valor_original_total']!=='8118.00'||$summary['valor_liquido_total']!=='5841.50'
    ||$summary['desconto_incondicional_total']!=='2276.50'||$summary['pontualidade_min']!=='0.00'||$summary['pontualidade_max']!=='103.00'
    ||$summary['parcelas'][1]['descontos_detalhados'][0]['nome']!=='BOLSA FIXA'||isset($summary['termo_snapshot'])){
    throw new RuntimeException('Totais ou detalhes divergentes: '.wp_json_encode($summary));
}
$normal=$contracts->listing(['codperiodo'=>'todos'])['items'];
if(isset($normal[0]['pontualidade_min']))throw new RuntimeException('Listagem comum recebeu a visão de bolsas.');
$pending=createScholarshipContract($db,$fixture,'QUIT-PEND',[scholarshipSelection($fixture['half'],1,1)],true);
$plan=$contracts->financialSummary((int)$pending['idcontrato']);
if($plan['parcelas']||!$plan['bolsas_previstas']||$plan['desconto_incondicional_total']==='0.00')throw new RuntimeException('Contrato sem parcelas perdeu bolsa planejada.');
$role=MenuPolicy::create(['nome'=>'Relatório Contratos QA'])['slug'];
$discountRole=MenuPolicy::create(['nome'=>'Descontos QA'])['slug'];
MenuPolicy::save(['role'=>$role,'menus'=>[],'reports'=>['contratos']]);
MenuPolicy::save(['role'=>$discountRole,'menus'=>['bolsas'],'reports'=>[]]);
$account=static function(string $role,string $login):array{
    $password=wp_generate_password(24,false,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'role'=>$role]);
    if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
    return compact('login','password');
};
file_put_contents(dirname(getenv('WP_QA_DIR')).'/fixture.json',wp_json_encode([
    'portal'=>add_query_arg('relatorio','contratos',Portal::url('relatorios')),
    'discountsUrl'=>Portal::url('bolsas'),
    'period'=>$fixture['period'],
    'classB'=>$fixture['classB'],
    'contract'=>$contract['numero'],
    'authorized'=>$account($role,'relatorio_qa'),
    'discounts'=>$account($discountRole,'bolsas_qa'),
    'denied'=>$account('erp_financeiro','financeiro_qa'),
],JSON_UNESCAPED_UNICODE));
echo "Totais, bolsa planejada e perfis verificados em MariaDB.\n";
