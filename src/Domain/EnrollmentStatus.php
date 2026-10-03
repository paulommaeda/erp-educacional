<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class EnrollmentStatus
{
    public const LABELS=['reservado'=>'Reservado','cursando'=>'Cursando','transferencia_externa'=>'Transferência externa','aprovado'=>'Aprovado','reprovado'=>'Reprovado','cancelada'=>'Cancelada'];
    public const OPEN=['reservado','cursando','ativa'];
    public static function open(string $status):bool {return in_array($status,self::OPEN,true);}
    public static function sql():string {return "('reservado','cursando','ativa')";}
}
