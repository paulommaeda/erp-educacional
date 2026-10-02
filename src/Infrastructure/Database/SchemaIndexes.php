<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
/** Preserve existing names: positional names changed meaning across releases. */
final class SchemaIndexes
{
    private static function existing(array $rows):array
    {
        $out=[];
        foreach($rows as $r){$name=$r['Key_name'];$out[$name]['unique']=!(int)$r['Non_unique'];$out[$name]['columns'][(int)$r['Seq_in_index']]=$r['Column_name'];$out[$name]['usable']=($out[$name]['usable']??true)&&empty($r['Sub_part'])&&strtoupper($r['Index_type']??'BTREE')==='BTREE';}
        foreach($out as &$index){ksort($index['columns']);$index['columns']=array_values($index['columns']);}unset($index);
        return $out;
    }
    private static function required(array $meta):array
    {
        $required=[];
        foreach($meta['unique'] as $cols)$required['u:'.implode(',',$cols)]=['unique'=>true,'columns'=>$cols];
        foreach(array_merge($meta['indexes'],array_column($meta['fks'],'columns')) as $cols)$required['i:'.implode(',',$cols)]=['unique'=>false,'columns'=>$cols];
        return $required;
    }
    public static function lines(array $meta,array $rows):array
    {
        $existing=self::existing($rows);$lines=[];
        foreach(self::required($meta) as $signature=>$wanted){$name=null;
            foreach($existing as $candidate=>$index)if($candidate!=='PRIMARY'&&$index['usable']&&$index['unique']===$wanted['unique']&&$index['columns']===$wanted['columns']){$name=$candidate;break;}
            if($name===null){$base=($wanted['unique']?'uq_':'ix_').substr(hash('sha256',$signature),0,24);$name=$base;$n=0;while(isset($existing[$name]))$name=$base.'_'.(++$n);$existing[$name]=$wanted+['usable'=>true];}
            $lines[]=($wanted['unique']?'UNIQUE KEY ':'KEY ').'`'.str_replace('`','``',$name).'` ('.implode(',',$wanted['columns']).')';
        }
        return $lines;
    }
    public static function verify(array $meta,array $rows):void
    {
        $existing=self::existing($rows);
        foreach(self::required($meta) as $wanted){$found=false;foreach($existing as $index)if($index['usable']&&$index['unique']===$wanted['unique']&&$index['columns']===$wanted['columns']){$found=true;break;}if(!$found)throw new \RuntimeException('Índice ausente após migração: '.implode(',',$wanted['columns']));}
    }
}
