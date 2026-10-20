<?php
require __DIR__.'/portal-auth.php';
use EducacionalERP\Presentation\Portal\Portal;
class WP_Post {public string $post_content='';}
define('EDERP_FILE',dirname(__DIR__).'/erp-educacional.php');define('EDERP_VERSION','0.9.9');
$GLOBALS['logged']=false;
function current_user_can($cap){return false;}
function is_user_logged_in(){return $GLOBALS['logged'];}
function wp_get_current_user(){return (object)['ID'=>2,'roles'=>['erp_pessoa'],'display_name'=>'Pessoa'];}
function sanitize_key($v){return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/','',$v));}
function sanitize_text_field($v){return strip_tags($v);}
function is_admin(){return false;}
function wp_enqueue_script(...$args){$GLOBALS['enqueued_scripts'][$args[0]]=$args;}
function wp_enqueue_style(...$args){$GLOBALS['enqueued_styles'][$args[0]]=$args;}
function wp_add_inline_style(...$args){}
function wp_localize_script(...$args){}
function add_shortcode($key,$callback){$GLOBALS['shortcodes'][$key]=$callback;}
function plugins_url($p,$file){return 'https://school.test/plugin/'.$p;}
function esc_url_raw($v){return $v;}
function rest_url($p){return 'https://school.test/api/'.$p;}
function wp_create_nonce($v){return 'nonce-'.$v;}
function admin_url($v){return 'https://school.test/wp-admin/'.$v;}
function wp_nonce_url($u,$a){return $u.'&nonce=test';}
function get_current_user_id(){return 2;}
function get_avatar($id,$size){return '<img alt="">';}
$_SERVER=[];$_POST=[];$_GET=[];$portal=new Portal();
set_error_handler(function($severity,$message){throw new RuntimeException($message);});
$html=$portal->render('inicio');okA(str_contains($html,'method="post"')&&!str_contains($html,'wp-login.php'),'Portal renderiza login próprio');
$_GET=['erp_tela'=>'recuperar_senha'];$html=$portal->render('inicio');okA(str_contains($html,'recuperar')&&!str_contains($html,'wp-login.php'),'Portal renderiza recuperação própria');
$GLOBALS['enqueued_styles']=[];$GLOBALS['enqueued_scripts']=[];$GLOBALS['logged']=true;$_GET=[];$html=$portal->render('inicio');okA(substr_count($html,'class="journey-menu"')===2&&str_contains($html,'Meu perfil'),'menus de desktop e mobile sem variáveis indefinidas');okA(!str_contains($html,'journey-account-chevron')&&!str_contains($html,'⌄')&&str_contains($html,'aria-controls="journey-account-submenu"'),'menu da conta sem setinha mantém controle acessível do submenu');
okA(!isset($GLOBALS['enqueued_styles']['ederp-login']),'telas autenticadas mantêm seus próprios estilos sem carregar layout de login');
$_GET=['erp_tela'=>'redefinir_senha','erp_key'=>'valid-key','erp_login'=>'family'];$html=$portal->render('inicio');okA(str_contains($html,'Nova senha')&&!str_contains($html,'journey-sidebar'),'Portal renderiza redefinição mesmo com sessão aberta');restore_error_handler();

$GLOBALS['logged']=false;$_GET=[];$post=new WP_Post();$post->post_content='[erp_app]';$portal->register();$GLOBALS['enqueued_styles']=[];$GLOBALS['enqueued_scripts']=[];$GLOBALS['hooks']['wp_enqueue_scripts']();okA(isset($GLOBALS['enqueued_styles']['ederp-login'],$GLOBALS['enqueued_scripts']['ederp-login'])&&$GLOBALS['enqueued_styles']['ederp-login'][2]===['journey-ui'],'CSS e mostrar senha carregados no hook antes do conteúdo do login');
