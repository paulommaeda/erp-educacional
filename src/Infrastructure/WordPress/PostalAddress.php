<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\RuleViolation;

/** Public postal data only. Never forwards names, form contents, credentials or caller URLs. */
final class PostalAddress
{
    public function find(string $cep):array
    {
        if(!preg_match('/^[0-9]{8}$/D',$cep))throw new RuleViolation('Informe um CEP com 8 dígitos.');
        $cache='ederp_cep_'. $cep;$saved=get_transient($cache);if(is_array($saved))return $saved;
        $response=wp_safe_remote_get('https://viacep.com.br/ws/'.$cep.'/json/',['timeout'=>5,'redirection'=>0,'limit_response_size'=>16384,'headers'=>['Accept'=>'application/json']]);
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new RuleViolation('A consulta de CEP está indisponível. Preencha o endereço manualmente.');
        try{$data=json_decode(wp_remote_retrieve_body($response),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuleViolation('Não foi possível consultar o CEP. Preencha o endereço manualmente.');}
        if(!is_array($data))throw new RuleViolation('Resposta de CEP inválida. Preencha o endereço manualmente.');
        if(!empty($data['erro'])){$out=['encontrado'=>false,'cep'=>$cep];set_transient($cache,$out,300);return $out;}
        if(!is_string($data['cep']??null)||str_replace('-','',$data['cep'])!==$cep)throw new RuleViolation('O serviço retornou outro CEP. Confira o endereço manualmente.');
        $out=['encontrado'=>true,'cep'=>$cep,'pais'=>'BR'];
        foreach(['logradouro'=>'rua','bairro'=>'bairro','localidade'=>'cidade','uf'=>'estado','ibge'=>'ibge'] as $source=>$target){
            if(!is_string($data[$source]??''))throw new RuleViolation('Resposta de CEP inválida. Preencha o endereço manualmente.');
            $value=sanitize_text_field($data[$source]??'');if(strlen($value)>191)throw new RuleViolation('Resposta de CEP inválida. Preencha o endereço manualmente.');$out[$target]=$value;
        }
        $states=json_decode(file_get_contents(dirname(__DIR__,3).'/assets/localidades/brasil.json'),true);
        if(!isset($states[$out['estado']])||$out['cidade']===''||($out['ibge']!==''&&!preg_match('/^[0-9]{7}$/D',$out['ibge'])))throw new RuleViolation('Não foi possível identificar a cidade do CEP. Confira o endereço manualmente.');
        set_transient($cache,$out,86400);return $out;
    }
}
