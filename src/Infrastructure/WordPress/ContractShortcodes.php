<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;

use EducacionalERP\Application\{Coligadas,ContractTemplates};
use EducacionalERP\Domain\RuleViolation;

final class ContractShortcodes
{
    private const OPTION = 'ederp_contrato_shortcodes_externos';

    public static function configured(): array
    {
        return (array)get_option(Coligadas::option(self::OPTION), []);
    }

    public static function save(array $data): array
    {
        Access::requireAdmin();
        $expressions = $data['expressoes'] ?? null;
        if (!is_array($expressions) || !array_is_list($expressions)) {
            throw new RuleViolation('Informe um shortcode por linha.');
        }

        $previous = self::configured();
        $allowed = [];
        foreach ($expressions as $expression) {
            if (!is_string($expression) || !preg_match('~^\[([^\s<>&/=\[\]"\']+)(?:[ \t]+[^\[\]<>\r\n]*)?/?\]$~uD', $expression, $matches)) {
                throw new RuleViolation('Use um shortcode completo por linha, como [unidade_escolar] ou [unidade_escolar campus="centro"].');
            }
            $tag = $matches[1];
            if (isset(ContractTemplates::TOKENS[$tag])) {
                throw new RuleViolation('O campo ['.$tag.'] já pertence ao contrato.');
            }
            if (!in_array($expression, $previous, true) && !shortcode_exists($tag)) {
                throw new RuleViolation('O WordPress não encontrou ['.$tag.']. Confira o nome e se o plugin ou tema está ativo.');
            }
            $allowed[$expression] = true;
        }
        $list = array_keys($allowed);
        if ($list !== $previous) {
            if (($data['anteriores'] ?? null) !== $previous) {
                throw new RuleViolation('A lista foi alterada. Reabra os shortcodes externos para editar.');
            }
            if (!update_option(Coligadas::option(self::OPTION), $list, false)) {
                throw new RuleViolation('Não foi possível salvar os shortcodes externos da coligada.');
            }
        }
        return ['shortcodes_externos' => $list];
    }

    /** Only a complete expression selected for this company can call its provider. */
    public static function substitute(array $native, array $configured): \Closure
    {
        $allowed = array_combine($configured, $configured);
        $cache = [];
        return static function (string $source) use ($native, $allowed, &$cache): string {
            return preg_replace_callback('~\[\[[^\[\]\r\n]+\]\]|\[[^\[\]\r\n]+\]~u', static function (array $match) use ($native, $allowed, &$cache): string {
                $token = $match[0];
                if (isset($native[$token])) return $native[$token];
                if (str_starts_with($token, '[[')) {
                    $inner = substr($token, 1, -1);
                    return isset($allowed[$inner]) ? $inner : strtr($token, $native);
                }
                if (!isset($allowed[$token])) return $token;
                if (array_key_exists($token, $cache)) return $cache[$token];

                $tag = substr($token, 1, strcspn($token, " \t/]", 1));
                if (!shortcode_exists($tag)) return $cache[$token] = $token;
                ob_start();
                try {
                    // The expression has fixed arguments and no nested shortcode or body.
                    $value = do_shortcode($allowed[$token]);
                } catch (\Throwable $error) {
                    throw new RuleViolation('Não foi possível preencher o shortcode ['.$tag.']. Confira o plugin que fornece esse campo.', 0, $error);
                } finally {
                    ob_end_clean();
                }
                return $cache[$token] = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $source);
        };
    }
}
