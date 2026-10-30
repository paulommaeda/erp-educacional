<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Educbank;
use EducacionalERP\Domain\RuleViolation;
final class Client
{
    public function __construct(private array $settings){}
    public function request(string $method,string $path,?array $body=null,array $query=[]):array
    {
        $scope=Settings::scope($this->settings);$tenant=$scope['tenant'];
        if(!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$tenant)||!preg_match('~^/(enrollment|invoice)(/[a-f0-9-]{36})?(/cancel)?$~D',$path))throw new RuleViolation('Destino Educbank inválido.');
        $env=$this->settings['ambiente']==='sandbox'?'api.sandbox':'api';$url='https://'.$tenant.'.'.$env.'.educbank.com.br/api/ebpay'.$path;
        // ASP.NET boolean query binders expect true/false, not PHP's default 1/0.
        array_walk_recursive($query,static function(&$value){if(is_bool($value))$value=$value?'true':'false';});
        if($query)$url.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $token=Settings::token($this->settings);
        $args=['method'=>$method,'timeout'=>15,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>2097152,'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','Content-Type'=>'application/json']];
        if($body!==null)$args['body']=wp_json_encode($body);
        $response=wp_remote_request($url,$args);
        if(is_wp_error($response))throw new RuleViolation('Sem confirmação de resposta do Educbank. O próximo envio consultará o identificador externo antes de criar.');
        $code=(int)wp_remote_retrieve_response_code($response);$data=json_decode(wp_remote_retrieve_body($response),true);
        if($code<200||$code>=300)throw new RuleViolation(ResponseError::message($code,$method,$path,$data,$token));
        if(!is_array($data))throw new RuleViolation('Educbank não retornou um objeto JSON válido.');return $data;
    }
    public static function uuid(mixed $v):string
    {
        if(!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$v))throw new RuleViolation('Educbank não confirmou o identificador UUID do registro.');return strtolower($v);
    }
}
