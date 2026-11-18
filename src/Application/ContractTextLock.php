<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,RuleViolation};

/** Serialize text edits/model propagation/signing before acquiring entity row locks. */
final class ContractTextLock
{
    public static function run(Store $db,callable $work):mixed
    {
        $name='ederp_text_'.substr(hash('sha256',$db->table('contratos').':'.Coligadas::current()),0,32);
        if((int)($db->row('SELECT GET_LOCK(%s,5) AS acquired',[$name])['acquired']??0)!==1)throw new RuleViolation('Outro texto de contrato está sendo atualizado. Tente novamente.');
        try{return $work();}finally{$db->row('SELECT RELEASE_LOCK(%s) AS released',[$name]);}
    }
}
