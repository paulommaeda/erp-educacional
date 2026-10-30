<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Educbank;
use EducacionalERP\Application\Coligadas;
use EducacionalERP\Domain\RuleViolation;

/** Callback credentials are independent of both WordPress accounts and the Educbank API token. */
final class ReturnSettings
{
    public static function read(int $company,string $environment):array
    {
        if(!in_array($environment,['sandbox','producao'],true))throw new RuleViolation('Ambiente de retorno inválido.');
        $all=Coligadas::within($company,fn()=>get_option(Coligadas::option('ederp_educbank_retorno'),[]));
        return array_replace(['ativo'=>0,'usuario'=>'','segredo'=>'','tenant'=>'','webhook_id'=>null,'webhook_confirmado'=>false,'erro'=>null,'cursor'=>0,'revisao_cursor'=>0,'ultima_consulta'=>null,'erro_consulta'=>null],is_array($all[$environment]??null)?$all[$environment]:[]);
    }
    public static function write(int $company,string $environment,array $changes):array
    {
        return Coligadas::within($company,static function()use($environment,$changes){$key=Coligadas::option('ederp_educbank_retorno');$all=(array)get_option($key,[]);$all[$environment]=array_replace(self::read(Coligadas::current(),$environment),$changes);update_option($key,$all,false);return $all[$environment];});
    }
    public static function url(int $company,string $environment):string
    {
        return rest_url('erp-educacional/v1/educbank/retorno/'.$company.'/'.$environment);
    }
    public static function publicData(int $company,array $s):array
    {
        $r=self::read($company,$s['ambiente']);return ['ativo'=>(bool)$r['ativo'],'webhook_confirmado'=>(bool)$r['webhook_confirmado'],'url'=>self::url($company,$s['ambiente']),'erro'=>$r['erro'],'ultima_consulta'=>$r['ultima_consulta'],'erro_consulta'=>$r['erro_consulta']];
    }
    public static function ensure(int $company,array $s):array
    {
        $environment=$s['ambiente'];$r=self::read($company,$environment);$tenant=Settings::scope($s)['tenant'];
        if($r['tenant']!==''&&$r['tenant']!==$tenant)throw new RuleViolation('Retorno configurado para outro tenant. Confira as configurações.');
        $renew=$r['segredo']==='';
        if(!$renew)try{Settings::reveal($r['segredo']);}catch(RuleViolation $e){$renew=true;}
        if($renew)$r=array_replace($r,['usuario'=>'ederp-'.$company.'-'.$environment.'-'.bin2hex(random_bytes(4)),'segredo'=>Settings::encrypt(bin2hex(random_bytes(32)))]);
        return self::write($company,$environment,array_replace($r,['tenant'=>$tenant]));
    }
    public static function authorization(array $r):string
    {
        return base64_encode($r['usuario'].':'.Settings::reveal($r['segredo']));
    }
    public static function authenticate(int $company,string $environment,string $header):bool
    {
        try{
            $r=self::read($company,$environment);$s=Settings::read($company);
            if($r['segredo']===''||$r['usuario']===''||$r['tenant']!==($s[$environment]['tenant']??''))return false;
            if(strlen($header)>1024||!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/iD',trim($header),$m))return false;
            $supplied=base64_decode($m[1],true);$expected=base64_decode(self::authorization($r),true);
            return is_string($supplied)&&hash_equals($expected,$supplied);
        }catch(\Throwable $e){return false;}
    }
}
