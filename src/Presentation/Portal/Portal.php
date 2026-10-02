<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy};
use EducacionalERP\Presentation\Admin\Pages;
final class Portal
{
    public static function url(string $view='inicio'):string
    {
        $id=(int)get_option('ederp_portal_page',0);$url=$id?get_permalink($id):home_url('/portal-journey/');
        return add_query_arg('erp_tela',$view,$url?:home_url('/portal-journey/'));
    }
    public static function setup():void
    {
        if(!Access::isAdmin()||get_option('ederp_portal_page'))return;
        $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Portal Journey','post_name'=>'portal-journey','post_content'=>'[erp_app]'],true);
        if(!is_wp_error($id))update_option('ederp_portal_page',(int)$id,false);
    }
    public function register():void
    {
        add_action('wp_enqueue_scripts',static function(){global $post;if($post instanceof \WP_Post && str_contains($post->post_content,'[erp_')){Pages::assets();wp_enqueue_style('journey-ui',plugins_url('assets/journey.css',EDERP_FILE),['ederp'],EDERP_VERSION);}});
        add_action('admin_init',[self::class,'setup'],20);
        add_action('admin_init',static function(){if(is_user_logged_in()&&!Access::isAdmin()&&!wp_doing_ajax()){wp_safe_redirect(self::url());exit;}},1);
        add_filter('show_admin_bar',static fn($show)=>Access::isAdmin()?$show:false);
        add_filter('login_redirect',static function($to,$requested,$user){if($user instanceof \WP_User&&!in_array('administrator',$user->roles,true))return self::url();return $to;},10,3);
        add_filter('template_include',static function($file){if(is_page((int)get_option('ederp_portal_page',0))&&get_option('ederp_portal_page'))return dirname(__DIR__,3).'/templates/portal.php';return $file;});
        add_action('template_redirect',static function(){global $post;if($post instanceof \WP_Post&&str_contains($post->post_content,'[erp_')){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}});
        add_shortcode('erp_app',fn()=>$this->render('inicio'));
        add_shortcode('erp_portal',fn()=>$this->render('inicio'));
        foreach(MenuPolicy::MENUS as $key=>$label)add_shortcode('erp_'.$key,fn()=>$this->render($key));
        add_shortcode('erp_dados_academicos',fn()=>$this->render('meus_estudos'));
        add_shortcode('erp_financeiro',fn()=>$this->render('meu_financeiro'));
        add_shortcode('erp_gestao_financeira',fn()=>$this->render('financeiro'));
        add_shortcode('erp_rematricula',fn()=>$this->render('renovacao'));
        add_shortcode('erp_menu',function(){Pages::assets();wp_enqueue_style('journey-ui',plugins_url('assets/journey.css',EDERP_FILE),['ederp'],EDERP_VERSION);return $this->nav();});
    }
    private function nav():string
    {
        if(!is_user_logged_in())return ''; $html='<nav class="journey-menu" aria-label="Navegação do ERP">';
        foreach(MenuPolicy::available() as $key=>$label)$html.='<a href="'.esc_url(self::url($key)).'">'.esc_html($label).'</a>';
        return $html.'</nav>';
    }
    public function render(string $fallback):string
    {
        $view=isset($_GET['erp_tela'])?sanitize_key(wp_unslash($_GET['erp_tela'])):$fallback;
        Pages::assets();wp_enqueue_style('journey-ui',plugins_url('assets/journey.css',EDERP_FILE),['ederp'],EDERP_VERSION);
        wp_enqueue_script('journey-portal',plugins_url('assets/portal.js',EDERP_FILE),['ederp','ederp-person-fields'],EDERP_VERSION,true);
        wp_localize_script('journey-portal','JOURNEY',['view'=>$view,'menus'=>MenuPolicy::available(),'url'=>self::url(),'logout'=>wp_logout_url(self::url()),'logo'=>plugins_url('assets/brand/journey.png',EDERP_FILE),'name'=>wp_get_current_user()->display_name??'','admin'=>Access::isAdmin()]);
        $logo='<img src="'.esc_url(plugins_url('assets/brand/journey.png',EDERP_FILE)).'" alt="Colégio Journey" width="245" height="56">';
        if(!is_user_logged_in())return '<div class="journey-login ederp">'.$logo.'<h1>Bem-vindo à sua jornada.</h1><p>Acesse sua vida acadêmica e mantenha seus dados em dia.</p>'.wp_login_form(['echo'=>false,'redirect'=>self::url(),'label_username'=>'Usuário ou e-mail','label_password'=>'Senha','label_log_in'=>'Entrar','label_remember'=>'Manter conectado']).'<a href="'.esc_url(wp_lostpassword_url(self::url())).'">Esqueci minha senha</a></div>';
        ob_start();echo '<div class="journey-app ederp"><a class="journey-skip" href="#journey-content">Ir para o conteúdo</a><aside class="journey-sidebar"><a class="journey-brand" href="'.esc_url(self::url()).'">'.$logo.'</a>'.$this->nav().'<p class="journey-sidebar-note">Gestão escolar<br>Colégio Journey</p></aside><div class="journey-body"><header class="journey-header"><a href="'.esc_url(self::url()).'">'.$logo.'</a><span>Portal educacional</span><a class="journey-account" href="'.esc_url(self::url('perfil')).'">'.get_avatar(get_current_user_id(),40).'<span>'.esc_html(wp_get_current_user()->display_name).'</span></a></header><main id="journey-content" class="journey-content" data-journey-screen="'.esc_attr($view).'" tabindex="-1">';
        if(!MenuPolicy::can($view))echo '<section class="ederp-card"><h1>Acesso indisponível</h1><p>Seu perfil não tem acesso a esta área.</p><a href="'.esc_url(self::url()).'">Voltar ao início</a></section>';
        elseif(in_array($view,['pessoas','alunos','academico','matriculas','financeiro','rematriculas','importacao','configuracoes'],true)){
            $caps=['pessoas'=>'erp_gerenciar_pessoas','alunos'=>'erp_gerenciar_pessoas','academico'=>'erp_gerenciar_academico','matriculas'=>'erp_gerenciar_academico','financeiro'=>'erp_consultar_financeiro','rematriculas'=>'erp_gerenciar_academico','importacao'=>'manage_options','configuracoes'=>'manage_options'];
            (new Pages())->screen($view,MenuPolicy::MENUS[$view],$caps[$view]);
        }elseif(in_array($view,['meus_estudos','meu_financeiro','renovacao'],true))echo '<section data-ederp-portal="'.esc_attr(['meus_estudos'=>'academic','meu_financeiro'=>'finance','renovacao'=>'renew'][$view]).'"><p role="status">Carregando...</p></section>';
        else echo '<section data-journey-view="'.esc_attr($view).'"><p role="status">Carregando...</p></section>';
        echo '</main><footer class="journey-footer">Colégio Journey · Portal educacional</footer></div><nav class="journey-bottom" aria-label="Navegação rápida"></nav><dialog class="journey-drawer"><div class="journey-drawer-head"><h2>Seu portal</h2><button type="button" data-close-menu aria-label="Fechar menu">Fechar</button></div>'.$this->nav().'<a class="journey-logout" href="'.esc_url(wp_logout_url(self::url())).'">Sair da conta</a></dialog></div>';
        return ob_get_clean();
    }
}
