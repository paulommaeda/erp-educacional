<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;

/** A recorded signature freezes the text and its provenance, for every ERP role. */
final class ContractText
{
    public const FROZEN=['termo_snapshot','versao_termo','idmodelo','modelo_nome_snapshot','modelo_versao_snapshot','assinado_em','assinado_por_wp_user_id','assinado_por_nome','assinatura_texto_hash','assinatura_numero_snapshot'];
    public static function signed(array $contract):bool {return !empty($contract['assinado_em']);}
    public static function requireEditable(array $contract):void {if(self::signed($contract))throw new RuleViolation('O texto deste contrato está assinado e bloqueado permanentemente.');}
    public static function assertUpdate(array $before,array $changes):void
    {
        if(!self::signed($before))return;
        foreach(self::FROZEN as $field)if(array_key_exists($field,$changes)&&(string)($changes[$field]??'')!==(string)($before[$field]??''))throw new RuleViolation('O texto e o registro de assinatura deste contrato são permanentes e não podem ser alterados.');
    }
}
