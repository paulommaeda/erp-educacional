<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Presentation\Portal\Portal;
final class LoginAsUser
{
    /** Render in a normal frontend request so the vendor can load its assets and generate its own nonce. */
    public static function panel(int $id):string {
        if(!UserPermissions::can('switch'))return '<p>Sem permissão para acessar como usuário.</p>';
        $user=get_userdata($id);
        if(!$user||!UserPermissions::portalTarget($user)||$id===get_current_user_id())return '<p>Esta conta não está disponível para acesso assistido.</p>';
        if(!shortcode_exists('login_as_user'))return '<p>Ative o Login as a User PRO com o shortcode oficial login_as_user. A integração não está disponível nesta instalação.</p>';
        if(!current_user_can('edit_users'))return '<p>Habilite este perfil nas permissões do Login as a User (Web357). O plugin exige edit_users e autorização para o perfil de destino.</p>';
        $portal=Portal::url();$parts=wp_parse_url($portal);$relative=($parts['path']??'/').(empty($parts['query'])?'':'?'.$parts['query']);
        $html=do_shortcode('[login_as_user user_id="'.$id.'" redirect_to="'.esc_attr($relative).'" logout_redirect_url="'.esc_attr(Portal::url('usuarios')).'" button_name="Acessar como usuário"]');
        if(trim($html)===''||str_contains($html,'[login_as_user'))return '<p>O Web357 não liberou o acesso. Confira sua licença e a matriz de perfis permitidos no plugin.</p>';
        return '<section class="ederp-card"><h2>Acesso assistido: '.esc_html($user->display_name).'</h2><p>Você usará a sessão desta pessoa, com as mesmas permissões e possibilidade de realizar ações. Para encerrar, use a barra de retorno do Login as User.</p>'.$html.'</section>';
    }
}
