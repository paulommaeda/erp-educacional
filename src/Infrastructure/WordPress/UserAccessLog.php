<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\Store;
use EducacionalERP\Infrastructure\Database\Installer;
/** Successful logins and returns with an existing session; no credentials or reset URLs. */
final class UserAccessLog
{
    public const LAST='_ederp_last_access';
    public function __construct(private Store $db){}
    private function ready():bool{return get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error');}
    public function record(int $user,string $type='login'):void
    {
        if(!$this->ready()||!in_array($type,['login','sessao'],true))return;
        $account=get_userdata($user);if(!$account)return;
        $now=gmdate('Y-m-d H:i:s');
        $this->db->insert('usuario_acessos',['wp_user_id'=>$user,'user_login_snapshot'=>$account->user_login,'nome_snapshot'=>$account->display_name,'tipo'=>$type,'ocorrido_em'=>$now]);
        update_user_meta($user,self::LAST,$now);
    }
    public function activity():void
    {
        if(!is_user_logged_in()||!$this->ready()||(function_exists('wp_doing_ajax')&&wp_doing_ajax())||(function_exists('wp_doing_cron')&&wp_doing_cron())||(defined('REST_REQUEST')&&REST_REQUEST))return;
        $id=get_current_user_id();$last=get_user_meta($id,self::LAST,true);$timestamp=is_string($last)&&$last!==''?strtotime($last.' UTC'):false;
        if(!$timestamp||time()-$timestamp>=1800)$this->record($id,'sessao');
        else update_user_meta($id,self::LAST,gmdate('Y-m-d H:i:s'));
    }
    public static function register(Store $db):void
    {
        $log=new self($db);
        add_action('wp_login',static function($login,$user)use($log){try{$log->record((int)$user->ID);}catch(\Throwable $ignored){/* A log failure must not prevent authentication. */}},10,2);
        $activity=static function()use($log){try{$log->activity();}catch(\Throwable $ignored){/* Keep the authenticated request available. */}};
        add_action('template_redirect',$activity,10);add_action('admin_init',$activity,40);
    }
    public static function label(?string $date):?string
    {
        if(!$date)return null;$timestamp=strtotime($date.' UTC');
        return $timestamp===false?null:(new \DateTimeImmutable('@'.$timestamp))->setTimezone(wp_timezone())->format('d/m/Y H:i:s');
    }
}
