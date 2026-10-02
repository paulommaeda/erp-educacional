<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
use EducacionalERP\Domain\{Input, RuleViolation};
final class Operations
{
    public function __construct(private Store $db) {}
    /** Must execute inside the caller's transaction. */
    public function run(string $key, string $type, array $request, callable $work): array
    {
        Input::key($key);
        $normalize = static function (array $data) use (&$normalize): array {
            ksort($data);
            foreach ($data as &$v) { if (is_array($v)) { $v = $normalize($v); } }
            return $data;
        };
        $hash = hash('sha256', wp_json_encode([$type, get_current_user_id(), $normalize($request)]));
        $t = $this->db->table('operacoes');
        $this->db->query("INSERT INTO $t (chave,tipo,request_hash) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE chave=chave", [$key,$type,$hash]);
        $op = $this->db->row("SELECT * FROM $t WHERE chave=%s FOR UPDATE", [$key]);
        if (!$op || !hash_equals($op['request_hash'], $hash) || $op['tipo'] !== $type) { throw new RuleViolation('Chave de idempotência já usada com outra operação ou conteúdo.'); }
        if ($op['resposta_json'] !== null) { return json_decode($op['resposta_json'], true, 512, JSON_THROW_ON_ERROR); }
        $result = $work();
        $this->db->update('operacoes', (int)$op['idoperacao'], ['resposta_json'=>wp_json_encode($result)]);
        return $result;
    }
}
