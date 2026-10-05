<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Presentation\Portal\Portal;
final class PortalTheme
{
    public const SLUG='erp-educacional-portal';
    public const VERSION='1';
    /** Explicitly authorized automatic setup, once for upgrades and on each activation. */
    public static function setup(bool $activation=false):void
    {
        if(!Access::isAdmin()||(!$activation&&get_option('ederp_portal_theme_setup')===self::VERSION))return;
        if(get_option('ederp_schema_version')!==\EducacionalERP\Infrastructure\Database\Installer::VERSION||get_option('ederp_schema_error'))return;
        try{
            Portal::setup();$page=(int)get_option('ederp_portal_page');if(!$page||get_post_status($page)!=='publish')throw new \RuntimeException('Não foi possível preparar a página do portal.');
            $source=dirname(__DIR__,3).'/theme/'.self::SLUG;$target=get_theme_root().'/'.self::SLUG;
            if(is_link($target))throw new \RuntimeException('Diretório de tema incompatível.');
            if(is_dir($target)&&!is_file($target.'/erp-owned.txt'))throw new \RuntimeException('Já existe outro tema com o nome reservado do portal.');
            if(!is_dir($target)&&!wp_mkdir_p($target))throw new \RuntimeException('A pasta de temas não permite instalar o tema do portal.');
            foreach(['erp-owned.txt','style.css','functions.php','index.php','front-page.php'] as $file){if(is_link($target.'/'.$file)||!copy($source.'/'.$file,$target.'/'.$file))throw new \RuntimeException('Não foi possível instalar o arquivo do tema: '.$file);}
            wp_clean_themes_cache(true);$theme=wp_get_theme(self::SLUG);if($theme->errors())throw new \RuntimeException('WordPress não reconheceu o tema do portal.');
            if(!get_option('ederp_portal_previous_site'))update_option('ederp_portal_previous_site',['theme'=>get_stylesheet(),'show_on_front'=>get_option('show_on_front'),'page_on_front'=>(int)get_option('page_on_front'),'page_for_posts'=>(int)get_option('page_for_posts')],false);
            switch_theme(self::SLUG);if(get_stylesheet()!==self::SLUG)throw new \RuntimeException('Não foi possível ativar o tema do portal.');
            update_option('show_on_front','page');update_option('page_on_front',$page);if((int)get_option('page_for_posts')===$page)update_option('page_for_posts',0);
            update_option('ederp_portal_theme_setup',self::VERSION,false);delete_option('ederp_portal_theme_error');
        }catch(\Throwable $e){update_option('ederp_portal_theme_error',$e->getMessage(),false);}
    }
    public static function register():void
    {
        add_action('admin_init',static fn()=>self::setup(),25);
        add_action('admin_notices',static function(){if(Access::isAdmin()&&($error=get_option('ederp_portal_theme_error')))echo '<div class="notice notice-error"><p>'.esc_html('Configuração automática do portal: '.$error).'</p></div>';});
    }
}
