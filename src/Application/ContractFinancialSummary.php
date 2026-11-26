<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{FinancialStatus,Money,Store};

final class ContractFinancialSummary
{
    public const COLUMNS=['Contrato e aluno','Período e turma','Condições','Pontualidade','Descontos incondicionais','Assinado'];

    public function __construct(private Store $db) {}

    public function listing(array $contracts):array
    {
        $summaries=[];
        foreach(array_chunk($contracts,200) as $batch){
            $groups=$this->charges(array_column($batch,'idcontrato'));
            foreach($batch as $contract)$summaries[]=$this->totals($contract,$groups[$contract['idcontrato']]??[]);
        }
        return $summaries;
    }

    public function detail(array $row):array
    {
        $id=(int)$row['idcontrato'];
        $titles=$this->charges([$id])[$id]??[];
        $data=$this->totals($row,$titles);
        $discounts=new ContractDiscounts($this->db,new Operations($this->db));
        $data['parcelas']=[];
        $paid=$balance=0;
        foreach($discounts->decorate($titles) as $title){
            $paid+=Money::cents((string)$title['valor_baixa']);
            $balance+=Money::cents((string)$title['saldo_aberto']);
            $data['parcelas'][]=array_intersect_key($title,array_flip(['numero','vencimento','valor_original','desconto_incondicional','valor_liquido','valor_baixa','saldo_aberto','descontos_detalhados']))
                +['pontualidade'=>Money::format(self::punctuality($title)), 'situacao'=>FinancialStatus::value($title)];
        }
        $data['valor_pago']=self::decimal($paid);
        $data['saldo_aberto']=self::decimal($balance);
        $data['bolsas_aluno']=(new StudentScholarships($this->db))->forContract($id);
        if(!(int)$row['parcelas_geradas']){
            $rules=$this->db->table('contrato_descontos');
            $data['descontos_previstos']=$this->db->rows("SELECT nome,descricao,valor,parcela_inicio,parcela_fim FROM $rules
                WHERE idcontrato=%d AND ativo=1 AND habilitado=1 AND (tipo<>'indicacao' OR indicacao_ativa=1) ORDER BY iddesconto",[$id]);
            $grants=$this->db->table('contrato_bolsas');
            $data['bolsas_previstas']=$this->db->rows("SELECT nome_snapshot,descricao_snapshot,forma_calculo,valor,parcela_inicio,parcela_fim
                FROM $grants WHERE idcontrato=%d AND ativo=1 AND habilitado=1 ORDER BY idconcessao",[$id]);
        }
        return $data;
    }

    private function charges(array $ids):array
    {
        $titles=$this->db->table('lancamentos');
        $parts=$this->db->table('parcelas');
        $slots=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$this->db->rows("SELECT l.idlancamento,l.codcoligada,l.bolsas_json,l.status,l.vencimento,l.valor_original,
            l.desconto_incondicional,l.valor_liquido,l.valor_baixa,l.saldo_aberto,l.pontualidade_snapshot,p.idcontrato,p.numero
            FROM $parts p JOIN $titles l ON l.idparcela=p.idparcela AND l.codcoligada=p.codcoligada
            WHERE p.codcoligada=%d AND p.idcontrato IN ($slots) AND l.excluido_em IS NULL AND l.status<>'cancelado'
            ORDER BY p.idcontrato,p.numero,l.idlancamento",[Coligadas::current(),...$ids]);
        $groups=[];
        foreach($rows as $row)$groups[$row['idcontrato']][]=$row;
        return $groups;
    }

    private function totals(array $contract,array $titles):array
    {
        $punctuality=[];
        if($titles){
            $original=$net=$discount=0;
            foreach($titles as $title){
                $original+=Money::cents((string)$title['valor_original']);
                $net+=Money::cents((string)$title['valor_liquido']);
                $discount+=Money::cents((string)$title['desconto_incondicional']);
                $punctuality[]=self::punctuality($title);
            }
            $contract['valor_original_total']=self::decimal($original);
            $contract['valor_liquido_total']=self::decimal($net);
            $contract['desconto_incondicional_total']=self::decimal($discount);
            $contract['primeiro_vencimento']=min(array_column($titles,'vencimento'));
        }elseif(!(int)$contract['parcelas_geradas']){
            $punctuality[]=max(0,ContractDiscounts::punctuality($contract));
        }
        $contract['pontualidade_min']=Money::format($punctuality?min($punctuality):0);
        $contract['pontualidade_max']=Money::format($punctuality?max($punctuality):0);
        unset($contract['pontualidade_snapshot']);
        return $contract;
    }

    private static function punctuality(array $title):int
    {
        return min(max(0,ContractDiscounts::punctuality($title)),max(0,Money::cents((string)$title['valor_liquido'])));
    }

    private static function decimal(int $cents):string
    {
        $amount=abs($cents);
        return ($cents<0?'-':'').intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT);
    }

    public static function reportRow(array $contract):array
    {
        $money=static function($value):string{
            $value=(string)$value;
            [$whole,$fraction]=array_pad(explode('.',ltrim($value,'-'),2),2,'00');
            return (str_starts_with($value,'-')?'-':'').'R$ '.number_format((int)$whole,0,',','.').','.str_pad($fraction,2,'0');
        };
        $due=$contract['primeiro_vencimento'];
        $punctuality=$money($contract['pontualidade_min']);
        if($contract['pontualidade_max']!==$contract['pontualidade_min'])$punctuality.=' a '.$money($contract['pontualidade_max']);
        return [
            [$contract['numero'],$contract['aluno'],'RA '.$contract['ra']],
            [$contract['periodo'],$contract['turma']],
            [$money($contract['valor_liquido_total']).' líquidos',$contract['quantidade_parcelas'].' parcelas'.($due?' · '.implode('/',array_reverse(explode('-',$due))):'')],
            [$punctuality,'Por parcela'],
            [$money($contract['desconto_incondicional_total']),'Total do contrato'],
            [$contract['assinado_em']?'Sim':'Não'],
        ];
    }
}
