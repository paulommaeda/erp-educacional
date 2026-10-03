<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
/** Public person code is independent of immutable relational PKs. Sequence shares ERP transaction. */
final class PersonNumbering
{
    public function __construct(private Store $db){}
    public function read():array {Access::requireAdmin();$row=$this->db->get('numeracao_pessoas',1);return ['ultimo_codigo'=>$row['ultimo_codigo'],'versao'=>$row['versao']];}
    public function reserve(?string $imported):string
    {
        $row=$this->db->get('numeracao_pessoas',1,true);$last=(int)$row['ultimo_codigo'];$p=$this->db->table('pessoas');
        if($imported!==null){$code=trim($imported);if($code==='')throw new RuleViolation('Informe o código da pessoa na importação.');}
        else {if($last>=999999999999)throw new RuleViolation('Limite da numeração atingido.');$code=(string)($last+1);}
        if($this->db->row("SELECT codpessoa FROM $p WHERE codigo_pessoa=%s FOR UPDATE",[$code]))throw new RuleViolation('Código de pessoa já cadastrado: '.$code.'. Ajuste a sequência ou a importação.');
        if(ctype_digit($code)&&strlen(ltrim($code,'0'))<=12&&(int)$code>$last)$this->db->update('numeracao_pessoas',1,['ultimo_codigo'=>(int)$code]);
        return $code;
    }
    public function save(array $d,string $key):array
    {
        Access::requireAdmin();$value=$d['ultimo_codigo']??null;if(!is_scalar($value)||!preg_match('/^\d{1,12}$/D',(string)$value))throw new RuleViolation('Informe o último código numérico, de 0 a 999999999999.');
        return $this->db->atomic(function()use($d,$value,$key){$before=$this->db->get('numeracao_pessoas',1,true);if((string)($d['versao']??'')!==(string)$before['versao'])throw new RuleViolation('Sequência alterada. Atualize a configuração.');if((int)$value<(int)$before['ultimo_codigo'])throw new RuleViolation('A sequência não pode retroceder.');$this->db->update('numeracao_pessoas',1,['ultimo_codigo'=>(int)$value]);$this->db->audit('numeracao_pessoas',1,'configurar',$before,['ultimo_codigo'=>(int)$value],$key);return $this->read();});
    }
    public function migrate():void
    {
        $p=$this->db->table('pessoas');$t=$this->db->table('numeracao_pessoas');$used=[];$last=0;
        $rows=$this->db->rows("SELECT codpessoa,codpessoa_origem,codigo_pessoa FROM $p ORDER BY codpessoa");
        foreach($rows as $r){$code=$r['codigo_pessoa']?:($r['codpessoa_origem']?:null);if($code!==null){$used[(string)$code]=true;if(ctype_digit((string)$code)&&strlen(ltrim((string)$code,'0'))<=12)$last=max($last,(int)$code);} $last=max($last,(int)$r['codpessoa']);}
        foreach($rows as $r){if($r['codigo_pessoa']!==null)continue;$code=$r['codpessoa_origem']?: (string)$r['codpessoa'];if(!$r['codpessoa_origem']&&isset($used[$code]))$code=(string)++$last;$used[$code]=true;$this->db->update('pessoas',(int)$r['codpessoa'],['codigo_pessoa'=>$code]);}
        $row=$this->db->row("SELECT * FROM $t WHERE idnumeracao=1 FOR UPDATE");if(!$row)$this->db->insert('numeracao_pessoas',['idnumeracao'=>1,'ultimo_codigo'=>$last]);elseif((int)$row['ultimo_codigo']<$last)$this->db->update('numeracao_pessoas',1,['ultimo_codigo'=>$last]);
    }
}
