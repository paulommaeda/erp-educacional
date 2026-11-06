<?php
/** Disposable WordPress/MySQL only: orphan histories, full copies, rollback and all-FK diagnostics. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');if(!$bootstrap||!is_file($bootstrap)){fwrite(STDERR,"Defina EDERP_TEST_BOOTSTRAP para WordPress descartável.\n");exit(2);}
$_SERVER['HTTP_HOST']='erp-test.local';$_SERVER['REQUEST_URI']='/';require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true||defined('EDERP_SQLITE_TEST'))throw new RuntimeException('Exige banco MySQL descartável autorizado.');
require_once dirname(__DIR__).'/autoload.php';require_once __DIR__.'/wordpress-migration-fixtures.php';
use EducacionalERP\Infrastructure\Database\{Database,Installer,ForeignKeyIntegrity};
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};
use EducacionalERP\Application\PreservedRecords;
use EducacionalERP\Presentation\Admin\{BackupRecovery,Pages};
use EducacionalERP\Domain\RuleViolation;
$admin=get_users(['role'=>'administrator','number'=>1])[0]??null;if(!$admin)throw new RuntimeException('Administrador ausente.');wp_set_current_user($admin->ID);Access::install();
$savedPrefix=$wpdb->prefix;$savedOptions=[];$checks=0;$privateFile=null;$previous=$wpdb->suppress_errors(true);
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_attempt_release','ederp_schema_integrity'] as $key)$savedOptions[$key]=get_option($key,null);
function okMR(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
function denyMR(callable $work,string $label):void{try{$work();}catch(RuleViolation $e){okMR(true,$label);return;}throw new RuntimeException($label);}
function dropMovementFks(Database $db,array $meta):void{$table=$db->table('matricula_movimentacoes');foreach($meta['fks'] as $i=>$fk)$db->query("ALTER TABLE $table DROP FOREIGN KEY erp_fk_".substr(hash('sha256',$table.':'.$i),0,32));}
function rawMovement(Database $db,array $row):void{global $wpdb;if($wpdb->insert($db->table('matricula_movimentacoes'),$row)===false)throw new RuntimeException('Fixture de movimentação inválida: '.$wpdb->last_error);}
try{
    $old=json_decode(file_get_contents(__DIR__.'/fixtures/schema-36.json'),true,512,JSON_THROW_ON_ERROR);$wpdb->prefix='mr_'.bin2hex(random_bytes(5)).'_';$db=fixtureM($old);$source=$db->table('matricula_movimentacoes');dropMovementFks($db,$old['matricula_movimentacoes']);
    $base=['tipo'=>'inicial','status_anterior'=>null,'status_novo'=>'reservado','efetivado_em'=>'2025-01-10 10:00:00','registrado_em'=>'2025-01-10 10:30:00','motivo'=>'Histórico de backup fictício','ator_wp_user_id'=>$admin->ID];
    rawMovement($db,['idmovimentacao'=>1,'idmatricula'=>1]+$base);
    rawMovement($db,['idmovimentacao'=>2,'idmatricula'=>999,'codcoligada'=>999,'idturma_origem'=>888,'idturma_destino'=>777,'solicitante_codpessoa'=>666,'solicitante_nome'=>'SOLICITANTE ORIGINAL']+$base);
    $token=bin2hex(random_bytes(32));$bytes="%PDF-1.4\nDocumento fictício preservado.\n";$document=['owner'=>$admin->ID,'created'=>time(),'name'=>'declaracao-original.pdf','mime'=>'application/pdf','data'=>base64_encode($bytes)];
    $upload=wp_upload_dir();$dir=$upload['basedir'].'/erp-documentos';if(!wp_mkdir_p($dir))throw new RuntimeException('Diretório fictício indisponível.');$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(wp_json_encode($document),'aes-256-gcm',hash('sha256',wp_salt('auth').'|erp-private-documents',true),OPENSSL_RAW_DATA,$iv,$tag);$privateFile=$dir.'/'.$token.'.bin';file_put_contents($privateFile,$iv.$tag.$cipher,LOCK_EX);
    rawMovement($db,['idmovimentacao'=>3,'idmatricula'=>1000,'tipo'=>'solicitacao_transferencia','documento_token'=>$token,'documento_nome'=>$document['name'],'documento_mime'=>$document['mime']]+$base);
    $before=snapshotM($db,$old);$audit=$db->table('auditoria');$trigger=$wpdb->prefix.'fail_preserve';
    $db->query("CREATE TRIGGER $trigger BEFORE INSERT ON $audit FOR EACH ROW BEGIN IF NEW.entidade='registros_preservados' AND NEW.entidade_id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha de preservação simulada'; END IF; END");
    update_option('ederp_schema_version','36');delete_option('ederp_schema_error');
    try{SchemaMigration::run($db);throw new LogicException('ignorou falha da auditoria');}catch(RuntimeException $e){okMR(str_contains($e->getMessage(),'preservação dos históricos'),'falha simulada informa a etapa de preservação');}
    okMR(snapshotM($db,$old)===$before,'falha reverte todo o lote e preserva os registros originais');
    okMR(count($db->rows('SELECT * FROM '.$db->table('registros_preservados')))===0&&get_option('ederp_schema_version')==='36','falha não deixa cópias parciais nem avança a versão');
    $db->query("DROP TRIGGER $trigger");SchemaMigration::run($db);okMR(!SchemaMigration::pending()&&get_option('ederp_schema_version')===Installer::VERSION,'backup com movimentações sem matrícula migra para '.Installer::VERSION);
    $after=snapshotM($db,$old);$valid=$after['matricula_movimentacoes'];okMR(count($valid)===1&&$valid[0]===$before['matricula_movimentacoes'][0],'movimentação válida mantém todos os seus campos e vínculos');
    $originalOthers=$before;$updatedOthers=$after;unset($originalOthers['matricula_movimentacoes'],$originalOthers['auditoria'],$updatedOthers['matricula_movimentacoes'],$updatedOthers['auditoria']);okMR($updatedOthers===$originalOthers,'alunos, matrículas, contratos, parcelas, pagamentos e demais registros permanecem iguais');
    $service=new PreservedRecords($db);$records=$db->rows('SELECT * FROM '.$db->table('registros_preservados').' ORDER BY registro_origem_id');okMR(count($records)===2,'ambos os históricos inválidos são preservados');
    foreach($records as $record){$original=$before['matricula_movimentacoes'][(int)$record['registro_origem_id']-1];ksort($original);$copy=json_decode($record['dados_json'],true,512,JSON_THROW_ON_ERROR);okMR($copy===$original,'cópia integral e sem conversão de valores: '.$record['registro_origem_id']);okMR(hash_equals($record['hash_registro'],hash('sha256',$record['dados_json'])),'hash confirma a cópia: '.$record['registro_origem_id']);}
    $docRecord=(int)$records[1]['idpreservado'];okMR($service->file($docRecord,'documento')['bytes']===$bytes,'documento privado continua legível pela cópia preservada');okMR(json_decode($service->file($docRecord)['bytes'],true)['documento_token']===$token,'exportação mantém a referência original do documento');
    okMR(count($db->rows('SELECT * FROM '.$db->table('matriculas')))===1&&count($db->rows('SELECT * FROM '.$db->table('coligadas')))===1,'recuperação não inventa matrícula, turma, pessoa ou coligada');
    okMR((new ForeignKeyIntegrity($db))->scan()===[],'todas as referências declaradas ficam consistentes após a recuperação');
    $bad=$wpdb->insert($source,['idmatricula'=>9999]+$base);okMR($bad===false,'FK final continua bloqueando novas movimentações sem matrícula');
    $stable=snapshotM($db,$db->schema());SchemaMigration::run($db);okMR(snapshotM($db,$db->schema())===$stable,'repetir a migração não altera dados ou duplica cópias e auditorias');
    // Restoring the same original row again reuses its verified copy.
    dropMovementFks($db,$old['matricula_movimentacoes']);rawMovement($db,$before['matricula_movimentacoes'][1]);SchemaMigration::run($db);okMR($service->count()===2,'restaurar o mesmo histórico novamente reutiliza a cópia integral');
    // A different restored version of the same ID must never overwrite the older copy.
    dropMovementFks($db,$old['matricula_movimentacoes']);$another=$before['matricula_movimentacoes'][1];$another['motivo']='Outra versão do mesmo histórico';rawMovement($db,$another);SchemaMigration::run($db);okMR($service->count()===3,'conteúdo diferente com o mesmo código mantém as duas versões preservadas');
    $staff=wp_insert_user(['user_login'=>'mr_staff_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(),'role'=>'erp_secretaria']);if(is_wp_error($staff))throw new RuntimeException('Atendente fictício inválido.');wp_set_current_user((int)$staff);denyMR(fn()=>$service->listing(),'atendente não consulta registros preservados');denyMR(fn()=>$service->file($docRecord,'documento'),'atendente não baixa documento preservado');wp_set_current_user(0);denyMR(fn()=>$service->file($docRecord),'visitante não exporta cópia preservada');wp_set_current_user($admin->ID);
    // Separate critical data issues must be reported together, without automatic financial changes.
    foreach(['contratos'=>'matriculas','chat_leituras'=>'chat_conversas'] as $name=>$target){$table=$db->table($name);foreach($db->schema()[$name]['fks'] as $i=>$fk)if($fk['target']===$target)$db->query("ALTER TABLE $table DROP FOREIGN KEY erp_fk_".substr(hash('sha256',$table.':'.$i),0,32));}
    $db->query('UPDATE '.$db->table('contratos').' SET idmatricula=7777 WHERE idcontrato=1');$wpdb->insert($db->table('chat_leituras'),['idconversa'=>9999,'wp_user_id'=>$admin->ID]);$readId=(int)$wpdb->insert_id;update_option('ederp_schema_version','36');
    try{SchemaMigration::run($db);throw new LogicException('ignorou referências críticas');}catch(RuntimeException $e){okMR(str_contains($e->getMessage(),'lista completa'),'outros impedimentos são identificados antes de adicionar FKs');}
    $report=get_option('ederp_schema_integrity');$tables=array_column($report['referencias'],'tabela');okMR(in_array('contratos',$tables,true)&&in_array('chat_leituras',$tables,true),'relatório reúne inconsistências de tabelas diferentes');
    okMR(get_option('ederp_schema_version')==='36'&&$service->count()===3,'diagnóstico não avança a versão e mantém as cópias acessíveis');okMR((int)$db->get('contratos',1)['idmatricula']===7777&&count($db->rows('SELECT * FROM '.$db->table('matriculas')))===1,'conferência não altera contratos nem cria pais fictícios');
    ob_start();(new Pages())->screen('config','Configurações','manage_options');$html=ob_get_clean();okMR(str_contains($html,'Referências pendentes')&&str_contains($html,'contratos')&&str_contains($html,'chat_leituras')&&str_contains($html,'Conferir registros preservados'),'tela de migração apresenta relatório completo e acesso aos históricos');$migrationHtml=$html;
    ob_start();(new BackupRecovery($db))->screen();$html=ob_get_clean();okMR(substr_count($html,'Baixar registro completo')===3&&str_contains($html,'Baixar documento preservado')&&str_contains($html,'_wpnonce='),'conferência administrativa oferece downloads protegidos durante migração pendente');if($path=getenv('EDERP_TEST_BACKUP_HTML'))file_put_contents($path,$migrationHtml.'<hr>'.$html);
    $db->query('UPDATE '.$db->table('contratos').' SET idmatricula=1 WHERE idcontrato=1');$db->query('DELETE FROM '.$db->table('chat_leituras').' WHERE idleitura=%d',[$readId]);SchemaMigration::run($db);okMR(!SchemaMigration::pending()&&!get_option('ederp_schema_integrity'),'resolver os vínculos restantes permite concluir e limpa o relatório anterior');
    echo "WORDPRESS_MOVEMENT_RECOVERY_OK: $checks verificações / WordPress $wp_version / banco ".$wpdb->db_version()."\n";
}finally{if($privateFile&&is_file($privateFile))unlink($privateFile);$wpdb->prefix=$savedPrefix;foreach($savedOptions as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}wp_set_current_user($admin->ID);$wpdb->suppress_errors($previous);}
