<?php
/** Real dbDelta/FK/rollback test. Disposable WordPress/MySQL only, with EDERP_TEST_DATABASE=true. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');if(!$bootstrap||!is_file($bootstrap)){fwrite(STDERR,"Defina EDERP_TEST_BOOTSTRAP para WordPress descartável.\n");exit(2);}
require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true||defined('EDERP_SQLITE_TEST'))throw new RuntimeException('Exige banco MySQL descartável autorizado.');
require_once dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\{Database,Installer,SchemaIndexes};
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};
use EducacionalERP\Application\{RecoveredChatAttachments,ChatService};
use EducacionalERP\Presentation\Admin\ChatRecovery;
use EducacionalERP\Domain\RuleViolation;
$admin=get_users(['role'=>'administrator','number'=>1])[0]??null;if(!$admin)throw new RuntimeException('Administrador ausente.');
wp_set_current_user($admin->ID);Access::install();$checks=0;$savedPrefix=$wpdb->prefix;$savedOptions=[];$savedUser=get_current_user_id();
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_attempt_release'] as $key)$savedOptions[$key]=get_option($key,null);
$old=json_decode(file_get_contents(__DIR__.'/fixtures/schema-36.json'),true,512,JSON_THROW_ON_ERROR);$previous=$wpdb->suppress_errors(true);
function checkCR(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
function deniedCR(callable $work,string $label):void{try{$work();}catch(RuleViolation $e){checkCR(true,$label);return;}throw new RuntimeException($label);}
function snapshotCR(Database $db,array $schema):array{$out=[];foreach($schema as $name=>$meta)$out[$name]=$db->rows('SELECT '.implode(',',array_keys($meta['columns'])).' FROM '.$db->table($name).' ORDER BY '.implode(',',$meta['pk']));return $out;}
try{
    $wpdb->prefix='cr_'.bin2hex(random_bytes(5)).'_';$db=new Database($wpdb);
    foreach($old as $name=>$meta){$lines=[];foreach($meta['columns'] as $col=>$def)$lines[]="$col $def";$lines[]='PRIMARY KEY ('.implode(',',$meta['pk']).')';$lines=array_merge($lines,SchemaIndexes::lines($meta,[]));$db->query('CREATE TABLE '.$db->table($name).' ('.implode(',',$lines).') ENGINE=InnoDB '.$wpdb->get_charset_collate());}
    foreach($old as $name=>$meta)foreach($meta['fks'] as $i=>$fk){if($name==='chat_anexos'&&$fk['target']==='chat_mensagens')continue;$table=$db->table($name);$db->query("ALTER TABLE $table ADD CONSTRAINT erp_fk_".substr(hash('sha256',$table.':'.$i),0,32).' FOREIGN KEY ('.implode(',',$fk['columns']).') REFERENCES '.$db->table($fk['target']).' ('.implode(',',$fk['references']).') ON DELETE RESTRICT ON UPDATE RESTRICT');}
    $fixtures=[
        'coligadas'=>[['codcoligada'=>1,'nome'=>'ESCOLA A','razao_social'=>'ESCOLA A'],['codcoligada'=>2,'nome'=>'ESCOLA B','razao_social'=>'ESCOLA B']],
        'pessoas'=>[['codpessoa'=>1,'nome'=>'RESPONSÁVEL FICTÍCIO']],
        'chat_canais'=>[['idcanal'=>1,'codcoligada'=>1,'nome'=>'Secretaria A'],['idcanal'=>2,'codcoligada'=>2,'nome'=>'Secretaria B']],
        'chat_conversas'=>[['idconversa'=>1,'codcoligada'=>1,'idcanal'=>1,'codpessoa'=>1,'ultima_mensagem_id'=>1],['idconversa'=>2,'codcoligada'=>2,'idcanal'=>2,'codpessoa'=>1,'ultima_mensagem_id'=>2]],
        'chat_mensagens'=>[['idmensagem'=>1,'codcoligada'=>1,'idconversa'=>1,'autor_wp_user_id'=>$admin->ID,'autor_nome'=>'Responsável','origem'=>'responsavel','texto'=>'MENSAGEM DA ESCOLA A'],['idmensagem'=>2,'codcoligada'=>2,'idconversa'=>2,'autor_wp_user_id'=>$admin->ID,'autor_nome'=>'Responsável','origem'=>'responsavel','texto'=>'MENSAGEM DA ESCOLA B']],
    ];
    foreach($fixtures as $name=>$rows)foreach($rows as $row)if($wpdb->insert($db->table($name),$row)===false)throw new RuntimeException('Fixture inválida: '.$wpdb->last_error);
    foreach([[1,1,1,'válido.txt','arquivo válido'],[2,999,1,'ausente.txt','arquivo sem mensagem'],[3,2,1,'coligada.txt','arquivo com referência de outra coligada'],[4,1001,2,'<arquivo>.txt','arquivo da escola B']] as [$id,$message,$company,$name,$bytes]){
        if($wpdb->insert($db->table('chat_anexos'),['idanexo'=>$id,'idmensagem'=>$message,'codcoligada'=>$company,'nome'=>$name,'mime'=>'text/plain','tamanho'=>strlen($bytes),'conteudo_base64'=>base64_encode($bytes),'criado_em'=>'2025-05-01 10:00:00'])===false)throw new RuntimeException('Anexo de teste inválido.');
    }
    $before=snapshotCR($db,$old);$audit=$db->table('auditoria');$trigger=$wpdb->prefix.'fail_audit';
    $db->query("CREATE TRIGGER $trigger BEFORE INSERT ON $audit FOR EACH ROW BEGIN IF NEW.acao='preservar_anexo_backup' AND NEW.entidade_id=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha de auditoria simulada'; END IF; END");
    update_option('ederp_schema_version','36');update_option('ederp_schema_error','Falha de vínculo em chat_anexos');update_option('ederp_schema_attempt_release','0.19.16');
    try{SchemaMigration::run($db);throw new LogicException('ignorou falha de auditoria');}catch(RuntimeException $e){checkCR(str_contains($e->getMessage(),'preservação dos anexos'),'falha simulada é identificada na etapa de recuperação');}
    checkCR(snapshotCR($db,$old)===$before,'falha de auditoria reverte referências, versões e registros do lote');
    checkCR(get_option('ederp_schema_version')==='36','falha não confirma a nova versão do banco');
    checkCR($db->get('chat_anexos',2)['idmensagem_original']===null,'falha não deixa metadados de recuperação parciais');
    $db->query("DROP TRIGGER $trigger");SchemaMigration::run($db);
    checkCR(get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error'),'backup 36 com anexos inválidos migra para '.Installer::VERSION);
    $after=snapshotCR($db,$old);foreach($before as $name=>$rows)if($name!=='chat_anexos'&&$name!=='auditoria')checkCR($after[$name]===$rows,'registros preservados: '.$name);
    checkCR($after['chat_anexos'][0]===$before['chat_anexos'][0],'anexo válido mantém vínculo, conteúdo e todos os campos anteriores');
    foreach([2=>[999,'mensagem_ausente'],3=>[2,'coligada_divergente'],4=>[1001,'mensagem_ausente']] as $id=>[$original,$reason]){
        $file=$db->get('chat_anexos',$id);$source=$before['chat_anexos'][$id-1];
        checkCR($file['idmensagem']===null&&(int)$file['idmensagem_original']===$original&&$file['motivo_recuperacao']===$reason,'anexo '.$id.' mantém referência original e motivo sem vínculo inventado');
        foreach(['nome','mime','tamanho','conteudo_base64','codcoligada','criado_em'] as $field)checkCR($file[$field]===$source[$field],'anexo '.$id.' preserva '.$field);
    }
    checkCR(count($after['chat_anexos'])===4&&count($after['chat_mensagens'])===2&&count($after['chat_conversas'])===2,'recuperação não apaga arquivos nem inventa mensagens/conversas');
    $files=new RecoveredChatAttachments($db);$list=$files->listing();checkCR($list['total']===3&&count($list['items'])===3,'administrador confere os três arquivos pendentes');
    checkCR(!array_key_exists('conteudo_base64',$list['items'][0]),'listagem não carrega conteúdo dos arquivos');
    checkCR($files->file(2)['bytes']==='arquivo sem mensagem','administrador pode baixar o conteúdo original');
    deniedCR(fn()=>$files->file(1),'recuperação não oferece download de arquivo válido fora dessa área');
    deniedCR(fn()=>(new ChatService($db))->attachment(2),'anexo sem vínculo não é disponibilizado pelo chat comum');
    $user=wp_insert_user(['user_login'=>'cr_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(),'role'=>'erp_secretaria']);if(is_wp_error($user))throw new RuntimeException('Falha ao criar usuário de teste.');wp_set_current_user((int)$user);
    deniedCR(fn()=>$files->listing(),'atendente não lista os anexos preservados');deniedCR(fn()=>$files->file(2),'atendente não baixa anexos preservados');wp_set_current_user(0);deniedCR(fn()=>$files->file(2),'visitante não baixa anexos preservados');wp_set_current_user($admin->ID);
    ob_start();(new ChatRecovery($db))->screen();$html=ob_get_clean();checkCR(str_contains($html,'&lt;arquivo&gt;.txt')&&!str_contains($html,'<arquivo>'),'nomes de arquivos são escapados na conferência');
    checkCR(substr_count($html,'action=ederp_recovered_chat_file')===3&&substr_count($html,'_wpnonce=')===3,'downloads administrativos recebem nonce individual');
    if($path=getenv('EDERP_TEST_RECOVERY_HTML'))file_put_contents($path,$html);
    $bad=$wpdb->insert($db->table('chat_anexos'),['idmensagem'=>9999,'codcoligada'=>1,'nome'=>'inválido.txt','mime'=>'text/plain','tamanho'=>1,'conteudo_base64'=>'eA==']);checkCR($bad===false,'FK final rejeita novo anexo sem mensagem');
    $bad=$wpdb->insert($db->table('chat_anexos'),['idmensagem'=>2,'codcoligada'=>1,'nome'=>'outra.txt','mime'=>'text/plain','tamanho'=>1,'conteudo_base64'=>'eA==']);checkCR($bad===false,'FK final rejeita novo anexo de outra coligada');
    $audits=$db->rows("SELECT * FROM $audit WHERE acao='preservar_anexo_backup'");checkCR(count($audits)===3&&!str_contains(json_encode($audits),'conteudo_base64'),'recuperações ficam auditadas sem conteúdo dos arquivos');
    $stable=snapshotCR($db,$db->schema());SchemaMigration::run($db);checkCR(snapshotCR($db,$db->schema())===$stable,'repetir migração não duplica recuperação, auditoria ou arquivos');
    echo "WORDPRESS_CHAT_RECOVERY_OK: $checks verificações / WordPress $wp_version / banco ".$wpdb->db_version()."\n";
}finally{$wpdb->prefix=$savedPrefix;foreach($savedOptions as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}wp_set_current_user($savedUser);$wpdb->suppress_errors($previous);}
