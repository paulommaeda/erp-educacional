<?php
/** Isolated WordPress/MySQL. Every destination HTTP call and every e-mail is intercepted. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');if(!$bootstrap||!is_file($bootstrap))exit(2);$_SERVER['HTTP_HOST']='erp-test.local';$_SERVER['REQUEST_URI']='/';require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true)throw new RuntimeException('Exige banco de testes autorizado.');
require_once dirname(__DIR__).'/autoload.php';require __DIR__.'/fixtures/materials.php';require __DIR__.'/fixtures/k8-receiver.php';require_once ABSPATH.'wp-admin/includes/user.php';
use EducacionalERP\Application\{MaterialsSync,Operations,CatalogService,AcademicService,FinanceService,ExportService,RenewalService,StudentWorkflow,Coligadas};
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};
use EducacionalERP\Infrastructure\Materials\Settings;
use EducacionalERP\Presentation\Rest\Controller;
$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);$oldPrefix=$wpdb->prefix;$saved=[];$users=[];$checks=0;$mails=0;$tag=bin2hex(random_bytes(5));$GLOBALS['materials_calls']=[];$GLOBALS['materials_imported']=[];
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_integrity','ederp_schema_attempt_release','ederp_materials_k8','ederp_materials_history','ederp_materials_k8_coligada_2','ederp_materials_history_coligada_2'] as $key)$saved[$key]=get_option($key,null);
add_filter('pre_wp_mail',static function()use(&$mails){$mails++;return true;},999,2);
add_filter('pre_http_request',static function($pre,$args,$url){if(str_contains($url,'/k8-produtos/v1/alunos/importar'))return materialsReceiver($url,$args);return new WP_Error('test_http_blocked','HTTP externo bloqueado no teste.');},999,3);
function okWM(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
function requestWM(string $path,?array $body=null,string $key=''):WP_REST_Response {$r=new WP_REST_Request($body===null?'GET':'POST','/erp-educacional/v1/materiais/'.$path);if($body!==null){$r->set_header('Content-Type','application/json');$r->set_header('Idempotency-Key',$key?:bin2hex(random_bytes(16)));$r->set_body(wp_json_encode($body));}return rest_do_request($r);}
try{
    $wpdb->prefix='mk_'.$tag.'_';$db=new Database($wpdb);delete_option('ederp_schema_version');delete_option('ederp_schema_error');SchemaMigration::run($db);delete_option('ederp_materials_k8');delete_option('ederp_materials_history');$fixture=seedMaterials($db);extract($fixture);$ops=new Operations($db);$access=new Access($db);$academicService=new AcademicService($db,$ops);$catalog=new CatalogService($db,$ops);rest_get_server();(new Controller($db,$access,$academicService,$catalog,new FinanceService($db,$ops),new ExportService($db),new RenewalService($db,$ops,$academicService,$access),new StudentWorkflow($db,$ops,$catalog,$academicService)))->register();
    $staff=wp_insert_user(['user_login'=>'mat_staff_'.$tag,'user_pass'=>wp_generate_password(40),'role'=>'erp_secretaria']);$users[]=$staff;
    foreach([0,$staff] as $actor){wp_set_current_user($actor);foreach(['configuracao'=>null,'conferencia'=>null,'enviar'=>['lote'=>1,'assinatura'=>str_repeat('a',64),'validar_apenas'=>false]] as $path=>$body)okWM(in_array(requestWM($path,$body)->get_status(),[401,403],true),'usuário sem administração bloqueado em '.$path);}
    okWM($GLOBALS['materials_calls']===[],'falhas de autorização não executam transporte');wp_set_current_user($admin->ID);$initial=requestWM('configuracao')->get_data();$save=['versao'=>$initial['versao'],'url'=>'https://example.org/wp-json/k8-produtos/v1/alunos/importar','usuario'=>'k8_integrador','senha'=>'senha-aplicacao-ficticia','codperiodo'=>$period];$response=requestWM('configuracao',$save);okWM($response->get_status()===200,'configuração do destino K8 é salva pela REST');$config=$response->get_data();okWM($config['senha_configurada']&&!isset($config['senha'])&&!str_contains(wp_json_encode(get_option(Settings::option())),'senha-aplicacao-ficticia'),'senha é cifrada e omitida das consultas administrativas');okWM(requestWM('configuracao',$save)->get_status()===422,'versão antiga da configuração retorna 422');
    $preview=requestWM('conferencia');$plan=$preview->get_data();okWM($preview->get_status()===200&&$plan['total']===2&&$plan['alunos'][0]['responsaveis']===3,'conferência mostra período, turma e todos os responsáveis vigentes');okWM(!str_contains(wp_json_encode($plan),'52998224725'),'conferência da interface não devolve CPF bruto');$state=serialize([$db->rows('SELECT * FROM '.$db->table('pessoas')),$db->rows('SELECT * FROM '.$db->table('alunos')),$db->rows('SELECT * FROM '.$db->table('aluno_responsaveis')),$db->rows('SELECT * FROM '.$db->table('matriculas'))]);
    $payload=['assinatura'=>$plan['assinatura'],'lote'=>1,'validar_apenas'=>true];$key=bin2hex(random_bytes(16));$validation=requestWM('enviar',$payload,$key);okWM($validation->get_status()===200&&$validation->get_data()['validar_apenas']===true&&$GLOBALS['materials_imported']===[],'validação HTTP usa booleano verdadeiro e não grava no receptor simulado');$call=$GLOBALS['materials_calls'][0];$body=json_decode($call['args']['body'],true);okWM($call['args']['method']==='POST'&&$body['modo']==='atualizar'&&count($body['alunos'][0]['responsaveis'])===3,'WordPress envia POST com o contrato completo do documento');okWM($call['args']['headers']['Authorization']==='Basic '.base64_encode('k8_integrador:senha-aplicacao-ficticia')&&$call['args']['sslverify']===true&&$call['args']['redirection']===0,'Basic Auth, HTTPS e bloqueio de redirecionamento são preservados');$calls=count($GLOBALS['materials_calls']);okWM(requestWM('enviar',$payload,$key)->get_status()===200&&count($GLOBALS['materials_calls'])===$calls,'repetição de tentativa confirmada não duplica o transporte');
    $payload['validar_apenas']=false;$sent=requestWM('enviar',$payload);okWM($sent->get_status()===200&&count($GLOBALS['materials_imported'])===2&&count($GLOBALS['materials_imported']['000042']['dados']['responsaveis'])===3,'envio de gravação entrega alunos e todos os responsáveis juntos');okWM($state===serialize([$db->rows('SELECT * FROM '.$db->table('pessoas')),$db->rows('SELECT * FROM '.$db->table('alunos')),$db->rows('SELECT * FROM '.$db->table('aluno_responsaveis')),$db->rows('SELECT * FROM '.$db->table('matriculas'))])&&$mails===0,'envio não modifica o cadastro ERP nem dispara e-mails');
    $payload['modo']='substituir';okWM(requestWM('enviar',$payload)->get_status()===422,'substituição total é recusada pelo adaptador');unset($payload['modo']);$db->update('pessoas',$anaPerson,['nome'=>'ANA ATUALIZADA']);$calls=count($GLOBALS['materials_calls']);okWM(requestWM('enviar',$payload)->get_status()===422&&count($GLOBALS['materials_calls'])===$calls,'alteração após conferência impede exportar um retrato antigo');$fresh=requestWM('conferencia')->get_data();$payload['assinatura']=$fresh['assinatura'];
    $GLOBALS['materials_timeout']=true;$uncertain=bin2hex(random_bytes(16));okWM(requestWM('enviar',$payload,$uncertain)->get_status()===422,'timeout devolve orientação sem afirmar conclusão');$calls=count($GLOBALS['materials_calls']);okWM(requestWM('enviar',$payload,$uncertain)->get_status()===422&&count($GLOBALS['materials_calls'])===$calls,'mesma tentativa incerta não é reenviada');$GLOBALS['materials_timeout']=false;
    $GLOBALS['materials_status']=403;okWM(requestWM('enviar',$payload)->get_status()===422,'permissão insuficiente do destino não confirma envio');$GLOBALS['materials_status']=200;$GLOBALS['materials_base_warning']='A compra consulta uma base externa.';$warning=requestWM('enviar',$payload);okWM($warning->get_data()['aviso_base']==='A compra consulta uma base externa.','aviso sobre base externa é preservado para o administrador');$GLOBALS['materials_base_warning']=null;
    $public=requestWM('configuracao')->get_data();okWM($public['ultimo']['status']==='confirmado'&&!str_contains(wp_json_encode($public),'senha-aplicacao-ficticia')&&!str_contains(wp_json_encode($public),'52998224725'),'histórico administrativo contém resultado sem credenciais ou cadastros completos');Coligadas::within($company,function()use($db){okWM(Settings::publicData($db)['url']==='','outra coligada não reutiliza a conexão do primeiro colégio');});update_option('ederp_schema_error','falha simulada');okWM(requestWM('conferencia')->get_status()===503,'migração pendente bloqueia a exportação');delete_option('ederp_schema_error');
    $jobs=$db->table('materiais_envios');
    okWM($db->rows("SELECT * FROM $jobs")===[]&&!$public['automatico'],'opção desativada e matrículas anteriores fora da fila');
    $save['versao']=$public['versao'];$save['senha']='';$save['automatico']=true;
    okWM(requestWM('configuracao',$save)->get_data()['automatico']===true,'administrador ativa o envio por coligada');
    $createStudent=static function(string $ra)use($db,$father):int{
        $person=$db->insert('pessoas',['nome'=>'ALUNO TESTE '.$ra]);
        $id=$db->insert('alunos',['codpessoa'=>$person,'ra'=>$ra]);
        $db->insert('aluno_responsaveis',['idaluno'=>$id,'codpessoa_responsavel'=>$father,'parentesco'=>'pai','inicio_vigencia'=>'2000-01-01 00:00:00']);
        return $id;
    };
    $new=$createStudent('000070');
    $calls=count($GLOBALS['materials_calls']);
    try{
        $db->atomic(function()use($academicService,$new,$period,$class){
            $academicService->placeInside($new,$period,$class,bin2hex(random_bytes(16)));
            throw new RuntimeException('rollback-simulado');
        });
    }catch(RuntimeException $error){if($error->getMessage()!=='rollback-simulado')throw $error;}
    okWM($db->rows("SELECT * FROM $jobs")===[]&&count($GLOBALS['materials_calls'])===$calls,'rollback da matrícula desfaz a inclusão na fila');
    $placed=$db->atomic(fn()=>$academicService->placeInside($new,$period,$class,bin2hex(random_bytes(16))));
    okWM(count($db->rows("SELECT * FROM $jobs"))===1&&count($GLOBALS['materials_calls'])===$calls,'matrícula confirmada enfileira apenas o aluno e não chama K8 na requisição');
    $wrongPeriod=$db->insert('periodos_letivos',['codigo'=>'2028','descricao'=>'Fora da integração','data_inicio'=>'2028-01-01','data_fim'=>'2028-12-31']);
    $wrongClass=$db->insert('turmas',['codperiodo'=>$wrongPeriod,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'6A','nome'=>'6A','capacidade'=>100]);
    $db->atomic(fn()=>$academicService->placeInside($createStudent('000071'),$wrongPeriod,$wrongClass,bin2hex(random_bytes(16))));
    okWM(count($db->rows("SELECT * FROM $jobs"))===1,'matrícula de outro período fica fora da fila');
    $cron=\EducacionalERP\Infrastructure\Materials\Cron::HOOK;
    \EducacionalERP\Infrastructure\Materials\Cron::register($db);
    do_action('init');
    okWM(wp_get_schedule($cron)===\EducacionalERP\Infrastructure\Materials\Cron::INTERVAL,'WP-Cron agenda a integração a cada cinco minutos');
    wp_set_current_user(0);
    $GLOBALS['materials_timeout']=true;
    do_action($cron);
    $job=$db->row("SELECT * FROM $jobs WHERE idmatricula=%d",[(int)$placed['idmatricula']]);
    okWM($job['situacao']==='pendente'&&(int)$job['tentativas']===1&&$job['tentar_apos']>gmdate('Y-m-d H:i:s'),'timeout reagenda e mantém a matrícula');
    do_action($cron);
    okWM(count($GLOBALS['materials_calls'])===$calls+1,'intervalo impede nova tentativa imediata');
    $GLOBALS['materials_timeout']=false;
    $db->update('materiais_envios',(int)$job['idenvio'],['tentar_apos'=>'2000-01-01 00:00:00']);
    do_action($cron);do_action($cron);
    $body=json_decode(end($GLOBALS['materials_calls'])['args']['body'],true);
    okWM(count($GLOBALS['materials_calls'])===$calls+2&&count($body['alunos'])===1&&$body['alunos'][0]['ra']==='000070'&&count($body['alunos'][0]['responsaveis'])===1,'cron entrega apenas o RA pendente, com responsável, sem repetir envio confirmado');
    wp_set_current_user($admin->ID);
    okWM((int)requestWM('configuracao')->get_data()['fila_automatica']['totais']['confirmado']===1,'configuração mostra confirmação do envio');
    $cancel=$db->atomic(fn()=>$academicService->placeInside($createStudent('000072'),$period,$class,bin2hex(random_bytes(16))));
    $db->update('matriculas',(int)$cancel['idmatricula'],['status'=>'cancelada','ativo_unico'=>null]);
    $calls=count($GLOBALS['materials_calls']);do_action($cron);
    okWM(count($GLOBALS['materials_calls'])===$calls&&$db->row("SELECT situacao FROM $jobs WHERE idmatricula=%d",[(int)$cancel['idmatricula']])['situacao']==='cancelado','matrícula cancelada antes da entrega fica fora da importação');
    $pause=$db->atomic(fn()=>$academicService->placeInside($createStudent('000073'),$period,$class,bin2hex(random_bytes(16))));
    $save['versao']=requestWM('configuracao')->get_data()['versao'];$save['automatico']=false;
    requestWM('configuracao',$save);do_action($cron);
    okWM(count($GLOBALS['materials_calls'])===$calls&&$db->row("SELECT situacao FROM $jobs WHERE idmatricula=%d",[(int)$pause['idmatricula']])['situacao']==='pendente','desativar a opção pausa a fila da coligada');
    wp_clear_scheduled_hook($cron);
    echo "WORDPRESS_MATERIALS_SYNC_OK: $checks verificações / WordPress $wp_version / MariaDB ".$wpdb->db_version()."\n";
}finally{wp_set_current_user($admin->ID);foreach($users as $user)if(is_int($user))wp_delete_user($user);$wpdb->prefix=$oldPrefix;foreach($saved as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}}
