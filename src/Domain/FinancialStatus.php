<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class FinancialStatus
{
    public static function value(array $title):string
    {
        if($title['status']==='cancelado')return 'cancelado';
        if(Money::cents($title['saldo_aberto'])===0)return 'baixado';
        return $title['vencimento']<Input::today()?'vencido':'em_aberto';
    }
    public static function present(array $title):array
    {
        $available=\EducacionalERP\Application\ContractDiscounts::conditional($title);$applied=Money::cents($title['desconto_condicional_aplicado']??'0.00');
        $title['valor_liquido_contratual']=$title['valor_liquido'];
        $title['desconto_condicional']=Money::format($available+$applied);$title['saldo_a_pagar']=Money::format(max(0,Money::cents($title['saldo_aberto'])-$available));
        $title['valor_liquido_sem_pontualidade']=$title['valor_liquido'];
        $title['valor_com_pontualidade']=Money::format(max(0,Money::cents($title['valor_liquido'])-$applied-$available));
        $title['status_liquidacao']=$title['status'];$title['status']=self::value($title);return $title;
    }
}
