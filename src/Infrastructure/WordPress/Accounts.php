<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\{Store,Input,RuleViolation,Usernames,UsernameRequired};
/** WordPress accounts share the ERP connection/transaction; account tables must be InnoDB. */
final class Accounts
{
    public const MANAGED=['erp_professor','erp_pessoa','erp_aluno','erp_responsavel_academico','erp_responsavel_financeiro','erp_responsavel'];
    private bool $locked=false;
    public function __construct(private Store $db) {}
    private function lock(): void
    {
        if($this->locked) { return; }
        // WordPress does not enforce UNIQUE(user_login); serialize account provisioning by this plugin.
        $name='ederp_accounts_'.substr(hash('sha256',defined('DB_NAME')?DB_NAME:'tests'),0,32);
        $row=$this->db->row('SELECT GET_LOCK(%s, 10) AS acquired',[$name]);
        if((int)($row['acquired']??0)!==1) { throw new RuleViolation('Outro cadastro está criando uma conta. Tente novamente.'); }
        $this->locked=true;
        $this->db->onCompletion(function()use($name){$this->locked=false;$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$name]);});
    }
    private function cache(int $id): void
    {
        $u=get_userdata($id);$login=$u?$u->user_login:null;$email=$u->user_email??null;
        $this->db->onCompletion(static function()use($id,$login,$email){
            clean_user_cache($id);
            // After rollback the row may no longer exist, so invalidate captured lookup keys too.
            if(function_exists('wp_cache_delete')) {
                wp_cache_delete($id,'users');wp_cache_delete($id,'user_meta');
                if($login) { wp_cache_delete($login,'userlogins'); }
                if($email) { wp_cache_delete($email,'useremail'); }
            }
        });
    }
    public function ensure(int $personId,?string $manual=null): array
    {
        $person=$this->db->get('pessoas',$personId,true);$this->lock();
        $t=$this->db->table('pessoa_usuarios');
        $link=$this->db->row("SELECT * FROM $t WHERE codpessoa=%d FOR UPDATE",[$personId]);
        if($link) {
            $u=get_userdata((int)$link['wp_user_id']);
            if(!$u) { throw new RuleViolation('A conta vinculada não existe. Solicite ao administrador a correção do vínculo.'); }
            $this->sync($personId);
            return ['wp_user_id'=>(string)$u->ID,'user_login'=>$u->user_login];
        }
        if(empty($person['data_nascimento'])) { throw new RuleViolation('Informe a data de nascimento para criar a conta WordPress.'); }
        $dob=Input::date($person['data_nascimento']);
        if($dob>Input::today()) { throw new RuleViolation('Nascimento não pode estar no futuro.'); }
        $login=Usernames::choose($person['nome'],$manual,fn($name)=>(bool)username_exists($name));
        $email=$person['email']??'';
        // Family members may share contact e-mail. WordPress accounts require unique e-mail.
        if($email && email_exists($email)) { $email=''; }
        $parts=preg_split('/\s+/',trim($person['nome']));
        $id=wp_insert_user(['user_login'=>$login,'user_pass'=>(new \DateTimeImmutable($dob))->format('dmY'),'user_email'=>$email,
            'display_name'=>$person['nome'],'first_name'=>$parts[0],'last_name'=>count($parts)>1?implode(' ',array_slice($parts,1)):'','role'=>'erp_pessoa']);
        if(is_wp_error($id)) {
            if($id->get_error_code()==='existing_user_login') { throw new UsernameRequired('Esse nome de usuário acabou de ser utilizado. Escolha outro.'); }
            throw new RuleViolation('Não foi possível criar a conta WordPress: '.$id->get_error_message());
        }
        $this->cache((int)$id);
        $this->db->insert('pessoa_usuarios',['codpessoa'=>$personId,'wp_user_id'=>$id]);
        $this->sync($personId);
        return ['wp_user_id'=>(string)$id,'user_login'=>$login];
    }
    public function sync(int $personId): void
    {
        $t=$this->db->table('pessoa_usuarios');
        $link=$this->db->row("SELECT wp_user_id FROM $t WHERE codpessoa=%d FOR UPDATE",[$personId]);
        if(!$link) { return; } // Legacy people awaiting a birth date/manual login appear as pending.
        $id=(int)$link['wp_user_id'];clean_user_cache($id);$u=get_userdata($id);
        if(!$u) { throw new RuleViolation('Usuário vinculado não encontrado.'); }
        $person=$this->db->get('pessoas',$personId);$roles=[];
        if((int)$person['ativo']) {
            $a=$this->db->table('alunos');$v=$this->db->table('aluno_responsaveis');
            if($this->db->row("SELECT idaluno FROM $a WHERE codpessoa=%d AND ativo=1 FOR UPDATE",[$personId])) { $roles[]='erp_aluno'; }
            if($this->db->row('SELECT idprofessor FROM '.$this->db->table('professores').' WHERE codpessoa=%d AND ativo=1 FOR UPDATE',[$personId]))$roles[]='erp_professor';
            $links=$this->db->rows("SELECT responsavel_academico,responsavel_financeiro FROM $v WHERE codpessoa_responsavel=%d AND fim_vigencia IS NULL AND inicio_vigencia<=UTC_TIMESTAMP() FOR UPDATE",[$personId]);
            foreach($links as $r) {
                if((int)$r['responsavel_academico']) { $roles[]='erp_responsavel_academico'; }
                if((int)$r['responsavel_financeiro']) { $roles[]='erp_responsavel_financeiro'; }
            }
        }
        $roles=array_values(array_unique($roles?:['erp_pessoa']));
        $this->cache($id);
        // Preserve administrator/editor/custom roles; remove only roles managed by ERP (and base subscriber).
        foreach(array_merge(self::MANAGED,['subscriber']) as $role) { if(in_array($role,$u->roles,true)&&!in_array($role,$roles,true)) { $u->remove_role($role); } }
        foreach($roles as $role) { $u->add_role($role); }
        $email=$person['email']??'';$owner=$email?email_exists($email):false;
        if($owner && (int)$owner!==$id) { $email=''; }
        $parts=preg_split('/\s+/',trim($person['nome']));
        $result=wp_update_user(['ID'=>$id,'display_name'=>$person['nome'],'first_name'=>$parts[0],'last_name'=>implode(' ',array_slice($parts,1)),'user_email'=>$email]);
        if(is_wp_error($result)) { throw new RuleViolation('Não foi possível sincronizar o perfil WordPress ['.$result->get_error_code().']: '.$result->get_error_message()); }
        // No password or login change during ordinary profile/birth-date edits.
    }
    public function summary(int $personId): array
    {
        $t=$this->db->table('pessoa_usuarios');$link=$this->db->row("SELECT wp_user_id FROM $t WHERE codpessoa=%d",[$personId]);
        $u=$link?get_userdata((int)$link['wp_user_id']):false;
        return $u?['user_login'=>$u->user_login,'wp_user_id'=>(string)$u->ID,'roles'=>array_values($u->roles),'status'=>'criada']:['user_login'=>null,'wp_user_id'=>null,'roles'=>[],'status'=>'pendente'];
    }
    public function migrate(int $cursor=0,int $limit=20): array
    {
        Access::requireAdmin();$p=$this->db->table('pessoas');$rows=$this->db->rows("SELECT codpessoa,nome FROM $p WHERE codpessoa>%d ORDER BY codpessoa LIMIT %d",[$cursor,$limit]);$results=[];
        foreach($rows as $r) {
            try { $account=$this->db->atomic(fn()=>$this->ensure((int)$r['codpessoa']));$results[]=['codpessoa'=>$r['codpessoa'],'nome'=>$r['nome'],'status'=>'criada','user_login'=>$account['user_login']]; }
            catch(\Throwable $e) { $results[]=['codpessoa'=>$r['codpessoa'],'nome'=>$r['nome'],'status'=>'pendente','motivo'=>$e instanceof RuleViolation||$e instanceof UsernameRequired?$e->getMessage():'Falha na criação da conta.']; }
        }
        return ['items'=>$results,'next_cursor'=>count($rows)===$limit?(int)end($rows)['codpessoa']:null];
    }
}
