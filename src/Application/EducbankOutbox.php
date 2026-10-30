<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
use EducacionalERP\Infrastructure\Educbank\Settings;
/** Durable intent in the same transaction as the ERP mutation. Never performs HTTP. */
final class EducbankOutbox
{
    private static int $suspended=0;
    public function __construct(private Store $db){}
    public static function without(callable $work):mixed {self::$suspended++;try{return $work();}finally{self::$suspended--;}}
    /** Referral recalculations after an incoming settlement are new outbound changes, not payment echoes. */
    public static function allow(callable $work):mixed {$before=self::$suspended;self::$suspended=0;try{return $work();}finally{self::$suspended=$before;}}
    public static function changed(Store $db,string $table,int $id):void
    {
        if(self::$suspended)return;
        if(!in_array($table,['matriculas','lancamentos','contratos','pessoas','aluno_responsaveis','turmas','periodos_letivos'],true))return;
        if(get_option('ederp_schema_version')!==\EducacionalERP\Infrastructure\Database\Installer::VERSION)return;
        $self=new self($db);
        if($table==='matriculas'){$self->enrollment($id,true);return;}
        if($table==='lancamentos'){$self->invoice($id);return;}
        $m=$db->table('matriculas');$args=[$id];
        $where=match($table){
            'contratos'=>'m.idmatricula IN (SELECT idmatricula FROM '.$db->table('contratos').' WHERE idcontrato=%d)',
            'turmas'=>'m.idturma_atual=%d','periodos_letivos'=>'m.codperiodo=%d',
            'aluno_responsaveis'=>'m.idaluno IN (SELECT idaluno FROM '.$db->table('aluno_responsaveis').' WHERE idvinculo=%d)',
            'pessoas'=>'(m.idaluno IN (SELECT idaluno FROM '.$db->table('alunos').' WHERE codpessoa=%d) OR m.idaluno IN (SELECT idaluno FROM '.$db->table('aluno_responsaveis').' WHERE codpessoa_responsavel=%d))',
        };
        if($table==='pessoas')$args[]=$id;
        foreach($db->rows("SELECT m.idmatricula FROM $m m WHERE $where",$args) as $r)$self->enrollment((int)$r['idmatricula'],true);
    }
    public function enrollment(int $id,bool $invoices=false):void
    {
        $m=$this->db->get('matriculas',$id);$s=Settings::read((int)$m['codcoligada']);if(!$s['ativo'])return;
        $this->put($m,$s,'matricula',$id,null);
        if($invoices)foreach($this->db->rows('SELECT l.idlancamento FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$this->db->table('contratos').' c ON c.idcontrato=p.idcontrato WHERE c.idmatricula=%d',[$id]) as $row)$this->put($m,$s,'fatura',(int)$row['idlancamento'],(int)$row['idlancamento']);
    }
    public function invoice(int $id):void
    {
        $r=$this->db->row('SELECT c.idmatricula FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$this->db->table('contratos').' c ON c.idcontrato=p.idcontrato WHERE l.idlancamento=%d',[$id]);if(!$r)return;
        $m=$this->db->get('matriculas',(int)$r['idmatricula']);$s=Settings::read((int)$m['codcoligada']);if(!$s['ativo'])return;
        // A fresh bill always depends on the enrollment, including deferred renewal installments.
        $this->put($m,$s,'matricula',(int)$m['idmatricula'],null);$this->put($m,$s,'fatura',$id,$id);
    }
    private function put(array $m,array $s,string $type,int $local,?int $title):void
    {
        $q=$this->db->table('educbank_fila');$values=[$m['codcoligada'],$s['ambiente'],Settings::scope($s)['tenant'],$type,$local,$m['idmatricula']];
        $titleSql=$title===null?'NULL':'%d';if($title!==null)$values[]=$title;$values[]=(string)$local;
        $this->db->query("INSERT INTO $q (codcoligada,ambiente,tenant,tipo,idlocal,idmatricula,idlancamento,external_id) VALUES (%d,%s,%s,%s,%d,%d,$titleSql,%s) ON DUPLICATE KEY UPDATE geracao=geracao+1,status='pendente',proxima_tentativa=NULL,erro=NULL,versao=versao+1",$values);
    }
    public static function refresh(Store $db,int $company):void
    {
        $s=Settings::read($company);if(!$s['ativo'])return;$db->query("UPDATE ".$db->table('educbank_fila')." SET geracao=geracao+1,status='pendente',proxima_tentativa=NULL,erro=NULL,versao=versao+1 WHERE codcoligada=%d AND ambiente=%s AND tenant=%s",[$company,$s['ambiente'],Settings::scope($s)['tenant']]);
    }
}
