<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;

use EducacionalERP\Application\{Coligadas,ScholarshipRequests};
use EducacionalERP\Domain\Store;
use EducacionalERP\Presentation\Portal\Portal;

final class ScholarshipRequestMail
{
    public const HOOK='ederp_send_scholarship_request_mail';
    private const TABLE='requerimento_bolsa_emails';

    public function __construct(private Store $db) {}

    public static function register(Store $db): void
    {
        add_action(self::HOOK,static function()use($db){
            try{(new self($db))->process();}catch(\Throwable){self::schedule(120);}
        });
        add_action('init',static function()use($db){
            if(!SchemaMigration::pending()&&$db->row('SELECT idemail FROM '.$db->table(self::TABLE)." WHERE status='pendente' LIMIT 1"))self::schedule(120);
        });
    }

    private static function schedule(int $seconds): void
    {
        if(function_exists('wp_schedule_single_event')&&!wp_next_scheduled(self::HOOK))wp_schedule_single_event(time()+$seconds,self::HOOK);
    }

    public function queue(int $id,string $type,array $emails): void
    {
        foreach($emails as $email){
            $this->db->insert(self::TABLE,['idpedido'=>$id,'tipo'=>$type,'destinatario'=>$email,'proxima_tentativa'=>gmdate('Y-m-d H:i:s')]);
        }
    }

    public function dispatch(int $id): void
    {
        try{
            self::schedule(120);
            $this->process($id);
        }catch(\Throwable){/* The committed records remain available to the cron worker. */}
    }

    public function process(?int $request=null): void
    {
        if(SchemaMigration::pending())return;
        $table=$this->db->table(self::TABLE);
        $rows=$this->db->rows("SELECT idemail FROM $table WHERE status='pendente' AND proxima_tentativa<=%s"
            .($request?' AND idpedido=%d':'').' ORDER BY idemail LIMIT 25',$request?[gmdate('Y-m-d H:i:s'),$request]:[gmdate('Y-m-d H:i:s')]);
        foreach($rows as $result){
            $id=(int)$result['idemail'];$lock='erp_bolsa_mail_'.substr(hash('sha256',$table.':'.$id),0,36);
            if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)continue;
            try{
                $row=$this->db->get(self::TABLE,$id);
                if($row['status']!=='pendente'||$row['proxima_tentativa']>gmdate('Y-m-d H:i:s'))continue;
                Coligadas::within((int)$row['codcoligada'],fn()=>$this->deliver($row));
            }finally{$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}
        }
        if($this->db->row("SELECT idemail FROM $table WHERE status='pendente' LIMIT 1"))self::schedule(120);
    }

    private function deliver(array $row): void
    {
        $id=(int)$row['idemail'];
        if($row['tipo']==='administracao'&&!in_array($row['destinatario'],ScholarshipRequests::configuration()['emails'],true)){
            $this->db->update(self::TABLE,$id,['status'=>'cancelado']);
            return;
        }
        $request=$this->db->get('requerimentos_bolsa',(int)$row['idpedido']);
        $protocol=$request['protocolo'];
        [$subject,$message]=match($row['tipo']){
            'recebido'=>['Recebemos seu requerimento de bolsa',"Seu requerimento de bolsa foi recebido e será analisado pela escola."],
            'administracao'=>['Novo requerimento de bolsa',"Há um requerimento de bolsa para analisar no ERP.\n".Portal::url('requerimentos_bolsa')],
            'decisao'=>$request['situacao']==='aprovado'
                ?['Requerimento de bolsa aprovado','Seu requerimento de bolsa foi aprovado com percentual de '.str_replace('.',',',$request['percentual_aprovado']).'%.']
                :['Requerimento de bolsa rejeitado','Seu requerimento de bolsa foi analisado e rejeitado.'],
        };
        if($row['tipo']==='decisao'&&$request['observacao'])$message.="\n\nMensagem da escola: ".$request['observacao'];
        $school=SchoolIdentity::read();
        $name=preg_replace('/[\r\n]+/',' ',strip_tags($school['nome']));
        $headers=is_email($school['email'])?['Reply-To: '.$school['email']]:[];
        $attempt=(int)$row['tentativas']+1;
        $this->db->update(self::TABLE,$id,['tentativas'=>$attempt,'proxima_tentativa'=>gmdate('Y-m-d H:i:s',time()+min(3600,120*(2**min(5,$attempt-1))))]);
        try{$ok=wp_mail($row['destinatario'],$subject.' · '.$protocol,$message."\n\nProtocolo: ".$protocol."\n".$name,$headers)===true;}
        catch(\Throwable){$ok=false;}
        $this->db->update(self::TABLE,$id,['status'=>$ok?'enviado':($attempt>=5?'falha':'pendente')]);
    }

    public function status(int $id): array
    {
        $counts=['enviado'=>0,'pendente'=>0,'falha'=>0,'cancelado'=>0];
        foreach($this->db->rows('SELECT status FROM '.$this->db->table(self::TABLE).' WHERE idpedido=%d',[$id]) as $row)$counts[$row['status']]++;
        return $counts;
    }
}
