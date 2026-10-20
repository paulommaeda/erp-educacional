<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;
/** Uses the WordPress authentication and reset-key APIs without exposing wp-login forms. */
final class Authentication
{
    private static string $message='';
    public static function register():void
    {
        add_action('template_redirect',[self::class,'handle'],0);
        add_filter('lostpassword_url',static fn($url,$redirect)=>Portal::url('recuperar_senha'),10,2);
        add_filter('retrieve_password_message',static function($message,$key,$login,$user){
            $url=add_query_arg(['erp_key'=>$key,'erp_login'=>$login],Portal::url('redefinir_senha'));
            return \EducacionalERP\Infrastructure\WordPress\SchoolIdentity::read()['nome']."\n\nUma redefinição de senha foi solicitada para sua conta.\n\n".$url."\n\nSe não foi você, ignore esta mensagem.\n";
        },20,4);
    }
    public static function logoutUrl():string {return wp_nonce_url(add_query_arg('erp_auth','sair',Portal::url()),'ederp_logout');}
    private static function text(string $key,array $source):string {return isset($source[$key])&&is_string($source[$key])?wp_unslash($source[$key]):'';}
    private static function origin(string $url):?string
    {
        $parts=parse_url($url);if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||isset($parts['user'])||isset($parts['pass']))return null;
        $scheme=strtolower($parts['scheme']);if(!in_array($scheme,['http','https'],true))return null;
        $port=$parts['port']??($scheme==='https'?443:80);
        return $scheme.'://'.strtolower($parts['host']).':'.$port;
    }
    private static function validOrigin(string $header):bool
    {
        $origin=self::origin($header);if($origin===null)return false;
        $allowed=[home_url()];if(function_exists('site_url'))$allowed[]=site_url();
        foreach($allowed as $url)if($origin===self::origin($url))return true;
        return false;
    }
    public static function process(string $action,array $data):string
    {
        if(!wp_verify_nonce(self::text('_wpnonce',$data),'ederp_auth_'.$action))return 'A sessão do formulário expirou. Atualize a página.';
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if($origin&&!self::validOrigin($origin))return 'Origem do formulário inválida.';
        $login=trim(self::text('login',$data));
        $rate='ederp_auth_'.hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.$action.'|'.strtolower($login));
        $attempts=(int)get_transient($rate);if($attempts>=10)return 'Aguarde alguns minutos antes de tentar novamente.';set_transient($rate,$attempts+1,10*MINUTE_IN_SECONDS);
        if($action==='entrar'){
            $user=wp_signon(['user_login'=>$login,'user_password'=>self::text('password',$data),'remember'=>!empty($data['remember'])],is_ssl());
            if(is_wp_error($user))return 'Usuário ou senha incorretos. Tente novamente.';
            delete_transient($rate);wp_set_current_user($user->ID);return 'login_ok';
        }
        if($action==='recuperar'){
            if($login==='')return 'Informe seu usuário ou e-mail.';
            $result=retrieve_password($login);
            if(is_wp_error($result)&&$result->get_error_code()==='retrieve_password_email_failure')return 'Não foi possível enviar o e-mail. Procure a secretaria.';
            return 'Se a conta tiver um e-mail válido, você receberá um link para redefinir a senha.';
        }
        if($action==='redefinir'){
            $key=self::text('key',$data);$user=check_password_reset_key($key,$login);
            if(is_wp_error($user))return 'Link inválido ou expirado. Solicite outro link.';
            $password=self::text('password',$data);
            if(strlen($password)<12||strlen($password)>4096)return 'Use uma senha com pelo menos 12 caracteres.';
            if($password!==self::text('confirm',$data))return 'As senhas não conferem.';
            $errors=new \WP_Error();do_action('validate_password_reset',$errors,$user);if($errors->has_errors())return 'A senha não atende aos requisitos da conta.';
            reset_password($user,$password);delete_transient($rate);return 'reset_ok';
        }
        return 'Ação inválida.';
    }
    public static function handle():void
    {
        global $post;if(!$post instanceof \WP_Post||!str_contains($post->post_content,'[erp_'))return;
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();header('Referrer-Policy: same-origin');
        if(($_GET['erp_auth']??'')==='sair'){
            if(wp_verify_nonce(self::text('_wpnonce',$_GET),'ederp_logout')){wp_logout();wp_safe_redirect(Portal::url());exit;}
            self::$message='Não foi possível sair. Atualize a página.';
        }
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!isset($_POST['erp_auth']))return;
        $action=self::text('erp_auth',$_POST);self::$message=self::process($action,$_POST);
        if(in_array(self::$message,['login_ok','reset_ok'],true)){wp_safe_redirect(self::$message==='login_ok'?Portal::url():add_query_arg('senha_alterada','1',Portal::url()));exit;}
    }
    public static function render(string $logo,string $view):string
    {
        $identity=\EducacionalERP\Infrastructure\WordPress\SchoolIdentity::read();
        $copy=static fn(string $key):string=>str_replace('{colegio}',$identity['nome'],$identity[$key]);
        $reset=$view==='redefinir_senha';$recover=$view==='recuperar_senha';$action=$reset?'redefinir':($recover?'recuperar':'entrar');
        $title=$reset?'Definir nova senha':($recover?'Recuperar senha':$copy('login_titulo_form'));
        $description=$reset?'Escolha uma nova senha para acessar seu portal com segurança.':($recover?'Informe seu usuário ou e-mail. Enviaremos as orientações para recuperar seu acesso.':$copy('login_descricao_form'));
        $notice=self::$message;if(isset($_GET['senha_alterada']))$notice='Senha alterada. Entre com sua nova senha.';
        $login=$reset?self::text('erp_login',$_GET):self::text('login',$_POST);$key=$reset?self::text('erp_key',$_GET):'';
        $html='<section class="erp-login-screen" style="'.esc_attr(\EducacionalERP\Infrastructure\WordPress\SchoolIdentity::loginStyle()).'">';
        $html.='<aside class="erp-login-story" aria-label="'.esc_attr($identity['nome']).'"><div class="erp-login-story-inner"><div class="erp-login-brand"><div class="erp-login-brand-logo">'.$logo.'</div>';
        if($copy('login_etiqueta')!=='')$html.='<span class="erp-login-tag">'.esc_html($copy('login_etiqueta')).'</span>';
        $html.='</div><div class="erp-login-story-copy"><p class="erp-login-eyebrow">'.esc_html($copy('login_chamada_painel')).'</p><h2>'.esc_html($copy('login_titulo_painel')).'</h2><p class="erp-login-story-description">'.esc_html($copy('login_descricao_painel')).'</p></div><p class="erp-login-story-footer">'.esc_html($copy('login_rodape_painel')).'</p></div></aside>';
        $html.='<div class="erp-login-access"><div class="journey-login ederp">';
        if($reset||$recover)$html.='<a class="erp-login-back" href="'.esc_url(Portal::url()).'">← Voltar ao login</a>';
        $html.='<header class="erp-login-intro"><p class="erp-login-eyebrow">'.esc_html($copy('login_boas_vindas')).'</p><h1>'.esc_html($title).'</h1><p>'.esc_html($description).'</p></header>';
        if($notice)$html.='<p role="alert" class="erp-feedback erp-login-notice">'.esc_html($notice).'</p>';
        $footer='<footer class="erp-login-footer">'.esc_html($copy('login_rodape'));
        if(defined('EDERP_VERSION'))$footer.='<span class="erp-login-version">Versão '.esc_html(EDERP_VERSION).'</span>';
        $footer.='</footer></div></div></section>';
        if($reset&&is_wp_error(check_password_reset_key($key,$login)))return $html.'<p role="alert" class="erp-login-notice">Link inválido ou expirado.</p><a href="'.esc_url(Portal::url('recuperar_senha')).'">Solicitar outro link</a>'.$footer;
        $url=$reset?add_query_arg(['erp_key'=>$key,'erp_login'=>$login],Portal::url($view)):Portal::url($recover?'recuperar_senha':'inicio');
        $html.='<form method="post" action="'.esc_url($url).'" class="erp-login-form">'.wp_nonce_field('ederp_auth_'.$action,'_wpnonce',false,false).'<input type="hidden" name="erp_auth" value="'.$action.'">';
        if($reset)$html.='<input type="hidden" name="login" value="'.esc_attr($login).'"><input type="hidden" name="key" value="'.esc_attr($key).'">';
        else $html.='<label for="erp-login-user">E-mail ou usuário</label><input id="erp-login-user" name="login" autocomplete="username" autocapitalize="none" spellcheck="false" value="'.esc_attr($login).'" required>';
        if(!$recover){
            $fields=$reset?['password'=>'Nova senha','confirm'=>'Confirmar nova senha']:['password'=>'Senha'];
            foreach($fields as $name=>$label){$id='erp-login-'.$name;$html.='<label for="'.$id.'">'.$label.'</label><div class="erp-login-password"><input id="'.$id.'" type="password" name="'.$name.'" autocomplete="'.($reset?'new-password':'current-password').'" '.($reset?'minlength="12" maxlength="4096"':'').' required><button type="button" class="erp-login-toggle" data-login-toggle aria-controls="'.$id.'" aria-pressed="false" aria-label="Mostrar '.esc_attr(strtolower($label)).'">Mostrar</button></div>';}
        }
        if(!$reset&&!$recover)$html.='<div class="erp-login-options"><label class="erp-login-remember"><input type="checkbox" name="remember" value="1"> Manter conectado</label><a href="'.esc_url(Portal::url('recuperar_senha')).'">Esqueci minha senha</a></div>';
        $html.='<button type="submit">'.esc_html($reset?'Salvar nova senha':($recover?'Enviar link':$copy('login_botao_rotulo'))).'</button></form>';
        return $html.$footer;
    }
}
