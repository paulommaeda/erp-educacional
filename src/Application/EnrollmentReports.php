<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\Store;

final class EnrollmentReports
{
    public function __construct(private Store $db) {}

    public function periods():array
    {
        $table=$this->db->table('periodos_letivos');
        $rows=$this->db->rows("SELECT codperiodo,codigo,descricao FROM $table
            WHERE codcoligada=%d ORDER BY data_inicio DESC,codperiodo DESC",[Coligadas::current()]);
        return array_column($rows,null,'codperiodo');
    }

    public function classes(int $period):array
    {
        $table=$this->db->table('turmas');
        $rows=$this->db->rows("SELECT idturma,codigo,nome FROM $table
            WHERE codcoligada=%d AND codperiodo=%d ORDER BY nome,codigo,idturma",[Coligadas::current(),$period]);
        return array_column($rows,null,'idturma');
    }

    public function signatures(array $period,array $classes,?int $class):array
    {
        $groups=[];
        foreach($this->enrollments((int)$period['codperiodo'],$class,[],true) as $student){
            $groups[$student['idturma']][]=$student;
        }

        $label=$period['descricao'];
        if(!str_contains($label,$period['codigo']))$label=$period['codigo'].' · '.$label;
        $sheets=[];
        foreach($class===null?$classes:[$class=>$classes[$class]] as $id=>$item){
            if($class!==null||isset($groups[$id])){
                $sheets[]=['periodo'=>$label,'turma'=>$item['nome'],'alunos'=>$groups[$id]??[]];
            }
        }
        return $sheets?:[['periodo'=>$label,'turma'=>'Todas','alunos'=>[]]];
    }

    public function students(int $period,?int $class,array $statuses):array
    {
        $rows=$this->enrollments($period,$class,$statuses,false);
        $contacts=$this->contacts(array_unique(array_map('intval',array_column($rows,'idaluno'))));
        foreach($rows as &$row){
            $row=StudentRoster::lines($row,$contacts[$row['idaluno']]??[]);
        }
        unset($row);
        return $rows;
    }

    private function enrollments(int $period,?int $class,array $statuses,bool $signatures):array
    {
        $enrollments=$this->db->table('matriculas');
        $students=$this->db->table('alunos');
        $people=$this->db->table('pessoas');
        $classes=$this->db->table('turmas');
        $periods=$this->db->table('periodos_letivos');
        $params=[Coligadas::current(),$period];
        $conditions=['m.codcoligada=%d','m.codperiodo=%d','m.excluido_em IS NULL'];
        if($class!==null){
            $conditions[]='m.idturma_atual=%d';
            $params[]=$class;
        }
        if($signatures){
            $conditions[]="m.ativo_unico=1 AND m.status NOT IN ('cancelada','transferencia_externa')";
        }elseif($statuses){
            $marks=implode(',',array_fill(0,count($statuses),'%s'));
            $conditions[]="m.status IN ($marks)";
            array_push($params,...$statuses);
        }
        $fields=$signatures?'':' ,p.email,t.nome AS turma,l.codigo AS periodo';
        $where=implode(' AND ',$conditions);
        return $this->db->rows("SELECT DISTINCT a.idaluno,t.idturma,a.ra,p.nome$fields
            FROM $enrollments m
            JOIN $students a ON a.idaluno=m.idaluno AND a.codcoligada=m.codcoligada
            JOIN $people p ON p.codpessoa=a.codpessoa
            JOIN $classes t ON t.idturma=m.idturma_atual AND t.codcoligada=m.codcoligada AND t.codperiodo=m.codperiodo
            JOIN $periods l ON l.codperiodo=m.codperiodo AND l.codcoligada=m.codcoligada
            WHERE $where ORDER BY p.nome,a.ra,t.idturma",$params);
    }

    private function contacts(array $ids):array
    {
        $links=$this->db->table('aluno_responsaveis');
        $people=$this->db->table('pessoas');
        $company=Coligadas::current();
        $now=gmdate('Y-m-d H:i:s');
        $contacts=[];
        foreach(array_chunk($ids,500) as $batch){
            $marks=implode(',',array_fill(0,count($batch),'%d'));
            $rows=$this->db->rows("SELECT v.idaluno,v.parentesco,r.codpessoa,r.nome,r.email,r.telefone
                FROM $links v JOIN $people r ON r.codpessoa=v.codpessoa_responsavel
                WHERE v.codcoligada=%d AND v.idaluno IN ($marks) AND r.ativo=1 AND v.parentesco<>'proprio_aluno'
                    AND v.inicio_vigencia<=%s AND (v.fim_vigencia IS NULL OR v.fim_vigencia>%s)
                ORDER BY v.idaluno,r.nome,v.idvinculo",[$company,...$batch,$now,$now]);
            foreach($rows as $row){
                $group=in_array($row['parentesco'],['pai','mae'],true)?$row['parentesco']:'outro';
                $contacts[$row['idaluno']][$group][$row['codpessoa']]=$row;
            }
        }
        return $contacts;
    }
}
