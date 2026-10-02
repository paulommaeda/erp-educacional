<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class Input
{
    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9]\d{0,17}$/D', (string)$value)) {
            throw new RuleViolation('Identificador inválido.');
        }
        return (int)$value;
    }
    public static function date(mixed $value): string
    {
        if (!is_string($value)) { throw new RuleViolation('Data inválida.'); }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) { throw new RuleViolation('Use uma data válida no formato AAAA-MM-DD.'); }
        return $value;
    }
    public static function text(mixed $value, int $max = 191): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max) { throw new RuleViolation('Texto ausente ou maior que o limite.'); }
        $clean = trim(strip_tags($value));
        if ($clean === '') { throw new RuleViolation('Texto vazio após sanitização.'); }
        return $clean;
    }
    public static function key(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{16,100}$/D', $value)) { throw new RuleViolation('Idempotency-Key deve ter entre 16 e 100 caracteres alfanuméricos, _ ou -.'); }
        return $value;
    }
    public static function today(): string { return function_exists('wp_date') ? wp_date('Y-m-d') : date('Y-m-d'); }
}
