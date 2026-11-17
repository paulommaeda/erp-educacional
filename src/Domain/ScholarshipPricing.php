<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
/** Scholarship percentages share the same tuition base; all arithmetic uses integer cents. */
final class ScholarshipPricing
{
    private static function percent(int $base,int $basisPoints,bool $round):int
    {
        return intdiv($base,10000)*$basisPoints+intdiv(($base%10000)*$basisPoints+($round?5000:0),10000);
    }
    public static function quote(int $base,int $manual,int $punctuality,array $rules):array
    {
        if(min($base,$manual,$punctuality)<0||$base>Money::MAX-$punctuality||$manual>$base)throw new RuleViolation('Valores da mensalidade ou dos descontos inválidos.');
        $percent=0;$fixed=0;$rows=[];$remainders=[];$floorTotal=0;
        foreach($rules as $rule){$value=Money::positive($rule['valor']);$row=['idconcessao'=>(string)($rule['idconcessao']??0),'idbolsa'=>(string)($rule['idbolsa']??0),'nome'=>$rule['nome'],'descricao'=>$rule['descricao']??null,'tipo'=>'bolsa','forma_calculo'=>$rule['forma_calculo'],'valor_regra'=>$rule['valor'],'valor'=>'0.00','pontualidade_isenta'=>'0.00'];$index=count($rows);
            if($rule['forma_calculo']==='percentual'){if($value>10000||$percent>10000-$value)throw new RuleViolation('A soma das bolsas percentuais não pode superar 100%.');$percent+=$value;$amount=self::percent($base,$value,false);$floorTotal+=$amount;$remainders[$index]=($base%10000)*$value%10000;}
            elseif($rule['forma_calculo']==='valor'){if($fixed>Money::MAX-$value)throw new RuleViolation('Soma das bolsas acima do limite monetário.');$fixed+=$value;$amount=$value;}
            else throw new RuleViolation('Forma de cálculo da bolsa inválida.');
            $row['valor']=Money::format($amount);$rows[]=$row;
        }
        $percentage=self::percent($base,$percent,true);arsort($remainders,SORT_NUMERIC);$extra=$percentage-$floorTotal;foreach($remainders as $index=>$unused){if($extra<=0)break;$rows[$index]['valor']=Money::format(Money::cents($rows[$index]['valor'])+1);$extra--;}
        if($fixed>$base-$manual||$percentage>$base-$manual-$fixed)throw new RuleViolation('A soma das bolsas e dos descontos supera a mensalidade-base.');
        $scholarship=$fixed+$percentage;$remaining=$base-$manual-$scholarship;
        if($remaining===0&&$punctuality>0){if(!$rows)$rows[]=['idconcessao'=>'0','idbolsa'=>'0','nome'=>'Isenção de pontualidade','descricao'=>'Mensalidade integralmente coberta por bolsas e descontos.','tipo'=>'bolsa','forma_calculo'=>'valor','valor_regra'=>'0.00','valor'=>'0.00','pontualidade_isenta'=>'0.00'];$last=count($rows)-1;$rows[$last]['valor']=Money::format(Money::cents($rows[$last]['valor'])+$punctuality);$rows[$last]['pontualidade_isenta']=Money::format($punctuality);$scholarship+=$punctuality;}
        return ['original'=>$base+$punctuality,'desconto'=>$manual+$scholarship,'bolsa'=>$scholarship,'liquido'=>$remaining===0?0:$remaining+$punctuality,'com_pontualidade'=>$remaining,'aplicacoes'=>$rows];
    }
}
