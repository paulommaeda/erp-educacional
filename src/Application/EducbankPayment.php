<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Input,Money,RuleViolation};
use EducacionalERP\Infrastructure\Educbank\Client;

/** Provider integers are cents. No float, net remittance or current ERP discount is used as cash received. */
final class EducbankPayment
{
    public static function cents(array $data,string $key,?int $default=null):int
    {
        $value=$data[$key]??$default;
        if(is_string($value)&&preg_match('/^\d{1,15}$/D',$value))$value=(int)$value;
        if(!is_int($value)||$value<0||$value>Money::MAX)throw new RuleViolation('Educbank não informou '.$key.' como centavos inteiros válidos.');
        return $value;
    }
    public static function date(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/D',$value))throw new RuleViolation('Educbank não informou uma data de pagamento válida.');
        $date=Input::date(substr($value,0,10));if($date>Input::today())throw new RuleViolation('Educbank informou pagamento em data futura. Confira o retorno.');return $date;
    }
    public static function event(array $d):array
    {
        $id=Client::uuid($d['InvoiceId']??$d['invoiceId']??null);$external=$d['ExternalId']??$d['externalId']??null;
        if(!is_string($external)||$external===''||strlen($external)>80)throw new RuleViolation('ExternalId ausente ou inválido no retorno Educbank.');
        $state=$d['Status']??$d['status']??null;
        if(!in_array($state,['Paid','Pending','Expired','Canceled','Generated','Overdue'],true))throw new RuleViolation('Status do retorno Educbank inválido.');
        $out=['InvoiceId'=>$id,'ExternalId'=>$external,'Status'=>$state];
        foreach(['PaidMethod','PaymentDate','TotalDiscountCents','TotalFineCents','TotalPaidCents'] as $key){$camel=lcfirst($key);$value=$d[$key]??$d[$camel]??null;if($value===null)continue;if(str_ends_with($key,'Cents'))$out[$key]=self::cents([$key=>$value],$key);elseif(is_string($value)&&strlen($value)<=80)$out[$key]=$value;else throw new RuleViolation('Campo inválido no retorno: '.$key);}
        return $out;
    }
    public static function fromInvoice(array $r):array
    {
        $out=['InvoiceId'=>Client::uuid($r['id']??null),'ExternalId'=>$r['externalId']??null,'Status'=>$r['state']??null];
        foreach(['paidCents'=>'TotalPaidCents','paidDate'=>'PaymentDate','paidMethod'=>'PaidMethod','totalDiscountCents'=>'TotalDiscountCents','totalFineCents'=>'TotalFineCents'] as $from=>$to)if(isset($r[$from]))$out[$to]=$r[$from];
        return self::event($out);
    }
    public static function parse(array $r,array $title):array
    {
        if(($r['state']??'')!=='Paid')throw new RuleViolation('A fatura ainda não está paga no Educbank.');
        $paid=self::cents($r,'paidCents');$discount=self::cents($r,'totalDiscountCents');$fixed=self::cents($r,'totalFixedDiscountCents');$charges=self::cents($r,'totalFineCents',0);
        $original=Money::cents($title['valor_original']);$localFixed=Money::cents($title['desconto_incondicional']);$net=Money::cents($title['valor_liquido']);
        $items=$r['items']??null;
        if(!is_array($items)||count($items)!==1||self::cents($items[0],'quantity')!==1||self::cents($items[0],'valueCents')!==$original||self::cents($items[0],'fixedDiscountCents',0)!==$localFixed||$fixed!==$localFixed||$net!==$original-$localFixed)throw new RuleViolation('Os valores ou descontos fixos da fatura diferem do lançamento. Confira antes de baixar.');
        $conditional=$net+$charges-$paid;
        if($conditional<0||$conditional>$net)throw new RuleViolation('Valor pago e encargos não fecham com o valor líquido da parcela.');
        $date=self::date($r['paidDate']??null);
        // Some gateways expose punctuality separately. Accept that shape only when the original offer proves it.
        if($discount!==$fixed+$conditional&&($discount!==$conditional||($conditional>0&&!self::offered($r,$conditional,$date))))throw new RuleViolation('Os descontos retornados não fecham com o valor efetivamente pago.');
        [$interest,$fine]=self::charges($r,$charges);
        $methodKey=strtolower(preg_replace('/[^a-z]/i','',(string)($r['paidMethod']??'')));
        $method=['bankslip'=>'boleto','creditcard'=>'cartao','debitcard'=>'cartao','pix'=>'pix','deposit'=>'deposito','school'=>'escola','multiple'=>'multiplo'][$methodKey]??null;
        if($methodKey==='financialagreement')throw new RuleViolation('Liquidação por acordo financeiro exige conciliação do acordo. Não foi presumido recebimento em dinheiro.');
        if(!$method)throw new RuleViolation('Forma de pagamento do Educbank ausente ou não reconhecida.');
        return ['valor_pago'=>Money::format($paid),'desconto_condicional'=>Money::format($conditional),'juros'=>Money::format($interest),'multa'=>Money::format($fine),'data_pagamento'=>$date,'forma_pagamento'=>$method];
    }
    private static function offered(array $r,int $conditional,string $date):bool
    {
        $offers=is_array($r['earlyPaymentDiscounts']??null)?$r['earlyPaymentDiscounts']:[];
        foreach(['earlyPaymentDiscount','earlyPaymentDiscountApplicable'] as $key)if(is_array($r[$key]??null))$offers[]=$r[$key];
        $due=Input::date(substr((string)($r['dueDate']??''),0,10));
        foreach($offers as $o){if(!is_array($o)||!isset($o['valueCents']))continue;$days=$o['days']??null;if(!is_int($days)||$days<0||$days>30)continue;if(self::cents($o,'valueCents')===$conditional&&$date<=(new \DateTimeImmutable($due))->modify('-'.$days.' days')->format('Y-m-d'))return true;}
        return false;
    }
    private static function charges(array $r,int $total):array
    {
        if($total===0)return [0,0];$matches=[];
        foreach(['currentFineAndCharges','zeroDefaultFineAndCharges'] as $key){$c=$r['fineAndCharges'][$key]??null;if(!is_array($c)||!isset($c['fineInCents'],$c['interestInCents']))continue;$fine=self::cents($c,'fineInCents');$interest=self::cents($c,'interestInCents');if($fine+$interest===$total)$matches[$interest.'|'.$fine]=[$interest,$fine];}
        if(count($matches)!==1)throw new RuleViolation('Educbank não detalhou juros e multa de forma consistente com o total pago. Confira os encargos.');
        return array_values($matches)[0];
    }
}
