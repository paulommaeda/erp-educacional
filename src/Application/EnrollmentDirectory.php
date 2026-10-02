<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
final class EnrollmentDirectory
{
    public function __construct(private Store $db) {}
    public function search(array $data):array
    {
        $m=$this->db->table('matriculas');$a=$this->db->table('alunos');$p=$this->db->table('pessoas');$t=$this->db->table('turmas');$c=$this->db->table('cursos');$u=$this->db->table('turnos');$l=$this->db->table('periodos_letivos');
        $joins=" FROM $m m JOIN $a a ON a.idaluno=m.idaluno JOIN $p p ON p.codpessoa=a.codpessoa JOIN $t t ON t.idturma=m.idturma_atual JOIN $c c ON c.idcurso=m.idcurso JOIN $u u ON u.idturno=t.idturno JOIN $l l ON l.codperiodo=m.codperiodo";
        $where=['1=1'];$args=[];$period=SchoolSettings::resolve($data['codperiodo']??null);
        if($period){$where[]='m.codperiodo=%d';$args[]=$period;}
        foreach(['idturma'=>'m.idturma_atual','idcurso'=>'m.idcurso','idturno'=>'t.idturno'] as $key=>$col)if(!empty($data[$key])){$where[]="$col=%d";$args[]=Input::id($data[$key]);}
        foreach(['status'=>'m.status','tipo_aluno'=>'a.tipo_aluno','origem'=>'m.origem'] as $key=>$col)if(!empty($data[$key])){$where[]="$col=%s";$args[]=Input::text($data[$key],30);}
        $q=trim((string)($data['search']??''));if($q!==''){$term='%'.addcslashes($q,'_%\\').'%';$where[]='(p.nome LIKE %s OR a.ra LIKE %s)';$args[]=$term;$args[]=$term;}
        foreach(['data_inicio'=>'>=','data_fim'=>'<='] as $key=>$op)if(!empty($data[$key])){$where[]="m.data_matricula $op %s";$args[]=Input::date($data[$key]);}
        if(!empty($data['data_inicio'])&&!empty($data['data_fim'])&&$data['data_inicio']>$data['data_fim'])throw new RuleViolation('A data inicial deve ser anterior à final.');
        $joins.=' WHERE '.implode(' AND ',$where);$page=max(1,min(100000,(int)($data['page']??1)));
        $total=(int)$this->db->row('SELECT COUNT(*) AS n'.$joins,$args)['n'];
        $rows=$this->db->rows('SELECT m.idmatricula,m.idaluno,m.versao,m.codperiodo,a.ra,p.nome AS aluno,t.nome AS turma,u.nome AS turno,c.nome AS curso,l.codigo AS periodo,m.status,a.tipo_aluno,m.data_matricula,m.origem'.$joins.' ORDER BY p.nome,m.idmatricula LIMIT 20 OFFSET %d',[...$args,($page-1)*20]);
        return ['items'=>$rows,'total'=>$total,'page'=>$page,'codperiodo'=>$period];
    }
}
