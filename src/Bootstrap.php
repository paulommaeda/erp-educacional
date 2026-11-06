<?php
declare(strict_types=1);
namespace EducacionalERP;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts,SchemaMigration};
use EducacionalERP\Application\{Operations,AcademicService,CatalogService,FinanceService,ExportService,RenewalService,StudentWorkflow};
use EducacionalERP\Presentation\{Rest\Controller,Admin\Pages,Portal\Shortcodes};
final class Bootstrap
{
    public static function activate(bool $networkWide=false): void
    {
        if($networkWide) { wp_die('Ative o ERP individualmente em cada site. Ativação em rede não é suportada nesta versão.'); }
        global $wpdb; $previous=$wpdb->suppress_errors(true);
        try { SchemaMigration::run(new Database($wpdb)); }
        catch(\Throwable $e) { update_option('ederp_schema_error',$e->getMessage(),false); wp_die(esc_html('ERP não ativado: '.$e->getMessage())); }
        finally { $wpdb->suppress_errors($previous); }
        \EducacionalERP\Infrastructure\WordPress\PortalTheme::setup(true);
    }
    public static function deactivate():void {wp_clear_scheduled_hook(\EducacionalERP\Infrastructure\WordPress\AccountInvitations::HOOK);wp_clear_scheduled_hook(\EducacionalERP\Infrastructure\WordPress\DiscountCron::HOOK);wp_clear_scheduled_hook('ederp_cleanup_student_documents');wp_clear_scheduled_hook(\EducacionalERP\Infrastructure\Educbank\Cron::HOOK);wp_clear_scheduled_hook(\EducacionalERP\Infrastructure\Educbank\Cron::RETURNS_HOOK);}
    public function boot(): void
    {
        global $wpdb;
        \EducacionalERP\Infrastructure\WordPress\SchoolIdentity::register();
        \EducacionalERP\Infrastructure\WordPress\PortalTheme::register();
        $db=new Database($wpdb); $ops=new Operations($db); $access=new Access($db); $academic=new AcademicService($db,$ops);
        \EducacionalERP\Infrastructure\WordPress\AccountInvitations::register($db);
        \EducacionalERP\Infrastructure\Educbank\Cron::register($db);
        \EducacionalERP\Infrastructure\WordPress\DiscountCron::register($db);
        \EducacionalERP\Infrastructure\Educbank\Webhook::register($db);
        $accounts=new Accounts($db);$catalog=new CatalogService($db,$ops,$accounts);
        $controller=new Controller($db,$access,$academic,$catalog,new FinanceService($db,$ops),new ExportService($db),new RenewalService($db,$ops,$academic,$access),new StudentWorkflow($db,$ops,$catalog,$academic));
        add_action('rest_api_init',[$controller,'register']);
        $admissions=new \EducacionalERP\Presentation\Rest\AdmissionController($db,new \EducacionalERP\Application\PublicAdmissions($db,$catalog));add_action('rest_api_init',[$admissions,'register']);
        \EducacionalERP\Presentation\Portal\Admissions::register();
        $chat=new \EducacionalERP\Presentation\Rest\ChatController(new \EducacionalERP\Application\ChatService($db));add_action('rest_api_init',[$chat,'register']);
        add_filter('get_avatar_data', [new \EducacionalERP\Infrastructure\WordPress\Avatar($db),'filter'],10,2);
        add_action('ederp_cleanup_student_documents',static function()use($db){if(get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error'))(new \EducacionalERP\Application\StudentDocuments($db))->cleanupRejected();});
        add_action('admin_init',static function(){if(Access::isAdmin()&&!wp_next_scheduled('ederp_cleanup_student_documents'))wp_schedule_event(time()+300,'hourly','ederp_cleanup_student_documents');});
        $pages=new Pages(); add_action('admin_menu',[$pages,'register']);
        (new \EducacionalERP\Presentation\Portal\Portal())->register();
        // ZIP replacements do not activate again. New releases also retry an older saved failure once.
        add_action('admin_init',static function()use($db){
            if(current_user_can('manage_options') && SchemaMigration::shouldAttemptAutomatically()) {
                try { SchemaMigration::run($db); }
                catch(\Throwable $ignored) { /* The diagnostic is saved for the recovery screen. */ }
            }
        });
        add_action('admin_init',static function()use($db,$catalog){if(!Access::isAdmin()||get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return;try{(new \EducacionalERP\Application\ExternalPeopleApi($db,$catalog))->installAdmissionTemplates();delete_option('ederp_api_template_error');}catch(\Throwable $e){update_option('ederp_api_template_error','Não foi possível preparar o mapeamento do requerimento. Confira os campos adicionais ativos e a configuração da conexão.',false);}},30);
        // Existing records are reconciled in bounded batches; missing dates/logins remain visible as pending.
        add_action('admin_init',static function()use($db,$accounts){
            if(!Access::isAdmin() || get_option('ederp_schema_version')!==Installer::VERSION || get_option('ederp_schema_error')) { return; }
            $cursor=get_option('ederp_accounts_cursor',0);
            if($cursor==='done') { return; }
            try {
                $result=$accounts->migrate((int)$cursor,20);
                update_option('ederp_accounts_cursor',$result['next_cursor']===null?'done':$result['next_cursor'],false);
                $pending=array_values(array_filter($result['items'],fn($r)=>$r['status']==='pendente'));
                if($pending) { update_option('ederp_accounts_pending_notice',true,false); }
            } catch(\Throwable $e) { update_option('ederp_accounts_pending_notice',true,false); }
        });
        add_action('admin_notices',static function()use($db){
            if(Access::isAdmin() && get_option('ederp_accounts_pending_notice') && get_option('ederp_schema_version')===Installer::VERSION && !get_option('ederp_schema_error')) {
                $p=$db->table('pessoas');$u=$db->table('pessoa_usuarios');
                if(!$db->row("SELECT p.codpessoa FROM $p p LEFT JOIN $u u ON u.codpessoa=p.codpessoa WHERE u.wp_user_id IS NULL LIMIT 1")) { delete_option('ederp_accounts_pending_notice'); }
            }
            if(Access::isAdmin() && get_option('ederp_accounts_pending_notice')) { echo '<div class="notice notice-warning"><p>Algumas pessoas precisam de e-mail, data de nascimento ou nome de usuário. Abra ERP Educacional → Pessoas e edite os cadastros com conta pendente.</p></div>'; }
            if(current_user_can('erp_configurar') && (get_option('ederp_schema_version')!==Installer::VERSION || get_option('ederp_schema_error'))) { echo '<div class="notice notice-error"><p>O ERP requer migração. Acesse ERP Educacional → Configurações.</p></div>'; }
        });
        add_action('admin_post_ederp_migrate',static function()use($db){
            if(!current_user_can('erp_configurar') && !current_user_can('manage_options')) { wp_die('Sem permissão.'); }
            check_admin_referer('ederp_migrate');
            try { SchemaMigration::run($db); }
            catch(\Throwable $ignored) { /* Display the saved diagnostic after redirect. */ }
            wp_safe_redirect(admin_url('admin.php?page=ederp-config')); exit;
        });
        // Accounts can be deleted without touching historical ERP people or balances.
        add_action('deleted_user',static function($userId)use($db){
            if(get_option('ederp_schema_version')===Installer::VERSION) { $db->query('DELETE FROM '.$db->table('pessoa_usuarios').' WHERE wp_user_id=%d',[(int)$userId]); }
        });
    }
}
