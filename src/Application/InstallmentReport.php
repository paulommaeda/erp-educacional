<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Input,Money,RuleViolation,Store};

final class InstallmentReport
{
    public const COLUMNS=['RA','Aluno','Parcela original','Desconto inc.','Pontualidade','Valor líquido (sem pontualidade)'];
    private const MONTHS=['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

    public function __construct(private Store $db) {}

    public static function filters(mixed $student,mixed $month):array
    {
        $student=$student==='todos'?null:Input::id($student);
        if($month==='todos')return [$student,null];
        if(!is_string($month)||!preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D',$month)){
            throw new RuleViolation('Selecione um mês de vencimento válido.');
        }
        return [$student,$month];
    }

    public function students():array
    {
        $students=$this->db->table('alunos');
        $people=$this->db->table('pessoas');
        return $this->db->rows("SELECT a.idaluno,a.ra,pe.nome FROM $students a
            JOIN $people pe ON pe.codpessoa=a.codpessoa
            WHERE a.codcoligada=%d ORDER BY pe.nome,a.ra,a.idaluno",[Coligadas::current()]);
    }

    public function months():array
    {
        return array_column($this->db->rows('SELECT DISTINCT SUBSTR(l.vencimento,1,7) AS mes'.$this->scope().' ORDER BY mes DESC',[Coligadas::current()]),'mes');
    }

    public static function monthLabel(?string $month):string
    {
        return $month===null?'Todos os meses':self::MONTHS[(int)substr($month,5)-1].'/'.substr($month,0,4);
    }

    public function sheets(?int $student,?string $month):array
    {
        $where=$this->scope();
        $args=[Coligadas::current()];
        if($student!==null){
            $a=$this->db->table('alunos');
            if(!$this->db->row("SELECT idaluno FROM $a WHERE idaluno=%d AND codcoligada=%d",[$student,Coligadas::current()])){
                throw new RuleViolation('Selecione um aluno da coligada atual.');
            }
            $where.=' AND a.idaluno=%d';
            $args[]=$student;
        }
        if($month!==null){
            $start=$month.'-01';
            $where.=' AND l.vencimento BETWEEN %s AND %s';
            array_push($args,$start,(new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'));
        }
        $rows=$this->db->rows('SELECT SUBSTR(l.vencimento,1,7) AS mes,a.idaluno,a.ra,pe.nome,l.codcoligada,
            l.valor_original,l.desconto_incondicional,l.pontualidade_snapshot,l.valor_liquido'.$where.
            ' ORDER BY mes DESC,pe.nome,a.ra,l.vencimento,l.idlancamento',$args);
        $groups=[];
        $students=[];
        foreach($rows as $row){
            $key=$row['mes'];
            $original=Money::cents((string)$row['valor_original']);
            $net=Money::cents((string)$row['valor_liquido']);
            $punctuality=min(ContractDiscounts::punctuality($row),max(0,$net));
            $groups[$key]??=['rows'=>[],'students'=>[],'original'=>0,'net'=>0];
            $groups[$key]['rows'][]=[
                $row['ra'],$row['nome'],self::currency($original),
                self::currency(Money::cents((string)$row['desconto_incondicional'])),self::currency($punctuality),self::currency($net),
            ];
            $groups[$key]['students'][$row['idaluno']]=true;
            $groups[$key]['original']+=$original;
            $groups[$key]['net']+=$net;
            $students[$row['idaluno']]=true;
        }
        if(!$groups)return [[
            'month'=>self::monthLabel($month),'rows'=>[],
            'totals'=>[self::totals($month===null?'Total geral':'Total do mês',0,0,0)],
        ]];

        $sheets=[];
        foreach($groups as $key=>$group){
            $sheets[]=[
                'month'=>self::monthLabel($key),'rows'=>$group['rows'],
                'totals'=>[self::totals('Total do mês',count($group['students']),$group['original'],$group['net'])],
            ];
        }
        if(count($sheets)>1){
            $sheets[array_key_last($sheets)]['totals'][]=self::totals(
                'Total geral',count($students),array_sum(array_column($groups,'original')),array_sum(array_column($groups,'net')),
            );
        }
        return $sheets;
    }

    private static function totals(string $label,int $students,int $original,int $net):array
    {
        return [
            'label'=>$label.' · '.$students.($students===1?' aluno':' alunos'),
            'original'=>self::currency($original),'net'=>self::currency($net),
        ];
    }

    private function scope():string
    {
        $titles=$this->db->table('lancamentos');
        $installments=$this->db->table('parcelas');
        $contracts=$this->db->table('contratos');
        $enrollments=$this->db->table('matriculas');
        $students=$this->db->table('alunos');
        $people=$this->db->table('pessoas');
        return " FROM $titles l
            JOIN $installments p ON p.idparcela=l.idparcela AND p.codcoligada=l.codcoligada
            JOIN $contracts c ON c.idcontrato=p.idcontrato AND c.codcoligada=l.codcoligada
            JOIN $enrollments m ON m.idmatricula=c.idmatricula AND m.codcoligada=l.codcoligada
            JOIN $students a ON a.idaluno=m.idaluno AND a.codcoligada=l.codcoligada
            JOIN $people pe ON pe.codpessoa=a.codpessoa
            WHERE l.codcoligada=%d AND l.excluido_em IS NULL AND c.excluido_em IS NULL AND m.excluido_em IS NULL
                AND l.status<>'cancelado'";
    }

    private static function currency(int $cents):string
    {
        $absolute=abs($cents);
        return ($cents<0?'-':'').'R$ '.number_format(intdiv($absolute,100),0,',','.').','.str_pad((string)($absolute%100),2,'0',STR_PAD_LEFT);
    }
}
