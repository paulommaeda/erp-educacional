<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{FinancialStatus,Money,RuleViolation,Store};

final class ContractFinancialSummary
{
    public const COLUMNS=['Contrato e aluno','Período e turma','Condições','Venc.','Pontualidade','Descontos incondicionais','Assinado'];

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
            $data['parcelas'][]=array_intersect_key($title,array_flip(['numero','competencia','vencimento','valor_original','desconto_incondicional','valor_liquido','valor_baixa','saldo_aberto','descontos_detalhados']))
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
            l.desconto_incondicional,l.valor_liquido,l.valor_baixa,l.saldo_aberto,l.pontualidade_snapshot,p.idcontrato,p.numero,p.competencia
            FROM $parts p JOIN $titles l ON l.idparcela=p.idparcela AND l.codcoligada=p.codcoligada
            WHERE p.codcoligada=%d AND p.idcontrato IN ($slots) AND l.excluido_em IS NULL AND l.status<>'cancelado'
            ORDER BY p.idcontrato,p.numero,l.idlancamento",[Coligadas::current(),...$ids]);
        $groups=[];
        foreach($rows as $row)$groups[$row['idcontrato']][]=$row;
        return $groups;
    }

    private function totals(array $contract,array $titles):array
    {
        $contract['valor_original_contrato']=$contract['valor_original_total'];
        $punctuality=$originals=$discounts=[];
        if($titles){
            $original=$net=$discount=0;
            foreach($titles as $title){
                $originals[]=$face=Money::cents((string)$title['valor_original']);
                $original+=$face;
                $net+=Money::cents((string)$title['valor_liquido']);
                $discounts[]=$deduction=Money::cents((string)$title['desconto_incondicional']);
                $discount+=$deduction;
                $punctuality[]=self::punctuality($title);
            }
            $contract['valor_original_total']=self::decimal($original);
            $contract['valor_liquido_total']=self::decimal($net);
            $contract['desconto_incondicional_total']=self::decimal($discount);
            $contract['primeiro_vencimento']=min(array_column($titles,'vencimento'));
        }elseif(!(int)$contract['parcelas_geradas']){
            $full=$this->db->get('contratos',(int)$contract['idcontrato']);
            try{
                foreach((new Scholarships($this->db))->plannedInstallments($full) as $part){
                    $originals[]=Money::cents($part['valor_original']);
                    $discounts[]=Money::cents($part['desconto_incondicional']);
                    $punctuality[]=self::punctuality($part+array_intersect_key($contract,array_flip(['pontualidade_snapshot','codcoligada'])));
                }
                $contract['desconto_incondicional_total']=self::decimal(array_sum($discounts));
                $contract['valor_liquido_total']=self::decimal(array_sum($originals)-array_sum($discounts));
            }catch(RuleViolation){
                // A pending contract with conflicting rules remains visible so it can be corrected.
            }
        }
        $contract['dia_vencimento']=$contract['primeiro_vencimento']?substr($contract['primeiro_vencimento'],8,2):null;
        foreach(['pontualidade'=>$punctuality,'original_parcela'=>$originals,'desconto_parcela'=>$discounts] as $field=>$amounts){
            $contract[$field.'_min']=$amounts?self::decimal(min($amounts)):null;
            $contract[$field.'_max']=$amounts?self::decimal(max($amounts)):null;
        }
        $contract['pontualidade_total']=$punctuality?self::decimal(array_sum($punctuality)):null;
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
        $range=static function(string $field)use($contract,$money):string{
            $min=$contract[$field.'_min'];$max=$contract[$field.'_max'];
            return $min===null?'—':($money($min).($min===$max?'':' a '.$money($max)));
        };
        return [
            [$contract['numero'],$contract['aluno'],'RA '.$contract['ra']],
            [$contract['periodo'],$contract['turma']],
            [$money($contract['valor_original_contrato']).' original',$contract['quantidade_parcelas'].' parcelas · '.$range('original_parcela').' original',
                $money($contract['valor_liquido_total']).' líquidos'],
            [$contract['dia_vencimento']??'—'],
            [$contract['pontualidade_total']===null?'—':($money($contract['pontualidade_total']).' total'),$range('pontualidade').' por parcela'],
            [$money($contract['desconto_incondicional_total']).' total',$range('desconto_parcela').' por parcela'],
            [$contract['assinado_em']?'Sim':'Não'],
        ];
    }
}
