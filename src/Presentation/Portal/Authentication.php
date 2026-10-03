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
    public static function process(string $action,array $data):string
    {
        if(!wp_verify_nonce(self::text('_wpnonce',$data),'ederp_auth_'.$action))return 'A sessão do formulário expirou. Atualize a página.';
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if($origin&&parse_url($origin,PHP_URL_HOST)!==parse_url(home_url(),PHP_URL_HOST))return 'Origem do formulário inválida.';
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
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();header('Referrer-Policy: no-referrer');
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
        $reset=$view==='redefinir_senha';$recover=$view==='recuperar_senha';$action=$reset?'redefinir':($recover?'recuperar':'entrar');
        $title=$reset?'Definir nova senha':($recover?'Recuperar senha':'Acesse seu portal');
        $notice=self::$message;if(isset($_GET['senha_alterada']))$notice='Senha alterada. Entre com sua nova senha.';
        $login=$reset?self::text('erp_login',$_GET):self::text('login',$_POST);$key=$reset?self::text('erp_key',$_GET):'';
        $html='<div class="journey-login ederp">'.$logo.'<h1>'.esc_html($title).'</h1>';
        if($notice)$html.='<p role="alert" class="erp-feedback">'.esc_html($notice).'</p>';
        if($reset&&is_wp_error(check_password_reset_key($key,$login)))return $html.'<p>Link inválido ou expirado.</p><a href="'.esc_url(Portal::url('recuperar_senha')).'">Solicitar outro link</a></div>';
        $url=$reset?add_query_arg(['erp_key'=>$key,'erp_login'=>$login],Portal::url($view)):Portal::url($recover?'recuperar_senha':'inicio');
        $html.='<form method="post" action="'.esc_url($url).'" class="ederp-form">'.wp_nonce_field('ederp_auth_'.$action,'_wpnonce',false,false).'<input type="hidden" name="erp_auth" value="'.$action.'">';
        if($reset)$html.='<input type="hidden" name="login" value="'.esc_attr($login).'"><input type="hidden" name="key" value="'.esc_attr($key).'">';
        else $html.='<label>Usuário ou e-mail<input name="login" autocomplete="username" value="'.esc_attr($login).'" required></label>';
        if(!$recover)$html.='<label>'.($reset?'Nova senha':'Senha').'<input type="password" name="password" autocomplete="'.($reset?'new-password':'current-password').'" '.($reset?'minlength="12" maxlength="4096"':'').' required></label>';
        if($reset)$html.='<label>Confirmar nova senha<input type="password" name="confirm" autocomplete="new-password" required></label>';
        if(!$reset&&!$recover)$html.='<label class="ederp-check"><input type="checkbox" name="remember" value="1"> Manter conectado</label>';
        $html.='<button type="submit">'.($reset?'Salvar nova senha':($recover?'Enviar link':'Entrar')).'</button></form><a href="'.esc_url(Portal::url($reset||$recover?'inicio':'recuperar_senha')).'">'.($reset||$recover?'Voltar ao login':'Esqueci minha senha').'</a></div>';
        return $html;
    }
}
