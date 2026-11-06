<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Domain\RuleViolation;
/** The caller's ERP transaction also covers the native WordPress account tables. */
final class AccountDeletion
{
    public function __construct(private Database $db){}
    public function remove(int $person):?int
    {
        Access::requireAdmin();$wp=$this->db->wp;$links=$this->db->table('pessoa_usuarios');
        $link=$this->db->row("SELECT * FROM $links WHERE codpessoa=%d FOR UPDATE",[$person]);if(!$link)return null;
        $id=(int)$link['wp_user_id'];if($id===get_current_user_id())throw new RuleViolation('Não é possível excluir sua própria conta pela exclusão de pessoa. Use outro administrador.');
        if(function_exists('is_multisite')&&is_multisite())throw new RuleViolation('Esta conta pertence a uma rede WordPress. A exclusão precisa ser feita pelo administrador da rede.');
        $lock='ederp_access_invite_'.$id;if((int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('A conta está processando um convite. Tente excluir novamente.');
        $this->db->onCompletion(fn()=>$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]));
        clean_user_cache($id);$user=get_userdata($id);
        if($user){
            if(!current_user_can('delete_user',$id))throw new RuleViolation('Seu perfil não permite excluir esta conta WordPress.');
            if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';
            $posts=$this->db->rows("SELECT ID FROM $wp->posts WHERE post_author=%d",[$id]);$bookmarks=$this->db->rows("SELECT link_id FROM $wp->links WHERE link_owner=%d",[$id]);
            $tables=[$wp->users,$wp->usermeta];if($posts)$tables[]=$wp->posts;if($bookmarks)$tables[]=$wp->links;
            foreach($tables as $table){$engine=$this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',[$table]);if(strtoupper($engine['ENGINE']??'')!=='INNODB')throw new RuleViolation('A exclusão conjunta exige tabelas WordPress InnoDB para reverter falhas sem perder dados.');}
            $login=$user->user_login;$email=$user->user_email;
            $this->db->onCompletion(static function()use($id,$login,$email,$posts,$bookmarks){clean_user_cache($id);wp_cache_delete($id,'user_meta');wp_cache_delete($login,'userlogins');wp_cache_delete($email,'useremail');foreach($posts as $row)clean_post_cache((int)$row['ID']);foreach($bookmarks as $row)clean_bookmark_cache((int)$row['link_id']);});
            if(!wp_delete_user($id,get_current_user_id()))throw new RuleViolation('Não foi possível excluir a conta WordPress. A pessoa foi mantida.');
            if($this->db->row("SELECT ID FROM $wp->users WHERE ID=%d",[$id])||$this->db->row("SELECT user_id FROM $wp->usermeta WHERE user_id=%d LIMIT 1",[$id])||$this->db->row("SELECT ID FROM $wp->posts WHERE post_author=%d LIMIT 1",[$id])||$this->db->row("SELECT link_id FROM $wp->links WHERE link_owner=%d LIMIT 1",[$id]))throw new RuleViolation('A exclusão da conta não concluiu. A operação foi revertida.');
        }
        $this->db->query("DELETE FROM $links WHERE codpessoa=%d",[$person]);return $id;
    }
}
