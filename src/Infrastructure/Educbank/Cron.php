<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Educbank;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Application\EducbankIntegration;
final class Cron
{
    public const HOOK='ederp_educbank_sync';
    public static function register(Database $db):void
    {
        add_filter('cron_schedules',static function($s){$s['ederp_five_minutes']=['interval'=>300,'display'=>'ERP: a cada cinco minutos'];return $s;});
        add_action('init',static function(){if(get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error')&&!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'ederp_five_minutes',self::HOOK);});
        add_action(self::HOOK,static function()use($db){
            if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return;
            $cursor=(int)get_option('ederp_educbank_cron_cursor',0);$rows=$db->rows('SELECT codcoligada FROM '.$db->table('coligadas').' WHERE ativo=1 AND codcoligada>%d ORDER BY codcoligada LIMIT 25',[$cursor]);$start=microtime(true);
            if(!$rows){update_option('ederp_educbank_cron_cursor',0,false);return;}
            foreach($rows as $r){if(microtime(true)-$start>25)break;$company=(int)$r['codcoligada'];try{(new EducbankIntegration($db))->process($company,3);}catch(\Throwable $e){/* Individual requests are logged in the durable queue; retry next cron. */}update_option('ederp_educbank_cron_cursor',$company,false);}
        });
    }
}
