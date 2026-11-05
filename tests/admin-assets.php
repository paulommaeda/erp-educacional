<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Installer;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Presentation\Admin\Pages;

define('EDERP_FILE',dirname(__DIR__).'/erp-educacional.php');define('EDERP_VERSION','test-assets');
class WP_Post {public string $post_content='[erp_app]';}
function sanitize_key($value){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',$value));}
function sanitize_text_field($value){return trim(strip_tags($value));}
function is_admin(){return $GLOBALS['assets_is_admin']??false;}
function wp_enqueue_media(){$GLOBALS['assets_media']=($GLOBALS['assets_media']??0)+1;}
function wp_enqueue_script(...$args){$GLOBALS['assets_scripts'][$args[0]]=$args;}
function wp_enqueue_style(...$args){$GLOBALS['assets_styles'][$args[0]]=$args;}
function wp_add_inline_style($handle,$css){$GLOBALS['assets_inline'][$handle]=$css;}
function wp_localize_script($handle,$name,$data){$GLOBALS['assets_localized'][$name]=$data;$GLOBALS['assets_localization_count']=($GLOBALS['assets_localization_count']??0)+1;}
function plugins_url($path,$file){return 'https://school.test/plugins/erp-educacional/'.$path;}
function esc_url_raw($value){return $value;}
function rest_url($path){return 'https://school.test/wp-json/'.$path;}
function wp_create_nonce($action){return 'nonce-'.$action;}
function admin_url($path){return 'https://school.test/wp-admin/'.$path;}
function home_url($path){return 'https://school.test'.$path;}
function add_query_arg($key,$value,$url){return $url.'?'.http_build_query([$key=>$value]);}
function add_menu_page(...$args){}
function add_submenu_page(...$args){}
function add_action($name,$callback,...$args){$GLOBALS['assets_hooks'][$name][]=$callback;}
$checks=0;
function assetCheck(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
set_error_handler(static function($severity,$message){throw new RuntimeException($message);});
Access::install();update_option('ederp_schema_version',Installer::VERSION);$post=new WP_Post();$pages=new Pages();$pages->register();
$financial=wp_insert_user(['user_login'=>'financeiro.assets','role'=>'erp_financeiro']);$secretary=wp_insert_user(['user_login'=>'secretaria.assets','role'=>'erp_secretaria']);$student=wp_insert_user(['user_login'=>'aluno.assets','role'=>'erp_aluno']);
foreach([[1,'administrador',true],[$financial,'financeiro',true],[$secretary,'secretaria',false],[$student,'aluno',false]] as [$user,$role,$canDelete]){
    wp_set_current_user($user);
    foreach([true,false] as $admin){
        $GLOBALS['assets_is_admin']=$admin;$GLOBALS['assets_localized']=[];
        if($admin)foreach($GLOBALS['assets_hooks']['admin_enqueue_scripts'] as $hook)$hook('toplevel_page_ederp');else Pages::assets();
        $data=$GLOBALS['assets_localized']['EDERP']??null;$context=$admin?'wp-admin':'portal';
        assetCheck(is_array($data),'assets executados sem fatal para '.$role.' no '.$context);
        assetCheck($data['caps']['deleteFinance']===$canDelete,'permissão real de exclusão publicada para '.$role.' no '.$context);
        assetCheck($data['caps']['admin']===($user===1)&&$data['front']===!$admin,'contexto e administrador corretos no '.$context);
        assetCheck($data['root']==='https://school.test/wp-json/erp-educacional/v1/'&&$data['nonce']==='nonce-wp_rest','REST e nonce preservados no '.$context);
        assetCheck(isset($GLOBALS['assets_scripts']['ederp'],$GLOBALS['assets_scripts']['ederp-workflow'],$GLOBALS['assets_styles']['ederp'])&&!empty($GLOBALS['assets_inline']['ederp']),'scripts, estilos e identidade carregados no '.$context);
    }
}
wp_set_current_user($financial);update_option('ederp_menu_policy',['erp_financeiro'=>[]]);Pages::assets();assetCheck($GLOBALS['assets_localized']['EDERP']['caps']['deleteFinance']===false,'menu financeiro revogado retira exclusão dos assets');update_option('ederp_menu_policy',[]);$GLOBALS['test_roles']['erp_financeiro']['capabilities']['erp_excluir_lancamentos']=false;Pages::assets();assetCheck($GLOBALS['assets_localized']['EDERP']['caps']['deleteFinance']===false,'capacidade revogada retira exclusão dos assets');
$before=$GLOBALS['assets_localization_count'];foreach($GLOBALS['assets_hooks']['admin_enqueue_scripts'] as $hook)$hook('dashboard');assetCheck($GLOBALS['assets_localization_count']===$before,'outras telas do WordPress não carregam os assets do ERP');
restore_error_handler();echo "ADMIN_ASSETS_OK: $checks verificações\n";
