<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class EnrollmentDueDate
{
    public static function validate(mixed $value):string
    {
        $date=Input::date($value);$today=Input::today();
        if($date<$today)throw new RuleViolation('O vencimento da primeira parcela não pode ser anterior a hoje ('.implode('/',array_reverse(explode('-',$today))).'). Selecione hoje ou uma data futura.');
        return $date;
    }
}
