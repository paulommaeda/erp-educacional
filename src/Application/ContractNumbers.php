<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,CadastroText};
final class ContractNumbers
{
    public static function forEnrollment(Store $db,int $enrollment,?int $own=null):string
    {
        $m=$db->get('matriculas',$enrollment);$a=$db->get('alunos',(int)$m['idaluno']);$p=$db->get('periodos_letivos',(int)$m['codperiodo']);$base=CadastroText::upper($a['ra'].'-'.$p['codigo']);$candidate=$base;$suffix=1;$table=$db->table('contratos');
        while($db->row("SELECT idcontrato FROM $table WHERE numero=%s".($own?' AND idcontrato<>%d':''),$own?[$candidate,$own]:[$candidate]))$candidate=$base.'-'.(++$suffix);
        return $candidate;
    }
}
