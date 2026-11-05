<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\WordPress\PostalAddress;
use EducacionalERP\Domain\RuleViolation;
function get_transient($key){return $GLOBALS['postal_cache'][$key]??false;}
function set_transient($key,$data,$ttl){$GLOBALS['postal_cache'][$key]=$data;$GLOBALS['postal_ttl'][$key]=$ttl;}
function sanitize_text_field($value){return trim(strip_tags($value));}
function wp_safe_remote_get($url,$args){$GLOBALS['postal_calls'][]=[$url,$args];return $GLOBALS['postal_response'];}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function postalCheck($condition,$label){if(!$condition)throw new RuntimeException($label);echo "PASS: $label\n";}
function postalReject($fn,$label){try{$fn();}catch(RuleViolation $e){postalCheck(true,$label);return;}throw new RuntimeException('Esperava recusa: '.$label);}
$service=new PostalAddress();$GLOBALS['postal_calls']=[];
$address=['cep'=>'01001-000','logradouro'=>'Praça da Sé','bairro'=>'Sé','localidade'=>'São Paulo','uf'=>'SP','ibge'=>'3550308','complemento'=>'DADO QUE NÃO É O APARTAMENTO','ddd'=>'11'];
$GLOBALS['postal_response']=['code'=>200,'body'=>json_encode($address)];$out=$service->find('01001000');
postalCheck($out['rua']==='Praça da Sé'&&$out['cidade']==='São Paulo'&&$out['estado']==='SP'&&$out['ibge']==='3550308','consulta devolve endereço e município do CEP');
postalCheck(!isset($out['numero'],$out['complemento'],$out['ddd']),'resposta preserva número e complemento para preenchimento do usuário');
[$url,$args]=$GLOBALS['postal_calls'][0];postalCheck($url==='https://viacep.com.br/ws/01001000/json/'&&$args['timeout']===5&&$args['redirection']===0&&$args['limit_response_size']===16384,'requisição usa host fixo, HTTPS, tempo e tamanho limitados');
postalCheck($service->find('01001000')===$out&&count($GLOBALS['postal_calls'])===1&&$GLOBALS['postal_ttl']['ederp_cep_01001000']===86400,'CEP encontrado usa cache por um dia sem repetir consulta');
foreach(['010010000','01001-000','http://127.0.0.1','0100100A'] as $bad)postalReject(fn()=>$service->find($bad),'rejeita CEP inválido antes de fazer requisição: '.$bad);postalCheck(count($GLOBALS['postal_calls'])===1,'entrada inválida não acessa o provedor');
$GLOBALS['postal_response']=['code'=>200,'body'=>'{"erro":true}'];$missing=$service->find('99999999');postalCheck(!$missing['encontrado']&&$GLOBALS['postal_ttl']['ederp_cep_99999999']===300,'CEP inexistente permite preenchimento manual e tem cache curto');
$GLOBALS['postal_response']=new WP_Error('http_request_failed','detalhe interno');postalReject(fn()=>$service->find('11111111'),'falha de rede informa alternativa manual');
$GLOBALS['postal_response']=['code'=>500,'body'=>'ERRO INTERNO'];postalReject(fn()=>$service->find('11111111'),'HTTP inesperado não preenche dados');
$GLOBALS['postal_response']=['code'=>200,'body'=>'<html>indisponível</html>'];postalReject(fn()=>$service->find('11111111'),'resposta inválida não preenche dados');
$GLOBALS['postal_response']=['code'=>200,'body'=>json_encode($address)];postalReject(fn()=>$service->find('11111111'),'CEP diferente do consultado não é aplicado');
$partial=array_replace($address,['cep'=>'80010-000','logradouro'=>'','bairro'=>'','localidade'=>'Curitiba','uf'=>'PR','ibge'=>'4106902']);$GLOBALS['postal_response']=['code'=>200,'body'=>json_encode($partial)];$p=$service->find('80010000');postalCheck($p['encontrado']&&$p['rua']===''&&$p['cidade']==='Curitiba','CEP geral retorna dados parciais para completar manualmente');
$partial['cep']='80010-001';$partial['uf']='ZZ';$GLOBALS['postal_response']['body']=json_encode($partial);postalReject(fn()=>$service->find('80010001'),'UF inválida do provedor é recusada');
echo "POSTAL ADDRESS OK\n";
