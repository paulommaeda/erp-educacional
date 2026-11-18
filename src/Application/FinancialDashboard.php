<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Store,Input,Money,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;

/** Read-only receivables by installment competence, using current title values. */
final class FinancialDashboard
{
    private const MONEY=['previsto_bruto','previsto','realizado','recebido_total','encargos_recebidos','a_receber_bruto','a_receber_liquido','inadimplencia_bruta','inadimplencia_liquida','base_vencida_bruta','base_vencida_liquida'];
    public function __construct(private Store $db) {}

    private function scope(array $query):array
    {
        Access::requireFinancialDashboard();
        $company=Coligadas::current();$period=SchoolSettings::resolve($query['codperiodo']??null);
        $where=['l.codcoligada=%d','p.codcoligada=%d','c.codcoligada=%d','m.codcoligada=%d','a.codcoligada=%d','t.codcoligada=%d','l.excluido_em IS NULL','c.excluido_em IS NULL','m.excluido_em IS NULL'];$args=array_fill(0,6,$company);
        if($period){if((int)$this->db->get('periodos_letivos',$period)['codcoligada']!==$company)throw new RuleViolation('Selecione um período da coligada atual.');$where[]='m.codperiodo=%d';$args[]=$period;}
        $filters=['codperiodo'=>$period?:'todos','competencia_inicio'=>'','competencia_fim'=>'','idturma'=>'','idaluno'=>''];
        foreach(['competencia_inicio'=>'>=','competencia_fim'=>'<='] as $field=>$operator){
            $month=$query[$field]??'';
            if($month==='')continue;
            if(!is_string($month)||!preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D',$month))throw new RuleViolation('Informe uma competência válida no formato AAAA-MM.');
            $filters[$field]=$month;$where[]='p.competencia '.$operator.' %s';$args[]=$operator==='>='?$month.'-01':(new \DateTimeImmutable($month.'-01'))->modify('last day of this month')->format('Y-m-d');
        }
        if($filters['competencia_inicio']!==''&&$filters['competencia_fim']!==''&&$filters['competencia_inicio']>$filters['competencia_fim'])throw new RuleViolation('A competência inicial deve ser anterior à final.');
        foreach(['idturma'=>['turmas','m.idturma_atual'],'idaluno'=>['alunos','m.idaluno']] as $field=>[$table,$column]){
            if(($query[$field]??'')==='')continue;
            $id=Input::id($query[$field]);$row=$this->db->get($table,$id);
            if((int)$row['codcoligada']!==$company)throw new RuleViolation('Filtro de outra coligada.');
            if($field==='idturma'&&$period&&(int)$row['codperiodo']!==$period)throw new RuleViolation('Selecione uma turma do período consultado.');
            $filters[$field]=(string)$id;$where[]=$column.'=%d';$args[]=$id;
        }
        $from=' FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$this->db->table('contratos').' c ON c.idcontrato=p.idcontrato JOIN '.$this->db->table('matriculas').' m ON m.idmatricula=c.idmatricula JOIN '.$this->db->table('alunos').' a ON a.idaluno=m.idaluno JOIN '.$this->db->table('pessoas').' pe ON pe.codpessoa=a.codpessoa JOIN '.$this->db->table('turmas').' t ON t.idturma=m.idturma_atual';
        return [$from,implode(' AND ',$where),$args,$filters];
    }

    /** Dedicated lookups reveal only names/codes required by the dashboard. */
    public function options(array $query):array
    {
        Access::requireFinancialDashboard();$type=$query['tipo']??'turmas';
        if(!in_array($type,['turmas','alunos'],true))throw new RuleViolation('Filtro inválido.');
        unset($query[$type==='turmas'?'idturma':'idaluno']);
        [$from,$where,$args]=$this->scope($query);$page=max(1,min(100000,(int)($query['page']??1)));$search=$query['search']??'';
        if(!is_string($search)||strlen($search)>191)throw new RuleViolation('Busca inválida ou muito longa.');
        $id=$type==='turmas'?'t.idturma':'a.idaluno';$code=$type==='turmas'?'t.codigo':'a.ra';$name=$type==='turmas'?'t.nome':'pe.nome';
        if(trim($search)!==''){$where.=" AND ($code LIKE %s ESCAPE '!' OR $name LIKE %s ESCAPE '!')";$term='%'.strtr(trim($search),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$args[]=$term;$args[]=$term;}
        $rows=$this->db->rows("SELECT DISTINCT $id AS id,$code AS codigo,$name AS nome".$from.' WHERE '.$where." ORDER BY $name,$id LIMIT 21 OFFSET %d",[...$args,($page-1)*20]);
        $more=count($rows)>20;$rows=array_slice($rows,0,20);
        return ['items'=>array_map(fn($r)=>['id'=>(string)$r['id'],'label'=>$r['codigo'].' — '.$r['nome']],$rows),'page'=>$page,'more'=>$more];
    }

    public function read(array $query):array
    {
        [$from,$where,$args,$filters]=$this->scope($query);$today=Input::today();
        // Aggregate payments first: multiple payments never duplicate installment amounts.
        $payments='SELECT b.idlancamento,SUM(b.principal_liquidado) AS principal,SUM(b.valor_pago) AS recebido,SUM(b.juros_pagos+b.multa_paga) AS encargos FROM '.$this->db->table('baixas').' b WHERE b.codcoligada=%d AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('baixa_estornos').' e WHERE e.idbaixa=b.idbaixa) GROUP BY b.idlancamento';
        $configured="CAST(COALESCE(l.pontualidade_snapshot,%s) AS DECIMAL(15,2))";$base='CAST(l.valor_liquido AS DECIMAL(15,2))';
        $source="SELECT SUBSTR(p.competencia,1,7) AS competencia,l.vencimento,l.status,$base AS base,CAST(l.desconto_condicional_aplicado AS DECIMAL(15,2)) AS aplicado,CASE WHEN $configured>$base THEN $base ELSE $configured END AS pontualidade,COALESCE(b.principal,0) AS principal,COALESCE(b.recebido,0) AS recebido,COALESCE(b.encargos,0) AS encargos".$from.' LEFT JOIN ('.$payments.') b ON b.idlancamento=l.idlancamento WHERE '.$where;
        $fallback=(string)get_option(Coligadas::option('ederp_pontualidade'),'0.00');Money::cents($fallback);
        $positive=static fn(string $s):string=>"CASE WHEN ($s)>0 THEN ($s) ELSE 0 END";
        $gross=$positive('base-aplicado-principal');
        $maximum='CASE WHEN aplicado>pontualidade THEN aplicado ELSE pontualidade END';
        $expected="CASE WHEN base-($maximum)>principal THEN base-($maximum) ELSE principal END";
        $balances="SELECT s.*,CASE WHEN status='cancelado' THEN 0 ELSE $gross END AS aberto_bruto,CASE WHEN status='cancelado' THEN principal+aplicado ELSE base END AS previsto_bruto,CASE WHEN status='cancelado' THEN principal ELSE $expected END AS previsto FROM ($source) s";
        $net=$positive('aberto_bruto-('.$positive('pontualidade-aplicado').')');
        $projection="SELECT v.*,$net AS aberto_liquido FROM ($balances) v";
        $sql="SELECT competencia,COUNT(*) AS parcelas,SUM(CASE WHEN vencimento<%s AND aberto_bruto>0 THEN 1 ELSE 0 END) AS parcelas_vencidas";
        $expressions=['previsto_bruto'=>'previsto_bruto','previsto'=>'previsto','realizado'=>'principal','recebido_total'=>'recebido','encargos_recebidos'=>'encargos','a_receber_bruto'=>'aberto_bruto','a_receber_liquido'=>'aberto_liquido','inadimplencia_bruta'=>'CASE WHEN vencimento<%s THEN aberto_bruto ELSE 0 END','inadimplencia_liquida'=>'CASE WHEN vencimento<%s THEN aberto_liquido ELSE 0 END','base_vencida_bruta'=>'CASE WHEN vencimento<%s THEN previsto_bruto ELSE 0 END','base_vencida_liquida'=>'CASE WHEN vencimento<%s THEN previsto ELSE 0 END'];
        foreach($expressions as $name=>$expression)$sql.=",ROUND(SUM($expression),2) AS $name";
        $sql.=" FROM ($projection) d GROUP BY competencia ORDER BY competencia";
        $rows=$this->db->rows($sql,[$today,$today,$today,$today,$today,$fallback,$fallback,Coligadas::current(),...$args]);
        $totals=array_fill_keys(self::MONEY,0)+['parcelas'=>0,'parcelas_vencidas'=>0];$items=[];
        foreach($rows as $row){$values=[];foreach(self::MONEY as $key){$values[$key]=Money::cents((string)$row[$key]);$totals[$key]+=$values[$key];}foreach(['parcelas','parcelas_vencidas'] as $key){$values[$key]=(int)$row[$key];$totals[$key]+=$values[$key];}$items[]=['competencia'=>$row['competencia']]+$this->present($values);}
        return ['data_referencia'=>$today,'filtros'=>$filters,'totais'=>$this->present($totals),'competencias'=>$items];
    }

    private function present(array $values):array
    {
        $result=$values;
        foreach(self::MONEY as $key)$result[$key]=Money::format($values[$key]);
        foreach(['bruta','liquida'] as $basis)$result['taxa_inadimplencia_'.$basis]=$values['base_vencida_'.$basis]>0?number_format($values['inadimplencia_'.$basis]/$values['base_vencida_'.$basis]*100,2,'.',''):'0.00';
        return $result;
    }
}
