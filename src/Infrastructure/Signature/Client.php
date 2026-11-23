<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Signature;

use EducacionalERP\Domain\RuleViolation;

final class Client
{
    public static function request(string $base,string $path,string $method='GET',?string $json=null,bool $pdf=false):array
    {
        $s=Settings::read();
        if($base===''||$base!==$s['base'])throw new RuleViolation('Confira a URL configurada nesta coligada. Esta revisão pertence ao site de assinaturas usado na emissão.');
        $options=['method'=>$method,'redirection'=>0,'sslverify'=>true,'timeout'=>25,'limit_response_size'=>$pdf?33554433:131073,
            'headers'=>['Accept'=>$pdf?'application/pdf':'application/json','Authorization'=>'Basic '.base64_encode($s['usuario'].':'.Settings::reveal($s['senha']))]];
        if($json!==null){$options['headers']['Content-Type']='application/json';$options['body']=$json;}
        $response=wp_safe_remote_request($base.$path,$options);
        if(is_wp_error($response))throw new RuleViolation('O site de assinaturas não respondeu. Consulte o protocolo antes de tentar enviar novamente.');
        $code=(int)wp_remote_retrieve_response_code($response);
        $body=wp_remote_retrieve_body($response);
        if($code<200||$code>=300){
            $reason=match($code){
                401,403=>'Confira o login, a senha de aplicativo e o papel Gestor de contratos no site de assinaturas.',
                404=>'A referência ou o documento ainda não foi localizado no site de assinaturas.',
                409=>'A referência ou o estado diverge do registrado no site de assinaturas. Consulte o protocolo.',
                400,413,422=>'Confira os dados do responsável e do PDF antes de fazer um novo envio.',
                default=>'Confira a disponibilidade do site de assinaturas.'
            };
            throw new RuleViolation('Assinatura eletrônica (HTTP '.$code.'). '.$reason,$code);
        }
        if($pdf){
            if(!str_starts_with($body,'%PDF-')||strlen($body)>33554432)throw new RuleViolation('O arquivo recebido não corresponde a um PDF assinado válido.');
            return ['bytes'=>$body];
        }
        $result=json_decode($body,true);
        if(!is_array($result)||strlen($body)>131072)throw new RuleViolation('O serviço devolveu uma resposta de assinatura inválida.');
        return $result;
    }
}
