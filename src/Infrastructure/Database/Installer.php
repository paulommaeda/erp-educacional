<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
final class Installer
{
    public const VERSION = '8';
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
                $lines=array_merge($lines,SchemaIndexes::lines($meta,$previousIndexes));
                dbDelta("CREATE TABLE $table (\n" . implode(",\n", $lines) . "\n) ENGINE=InnoDB " . $this->db->wp->get_charset_collate() . ';');
                if ($this->db->wp->last_error) { throw new \RuntimeException('dbDelta falhou em ' . $name . ': ' . $this->db->wp->last_error); }
                $engine = $this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', [$table]);
                if (strtoupper($engine['ENGINE'] ?? '') !== 'INNODB') { throw new \RuntimeException('Tabela ' . $name . ' deve utilizar InnoDB.'); }
                SchemaIndexes::verify($meta,$this->db->rows("SHOW INDEX FROM $table"));
                $columns = $this->db->rows("SHOW COLUMNS FROM $table");
                if (count($columns) !== count($meta['columns'])) { throw new \RuntimeException('Estrutura divergente em ' . $name); }
            }
            if(version_compare((string)get_option('ederp_schema_version','0'),'8','<')){
                $plans=$this->db->table('planos_pagamento');$classes=$this->db->table('turmas');
                foreach($this->db->rows("SELECT idplano,MIN(codperiodo) AS codperiodo FROM $classes WHERE idplano IS NOT NULL GROUP BY idplano HAVING COUNT(DISTINCT codperiodo)=1") as $row)
                    $this->db->query("UPDATE $plans SET codperiodo=%d,versao=versao+1 WHERE idplano=%d AND codperiodo IS NULL",[(int)$row['codperiodo'],(int)$row['idplano']]);
            }
            if(version_compare((string)get_option('ederp_schema_version','0'),'6','<'))(new \EducacionalERP\Application\CivilStatus($this->db))->migrate();
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
            update_option('ederp_schema_version', self::VERSION, false);
            delete_option('ederp_schema_error');
        } finally { $this->db->row('SELECT RELEASE_LOCK(%s) AS released', [$lock]); }
    }
}
