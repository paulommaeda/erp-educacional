<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,CadastroText,Cpf};
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy,StudentRecordPolicy};
/** Authorization to collect a student is independent of financial/academic responsibility. */
final class StudentPickup
{
    public function __construct(private Store $db){}
    public static function permitted():bool {return Access::isAdmin()||(current_user_can('erp_gerenciar_pessoas')&&MenuPolicy::can('alunos')&&StudentRecordPolicy::can('dados_pessoais'));}
    private function student(int $id,bool $lock=false):array {if(!self::permitted())throw new RuleViolation('Sem permissão para autorizações de retirada.');$a=$this->db->get('alunos',$id,$lock);if((int)$a['codcoligada']!==Coligadas::current())throw new RuleViolation('Aluno de outra coligada.');return $a;}
    public function listing(int $student):array {$this->student($student);return $this->db->rows('SELECT * FROM '.$this->db->table('aluno_retiradas').' WHERE idaluno=%d ORDER BY ativo DESC,nome,idautorizacao',[$student]);}
    public function save(int $student,array $d,string $key):array
    {
        $this->student($student);return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'autorizacao_retirada',['idaluno'=>$student]+$d,function()use($student,$d,$key){
            $this->student($student,true);$id=empty($d['idautorizacao'])?0:Input::id($d['idautorizacao']);$old=$id?$this->db->get('aluno_retiradas',$id,true):null;
            if($old&&((int)$old['idaluno']!==$student||(string)($d['versao']??'')!==(string)$old['versao']))throw new RuleViolation('Autorização alterada ou não pertence ao aluno.');
            $valid=$this->data($d);if($id)$this->db->update('aluno_retiradas',$id,$valid);else $id=$this->db->insert('aluno_retiradas',$valid+['idaluno'=>$student]);$this->db->audit('aluno_retiradas',$id,$old?'editar':'criar',$old,$valid,$key);return ['idautorizacao'=>$id];
        }));
    }
    public function data(array $d):array
    {
        $person=empty($d['codpessoa'])?null:Input::id($d['codpessoa']);$p=$person?$this->db->get('pessoas',$person):$d;if($person&&!(int)$p['ativo'])throw new RuleViolation('Pessoa inativa.');
        $cpf=preg_replace('/\D/','',(string)($p['cpf']??''));if($cpf!==''&&!Cpf::valid($cpf))throw new RuleViolation('CPF inválido.');$active=$d['ativo']??1;if(!in_array($active,[0,1,'0','1'],true))throw new RuleViolation('Situação inválida.');
        $v=['codpessoa'=>$person,'nome'=>Input::text($p['nome']??null,191),'cpf'=>$cpf?:null,'rg'=>empty($p['rg'])?null:Input::text($p['rg'],40),'telefone'=>empty($p['telefone'])?null:Input::text($p['telefone'],30),'parentesco'=>Input::text($d['parentesco']??null,40),'observacoes'=>empty($d['observacoes'])?null:Input::text($d['observacoes'],1000),'ativo'=>(int)$active];return CadastroText::normalize($v);
    }
    /** Composed approval transaction; previous authorizations are never silently revoked. */
    public function importInside(int $student,array $rows,array $people,string $key):void
    {
        foreach($rows as $r){$slot=$r['pessoa']??'';unset($r['pessoa']);$r['codpessoa']=$slot!==''?$people[$slot]['codpessoa']:null;$valid=$this->data($r);$matches=$this->db->rows('SELECT * FROM '.$this->db->table('aluno_retiradas').' WHERE idaluno=%d AND ativo=1 FOR UPDATE',[$student]);$exists=false;
            foreach($matches as $old)if(($valid['codpessoa']&&(int)$old['codpessoa']===(int)$valid['codpessoa'])||(!$valid['codpessoa']&&$old['nome']===$valid['nome']&&($old['telefone']??'')===($valid['telefone']??'')))$exists=true;
            if(!$exists){$id=$this->db->insert('aluno_retiradas',$valid+['idaluno'=>$student]);$this->db->audit('aluno_retiradas',$id,'aprovar_requerimento',null,$valid,$key);}
        }
    }
}
