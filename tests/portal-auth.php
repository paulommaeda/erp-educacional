<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Presentation\Portal\Authentication;
class WP_Error {public function __construct(private string $code=''){}public function get_error_code(){return $this->code;}public function has_errors(){return $this->code!=='';}}
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['hooks']=[];$GLOBALS['rates']=[];$GLOBALS['signed']=null;$GLOBALS['reset']=null;
function add_action($name,$call,$priority=10,$args=1){$GLOBALS['hooks'][$name]=$call;}
function add_filter($name,$call,$priority=10,$args=1){$GLOBALS['hooks'][$name]=$call;}
function do_action($name,...$args){}
function get_option($name,$default=false){return $default;}
function home_url($path=''){return 'https://school.test'.$path;}
function add_query_arg($arg,$value=null,$url=null){if(!is_array($arg)){$arg=[$arg=>$value];}else{$url=$value;}$url=$url??'https://school.test';return $url.(str_contains($url,'?')?'&':'?').http_build_query($arg);}
function wp_unslash($s){return $s;}
function wp_verify_nonce($value,$action){return $value==='nonce-'.$action;}
function wp_nonce_field($action,$name,$referer,$echo){return '<input name="'.$name.'" value="nonce-'.$action.'">';}
function esc_url($v){return htmlspecialchars($v,ENT_QUOTES);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}
function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES);}
function get_transient($name){return $GLOBALS['rates'][$name]??0;}
function set_transient($name,$value,$ttl){$GLOBALS['rates'][$name]=$value;}
function delete_transient($name){unset($GLOBALS['rates'][$name]);}
function is_ssl(){return true;}
function wp_signon($credentials,$secure){$GLOBALS['signed']=$credentials;return $credentials['user_password']==='valid-password'?(object)['ID'=>2]:new WP_Error('incorrect_password');}
function wp_set_current_user($id){$GLOBALS['current']=$id;}
function retrieve_password($login){$GLOBALS['recover']=$login;return $login==='missing'?new WP_Error('invalidcombo'):true;}
function check_password_reset_key($key,$login){return $key==='valid-key'?(object)['ID'=>2]:new WP_Error('expired_key');}
function reset_password($user,$password){$GLOBALS['reset']=[$user->ID,$password];}
define('MINUTE_IN_SECONDS',60);
function okA($condition,$label){if(!$condition)throw new RuntimeException($label);echo "PASS: $label\n";}
Authentication::register();$login=['_wpnonce'=>'nonce-ederp_auth_entrar','login'=>'family','password'=>'wrong'];$r=Authentication::process('entrar',$login);okA(str_contains($r,'incorretos'),'erro de login tratado no portal');okA(Authentication::process('entrar',array_merge($login,['_wpnonce'=>'bad']))!=='login_ok','nonce inválido bloqueia autenticação');$login['password']='valid-password';okA(Authentication::process('entrar',$login)==='login_ok'&&$GLOBALS['current']===2,'login usa wp_signon e estabelece usuário');
$message=Authentication::process('recuperar',['_wpnonce'=>'nonce-ederp_auth_recuperar','login'=>'family']);$missing=Authentication::process('recuperar',['_wpnonce'=>'nonce-ederp_auth_recuperar','login'=>'missing']);okA($message===$missing,'recuperação não revela existência de conta');$email=$GLOBALS['hooks']['retrieve_password_message']('original','valid-key','family',null);okA(str_contains($email,'erp_tela=redefinir_senha')&&!str_contains($email,'wp-login.php'),'e-mail direciona redefinição para ERP');
$d=['_wpnonce'=>'nonce-ederp_auth_redefinir','login'=>'family','key'=>'expired','password'=>'new-long-password','confirm'=>'new-long-password'];okA(str_contains(Authentication::process('redefinir',$d),'expirado')&&$GLOBALS['reset']===null,'chave inválida não redefine senha');$d['key']='valid-key';$d['confirm']='different';okA(str_contains(Authentication::process('redefinir',$d),'conferem'),'confirmação de senha validada');$d['confirm']=$d['password'];okA(Authentication::process('redefinir',$d)==='reset_ok'&&$GLOBALS['reset'][1]==='new-long-password','nova senha aplicada pela API WordPress');
$_POST=[];$_GET=[];foreach(['inicio','recuperar_senha'] as $view){$html=Authentication::render('LOGO',$view);okA(!str_contains($html,'wp-login.php')&&str_contains($html,'method="post"'),'formulário '.$view.' usa destino do portal');}
$_GET=['erp_key'=>'valid-key','erp_login'=>'family'];okA(!str_contains(Authentication::render('LOGO','redefinir_senha'),'wp-login.php'),'formulário de nova senha permanece no portal');$_SERVER['HTTP_ORIGIN']='https://evil.test';okA(str_contains(Authentication::process('entrar',$login),'Origem'),'origem externa rejeitada');unset($_SERVER['HTTP_ORIGIN']);for($i=0;$i<11;$i++)$r=Authentication::process('entrar',array_merge($login,['password'=>'bad','login'=>'rate-test']));okA(str_contains($r,'Aguarde'),'tentativas repetidas limitadas');
