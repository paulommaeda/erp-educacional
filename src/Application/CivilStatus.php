<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,CadastroText};
use EducacionalERP\Infrastructure\WordPress\Access;
final class CivilStatus
{
    public function __construct(private Store $db){}
    public function all():array{return $this->db->rows('SELECT * FROM '.$this->db->table('estados_civis').' ORDER BY idestado_civil');}
    public function decorate(array $person):array
    {
        if(!empty($person['idestado_civil']))$person['estado_civil']=$this->db->get('estados_civis',(int)$person['idestado_civil'])['nome'];
        return (new AdditionalFields($this->db))->decorate($person);
    }
    public function resolve(mixed $value):array
    {
        if($value===null||$value==='')return ['idestado_civil'=>null,'estado_civil'=>null];
        if(is_int($value)||(is_string($value)&&preg_match('/^\d+$/D',$value))){$id=Input::id(ltrim((string)$value,'0'));try{$row=$this->db->get('estados_civis',$id);}catch(RuleViolation $e){throw new RuleViolation('Código de estado civil não cadastrado. Cadastre-o em Configurações → Estados civis.');}}
        else {$needle=$this->key((string)$value);$row=null;foreach($this->all() as $r)if($this->key($r['nome'])===$needle){$row=$r;break;}if(!$row)throw new RuleViolation('Estado civil não localizado. Use o ID cadastrado nas Configurações.');}
        return ['idestado_civil'=>$row['idestado_civil'],'estado_civil'=>$row['nome']];
    }
    private function key(string $v):string{return str_replace(['(A)','(O)'],'',CadastroText::upper(remove_accents(trim($v))));}
    public function save(array $d,string $key):array
    {
        Access::requireAdmin();$id=Input::id($d['idestado_civil']??null);$name=CadastroText::upper(Input::text($d['nome']??null,80));
        return $this->db->atomic(function()use($id,$name,$d,$key){$table=$this->db->table('estados_civis');$before=$this->db->row("SELECT * FROM $table WHERE idestado_civil=%d FOR UPDATE",[$id]);
            if($before){if(!isset($d['versao'])||(int)$before['versao']!==(int)$d['versao'])throw new RuleViolation('Código já cadastrado ou alterado. Use Editar e recarregue a lista.');$this->db->update('estados_civis',$id,['nome'=>$name]);}
            else {if(isset($d['versao']))throw new RuleViolation('Registro não encontrado.');$this->db->insert('estados_civis',['idestado_civil'=>$id,'nome'=>$name]);}
            $p=$this->db->table('pessoas');$this->db->query("UPDATE $p SET estado_civil=%s,versao=versao+1 WHERE idestado_civil=%d",[$name,$id]);
            $this->db->audit('estados_civis',$id,$before?'editar':'criar',$before,['nome'=>$name],$key);return $this->db->get('estados_civis',$id);
        });
    }
    public function migrate():void
    {
        $table=$this->db->table('estados_civis');
        foreach([1=>'SOLTEIRO',2=>'CASADO',3=>'VIÚVO',4=>'DIVORCIADO',5=>'SEPARADO'] as $id=>$name)if(!$this->db->row("SELECT idestado_civil FROM $table WHERE idestado_civil=%d",[$id]))$this->db->insert('estados_civis',['idestado_civil'=>$id,'nome'=>$name]);
        $p=$this->db->table('pessoas');$values=$this->db->rows("SELECT DISTINCT estado_civil FROM $p WHERE estado_civil IS NOT NULL AND estado_civil<>'' AND idestado_civil IS NULL");
        foreach($values as $v){try{$mapped=$this->resolve($v['estado_civil']);}catch(RuleViolation $e){$id=(int)$this->db->row("SELECT MAX(idestado_civil) AS n FROM $table")['n']+1;$name=CadastroText::upper($v['estado_civil']);$this->db->insert('estados_civis',['idestado_civil'=>$id,'nome'=>$name]);$mapped=['idestado_civil'=>$id,'estado_civil'=>$name];}$this->db->query("UPDATE $p SET idestado_civil=%d,estado_civil=%s,versao=versao+1 WHERE estado_civil=%s AND idestado_civil IS NULL",[$mapped['idestado_civil'],$mapped['estado_civil'],$v['estado_civil']]);}
    }
}
