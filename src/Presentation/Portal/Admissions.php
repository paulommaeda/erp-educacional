<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;
use EducacionalERP\Application\{Coligadas,AdmissionSettings};
use EducacionalERP\Infrastructure\WordPress\{Access,SchoolIdentity};
final class Admissions
{
    public static function setup():void
    {
        if(!Access::isAdmin())return;$id=(int)get_option('ederp_admission_page',0);if($id&&get_post_type($id)==='page'&&get_post_status($id)!=='trash')return;
        $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Requerimento de matrícula','post_name'=>'requerimento-de-matricula','post_content'=>'[erp_requerimento]'],true);if(!is_wp_error($id))update_option('ederp_admission_page',(int)$id,false);
    }
    public static function register():void
    {
        add_action('admin_init',[self::class,'setup'],25);
        add_shortcode('erp_requerimento',[self::class,'render']);
        add_action('wp_enqueue_scripts',static function(){global $post;if($post instanceof \WP_Post&&str_contains($post->post_content,'[erp_requerimento'))self::assets();},5);
        add_filter('template_include',static function($file){if(is_page((int)get_option('ederp_admission_page',0))&&get_option('ederp_admission_page'))return dirname(__DIR__,3).'/templates/portal.php';return $file;},20);
    }
    private static function company(array $attrs=[]):int {return max(1,(int)($attrs['coligada']??($_GET['colegio']??1)));}
    private static function assets(array $attrs=[]):void
    {
        $company=self::company($attrs);wp_enqueue_style('ederp-admission-fonts','https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',[],'1');
        wp_enqueue_style('ederp-admissions',plugins_url('assets/admissions.css',EDERP_FILE),['ederp-admission-fonts'],EDERP_VERSION);
        wp_add_inline_style('ederp-admissions',Coligadas::within($company,fn()=>SchoolIdentity::css()));
        wp_enqueue_script('ederp-admission-form',plugins_url('assets/admission-form.js',EDERP_FILE),[],EDERP_VERSION,true);
        wp_enqueue_script('ederp-admissions',plugins_url('assets/admissions.js',EDERP_FILE),['ederp-admission-form'],EDERP_VERSION,true);
    }
    public static function render($attributes=[]):string
    {
        $attrs=is_array($attributes)?$attributes:[];self::assets($attrs);$company=self::company($attrs);
        return '<main class="erp-admission-public" data-erp-admission="'.esc_attr((string)$company).'" data-root="'.esc_url(rest_url('erp-educacional/v1/')).'" data-assets="'.esc_url(plugins_url('assets/',EDERP_FILE)).'"><p role="status">Carregando requerimento...</p><noscript>Ative o JavaScript para preencher o requerimento ou procure a secretaria.</noscript></main>';
    }
}
