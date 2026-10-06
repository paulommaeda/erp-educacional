<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
/** Group visibility is independent from access to the general student screen. */
final class FieldGroupPolicy
{
    public static function can(int $id):bool
    {
        if(!is_user_logged_in())return false;if(Access::isAdmin())return true;
        $policy=get_option('ederp_field_group_policy',[]);
        foreach(wp_get_current_user()->roles as $role)if(in_array((string)$id,array_map('strval',$policy[$role]??[]),true))return true;
        return false;
    }
}
