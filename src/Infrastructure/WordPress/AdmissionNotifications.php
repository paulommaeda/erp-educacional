<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Application\{AdmissionSettings,Coligadas};
use EducacionalERP\Domain\Store;
use EducacionalERP\Infrastructure\Database\Installer;
/** Receipt outbox. Submission commits before any transport is attempted. */
final class AdmissionNotifications
{
    public const HOOK='ederp_send_admission_confirmations';
    public function __construct(private Store $db){}
    public static function register(Store $db):void
    {
        add_action(self::HOOK,static function()use($db){try{(new self($db))->process(20);}catch(\Throwable $ignored){self::schedule(60);}});
        add_action('init',static function()use($db){if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return;if($db->row('SELECT idnotificacao FROM '.$db->table('requerimento_notificacoes')." WHERE status='pendente' LIMIT 1"))self::schedule(60);});
    }
    private static function schedule(int $delay):void
    {
        if(function_exists('wp_schedule_single_event')&&!wp_next_scheduled(self::HOOK))wp_schedule_single_event(time()+$delay,self::HOOK);
    }
    /** Called inside the same transaction as the new public request. No retroactive backfill. */
    public function queue(int $request,array $data,string $protocol):void
    {
        $settings=AdmissionSettings::read();if(!(int)$settings['confirmacao_ativa'])return;$people=$data['pessoas'];$guardians=[];
        $roles=match($settings['confirmacao_responsaveis']){'academico'=>['responsavel_academico'],'financeiro'=>['responsavel_financeiro'],default=>['responsavel_academico','responsavel_financeiro']};
        foreach($roles as $role){$slot=$data[$role];$p=$people[$slot];$email=strtolower(trim((string)$p['email']));if(is_email($email))$guardians[$email]=['nome'=>$p['nome'],'email'=>$email,'telefone'=>$p['telefone']??''];}
        $targets=[];foreach($guardians as $email=>$guardian)$targets[$email]=['tipo'=>'responsavel','nome'=>$guardian['nome']];
        foreach($settings['emails_comercial'] as $email){$email=strtolower(trim($email));if(is_email($email)&&!isset($targets[$email]))$targets[$email]=['tipo'=>'comercial','nome'=>'Equipe comercial'];}
        $snapshot=['protocolo'=>$protocol,'aluno'=>$people['aluno']['nome'],'curso'=>$data['campos']['interesse_curso']??'','serie'=>$data['campos']['interesse_serie']??'','turno'=>$data['campos']['interesse_turno']??'','recebido_em'=>gmdate('Y-m-d H:i:s')];
        foreach($targets as $email=>$target){$payload=$snapshot+['nome_destinatario'=>$target['nome']];if($target['tipo']==='comercial')$payload['responsaveis']=array_values($guardians);
            $this->db->insert('requerimento_notificacoes',['codcoligada'=>Coligadas::current(),'idrequerimento'=>$request,'destinatario'=>$email,'tipo'=>$target['tipo'],'payload_json'=>wp_json_encode($payload),'proxima_tentativa'=>gmdate('Y-m-d H:i:s')]);
        }
        if($targets)$this->db->onCompletion(function(bool $committed)use($request){if(!$committed)return;self::schedule(0);try{$this->process(3,$request);}catch(\Throwable $ignored){/* The committed outbox remains available to cron. */}
            if(function_exists('spawn_cron')&&(!defined('DISABLE_WP_CRON')||!DISABLE_WP_CRON)&&$this->db->row('SELECT idnotificacao FROM '.$this->db->table('requerimento_notificacoes')." WHERE idrequerimento=%d AND status='pendente' LIMIT 1",[$request]))spawn_cron();
        });
    }
    public function process(int $limit=20,?int $request=null):int
    {
        if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return 0;
        $table=$this->db->table('requerimento_notificacoes');$args=[gmdate('Y-m-d H:i:s')];$filter=$request===null?'':' AND idrequerimento=%d';if($request!==null)$args[]=$request;$args[]=max(1,min(50,$limit));
        $rows=$this->db->rows("SELECT idnotificacao FROM $table WHERE status='pendente' AND proxima_tentativa<=%s$filter ORDER BY idnotificacao LIMIT %d",$args);$sent=0;
        foreach($rows as $candidate){$id=(int)$candidate['idnotificacao'];$lock='erp_req_mail_'.substr(hash('sha256',$table.':'.$id),0,40);if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)continue;
            try{$row=$this->db->row("SELECT * FROM $table WHERE idnotificacao=%d",[$id]);if(!$row||$row['status']!=='pendente'||$row['proxima_tentativa']>gmdate('Y-m-d H:i:s'))continue;
                Coligadas::within((int)$row['codcoligada'],function()use($row,$table,$id,&$sent){
                    $company=$this->db->get('coligadas',(int)$row['codcoligada']);$settings=AdmissionSettings::read();
                    if(!(int)$company['ativo']||!(int)$settings['confirmacao_ativa']){$this->db->update('requerimento_notificacoes',$id,['status'=>'cancelado']);return;}
                    // Removed commercial destinations must not receive a previously queued message.
                    if($row['tipo']==='comercial'&&!in_array(strtolower($row['destinatario']),$settings['emails_comercial'],true)){$this->db->update('requerimento_notificacoes',$id,['status'=>'cancelado']);return;}
                    $attempt=(int)$row['tentativas']+1;$next=gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min(6,$attempt-1))));
                    $this->db->update('requerimento_notificacoes',$id,['tentativas'=>$attempt,'ultima_tentativa_em'=>gmdate('Y-m-d H:i:s'),'proxima_tentativa'=>$next]);$ok=false;
                    try{$payload=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);$school=SchoolIdentity::read();$name=trim(preg_replace('/[\r\n]+/',' ',strip_tags($school['nome'])));$subject=$name.' — '.($row['tipo']==='comercial'?'Novo requerimento recebido':'Recebemos seu requerimento').' — '.$payload['protocolo'];$headers=['Content-Type: text/html; charset=UTF-8'];if(is_email((string)$school['email']))$headers[]='Reply-To: '.$school['email'];$ok=wp_mail($row['destinatario'],$subject,AdmissionConfirmationMail::render($payload,$row['tipo']==='comercial'),$headers)===true;}catch(\Throwable $ignored){$ok=false;}
                    $this->db->update('requerimento_notificacoes',$id,['status'=>$ok?'enviado':($attempt>=5?'falha':'pendente'),'enviado_em'=>$ok?gmdate('Y-m-d H:i:s'):null,'ultimo_erro'=>$ok?null:'O WordPress não confirmou o envio. Confira o serviço de e-mail do site.']);if($ok)$sent++;
                });
            }finally{$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}
        }
        if($this->db->row("SELECT idnotificacao FROM $table WHERE status='pendente' LIMIT 1"))self::schedule(60);return $sent;
    }
    public function summary(int $request):array
    {
        $summary=['total'=>0,'enviados'=>0,'pendentes'=>0,'falhas'=>0,'cancelados'=>0];foreach($this->db->rows('SELECT status FROM '.$this->db->table('requerimento_notificacoes').' WHERE idrequerimento=%d',[$request]) as $row){$summary['total']++;$key=match($row['status']){'enviado'=>'enviados','falha'=>'falhas','cancelado'=>'cancelados',default=>'pendentes'};$summary[$key]++;}return $summary;
    }
    public function retry(int $request):array
    {
        Access::requireAdmin();$parent=$this->db->get('requerimentos_publicos',$request);if((int)$parent['codcoligada']!==Coligadas::current())throw new \EducacionalERP\Domain\RuleViolation('Requerimento de outra coligada.');
        if(!(int)AdmissionSettings::read()['confirmacao_ativa'])throw new \EducacionalERP\Domain\RuleViolation('Ative a confirmação por e-mail nas configurações do requerimento.');
        $table=$this->db->table('requerimento_notificacoes');foreach($this->db->rows("SELECT idnotificacao FROM $table WHERE idrequerimento=%d AND status IN ('falha','pendente')",[$request]) as $row){$id=(int)$row['idnotificacao'];$lock='erp_req_mail_'.substr(hash('sha256',$table.':'.$id),0,40);if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)continue;try{$this->db->query("UPDATE $table SET status='pendente',tentativas=0,proxima_tentativa=%s,ultimo_erro=NULL,versao=versao+1 WHERE idnotificacao=%d AND status IN ('falha','pendente')",[gmdate('Y-m-d H:i:s'),$id]);}finally{$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}}
        self::schedule(10);$this->process(3,$request);return $this->summary($request);
    }
}
