<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
/** Inspect every declared relation, including existing constraints from restored backups. */
final class ForeignKeyIntegrity
{
    public function __construct(private Database $db){}
    public function scan():array
    {
        $issues=[];$schema=$this->db->schema();
        foreach($schema as $name=>$meta){
            $queries=[];$table=$this->db->table($name);$pk=$meta['pk'][0];
            foreach($meta['fks'] as $i=>$fk){
                $target=$this->db->table($fk['target']);$targetPk=$schema[$fk['target']]['pk'][0];$join=[];$present=[];
                foreach($fk['columns'] as $j=>$column){$reference=$fk['references'][$j];$join[]="c.`$column`=p.`$reference`";$present[]="c.`$column` IS NOT NULL";}
                // A composite FK with any null component has no required parent (MATCH SIMPLE).
                $queries[]="SELECT $i AS fk,COUNT(*) AS n,MIN(c.`$pk`) AS first_id FROM $table c LEFT JOIN $target p ON ".implode(' AND ',$join).' WHERE '.implode(' AND ',$present)." AND p.`$targetPk` IS NULL HAVING COUNT(*)>0";
            }
            if(!$queries)continue;
            foreach($this->db->rows(implode(' UNION ALL ',$queries)) as $row){$fk=$meta['fks'][(int)$row['fk']];$issues[]=['tabela'=>$name,'colunas'=>$fk['columns'],'destino'=>$fk['target'],'referencias'=>$fk['references'],'quantidade'=>(int)$row['n'],'primeiro_id'=>(string)$row['first_id']];}
        }
        return $issues;
    }
}
