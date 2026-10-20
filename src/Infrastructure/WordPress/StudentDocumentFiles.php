<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\{StudentFiles,RuleViolation};
/** Authorization belongs to StudentDocuments; files on disk are encrypted and have no public media URL. */
class StudentDocumentFiles implements StudentFiles
{
    public const LIMIT=5242880;
    protected function uploaded(string $path):bool {return is_uploaded_file($path);}
    private function path(string $token):string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuleViolation('Documento inválido.');
        $upload=wp_upload_dir();if(!empty($upload['error']))throw new RuleViolation('Diretório de documentos indisponível.');
        $dir=$upload['basedir'].'/erp-documentos-alunos';if(!wp_mkdir_p($dir))throw new RuleViolation('Não foi possível preparar o diretório de documentos.');
        return $dir.'/'.$token.'.bin';
    }
    private function key():string {return hash('sha256',wp_salt('auth').'|erp-student-documents',true);}
    public function put(array $file):array
    {
        if(!function_exists('openssl_encrypt')||!class_exists('finfo'))throw new RuleViolation('Servidor precisa das extensões OpenSSL e Fileinfo.');
        $tmp=(string)($file['tmp_name']??'');$size=$tmp&&is_file($tmp)?filesize($tmp):0;
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!$this->uploaded($tmp)||!$size||$size>self::LIMIT)throw new RuleViolation('Envie PDF, JPG, PNG ou WebP de até 5 MB.');
        $name=sanitize_file_name((string)($file['name']??''));$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$allowed=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        if(!$name||strlen($name)>191||!isset($allowed[$ext])||$allowed[$ext]!==$mime)throw new RuleViolation('O conteúdo do arquivo deve ser PDF, JPG, PNG ou WebP e corresponder à extensão.');
        $bytes=file_get_contents($tmp,false,null,0,self::LIMIT+1);if($bytes===false||strlen($bytes)!==$size)throw new RuleViolation('Não foi possível ler o arquivo enviado.');
        if($mime!=='application/pdf'&&!getimagesize($tmp))throw new RuleViolation('Imagem inválida.');
        $token=bin2hex(random_bytes(32));$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($bytes,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new RuleViolation('Não foi possível proteger o documento.');
        $path=$this->path($token);$stored=$iv.$tag.$cipher;
        if(file_put_contents($path,$stored,LOCK_EX)!==strlen($stored)){@unlink($path);throw new RuleViolation('Não foi possível guardar o documento.');}
        @chmod($path,0600);
        return ['arquivo_token'=>$token,'arquivo_nome'=>$name,'arquivo_mime'=>$mime,'tamanho'=>$size,'sha256'=>hash('sha256',$bytes)];
    }
    public function read(string $token):string
    {
        $path=$this->path($token);$raw=is_file($path)?file_get_contents($path):false;
        if(!$raw||strlen($raw)<29)throw new RuleViolation('Arquivo não encontrado. Procure a secretaria.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        if($plain===false)throw new RuleViolation('Não foi possível verificar a integridade do documento.');return $plain;
    }
    public function delete(string $token):bool {$path=$this->path($token);return !is_file($path)||@unlink($path);}
}
