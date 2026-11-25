<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{RuleViolation,Store};

final class EnrollmentSignatures
{
    public function __construct(private Store $db) {}

    public function classes(int $period):array
    {
        $table=$this->db->table('turmas');
        return $this->db->rows("SELECT idturma,codigo,nome FROM $table
            WHERE codcoligada=%d AND codperiodo=%d ORDER BY nome,codigo,idturma",[Coligadas::current(),$period]);
    }

    public function report(int $period,int $class):array
    {
        $classes=$this->db->table('turmas');
        $periods=$this->db->table('periodos_letivos');
        $params=[Coligadas::current(),$period,$class];
        $report=$this->db->row("SELECT p.codigo AS periodo_codigo,p.descricao AS periodo,
                t.codigo AS turma_codigo,t.nome AS turma
            FROM $classes t JOIN $periods p ON p.codperiodo=t.codperiodo AND p.codcoligada=t.codcoligada
            WHERE t.codcoligada=%d AND t.codperiodo=%d AND t.idturma=%d",$params);
        if(!$report)throw new RuleViolation('Selecione uma turma do período letivo e da coligada atuais.');
        if(!str_contains($report['periodo'],$report['periodo_codigo'])){
            $report['periodo']=$report['periodo_codigo'].' · '.$report['periodo'];
        }

        $enrollments=$this->db->table('matriculas');
        $students=$this->db->table('alunos');
        $people=$this->db->table('pessoas');
        $report['alunos']=$this->db->rows("SELECT a.ra,p.nome
            FROM $enrollments m
            JOIN $students a ON a.idaluno=m.idaluno AND a.codcoligada=m.codcoligada
            JOIN $people p ON p.codpessoa=a.codpessoa
            WHERE m.codcoligada=%d AND m.codperiodo=%d AND m.idturma_atual=%d
                AND m.excluido_em IS NULL AND m.ativo_unico=1
                AND m.status NOT IN ('cancelada','transferencia_externa')
            ORDER BY p.nome,a.ra",$params);
        return $report;
    }
}
