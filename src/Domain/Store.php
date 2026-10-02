<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
/** Persistence/transaction port consumed by application use cases. */
interface Store
{
    public function table(string $name): string;
    public function query(string $sql,array $args=[]): int;
    public function rows(string $sql,array $args=[]): array;
    public function row(string $sql,array $args=[]): ?array;
    public function get(string $table,int $id,bool $lock=false): array;
    public function insert(string $table,array $data): int;
    public function update(string $table,int $id,array $data): void;
    public function atomic(callable $work): mixed;
    public function onCompletion(callable $callback): void;
    public function audit(string $entity,int $id,string $action,?array $before,array $after,string $key): void;
}
