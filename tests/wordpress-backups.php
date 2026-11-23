<?php
/** Integration test with a disposable WordPress copy and local MySQL databases. */
declare(strict_types=1);
use EducacionalERP\Infrastructure\Backup\{SiteBackup,SiteDatabase};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Presentation\Admin\Backups;

if(PHP_SAPI!=='cli'||!getenv('EDERP_BACKUP_TEST_ROOT')||!getenv('EDERP_TEST_BOOTSTRAP'))exit(2);
$_SERVER['HTTP_HOST']='erp-test.local';$_SERVER['REQUEST_URI']='/';
require getenv('EDERP_TEST_BOOTSTRAP');
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true||realpath(ABSPATH)!==realpath(getenv('EDERP_BACKUP_TEST_ROOT')))throw new RuntimeException('Use uma instalação descartável e um banco de testes.');
require_once dirname(__DIR__).'/erp-educacional.php';
require __DIR__.'/wordpress-migration-fixtures.php';
function backupCheck(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException($label);
    echo "PASS $label\n";
}
function backupReject(callable $operation,string $part):void{
    try{$operation();}catch(Throwable $e){backupCheck(str_contains($e->getMessage(),$part),$part.' / '.$e->getMessage());return;}
    throw new RuntimeException('Esperava recusa: '.$part);
}

$_SERVER['DOCUMENT_ROOT']=ABSPATH;
$tag=bin2hex(random_bytes(5));$p='bk_'.$tag.'_';$original=$wpdb->prefix;
$names=[$p.'full',$p.'data',$p.'invalid'];$connection=SiteDatabase::connect(DB_NAME,DB_USER,DB_PASSWORD);
$password=bin2hex(random_bytes(16));$media=wp_upload_dir()['basedir'].'/backup-'.$tag;
mkdir($media.'/vazio',0700,true);file_put_contents($media.'/termo.txt','arquivo original');
$deep=$media.'/'.str_repeat('d',65).'/'.str_repeat('e',65).'/'.str_repeat('f',65);
    mkdir($deep,0700,true);file_put_contents($deep.'/amostra.txt','caminho longo');
$uploads=substr(str_replace('\\','/',$media),strlen(str_replace('\\','/',rtrim(ABSPATH,'/\\')))+1);
try{
    foreach($names as $name)$connection->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    foreach($wpdb->tables('all',false) as $suffix){
        $connection->query('CREATE TABLE '.$p.$suffix.' LIKE '.$original.$suffix);
        $connection->query('INSERT INTO '.$p.$suffix.' SELECT * FROM '.$original.$suffix);
    }
    $wpdb->prefix=$p;
    fixtureM((new Database($wpdb))->schema());
    $options=$p.'options';$contracts=$p.'erp_contratos';
    $connection->query("REPLACE INTO $options (option_name,option_value,autoload) VALUES ('ederp_schema_version','50','yes')");
    $connection->query("CREATE TABLE {$p}blobs (id INT AUTO_INCREMENT PRIMARY KEY, payload BLOB, nullable TEXT, size INT GENERATED ALWAYS AS (OCTET_LENGTH(payload)) STORED) ENGINE=InnoDB");
    $connection->query("INSERT INTO {$p}blobs(payload) VALUES (0x00ff01)");
    $connection->query("ALTER TABLE {$p}blobs AUTO_INCREMENT=25");
    $service=new SiteBackup();
    $full=$service->create('completo',$password);
    backupCheck($full['erp']===EDERP_VERSION&&$full['schema']==='50'&&isset($full['wordpress'])&&strtotime($full['data_utc'])>0,'metadados de data, ERP, WordPress e esquema');
    file_put_contents($media.'/termo.txt','arquivo posterior');
    $connection->query("UPDATE $contracts SET termo_snapshot='TERMO POSTERIOR'");
    $connection->query("CREATE TABLE {$p}legado (id INT PRIMARY KEY) ENGINE=MyISAM");
    $connection->query("INSERT INTO {$p}legado VALUES (17)");
    $dbOnly=$service->create('banco',$password);
    backupCheck(count(array_intersect([$full['id'],$dbOnly['id']],array_column($service->history(),'id')))===2,'duas versões no histórico');
    $zipDb=$service->archive($dbOnly['id']);
    backupReject(fn()=>$service->import($zipDb,'senha-incorreta-teste'),'Senha incorreta');
    $damaged=tempnam(sys_get_temp_dir(),'bk-test-');
    try{
        copy($zipDb,$damaged);$zip=new ZipArchive();$zip->open($damaged);$zip->setPassword($password);
        $zip->addFromString('db/1.ndjson','different');$zip->setEncryptionName('db/1.ndjson',ZipArchive::EM_AES_256,$password);$zip->close();
        backupReject(fn()=>$service->import($damaged,$password),'divergente');
    }finally{unlink($damaged);}
    backupCheck($service->import($zipDb,$password)['id']===$dbOnly['id'],'importação idempotente');
    backupReject(fn()=>$service->restore($dbOnly['id'],$password,DB_NAME,'',''),'banco vazio diferente');
    $restored=$service->restore($full['id'],$password,$names[0],'','');
    $site=$restored['pasta'].'/site';$target=SiteDatabase::connect($names[0],DB_USER,DB_PASSWORD);
    backupCheck(file_get_contents($site.'/'.$uploads.'/termo.txt')==='arquivo original'&&is_dir($site.'/'.$uploads.'/vazio'),'arquivos e diretório vazio da versão anterior');
    backupCheck(file_get_contents($site.'/'.$uploads.substr($deep,strlen($media)).'/amostra.txt')==='caminho longo','arquivo com caminho longo recuperado');
    backupCheck(is_file($restored['pasta'].'/config/wp-config.php')&&!is_file($site.'/wp-config.php'),'configuração original separada do site recuperado');
    backupCheck(hash_file('sha256',$site.'/wp-content/plugins/erp-educacional/erp-educacional.php')===hash_file('sha256',EDERP_FILE),'código da versão no pacote completo');
    backupCheck($target->query("SELECT termo_snapshot FROM $contracts")->fetch_row()[0]==='TERMO ORIGINAL DO BACKUP','termo histórico recuperado');
    backupCheck($target->query("SELECT valor_baixa,saldo_aberto FROM {$p}erp_lancamentos")->fetch_row()===['100.00','900.00'],'baixa e saldo da parcela recuperados');
    backupCheck($target->query("SELECT HEX(payload),nullable,size FROM {$p}blobs")->fetch_row()===['00FF01',null,'3'],'dado binário, NULL e coluna calculada');
    $target->query("INSERT INTO {$p}blobs(payload) VALUES (NULL)");
    backupCheck($target->insert_id===25,'próximo identificador da origem preservado');$target->close();
    backupReject(fn()=>$service->restore($dbOnly['id'],$password,$names[0],'',''),'precisa estar vazio');
    $r=$service->restore($dbOnly['id'],$password,$names[1],'','');
    $target=SiteDatabase::connect($names[1],DB_USER,DB_PASSWORD);
    backupCheck($r['pasta']===null&&$target->query("SELECT id FROM {$p}legado")->fetch_row()[0]==='17'&&$target->query("SELECT termo_snapshot FROM $contracts")->fetch_row()[0]==='TERMO POSTERIOR','backup somente banco restaura a segunda versão');$target->close();
    $connection->query('SET foreign_key_checks=0');$connection->query("UPDATE $contracts SET idmatricula=9999");$connection->query('SET foreign_key_checks=1');
    $invalid=$service->create('banco',$password);
    backupReject(fn()=>$service->restore($invalid['id'],$password,$names[2],'',''),'Vínculo pendente');
    backupCheck($connection->query("SELECT termo_snapshot FROM $contracts")->fetch_row()[0]==='TERMO POSTERIOR'&&file_get_contents($media.'/termo.txt')==='arquivo posterior','banco e arquivos de origem preservados');
    $wpdb->prefix=$original;
    require_once ABSPATH.'wp-admin/includes/template.php';
    wp_set_current_user(get_users(['role'=>'administrator','number'=>1])[0]->ID);
    ob_start();Backups::screen();$screen=ob_get_clean();
    backupCheck(str_contains($screen,'Somente banco de dados')&&str_contains($screen,'Baixar ZIP')&&str_contains($screen,'Restaurar e conferir no destino'),'fluxo administrativo do WordPress');
}finally{
    $wpdb->prefix=$original;
    $connection->query('SET foreign_key_checks=0');
    $tables=$connection->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
    foreach($tables as [$table])if(str_starts_with($table,$p))$connection->query('DROP TABLE '.$table);
    foreach($names as $name)$connection->query('DROP DATABASE IF EXISTS '.$name);
    $connection->close();
}
