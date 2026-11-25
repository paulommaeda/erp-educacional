<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;

use EducacionalERP\Domain\RuleViolation;

final class ReportPolicy
{
    public const REPORTS=['assinaturas'=>'Lista de assinaturas','alunos'=>'Alunos'];
    public const OPTION='ederp_report_policy';

    public static function settings():array
    {
        $saved=get_option(self::OPTION,null);
        if(is_array($saved))return $saved;

        // Preserve signature-list access from the previous release on first use.
        $menus=get_option('ederp_menu_policy',[]);
        $initial=[];
        foreach(wp_roles()->roles as $role=>$data){
            $areas=$menus[$role]??MenuPolicy::defaults($role);
            if(in_array('matriculas',$areas,true)&&!empty($data['capabilities']['erp_gerenciar_academico'])){
                $initial[$role][]='assinaturas';
            }
            if($role==='erp_secretaria'&&in_array('alunos',$areas,true)&&!empty($data['capabilities']['erp_gerenciar_pessoas'])){
                $initial[$role][]='alunos';
            }
        }
        add_option(self::OPTION,$initial,'',false);
        return (array)get_option(self::OPTION,$initial);
    }

    public static function available():array
    {
        if(!is_user_logged_in())return [];
        if(Access::isAdmin())return self::REPORTS;

        $granted=[];
        $settings=self::settings();
        foreach(wp_get_current_user()->roles as $role){
            foreach($settings[$role]??[] as $report)$granted[$report]=true;
        }
        return array_intersect_key(self::REPORTS,$granted);
    }

    public static function validate(mixed $reports):array
    {
        if(!is_array($reports)||count($reports)>count(self::REPORTS))throw new RuleViolation('Permissões de relatório inválidas.');
        foreach($reports as $report){
            if(!is_string($report)||!isset(self::REPORTS[$report]))throw new RuleViolation('Relatório inválido.');
        }
        return array_values(array_unique($reports));
    }
}
