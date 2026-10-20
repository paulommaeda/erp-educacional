<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
use EducacionalERP\Infrastructure\WordPress\FamilyAccess;

/** Adds current school information only to the local student IDs authorized by Access::students(). */
final class FamilyOverview
{
    public function __construct(private Store $db){}

    public function decorate(array $students):array
    {
        $ids=[];
        foreach($students as $student)foreach($student['idalunos']??[$student['idaluno']] as $id)$ids[(int)$id]=(int)$id;
        if(!$ids)return [];
        $a=$this->db->table('alunos');$p=$this->db->table('pessoas');$periods=$this->db->table('periodos_letivos');
        $locals=[];$companies=[];
        foreach($this->db->rows("SELECT a.idaluno,a.codpessoa,a.codcoligada,a.ativo,a.ra,p.ativo AS pessoa_ativa,p.foto_attachment_id FROM $a a JOIN $p p ON p.codpessoa=a.codpessoa WHERE a.idaluno IN (".implode(',',array_fill(0,count($ids),'%d')).')',array_values($ids)) as $row){
            $locals[(int)$row['idaluno']]=$row;
            if((int)$row['ativo']&&(int)$row['pessoa_ativa'])$companies[(int)$row['codcoligada']][]=(int)$row['idaluno'];
        }
        $current=[];$classes=[];$m=$this->db->table('matriculas');$t=$this->db->table('turmas');
        foreach($companies as $company=>$localIds){
            $period=Coligadas::within($company,fn()=>SchoolSettings::current());
            if(!$period)continue;
            $info=$this->db->row("SELECT codigo,data_inicio,data_fim FROM $periods WHERE codperiodo=%d AND codcoligada=%d",[$period,$company]);
            if(!$info)continue;
            $current[$company]=$info;
            $rows=$this->db->rows("SELECT m.idaluno,t.nome FROM $m m JOIN $t t ON t.idturma=m.idturma_atual AND t.codcoligada=m.codcoligada AND t.codperiodo=m.codperiodo WHERE m.codcoligada=%d AND m.codperiodo=%d AND m.ativo_unico=1 AND m.status NOT IN ('cancelada','transferencia_externa') AND m.idaluno IN (".implode(',',array_fill(0,count($localIds),'%d')).') ORDER BY t.nome,t.idturma',[$company,$period,...$localIds]);
            foreach($rows as $row)$classes[(int)$row['idaluno']][]=$row['nome'];
        }
        $family=new FamilyAccess($this->db);
        foreach($students as &$student){
            $student['foto_url']=null;$student['pode_editar_contato']=false;$academic=[];
            foreach($student['idalunos']??[$student['idaluno']] as $id){
                $local=$locals[(int)$id]??null;
                if(!$local||(int)$local['codpessoa']!==(int)$student['codpessoa'])continue;
                if(!$student['foto_url']&&!empty($local['foto_attachment_id']))$student['foto_url']=wp_get_attachment_image_url((int)$local['foto_attachment_id'],'thumbnail')?:null;
                if(!$student['pode_editar_contato']&&$family->can((int)$id)){$student['pode_editar_contato']=true;$student['idaluno']=(string)$id;$student['codcoligada']=$local['codcoligada'];$student['ra']=$local['ra'];}
                if(!(int)$local['ativo']||!(int)$local['pessoa_ativa'])continue;
                $period=$current[(int)$local['codcoligada']]??null;if(!$period)continue;
                $key=implode('|',[$period['codigo'],$period['data_inicio'],$period['data_fim']]);
                if(!isset($academic[$key]))$academic[$key]=['periodo'=>$period['codigo'],'turmas'=>[]];
                $academic[$key]['turmas']=array_values(array_unique([...$academic[$key]['turmas'],...($classes[(int)$id]??[])]));
            }
            foreach($academic as &$entry)sort($entry['turmas'],SORT_NATURAL|SORT_FLAG_CASE);unset($entry);
            $student['academico_atual']=array_values($academic);
        }
        unset($student);
        return $students;
    }
}
