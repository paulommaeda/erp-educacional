<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\RuleViolation;
/** Encrypted at rest: the uploaded declaration has no public readable media URL. */
final class PrivateDocuments
{
    private function path(string $token):string {if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuleViolation('Documento inválido.');$upload=wp_upload_dir();if(!empty($upload['error']))throw new RuleViolation('Diretório de documentos indisponível.');$dir=$upload['basedir'].'/erp-documentos';if(!wp_mkdir_p($dir))throw new RuleViolation('Não foi possível preparar o diretório de documentos.');return $dir.'/'.$token.'.bin';}
    private function key():string {return hash('sha256',wp_salt('auth').'|erp-private-documents',true);}
    public function upload(array $f):array
    {
        Access::requireAdmin();if(!function_exists('openssl_encrypt')||!class_exists('finfo'))throw new RuleViolation('Servidor precisa das extensões OpenSSL e Fileinfo.');
        if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||empty($f['tmp_name'])||!is_uploaded_file($f['tmp_name'])||(int)($f['size']??0)>5*1024*1024)throw new RuleViolation('Envie PDF, JPG, PNG ou WebP de até 5 MB.');
        $type=wp_check_filetype_and_ext($f['tmp_name'],$f['name'],['pdf'=>'application/pdf','jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if(empty($type['ext'])||!in_array($mime,['application/pdf','image/jpeg','image/png','image/webp'],true)||$type['type']!==$mime)throw new RuleViolation('Formato do documento inválido.');
        $token=bin2hex(random_bytes(32));$payload=wp_json_encode(['owner'=>get_current_user_id(),'created'=>time(),'name'=>sanitize_file_name($f['name']),'mime'=>$mime,'data'=>base64_encode(file_get_contents($f['tmp_name']))]);$iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt($payload,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag);
        if($encrypted===false||file_put_contents($this->path($token),$iv.$tag.$encrypted,LOCK_EX)===false)throw new RuleViolation('Não foi possível guardar o documento.');return ['token'=>$token,'nome'=>sanitize_file_name($f['name'])];
    }
    public function read(string $token):array
    {
        Access::requireAdmin();$path=$this->path($token);$raw=is_file($path)?file_get_contents($path):false;if(!$raw||strlen($raw)<29)throw new RuleViolation('Documento não encontrado.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));$d=$plain===false?null:json_decode($plain,true);if(!is_array($d))throw new RuleViolation('Não foi possível ler o documento.');return $d;
    }
    public function validate(string $token):array {$d=$this->read($token);if((int)$d['owner']!==get_current_user_id()||time()-(int)$d['created']>7*DAY_IN_SECONDS)throw new RuleViolation('Envie novamente a declaração pela sua conta.');return ['documento_token'=>$token,'documento_nome'=>$d['name'],'documento_mime'=>$d['mime']];}
}
