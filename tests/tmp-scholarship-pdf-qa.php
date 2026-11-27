<?php
declare(strict_types=1);
define('WP_INSTALLING',true);
$_SERVER['HTTP_HOST']='127.0.0.1:38547';
$_SERVER['REQUEST_URI']='/';
require getenv('EDERP_TEST_BOOTSTRAP');
if(!defined('EDERP_TEST_DATABASE')||!EDERP_TEST_DATABASE)throw new RuntimeException('Exige WordPress descartável.');
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once WP_PLUGIN_DIR.'/erp-educacional/erp-educacional.php';

use EducacionalERP\Application\ScholarshipRequests;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{MenuPolicy,SchemaMigration,SchoolIdentity};
use EducacionalERP\Presentation\Portal\{Portal,ScholarshipRequestPage};

add_filter('pre_wp_mail',static fn()=>true);
if(!is_blog_installed())wp_install('Colégio Journey de teste','gestor_qa','admin@example.test',false,'','Senha-fixture-4029');
$db=new Database($wpdb);
SchemaMigration::run($db);
wp_set_current_user(get_users(['role'=>'administrator','number'=>1])[0]->ID);
Portal::setup();
ScholarshipRequestPage::setup();
update_option('active_plugins',['erp-educacional/erp-educacional.php']);
$role=MenuPolicy::create(['nome'=>'Comercial PDF QA'])['slug'];
MenuPolicy::save(['role'=>$role,'menus'=>['requerimentos_bolsa'],'conferir_requerimentos_bolsa'=>true]);
foreach(['comercial_pdf'=>$role,'leitor_pdf'=>'subscriber'] as $login=>$profile){
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>'Senha-fixture-4029','role'=>$profile,'user_email'=>$login.'@example.test']);
    if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
}
$logo=wp_upload_bits('logo-fixture.png',null,file_get_contents(WP_PLUGIN_DIR.'/erp-educacional/assets/brand/journey.png'));
if($logo['error'])throw new RuntimeException($logo['error']);
$attachment=wp_insert_attachment(['post_title'=>'Logo de teste','post_mime_type'=>'image/png'],$logo['file']);
if(!$attachment||is_wp_error($attachment))throw new RuntimeException('Logo indisponível.');
update_option(SchoolIdentity::option(),['nome'=>'Colégio Journey','telefone'=>'(41) 3333-4444','email'=>'contato@colegio.example.test',
    'site'=>'https://colegio.example.test','logo_id'=>$attachment],false);
ScholarshipRequests::saveSettings(['ativo'=>true,'emails'=>'administracao@example.test']);
$company=$db->insert('coligadas',['nome'=>'Outra coligada','razao_social'=>'Outra coligada']);
$data=['nivel'=>'Ensino Fundamental II','turma_fundamental_2'=>'8º Ano','turno_fundamental_2'=>'Matutino','aluno'=>'Marina de Oliveira',
    'nascimento'=>'2014-05-20','vive_com'=>'Só Mãe','situacao_pais'=>'Separados','guarda'=>'Mãe','numero_filhos'=>'2','idade_filhos'=>'8 e 12',
    'profissao_mae'=>'Analista de operações','renda_mae'=>'R$ 2.300,00','profissao_pai'=>'Motorista','renda_pai'=>'R$ 2.500,00',
    'solicitante'=>'Lúcia de Oliveira','email'=>'lucia@example.test','telefone'=>'41999999999','percentual'=>'18,50%',
    'justificativa'=>"Solicitamos apoio para matricular a aluna no próximo período letivo.\nA família relata mudança recente no orçamento."];
$service=new ScholarshipRequests($db);
wp_set_current_user(0);
$short=$service->submit($data,bin2hex(random_bytes(16)));
$long=$service->submit(array_replace($data,['aluno'=>'Felipe de Oliveira','justificativa'=>
    str_repeat("A família solicita a bolsa para acompanhar os estudos e manter a matrícula no próximo ano letivo.\n",69)
    ."\nFim da justificativa — teste de paginação."]),bin2hex(random_bytes(16)));
$directory=getenv('EDERP_PDF_QA_DIR');
file_put_contents($directory.'/fixture.json',wp_json_encode(['portal'=>Portal::url('requerimentos_bolsa'),
    'company'=>$company,'short'=>$short['protocolo'],'long'=>$long['protocolo']],JSON_THROW_ON_ERROR));
echo "Pedidos fictícios preparados.\n";
