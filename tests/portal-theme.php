<?php
/** Stubs verify setup orchestration, not a real WordPress/theme installation. */
require dirname(__DIR__).'/autoload.php';
$GLOBALS['options']=[];$GLOBALS['posts']=[];$GLOBALS['theme']='original';$GLOBALS['themes_root']=sys_get_temp_dir().'/erp-theme-test-'.bin2hex(random_bytes(4));
function current_user_can(string $cap):bool{return true;}
function wp_get_current_user():object{return (object)['roles'=>['administrator']];}
function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['options'][$key]??$default;}
function update_option(string $key,mixed $value,bool $autoload=true):void{$GLOBALS['options'][$key]=$value;}
function delete_option(string $key):void{unset($GLOBALS['options'][$key]);}
function wp_insert_post(array $data,bool $error=false):int{$GLOBALS['posts'][12]=$data;return 12;}
function wp_update_post(array $data):int{$GLOBALS['posts'][$data['ID']]=array_merge($GLOBALS['posts'][$data['ID']],$data);return $data['ID'];}
function get_post_status(int $id):string|false{return $GLOBALS['posts'][$id]['post_status']??false;}
function get_post_type(int $id):string|false{return $GLOBALS['posts'][$id]['post_type']??false;}
function is_wp_error(mixed $v):bool{return false;}
function get_theme_root():string{return $GLOBALS['themes_root'];}
function wp_mkdir_p(string $p):bool{return mkdir($p,0777,true);}
function wp_clean_themes_cache(bool $force):void{}
function wp_get_theme(string $slug):object{return new class{public function errors():bool{return false;}};}
function get_stylesheet():string{return $GLOBALS['theme'];}
function switch_theme(string $slug):void{$GLOBALS['theme']=$slug;}
function checkT(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo 'PASS: '.$label."\n";}
use EducacionalERP\Infrastructure\WordPress\PortalTheme;
update_option('ederp_schema_version',EducacionalERP\Infrastructure\Database\Installer::VERSION);update_option('show_on_front','posts');update_option('page_on_front',9);update_option('page_for_posts',12);
PortalTheme::setup(true);checkT(get_stylesheet()===PortalTheme::SLUG&&get_option('show_on_front')==='page'&&get_option('page_on_front')===12&&get_option('page_for_posts')===0,'ativa tema e define portal como página inicial');checkT(is_file(get_theme_root().'/'.PortalTheme::SLUG.'/functions.php'),'tema copiado da distribuição do plugin');checkT(get_option('ederp_portal_previous_site')['theme']==='original','configuração anterior preservada');
$GLOBALS['theme']='tema-manual';PortalTheme::setup();checkT(get_stylesheet()==='tema-manual','não sobrescreve decisão posterior em cada acesso');PortalTheme::setup(true);checkT(get_stylesheet()===PortalTheme::SLUG,'reativação reaplica configuração autorizada');checkT(get_option('ederp_portal_previous_site')['theme']==='original','reativação não substitui registro original');
foreach(glob(get_theme_root().'/'.PortalTheme::SLUG.'/*') as $file)unlink($file);rmdir(get_theme_root().'/'.PortalTheme::SLUG);rmdir(get_theme_root());
echo "PASS: tema e página inicial automáticos\n";
