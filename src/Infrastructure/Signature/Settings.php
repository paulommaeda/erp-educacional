<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Signature;

use EducacionalERP\Application\Coligadas;
use EducacionalERP\Domain\RuleViolation;
use EducacionalERP\Infrastructure\Educbank\Settings as EncryptedSettings;
use EducacionalERP\Infrastructure\WordPress\Access;

final class Settings
{
    public static function read():array
    {
        return array_replace(['base'=>'','usuario'=>'','senha'=>'','segredo'=>''],(array)get_option(Coligadas::option('ederp_assinatura'),[]));
    }

    public static function publicData():array
    {
        Access::requireAdmin();
        $s=self::read();
        return ['base'=>$s['base'],'usuario'=>$s['usuario'],'tem_senha'=>$s['senha']!=='','tem_segredo'=>$s['segredo']!=='',
            'retorno'=>rest_url('erp-educacional/v1/assinatura-eletronica/retorno')];
    }

    public static function save(array $data):array
    {
        Access::requireAdmin();
        $s=self::read();
        $base=rtrim(trim((string)($data['base']??'')),'/');
        $url=wp_parse_url($base);
        if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])
            ||strlen($base)>512||!str_ends_with($url['path']??'','/wp-json/edu-sign/v1')||!filter_var($base,FILTER_VALIDATE_URL))
            throw new RuleViolation('Informe a URL HTTPS da API terminada em /wp-json/edu-sign/v1.');
        $user=trim((string)($data['usuario']??''));
        if($user===''||strlen($user)>128||preg_match('/[:\x00-\x1f]/',$user))throw new RuleViolation('Informe o login do usuário de integração.');
        $password=trim((string)($data['senha']??''));
        $secret=trim((string)($data['segredo']??''));
        if(strlen($password)>512||strlen($secret)>512||preg_match('/[\r\n]/',$password.$secret))throw new RuleViolation('Confira as credenciais.');
        if(($s['base']!==$base||$s['usuario']!==$user)&&$password==='')throw new RuleViolation('Informe a senha de aplicativo para o site e o usuário selecionados.');
        if($s['base']!==''&&$s['base']!==$base)$s['segredo']='';
        if($password!=='')$s['senha']=EncryptedSettings::encrypt($password);
        if($secret!==''){
            if(!preg_match('/^[!-~]{32,256}$/D',$secret))throw new RuleViolation('Use de 32 a 256 caracteres ASCII sem espaços no segredo de retorno.');
            $s['segredo']=EncryptedSettings::encrypt($secret);
        }
        $s['base']=$base;$s['usuario']=$user;
        update_option(Coligadas::option('ederp_assinatura'),$s,false);
        return self::publicData();
    }

    public static function reveal(string $value):string
    {
        if($value==='')throw new RuleViolation('Configure a credencial da assinatura eletrônica nesta coligada.');
        try{return EncryptedSettings::reveal($value);}
        catch(RuleViolation $e){throw new RuleViolation('Cadastre novamente a credencial da assinatura eletrônica.');}
    }
}
