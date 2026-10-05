<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
use EducacionalERP\Application\Coligadas;
use EducacionalERP\Domain\RuleViolation;
/** Ownership is inherited from parents and is immutable for historical records. */
final class Ownership
{
    public static function apply(Database $db,string $table,array $data,?array $before=null):array
    {
        $meta=$db->schema()[$table];if($table==='coligadas'||!isset($meta['columns']['codcoligada']))return $data;
        $whole=$before?array_merge($before,$data):$data;$owners=[];
        // Only composite ownership FKs express same-company references.
        foreach($meta['fks'] as $fk){if(count($fk['columns'])<2||$fk['columns'][0]!=='codcoligada')continue;
            $column=$fk['columns'][1];if(empty($whole[$column]))continue;
            $parent=$db->get($fk['target'],(int)$whole[$column]);$owners[(int)$parent['codcoligada']]=true;
        }
        if(count($owners)>1)throw new RuleViolation('Os registros vinculados devem pertencer à mesma coligada.');
        $inherited=$owners?(int)array_key_first($owners):null;
        $id=(int)($whole['codcoligada']??$inherited??Coligadas::current());
        if(!$id||($inherited&&$inherited!==$id))throw new RuleViolation('Vínculo incompatível com a coligada do registro.');
        if($before&&$id!==(int)$before['codcoligada'])throw new RuleViolation('Não é permitido mudar a coligada de registros existentes. Use a rematrícula entre coligadas.');
        $company=$db->get('coligadas',$id);if(!$before&&!(int)$company['ativo'])throw new RuleViolation('Coligada inativa.');
        $data['codcoligada']=$id;return $data;
    }
}
