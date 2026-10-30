<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\Educbank\{Client,Settings,ResponseError};
use EducacionalERP\Domain\RuleViolation;
$db=new Database($wpdb);
function wp_salt($s):string{return 'client-error-tests';}
function wp_remote_request($url,$args){$GLOBALS['client_calls'][]=[$url,$args];return $GLOBALS['client_response'];}
function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}
function cc(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo 'PASS: '.$m."\n";}
function response(int $code,mixed $data):void{$GLOBALS['client_response']=['code'=>$code,'body'=>json_encode($data)];}
function message(callable $fn):string{try{$fn();}catch(RuleViolation $e){return $e->getMessage();}throw new RuntimeException('Expected RuleViolation');}
$token='test-secret-educbank-token';Settings::save($db,['ativo'=>0,'ambiente'=>'sandbox','sandbox'=>['tenant'=>'school','token'=>$token],'producao'=>['tenant'=>'','token'=>''],'item_nome'=>'MENSALIDADE','plano'=>'Gateway','versao'=>0]);$client=new Client(Settings::read(1));
response(200,['items'=>[],'totalCount'=>0]);$client->request('GET','/enrollment',null,['showCanceledEnrollments'=>true,'maxResultCount'=>100,'externalId'=>'000007']);$first=$GLOBALS['client_calls'][0];parse_str(parse_url($first[0],PHP_URL_QUERY),$query);cc($query['showCanceledEnrollments']==='true'&&$query['externalId']==='000007'&&$query['maxResultCount']==='100','Booleans na query usam true, preservando IDs e números');
$client->request('GET','/invoice',null,['showCanceledInvoices'=>false]);parse_str(parse_url($GLOBALS['client_calls'][1][0],PHP_URL_QUERY),$query);cc($query['showCanceledInvoices']==='false','False não é convertido em zero');
response(200,['id'=>'00000000-0000-0000-0000-000000000001']);$client->request('POST','/invoice',['closeInvoice'=>true,'items'=>[['valueCents'=>10000]]]);$post=json_decode($GLOBALS['client_calls'][2][1]['body'],true);cc($post['closeInvoice']===true&&$post['items'][0]['valueCents']===10000,'Corpo JSON mantém boolean e centavos inteiros');
response(400,['title'=>'One or more validation errors occurred.','errors'=>['showCanceledEnrollments'=>["The value '1' is not valid."]]]);$error=message(fn()=>$client->request('GET','/enrollment'));cc(str_contains($error,'HTTP 400 ao consultar matrículas')&&str_contains($error,'showCanceledEnrollments')&&str_contains($error,"'1'"),'Erro de binding mostra operação, campo e validação recebida');
response(400,['error'=>['message'=>'Your request is not valid!','validationErrors'=>[['message'=>'CEP obrigatório.','members'=>['guardians[0].address.zipCode']],['message'=>'Ano letivo não disponível.','members'=>['academicYear']]],'data'=>['Authorization'=>'Bearer '.$token],'stackTrace'=>'never expose this stack']]);$error=message(fn()=>$client->request('POST','/enrollment',['student'=>[]]));cc(str_contains($error,'ao criar matrícula')&&str_contains($error,'guardians[0].address.zipCode: CEP obrigatório')&&str_contains($error,'academicYear: Ano letivo'),'Formato ABP conserva campos e erros específicos');cc(!str_contains($error,$token)&&!str_contains($error,'never expose'),'Não copia data nem stack trace do parceiro');
response(400,['error'=>['message'=>'CNPJ não cadastrado nesta escola.','details'=>'Vincule a escola antes de criar a matrícula.']]);$error=message(fn()=>$client->request('POST','/enrollment'));cc(str_contains($error,'CNPJ não cadastrado')&&str_contains($error,'Vincule a escola'),'Mensagens de regra de negócio e detalhes curtos são exibidos');
response(422,['message'=>'Fatura inválida.','errors'=>[['message'=>'Produto não encontrado.']]]);$error=message(fn()=>$client->request('PUT','/invoice/00000000-0000-0000-0000-000000000001'));cc(str_contains($error,'ao atualizar fatura')&&str_contains($error,'Produto não encontrado'),'Outro formato de errors também mostra a validação');
response(401,['error'=>['message'=>'Authorization: Bearer '.$token.'; senha=senha-privada; token=outro-token; contato teste@example.test CPF 52998224725.']]);$error=message(fn()=>$client->request('GET','/invoice'));foreach([$token,'senha-privada','outro-token','teste@example.test','52998224725'] as $secret)cc(!str_contains($error,$secret),'Diagnóstico oculta credencial ou dado pessoal: '.substr(hash('sha256',$secret),0,6));
cc(str_contains($error,'HTTP 401')&&str_contains($error,'omitid'),'Código HTTP preservado sem credenciais');
$GLOBALS['client_response']=['code'=>502,'body'=>'<html><body>Proxy error with '.$token.'</body></html>'];$error=message(fn()=>$client->request('GET','/invoice'));cc(!str_contains($error,'html')&&!str_contains($error,$token)&&str_contains($error,'HTTP 502'),'HTML e corpo bruto não são gravados no diagnóstico');
response(400,['error'=>['message'=>str_repeat('Validação inválida çã. ',100)]]);$error=message(fn()=>$client->request('POST','/invoice'));cc(strlen($error)<=480&&preg_match('//u',$error)===1,'Mensagem longa cabe na fila e mantém UTF-8 válido');
response(400,['error'=>['message'=>'&lt;script&gt;alert(1)&lt;/script&gt; Campo obrigatório.']]);$error=message(fn()=>$client->request('POST','/invoice'));cc(!str_contains($error,'<script>')&&str_contains($error,'Campo obrigatório'),'HTML codificado também é tratado como texto');
cc(preg_match('//u',ResponseError::clip(str_repeat('ç',300),499))===1,'Truncamento do histórico não corta um caractere acentuado');
echo "EDUCBANK_CLIENT_OK\n";
