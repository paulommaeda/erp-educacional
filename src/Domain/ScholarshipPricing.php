<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
/** Integer cents. Each granted rule retains its pricing basis for future recalculations. */
final class ScholarshipPricing
{
    private static function percent(int $base,int $basisPoints,bool $round):int
    {
        return intdiv($base,10000)*$basisPoints+intdiv(($base%10000)*$basisPoints+($round?5000:0),10000);
    }
    public static function quote(int $base,int $manual,int $punctuality,array $rules):array
    {
        if(min($base,$manual,$punctuality)<0||$base>Money::MAX-$punctuality||$manual>$base)throw new RuleViolation('Valores da mensalidade ou dos descontos inválidos.');
        $face=$base+$punctuality;$percent=0;$fixed=0;$rows=[];$newRules=false;$newPercent=[];
        $groups=['base'=>['points'=>0,'floor'=>0,'remainders'=>[]],'original'=>['points'=>0,'floor'=>0,'remainders'=>[]]];
        foreach($rules as $rule){
            $value=Money::positive($rule['valor']);$isNew=($rule['base_percentual']??'')==='valor_original';$newRules=$newRules||$isNew;$index=count($rows);
            $row=['idconcessao'=>(string)($rule['idconcessao']??0),'idbolsa'=>(string)($rule['idbolsa']??0),'nome'=>$rule['nome'],'descricao'=>$rule['descricao']??null,'tipo'=>'bolsa','forma_calculo'=>$rule['forma_calculo'],'valor_regra'=>$rule['valor'],'valor'=>'0.00','pontualidade_isenta'=>'0.00'];
            if($rule['forma_calculo']==='percentual'){
                if($value>10000||$percent>10000-$value)throw new RuleViolation('A soma das bolsas percentuais não pode superar 100%.');$percent+=$value;
                $group=$isNew?'original':'base';$source=$isNew?$face:$base;$groups[$group]['points']+=$value;$amount=self::percent($source,$value,false);$groups[$group]['floor']+=$amount;$groups[$group]['remainders'][$index]=($source%10000)*$value%10000;
                if($isNew)$newPercent[]=$index;
            }elseif($rule['forma_calculo']==='valor'){
                if($fixed>Money::MAX-$value)throw new RuleViolation('Soma das bolsas acima do limite monetário.');$fixed+=$value;$amount=$value;
            }else throw new RuleViolation('Forma de cálculo da bolsa inválida.');
            $row['valor']=Money::format($amount);$rows[]=$row;
        }
        foreach($groups as $name=>$group){$amount=self::percent($name==='original'?$face:$base,$group['points'],true);$extra=$amount-$group['floor'];arsort($group['remainders'],SORT_NUMERIC);foreach($group['remainders'] as $index=>$unused){if($extra<=0)break;$rows[$index]['valor']=Money::format(Money::cents($rows[$index]['valor'])+1);$extra--;}}
        $scholarship=0;foreach($rows as &$row){$row['valor_calculado']=$row['valor'];$row['composicao_pontualidade']='0.00';$scholarship+=Money::cents($row['valor']);}unset($row);
        // A percentage defines the total benefit for on-time payment. Punctuality composes that benefit once.
        if($newPercent){
            if($scholarship<$punctuality)throw new RuleViolation('A bolsa percentual não pode aumentar a parcela. Confira o percentual e a pontualidade.');
            $offset=$punctuality;$order=[...$newPercent,...array_values(array_diff(array_keys($rows),$newPercent))];
            foreach($order as $index){$amount=Money::cents($rows[$index]['valor']);$part=min($offset,$amount);$rows[$index]['valor']=Money::format($amount-$part);$rows[$index]['composicao_pontualidade']=Money::format($part);$offset-=$part;if(!$offset)break;}
            $scholarship-=$punctuality;
        }
        if($scholarship>$face-$manual)throw new RuleViolation('A soma das bolsas e dos descontos supera o valor da parcela.');
        $net=$face-$manual-$scholarship;
        if(($newPercent||!$newRules)&&$net<$punctuality)throw new RuleViolation('A soma das bolsas e dos descontos supera a base de cálculo.');
        if($net===$punctuality&&$punctuality>0&&($newPercent||!$newRules)){
            if(!$rows)$rows[]=['idconcessao'=>'0','idbolsa'=>'0','nome'=>'Isenção de pontualidade','descricao'=>'Mensalidade integralmente coberta por bolsas e descontos.','tipo'=>'bolsa','forma_calculo'=>'valor','valor_regra'=>'0.00','valor'=>'0.00','pontualidade_isenta'=>'0.00'];
            $last=count($rows)-1;$rows[$last]['valor']=Money::format(Money::cents($rows[$last]['valor'])+$punctuality);$rows[$last]['pontualidade_isenta']=Money::format($punctuality);$scholarship+=$punctuality;$net=0;
        }
        return ['original'=>$face,'desconto'=>$manual+$scholarship,'bolsa'=>$scholarship,'liquido'=>$net,'com_pontualidade'=>max(0,$net-$punctuality),'aplicacoes'=>$rows];
    }
}
