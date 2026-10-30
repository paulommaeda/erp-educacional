<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,RuleViolation};
use EducacionalERP\Infrastructure\Database\Installer;
use EducacionalERP\Infrastructure\Educbank\ResponseError;
use EducacionalERP\Infrastructure\WordPress\Access;
/** Enrollment writes enqueue in the same transaction. Beneficiary money is changed only in a separate transaction. */
final class ReferralDiscounts
{
    private static ?\WeakMap $dispatch=null;
    private static bool $running=false;
    public function __construct(private Store $db){}
    public static function changed(Store $db,string $table,int $id):void
    {
        if($table!=='matriculas'||get_option('ederp_schema_version')!==Installer::VERSION)return;
        $m=$db->get('matriculas',$id);$rows=$db->rows('SELECT DISTINCT c.idcontrato,c.codcoligada FROM '.$db->table('contrato_descontos').' d JOIN '.$db->table('desconto_indicados').' r ON r.iddesconto=d.iddesconto JOIN '.$db->table('contratos').' c ON c.idcontrato=d.idcontrato JOIN '.$db->table('matriculas')." b ON b.idmatricula=c.idmatricula WHERE d.ativo=1 AND r.idaluno=%d AND c.codcoligada=%d AND b.codperiodo=%d AND c.status='ativo'",[$m['idaluno'],$m['codcoligada'],$m['codperiodo']]);
        if(!$rows)return;$self=new self($db);foreach($rows as $r)$self->enqueue((int)$r['idcontrato'],(int)$r['codcoligada']);
        self::$dispatch??=new \WeakMap();if(isset(self::$dispatch[$db]))return;self::$dispatch[$db]=true;
        try{$db->onCompletion(static function(bool $committed)use($db){unset(self::$dispatch[$db]);if($committed)(new self($db))->process(3);});}
        catch(\LogicException $e){unset(self::$dispatch[$db]);/* A direct write outside an application transaction is recovered by cron. */}
    }
    private function enqueue(int $contract,int $company):void
    {
        $this->db->query('INSERT INTO '.$this->db->table('desconto_recalculos')." (codcoligada,idcontrato) VALUES (%d,%d) ON DUPLICATE KEY UPDATE geracao=geracao+1,status='pendente',proxima_tentativa=NULL,erro=NULL,tentativas=0,versao=versao+1",[$company,$contract]);
    }
    public function retry(int $contract):array
    {
        Access::requireAdmin();$c=$this->db->get('contratos',$contract);if((int)$c['codcoligada']!==Coligadas::current())throw new RuleViolation('Contrato de outra coligada.');$this->enqueue($contract,(int)$c['codcoligada']);return $this->process(5);
    }
    public function process(int $limit=5):array
    {
        if(self::$running)return ['ocupado'=>true];$lock='ederp_indicacoes_'.substr(hash('sha256',$this->db->table('desconto_recalculos')),0,30);
        if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)return ['ocupado'=>true];self::$running=true;$actor=get_current_user_id();wp_set_current_user(0);
        try{
            $rows=$this->db->rows('SELECT * FROM '.$this->db->table('desconto_recalculos')." WHERE status IN ('pendente','erro') AND (proxima_tentativa IS NULL OR proxima_tentativa<=%s) ORDER BY idrecalculo LIMIT %d",[gmdate('Y-m-d H:i:s'),max(1,min(20,$limit))]);$count=0;$changes=0;$start=microtime(true);
            foreach($rows as $row){if(microtime(true)-$start>5)break;
                try{$changes+=Coligadas::within((int)$row['codcoligada'],fn()=>EducbankOutbox::allow(fn()=>$this->db->atomic(fn()=>$this->inside($row))));}
                catch(\Throwable $e){$message=$e instanceof RuleViolation?ResponseError::clip($e->getMessage()):'Não foi possível recalcular a indicação. Confira os descontos e reprocesse.';$attempts=(int)$row['tentativas']+1;$this->db->query('UPDATE '.$this->db->table('desconto_recalculos').' SET status=%s,erro=%s,tentativas=%d,proxima_tentativa=%s WHERE idrecalculo=%d AND geracao=%d',[$attempts>=8?'revisao':'erro',$message,$attempts,gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**min(6,$attempts)))),$row['idrecalculo'],$row['geracao']]);}$count++;
            }
            return ['processados'=>$count,'parcelas_alteradas'=>$changes];
        }finally{wp_set_current_user($actor);self::$running=false;$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
    private function inside(array $selected):int
    {
        // This snapshot and the enrollment eligibility must observe the same committed generation.
        $snapshot=$this->db->get('desconto_recalculos',(int)$selected['idrecalculo']);if($snapshot['geracao']!==$selected['geracao'])return 0;
        $c=$this->db->get('contratos',(int)$snapshot['idcontrato']);$m=$this->db->get('matriculas',(int)$c['idmatricula']);$this->db->get('alunos',(int)$m['idaluno'],true);$c=$this->db->get('contratos',(int)$c['idcontrato'],true);$locked=$this->db->get('desconto_recalculos',(int)$snapshot['idrecalculo'],true);
        if($locked['geracao']!==$snapshot['geracao'])return 0;
        $company=$this->db->get('coligadas',(int)$c['codcoligada']);if(!$company['ativo'])throw new RuleViolation('Coligada inativa. Confira o recálculo da indicação.');
        $key=hash('sha256','indicacao|'.$locked['idrecalculo'].'|'.$locked['geracao']);$count=(new ContractDiscounts($this->db,new Operations($this->db)))->referralsInside($c,$key);
        $this->db->update('desconto_recalculos',(int)$locked['idrecalculo'],['status'=>'processado','erro'=>null,'proxima_tentativa'=>null,'tentativas'=>0,'processado_em'=>gmdate('Y-m-d H:i:s')]);return $count;
    }
}
