<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Educbank;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Application\{EducbankIntegration,EducbankReturns};
final class Cron
{
    public const HOOK='ederp_educbank_sync';
    public const RETURNS_HOOK='ederp_educbank_returns';
    public static function register(Database $db):void
    {
        add_filter('cron_schedules',static function($s){$s['ederp_five_minutes']=['interval'=>300,'display'=>'ERP: a cada cinco minutos'];$s['ederp_one_minute']=['interval'=>60,'display'=>'ERP: retorno de pagamentos a cada minuto'];return $s;});
        add_action('init',static function(){if(get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error')){if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'ederp_five_minutes',self::HOOK);if(!wp_next_scheduled(self::RETURNS_HOOK))wp_schedule_event(time()+30,'ederp_one_minute',self::RETURNS_HOOK);}});
        add_action(self::HOOK,static function()use($db){
            if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return;
            $cursor=(int)get_option('ederp_educbank_cron_cursor',0);$rows=$db->rows('SELECT codcoligada FROM '.$db->table('coligadas').' WHERE ativo=1 AND codcoligada>%d ORDER BY codcoligada LIMIT 25',[$cursor]);$start=microtime(true);
            if(!$rows){$rows=$db->rows('SELECT codcoligada FROM '.$db->table('coligadas').' WHERE ativo=1 ORDER BY codcoligada LIMIT 25');update_option('ederp_educbank_cron_cursor',0,false);}
            foreach($rows as $r){if(microtime(true)-$start>25)break;$company=(int)$r['codcoligada'];try{(new EducbankIntegration($db))->process($company,3);}catch(\Throwable $e){/* Individual requests are logged in the durable queue; retry next cron. */}update_option('ederp_educbank_cron_cursor',$company,false);}
        });
        add_action(self::RETURNS_HOOK,static function()use($db){
            if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return;
            $cursor=(int)get_option('ederp_educbank_returns_cursor',0);$rows=$db->rows('SELECT codcoligada FROM '.$db->table('coligadas').' WHERE ativo=1 AND codcoligada>%d ORDER BY codcoligada LIMIT 25',[$cursor]);
            if(!$rows)$rows=$db->rows('SELECT codcoligada FROM '.$db->table('coligadas').' WHERE ativo=1 ORDER BY codcoligada LIMIT 25');
            $start=microtime(true);
            foreach($rows as $r){if(microtime(true)-$start>25)break;$company=(int)$r['codcoligada'];$returns=new EducbankReturns($db);
                try{$returns->process($company,3);}catch(\Throwable $e){}
                if(microtime(true)-$start<20)try{$returns->reconcile($company);}catch(\Throwable $e){}
                update_option('ederp_educbank_returns_cursor',$company,false);
            }
        });
    }
}
