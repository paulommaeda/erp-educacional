<?php
/** End-to-end REST test with the released signing plugin. Only synthetic identities and local transports. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
register_shutdown_function(static function(){
    $error=error_get_last();
    if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))fwrite(STDERR,$error['message'].PHP_EOL);
});
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');$sigPluginFile=getenv('EDERP_TEST_SIGN_PLUGIN');
if(!$bootstrap||!is_file($bootstrap)||!$sigPluginFile||!is_file($sigPluginFile))exit(2);
$_SERVER['HTTPS']='on';$_SERVER['HTTP_HOST']='erp-test.local';$_SERVER['REQUEST_URI']='/';$_SERVER['REMOTE_ADDR']='127.0.0.1';
if(!defined('REST_REQUEST'))define('REST_REQUEST',true);
require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true)throw new RuntimeException('Use exclusivamente um banco WordPress de testes.');
require_once dirname(__DIR__).'/autoload.php';require __DIR__.'/wordpress-migration-fixtures.php';require __DIR__.'/fixtures/scholarships.php';
require_once ABSPATH.'wp-admin/includes/user.php';
if(!defined('EDERP_FILE'))define('EDERP_FILE',dirname(__DIR__).'/erp-educacional.php');
require $sigPluginFile;
use EducacionalERP\Application\{AcademicService,CatalogService,Coligadas,ContractSignatures,ExportService,FinanceService,Operations,RenewalService,StudentScholarships,StudentWorkflow};
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\Signature\Notifications;
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration,SchoolIdentity};
use EducacionalERP\Presentation\Rest\Controller;

function sigCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
function sigReq(string $path,string $method='GET',?array $data=null,array $headers=[],?string $raw=null):WP_REST_Response{
    $uri=explode('?',$path,2);$req=new WP_REST_Request($method,'/'.$uri[0]);
    if(isset($uri[1])){parse_str($uri[1],$q);$req->set_query_params($q);}
    $req->set_header('Content-Type','application/json');$req->set_header('Idempotency-Key',bin2hex(random_bytes(16)));
    foreach($headers as $k=>$v)$req->set_header($k,$v);
    if($data!==null||$raw!==null)$req->set_body($raw??wp_json_encode($data));
    return rest_do_request($req);
}
function sigPass(WP_REST_Response $r,string $label,int $code=200){
    if($r->get_status()!==$code)throw new RuntimeException($label.' (HTTP '.$r->get_status().') '.($r->get_data()['message']??''));
    sigCheck(true,$label);return $r->get_data();
}

$checks=0;$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);Access::install();
$prefix=$wpdb->prefix;$saved=[];$actor=0;$tag=bin2hex(random_bytes(4));
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_integrity','ederp_schema_attempt_release','ederp_assinatura','ederp_pontualidade',SchoolIdentity::option(),'edu_sign_schema','edu_sign_settings'] as $k)$saved[$k]=get_option($k,null);
$key=static fn()=>bin2hex(random_bytes(16));$secret=$key().$key();$base='https://signing.example.test/wp-json/edu-sign/v1';$callback='https://erp.example.test/wp-json/erp-educacional/v1/assinatura-eletronica/retorno';
$otp='';$delivered=[];$issuedBodies=[];$dropNext=false;$offlineIssue=false;$failDownload=false;$blocked=false;$deliverNow=true;
add_filter('pre_wp_mail',static function($pre,$mail)use(&$otp){if(preg_match('/([0-9]{6})\./u',$mail['message'],$m))$otp=$m[1];return true;},999,2);
try{
    $wpdb->prefix='es_'.$tag.'_';$db=new Database($wpdb);$previous=$db->schema();unset($previous['assinatura_envios']);$db=fixtureM($previous);
    $tables=array_intersect_key($previous,array_flip(['contratos','parcelas','lancamentos','baixas','aluno_bolsas','contrato_bolsas_aluno']));$before=snapshotM($db,$tables);
    update_option('ederp_schema_version','49');delete_option('ederp_schema_error');SchemaMigration::run($db);SchemaMigration::run($db);
    sigCheck(get_option('ederp_schema_version')===Installer::VERSION&&snapshotM($db,$tables)===$before,'migração repetida conserva o histórico financeiro e os termos');
    update_option(SchoolIdentity::option(),['nome'=>'Colégio de teste','logo_id'=>0]);update_option('ederp_pontualidade','103.00');
    EduSign\Activator::activate();$user='sig_'.$tag;$actor=wp_insert_user(['user_login'=>$user,'user_pass'=>wp_generate_password(42),'role'=>'edu_contract_manager']);
    $credential=WP_Application_Passwords::create_new_application_password($actor,['name'=>'ERP de testes'])[0];
    update_option('edu_sign_settings',['erp_webhook_url'=>$callback,'erp_webhook_secret'=>$secret]);
    $ops=new Operations($db);$access=new Access($db);$academic=new AcademicService($db,$ops);$cat=new CatalogService($db,$ops);
    rest_get_server();(new Controller($db,$access,$academic,$cat,new FinanceService($db,$ops),new ExportService($db),new RenewalService($db,$ops,$academic,$access),new StudentWorkflow($db,$ops,$cat,$academic)))->register();
    Notifications::routes($db);
    add_filter('pre_http_request',static function($unused,$args,$url)use($base,$callback,$user,$actor,&$delivered,&$issuedBodies,&$dropNext,&$offlineIssue,&$failDownload,&$blocked,&$deliverNow){
        $result=static fn($code,$body)=>['response'=>['code'=>$code,'message'=>'Teste'],'body'=>$body,'headers'=>[]];
        $prior=get_current_user_id();
        try{
            if($url===$callback){
                $delivered[]=[$args['body'],$args['headers']];if(!$deliverNow)return $result(503,'');
                wp_set_current_user(0);$r=sigReq('erp-educacional/v1/assinatura-eletronica/retorno','POST',null,$args['headers'],$args['body']);
                return $result($r->get_status(),'');
            }
            if(!str_starts_with($url,$base.'/')){$blocked=true;return new WP_Error('unexpected','Destino externo bloqueado.');}
            $authorization=$args['headers']['Authorization']??'';
            $parts=explode(':',(string)base64_decode(substr($authorization,6),true),2);
            if(count($parts)!==2||$parts[0]!==$user)return $result(401,'');
            wp_set_current_user(0);$manager=wp_authenticate_application_password(null,$parts[0],$parts[1]);
            if(!($manager instanceof WP_User)||(int)$manager->ID!==(int)$actor)return $result(401,'');
            wp_set_current_user($manager->ID);
            $path='edu-sign/v1'.substr($url,strlen($base));$isIssue=$path==='edu-sign/v1/contracts'&&$args['method']==='POST';
            if($isIssue&&$offlineIssue){$offlineIssue=false;return new WP_Error('offline','Emissão interrompida antes do envio.');}
            $r=sigReq($path,$args['method'],null,[],$args['body']?:null);
            if($isIssue){$issuedBodies[]=json_decode($args['body'],true);if($dropNext){$dropNext=false;return new WP_Error('timeout','Resposta perdida após aceite.');}}
            $data=$r->get_data();
            if($failDownload&&is_string($data))$data.='alterado';
            return $result($r->get_status(),is_string($data)?$data:wp_json_encode($data));
        }finally{wp_set_current_user(0);wp_set_current_user($prior);}
    },999,3);
    $root='erp-educacional/v1/';$config=$root.'assinatura-eletronica/configuracao';
    $out=sigPass(sigReq($config,'POST',['base'=>$base,'usuario'=>$user,'senha'=>$credential,'segredo'=>$secret]),'configuração aceita credenciais da coligada');
    sigCheck($out['tem_senha']&&$out['tem_segredo']&&!str_contains(wp_json_encode($out),$credential)&&!str_contains(wp_json_encode(get_option('ederp_assinatura')),$secret),'credenciais ficam protegidas e não são devolvidas ao navegador');
    $f=seedScholarships($db);$make=function(string $ra)use($db,$f,$key){
        $models=new EducacionalERP\Application\ContractTemplates($db,new Operations($db));
        $model=$models->save(null,['nome'=>'Modelo '.$ra,'texto'=>'Contrato [aluno_nome]. [bolsas_do_aluno]','versao_texto'=>'1','ativo'=>1,'padrao'=>0],$key());
        $r=createScholarshipContract($db,$f,$ra,[],true,['idmodelo'=>$model['idmodelo']]);
        $grant=new StudentScholarships($db);$grant->save((int)$r['idaluno'],null,['titulo'=>'Bolsa Família','descricao'=>'Programa de continuidade','percentual'=>'10.00'],$key());

        return $db->get('contratos',(int)$r['idcontrato']);
    };
    $c=$make('000123');$id=(int)$c['idcontrato'];$api=$root.'contratos/'.$id.'/assinatura';
    wp_set_current_user(0);sigCheck(sigReq($api)->get_status()===401,'somente usuários autorizados consultam envios');
    wp_set_current_user($admin->ID);$dropNext=true;$sendKey=$key();
    sigCheck(sigReq($api.'/enviar','POST',['versao'=>$c['versao']],['Idempotency-Key'=>$sendKey])->get_status()===422,'interrupção após o aceite mantém a emissão pronta para conciliação');
    $list=sigPass(sigReq($api.'/enviar','POST',['versao'=>$c['versao']],['Idempotency-Key'=>$sendKey]),'repetição recupera o mesmo protocolo pela referência');
    $row=$list['items'][0];$n=(int)$row['idenvio'];$uuid=$row['uuid'];$hash=$row['original_hash'];
    sigCheck(count($issuedBodies)===1&&$row['situacao']==='sent'&&$issuedBodies[0]['student_ref']==='000123'&&$issuedBodies[0]['guardian_birth_date']==='1980-01-01','um único convite e PDF para o responsável com RA e nascimento preservados');
    $original=base64_decode($issuedBodies[0]['document']['pdf_base64']);sigCheck(hash('sha256',$original)===$hash&&EduSign\Pdf::read(EduSign\Store::contract($uuid),'issued')===$original&&str_contains($c['termo_snapshot'],'Programa de continuidade'),'PDF recebido é idêntico aos bytes emitidos com o termo da bolsa');
    $foreign=$db->insert('coligadas',['nome'=>'Outra','razao_social'=>'Outra']);
    sigCheck(Coligadas::within($foreign,fn()=>sigReq($api)->get_status())===422,'envio só é acessível na coligada de origem');
    wp_set_current_user(0);$contract=EduSign\Store::contract($uuid);
    $session=sigPass(sigReq('edu-sign/v1/portal/'.$uuid.'/identify','POST',['invite'=>EduSign\Contracts::invite($contract),'cpf'=>$issuedBodies[0]['guardian_cpf'],'birth_date'=>$issuedBodies[0]['guardian_birth_date']]),'responsável da fixture confirma sua identidade')['session'];
    $portal='edu-sign/v1/portal/'.$uuid.'/';$headers=['X-Edu-Session'=>$session];
    $view=sigPass(sigReq($portal.'view','POST',[],$headers),'portal apresenta o PDF original');
    wp_set_current_user($admin->ID);
    $seen=sigPass(sigReq($api.'/'.$n.'/consultar','POST',[]),'consulta recupera a visualização');
    sigCheck($seen['items'][0]['situacao']==='viewed','contrato visualizado continua pendente de assinatura');
    $unsigned=sigPass(sigReq($root.'contratos?codperiodo=todos&assinatura=nao_assinado&search=000123'),'lista não assinados para ação em lote');
    sigCheck((int)$unsigned['items'][0]['envio_assinatura']['idenvio']===$n&&$unsigned['items'][0]['envio_assinatura']['situacao']==='viewed','listagem fornece a última revisão e o estado para conferência');
    sigCheck(sigReq($root.'contratos?assinatura=outro')->get_status()===422,'lista recusa estado de assinatura inválido');
    wp_set_current_user(0);
    sigPass(sigReq($portal.'ack','POST',['clauses'=>array_keys($view['clauses']),'issued_sha256'=>$hash,'scroll_percent'=>100,'format'=>'pdf'],$headers),'ciência das cláusulas registrada');
    sigPass(sigReq($portal.'otp','POST',[],$headers),'código entregue ao transporte de teste');
    sigPass(sigReq($portal.'sign','POST',['confirm'=>true,'code'=>$otp,'issued_sha256'=>$hash],$headers),'responsável fictício conclui a assinatura');
    $remote=EduSign\Store::contract($uuid);
    sigCheck(EduSign\ErpWebhook::deliver($remote),'provedor entrega a confirmação autenticada ao ERP');
    [$body,$signedHeaders]=end($delivered);$hook=$root.'assinatura-eletronica/retorno';
    sigPass(sigReq($hook,'POST',null,$signedHeaders,$body),'reentrega idempotente reconhecida',204);
    $bad=$signedHeaders;$bad['X-Edu-Sign-Timestamp']=(string)(time()-301);
    sigCheck(sigReq($hook,'POST',null,$bad,$body)->get_status()===401&&sigReq($hook,'POST',null,$signedHeaders,$body.' ')->get_status()===401,'tempo e HMAC do corpo original são conferidos');
    $stored=$db->get('contratos',$id);$linked=$db->get('assinatura_envios',$n);
    sigCheck($stored['assinado_em']===$remote->signed_at&&$stored['assinado_por_nome']===$issuedBodies[0]['guardian_name']&&$stored['assinatura_texto_hash']===hash('sha256',$c['termo_snapshot'])&&$linked['evento']===$uuid.':signed'&&$linked['pedido_json']===null,'revisão e assinatura do ERP vinculadas à pessoa e ao documento correto');
    wp_set_current_user($admin->ID);$pdf=sigPass(sigReq($api.'/'.$n.'/pdf'),'gestor baixa o original com certificado');
    sigCheck(hash('sha256',base64_decode($pdf['conteudo']))===$remote->pdf_hash_sha256,'hash do PDF final confere');
    $failDownload=true;sigCheck(sigReq($api.'/'.$n.'/pdf')->get_status()===422,'divergência do PDF final impede a entrega');$failDownload=false;
    $signedList=sigPass(sigReq($root.'contratos?codperiodo=todos&assinatura=assinado&search=000123'),'lista assinados para ação em lote');
    sigCheck($signedList['total']===1&&$signedList['items'][0]['assinado_em']===$remote->signed_at&&sigReq($root.'contratos?codperiodo=todos&assinatura=nao_assinado&search=000123')->get_data()['total']===0,'filtros refletem a assinatura da redação atual');
    $other=$make('000124');$api2=$root.'contratos/'.$other['idcontrato'].'/assinatura';
    $initial=sigPass(sigReq($api2.'/enviar','POST',['versao'=>$other['versao']]),'segundo contrato recebe referência própria')['items'][0];
    $deliverNow=false;$cancel=sigPass(sigReq($api2.'/'.$initial['idenvio'].'/cancelar','POST',[]),'cancelamento confirmado por consulta autenticada');
    sigCheck($cancel['items'][0]['situacao']==='cancelled'&&!$db->get('contratos',(int)$other['idcontrato'])['assinado_em'],'cancelamento preserva o histórico e deixa o texto disponível para nova emissão');
    $revised=sigPass(sigReq($api2.'/enviar','POST',['versao'=>$other['versao']]),'contrato cancelado admite uma nova revisão')['items'][0];
    sigCheck((int)$revised['revisao']===2&&$revised['referencia']!==$initial['referencia'],'número e referência distinguem as duas revisões');
    $db->update('contratos',(int)$other['idcontrato'],['termo_snapshot'=>'Redação posterior ao envio.']);
    $proof=['uuid'=>$revised['uuid'],'external_ref'=>$revised['referencia'],'issued_sha256'=>$revised['original_hash'],'event_id'=>$revised['uuid'].':signed','status'=>'signed','event'=>'contract.signed','is_signed'=>true,'signed_at'=>gmdate('Y-m-d H:i:s'),'pdf_sha256'=>hash('sha256','certificado de teste')];
    $json=wp_json_encode($proof);$now=(string)time();$hs=['X-Edu-Sign-Timestamp'=>$now,'X-Edu-Sign-Signature'=>'sha256='.hash_hmac('sha256',$now.'.'.$json,$secret)];
    sigPass(sigReq($hook,'POST',null,$hs,$json),'confirmação de revisão anterior fica no histórico',204);
    sigCheck(!$db->get('contratos',(int)$other['idcontrato'])['assinado_em']&&!(int)$db->get('assinatura_envios',(int)$revised['idenvio'])['vinculado'],'assinatura de uma redação anterior não marca o texto novo');
    $last=$make('000125');$api3=$root.'contratos/'.$last['idcontrato'].'/assinatura';
    $pending=sigPass(sigReq($api3.'/enviar','POST',['versao'=>$last['versao']]),'terceiro contrato aguarda retorno')['items'][0];
    $providerRow=EduSign\Store::contract($pending['uuid']);
    EduSign\Store::update('contracts',['expires_at'=>'2000-01-01 00:00:00'],['id'=>$providerRow->id]);
    $db->update('assinatura_envios',(int)$pending['idenvio'],['consultar_apos'=>'2000-01-01 00:00:00']);
    Notifications::register($db);
    do_action('init');
    sigCheck(wp_get_schedule(Notifications::HOOK)==='hourly','consulta automática é agendada a cada hora');
    wp_set_current_user(0);$totalInvites=count($issuedBodies);do_action(Notifications::HOOK);
    sigCheck(count($issuedBodies)===$totalInvites,'cron não emite contratos nem solicita convites');
    sigCheck($db->get('assinatura_envios',(int)$pending['idenvio'])['situacao']==='expired'&&!$db->get('contratos',(int)$last['idcontrato'])['assinado_em'],'consulta em segundo plano recupera um vencimento sem webhook');
    wp_set_current_user($admin->ID);$fresh=$make('000126');$api4=$root.'contratos/'.$fresh['idcontrato'].'/assinatura';$offlineIssue=true;
    sigCheck(sigReq($api4.'/enviar','POST',['versao'=>$fresh['versao']])->get_status()===422,'interrupção anterior à emissão mantém a referência recuperável');
    $draft=sigReq($api4)->get_data()['items'][0];
    $db->update('contratos',(int)$fresh['idcontrato'],['termo_snapshot'=>'Revisão nova antes da primeira entrega.']);
    $retired=sigPass(sigReq($api4.'/'.$draft['idenvio'].'/cancelar','POST',[]),'revisão preparada pode ser encerrada antes de uma nova emissão')['items'][0];
    sigCheck($retired['situacao']==='cancelled'&&end($issuedBodies)['mode']==='draft','reserva cancelada não solicita convite para o texto anterior');
    sigCheck(!$blocked,'todo o transporte ocorreu entre as rotas locais das duas extensões');
    echo "WORDPRESS_CONTRACT_SIGNATURES_OK: $checks verificações / WordPress $wp_version / MariaDB ".$wpdb->db_version()." / Assinatura ".EduSign\VERSION."\n";
}finally{
    wp_clear_scheduled_hook(Notifications::HOOK);
    wp_set_current_user($admin->ID);if(is_int($actor)&&$actor)wp_delete_user($actor);$wpdb->prefix=$prefix;
    foreach($saved as $name=>$value){if($value===null)delete_option($name);else update_option($name,$value,false);}
}
