<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;

use EducacionalERP\Application\Coligadas;
use EducacionalERP\Infrastructure\WordPress\{Access,SchoolIdentity};

final class ScholarshipRequestPage
{
    private const OPTION='ederp_scholarship_request_page';
    private const SHORTCODE='erp_requerimento_bolsa';

    public static function register(): void
    {
        add_action('admin_init',[self::class,'setup'],25);
        add_shortcode(self::SHORTCODE,[self::class,'publicForm']);
        add_action('wp_enqueue_scripts',static function(){
            global $post;
            if($post instanceof \WP_Post&&has_shortcode($post->post_content,self::SHORTCODE))self::publicAssets(self::company([]));
        },5);
        add_action('template_redirect',static function(){
            global $post;
            if(self::isApproval()&&$post instanceof \WP_Post&&has_shortcode($post->post_content,self::SHORTCODE)){
                header('Referrer-Policy: no-referrer');
                header('X-Robots-Tag: noindex, nofollow');
            }
        });
        add_filter('template_include',static function($file){
            $id=(int)get_option(self::OPTION,0);
            return $id&&is_page($id)?dirname(__DIR__,3).'/templates/portal.php':$file;
        },20);
    }

    public static function setup(): void
    {
        if(!Access::isAdmin())return;
        $existing=(int)get_option(self::OPTION,0);
        if($existing&&get_post_type($existing)==='page'&&get_post_status($existing)!=='trash')return;
        $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Requerimento de bolsa',
            'post_name'=>'requerimento-de-bolsa','post_content'=>'['.self::SHORTCODE.']'],true);
        if(!is_wp_error($id)&&$id)update_option(self::OPTION,(int)$id,false);
    }

    public static function url(int $company): string
    {
        $id=(int)get_option(self::OPTION,0);
        return add_query_arg('colegio',$company,($id?get_permalink($id):false)?:home_url('/requerimento-de-bolsa/'));
    }

    public static function approvalUrl(int $company): string
    {
        return add_query_arg('aprovar_bolsa','1',self::url($company));
    }

    public static function publicForm($attributes=[]): string
    {
        $company=self::company(is_array($attributes)?$attributes:[]);
        self::publicAssets($company);
        if(self::isApproval()){
            return '<main class="erp-admission-public" data-erp-scholarship-approval data-url="'
                .esc_url(rest_url('erp-educacional/v1/publico/requerimentos-bolsa/'.$company.'/aprovacao/resumo')).'" data-decision-url="'
                .esc_url(rest_url('erp-educacional/v1/publico/requerimentos-bolsa/'.$company.'/aprovacao/decisao')).'">'
                .'<h1>Análise de requerimento de bolsa</h1><p data-status role="status">Carregando requerimento…</p>'
                .'<noscript>Ative o JavaScript e abra novamente o link recebido por e-mail.</noscript></main>';
        }
        return '<main class="erp-admission-public" data-erp-scholarship-form data-url="'
            .esc_url(rest_url('erp-educacional/v1/publico/requerimentos-bolsa/'.$company)).'">'
            .'<h1>Requerimento de desconto</h1><p role="status">Carregando requerimento...</p>'
            .'<noscript>Ative o JavaScript para preencher o requerimento ou procure a secretaria.</noscript></main>';
    }

    public static function review(): void
    {
        wp_enqueue_style('ederp-admissions',plugins_url('assets/admissions.css',EDERP_FILE),[],EDERP_VERSION);
        wp_enqueue_style('ederp-scholarship-request',plugins_url('assets/scholarship-request.css',EDERP_FILE),['ederp-admissions'],EDERP_VERSION);
        wp_enqueue_script('ederp-scholarship-review',plugins_url('assets/scholarship-review.js',EDERP_FILE),['ederp'],EDERP_VERSION,true);
        echo '<section data-erp-scholarship-review><h1>Requerimentos de bolsa</h1><p role="status">Carregando requerimentos...</p></section>';
    }

    private static function publicAssets(int $company): void
    {
        wp_enqueue_style('ederp-admissions',plugins_url('assets/admissions.css',EDERP_FILE),[],EDERP_VERSION);
        wp_add_inline_style('ederp-admissions',Coligadas::within($company,static fn()=>SchoolIdentity::css()));
        wp_enqueue_style('ederp-scholarship-request',plugins_url('assets/scholarship-request.css',EDERP_FILE),['ederp-admissions'],EDERP_VERSION);
        if(self::isApproval()){
            wp_enqueue_script('ederp-scholarship-approval',plugins_url('assets/scholarship-approval.js',EDERP_FILE),[],EDERP_VERSION,true);
        }else{
            wp_enqueue_script('ederp-scholarship-form',plugins_url('assets/scholarship-request.js',EDERP_FILE),[],EDERP_VERSION,true);
        }
    }

    private static function isApproval(): bool
    {
        return ($_GET['aprovar_bolsa']??null)==='1';
    }

    private static function company(array $attributes): int
    {
        $company=$attributes['coligada']??$_GET['colegio']??1;
        return is_scalar($company)&&preg_match('/^[1-9][0-9]{0,17}$/D',(string)$company)?(int)$company:1;
    }
}
