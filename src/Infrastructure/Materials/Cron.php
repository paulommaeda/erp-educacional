<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Materials;

use EducacionalERP\Application\MaterialsOutbox;
use EducacionalERP\Domain\Store;
use EducacionalERP\Infrastructure\WordPress\SchemaMigration;

final class Cron
{
    public const HOOK='ederp_k8_enviar_matriculas';
    public const INTERVAL='ederp_k8_cinco_minutos';

    public static function register(Store $db):void
    {
        add_filter('cron_schedules',static function(array $schedules):array{
            $schedules[self::INTERVAL]=['interval'=>300,'display'=>'ERP: envio de matrículas ao K8'];
            return $schedules;
        });
        add_action('init',static function(){
            if(!SchemaMigration::pending()&&!wp_next_scheduled(self::HOOK)){
                wp_schedule_event(time()+60,self::INTERVAL,self::HOOK);
            }
        });
        add_action(self::HOOK,static function()use($db){
            if(!SchemaMigration::pending())(new MaterialsOutbox($db))->process();
        });
    }
}
