<?php
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\WordPress\StudentDocumentFiles;
use EducacionalERP\Domain\RuleViolation;
function wp_upload_dir(){return ['basedir'=>sys_get_temp_dir().'/erp-student-files-test','error'=>false];}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function wp_salt($scheme){return 'test-only-student-document-secret';}
function sanitize_file_name($name){return preg_replace('/[^a-zA-Z0-9._-]/','-',basename($name));}
function okS($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";}
function denyS($fn,$m){try{$fn();}catch(RuleViolation $e){okS(true,$m);return;}throw new RuntimeException($m);}
// Only the HTTP-upload check is substituted; MIME, encryption, integrity and deletion use real code.
final class TestStudentDocumentFiles extends StudentDocumentFiles {protected function uploaded(string $path):bool{return is_file($path);}}
$files=new TestStudentDocumentFiles();$tmp=tempnam(sys_get_temp_dir(),'erp-file-');$bytes="%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";file_put_contents($tmp,$bytes);$input=['tmp_name'=>$tmp,'name'=>'certidao.pdf','error'=>UPLOAD_ERR_OK,'size'=>strlen($bytes)];
denyS(fn()=>(new StudentDocumentFiles())->put($input),'arquivo local não é aceito como upload HTTP');
$stored=$files->put($input);$path=wp_upload_dir()['basedir'].'/erp-documentos-alunos/'.$stored['arquivo_token'].'.bin';okS(is_file($path)&&!str_contains(file_get_contents($path),'%PDF'),'PDF é guardado criptografado sem URL pública da mídia');okS($files->read($stored['arquivo_token'])===$bytes&&$stored['sha256']===hash('sha256',$bytes),'conteúdo e hash conferem após leitura');
denyS(fn()=>$files->read('../fora'),'token não aceita travessia de diretório');denyS(fn()=>$files->put(array_merge($input,['name'=>'certidao.php'])),'extensão executável não é aceita');file_put_contents($tmp,'<html>Arquivo disfarçado</html>');denyS(fn()=>$files->put($input),'HTML com extensão PDF não é aceito');file_put_contents($tmp,str_repeat('x',5242881));denyS(fn()=>$files->put($input),'tamanho real acima de 5 MB não é aceito');
$encrypted=file_get_contents($path);$encrypted[30]=chr(ord($encrypted[30])^1);file_put_contents($path,$encrypted);denyS(fn()=>$files->read($stored['arquivo_token']),'arquivo adulterado falha na verificação de integridade');okS($files->delete($stored['arquivo_token'])&&!is_file($path),'exclusão remove arquivo físico');okS($files->delete($stored['arquivo_token']),'limpeza repetida é idempotente');unlink($tmp);
