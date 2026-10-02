<?php
declare(strict_types=1);
namespace EducacionalERP;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts};
use EducacionalERP\Application\{Operations,AcademicService,CatalogService,FinanceService,ExportService,RenewalService,StudentWorkflow};
use EducacionalERP\Presentation\{Rest\Controller,Admin\Pages,Portal\Shortcodes};
final class Bootstrap
{
    public static function activate(bool $networkWide=false): void
    {
        if($networkWide) { wp_die('Ative o ERP individualmente em cada site. Ativação em rede não é suportada nesta versão.'); }
        global $wpdb; $previous=$wpdb->suppress_errors(true);
        try { (new Installer(new Database($wpdb)))->install(); Access::install(); }
        catch(\Throwable $e) { update_option('ederp_schema_error',$e->getMessage(),false); wp_die(esc_html('ERP não ativado: '.$e->getMessage())); }
        finally { $wpdb->suppress_errors($previous); }
    }
    public function boot(): void
    {
        global $wpdb;
        $db=new Database($wpdb); $ops=new Operations($db); $access=new Access($db); $academic=new AcademicService($db,$ops);
        $accounts=new Accounts($db);$catalog=new CatalogService($db,$ops,$accounts);
        $controller=new Controller($db,$access,$academic,$catalog,new FinanceService($db,$ops),new ExportService($db),new RenewalService($db,$ops,$academic,$access),new StudentWorkflow($db,$ops,$catalog,$academic));
        add_action('rest_api_init',[$controller,'register']);
        add_filter('get_avatar_data', [new \EducacionalERP\Infrastructure\WordPress\Avatar($db),'filter'],10,2);
        $pages=new Pages(); add_action('admin_menu',[$pages,'register']);
        (new \EducacionalERP\Presentation\Portal\Portal())->register();
        // ZIP replacements do not run activation hooks. Apply pending additive migrations once.
        add_action('admin_init',static function()use($db){
            if(current_user_can('manage_options') && get_option('ederp_schema_version')!==Installer::VERSION && !get_option('ederp_schema_error')) {
                $previous=$db->wp->suppress_errors(true);
                try { (new Installer($db))->install(); Access::install(); }
                catch(\Throwable $e) { update_option('ederp_schema_error',$e->getMessage(),false); }
                finally { $db->wp->suppress_errors($previous); }
            }
        });
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
            if(Access::isAdmin() && get_option('ederp_accounts_pending_notice')) { echo '<div class="notice notice-warning"><p>Algumas pessoas precisam de data de nascimento ou nome de usuário. Abra ERP Educacional → Pessoas e edite os cadastros com conta pendente.</p></div>'; }
            if(current_user_can('erp_configurar') && (get_option('ederp_schema_version')!==Installer::VERSION || get_option('ederp_schema_error'))) { echo '<div class="notice notice-error"><p>O ERP requer migração. Acesse ERP Educacional → Configurações.</p></div>'; }
        });
        add_action('admin_post_ederp_migrate',static function()use($db){
            if(!current_user_can('erp_configurar') && !current_user_can('manage_options')) { wp_die('Sem permissão.'); }
            check_admin_referer('ederp_migrate');
            try { (new Installer($db))->install(); Access::install(); }
            catch(\Throwable $e) { update_option('ederp_schema_error',$e->getMessage(),false); }
            wp_safe_redirect(admin_url('admin.php?page=ederp-config')); exit;
        });
        // Accounts can be deleted without touching historical ERP people or balances.
        add_action('deleted_user',static function($userId)use($db){
            if(get_option('ederp_schema_version')===Installer::VERSION) { $db->query('DELETE FROM '.$db->table('pessoa_usuarios').' WHERE wp_user_id=%d',[(int)$userId]); }
        });
    }
}
