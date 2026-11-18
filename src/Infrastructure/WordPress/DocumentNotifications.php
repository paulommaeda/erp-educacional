<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Application\{Coligadas,DocumentChecklist,DocumentMailSettings};
use EducacionalERP\Infrastructure\Database\Installer;
use EducacionalERP\Presentation\Portal\Portal;

/** Committed outbox, current guardian authorization, and one reminder cycle per student every 48 hours. */
final class DocumentNotifications
{
    public const HOOK='ederp_document_notifications';
    public const MAIL_HOOK='ederp_document_notifications_mail';
    private \Closure $clock;
    public function __construct(private Store $db,?callable $clock=null){$this->clock=$clock?\Closure::fromCallable($clock):static fn()=>time();}
    private function now():int{return ($this->clock)();}
    private function stamp(?int $time=null):string{return gmdate('Y-m-d H:i:s',$time??$this->now());}
    private static function ready():bool{return get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error');}
    public static function register(Store $db):void
    {
        add_filter('cron_schedules',static function($schedules){$schedules['ederp_documents_five_minutes']=['interval'=>300,'display'=>'Documentos do ERP a cada cinco minutos'];return $schedules;});
        add_action(self::HOOK,static function()use($db){if(!self::ready())return;$worker=new self($db);try{$worker->sweep(100);$worker->process(30);}catch(\Throwable $ignored){self::wake();}});
        add_action(self::MAIL_HOOK,static function()use($db){if(!self::ready())return;try{(new self($db))->process(30);}catch(\Throwable $ignored){self::wake();}});
        add_action('init',static function(){if(self::ready()&&!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+60,'ederp_documents_five_minutes',self::HOOK);});
    }
    private static function wake():void
    {
        if(function_exists('wp_schedule_single_event')&&!wp_next_scheduled(self::MAIL_HOOK))wp_schedule_single_event(time()+60,self::MAIL_HOOK);
    }
    private function insert(array $student,?int $document,string $type,string $email,string $cycle):bool
    {
        $hash=hash('sha256',$student['codcoligada'].':'.$student['idaluno'].':'.$type.':'.$cycle.':'.$email);$table=$this->db->table('documento_notificacoes');
        if($type==='pendencias'){
            $waiting=$this->db->row("SELECT idnotificacao,status,ultima_tentativa_em FROM $table WHERE idaluno=%d AND destinatario=%s AND tipo='pendencias' AND status IN ('pendente','enviando') ORDER BY idnotificacao DESC LIMIT 1 FOR UPDATE",[(int)$student['idaluno'],$email]);
            if($waiting&&($waiting['status']==='pendente'||!$waiting['ultima_tentativa_em']||$waiting['ultima_tentativa_em']>=$this->stamp($this->now()-172800)))return false;
        }
        if($this->db->row("SELECT idnotificacao FROM $table WHERE chave=%s",[$hash]))return false;
        $this->db->insert('documento_notificacoes',['codcoligada'=>$student['codcoligada'],'idaluno'=>$student['idaluno'],'iddocumento'=>$document,'tipo'=>$type,'destinatario'=>$email,'chave'=>$hash,'proxima_tentativa'=>$this->stamp()]);return true;
    }
    /** Caller owns the upload/review transaction; transport runs only after commit. */
    public function document(int $document,string $type):void
    {
        if(!in_array($type,['recebido','aprovado','rejeitado'],true))throw new \LogicException('Evento de documento inválido.');
        $row=$this->db->get('aluno_documentos',$document);$checklist=new DocumentChecklist($this->db);$student=$checklist->activeStudent((int)$row['idaluno']);if(!$student)return;
        Coligadas::within((int)$student['codcoligada'],function()use($document,$type,$row,$student,$checklist){
            $settings=DocumentMailSettings::read();$targets=[];
            if($type==='recebido'){if(!$settings['recebido_ativo']||($row['origem_envio']??'familia')!=='familia')return;$targets=$settings['emails_equipe'];}
            elseif($settings['resultado_ativo'])$targets=array_keys($checklist->guardians((int)$student['idaluno'],$settings['responsaveis']));
            $queued=false;foreach($targets as $email)$queued=$this->insert($student,$document,$type,$email,(string)$document)||$queued;
            if($type==='rejeitado')$this->resetReminder($student);
            if($queued)$this->db->onCompletion(function(bool $committed)use($document){if(!$committed)return;self::wake();try{$this->process(3,$document);}catch(\Throwable $ignored){/* Persistent queue is retried by cron. */}});
        });
    }
    private function resetReminder(array $student):void
    {
        $row=$this->db->row('SELECT idlembrete FROM '.$this->db->table('documento_lembretes').' WHERE idaluno=%d FOR UPDATE',[(int)$student['idaluno']]);$fields=['proximo_envio_em'=>$this->stamp($this->now()+172800)];
        if($row)$this->db->update('documento_lembretes',(int)$row['idlembrete'],$fields);else $this->db->insert('documento_lembretes',['codcoligada'=>$student['codcoligada'],'idaluno'=>$student['idaluno']]+$fields);
    }
    /** Caller already holds the student lock during upload. Resolving all pending files resets the cycle immediately. */
    public function refreshReminder(int $student):void
    {
        if((new DocumentChecklist($this->db))->pending($student))return;
        $row=$this->db->row('SELECT idlembrete,proximo_envio_em FROM '.$this->db->table('documento_lembretes').' WHERE idaluno=%d FOR UPDATE',[$student]);
        if($row&&$row['proximo_envio_em']!==null)$this->db->update('documento_lembretes',(int)$row['idlembrete'],['proximo_envio_em'=>null]);
    }
    /** Bounded cursor makes every active student eligible without sending a burst after missed cron ticks. */
    public function sweep(int $limit=100):int
    {
        if(!self::ready())return 0;$table=$this->db->table('documento_lembretes');$lock='erp_doc_scan_'.substr(hash('sha256',$table),0,40);
        if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)return 0;$queued=0;
        try{
            $cursorKey='ederp_document_reminder_cursor';$cursor=max(0,(int)get_option($cursorKey,0));$limit=max(1,min(300,$limit));
            $rows=$this->db->rows('SELECT idaluno FROM '.$this->db->table('alunos').' WHERE ativo=1 AND idaluno>%d ORDER BY idaluno LIMIT %d',[$cursor,$limit+1]);$more=count($rows)>$limit;if($more)array_pop($rows);
            foreach($rows as $candidate){$id=(int)$candidate['idaluno'];$this->db->atomic(function()use($id,$table,&$queued){
                $this->db->get('alunos',$id,true);$list=new DocumentChecklist($this->db);$student=$list->activeStudent($id);if(!$student)return;
                Coligadas::within((int)$student['codcoligada'],function()use($id,$student,$list,$table,&$queued){
                    $settings=DocumentMailSettings::read();$pending=$list->pending($id);$state=$this->db->row("SELECT * FROM $table WHERE idaluno=%d FOR UPDATE",[$id]);
                    if(!$settings['lembretes_ativos']||!$pending){if($state&&$state['proximo_envio_em']!==null)$this->db->update('documento_lembretes',(int)$state['idlembrete'],['proximo_envio_em'=>null]);return;}
                    if(!$state||!$state['proximo_envio_em']){$this->resetReminder($student);return;}
                    if($state['proximo_envio_em']>$this->stamp())return;
                    foreach(array_keys($list->guardians($id,$settings['responsaveis'])) as $email)if($this->insert($student,null,'pendencias',$email,$state['proximo_envio_em']))$queued++;
                    $this->db->update('documento_lembretes',(int)$state['idlembrete'],['proximo_envio_em'=>$this->stamp($this->now()+172800)]);
                });
            });}
            $last=$rows?end($rows):null;update_option($cursorKey,$more?(int)$last['idaluno']:0,false);
        }finally{$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}return $queued;
    }
    private function context(array $row):?array
    {
        $list=new DocumentChecklist($this->db);$student=$list->activeStudent((int)$row['idaluno']);if(!$student||(int)$student['codcoligada']!==(int)$row['codcoligada'])return null;
        $settings=DocumentMailSettings::read();$staff=$row['tipo']==='recebido';$recipient='Equipe de documentação';
        if($staff){if(!$settings['recebido_ativo']||!in_array($row['destinatario'],$settings['emails_equipe'],true))return null;}
        else{if($row['tipo']==='pendencias'?!$settings['lembretes_ativos']:!$settings['resultado_ativo'])return null;$guardians=$list->guardians((int)$student['idaluno'],$settings['responsaveis']);if(!isset($guardians[$row['destinatario']]))return null;$recipient=$guardians[$row['destinatario']]['nome'];}
        $doc=null;$type=null;$pending=[];
        if($row['tipo']==='pendencias'){$pending=$list->pending((int)$student['idaluno']);if(!$pending)return null;}
        else{
            $doc=$this->db->get('aluno_documentos',(int)$row['iddocumento']);if((int)$doc['idaluno']!==(int)$student['idaluno']||(int)$doc['codcoligada']!==(int)$student['codcoligada'])return null;
            $expected=$staff?'pendente':$row['tipo'];if($doc['status']!==$expected||($staff&&($doc['origem_envio']??'familia')!=='familia'))return null;
            $latest=$this->db->row('SELECT MAX(iddocumento) AS ultimo FROM '.$this->db->table('aluno_documentos').' WHERE idaluno=%d AND idtipo_documento=%d',[(int)$student['idaluno'],(int)$doc['idtipo_documento']]);if((int)$latest['ultimo']!==(int)$doc['iddocumento'])return null;
            $type=$this->db->get('tipos_documento_aluno',(int)$doc['idtipo_documento']);
        }
        $school=SchoolIdentity::read();$familyUrl=add_query_arg('documentos_aluno',(int)$student['idaluno'],Portal::url('inicio'));$staffUrl=add_query_arg('aluno_documentos',(int)$student['idaluno'],Portal::url('documentos'));
        $items=[];foreach($pending as $p)$items[]='• '.$p['nome'].($p['status']==='rejeitado'?' — reenviar: '.$p['motivo']:' — ainda não enviado');
        $values=['colegio_nome'=>$school['nome'],'responsavel_nome'=>$recipient,'aluno_nome'=>$student['nome'],'aluno_ra'=>$student['ra'],'documento_nome'=>$type['nome']??'','situacao'=>match($row['tipo']){'recebido'=>'Aguardando análise','aprovado'=>'Aprovado','rejeitado'=>'Rejeitado',default=>'Documentos pendentes'},'motivo'=>$doc['motivo']??'','documentos_pendentes'=>implode("\n",$items),'data_envio'=>$doc?wp_date('d/m/Y H:i',strtotime($doc['enviado_em'].' UTC')):'','link_documentos'=>$familyUrl,'link_analise'=>$staffUrl];$tokens=[];foreach($values as $name=>$value)$tokens['['.$name.']']=(string)$value;
        $model=$settings['modelos'][$row['tipo']];$subject=trim(preg_replace('/[\r\n]+/',' ',strip_tags(strtr($model['assunto'],$tokens))));$body=strtr($model['corpo'],$tokens);
        $headers=['Content-Type: text/html; charset=UTF-8'];if(is_email((string)$school['email'])&&!preg_match('/[\r\n]/',(string)$school['email']))$headers[]='Reply-To: '.$school['email'];
        return ['assunto'=>$subject,'corpo'=>DocumentMail::render($subject,$body,$staff?$staffUrl:$familyUrl,$staff),'headers'=>$headers];
    }
    public function process(int $limit=30,?int $document=null):int
    {
        if(!self::ready())return 0;$table=$this->db->table('documento_notificacoes');$filter=$document===null?'':' AND iddocumento=%d';$args=[$this->stamp()];if($document!==null)$args[]=$document;$args[]=max(1,min(50,$limit));$rows=$this->db->rows("SELECT idnotificacao FROM $table WHERE status='pendente' AND proxima_tentativa<=%s$filter ORDER BY idnotificacao LIMIT %d",$args);$sent=0;
        foreach($rows as $candidate){$id=(int)$candidate['idnotificacao'];$lock='erp_doc_mail_'.substr(hash('sha256',$table.':'.$id),0,40);if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)continue;
            try{
                $row=$this->db->get('documento_notificacoes',$id);if($row['status']!=='pendente'||$row['proxima_tentativa']>$this->stamp())continue;
                Coligadas::within((int)$row['codcoligada'],function()use($row,$id,&$sent){
                    $context=$this->context($row);if(!$context){$this->db->update('documento_notificacoes',$id,['status'=>'cancelado','ultimo_erro'=>null]);return;}
                    $attempt=(int)$row['tentativas']+1;$this->db->update('documento_notificacoes',$id,['status'=>'enviando','tentativas'=>$attempt,'ultima_tentativa_em'=>$this->stamp()]);$ok=false;
                    try{$ok=wp_mail($row['destinatario'],$context['assunto'],$context['corpo'],$context['headers'])===true;}catch(\Throwable $ignored){$ok=false;}
                    $this->db->update('documento_notificacoes',$id,['status'=>$ok?'enviado':($attempt>=5?'falha':'pendente'),'enviado_em'=>$ok?$this->stamp():null,'assunto_enviado'=>$context['assunto'],'proxima_tentativa'=>$this->stamp($this->now()+min(3600,300*(2**min(4,$attempt-1)))),'ultimo_erro'=>$ok?null:'O WordPress não confirmou o envio. Confira o serviço de e-mail do site.']);if($ok)$sent++;
                });
            }catch(\Throwable $ignored){
                try{$current=$this->db->get('documento_notificacoes',$id);if($current['status']==='pendente'){$attempt=(int)$current['tentativas']+1;$this->db->update('documento_notificacoes',$id,['tentativas'=>$attempt,'status'=>$attempt>=5?'falha':'pendente','proxima_tentativa'=>$this->stamp($this->now()+300),'ultimo_erro'=>'Não foi possível preparar a notificação. Confira os dados e as configurações do colégio.']);}}catch(\Throwable $ignoredAgain){}
                // A claimed transport is never automatically replayed after an interrupted request.
            }
            finally{$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}
        }
        if($this->db->row("SELECT idnotificacao FROM $table WHERE status='pendente' LIMIT 1"))self::wake();return $sent;
    }
    public function listing(int $page=1):array
    {
        Access::requireAdmin();$page=max(1,min(100000,$page));$table=$this->db->table('documento_notificacoes');$total=(int)$this->db->row("SELECT COUNT(*) AS n FROM $table WHERE codcoligada=%d",[Coligadas::current()])['n'];
        return ['items'=>$this->db->rows("SELECT n.idnotificacao,n.tipo,n.destinatario,n.status,n.tentativas,n.enviado_em,n.ultimo_erro,n.criado_em,n.assunto_enviado,p.nome AS aluno,a.ra FROM $table n JOIN ".$this->db->table('alunos').' a ON a.idaluno=n.idaluno JOIN '.$this->db->table('pessoas')." p ON p.codpessoa=a.codpessoa WHERE n.codcoligada=%d ORDER BY n.idnotificacao DESC LIMIT 20 OFFSET %d",[Coligadas::current(),($page-1)*20]),'total'=>$total,'page'=>$page];
    }
    public function retry(int $id):array
    {
        Access::requireAdmin();$row=$this->db->get('documento_notificacoes',$id);if((int)$row['codcoligada']!==Coligadas::current())throw new RuleViolation('Notificação de outra coligada.');$lock='erp_doc_mail_'.substr(hash('sha256',$this->db->table('documento_notificacoes').':'.$id),0,40);
        if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Envio em andamento. Aguarde.');
        try{$row=$this->db->get('documento_notificacoes',$id);if($row['status']!=='falha')throw new RuleViolation('A nova tentativa está disponível para envios com falha confirmada.');$this->db->update('documento_notificacoes',$id,['status'=>'pendente','tentativas'=>0,'proxima_tentativa'=>$this->stamp(),'ultimo_erro'=>null]);}finally{$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}self::wake();return ['agendado'=>true];
    }
}
