<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
final class Installer
{
    public const VERSION = '43';
    public function __construct(private Database $db) {}
    public function install(): void
    {
        if (PHP_INT_SIZE < 8) { throw new \RuntimeException('O ERP exige PHP 64 bits.'); }
        if (version_compare($this->db->wp->db_version(), '5.7', '<')) { throw new \RuntimeException('MySQL 5.7+ ou MariaDB 10.3+ necessário.'); }
        $lock = 'ederp_schema_' . substr(hash('sha256', $this->db->wp->prefix . DB_NAME), 0, 32);
        $got = $this->db->row('SELECT GET_LOCK(%s, 5) AS acquired', [$lock]);
        if ((int)($got['acquired'] ?? 0) !== 1) { throw new \RuntimeException('Outra migração está em execução. Tente novamente.'); }
        $stage = 'preparação do banco';
        try {
            update_option('ederp_schema_error', 'Migração em andamento ou interrompida. Execute novamente a verificação.', false);
            delete_option('ederp_schema_integrity');
            foreach([$this->db->wp->users,$this->db->wp->usermeta] as $native) {
                $engine=$this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',[$native]);
                if(strtoupper($engine['ENGINE']??'')!=='INNODB') { throw new \RuntimeException('As tabelas WordPress de usuários e metadados devem ser InnoDB para contas transacionais.'); }
            }
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            foreach ($this->db->schema() as $name => $meta) {
                $stage = 'estrutura da tabela ' . $name;
                $table = $this->db->table($name);
                $lines = [];
                foreach ($meta['columns'] as $col => $definition) { $lines[] = "$col $definition"; }
                $lines[] = 'PRIMARY KEY  (' . implode(',', $meta['pk']) . ')';
                // Secondary indexes are reconciled explicitly; dbDelta can misread legacy names.
                dbDelta("CREATE TABLE $table (\n" . implode(",\n", $lines) . "\n) ENGINE=InnoDB " . $this->db->wp->get_charset_collate() . ';');
                if ($this->db->wp->last_error) { throw new \RuntimeException('Não foi possível ajustar as colunas.'); }
                if($name==='chat_anexos')(new ChatAttachmentRecovery($this->db))->prepare();
                $engine = $this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', [$table]);
                if (strtoupper($engine['ENGINE'] ?? '') !== 'INNODB') { throw new \RuntimeException('Tabela ' . $name . ' deve utilizar InnoDB.'); }
                if($name==='coligadas')(new \EducacionalERP\Application\Coligadas($this->db))->seed();
                $stage = 'índices da tabela ' . $name;
                // A legacy unique key can still support a foreign key after a restore.
                // Install replacement indexes before removing any obsolete key.
                SchemaIndexes::ensure($this->db,$table,$meta);
                $stage = 'substituição de índices antigos da tabela ' . $name;
                // Local keys now exist; obsolete global business keys can be removed.
                if(in_array($name,['alunos','periodos_letivos','cursos','turnos','planos_pagamento','contratos'],true)){
                    $keys=[];foreach($this->db->rows("SHOW INDEX FROM $table") as $index)if((int)$index['Non_unique']===0&&$index['Key_name']!=='PRIMARY')$keys[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
                    foreach($keys as $key=>$cols){ksort($cols);$cols=array_values($cols);if(!in_array('codcoligada',$cols,true)&&!in_array($cols,$meta['unique'],true))$this->db->query("ALTER TABLE $table DROP INDEX `".str_replace('`','``',$key)."`");}
                }
                if($name==='chat_conversas'){
                    $keys=[];foreach($this->db->rows("SHOW INDEX FROM $table") as $idx)if((int)$idx['Non_unique']===0&&$idx['Key_name']!=='PRIMARY')$keys[$idx['Key_name']][(int)$idx['Seq_in_index']]=$idx['Column_name'];
                    foreach($keys as $key=>$cols){ksort($cols);if(array_values($cols)===['idcanal','codpessoa'])$this->db->query("ALTER TABLE $table DROP INDEX `".str_replace('`','``',$key)."`");}
                }
                if($name==='professor_disciplinas'){
                    $keys=[];foreach($this->db->rows("SHOW INDEX FROM $table") as $idx)if((int)$idx['Non_unique']===0&&$idx['Key_name']!=='PRIMARY')$keys[$idx['Key_name']][(int)$idx['Seq_in_index']]=$idx['Column_name'];
                    foreach($keys as $key=>$cols){ksort($cols);if(array_values($cols)===['codcoligada','idprofessor','iddisciplina'])$this->db->query("ALTER TABLE $table DROP INDEX `".str_replace('`','``',$key)."`");}
                }
                if($name==='api_requerimentos'){
                    $obsolete=[];foreach($this->db->rows("SHOW INDEX FROM $table") as $index)if((int)$index['Non_unique']===0&&$index['Key_name']!=='PRIMARY')$obsolete[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
                    foreach($obsolete as $key=>$cols){ksort($cols);if(array_values($cols)===['cpf_origem'])$this->db->query("ALTER TABLE $table DROP INDEX `".str_replace('`','``',$key)."`");}
                }
                SchemaIndexes::verify($meta,$this->db->rows("SHOW INDEX FROM $table"));
                $columns = $this->db->rows("SHOW COLUMNS FROM $table");
                if (count($columns) !== count($meta['columns'])) { throw new \RuntimeException('Estrutura divergente em ' . $name); }
            }
            $stage = 'adequação dos registros históricos';
            if((int)get_option('ederp_schema_version',0)<27){
                $this->db->atomic(function(){
                    $q=$this->db->table('professor_disciplinas');$p=$this->db->table('periodos_letivos');
                    foreach($this->db->rows("SELECT * FROM $q WHERE codperiodo IS NULL FOR UPDATE") as $old){
                        $periods=$this->db->rows("SELECT codperiodo FROM $p WHERE codcoligada=%d",[(int)$old['codcoligada']]);
                        foreach($periods as $period){if(!$this->db->row("SELECT idvinculo FROM $q WHERE idprofessor=%d AND iddisciplina=%d AND codperiodo=%d",[(int)$old['idprofessor'],(int)$old['iddisciplina'],(int)$period['codperiodo']]))$this->db->insert('professor_disciplinas',['codcoligada'=>$old['codcoligada'],'idprofessor'=>$old['idprofessor'],'iddisciplina'=>$old['iddisciplina'],'codperiodo'=>$period['codperiodo']]);}
                        if($periods){$this->db->query("DELETE FROM $q WHERE idvinculo=%d",[(int)$old['idvinculo']]);$this->db->audit('professor_disciplinas',(int)$old['idvinculo'],'migrar_periodos',$old,['periodos'=>array_column($periods,'codperiodo')],'schema-27');}
                    }
                });
            }
            if((int)get_option('ederp_schema_version',0)<18)(new \EducacionalERP\Application\AdditionalFields($this->db))->migrateGroups();
            if(version_compare((string)get_option('ederp_schema_version','0'),'8','<')){
                $plans=$this->db->table('planos_pagamento');$classes=$this->db->table('turmas');
                foreach($this->db->rows("SELECT idplano,MIN(codperiodo) AS codperiodo FROM $classes WHERE idplano IS NOT NULL GROUP BY idplano HAVING COUNT(DISTINCT codperiodo)=1") as $row)
                    $this->db->query("UPDATE $plans SET codperiodo=%d,versao=versao+1 WHERE idplano=%d AND codperiodo IS NULL",[(int)$row['codperiodo'],(int)$row['idplano']]);
            }
            if(version_compare((string)get_option('ederp_schema_version','0'),'9','<')){
                $periods=$this->db->table('periodos_letivos');$classes=$this->db->table('turmas');
                $links=$this->db->rows("SELECT a.codperiodo,MIN(b.codperiodo) AS destino FROM $classes a JOIN $classes b ON b.idturma=a.idturma_proxima GROUP BY a.codperiodo HAVING COUNT(DISTINCT b.codperiodo)=1");
                foreach($links as $link){
                    $from=$this->db->get('periodos_letivos',(int)$link['codperiodo']);$to=$this->db->get('periodos_letivos',(int)$link['destino']);
                    if(empty($from['codperiodo_proximo'])&&$to['data_inicio']>$from['data_inicio'])$this->db->update('periodos_letivos',(int)$link['codperiodo'],['codperiodo_proximo'=>(int)$link['destino']]);
                }
            }
            if(version_compare((string)get_option('ederp_schema_version','0'),'6','<'))(new \EducacionalERP\Application\CivilStatus($this->db))->migrate();
            // New nullable active keys permit a replacement while keeping cancelled history.
            foreach(['matriculas'=>['idaluno','codperiodo','idcurso'],'rematriculas'=>['idoferta','idmatricula_origem']] as $name=>$obsolete){
                $table=$this->db->table($name);$indexes=[];foreach($this->db->rows("SHOW INDEX FROM $table") as $index){if((int)$index['Non_unique']===0&&$index['Key_name']!=='PRIMARY')$indexes[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];}
                foreach($indexes as $index=>$columns){ksort($columns);if(array_values($columns)===$obsolete)$this->db->query("ALTER TABLE $table DROP INDEX `".str_replace('`','``',$index)."`");}
            }
            if(version_compare((string)get_option('ederp_schema_version','0'),'10','<')){
                $this->db->atomic(function(){
                    (new \EducacionalERP\Application\PersonNumbering($this->db))->migrate();
                    $this->db->query('UPDATE '.$this->db->table('matriculas')." SET ativo_unico=NULL WHERE status='cancelada'");
                    $this->db->query('UPDATE '.$this->db->table('rematriculas')." SET ativo_unico=NULL WHERE status='cancelada'");
                    $m=$this->db->table('matriculas');
                    foreach($this->db->rows("SELECT * FROM $m WHERE status='ativa'") as $row){
                        $paid=$this->db->row('SELECT l.idlancamento FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$this->db->table('contratos')." c ON c.idcontrato=p.idcontrato WHERE c.idmatricula=%d AND p.numero=1 AND l.status='quitado' AND l.valor_baixa>0 LIMIT 1",[(int)$row['idmatricula']]);
                        $status=$paid?'cursando':'reservado';$this->db->update('matriculas',(int)$row['idmatricula'],['status'=>$status]);
                        $now=gmdate('Y-m-d H:i:s');$this->db->insert('matricula_movimentacoes',['idmatricula'=>$row['idmatricula'],'tipo'=>'migracao_situacao','status_anterior'=>'ativa','status_novo'=>$status,'efetivado_em'=>$now,'registrado_em'=>$now,'motivo'=>'Adequação das situações da matrícula na versão 0.9.8','ator_wp_user_id'=>get_current_user_id()]);
                        $this->db->audit('matriculas',(int)$row['idmatricula'],'migracao_situacao',$row,['status'=>$status],'schema-10');
                    }
                });
            }
            $stage = 'preservação dos anexos do chat restaurado';
            (new ChatAttachmentRecovery($this->db))->recover();
            $stage = 'preservação dos históricos sem matrícula do backup';
            (new OrphanMovementRecovery($this->db))->recover();
            $stage = 'conferência das referências do backup';
            $issues=(new ForeignKeyIntegrity($this->db))->scan();
            if($issues){update_option('ederp_schema_integrity',['verificado_em'=>gmdate('Y-m-d H:i:s'),'referencias'=>$issues],false);throw new \RuntimeException('Há '.count($issues).' referência(s) com registros inconsistentes no backup. Consulte a lista completa em Configurações.');}
            foreach ($this->db->schema() as $name => $meta) {
                $table = $this->db->table($name);
                foreach ($meta['fks'] as $i => $fk) {
                    $stage = 'vínculo da tabela ' . $name . ' (' . implode(', ', $fk['columns']) . ') com ' . $fk['target'];
                    $constraint = 'erp_fk_' . substr(hash('sha256', $table . ':' . $i), 0, 32);
                    $existing = $this->db->rows('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s ORDER BY ORDINAL_POSITION', [$table, $constraint]);
                    $target = $this->db->table($fk['target']);
                    if ($existing) {
                        if (array_column($existing, 'COLUMN_NAME') !== $fk['columns'] || array_column($existing, 'REFERENCED_COLUMN_NAME') !== $fk['references'] || $existing[0]['REFERENCED_TABLE_NAME'] !== $target) { throw new \RuntimeException('FK divergente: ' . $constraint); }
                        continue;
                    }
                    $this->db->query("ALTER TABLE $table ADD CONSTRAINT $constraint FOREIGN KEY (" . implode(',', $fk['columns']) . ") REFERENCES $target (" . implode(',', $fk['references']) . ') ON DELETE RESTRICT ON UPDATE RESTRICT');
                }
            }
            $stage = 'conclusão dos registros históricos';
            if(version_compare((string)get_option('ederp_schema_version','0'),'13','<')){
                $this->db->atomic(function(){foreach($this->db->rows('SELECT * FROM '.$this->db->table('contratos')." WHERE numero LIKE 'CT-%' ORDER BY idcontrato FOR UPDATE") as $contract){$id=(int)$contract['idcontrato'];$number=\EducacionalERP\Application\ContractNumbers::forEnrollment($this->db,(int)$contract['idmatricula'],$id);$this->db->update('contratos',$id,['numero'=>$number]);$this->db->audit('contratos',$id,'padronizar_numero',$contract,['numero'=>$number],'migracao_13_contrato');}});
            }
            if((int)get_option('ederp_schema_version',0)<29)(new \EducacionalERP\Application\AdditionalFields($this->db))->ensureStudentSections();
            if((int)get_option('ederp_schema_version',0)<36)\EducacionalERP\Domain\AdmissionForm::seed($this->db);
            $stage = 'confirmação da versão do banco';
            update_option('ederp_schema_version', self::VERSION, false);
            if(get_option('ederp_schema_version')!==self::VERSION)throw new \RuntimeException('Não foi possível registrar a versão do banco.');
            delete_option('ederp_schema_error');
            if(get_option('ederp_schema_error'))throw new \RuntimeException('Não foi possível limpar o diagnóstico anterior nas opções do WordPress.');
        } catch (\Throwable $e) {
            // Capture before RELEASE_LOCK clears wpdb::last_error. Never include SQL or record values.
            $detail = trim($this->db->wp->last_error);
            $detail = preg_replace('/[\'\"](?:\\\\.|[^\'\"\\\\])*[\'\"]/us', '[valor omitido]', $detail) ?? '';
            $message = 'Migração interrompida em ' . $stage . '. ' . $e->getMessage();
            if($detail!=='')$message .= ' Detalhe do banco: ' . mb_substr($detail,0,800);
            throw new \RuntimeException($message,0,$e);
        } finally {
            try { $this->db->row('SELECT RELEASE_LOCK(%s) AS released', [$lock]); }
            catch (\Throwable $ignored) { /* A lost connection already releases its advisory lock. */ }
        }
    }
}
