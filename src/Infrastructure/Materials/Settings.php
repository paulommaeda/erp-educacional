<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Materials;
use EducacionalERP\Application\{Coligadas,SchoolSettings};
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
final class Settings
{
    public static function option():string {return Coligadas::option('ederp_materials_k8');}
    public static function read():array {return array_replace(['url'=>'','usuario'=>'','senha'=>'','codperiodo'=>SchoolSettings::current(),'automatico'=>false,'versao'=>0],(array)get_option(self::option(),[]));}
    public static function automatic(array $settings):bool
    {
        return $settings['automatico']===true&&$settings['url']!==''&&$settings['usuario']!==''&&$settings['senha']!=='';
    }
    public static function lock(Store $db):string {return 'erp_materials_'.substr(hash('sha256',$db->table('alunos').'|'.Coligadas::current()),0,44);}
    public static function destination(string $url):string
    {
        $url=trim($url);$parts=parse_url($url);
        if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||!wp_http_validate_url($url))throw new RuleViolation('Informe a URL HTTPS pública de importação do K8, sem credenciais no endereço.');
        $query=[];parse_str($parts['query']??'',$query);
        $rest=empty($query)&&preg_match('~/wp-json/k8-produtos/v1/alunos/importar/?$~D',$parts['path']??'');
        $plain=array_keys($query)===['rest_route']&&$query['rest_route']==='/k8-produtos/v1/alunos/importar';
        if(!$rest&&!$plain)throw new RuleViolation('Copie a URL exibida em K8 Produtos → Alunos → Importar pela API.');return $url;
    }
    private static function crypt(string $value,bool $decrypt=false):string
    {
        if(!function_exists('openssl_encrypt'))throw new RuleViolation('A integração exige OpenSSL.');
        $key=hash('sha256',wp_salt('auth').'|materials-k8',true);
        if($decrypt){$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new RuleViolation('Cadastre novamente a Senha de aplicação do K8.');$out=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($out===false)throw new RuleViolation('Cadastre novamente a Senha de aplicação do K8.');return $out;}
        $iv=random_bytes(12);$tag='';$out=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);if($out===false)throw new RuleViolation('Não foi possível proteger a senha.');return base64_encode($iv.$tag.$out);
    }
    public static function password(array $s):string {return self::crypt($s['senha'],true);}
    public static function publicData(Store $db):array
    {
        Access::requireAdmin();$s=self::read();$s['senha_configurada']=$s['senha']!=='';unset($s['senha']);$s['codcoligada']=(string)Coligadas::current();$s['periodos']=$db->rows('SELECT codperiodo,codigo,descricao FROM '.$db->table('periodos_letivos').' WHERE codcoligada=%d ORDER BY data_inicio DESC,codperiodo DESC',[Coligadas::current()]);
        $history=(array)get_option(Coligadas::option('ederp_materials_history'),[]);$last=$history?end($history):null;if(is_array($last))unset($last['chave'],$last['hash']);$s['ultimo']=$last;
        $s['fila_automatica']=(new \EducacionalERP\Application\MaterialsOutbox($db))->status();
        return $s;
    }
    public static function save(Store $db,array $data):array
    {
        Access::requireAdmin();$lock=self::lock($db);if((int)($db->row('SELECT GET_LOCK(%s,3) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Há uma operação de materiais em andamento. Aguarde.');
        try{
            $old=self::read();if((string)($data['versao']??'')!==(string)$old['versao'])throw new RuleViolation('Configuração alterada. Recarregue a tela.');
            $url=self::destination(Input::text($data['url']??null,2000));$user=Input::text($data['usuario']??null,191);if(preg_match('/[:\x00-\x1f\x7f]/',$user))throw new RuleViolation('Login do K8 inválido.');
            $period=Input::id($data['codperiodo']??null);$p=$db->get('periodos_letivos',$period);if((int)$p['codcoligada']!==Coligadas::current())throw new RuleViolation('Período de outra coligada.');
            $password=$data['senha']??'';if(!is_string($password)||strlen($password)>512||preg_match('/[\x00-\x1f\x7f]/',$password))throw new RuleViolation('Senha de aplicação inválida.');$password=trim($password);
            if($password===''&&($url!==$old['url']||$user!==$old['usuario']||$old['senha']===''))throw new RuleViolation('Informe a Senha de aplicação para este destino e login.');
            $auto=$data['automatico']??$old['automatico'];
            if(!is_bool($auto))throw new RuleViolation('Opção de envio automático inválida.');
            update_option(self::option(),['url'=>$url,'usuario'=>$user,'senha'=>$password!==''?self::crypt($password):$old['senha'],'codperiodo'=>$period,'automatico'=>$auto,'versao'=>(int)$old['versao']+1],false);return self::publicData($db);
        }finally{$db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
}
