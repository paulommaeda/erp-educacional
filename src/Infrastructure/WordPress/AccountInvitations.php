<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Application\Coligadas;
use EducacionalERP\Domain\{Store,RuleViolation};

/** Transactional WordPress user-meta outbox. Reset keys are encrypted at rest. */
final class AccountInvitations
{
    public const META='_ederp_access_invitation';
    public const DUE='_ederp_access_invitation_due';
    public const HOOK='ederp_send_account_invitations';
    private static array $initialResets=[];
    public function __construct(private Store $db){}

    public function created(int $person,int $user):void
    {
        update_user_meta($user,self::META,['codpessoa'=>$person,'codcoligada'=>Coligadas::current(),'status'=>'nao_enviado','criado_em'=>gmdate('Y-m-d H:i:s'),'entregas'=>[],'tentativas'=>0,'senha_inicial'=>hash('sha256',get_userdata($user)->user_pass)]);
    }
    /** Caller owns the ERP transaction; account and invitation roll back together. */
    public function queue(int $person,bool $resend=false,bool $rotate=false,bool $requested=false):void
    {
        // Person creation, links and imports never authorize a message on their own.
        if(!$requested&&!$rotate)return;
        $link=$this->db->row('SELECT wp_user_id FROM '.$this->db->table('pessoa_usuarios').' WHERE codpessoa=%d',[$person]);
        if(!$link)return;$id=(int)$link['wp_user_id'];$lock='ederp_access_invite_'.$id;
        if((int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('O convite está sendo processado. Tente novamente.');
        $this->db->onCompletion(function()use($lock,$id){clean_user_cache($id);$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);});
        clean_user_cache($id);$state=get_user_meta($id,self::META,true);
        if(!is_array($state)||$state['status']==='definida')return; // Existing passwords are never changed by this release.
        $previous=$state;
        $state['status']=$requested?'pendente':'nao_enviado';$state['tentativas']=0;unset($state['erro']);
        if($resend)$state['entregas']=[];
        if($rotate){$result=wp_update_user(['ID'=>$id,'user_activation_key'=>'']);if(is_wp_error($result))throw new RuleViolation('Não foi possível invalidar o convite anterior. Tente novamente.');unset($state['chave']);$state['entregas']=[];}
        if(!$requested){unset($state['envio_manual_em'],$state['solicitado_por']);if($state!==$previous&&update_user_meta($id,self::META,$state,$previous)===false)throw new RuleViolation('O convite foi alterado. Recarregue a lista.');delete_user_meta($id,self::DUE);return;}
        $state['envio_manual_em']=gmdate('Y-m-d H:i:s');$state['solicitado_por']=get_current_user_id();
        if(update_user_meta($id,self::META,$state,$previous)===false)throw new RuleViolation('O convite foi alterado. Recarregue a lista.');update_user_meta($id,self::DUE,time());
        $this->db->onCompletion(static function(bool $committed)use($id){
            clean_user_cache($id);if($committed&&!wp_next_scheduled(self::HOOK))wp_schedule_single_event(time()+10,self::HOOK);
        });
    }
    public function contactChanged(int $person,bool $changed):void
    {
        $this->queue($person,false,$changed);
        if(!$changed)return;
        $children=$this->db->rows('SELECT DISTINCT a.codpessoa FROM '.$this->db->table('aluno_responsaveis').' v JOIN '.$this->db->table('alunos')." a ON a.idaluno=v.idaluno WHERE v.codpessoa_responsavel=%d AND v.responsavel_academico=1 AND v.fim_vigencia IS NULL AND v.inicio_vigencia<=UTC_TIMESTAMP()",[$person]);
        foreach($children as $child)$this->queue((int)$child['codpessoa'],false,true);
    }
    public static function summary(int $user):?array
    {
        $state=get_user_meta($user,self::META,true);
        if(!is_array($state))return null;
        $deliveries=array_values($state['entregas']??[]);sort($deliveries);
        $status=$state['status'];if(in_array($status,['pendente','erro'],true)&&empty($state['envio_manual_em']))$status='nao_enviado';$sent=$deliveries?end($deliveries):null;$confirmed=$state['confirmado_em']??null;
        return ['status'=>$status,'erro'=>$status==='nao_enviado'?null:($state['erro']??null),'pode_reenviar'=>$status!=='definida','enviado'=>!empty($deliveries)||!empty($confirmed),'enviado_em'=>$sent,'enviado_em_label'=>UserAccessLog::label($sent),'ok'=>!empty($confirmed),'confirmado_em'=>$confirmed,'confirmado_em_label'=>UserAccessLog::label($confirmed)];
    }
    public static function finish(int $user):void
    {
        if(isset(self::$initialResets[$user]))return; // Confirmation is recorded only after reset_password succeeds.
        for($attempt=0;$attempt<3;$attempt++){
            wp_cache_delete($user,'user_meta');$before=get_user_meta($user,self::META,true);if(!is_array($before))return;
            $state=$before;$state['status']='definida';unset($state['chave'],$state['erro'],$state['senha_inicial']);
            if($state===$before||update_user_meta($user,self::META,$state,$before)!==false)break;
        }
        delete_user_meta($user,self::DUE);
    }
    public static function expectInitialReset(int $user,string $key):void
    {
        unset(self::$initialResets[$user]);$state=get_user_meta($user,self::META,true);
        if(!is_array($state)||empty($state['chave'])||empty($state['senha_inicial']))return;
        $account=get_userdata($user);if(!$account||!hash_equals($state['senha_inicial'],hash('sha256',$account->user_pass)))return;
        $expected=self::reveal($state['chave'],$user);
        if($expected!==''&&hash_equals($expected,$key)&&!is_wp_error(check_password_reset_key($key,$account->user_login)))self::$initialResets[$user]=hash('sha256',$account->user_pass);
    }
    public static function resetFinished(int $user):void
    {
        $before=self::$initialResets[$user]??null;unset(self::$initialResets[$user]);$account=get_userdata($user);
        if(!$account||($before!==null&&hash_equals($before,hash('sha256',$account->user_pass))))return;
        self::finish($user);
        if($before!==null){$state=get_user_meta($user,self::META,true);if(is_array($state)){$state['confirmado_em']=gmdate('Y-m-d H:i:s');update_user_meta($user,self::META,$state);}}
    }
    public static function register(Store $db):void
    {
        add_filter('cron_schedules',static function($s){$s['ederp_access_minute']=['interval'=>60,'display'=>'ERP: convites de acesso'];return $s;});
        add_action('init',static function(){if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+30,'ederp_access_minute',self::HOOK);});
        add_action(self::HOOK,static function()use($db){try{(new self($db))->process();}catch(\Throwable $ignored){/* Due markers survive failures and are retried. */}finally{if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'ederp_access_minute',self::HOOK);}});
        add_action('wp_set_password',static fn($password,$id)=>self::finish((int)$id),10,2);
        add_action('profile_update',static function($id,$before){$after=get_userdata((int)$id);if($after&&$before->user_pass!==$after->user_pass)self::finish((int)$id);},10,2);
        add_action('validate_password_reset',static function($errors,$user){if($user instanceof \WP_User&&isset($_POST['rp_key'])&&is_string($_POST['rp_key']))self::expectInitialReset((int)$user->ID,wp_unslash($_POST['rp_key']));},20,2);
        add_action('after_password_reset',static fn($user)=>self::resetFinished((int)$user->ID),20,1);
        add_filter('retrieve_password_notification_email',static fn($mail,$key,$login,$user)=>(new self($db))->recoveryRecipient($mail,$user),20,4);
    }
    /** Shared contact addresses cannot occupy multiple native user_email fields. Reset by username. */
    public function recoveryRecipient(array $mail,\WP_User $user):array
    {
        if(!empty($mail['to']))return $mail;
        $person=$this->db->row('SELECT p.email FROM '.$this->db->table('pessoa_usuarios').' u JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=u.codpessoa WHERE u.wp_user_id=%d AND p.ativo=1',[(int)$user->ID]);
        if($person&&is_email((string)$person['email']))$mail['to']=$person['email'];
        return $mail;
    }
    public function process(int $limit=10):void
    {
        $users=get_users(['meta_key'=>self::DUE,'meta_value'=>time(),'meta_compare'=>'<=','meta_type'=>'NUMERIC','number'=>max(1,min(50,$limit)),'orderby'=>'meta_value_num','order'=>'ASC','fields'=>'ID']);
        foreach($users as $id)$this->deliver((int)$id);
    }
    /** Do not overwrite a password confirmation completed while the mail transport was running. */
    private static function persist(int $id,array $before,array $after):bool
    {
        clean_user_cache($id);$user=get_userdata($id);$latest=get_user_meta($id,self::META,true);
        if(!$user||!is_array($latest)||$latest['status']==='definida')return false;
        if(!hash_equals((string)($before['senha_inicial']??''),hash('sha256',$user->user_pass))){self::finish($id);return false;}
        if($latest!==$before)return false;
        if(update_user_meta($id,self::META,$after,$before)!==false)return true;
        wp_cache_delete($id,'user_meta');return get_user_meta($id,self::META,true)===$after;
    }
    private static function protect(string $key,int $user):string
    {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($key,'aes-256-gcm',hash('sha256',wp_salt('auth').'|ederp-invitation',true),OPENSSL_RAW_DATA,$iv,$tag,(string)$user);
        if($cipher===false)throw new RuleViolation('Não foi possível proteger o convite.');return base64_encode($iv.$tag.$cipher);
    }
    private static function reveal(string $cipher,int $user):string
    {
        $raw=base64_decode($cipher,true);if($raw===false||strlen($raw)<29)return '';
        return (string)openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth').'|ederp-invitation',true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),(string)$user);
    }
    private function targets(array $person,array $state):array
    {
        $students=$this->db->rows('SELECT * FROM '.$this->db->table('alunos').' WHERE codpessoa=%d AND ativo=1 ORDER BY idaluno',[(int)$person['codpessoa']]);$student=null;
        foreach($students as $row)if(!$student||(int)$row['codcoligada']===(int)$state['codcoligada'])$student=$row;
        $targets=[];
        if(is_email((string)$person['email']))$targets[strtolower($person['email'])]=['email'=>$person['email'],'student'=>$student,'guardian'=>null,'company'=>$student?(int)$student['codcoligada']:(int)$state['codcoligada']];
        foreach($students as $row){
            $guardians=$this->db->rows('SELECT p.codpessoa,p.nome,p.email FROM '.$this->db->table('aluno_responsaveis').' v JOIN '.$this->db->table('pessoas')." p ON p.codpessoa=v.codpessoa_responsavel WHERE v.idaluno=%d AND v.responsavel_academico=1 AND v.fim_vigencia IS NULL AND v.inicio_vigencia<=UTC_TIMESTAMP() AND p.ativo=1 ORDER BY v.idvinculo",[(int)$row['idaluno']]);
            foreach($guardians as $guardian)if(is_email((string)$guardian['email']))$targets[strtolower($guardian['email'])]=['email'=>$guardian['email'],'student'=>$row,'guardian'=>$guardian,'company'=>(int)$row['codcoligada']];
        }
        return array_values($targets);
    }
    /** Lock prevents concurrent cron runs from issuing competing keys or duplicate sends. */
    public function deliver(int $id):void
    {
        $lock='ederp_access_invite_'.$id;if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)return;
        try{
            clean_user_cache($id);$state=get_user_meta($id,self::META,true);$due=(int)get_user_meta($id,self::DUE,true);
            if(!is_array($state)||$state['status']==='definida'||!$due||$due>time())return;
            if(empty($state['envio_manual_em'])){$state['status']='nao_enviado';unset($state['erro']);update_user_meta($id,self::META,$state);delete_user_meta($id,self::DUE);return;}
            $link=$this->db->row('SELECT codpessoa FROM '.$this->db->table('pessoa_usuarios').' WHERE wp_user_id=%d',[$id]);$user=get_userdata($id);
            if(!$link||!$user||(int)$link['codpessoa']!==(int)$state['codpessoa']){delete_user_meta($id,self::DUE);return;}
            if(!hash_equals((string)($state['senha_inicial']??''),hash('sha256',$user->user_pass))){self::finish($id);return;}
            $person=$this->db->get('pessoas',(int)$link['codpessoa']);if(!(int)$person['ativo']){delete_user_meta($id,self::DUE);return;}
            $targets=$this->targets($person,$state);if(!$targets)throw new RuleViolation('Confira o e-mail do cadastro antes de reenviar o convite.');
            $key=isset($state['chave'])?self::reveal($state['chave'],$id):'';
            if($key===''||is_wp_error(check_password_reset_key($key,$user->user_login))){
                $previous=$state;
                $key=get_password_reset_key($user);if(is_wp_error($key))throw new RuleViolation('Não foi possível gerar o link de criação de senha.');
                $state['chave']=self::protect($key,$id);$state['entregas']=[];
                if(!self::persist($id,$previous,$state))return; // Encrypt before transport; reuse the same key for the family.
            }
            clean_user_cache($id);$current=get_userdata($id);
            if(!$current||!hash_equals($state['senha_inicial'],hash('sha256',$current->user_pass))){
                if($current&&!is_wp_error(check_password_reset_key($key,$current->user_login)))wp_update_user(['ID'=>$id,'user_activation_key'=>'']);
                self::finish($id);return;
            }
            $url=add_query_arg(['erp_key'=>$key,'erp_login'=>$user->user_login,'erp_primeiro_acesso'=>'1'],\EducacionalERP\Presentation\Portal\Portal::url('redefinir_senha'));
            $failed=false;
            foreach($targets as $target){
                $signature=hash('sha256',strtolower($target['email']).'|'.($target['guardian']['codpessoa']??0).'|'.($target['student']['ra']??''));
                if(isset($state['entregas'][$signature]))continue;
                $previous=$state;
                $sent=Coligadas::within($target['company'],static function()use($person,$user,$url,$target){
                    $school=SchoolIdentity::read();$subject=$school['nome'].' — '.($target['student']?'Crie a senha do aluno '.$person['nome'].' · RA '.$target['student']['ra']:'Crie sua senha de acesso');
                    $headers=['Content-Type: text/html; charset=UTF-8'];if(is_email((string)$school['email']))$headers[]='Reply-To: '.$school['email'];
                    return wp_mail($target['email'],$subject,AccountInvitationMail::render($person,$user,$url,$target['student'],$target['guardian']),$headers);
                });
                if($sent)$state['entregas'][$signature]=gmdate('Y-m-d H:i:s');else $failed=true;
                if(!self::persist($id,$previous,$state))return;
            }
            if($failed)throw new RuleViolation('Não foi possível enviar todos os convites. Confira o serviço de e-mail e reenvie.');
            $previous=$state;$state['status']='enviado';unset($state['erro']);if(!self::persist($id,$previous,$state))return;delete_user_meta($id,self::DUE);
        }catch(\Throwable $e){
            if(!isset($state)||!is_array($state))return;
            $previous=$state;
            $state['tentativas']=(int)($state['tentativas']??0)+1;$state['status']='erro';$state['erro']=$e instanceof RuleViolation?$e->getMessage():'Falha ao preparar o convite. Confira a configuração de e-mail e tente reenviar.';
            if(!self::persist($id,$previous,$state))return;
            if($state['tentativas']<5)update_user_meta($id,self::DUE,time()+min(3600,60*(2**$state['tentativas'])));else delete_user_meta($id,self::DUE);
        }finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
}
