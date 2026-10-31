<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
final class AdmissionSettings
{
    private static function option():string {return Coligadas::option('ederp_public_admissions');}
    public static function read():array
    {
        $saved=get_option(self::option(),[]);return (is_array($saved)?$saved:[])+['ativo'=>1,'apresentacao'=>'Preencha o requerimento com atenção. A secretaria conferirá as informações antes de concluir o cadastro. O envio não garante vaga nem efetiva a matrícula.','privacidade'=>'As informações serão utilizadas pelo colégio para analisar o requerimento, cadastrar a família e atender às necessidades educacionais e de saúde informadas. O acesso aos dados é restrito aos profissionais autorizados.','declaracao'=>'Declaro que sou responsável pelas informações apresentadas, que elas são verdadeiras e que estou ciente de sua utilização pelo colégio para analisar este requerimento.'];
    }
    public static function url(int $company):string
    {
        $page=(int)get_option('ederp_admission_page',0);return add_query_arg('colegio',$company,$page?get_permalink($page):home_url('/requerimento-de-matricula/'));
    }
    public static function settings():array {Access::requireAdmin();return self::read()+['url'=>self::url(Coligadas::current()),'shortcode'=>'[erp_requerimento coligada="'.Coligadas::current().'"]'];}
    public static function save(array $d):array
    {
        Access::requireAdmin();if(!in_array($d['ativo']??null,[0,1,'0','1'],true))throw new RuleViolation('Situação inválida.');$out=['ativo'=>(int)$d['ativo']];
        foreach(['apresentacao','privacidade','declaracao'] as $f)$out[$f]=trim(strip_tags(Input::text($d[$f]??null,5000)));
        update_option(self::option(),$out,false);return self::settings();
    }
}
