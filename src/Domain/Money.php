<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
/** All intermediate calculations use integer cents (requires a 64-bit PHP build). */
final class Money
{
    public const MAX = 999999999999999;
    public static function cents(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
            throw new RuleViolation('Informe dinheiro como string decimal, por exemplo "1000.00".');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        $cents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        if ($cents > self::MAX) { throw new RuleViolation('Valor excede o limite monetário.'); }
        return $negative ? -$cents : $cents;
    }
    public static function format(int $cents): string
    {
        if (abs($cents) > self::MAX) { throw new RuleViolation('Valor excede o limite monetário.'); }
        $n = abs($cents);
        return ($cents < 0 ? '-' : '') . intdiv($n, 100) . '.' . str_pad((string)($n % 100), 2, '0', STR_PAD_LEFT);
    }
    public static function positive(mixed $value): int
    {
        $c = self::cents($value);
        if ($c <= 0) { throw new RuleViolation('O valor deve ser positivo.'); }
        return $c;
    }
    public static function split(int $total, int $count): array
    {
        if ($count < 1 || $count > 120 || $total < $count) { throw new RuleViolation('Parcelamento inválido.'); }
        $base = intdiv($total, $count); $remainder = $total % $count;
        $out = [];
        for ($i = 0; $i < $count; $i++) { $out[] = $base + ($i < $remainder ? 1 : 0); }
        return $out;
    }
}
