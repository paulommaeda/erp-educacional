<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
/** One automatic recovery attempt per plugin release; manual retries remain available. */
final class SchemaMigration
{
    public static function pending():bool
    {
        return get_option('ederp_schema_version')!==Installer::VERSION || (bool)get_option('ederp_schema_error');
    }
    public static function release():string
    {
        return defined('EDERP_VERSION')?(string)EDERP_VERSION:'schema-'.Installer::VERSION.'-recovery-1';
    }
    public static function shouldAttemptAutomatically():bool
    {
        return self::pending() && (!get_option('ederp_schema_error') || get_option('ederp_schema_attempt_release')!==self::release());
    }
    public static function run(Database $db):void
    {
        update_option('ederp_schema_attempt_release',self::release(),false);
        $previous=$db->wp->suppress_errors(true);
        try { (new Installer($db))->install(); Access::install(); }
        catch(\Throwable $e) { update_option('ederp_schema_error',$e->getMessage(),false); throw $e; }
        finally { $db->wp->suppress_errors($previous); }
    }
}
