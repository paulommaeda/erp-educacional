<?php
/** Native migration from schema 48, financial rules and the delegated student-scholarship routes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');if(!$bootstrap||!is_file($bootstrap))exit(2);
$_SERVER['HTTP_HOST']='erp-test.local';$_SERVER['REQUEST_URI']='/';require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true||defined('EDERP_SQLITE_TEST'))throw new RuntimeException('Exige WordPress/MySQL de testes.');
require_once dirname(__DIR__).'/autoload.php';require __DIR__.'/wordpress-migration-fixtures.php';require __DIR__.'/fixtures/scholarships.php';require __DIR__.'/student-scholarships.php';require_once ABSPATH.'wp-admin/includes/user.php';
use EducacionalERP\Application\{AcademicService,CatalogService,Coligadas,ExportService,FinanceService,Operations,RenewalService,StudentWorkflow};
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};
use EducacionalERP\Presentation\Rest\Controller;
$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);Access::install();
$prefix=$wpdb->prefix;$saved=[];$actors=[];$roles=[];$checks=0;$mail=0;$http=0;
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_integrity','ederp_schema_attempt_release','ederp_pontualidade','ederp_menu_policy','ederp_user_permissions'] as $name)$saved[$name]=get_option($name,null);
add_filter('pre_wp_mail',static function()use(&$mail){$mail++;return true;},999,2);
add_filter('pre_http_request',static function()use(&$http){$http++;return new WP_Error('test','Transporte bloqueado no teste.');},999,3);
function studentCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
function studentRequest(string $path,?array $data=null,array $query=[]):WP_REST_Response{
    $req=new WP_REST_Request($data===null?'GET':'POST','/erp-educacional/v1/'.$path);$req->set_query_params($query);
    if($data!==null){$req->set_header('Content-Type','application/json');$req->set_header('Idempotency-Key',bin2hex(random_bytes(16)));$req->set_body(wp_json_encode($data));}
    return rest_do_request($req);
}
try{
    $tag=bin2hex(random_bytes(5));$wpdb->prefix='ba_'.$tag.'_';$db=new Database($wpdb);$legacy=$db->schema();unset($legacy['aluno_bolsas'],$legacy['contrato_bolsas_aluno']);$db=fixtureM($legacy);
    $financial=array_intersect_key($legacy,array_flip(['contratos','parcelas','lancamentos','baixas','contrato_bolsas']));
    $original=snapshotM($db,$financial);update_option('ederp_schema_version','48');delete_option('ederp_schema_error');update_option('ederp_pontualidade','103.00');
    SchemaMigration::run($db);SchemaMigration::run($db);
    studentCheck(get_option('ederp_schema_version')===Installer::VERSION&&snapshotM($db,$financial)===$original,'migração 48→49 e sua repetição preservam contratos, concessões, lançamentos e baixas');
    $ops=new Operations($db);$access=new Access($db);$academic=new AcademicService($db,$ops);$catalog=new CatalogService($db,$ops);
    rest_get_server();(new Controller($db,$access,$academic,$catalog,new FinanceService($db,$ops),new ExportService($db),new RenewalService($db,$ops,$academic,$access),new StudentWorkflow($db,$ops,$catalog,$academic)))->register();
    $fixture=seedScholarships($db);$id=exerciseStudentScholarships($db,$fixture,'studentCheck');
    foreach(['bolsas','contratos','matriculas'] as $menu){
        $r=studentRequest('perfis',['nome'=>'Bolsas '.$menu.' '.$tag])->get_data();$role=$r['slug'];$roles[]=$role;
        studentRequest('perfis/menus',['role'=>$role,'menus'=>[$menu]]);
        $actor=wp_insert_user(['user_login'=>'ba_'.$menu.'_'.$tag,'user_pass'=>wp_generate_password(40),'role'=>$role]);
        if(is_wp_error($actor))throw new RuntimeException('Usuário de testes indisponível.');$actors[$menu]=$actor;
    }
    $entry=['titulo'=>'Auxílio familiar','descricao'=>'Histórico do programa.','percentual'=>'10.00','ativo'=>0];
    foreach([0,$actors['contratos']] as $actor){
        wp_set_current_user(0);wp_set_current_user($actor);
        studentCheck(in_array(studentRequest('bolsas-alunos/'.$id,$entry)->get_status(),[401,403],true),'criação exige a permissão de bolsas');
    }
    wp_set_current_user(0);wp_set_current_user($actors['bolsas']);
    $found=studentRequest('bolsas-alunos',null,['search'=>'BA-001'])->get_data()['items'];
    studentCheck(count($found)===1&&(int)$found[0]['idaluno']===$id&&!isset($found[0]['data_nascimento']),'perfil de bolsas encontra o estudante pelo RA com nome e dados mínimos');
    $add=studentRequest('bolsas-alunos/'.$id,$entry);studentCheck($add->get_status()===200,'perfil delegado cadastra bolsa do aluno');
    $list=studentRequest('bolsas-alunos/'.$id)->get_data();$last=end($list['items']);
    $edit=studentRequest('bolsas-alunos/'.$id.'/'.$last['idbolsa_aluno'],array_replace($entry,['versao'=>$last['versao'],'titulo'=>'Novo título']));
    studentCheck($edit->get_status()===200&&studentRequest('bolsas-alunos/'.$id)->get_data()['items'][1]['titulo']==='Novo título','edição pelo portal atualiza o registro escolhido');
    $foreign=$db->insert('coligadas',['nome'=>'Outra gestão','razao_social'=>'Outra gestão']);
    studentCheck(Coligadas::within($foreign,fn()=>studentRequest('bolsas-alunos/'.$id)->get_status())===422,'REST mantém a escolha da coligada ao abrir uma bolsa');
    wp_set_current_user(0);wp_set_current_user($admin->ID);
    $plan=studentRequest('turmas/'.$fixture['class'].'/plano',null,['idaluno'=>$id])->get_data();
    studentCheck($plan['percentual_bolsa_aluno']==='20.00','prévia individual recebe o percentual ativo do cadastro');
    $batch=studentRequest('secretaria/alunos',null,['search'=>'BA-001','bolsas_aluno'=>'1'])->get_data();
    studentCheck((float)$batch['items'][0]['percentual_bolsa_aluno']===20.0,'matrícula em lote recebe o percentual de cada aluno');
    wp_set_current_user(0);wp_set_current_user($actors['matriculas']);
    studentCheck(!Access::canManageDiscounts()&&current_user_can('erp_gerenciar_academico'),'operador de matrícula possui acesso acadêmico sem gestão de bolsas');
    $enroll=['idaluno'=>$id,'idturma'=>$fixture['class'],'quantidade_parcelas'=>12,'primeiro_vencimento'=>wp_date('Y-m-d',strtotime('+30 days')),'percentuais_bolsas_alunos'=>[$id=>'10.00']];
    $stale=studentRequest('matriculas',$enroll);
    studentCheck($stale->get_status()===422&&str_contains($stale->get_data()['message'],'bolsas do aluno mudaram'),'matrícula recusa a prévia desatualizada e desfaz a criação');
    $enroll['percentuais_bolsas_alunos']=[$id=>'20.00'];
    $created=studentRequest('matriculas',$enroll);$result=$created->get_data();
    studentCheck($created->get_status()===200&&$db->get('contratos',(int)$result['idcontrato'])['valor_liquido_total']==='14224.80','operador emite o novo contrato já com a bolsa ativa de 20%');
    studentCheck($mail===0&&$http===0,'testes isolados sem cobranças ou mensagens externas');
    echo "WORDPRESS_STUDENT_SCHOLARSHIPS_OK: $checks verificações / WordPress $wp_version / MariaDB ".$wpdb->db_version()."\n";
}finally{
    wp_set_current_user($admin->ID);foreach($actors as $actor)wp_delete_user($actor);foreach($roles as $role)remove_role($role);
    $wpdb->prefix=$prefix;foreach($saved as $name=>$value){if($value===null)delete_option($name);else update_option($name,$value,false);}
}
