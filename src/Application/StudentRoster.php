<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{EnrollmentStatus,RuleViolation};

final class StudentRoster
{
    public const COLUMNS=['RA','Nome','Email','Turma','Período Letivo','Pai','Email Pai','Telefone Pai','Mãe','Email Mãe','Telefone Mãe','Outro Res','Telefone','Email'];
    public const STATUSES=EnrollmentStatus::LABELS+['ativa'=>'Ativa'];

    public static function statuses(mixed $value):array
    {
        if(!is_array($value)||count($value)>count(self::STATUSES))throw new RuleViolation('Situação de matrícula inválida.');
        foreach($value as $status){
            if(!is_string($status)||!isset(self::STATUSES[$status]))throw new RuleViolation('Situação de matrícula inválida.');
        }
        return array_values(array_intersect(array_keys(self::STATUSES),$value));
    }

    /** Keep each contact's name, email and phone aligned, even when there are several of the same type. */
    public static function lines(array $student,array $contacts):array
    {
        $fathers=array_values($contacts['pai']??[]);
        $mothers=array_values($contacts['mae']??[]);
        $others=array_values($contacts['outro']??[]);
        $lines=[];
        $count=max(1,count($fathers),count($mothers),count($others));
        for($i=0;$i<$count;$i++){
            $father=$fathers[$i]??[];
            $mother=$mothers[$i]??[];
            $other=$others[$i]??[];
            $lines[]=[
                ...($i===0?[$student['ra'],$student['nome'],$student['email']??'',$student['turma'],$student['periodo']]:['','','','','']),
                $father['nome']??'',$father['email']??'',$father['telefone']??'',
                $mother['nome']??'',$mother['email']??'',$mother['telefone']??'',
                $other['nome']??'',$other['telefone']??'',$other['email']??'',
            ];
        }
        return $lines;
    }
}
