<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Presentation\Portal\Portal;
Access::install();$GLOBALS['actions']=[];$GLOBALS['filters']=[];$GLOBALS['shortcodes']=[];$GLOBALS['ajax']=false;$GLOBALS['posts_created']=0;
function add_action($n,$f,$p=10,$a=1){$GLOBALS['actions'][$n][$p][]=$f;}
function add_filter($n,$f,$p=10,$a=1){$GLOBALS['filters'][$n]=$f;}
function add_shortcode($n,$f){$GLOBALS['shortcodes'][$n]=$f;}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
class Redirected extends RuntimeException{}
function wp_safe_redirect($url){throw new Redirected($url);}
function home_url($p=''){return 'https://test.example'.$p;}
function get_permalink($id){return 'https://test.example/portal-journey/';}
function add_query_arg($key,$value,$url){return $url.'?'.$key.'='.$value;}
function wp_insert_post($p,$error){$GLOBALS['posts_created']++;return 321;}
function okR(bool $v,string $m){if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";}
(new Portal())->register();Portal::setup();Portal::setup();okR($GLOBALS['posts_created']===1&&get_option('ederp_portal_page')===321,'configuração cria uma única página do portal');
$uid=wp_insert_user(['user_login'=>'visitante','role'=>'erp_pessoa']);wp_set_current_user($uid);$block=$GLOBALS['actions']['admin_init'][1][0];
try{$block();throw new RuntimeException('panel allowed');}catch(Redirected $e){okR(str_contains($e->getMessage(),'portal-journey'),'não administrador é redirecionado do wp-admin');}
$GLOBALS['ajax']=true;$block();okR(true,'requisições AJAX continuam disponíveis sem liberar painel');$GLOBALS['ajax']=false;
okR($GLOBALS['filters']['show_admin_bar'](true)===false,'barra administrativa escondida para pessoa');
okR(str_contains($GLOBALS['filters']['login_redirect']('wp-admin','',get_userdata($uid)),'portal-journey'),'login de pessoa direciona ao portal');
wp_set_current_user(1);$block();okR($GLOBALS['filters']['show_admin_bar'](true)===true,'administrador mantém acesso ao painel');
foreach(['erp_app','erp_pessoas','erp_alunos','erp_matriculas','erp_perfil','erp_perfis','erp_importacao','erp_menu','erp_gestao_financeira','erp_financeiro','erp_rematricula'] as $code)if(!isset($GLOBALS['shortcodes'][$code]))throw new RuntimeException($code);
okR(true,'shortcodes de gestão e autoatendimento registrados');
