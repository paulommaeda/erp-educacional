<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Backup;

use RuntimeException;
use mysqli;

/** A dedicated connection avoids changing the transaction or session settings of WordPress. */
final class SiteDatabase
{
    public static function connect(string $name,string $user,string $password):mysqli
    {
        global $wpdb;
        $host=$wpdb->parse_db_host(DB_HOST);
        if(!$host)throw new RuntimeException('Endereço de banco inválido.');
        [$server,$port,$socket,$ipv6]=$host;
        if($ipv6&&extension_loaded('mysqlnd'))$server='['.$server.']';
        $db=mysqli_init();
        try{
            if(!$db->real_connect($server,$user,$password,$name,(int)$port,$socket,defined('MYSQL_CLIENT_FLAGS')?MYSQL_CLIENT_FLAGS:0))throw new RuntimeException();
            if(!$db->set_charset('utf8mb4'))throw new RuntimeException();
            self::run($db,"SET SESSION time_zone='+00:00', sql_quote_show_create=1");
            return $db;
        }catch(\Throwable $e){throw new RuntimeException('Não foi possível conectar ao banco escolhido. Confira nome, usuário e permissões.');}
    }

    private static function ident(string $name):string
    {
        if($name===''||str_contains($name,"\0"))throw new RuntimeException('Identificador inválido no banco.');
        return chr(96).str_replace(chr(96),chr(96).chr(96),$name).chr(96);
    }

    private static function run(mysqli $db,string $sql,int $mode=MYSQLI_STORE_RESULT)
    {
        $result=$db->query($sql,$mode);
        if($result===false)throw new RuntimeException('Operação no banco falhou ('.$db->errno.').');
        return $result;
    }

    private static function names(mysqli $db,string $prefix):array
    {
        $tables=[];
        foreach(self::run($db,'SHOW FULL TABLES')->fetch_all(MYSQLI_NUM) as [$name,$kind]){
            if(!str_starts_with($name,$prefix))continue;
            if($kind!=='BASE TABLE')throw new RuntimeException('A instalação contém uma view que requer backup pelo servidor: '.$name);
            $tables[]=$name;
        }
        sort($tables,SORT_STRING);
        return $tables;
    }

    public static function dump(mysqli $db,string $prefix,string $dir,callable $include):array
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$prefix))throw new RuntimeException('Prefixo WordPress não suportado.');
        $names=self::names($db,$prefix);
        foreach(['users','usermeta','options','erp_contratos'] as $required)if(!in_array($prefix.$required,$names,true))throw new RuntimeException('Tabela necessária ausente: '.$prefix.$required);
        foreach(self::run($db,'SHOW TRIGGERS')->fetch_all(MYSQLI_ASSOC) as $trigger)
            if(in_array($trigger['Table'],$names,true))throw new RuntimeException('A tabela '.$trigger['Table'].' possui um gatilho que requer backup pelo servidor.');
        $mixed=false;
        foreach(self::run($db,'SHOW TABLE STATUS')->fetch_all(MYSQLI_ASSOC) as $table)
            if(in_array($table['Name'],$names,true)&&strcasecmp($table['Engine'],'InnoDB')!==0)$mixed=true;
        $tables=array_map([self::class,'ident'],$names);
        if($mixed)self::run($db,'LOCK TABLES '.implode(' READ,',$tables).' READ');
        else{
            self::run($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::run($db,'START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        try{
            // Take metadata locks before the first data stream to also cover concurrent DDL.
            foreach($tables as $table)self::run($db,'SELECT 1 FROM '.$table.' LIMIT 0')->free();
            $catalog=[];$index=0;
            foreach($names as $name){
                $quoted=self::ident($name);
                $ddl=self::run($db,'SHOW CREATE TABLE '.$quoted)->fetch_row()[1];
                if(preg_match('/\b(?:DATA DIRECTORY|INDEX DIRECTORY|TABLESPACE|CONNECTION)\s*[= ]/i',$ddl))throw new RuntimeException('Armazenamento externo requer backup pelo servidor: '.$name);
                $columns=[];
                foreach(self::run($db,'SHOW FULL COLUMNS FROM '.$quoted)->fetch_all(MYSQLI_ASSOC) as $col){
                    if(!preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i',$col['Extra']))$columns[]=$col['Field'];
                }
                $streamName='db/'.(++$index).'.ndjson';$file=$dir.'/table-'.$index;
                $fp=fopen($file,'xb');if(!$fp)throw new RuntimeException('Sem espaço para a cópia do banco.');
                $rows=self::run($db,'SELECT '.implode(',',array_map([self::class,'ident'],$columns)).' FROM '.$quoted,MYSQLI_USE_RESULT);
                $count=0;
                try{
                    while($row=$rows->fetch_row()){
                        $line=json_encode(array_map(static fn($v)=>$v===null?null:base64_encode((string)$v),$row),JSON_THROW_ON_ERROR)."\n";
                        if(fwrite($fp,$line)!==strlen($line))throw new RuntimeException('Escrita incompleta durante o backup.');
                        $count++;
                    }
                    if($db->errno)throw new RuntimeException('Leitura interrompida na tabela '.$name);
                }finally{$rows->free();fclose($fp);}
                $include($streamName,$file);
                $catalog[]=['nome'=>$name,'ddl'=>$ddl,'colunas'=>$columns,'dados'=>$streamName,'linhas'=>$count];
            }
            $refs=self::foreignKeys($db,$names);
            $options=self::ident($prefix.'options');
            $schema=self::run($db,"SELECT option_value FROM $options WHERE option_name='ederp_schema_version'")->fetch_row()[0]??'';
            if($schema===''||!ctype_digit((string)$schema))throw new RuntimeException('A versão do esquema do ERP está ausente.');
            return ['prefixo'=>$prefix,'schema'=>(string)$schema,'tabelas'=>$catalog,'vinculos'=>count($refs)];
        }finally{self::run($db,$mixed?'UNLOCK TABLES':'ROLLBACK');}
    }

    private static function foreignKeys(mysqli $db,array $names):array
    {
        $sql='SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,DATABASE() AS origem FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION';
        $keys=[];$allowed=array_fill_keys($names,true);
        foreach(self::run($db,$sql)->fetch_all(MYSQLI_ASSOC) as $r){
            if(!isset($allowed[$r['TABLE_NAME']]))continue;
            if($r['REFERENCED_TABLE_SCHEMA']!==$r['origem']||!isset($allowed[$r['REFERENCED_TABLE_NAME']]))throw new RuntimeException('Vínculo com uma tabela fora do backup: '.$r['TABLE_NAME']);
            $keys[$r['TABLE_NAME']."\0".$r['CONSTRAINT_NAME']][]=$r;
        }
        return $keys;
    }

    public static function restore(mysqli $db,array $metadata,callable $stream):void
    {
        $name=self::run($db,'SELECT DATABASE()')->fetch_row()[0];
        if(strcasecmp((string)$name,DB_NAME)===0)throw new RuntimeException('Escolha um banco de destino diferente do banco em uso.');
        $lock='erp_backup_'.substr(hash('sha256',$name),0,40);
        if(self::run($db,"SELECT GET_LOCK('$lock',0)")->fetch_row()[0]!='1')throw new RuntimeException('Outra restauração usa este banco.');
        try{
            if(self::run($db,'SHOW FULL TABLES')->num_rows)throw new RuntimeException('O banco de destino precisa estar vazio. Nenhuma tabela foi substituída.');
            self::run($db,"SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
            self::run($db,'SET SESSION foreign_key_checks=0');
            foreach($metadata['tabelas'] as $t){
                if(!str_starts_with($t['nome'],$metadata['prefixo'])||!str_starts_with($t['ddl'],'CREATE TABLE '.self::ident($t['nome']).' ('))throw new RuntimeException('Definição de tabela inválida.');
                self::run($db,$t['ddl']);
            }
            foreach($metadata['tabelas'] as $t){
                $table=self::ident($t['nome']);$cols=$t['colunas'];
                $stmt=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_map([self::class,'ident'],$cols)).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');
                if(!$stmt)throw new RuntimeException('Não foi possível preparar a tabela '.$t['nome']);
                $fp=$stream($t['dados']);$count=0;
                self::run($db,'START TRANSACTION');
                try{
                    while(($line=fgets($fp))!==false){
                        $row=json_decode($line,true,512,JSON_THROW_ON_ERROR);
                        if(!is_array($row)||count($row)!==count($cols))throw new RuntimeException('Linha inválida na tabela '.$t['nome']);
                        foreach($row as &$v){if($v!==null&&($v=base64_decode($v,true))===false)throw new RuntimeException('Valor inválido na tabela '.$t['nome']);}unset($v);
                        if(!$stmt->bind_param(str_repeat('s',count($cols)),...$row)||!$stmt->execute()||$db->warning_count)throw new RuntimeException('Valor rejeitado na tabela '.$t['nome']);
                        $count++;
                    }
                    if(!feof($fp)||$count!==$t['linhas']||(int)self::run($db,'SELECT COUNT(*) FROM '.$table)->fetch_row()[0]!==$count)throw new RuntimeException('Contagem divergente na tabela '.$t['nome']);
                    self::run($db,'COMMIT');
                }catch(\Throwable $e){self::run($db,'ROLLBACK');throw $e;}
                finally{fclose($fp);$stmt->close();}
            }
            self::run($db,'SET SESSION foreign_key_checks=1');
            $keys=self::foreignKeys($db,array_column($metadata['tabelas'],'nome'));
            if(count($keys)!==$metadata['vinculos'])throw new RuntimeException('O número de chaves estrangeiras diverge do backup.');
            foreach($keys as $parts){
                $r=$parts[0];$join=[];$present=[];
                foreach($parts as $k){
                    $child='c.'.self::ident($k['COLUMN_NAME']);$parent='p.'.self::ident($k['REFERENCED_COLUMN_NAME']);
                    $join[]=$child.'='.$parent;$present[]=$child.' IS NOT NULL';
                }
                $sql='SELECT 1 FROM '.self::ident($r['TABLE_NAME']).' c LEFT JOIN '.self::ident($r['REFERENCED_TABLE_NAME']).' p ON '.implode(' AND ',$join).' WHERE '.implode(' AND ',$present).' AND p.'.self::ident($r['REFERENCED_COLUMN_NAME']).' IS NULL LIMIT 1';
                if(self::run($db,$sql)->num_rows)throw new RuntimeException('Vínculo pendente na tabela '.$r['TABLE_NAME'].' ('.$r['CONSTRAINT_NAME'].').');
            }
        }finally{self::run($db,"SELECT RELEASE_LOCK('$lock')");}
    }
}
