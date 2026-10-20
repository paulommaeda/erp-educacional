<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\RuleViolation;
/** The same policy protects field definitions, stored values and write operations. */
final class StudentRecordPolicy
{
    public const SECTIONS=['ficha_medica'=>'Ficha médica','dificuldades_aprendizagem'=>'Dificuldades de aprendizagem','laudos'=>'Laudos'];
    public const OPTION='ederp_student_record_policy';
    public static function section(array $field):?string
    {
        if(isset(self::SECTIONS[$field['area_aluno']??'']))return $field['area_aluno'];
        // Existing canonical fields remain protected even if assigned to another display area.
        foreach([$field['chave']??'',$field['nome']??'',$field['grupo_nome']??''] as $value){
            $slug=trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower(remove_accents((string)$value))),'_');
            $section=match($slug){'ficha_medica'=>'ficha_medica','dificuldades_de_aprendizagem','dificuldades_aprendizagem'=>'dificuldades_aprendizagem','laudo','laudos','laudos_medicos'=>'laudos',default=>null};
            if($section)return $section;
        }
        return null;
    }
    public static function can(string $section):bool
    {
        if(!isset(self::SECTIONS[$section])||!is_user_logged_in())return false;
        if(Access::isAdmin())return true;
        $settings=get_option(self::OPTION,[]);
        foreach(wp_get_current_user()->roles as $role)if(in_array($section,$settings[$role]??[],true))return true;
        return false;
    }
    public static function available():array {return array_filter(self::SECTIONS,fn($label,$key)=>self::can($key),ARRAY_FILTER_USE_BOTH);}
    public static function validate(mixed $sections):array
    {
        if(!is_array($sections)||count($sections)>count(self::SECTIONS))throw new RuleViolation('Áreas protegidas inválidas.');
        foreach($sections as $section)if(!is_string($section)||!isset(self::SECTIONS[$section]))throw new RuleViolation('Área protegida inválida.');
        return array_values(array_unique($sections));
    }
}
