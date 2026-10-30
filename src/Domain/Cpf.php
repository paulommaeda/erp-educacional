<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class Cpf
{
    public static function valid(string $cpf):bool
    {
        if(!preg_match('/^\d{11}$/D',$cpf)||preg_match('/^(\d)\1{10}$/D',$cpf))return false;
        for($t=9;$t<11;$t++){$sum=0;for($i=0;$i<$t;$i++)$sum+=(int)$cpf[$i]*(($t+1)-$i);$digit=($sum*10)%11;if($digit===10)$digit=0;if((int)$cpf[$t]!==$digit)return false;}return true;
    }
}
