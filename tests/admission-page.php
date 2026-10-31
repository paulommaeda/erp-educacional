<?php
declare(strict_types=1);
require __DIR__.'/portal-routing.php';
use EducacionalERP\Presentation\Portal\Admissions;
class WP_Post {public string $post_content='';}
define('EDERP_FILE',dirname(__DIR__).'/erp-educacional.php');define('EDERP_VERSION','0.19.0');
function wp_enqueue_script(...$a){$GLOBALS['admission_scripts'][$a[0]]=$a;}
function wp_enqueue_style(...$a){$GLOBALS['admission_styles'][$a[0]]=$a;}
function wp_add_inline_style(...$a){}
function rest_url($s){return 'https://test.example/wp-json/'.$s;}
function plugins_url($s,$file){return 'https://test.example/plugin/'.$s;}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}
Admissions::register();$before=$GLOBALS['posts_created'];Admissions::setup();Admissions::setup();okR($GLOBALS['posts_created']===$before+1&&get_option('ederp_admission_page')===321,'cria página pública uma vez');okR(isset($GLOBALS['shortcodes']['erp_requerimento']),'registra shortcode público específico');
wp_set_current_user(0);$post=new WP_Post();$post->post_content='[erp_requerimento]';$GLOBALS['admission_scripts']=[];$GLOBALS['admission_styles']=[];
foreach($GLOBALS['actions']['wp_enqueue_scripts'] as $callbacks)foreach($callbacks as $cb)$cb();
$html=Admissions::render(['coligada'=>'2']);okR(str_contains($html,'data-erp-admission="2"')&&!str_contains($html,'wp-login')&&!str_contains($html,'senha'),'shortcode anônimo aponta para coligada definida sem login');okR(isset($GLOBALS['admission_scripts']['ederp-admissions'],$GLOBALS['admission_scripts']['ederp-admission-form']),'formulário carrega scripts próprios');okR(!isset($GLOBALS['admission_scripts']['ederp'],$GLOBALS['admission_scripts']['ederp-login'],$GLOBALS['admission_scripts']['ederp-workflow']),'página pública não carrega cadastro administrativo ou login');echo "ADMISSION PAGE OK\n";
