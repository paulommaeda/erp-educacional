<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,RuleViolation,Input};
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy,UserPermissions};
final class UserService
{
    public function __construct(private Store $db){}
    private function access():void {if(!MenuPolicy::can('usuarios'))throw new RuleViolation('Sem acesso à gestão de usuários.');}
    private function target(int $id):\WP_User {
        $this->access();$user=get_userdata($id);
        if(!$user||(!Access::isAdmin()&&!UserPermissions::portalTarget($user)))throw new RuleViolation('Conta protegida ou não disponível para este perfil.');
        return $user;
    }
    private function version(\WP_User $u,?array $p):string {return hash_hmac('sha256',$u->ID.'|'.$u->user_email.'|'.$u->user_pass.'|'.($p['versao']??''),wp_salt('auth'));}
    public function listing(array $d):array {
        $this->access();$page=max(1,min(100000,(int)($d['page']??1)));$search=sanitize_text_field((string)($d['search']??''));
        $query=new \WP_User_Query(['number'=>20,'paged'=>$page,'orderby'=>'display_name','order'=>'ASC','search'=>$search!==''?'*'.$search.'*':'','search_columns'=>['user_login','user_email','display_name'],'fields'=>'all']);
        $items=[];foreach($query->get_results() as $u){
            // Read directory is available to authorized staff; protected accounts are never editable by them.
            $safe=UserPermissions::portalTarget($u);$link=$this->db->row('SELECT codpessoa FROM '.$this->db->table('pessoa_usuarios').' WHERE wp_user_id=%d',[(int)$u->ID]);$person=$link?$this->db->get('pessoas',(int)$link['codpessoa']):null;
            $items[]=['id'=>(int)$u->ID,'login'=>$u->user_login,'nome'=>$u->display_name,'email'=>$u->user_email,'perfis'=>array_map(fn($r)=>wp_roles()->roles[$r]['name']??$r,$u->roles),'codpessoa'=>$link['codpessoa']??null,'version'=>$this->version($u,$person),'editavel'=>Access::isAdmin()||$safe,'acessavel'=>$safe&&(int)$u->ID!==get_current_user_id()&&UserPermissions::can('switch')];
        }
        return ['items'=>$items,'total'=>(int)$query->get_total(),'page'=>$page,'permissions'=>array_map(fn($a)=>UserPermissions::can($a),array_combine(array_keys(UserPermissions::ACTIONS),array_keys(UserPermissions::ACTIONS))),'web357_disponivel'=>shortcode_exists('login_as_user')];
    }
    public function update(int $id,array $d,string $key):array {
        $user=$this->target($id);Input::key($key);
        foreach(array_keys($d) as $field)if(!in_array($field,['email','password','version'],true))throw new RuleViolation('Campo de conta não permitido.');
        $changes=['ID'=>$id];$audit=[];
        if(array_key_exists('email',$d)){
            if(!UserPermissions::can('email'))throw new RuleViolation('Sem permissão para alterar e-mail.');
            $email=trim((string)$d['email']);if(!is_email($email))throw new RuleViolation('Informe um e-mail válido.');
            $owner=email_exists($email);if($owner&&(int)$owner!==$id)throw new RuleViolation('E-mail já utilizado por outra conta.');
            $changes['user_email']=$email;$audit['email']=$email;
        }
        if(!empty($d['password'])){
            if(!UserPermissions::can('password'))throw new RuleViolation('Sem permissão para redefinir senha.');
            if(!is_string($d['password'])||strlen($d['password'])<12||strlen($d['password'])>4096)throw new RuleViolation('A nova senha deve ter entre 12 e 4096 caracteres.');
            if($id===get_current_user_id())throw new RuleViolation('Use a redefinição de senha no seu perfil para alterar sua própria senha.');
            $changes['user_pass']=$d['password'];$audit['senha_redefinida']=true;
        }
        if(!$audit)throw new RuleViolation('Informe e-mail ou nova senha.');
        return $this->db->atomic(function()use($id,$d,$changes,$audit,$key){
            $link=$this->db->row('SELECT codpessoa FROM '.$this->db->table('pessoa_usuarios').' WHERE wp_user_id=%d FOR UPDATE',[$id]);$person=$link?$this->db->get('pessoas',(int)$link['codpessoa'],true):null;
            clean_user_cache($id);$u=$this->target($id);
            if(!isset($d['version'])||!hash_equals($this->version($u,$person),(string)$d['version']))throw new RuleViolation('Conta alterada desde a consulta. Recarregue a lista.');
            $oldEmail=$u->user_email;$newEmail=$changes['user_email']??$oldEmail;
            $this->db->onCompletion(static function()use($id,$oldEmail,$newEmail){clean_user_cache($id);wp_cache_delete($oldEmail,'useremail');wp_cache_delete($newEmail,'useremail');});
            if($person&&isset($changes['user_email']))$this->db->update('pessoas',(int)$link['codpessoa'],['email'=>$changes['user_email']]);
            $result=wp_update_user($changes);if(is_wp_error($result))throw new RuleViolation($result->get_error_message());
            $this->db->audit('usuarios',$id,'editar_conta',['email'=>$oldEmail],$audit,$key);
            return ['salvo'=>true];
        });
    }
}
