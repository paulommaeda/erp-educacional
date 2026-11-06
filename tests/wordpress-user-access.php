<?php
/** Disposable WordPress/MySQL only. Mail is intercepted before any transport. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');if(!$bootstrap||!is_file($bootstrap)){fwrite(STDERR,"Defina EDERP_TEST_BOOTSTRAP para WordPress descartável.\n");exit(2);}
$_SERVER['HTTP_HOST']='erp-test.local';$_SERVER['REQUEST_URI']='/';
require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true||defined('EDERP_SQLITE_TEST'))throw new RuntimeException('Exige banco MySQL descartável autorizado.');
require_once dirname(__DIR__).'/autoload.php';
if(!defined('EDERP_FILE'))define('EDERP_FILE',dirname(__DIR__).'/erp-educacional.php');
if(!defined('EDERP_VERSION'))define('EDERP_VERSION','test-user-access');
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{SchemaMigration,AccountInvitations,UserAccessLog,Access};
use EducacionalERP\Application\{CatalogService,Operations,UserService,DeletionService};
use EducacionalERP\Presentation\Portal\Authentication;
use EducacionalERP\Domain\RuleViolation;
$admin=get_users(['role'=>'administrator','number'=>1])[0]??null;if(!$admin)throw new RuntimeException('Administrador ausente.');wp_set_current_user($admin->ID);
$savedPrefix=$wpdb->prefix;$savedOptions=[];$created=[];$checks=[];$mails=[];$mailOK=true;$confirmDuringSend=null;$previous=$wpdb->suppress_errors(true);
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_attempt_release','timezone_string'] as $key)$savedOptions[$key]=get_option($key,null);
add_filter('pre_wp_mail',static function($result,$atts)use(&$mails,&$mailOK,&$confirmDuringSend){$mails[]=$atts;if($mailOK&&$confirmDuringSend){$link=linkUA($atts);$user=get_user_by('login',$link['erp_login']??'');if($user&&(int)$user->ID===$confirmDuringSend){$confirmDuringSend=null;AccountInvitations::expectInitialReset((int)$user->ID,$link['erp_key']);reset_password($user,'SenhaDuranteEnvio123!');}}return $mailOK;},999,2);
function kUA():string{return bin2hex(random_bytes(16));}
function okUA(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks[]=$message;}
function denyUA(callable $work,string $message):void{try{$work();}catch(RuleViolation $e){okUA(true,$message);return;}throw new RuntimeException($message);}
function versionUA(int $id):string{global $users;foreach($users->listing(['search'=>get_userdata($id)->user_login])['items'] as $row)if($row['id']===$id)return $row['version'];throw new RuntimeException('Conta fora da lista.');}
function linkUA(array $mail):array{preg_match('/href="([^"]*erp_key[^"]*)"/',$mail['message'],$m);parse_str(parse_url(html_entity_decode($m[1]??''),PHP_URL_QUERY)??'',$q);return $q;}
try{
    $wpdb->prefix='ua_'.bin2hex(random_bytes(5)).'_';$db=new Database($wpdb);delete_option('ederp_schema_version');delete_option('ederp_schema_error');update_option('timezone_string','America/Sao_Paulo');SchemaMigration::run($db);
    AccountInvitations::register($db);UserAccessLog::register($db);$invites=new AccountInvitations($db);$log=new UserAccessLog($db);$cat=new CatalogService($db,new Operations($db));$users=new UserService($db);$delete=new DeletionService($db,new Operations($db));$tag=bin2hex(random_bytes(4));
    $make=function(string $name)use($cat,$tag,&$created){$row=$cat->create('pessoas',['nome'=>$name.' '.$tag,'email'=>strtolower($name).'.'.$tag.'@example.test','data_nascimento'=>'1980-01-01'],kUA());$created[]=(int)$row['wp_user_id'];return $row;};
    $a=$make('Alice');$b=$make('Bruno');$c=$make('Clara');$aid=(int)$a['wp_user_id'];$bid=(int)$b['wp_user_id'];$cid=(int)$c['wp_user_id'];
    okUA(get_userdata($aid)!==false&&AccountInvitations::summary($aid)['status']==='nao_enviado','cadastro cria a conta com convite não enviado');
    $invites->process();okUA(count($mails)===0&&!get_user_meta($aid,AccountInvitations::DUE,true),'criar pessoas e processar cron não envia convite');
    $legacy=get_user_meta($cid,AccountInvitations::META,true);$legacy['status']='pendente';update_user_meta($cid,AccountInvitations::META,$legacy);update_user_meta($cid,AccountInvitations::DUE,time()-1);$invites->process();
    okUA(count($mails)===0&&AccountInvitations::summary($cid)['status']==='nao_enviado'&&!get_user_meta($cid,AccountInvitations::DUE,true),'fila automática antiga é interrompida sem envio');
    $payload=['usuarios'=>[['id'=>$aid,'version'=>versionUA($aid)],['id'=>$bid,'version'=>versionUA($bid)]]];$bad=$payload;$bad['usuarios'][1]['version']='alterada';denyUA(fn()=>$users->sendInvitations($bad,kUA()),'lote com conta desatualizada é rejeitado');
    okUA(!get_user_meta($aid,AccountInvitations::DUE,true)&&AccountInvitations::summary($aid)['status']==='nao_enviado','falha no lote reverte fila e estado dos usuários anteriores');
    $batchKey=kUA();$response=$users->sendInvitations($payload,$batchKey);okUA($response['quantidade']===2&&$users->sendInvitations($payload,$batchKey)===$response,'seleção de dois usuários é idempotente');
    okUA(count($mails)===0&&(int)get_user_meta($aid,AccountInvitations::DUE,true)>0&&!get_user_meta($cid,AccountInvitations::DUE,true),'seleção agenda somente as contas escolhidas e não envia dentro da transação');
    $invites->process();okUA(count($mails)===2&&AccountInvitations::summary($aid)['enviado']&&!AccountInvitations::summary($aid)['ok'],'convites selecionados recebem tag Enviado antes da confirmação');
    $keyA=linkUA($mails[0]);if(($keyA['erp_login']??'')!==$a['user_login'])$keyA=linkUA($mails[1]);$keyB=linkUA($mails[0]);if(($keyB['erp_login']??'')!==$b['user_login'])$keyB=linkUA($mails[1]);
    okUA(!empty($keyA['erp_key'])&&!str_contains(wp_json_encode($users->listing([])),$keyA['erp_key']),'listagem não revela chave de convite');
    $beforeMail=count($mails);$invites->process();okUA(count($mails)===$beforeMail,'cron repetido não duplica convites já enviados');
    wp_set_current_user(0);$reset=['_wpnonce'=>wp_create_nonce('ederp_auth_redefinir'),'login'=>$a['user_login'],'key'=>'chave-invalida','password'=>'SenhaInicialAlice123!','confirm'=>'SenhaInicialAlice123!'];
    okUA(str_contains(Authentication::process('redefinir',$reset),'inválido')&&!AccountInvitations::summary($aid)['ok'],'link inválido não marca OK');
    $reset['key']=$keyA['erp_key'];okUA(Authentication::process('redefinir',$reset)==='reset_ok'&&AccountInvitations::summary($aid)['ok'],'criar senha pelo link inicial marca OK');
    okUA(AccountInvitations::summary($aid)['confirmado_em']!==null&&!get_user_meta($aid,AccountInvitations::DUE,true)&&is_wp_error(check_password_reset_key($keyA['erp_key'],$a['user_login'])),'confirmação tem data, encerra fila e invalida link inicial');
    $login=['_wpnonce'=>wp_create_nonce('ederp_auth_entrar'),'login'=>$a['user_login'],'password'=>'SenhaInicialAlice123!'];okUA(Authentication::process('entrar',$login)==='login_ok','conta criada entra com a senha escolhida no convite');
    $events=$db->rows('SELECT * FROM '.$db->table('usuario_acessos').' WHERE wp_user_id=%d',[$aid]);okUA(count($events)===1&&$events[0]['tipo']==='login'&&get_user_meta($aid,UserAccessLog::LAST,true),'login real registra histórico e último acesso');
    $log->activity();okUA(count($db->rows('SELECT * FROM '.$db->table('usuario_acessos').' WHERE wp_user_id=%d',[$aid]))===1,'navegação imediata não duplica entrada de login');
    update_user_meta($aid,UserAccessLog::LAST,gmdate('Y-m-d H:i:s',time()-3600));$log->activity();okUA(count($db->rows('SELECT * FROM '.$db->table('usuario_acessos').' WHERE wp_user_id=%d',[$aid]))===2,'retorno com sessão ativa registra novo acesso');
    wp_set_current_user($admin->ID);$history=$users->accessLog($aid);okUA(count($history['items'])===2&&$history['ultimo_acesso']&&$history['fuso']==='America/Sao_Paulo','tela recebe histórico e último acesso no fuso configurado');
    okUA(UserAccessLog::label('2026-11-06 13:00:00')==='06/11/2026 10:00:00','data e hora usam o fuso do WordPress e não o horário do servidor');
    $users->update($bid,['version'=>versionUA($bid),'password'=>'SenhaDefinidaPorAdmin123!'],kUA());okUA(!AccountInvitations::summary($bid)['ok']&&!AccountInvitations::summary($bid)['pode_reenviar'],'senha definida pelo administrador não simula confirmação do link inicial');
    $staff=wp_insert_user(['user_login'=>'ua_staff_'.$tag,'user_pass'=>wp_generate_password(),'role'=>'erp_secretaria']);if(is_wp_error($staff))throw new RuntimeException('Atendente de teste ausente.');$created[]=(int)$staff;wp_set_current_user((int)$staff);
    okUA(count($users->accessLog($aid)['items'])===2,'secretaria autorizada consulta histórico de conta da família');denyUA(fn()=>$users->accessLog($admin->ID),'secretaria não consulta histórico de conta protegida');
    wp_set_current_user($aid);denyUA(fn()=>$users->accessLog($bid),'usuário comum não consulta o histórico de outro usuário');denyUA(fn()=>$users->sendInvitations($payload,kUA()),'usuário comum não envia convites de outras contas');wp_set_current_user($admin->ID);
    $d=$make('Diana');$did=(int)$d['wp_user_id'];$mailOK=false;$users->resendInvitation($did,kUA());$invites->process();okUA(AccountInvitations::summary($did)['status']==='erro'&&!AccountInvitations::summary($did)['enviado'],'falha de transporte não recebe tag Enviado');$mailOK=true;$users->resendInvitation($did,kUA());$confirmDuringSend=$did;$invites->process();okUA(AccountInvitations::summary($did)['enviado'],'envio manual recupera falha de transporte');okUA(AccountInvitations::summary($did)['ok']&&AccountInvitations::summary($did)['status']==='definida'&&!get_user_meta($did,AccountInvitations::DUE,true),'confirmação concluída durante transporte não é sobrescrita pelo envio');
    $log->record($cid);$post=wp_insert_post(['post_author'=>$cid,'post_title'=>'Conteúdo preservado '.$tag,'post_status'=>'draft']);if(is_wp_error($post)||!$post)throw new RuntimeException('Post fictício inválido.');
    $wpdb->insert($wpdb->links,['link_owner'=>$cid,'link_name'=>'Link preservado','link_url'=>'https://example.test']);$bookmark=(int)$wpdb->insert_id;
    $trigger=$wpdb->prefix.'fail_delete';$audit=$db->table('auditoria');$person=(int)$c['id'];$db->query("CREATE TRIGGER $trigger BEFORE INSERT ON $audit FOR EACH ROW BEGIN IF NEW.entidade='pessoas' AND NEW.entidade_id=$person AND NEW.acao='excluir' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha simulada após exclusão da conta'; END IF; END");
    $remove=['versao'=>$db->get('pessoas',$person)['versao'],'motivo'=>'Correção de cadastro de teste'];try{$delete->remove('pessoas',$person,$remove,kUA());throw new LogicException('ignorou falha na auditoria');}catch(RuntimeException $e){okUA(get_userdata($cid)!==false&&(bool)$db->get('pessoas',$person),'falha na exclusão restaura pessoa e usuário');}
    okUA((int)get_post($post)->post_author===$cid&&(int)$wpdb->get_var($wpdb->prepare("SELECT link_owner FROM $wpdb->links WHERE link_id=%d",$bookmark))===$cid,'rollback também restaura autoria de posts e links');
    $db->query("DROP TRIGGER $trigger");$deleteKey=kUA();$deleted=$delete->remove('pessoas',$person,$remove,$deleteKey);okUA(!get_userdata($cid)&&!$db->row('SELECT codpessoa FROM '.$db->table('pessoas').' WHERE codpessoa=%d',[$person]),'excluir pessoa remove também sua conta WordPress');
    okUA((int)$deleted['wp_user_id_excluido']===$cid&&$delete->remove('pessoas',$person,$remove,$deleteKey)===$deleted,'exclusão conjunta é identificada e idempotente');
    okUA((int)get_post($post)->post_author===(int)$admin->ID&&(int)$wpdb->get_var($wpdb->prepare("SELECT link_owner FROM $wpdb->links WHERE link_id=%d",$bookmark))===(int)$admin->ID,'conteúdo WordPress é preservado e transferido ao administrador');
    okUA(!get_user_meta($cid,AccountInvitations::META,true)&&count($db->rows('SELECT * FROM '.$db->table('usuario_acessos').' WHERE wp_user_id=%d',[$cid]))===1,'convite e metadados da conta são removidos e histórico de acessos é preservado');
    $self=$db->insert('pessoas',['nome'=>'ADMIN FICTÍCIO','email'=>'admin-copy@example.test']);$db->insert('pessoa_usuarios',['codpessoa'=>$self,'wp_user_id'=>$admin->ID]);denyUA(fn()=>$delete->remove('pessoas',$self,['versao'=>1,'motivo'=>'Teste de proteção'],kUA()),'administrador não exclui a própria conta');
    $cat->create('alunos',['codpessoa'=>$a['id'],'ra'=>'UA'.$tag],kUA());denyUA(fn()=>$delete->remove('pessoas',(int)$a['id'],['versao'=>$db->get('pessoas',(int)$a['id'])['versao'],'motivo'=>'Teste com dependência'],kUA()),'pessoa com aluno vinculado mantém proteção contra exclusão');okUA(get_userdata($aid)!==false,'bloqueio por dependência mantém conta do aluno');
    foreach($checks as $message)echo 'PASS: '.$message."\n";echo 'WORDPRESS_USER_ACCESS_OK: '.count($checks)." verificações / WordPress $wp_version / banco ".$wpdb->db_version()."\n";
}finally{foreach($created as $id)delete_user_meta($id,AccountInvitations::DUE);wp_clear_scheduled_hook(AccountInvitations::HOOK);$wpdb->prefix=$savedPrefix;foreach($savedOptions as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}wp_set_current_user($admin->ID);$wpdb->suppress_errors($previous);}
