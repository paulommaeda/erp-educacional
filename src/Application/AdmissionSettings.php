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
        $saved=get_option(self::option(),[]);return (is_array($saved)?$saved:[])+['ativo'=>1,'confirmacao_ativa'=>1,'confirmacao_responsaveis'=>'ambos','emails_comercial'=>[],'apresentacao'=>'Preencha o requerimento com atenção. A secretaria conferirá as informações antes de concluir o cadastro. O envio não garante vaga nem efetiva a matrícula.','privacidade'=>'As informações serão utilizadas pelo colégio para analisar o requerimento, cadastrar a família e atender às necessidades educacionais e de saúde informadas. O acesso aos dados é restrito aos profissionais autorizados.','declaracao'=>'Declaro que sou responsável pelas informações apresentadas, que elas são verdadeiras e que estou ciente de sua utilização pelo colégio para analisar este requerimento.'];
    }
    /** Notification recipients are private configuration, never part of the anonymous form. */
    public static function publicData():array {return array_intersect_key(self::read(),array_flip(['ativo','apresentacao','privacidade','declaracao']));}
    public static function url(int $company):string
    {
        $page=(int)get_option('ederp_admission_page',0);return add_query_arg('colegio',$company,$page?get_permalink($page):home_url('/requerimento-de-matricula/'));
    }
    public static function settings():array {Access::requireAdmin();return self::read()+['url'=>self::url(Coligadas::current()),'shortcode'=>'[erp_requerimento coligada="'.Coligadas::current().'"]'];}
    public static function save(array $d):array
    {
        Access::requireAdmin();if(!in_array($d['ativo']??null,[0,1,'0','1'],true))throw new RuleViolation('Situação inválida.');$out=['ativo'=>(int)$d['ativo']];
        foreach(['apresentacao','privacidade','declaracao'] as $f)$out[$f]=trim(strip_tags(Input::text($d[$f]??null,5000)));
        $before=self::read();$active=$d['confirmacao_ativa']??$before['confirmacao_ativa'];if(!in_array($active,[0,1,'0','1'],true))throw new RuleViolation('Situação de confirmação por e-mail inválida.');$out['confirmacao_ativa']=(int)$active;
        $who=$d['confirmacao_responsaveis']??$before['confirmacao_responsaveis'];if(!in_array($who,['academico','financeiro','ambos'],true))throw new RuleViolation('Escolha os responsáveis que receberão a confirmação.');$out['confirmacao_responsaveis']=$who;
        $emails=$d['emails_comercial']??$before['emails_comercial'];if(is_string($emails)){if(strlen($emails)>10000)throw new RuleViolation('Lista de e-mails muito longa.');$emails=preg_split('/[\r\n,;]+/',$emails);}if(!is_array($emails)||!array_is_list($emails))throw new RuleViolation('Informe os e-mails do comercial, um por linha.');$valid=[];foreach($emails as $email){if(!is_string($email))throw new RuleViolation('E-mail do comercial inválido.');$email=strtolower(trim($email));if($email==='')continue;if(strlen($email)>191||!is_email($email))throw new RuleViolation('Confira o e-mail do comercial: '.$email);$valid[$email]=$email;}if(count($valid)>30)throw new RuleViolation('Use até 30 e-mails do comercial.');$out['emails_comercial']=array_values($valid);
        update_option(self::option(),$out,false);return self::settings();
    }
}
