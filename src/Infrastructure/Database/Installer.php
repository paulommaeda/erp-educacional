<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
final class Installer
{
    public const VERSION = '15';
    public function __construct(private Database $db) {}
    public function install(): void
    {
        if (PHP_INT_SIZE < 8) { throw new \RuntimeException('O ERP exige PHP 64 bits.'); }
        if (version_compare($this->db->wp->db_version(), '5.7', '<')) { throw new \RuntimeException('MySQL 5.7+ ou MariaDB 10.3+ necessário.'); }
        $lock = 'ederp_schema_' . substr(hash('sha256', $this->db->wp->prefix . DB_NAME), 0, 32);
        $got = $this->db->row('SELECT GET_LOCK(%s, 5) AS acquired', [$lock]);
        if ((int)($got['acquired'] ?? 0) !== 1) { throw new \RuntimeException('Outra migração está em execução. Tente novamente.'); }
        try {
            update_option('ederp_schema_error', 'Migração em andamento ou interrompida. Execute novamente a verificação.', false);
            foreach([$this->db->wp->users,$this->db->wp->usermeta] as $native) {
                $engine=$this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',[$native]);
                if(strtoupper($engine['ENGINE']??'')!=='INNODB') { throw new \RuntimeException('As tabelas WordPress de usuários e metadados devem ser InnoDB para contas transacionais.'); }
            }
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            foreach ($this->db->schema() as $name => $meta) {
                $table = $this->db->table($name);
                $lines = [];
                foreach ($meta['columns'] as $col => $definition) { $lines[] = "$col $definition"; }
                $lines[] = 'PRIMARY KEY  (' . implode(',', $meta['pk']) . ')';
                $exists=$this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',[$table]);
                $previousIndexes=$exists?$this->db->rows("SHOW INDEX FROM $table"):[];
                // Secondary indexes are reconciled explicitly; dbDelta can misread legacy names.
                dbDelta("CREATE TABLE $table (\n" . implode(",\n", $lines) . "\n) ENGINE=InnoDB " . $this->db->wp->get_charset_collate() . ';');
                if ($this->db->wp->last_error) { throw new \RuntimeException('dbDelta falhou em ' . $name . ': ' . $this->db->wp->last_error); }
                $engine = $this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', [$table]);
                if (strtoupper($engine['ENGINE'] ?? '') !== 'INNODB') { throw new \RuntimeException('Tabela ' . $name . ' deve utilizar InnoDB.'); }
                if($name==='coligadas')(new \EducacionalERP\Application\Coligadas($this->db))->seed();
                // Remove obsolete global business keys; local unique keys below replace them.
                if(in_array($name,['alunos','periodos_letivos','cursos','turnos','planos_pagamento','contratos'],true)){
                    $keys=[];foreach($this->db->rows("SHOW INDEX FROM $table") as $index)if((int)$index['Non_unique']===0&&$index['Key_name']!=='PRIMARY')$keys[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
                    foreach($keys as $key=>$cols){ksort($cols);$cols=array_values($cols);if(!in_array('codcoligada',$cols,true)&&!in_array($cols,$meta['unique'],true))$this->db->query("ALTER TABLE $table DROP INDEX `".str_replace('`','``',$key)."`");}
                }
                SchemaIndexes::ensure($this->db,$table,$meta);
                $columns = $this->db->rows("SHOW COLUMNS FROM $table");
                if (count($columns) !== count($meta['columns'])) { throw new \RuntimeException('Estrutura divergente em ' . $name); }
            }
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
            foreach ($this->db->schema() as $name => $meta) {
                $table = $this->db->table($name);
                foreach ($meta['fks'] as $i => $fk) {
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
            if(version_compare((string)get_option('ederp_schema_version','0'),'13','<')){
                $this->db->atomic(function(){foreach($this->db->rows('SELECT * FROM '.$this->db->table('contratos')." WHERE numero LIKE 'CT-%' ORDER BY idcontrato FOR UPDATE") as $contract){$id=(int)$contract['idcontrato'];$number=\EducacionalERP\Application\ContractNumbers::forEnrollment($this->db,(int)$contract['idmatricula'],$id);$this->db->update('contratos',$id,['numero'=>$number]);$this->db->audit('contratos',$id,'padronizar_numero',$contract,['numero'=>$number],'migracao_13_contrato');}});
            }
            update_option('ederp_schema_version', self::VERSION, false);
            delete_option('ederp_schema_error');
        } finally { $this->db->row('SELECT RELEASE_LOCK(%s) AS released', [$lock]); }
    }
}
