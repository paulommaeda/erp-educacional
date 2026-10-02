<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;
use EducacionalERP\Presentation\Admin\Pages;
final class Shortcodes
{
    public function register(): void
    {
        foreach(['erp_portal'=>'all','erp_dados_academicos'=>'academic','erp_financeiro'=>'finance','erp_rematricula'=>'renew'] as $code=>$scope) {
            add_shortcode($code,fn()=>$this->render($scope));
        }
        add_action('template_redirect',static function(){
            global $post;
            if($post instanceof \WP_Post && preg_match('/\[erp_(portal|dados_academicos|financeiro|rematricula)\b/',$post->post_content)) {
                if(!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
                nocache_headers();
            }
        });
    }
    private function render(string $scope): string
    {
        if(!is_user_logged_in()) { return '<p>Entre para acessar o portal.</p><a href="'.esc_url(wp_login_url(get_permalink())).'">Entrar</a>'; }
        Pages::assets();
        return '<section class="ederp" data-ederp-portal="'.esc_attr($scope).'"><h2>Portal educacional</h2><p role="status">Carregando seus alunos...</p></section>';
    }
}
