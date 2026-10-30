<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Application\ReferralDiscounts;
final class DiscountCron
{
    public const HOOK='ederp_discount_referrals';
    public static function register(Database $db):void
    {
        add_filter('cron_schedules',static function($s){$s['ederp_discount_minute']=['interval'=>60,'display'=>'ERP: indicações a cada minuto'];return $s;});
        add_action('init',static function(){if(get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error')&&!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+30,'ederp_discount_minute',self::HOOK);});
        add_action(self::HOOK,static function()use($db){if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return;try{(new ReferralDiscounts($db))->process(10);}catch(\Throwable $e){/* Durable pending records are retried on the next tick. */}});
    }
}
