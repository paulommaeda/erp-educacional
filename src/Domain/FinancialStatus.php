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
        $title['status_liquidacao']=$title['status'];$title['status']=self::value($title);return $title;
    }
}
