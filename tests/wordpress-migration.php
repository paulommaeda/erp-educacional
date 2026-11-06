<?php
/**
 * Real WordPress/dbDelta + MySQL/MariaDB regression. Disposable database only.
 * EDERP_TEST_BOOTSTRAP=/path/to/wp-load.php php tests/wordpress-migration.php
 * Requires EDERP_TEST_DATABASE=true in that installation's wp-config.php.
 * Uses new fixture table prefixes and leaves fictitious records for inspection.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');
if(!$bootstrap||!is_file($bootstrap)){fwrite(STDERR,"Defina EDERP_TEST_BOOTSTRAP para um WordPress descartável.\n");exit(2);}
require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true||defined('EDERP_SQLITE_TEST'))throw new RuntimeException('Este teste exige WordPress/MySQL descartável autorizado.');
require_once dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\{Database,Installer,SchemaIndexes};
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};
use EducacionalERP\Presentation\Admin\Pages;
$admins=get_users(['role'=>'administrator','number'=>1]);if(!$admins)throw new RuntimeException('Administrador de teste ausente.');
wp_set_current_user($admins[0]->ID);Access::install();
$old=json_decode(file_get_contents(__DIR__.'/fixtures/schema-36.json'),true,512,JSON_THROW_ON_ERROR);
$savedPrefix=$wpdb->prefix;$savedOptions=[];$checks=0;
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_attempt_release'] as $key)$savedOptions[$key]=get_option($key,null);
$previous=$wpdb->suppress_errors(true);
function checkM(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
function fixtureM(array $schema):Database{
    global $wpdb;
    $db=new Database($wpdb);
    foreach($schema as $name=>$meta){$lines=[];foreach($meta['columns'] as $col=>$def)$lines[]="$col $def";$lines[]='PRIMARY KEY ('.implode(',',$meta['pk']).')';$lines=array_merge($lines,SchemaIndexes::lines($meta,[]));$db->query('CREATE TABLE '.$db->table($name).' ('.implode(',',$lines).') ENGINE=InnoDB '.$wpdb->get_charset_collate());}
    foreach($schema as $name=>$meta)foreach($meta['fks'] as $i=>$fk){$table=$db->table($name);$constraint='erp_fk_'.substr(hash('sha256',$table.':'.$i),0,32);$db->query("ALTER TABLE $table ADD CONSTRAINT $constraint FOREIGN KEY (".implode(',',$fk['columns']).') REFERENCES '.$db->table($fk['target']).' ('.implode(',',$fk['references']).') ON DELETE RESTRICT ON UPDATE RESTRICT');}
    $fixtures=[
        'coligadas'=>[['codcoligada'=>1,'nome'=>'COLÉGIO FICTÍCIO','razao_social'=>'COLÉGIO FICTÍCIO']],
        'pessoas'=>[['codpessoa'=>1,'nome'=>'ALUNO DO BACKUP'],['codpessoa'=>2,'nome'=>'ASSINANTE DO BACKUP']],
        'alunos'=>[['idaluno'=>1,'codpessoa'=>1,'ra'=>'000001']],
        'periodos_letivos'=>[['codperiodo'=>1,'codigo'=>'2026','descricao'=>'Ano letivo','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']],
        'cursos'=>[['idcurso'=>1,'codigo'=>'EF','nome'=>'Fundamental']],
        'turnos'=>[['idturno'=>1,'codigo'=>'M','nome'=>'Manhã']],
        'planos_pagamento'=>[['idplano'=>1,'codperiodo'=>1,'codigo'=>'AN','nome'=>'Anuidade','valor_anuidade'=>'1000.00']],
        'turmas'=>[['idturma'=>1,'codperiodo'=>1,'idcurso'=>1,'idturno'=>1,'idplano'=>1,'codigo'=>'6A','nome'=>'6º ano','capacidade'=>30]],
        'matriculas'=>[['idmatricula'=>1,'idaluno'=>1,'codperiodo'=>1,'idcurso'=>1,'idturma_atual'=>1,'data_matricula'=>'2026-01-05','origem'=>'inicial','status'=>'cursando']],
        'contratos'=>[['idcontrato'=>1,'numero'=>'BACKUP-000001','idmatricula'=>1,'codpessoa_rf_original'=>2,'codpessoa_rf_atual'=>2,'idplano'=>1,'data_contrato'=>'2026-01-05','quantidade_parcelas'=>1,'versao_termo'=>'backup-1','termo_snapshot'=>'TERMO ORIGINAL DO BACKUP','valor_original_total'=>'1000.00','valor_liquido_total'=>'1000.00']],
        'parcelas'=>[['idparcela'=>1,'idcontrato'=>1,'numero'=>1,'competencia'=>'2026-01-01','vencimento_original'=>'2026-01-31','regra_desconto_condicional'=>'{}','regra_encargos'=>'{}','valor_original'=>'1000.00','valor_liquido'=>'1000.00']],
        'lancamentos'=>[['idlancamento'=>1,'idparcela'=>1,'codpessoa_rf_original'=>2,'codpessoa_rf_atual'=>2,'descricao'=>'PARCELA DO BACKUP','emissao'=>'2026-01-05','vencimento'=>'2026-01-31','valor_original'=>'1000.00','valor_liquido'=>'1000.00','valor_baixa'=>'100.00','saldo_aberto'=>'900.00','status'=>'parcial']],
        'baixas'=>[['idbaixa'=>1,'idlancamento'=>1,'codpessoa_devedor'=>2,'data_pagamento'=>'2026-01-10','registrado_em'=>'2026-01-10 12:00:00','forma_pagamento'=>'pix','valor_pago'=>'100.00','idempotencia'=>'backup-migration-payment','ator_wp_user_id'=>get_current_user_id()]],
        'chat_canais'=>[['idcanal'=>1,'nome'=>'Secretaria']],
        'chat_conversas'=>[['idconversa'=>1,'idcanal'=>1,'codpessoa'=>2,'idaluno'=>1,'ultima_mensagem_id'=>1]],
        'chat_mensagens'=>[['idmensagem'=>1,'idconversa'=>1,'autor_wp_user_id'=>get_current_user_id(),'autor_nome'=>'Responsável','origem'=>'responsavel','texto'=>'MENSAGEM VÁLIDA DO BACKUP']],
        'chat_anexos'=>[['idanexo'=>1,'idmensagem'=>1,'nome'=>'valido.txt','mime'=>'text/plain','tamanho'=>6,'conteudo_base64'=>base64_encode('backup')]],
    ];
    foreach($fixtures as $name=>$rows)foreach($rows as $row)if($wpdb->insert($db->table($name),$row)===false)throw new RuntimeException('Fixture inválida em '.$name.': '.$wpdb->last_error);
    return $db;
}
function snapshotM(Database $db,array $schema):array{
    $out=[];foreach($schema as $name=>$meta)$out[$name]=$db->rows('SELECT '.implode(',',array_keys($meta['columns'])).' FROM '.$db->table($name).' ORDER BY '.implode(',',$meta['pk']));return $out;
}
try{
    // Frontend configuration must render before upgrade.php loads admin-only helpers.
    update_option('ederp_schema_version','36');update_option('ederp_schema_error','Migração pendente');
    foreach(['config','configuracoes'] as $slug){ob_start();(new Pages())->screen($slug,'Configurações','manage_options');$html=ob_get_clean();checkM(str_contains($html,'<button type="submit"')&&str_contains($html,'_wpnonce')&&!str_contains($html,'data-erp-screen='),'recuperação renderiza sem funções exclusivas do wp-admin: '.$slug);}
    foreach([false,true] as $legacy){
        $wpdb->prefix='mig_'.bin2hex(random_bytes(5)).'_';$db=fixtureM($old);$before=snapshotM($db,$old);
        if($legacy){
            $table=$db->table('alunos');$db->query("ALTER TABLE $table ADD UNIQUE KEY restored_person (codpessoa)");
            foreach($db->rows("SHOW INDEX FROM $table") as $idx)if((int)$idx['Non_unique']===1&&$idx['Column_name']==='codpessoa')$db->query("ALTER TABLE $table DROP INDEX `".$idx['Key_name'].'`');
        }
        update_option('ederp_schema_version','36');update_option('ederp_schema_error','Falha de persistência. Verifique conexão, restrições e índices do ERP.');update_option('ederp_schema_attempt_release','0.19.15');
        checkM(SchemaMigration::shouldAttemptAutomatically(),'erro antigo permite uma tentativa na nova versão');
        SchemaMigration::run($db);
        checkM(get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error'),$legacy?'recupera índice antigo usado pela FK sem remover vínculos':'atualiza backup íntegro 36 → '.Installer::VERSION);
        checkM(snapshotM($db,$old)===$before,'todos os registros e valores anteriores permanecem iguais');
        foreach(['matriculas','contratos','lancamentos'] as $name)checkM($db->get($name,1)['excluido_em']===null,'registros restaurados continuam disponíveis em '.$name);
        checkM($db->get('contratos',1)['idmodelo']===null,'contrato antigo mantém seu termo e não recebe modelo inventado');
        SchemaMigration::run($db);checkM(snapshotM($db,$old)===$before,'repetir migração preserva os dados e não duplica registros');
        checkM(!SchemaMigration::shouldAttemptAutomatically(),'banco atualizado encerra as tentativas automáticas');
        $invalid=$wpdb->insert($db->table('alunos'),['codpessoa'=>99999999,'ra'=>'INVALID']);checkM($invalid===false,'chaves estrangeiras continuam impedindo aluno sem pessoa');
    }
    // A backup with a missing unique key and conflicting data must stop, retaining its version.
    $table=$db->table('alunos');$indexes=[];foreach($db->rows("SHOW INDEX FROM $table") as $idx)if((int)$idx['Non_unique']===0)$indexes[$idx['Key_name']][(int)$idx['Seq_in_index']]=$idx['Column_name'];
    foreach($indexes as $name=>$cols){ksort($cols);if(array_values($cols)===['codcoligada','ra'])$db->query("ALTER TABLE $table DROP INDEX `$name`");}
    $db->query('INSERT INTO '.$db->table('pessoas')." (codpessoa,nome) VALUES (3,'OUTRO ALUNO FICTÍCIO')");$db->query("INSERT INTO $table (codpessoa,ra) VALUES (3,'000001')");
    update_option('ederp_schema_version','36');delete_option('ederp_schema_error');
    try{SchemaMigration::run($db);throw new LogicException('aceitou RA duplicado');}catch(RuntimeException $e){
        $message=(string)get_option('ederp_schema_error');checkM(str_contains($message,'índices da tabela alunos')&&str_contains($message,'Duplicate entry'),'diagnóstico identifica etapa, tabela e erro do banco');
        checkM(!str_contains($message,'000001')&&!str_contains($message,'INSERT INTO'),'diagnóstico não revela valores dos registros nem consultas');
    }
    checkM(get_option('ederp_schema_version')==='36','migração incompleta não marca banco como atualizado');
    checkM(!SchemaMigration::shouldAttemptAutomatically(),'falha não provoca novas migrações a cada carregamento');
    foreach(['config','configuracoes'] as $slug){ob_start();(new Pages())->screen($slug,'Configurações','manage_options');$html=ob_get_clean();checkM(str_contains($html,'ederp_migrate')&&str_contains($html,'_wpnonce')&&str_contains($html,'Diagnóstico da última tentativa')&&!str_contains($html,'data-erp-screen='),'recuperação acessível sem API bloqueada: '.$slug);}
    update_option('ederp_schema_error','<script>alert("fixture")</script>');ob_start();(new Pages())->screen('config','Configurações','manage_options');$html=ob_get_clean();checkM(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'diagnóstico é escapado na tela');
    $db->query("UPDATE $table SET ra='000002' WHERE codpessoa=3");SchemaMigration::run($db);
    checkM(!SchemaMigration::pending(),'tentativa manual conclui após resolver os dados conflitantes');
    checkM(count($db->rows("SELECT * FROM $table"))===2,'recuperação mantém ambos os alunos');
    $wpdb->prefix='mig_'.bin2hex(random_bytes(5)).'_';$db=new Database($wpdb);delete_option('ederp_schema_version');delete_option('ederp_schema_error');
    SchemaMigration::run($db);checkM(!SchemaMigration::pending(),'instalação nova também conclui todas as migrações');
    $fresh=snapshotM($db,$db->schema());checkM(count($fresh['campos_adicionais'])>0&&count($fresh['coligadas'])===1,'instalação nova prepara cadastros iniciais');
    SchemaMigration::run($db);checkM(snapshotM($db,$db->schema())===$fresh,'repetir instalação nova não altera cadastros iniciais');
    echo "WORDPRESS_MIGRATION_OK: $checks verificações / WordPress $wp_version / banco ".$wpdb->db_version()."\n";
}finally{
    $wpdb->prefix=$savedPrefix;
    foreach($savedOptions as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
    $wpdb->suppress_errors($previous);
}
