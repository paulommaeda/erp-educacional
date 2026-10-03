<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\RuleViolation;
final class UserPermissions
{
    public const ACTIONS=['email'=>'Alterar e-mail de usuários','password'=>'Redefinir senha de usuários','switch'=>'Acessar como usuário (Web357)'];
    public static function forRole(string $role):array {return get_option('ederp_user_permissions',[])[$role]??($role==='erp_secretaria'?['email','password']:[]);}
    public static function can(string $action):bool {
        if(!is_user_logged_in()||!MenuPolicy::can('usuarios'))return false;
        if(Access::isAdmin())return true;
        if(!current_user_can('read'))return false;
        foreach(wp_get_current_user()->roles as $role)if(in_array($action,self::forRole($role),true))return true;
        return false;
    }
    public static function save(string $role,array $actions):void {
        Access::requireAdmin();if(!$role||$role==='administrator'||!get_role($role))throw new RuleViolation('Perfil inválido.');
        foreach($actions as $action)if(!is_string($action)||!isset(self::ACTIONS[$action]))throw new RuleViolation('Permissão de usuário inválida.');
        $all=get_option('ederp_user_permissions',[]);$all[$role]=array_values(array_unique($actions));update_option('ederp_user_permissions',$all,false);
    }
    public static function portalTarget(\WP_User $user):bool {
        $allowed=['subscriber','erp_pessoa','erp_aluno','erp_responsavel','erp_responsavel_academico','erp_responsavel_financeiro'];
        if(!$user->roles||array_diff($user->roles,$allowed))return false;
        foreach(['manage_options','edit_users','promote_users','edit_posts','delete_users','create_users','install_plugins','activate_plugins','edit_theme_options','manage_network','erp_gerenciar_pessoas','erp_gerenciar_academico','erp_consultar_financeiro'] as $cap)if(user_can($user,$cap))return false;
        foreach($user->roles as $role){if(self::forRole($role))return false;foreach(get_option('ederp_menu_policy',[])[$role]??MenuPolicy::defaults($role) as $menu)if(in_array($menu,['usuarios','pessoas','alunos','academico','matriculas','financeiro','rematriculas'],true))return false;}
        return !is_super_admin($user->ID);
    }
}
