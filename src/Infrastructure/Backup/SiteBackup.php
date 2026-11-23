<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Backup;

use RuntimeException;
use ZipArchive;

final class SiteBackup
{
    private string $root;
    private string $storage;

    public function __construct()
    {
        if(is_multisite())throw new RuntimeException('A recuperação do ERP exige uma instalação WordPress individual.');
        if(!class_exists(ZipArchive::class)||!method_exists(ZipArchive::class,'setEncryptionName'))throw new RuntimeException('Ative a extensão ZIP do PHP com suporte a AES-256.');
        $this->root=self::real(ABSPATH);
        $documentRoot=$_SERVER['DOCUMENT_ROOT']??'';
        if($documentRoot===''&&!defined('EDERP_BACKUP_DIR'))throw new RuntimeException('Configure EDERP_BACKUP_DIR no wp-config.php com um caminho privado fora da raiz pública da hospedagem.');
        $public=self::real($documentRoot!==''?$documentRoot:ABSPATH);
        $path=defined('EDERP_BACKUP_DIR')?EDERP_BACKUP_DIR:dirname($public).'/.erp-backups-'.substr(hash('sha256',$this->root),0,12);
        if(!is_string($path)||(!is_dir($path)&&!@mkdir($path,0700,true)))throw new RuntimeException('Configure EDERP_BACKUP_DIR no wp-config.php com uma pasta privada gravável fora do diretório público.');
        $this->storage=self::real($path);
        foreach([$public,$this->root,self::real(WP_CONTENT_DIR)] as $dir)
            if(self::inside($this->storage,$dir)||self::inside($dir,$this->storage))throw new RuntimeException('A pasta de backup deve ficar separada da instalação e fora da raiz pública.');
    }

    private static function real(string $path):string
    {
        $resolved=realpath($path);
        if($resolved===false)throw new RuntimeException('Diretório ou arquivo indisponível.');
        return rtrim(str_replace('\\','/',$resolved),'/');
    }

    private static function inside(string $path,string $dir):bool
    {
        if(PHP_OS_FAMILY==='Windows'){$path=strtolower($path);$dir=strtolower($dir);}
        return $path===$dir||str_starts_with($path,$dir.'/');
    }

    private static function relative(string $path):void
    {
        if($path===''||preg_match('~(?:^|/)\.{0,2}(?:/|$)|[\\\\:\x00-\x1f]~',$path))throw new RuntimeException('Caminho inválido no pacote de backup.');
    }

    private function path(string $id,string $extension):string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Backup inválido.');
        return $this->storage.'/'.$id.$extension;
    }

    public function history():array
    {
        $list=[];
        foreach(glob($this->storage.'/*.json')?:[] as $file){
            $row=json_decode((string)file_get_contents($file),true);
            if(is_array($row)&&isset($row['id'],$row['data_utc'])&&is_file($this->path($row['id'],'.zip')))$list[]=$row;
        }
        usort($list,static fn($a,$b)=>strcmp($b['data_utc'],$a['data_utc']));
        return $list;
    }

    public function archive(string $id):string
    {
        $file=$this->path($id,'.zip');
        if(!is_file($file))throw new RuntimeException('Arquivo de backup indisponível.');
        return $file;
    }

    private function work(string $label):string
    {
        $dir=$this->storage.'/'.$label.'-'.bin2hex(random_bytes(12));
        if(!mkdir($dir,0700))throw new RuntimeException('Não foi possível reservar espaço para o backup.');
        return $dir;
    }

    private static function cleanup(string $dir):void
    {
        if(!is_dir($dir))return;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){if($item->isDir()&&!$item->isLink())@rmdir($item->getPathname());else @unlink($item->getPathname());}
        @rmdir($dir);
    }

    private function sourceFiles(callable $add):array
    {
        $uploads=wp_upload_dir();
        $directories=[];
        foreach([WP_CONTENT_DIR,WP_PLUGIN_DIR,get_theme_root(),$uploads['basedir'],dirname(EDERP_FILE)] as $dir)
            if(!self::inside(self::real($dir),$this->root))throw new RuntimeException('Há arquivos do site fora da raiz do WordPress. Inclua essa instalação em um backup da hospedagem.');
        $config=is_file($this->root.'/wp-config.php')?$this->root.'/wp-config.php':dirname($this->root).'/wp-config.php';
        if(!is_file($config))throw new RuntimeException('wp-config.php não encontrado.');
        $add('config/wp-config.php',$config);
        $content=ltrim(substr(self::real(WP_CONTENT_DIR),strlen($this->root)),'/');
        $skip=['.git','wp-config.php','.maintenance',$content.'/cache',$content.'/upgrade',$content.'/upgrade-temp-backup',$content.'/ai1wm-backups'];
        $filter=new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),function($item)use($skip){
            $p=str_replace('\\','/',$item->getPathname());$rel=substr($p,strlen($this->root)+1);
            if($item->getFilename()==='.git'||in_array($rel,$skip,true))return false;
            if($item->isLink())throw new RuntimeException('Link simbólico exige backup pela hospedagem: '.$rel);
            return true;
        });
        foreach(new \RecursiveIteratorIterator($filter,\RecursiveIteratorIterator::SELF_FIRST) as $file){
            $p=str_replace('\\','/',$file->getPathname());
            if($file->isDir()){$directories[]='site/'.substr($p,strlen($this->root)+1);continue;}
            if(!$file->isFile())throw new RuntimeException('Arquivo especial na instalação do WordPress.');
            $add('site/'.substr($p,strlen($this->root)+1),$p);
        }
        return $directories;
    }

    private static function password(string $password):void
    {
        if(strlen($password)<12||strlen($password)>256||str_contains($password,"\0"))throw new RuntimeException('Use uma senha de backup de 12 a 256 caracteres.');
    }

    private static function mac(string $manifest):string
    {
        return hash_hmac('sha256',"ERP-BACKUP-1\n".$manifest,wp_salt('auth'));
    }

    private static function seal(ZipArchive $zip,string $key,string $password):void
    {
        if(!$zip->setEncryptionName($key,ZipArchive::EM_AES_256,$password))throw new RuntimeException('A biblioteca ZIP não conseguiu criptografar o arquivo.');
    }

    public function create(string $type,string $password):array
    {
        global $wpdb,$wp_version;
        self::password($password);
        if(!in_array($type,['banco','completo'],true))throw new RuntimeException('Tipo de backup inválido.');
        // Resolve any WordPress-generated authentication salts before snapshotting the options table.
        wp_salt('auth');
        $createdAt=gmdate('Y-m-d\TH:i:s\Z');
        $work=$this->work('criacao');$zip=new ZipArchive();$open=false;
        try{
            $file=$work.'/backup.tmp';
            if($zip->open($file,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Não foi possível criar o ZIP.');
            $open=true;$entries=[];
            $add=static function(string $entry,string $path)use($zip,$password,$work,&$entries){
                self::relative($entry);
                // libzip on Windows may reject deep paths which PHP itself can read.
                if(PHP_OS_FAMILY==='Windows'&&strlen($path)>=248){
                    $short=$work.'/source-'.count($entries);
                    if(!copy($path,$short))throw new RuntimeException('Falha ao preparar o arquivo '.$entry);
                    $path=$short;
                }
                $hash=hash_file('sha256',$path);$size=filesize($path);
                if($hash===false||$size===false||!$zip->addFile($path,$entry))throw new RuntimeException('Falha ao copiar '.$entry);
                self::seal($zip,$entry,$password);$entries[$entry]=[$size,$hash];
            };
            $db=SiteDatabase::connect(DB_NAME,DB_USER,DB_PASSWORD);
            try{$database=SiteDatabase::dump($db,$wpdb->prefix,$work,$add);}finally{$db->close();}
            $directories=$type==='completo'?$this->sourceFiles($add):[];
            $meta=['formato'=>1,'id'=>bin2hex(random_bytes(16)),'tipo'=>$type,'data_utc'=>$createdAt,'erp'=>EDERP_VERSION,'wordpress'=>$wp_version,'schema'=>$database['schema'],'site'=>home_url('/')];
            $manifest=json_encode(['backup'=>$meta,'banco'=>$database,'diretorios'=>$directories,'arquivos'=>$entries],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            foreach(['manifesto.json'=>$manifest,'manifesto.hmac'=>self::mac($manifest)] as $key=>$value){
                if(!$zip->addFromString($key,$value))throw new RuntimeException('Falha ao gravar o manifesto.');
                self::seal($zip,$key,$password);
            }
            $closed=$zip->close();$open=false;
            if(!$closed)throw new RuntimeException('Falha ao fechar o pacote. Confira o espaço livre.');
            $this->verify($file,$password);
            return $this->publish($file,$meta);
        }finally{if($open)@$zip->close();self::cleanup($work);}
    }

    private function publish(string $file,array $meta):array
    {
        $meta['bytes']=filesize($file);$id=$meta['id'];$archive=$this->path($id,'.zip');$index=$this->path($id,'.json');
        $lock=fopen($this->storage.'/catalogo.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Não foi possível atualizar o histórico.');
        try{
            if(is_file($archive)&&is_file($index)){
                if(!hash_equals(hash_file('sha256',$archive),hash_file('sha256',$file)))throw new RuntimeException('Já existe um pacote diferente com esse identificador.');
                return $meta;
            }
            $tmp=$index.'.'.bin2hex(random_bytes(4));
            if(file_put_contents($tmp,json_encode($meta,JSON_THROW_ON_ERROR))===false||!rename($file,$archive)||!rename($tmp,$index)){@unlink($tmp);throw new RuntimeException('Falha ao disponibilizar o arquivo de backup.');}
            return $meta;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private function open(string $file,string $password):ZipArchive
    {
        self::password($password);$zip=new ZipArchive();
        if($zip->open($file,ZipArchive::RDONLY)!==true||!$zip->setPassword($password))throw new RuntimeException('Não foi possível abrir o pacote de backup.');
        return $zip;
    }

    private function verify(string $file,string $password,?string $stage=null):array
    {
        $zip=$this->open($file,$password);
        try{
            $manifest=$zip->getFromName('manifesto.json',67108865);$signature=$zip->getFromName('manifesto.hmac',65);
            if(!is_string($manifest)||strlen($manifest)>67108864||!is_string($signature)||!hash_equals(self::mac($manifest),$signature))throw new RuntimeException('Senha incorreta, pacote danificado ou chave de origem diferente. Confira o backup e as chaves do wp-config.php.');
            $data=json_decode($manifest,true,512,JSON_THROW_ON_ERROR);$meta=$data['backup']??[];
            if(($meta['formato']??0)!==1||!in_array($meta['tipo']??'',['banco','completo'],true)||count($data['arquivos'])+2!==$zip->numFiles)throw new RuntimeException('Estrutura de backup incompatível.');
            foreach($data['diretorios'] as $directory){
                self::relative($directory);
                if(!str_starts_with($directory,'site/'))throw new RuntimeException('Diretório fora da árvore do site.');
                if($stage!==null&&!is_dir($stage.'/'.$directory)&&!mkdir($stage.'/'.$directory,0700,true))throw new RuntimeException('Falha ao restaurar um diretório do site.');
            }
            foreach($data['arquivos'] as $entry=>[$size,$digest]){
                self::relative($entry);
                $stat=$zip->statName($entry);
                if(!$stat||$stat['size']!==$size||$stat['encryption_method']!==ZipArchive::EM_AES_256)throw new RuntimeException('Entrada de backup divergente: '.$entry);
                $fp=$zip->getStream($entry);if(!$fp)throw new RuntimeException('Arquivo indisponível no pacote: '.$entry);
                $out=null;
                try{
                    if($stage!==null&&(str_starts_with($entry,'site/')||$entry==='config/wp-config.php')){
                        $target=$stage.'/'.$entry;
                        if(!is_dir(dirname($target))&&!mkdir(dirname($target),0700,true))throw new RuntimeException('Não foi possível criar a pasta recuperada.');
                        $out=fopen($target,'xb');if(!$out)throw new RuntimeException('Falha ao restaurar '.$entry);
                    }
                    $hash=hash_init('sha256');$total=0;
                    while(!feof($fp)){
                        $bytes=fread($fp,262144);if($bytes===false||($bytes===''&&!feof($fp)))throw new RuntimeException('Leitura incompleta: '.$entry);
                        hash_update($hash,$bytes);$total+=strlen($bytes);
                        if($out&&fwrite($out,$bytes)!==strlen($bytes))throw new RuntimeException('Escrita incompleta: '.$entry);
                    }
                    if($total!==$size||!hash_equals($digest,hash_final($hash)))throw new RuntimeException('Conteúdo divergente: '.$entry);
                }finally{fclose($fp);if(is_resource($out))fclose($out);}
            }
            return $data;
        }finally{$zip->close();}
    }

    public function import(string $file,string $password):array
    {
        $work=$this->work('importacao');
        try{
            $copy=$work.'/importado.zip';
            if(!copy($file,$copy))throw new RuntimeException('Não foi possível receber o backup.');
            $data=$this->verify($copy,$password);return $this->publish($copy,$data['backup']);
        }finally{self::cleanup($work);}
    }

    public function restore(string $id,string $password,string $name,string $user,string $dbPassword):array
    {
        if(trim($name)===''||strcasecmp($name,DB_NAME)===0)throw new RuntimeException('Informe um banco vazio diferente do banco em uso.');
        $file=$this->archive($id);$stage=$this->work('recuperacao');$ok=false;
        try{
            $data=$this->verify($file,$password,$stage);
            if($data['backup']['id']!==$id)throw new RuntimeException('Identificador divergente no pacote.');
            $db=SiteDatabase::connect($name,$user!==''?$user:DB_USER,$user!==''?$dbPassword:DB_PASSWORD);
            try{
                $zip=$this->open($file,$password);
                try{SiteDatabase::restore($db,$data['banco'],static function($entry)use($zip){$fp=$zip->getStream($entry);if(!$fp)throw new RuntimeException('Tabela indisponível no pacote.');return $fp;});}
                finally{$zip->close();}
            }finally{$db->close();}
            $ok=true;return ['backup'=>$data['backup'],'banco'=>$name,'prefixo'=>$data['banco']['prefixo'],'pasta'=>$data['backup']['tipo']==='completo'?$stage:null];
        }finally{if(!$ok||!is_dir($stage.'/site'))self::cleanup($stage);}
    }
}
