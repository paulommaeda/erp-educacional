<?php
/** Test-only wpdb adapter. Exercises services with real SQLite transactions/FKs.
 * Does NOT validate dbDelta, MySQL locking, MySQL DDL, WordPress authentication or hooks.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli') { exit; }
define('EDERP_TEST_DATABASE',true);
define('EDERP_SQLITE_TEST',true);
define('ARRAY_A','ARRAY_A');
final class wpdb
{
    public string $prefix='test_';
    public string $last_error='';
    public int $insert_id=0;
    public PDO $pdo;
    public function __construct() { $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_STRINGIFY_FETCHES=>true]); $this->pdo->exec('PRAGMA foreign_keys=ON'); }
    public function prepare(string $sql,mixed ...$args): string
    {
        $i=0;return preg_replace_callback('/%[ds]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:$this->pdo->quote((string)$v);},$sql);
    }
    private function translate(string $sql): string
    {
        if(str_contains($sql,'GET_LOCK('))return 'SELECT 1 AS acquired';
        if(str_contains($sql,'RELEASE_LOCK('))return 'SELECT 1 AS released';
        $sql=str_replace([' FOR UPDATE','UTC_TIMESTAMP()'],['',"datetime('now')"],$sql);
        $sql=str_replace('ON DUPLICATE KEY UPDATE chave=chave','ON CONFLICT(chave) DO NOTHING',$sql);
        if(str_starts_with($sql,'SET TRANSACTION'))return 'SELECT 1';
        if(str_starts_with($sql,'START TRANSACTION'))return 'BEGIN';
        return $sql;
    }
    public function query(string $sql): int|false
    {
        $this->last_error='';try{return $this->pdo->exec($this->translate($sql));}catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    public function get_results(string $sql,string $mode): array|false
    {
        $this->last_error='';try{return $this->pdo->query($this->translate($sql))->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    public function insert(string $table,array $data): int|false
    {
        $cols=implode(',',array_keys($data));$values=implode(',',array_fill(0,count($data),'?'));
        $this->last_error='';try{$s=$this->pdo->prepare("INSERT INTO $table ($cols) VALUES ($values)");$s->execute(array_values($data));$this->insert_id=(int)$this->pdo->lastInsertId();return $s->rowCount();}catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    public function update(string $table,array $data,array $where): int|false
    {
        $set=implode(',',array_map(fn($c)=>"$c=?",array_keys($data)));$filter=implode(' AND ',array_map(fn($c)=>"$c=?",array_keys($where)));
        $this->last_error='';try{$s=$this->pdo->prepare("UPDATE $table SET $set WHERE $filter");$s->execute(array_merge(array_values($data),array_values($where)));return $s->rowCount();}catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    public function suppress_errors(bool $v): bool { return false; }
    public function esc_like(string $s): string { return addcslashes($s,'_%\\'); }
}
$GLOBALS['test_user']=1;
$GLOBALS['test_options']=[];
function wp_json_encode(mixed $v): string {return json_encode($v,JSON_THROW_ON_ERROR);}
function wp_date(string $f): string {return date($f);}
function wp_timezone(): DateTimeZone {return new DateTimeZone('UTC');}
function get_current_user_id(): int {return $GLOBALS['test_user'];}
function wp_set_current_user(int $id): void {$GLOBALS['test_user']=$id;}
function get_users(array $args): array {return [(object)['ID'=>1]];}
function is_user_logged_in(): bool {return get_current_user_id()>0;}
function current_user_can(string $cap,...$args): bool {if(get_current_user_id()===1)return true;$u=get_userdata(get_current_user_id());if(!$u)return false;foreach($u->roles as $r)if(!empty($GLOBALS['test_roles'][$r]['capabilities'][$cap]))return true;return false;}
function remove_accents(string $s):string{return strtr($s,['á'=>'a','ã'=>'a','â'=>'a','à'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c','Ã'=>'A','Â'=>'A','À'=>'A','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Á'=>'A','É'=>'E','Ç'=>'C']);}
function clean_user_cache(int $id):void{}
class WP_User {
 public int $ID;public string $user_login;public string $user_pass;public string $display_name;public string $user_email;public array $roles;
 function __construct(array $row){$this->ID=(int)$row['ID'];$this->user_pass=$row['user_pass']??'';$this->display_name=$row['display_name']??$row['user_login'];$this->user_email=$row['user_email']??'';$this->user_login=$row['user_login'];$this->roles=json_decode($row['roles'],true);}
 function add_role(string $r):void{if(!in_array($r,$this->roles,true))$this->roles[]=$r;$this->save();}
 function remove_role(string $r):void{$this->roles=array_values(array_diff($this->roles,[$r]));$this->save();}
 private function save():void{global $wpdb;$wpdb->update('mock_users',['roles'=>json_encode($this->roles)],['ID'=>$this->ID]);}
}
function get_userdata(int $id):WP_User|false{global $wpdb;$s=$wpdb->pdo->prepare('SELECT * FROM mock_users WHERE ID=?');$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?new WP_User($r):false;}
function wp_get_current_user():object{return get_userdata(get_current_user_id())?: (object)['roles'=>[]];}
function get_user_by(string $k,mixed $v):WP_User|false{return $k==='id'?get_userdata((int)$v):false;}
function username_exists(string $v):int|false{global $wpdb;$s=$wpdb->pdo->prepare('SELECT ID FROM mock_users WHERE user_login=? COLLATE NOCASE');$s->execute([$v]);return ($id=$s->fetchColumn())?(int)$id:false;}
function email_exists(string $v):int|false{global $wpdb;$s=$wpdb->pdo->prepare('SELECT ID FROM mock_users WHERE user_email=? COLLATE NOCASE');$s->execute([$v]);return ($id=$s->fetchColumn())?(int)$id:false;}
function wp_insert_user(array $a):int|WP_Error{global $wpdb;if(empty($a['user_login']))return new WP_Error('empty_user_login','Cannot create a user with an empty login name.');$d=array_intersect_key($a,array_flip(['user_login','user_email','user_pass','display_name','first_name','last_name']));if(isset($a['role']))$d['roles']=json_encode([$a['role']]);if(isset($a['ID'])){$wpdb->update('mock_users',$d,['ID'=>$a['ID']]);return (int)$a['ID'];}$wpdb->insert('mock_users',$d);return $wpdb->insert_id;}
class WP_Error {public function __construct(private string $code,private string $message){} public function get_error_code():string{return $this->code;}public function get_error_message():string{return $this->message;}}
function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}
function wp_update_user(array $a):int|WP_Error{global $wpdb;
 if(isset($GLOBALS['test_update_error']))return $GLOBALS['test_update_error'];
 $s=$wpdb->pdo->prepare('SELECT * FROM mock_users WHERE ID=?');$s->execute([$a['ID']??0]);$old=$s->fetch(PDO::FETCH_ASSOC);
 if(!$old)return new WP_Error('invalid_user_id','Invalid user ID.');
 return wp_insert_user(array_merge($old,$a));
}
$GLOBALS['test_roles']=['administrator'=>['name'=>'Administrador','capabilities'=>['read'=>true,'manage_options'=>true]],'subscriber'=>['name'=>'Assinante','capabilities'=>['read'=>true]]];
class TestRole {public function __construct(private string $role){}public function add_cap(string $c):void{$GLOBALS['test_roles'][$this->role]['capabilities'][$c]=true;}public function remove_cap(string $c):void{unset($GLOBALS['test_roles'][$this->role]['capabilities'][$c]);}}
function add_role(string $s,string $name,array $caps=[]):?TestRole{if(isset($GLOBALS['test_roles'][$s]))return null;$GLOBALS['test_roles'][$s]=['name'=>$name,'capabilities'=>$caps];return new TestRole($s);}
function wp_roles():object{return (object)['roles'=>$GLOBALS['test_roles']];}
function sanitize_title(string $s):string{return strtolower(str_replace(' ','-',remove_accents($s)));}
function get_role(string $s):TestRole|false{return isset($GLOBALS['test_roles'][$s])?new TestRole($s):false;}
function sanitize_email(string $s): string {return $s;}
function is_email(string $s): bool {return (bool)filter_var($s,FILTER_VALIDATE_EMAIL);}
function update_option(string $k,mixed $v,...$a):void{$GLOBALS['test_options'][$k]=$v;}
function get_option(string $k,mixed $default=false):mixed{return $GLOBALS['test_options'][$k]??$default;}
function delete_option(string $k):void{unset($GLOBALS['test_options'][$k]);}
$wpdb=new wpdb();
$wpdb->pdo->exec("CREATE TABLE mock_users (ID INTEGER PRIMARY KEY AUTOINCREMENT,user_login TEXT UNIQUE,user_email TEXT DEFAULT '',user_pass TEXT,display_name TEXT,first_name TEXT,last_name TEXT,roles TEXT DEFAULT '[]')");
wp_insert_user(['user_login'=>'admin','role'=>'administrator']);
$schema=json_decode(file_get_contents(dirname(__DIR__).'/src/Infrastructure/Database/schema.json'),true);
foreach($schema as $name=>$meta){
    $lines=[];
    foreach($meta['columns'] as $c=>$type){
        if(str_contains($type,'AUTO_INCREMENT')){$lines[]="$c INTEGER PRIMARY KEY AUTOINCREMENT";continue;}
        // TEXT preserves monetary decimal strings like MySQL DECIMAL through wpdb.
        $type=preg_replace('/^(?:bigint\(20\)|int|tinyint\(1\)|tinyint)(?: unsigned)?/','INTEGER',$type);
        $type=preg_replace('/^decimal\([^)]*\)/','TEXT',$type);
        $type=preg_replace('/^varchar\([^)]*\)|^(?:longtext|datetime|date|time|text)/','TEXT',$type);
        $lines[]="$c $type";
    }
    if(count($meta['pk'])>1 || !str_contains($meta['columns'][$meta['pk'][0]],'AUTO_INCREMENT'))$lines[]='PRIMARY KEY ('.implode(',',$meta['pk']).')';
    foreach($meta['unique'] as $u)$lines[]='UNIQUE ('.implode(',',$u).')';
    foreach($meta['fks'] as $fk)$lines[]='FOREIGN KEY ('.implode(',',$fk['columns']).') REFERENCES test_erp_'.$fk['target'].' ('.implode(',',$fk['references']).') ON DELETE RESTRICT ON UPDATE RESTRICT';
    $wpdb->pdo->exec('CREATE TABLE test_erp_'.$name.' ('.implode(',',$lines).')');
}
function wp_attachment_is_image(int $id):bool{return $id===10;}
function wp_get_attachment_image_url(int $id,string $size):string|false{return $id===10?'https://test.example/foto.jpg':false;}

$wpdb->insert($wpdb->prefix."erp_numeracao_pessoas",["idnumeracao"=>1,"ultimo_codigo"=>0]);

$wpdb->insert($wpdb->prefix.'erp_coligadas',['codcoligada'=>1,'nome'=>'COLIGADA PRINCIPAL','razao_social'=>'COLIGADA PRINCIPAL']);
$GLOBALS['test_user_meta']=[];
function get_user_meta($id,$key,$single=true){return $GLOBALS['test_user_meta'][$id][$key]??'';}
function update_user_meta($id,$key,$value){$GLOBALS['test_user_meta'][$id][$key]=$value;}
