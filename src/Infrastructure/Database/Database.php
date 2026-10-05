<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
use EducacionalERP\Domain\RuleViolation;
/** Small persistence adapter. All table/column identifiers originate in bundled schema metadata. */
final class Database implements \EducacionalERP\Domain\Store
{
    private bool $transaction = false;
    private array $schema;
    private array $completion=[];
    public function __construct(public readonly \wpdb $wp)
    {
        $this->schema = json_decode(file_get_contents(__DIR__ . '/schema.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public function schema(): array { return $this->schema; }
    public function table(string $name): string
    {
        if (!isset($this->schema[$name]) || !preg_match('/^[A-Za-z0-9_]+$/D', $this->wp->prefix)) { throw new \LogicException('Tabela inválida.'); }
        return $this->wp->prefix . 'erp_' . $name;
    }
    public function query(string $sql, array $args = []): int
    {
        $result = $this->wp->query($args ? $this->wp->prepare($sql, ...$args) : $sql);
        if ($result === false) { throw new \RuntimeException('Falha de persistência. Verifique conexão, restrições e índices do ERP.'); }
        return (int)$result;
    }
    public function rows(string $sql, array $args = []): array
    {
        $rows = $this->wp->get_results($args ? $this->wp->prepare($sql, ...$args) : $sql, ARRAY_A);
        if ($this->wp->last_error) { throw new \RuntimeException('Falha de leitura do ERP.'); }
        return $rows ?: [];
    }
    public function row(string $sql, array $args = []): ?array { return $this->rows($sql, $args)[0] ?? null; }
    public function get(string $table, int $id, bool $lock = false): array
    {
        $pk = $this->schema[$table]['pk'][0];
        $row = $this->row('SELECT * FROM ' . $this->table($table) . " WHERE $pk = %d" . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (!$row) { throw new RuleViolation('Registro não encontrado.'); }
        return $row;
    }
    public function insert(string $table, array $data): int
    {
        $data=Ownership::apply($this,$table,$data);
        $this->validateColumns($table, $data);
        if ($this->wp->insert($this->table($table), $data) === false) { throw new \RuntimeException('Não foi possível inserir o registro; verifique duplicidade e referências.'); }
        return (int)$this->wp->insert_id;
    }
    public function update(string $table, int $id, array $data): void
    {
        $data=Ownership::apply($this,$table,$data,$this->get($table,$id));
        $data['atualizado_em'] = gmdate('Y-m-d H:i:s');
        $this->validateColumns($table, $data);
        $pk = $this->schema[$table]['pk'][0];
        if ($this->wp->update($this->table($table), $data, [$pk => $id]) === false) { throw new \RuntimeException('Não foi possível atualizar o registro.'); }
        $this->query('UPDATE ' . $this->table($table) . " SET versao = versao + 1 WHERE $pk = %d", [$id]);
    }
    private function validateColumns(string $table, array $data): void
    {
        $this->table($table);
        foreach ($data as $column => $value) {
            if (!isset($this->schema[$table]['columns'][$column]) || is_array($value) || is_object($value)) { throw new \LogicException('Coluna ou valor inválido.'); }
        }
    }
    public function atomic(callable $work): mixed
    {
        if ($this->transaction) { throw new \LogicException('Transações aninhadas não são permitidas.'); }
        $this->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $this->transaction = true; $committed=false;
        try { $result = $work(); $this->query('COMMIT'); $committed=true; return $result; }
        catch (\Throwable $e) { $this->wp->query('ROLLBACK'); throw $e; }
        finally {
            $this->transaction = false; $callbacks=$this->completion; $this->completion=[];
            foreach($callbacks as $callback) { try { $callback($committed); } catch(\Throwable $ignored) { /* Cleanup cannot replace a committed result. */ } }
        }
    }
    public function onCompletion(callable $callback): void
    {
        if(!$this->transaction) { throw new \LogicException('Callback exige transação ativa.'); }
        $this->completion[]=$callback;
    }
    public function audit(string $entity, int $id, string $action, ?array $before, array $after, string $key): void
    {
        $company=\EducacionalERP\Application\Coligadas::current();
        if(isset($this->schema[$entity]['columns']['codcoligada'])&&$entity!=='coligadas'){$pk=$this->schema[$entity]['pk'][0];$row=$this->row('SELECT codcoligada FROM '.$this->table($entity)." WHERE $pk=%d",[$id]);$company=(int)($row['codcoligada']??$before['codcoligada']??$company);}
        $this->insert('auditoria', ['codcoligada'=>$company,'entidade'=>$entity,'entidade_id'=>$id,'acao'=>$action,
            'antes_json'=>$before === null ? null : wp_json_encode($before),'depois_json'=>wp_json_encode($after),
            'ator_wp_user_id'=>get_current_user_id(),'ocorrido_em'=>gmdate('Y-m-d H:i:s'),'correlacao_id'=>$key]);
    }
}
