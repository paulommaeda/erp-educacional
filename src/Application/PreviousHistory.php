<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\{Input,RuleViolation,CadastroText};
final class PreviousHistory
{
    public function __construct(private Database $db) {}
    public function types(?int $company=null):array {$company=$company??Coligadas::current();$rows=$this->db->rows('SELECT * FROM '.$this->db->table('tipos_disciplina').' WHERE codcoligada=%d ORDER BY nome',[$company]);foreach($rows as &$r)$r['subtipos']=$this->db->rows('SELECT * FROM '.$this->db->table('subtipos_disciplina').' WHERE codcoligada=%d AND idtipo_disciplina=%d ORDER BY nome',[$company,(int)$r['idtipo_disciplina']]);return $rows;}
    public function typeSave(array $d,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'tipo_disciplina',$d,function()use($d,$key){
            $id=empty($d['idtipo_disciplina'])?0:Input::id($d['idtipo_disciplina']);$before=$id?$this->db->get('tipos_disciplina',$id,true):null;
            if($before&&(int)$before['codcoligada']!==Coligadas::current())throw new RuleViolation('Tipo pertence a outra coligada.');
            if($before)$this->version($before,$d);$data=['nome'=>$this->text($d,'nome',100),'ativo'=>isset($d['ativo'])?$this->active($d['ativo']):1];
            if($id)$this->db->update('tipos_disciplina',$id,$data);else $id=$this->db->insert('tipos_disciplina',$data);
            $after=$this->db->get('tipos_disciplina',$id);$this->db->audit('tipos_disciplina',$id,$before?'editar':'criar',$before,$after,$key);return $after;
        }));
    }
    private function active(mixed $v):int {if(!in_array($v,[0,1,'0','1',false,true],true))throw new RuleViolation('Situação inválida.');return (int)$v;}
    private function version(array $row,array $d):void {if(!isset($d['versao'])||(string)$d['versao']!==(string)$row['versao'])throw new RuleViolation('Registro alterado. Reabra o cadastro antes de salvar.');}
    private function text(array $d,string $key,int $max,bool $required=true):?string {if(!$required&&($d[$key]??'')==='')return null;return CadastroText::upper(Input::text($d[$key]??null,$max));}
    public function listing(int $student):array
    {
        $this->db->get('alunos',$student);$rows=$this->db->rows('SELECT * FROM '.$this->db->table('historicos_anteriores').' WHERE idaluno=%d ORDER BY idhistorico',[$student]);
        foreach($rows as &$row){$row['anos']=$this->db->rows('SELECT * FROM '.$this->db->table('historico_anos').' WHERE idhistorico=%d ORDER BY ano_letivo,periodo_letivo,idano',[$row['idhistorico']]);foreach($row['anos'] as &$year)$year['disciplinas']=$this->db->rows('SELECT d.*,t.nome AS tipo_nome,s.nome AS subtipo_nome FROM '.$this->db->table('historico_disciplinas').' d JOIN '.$this->db->table('tipos_disciplina').' t ON t.idtipo_disciplina=d.idtipo_disciplina LEFT JOIN '.$this->db->table('subtipos_disciplina').' s ON s.idsubtipo_disciplina=d.idsubtipo_disciplina WHERE d.idano=%d ORDER BY d.nome,d.idregistro_disciplina',[$year['idano']]);unset($year);}unset($row);return $rows;
    }
    public function save(int $student,array $d,string $key):array
    {
        Access::requireAdmin();$aluno=$this->db->get('alunos',$student);
        return Coligadas::within((int)$aluno['codcoligada'],fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'historico_anterior_'.$student,$d,function()use($student,$d,$key){
            $this->db->get('alunos',$student,true);$id=empty($d['idhistorico'])?0:Input::id($d['idhistorico']);$before=$id?$this->db->get('historicos_anteriores',$id,true):null;
            if($before){if((int)$before['idaluno']!==$student)throw new RuleViolation('Histórico pertence a outro aluno.');$this->version($before,$d);$before=$this->find($student,$id);}
            $data=['idaluno'=>$student,'escola_nome'=>$this->text($d,'escola_nome',200),'observacoes'=>$this->text($d,'observacoes',4000,false)];
            $cnpj=$d['escola_cnpj']??'';if(!is_string($cnpj))throw new RuleViolation('CNPJ inválido.');$cnpj=preg_replace('/\D/','',$cnpj);if($cnpj&&strlen($cnpj)!==14)throw new RuleViolation('Informe 14 dígitos no CNPJ da escola anterior, ou deixe vazio.');$data['escola_cnpj']=$cnpj?:null;
            foreach(['rua','numero','complemento','bairro','cep','cidade','estado','pais'] as $field)$data[$field]=$this->text($d,$field,200,false);
            $years=$d['anos']??null;if(!is_array($years)||!array_is_list($years)||!$years||count($years)>30)throw new RuleViolation('Cadastre de 1 a 30 anos letivos.');
            if($id)$this->db->update('historicos_anteriores',$id,$data);else $id=$this->db->insert('historicos_anteriores',$data);
            $keptYears=[];foreach($years as $year){if(!is_array($year))throw new RuleViolation('Ano inválido.');$yearId=empty($year['idano'])?0:Input::id($year['idano']);if(in_array($yearId,$keptYears,true)&&$yearId)throw new RuleViolation('Ano duplicado.');if($yearId&&(int)$this->db->get('historico_anos',$yearId,true)['idhistorico']!==$id)throw new RuleViolation('Ano pertence a outro histórico.');
                $yd=['idhistorico'=>$id];foreach(['periodo_letivo'=>80,'ano_letivo'=>20,'serie'=>80,'curso'=>120] as $field=>$max)$yd[$field]=$this->text($year,$field,$max);$yd['resultado']=$this->text($year,'resultado',80,false);
                if($yearId)$this->db->update('historico_anos',$yearId,$yd);else $yearId=$this->db->insert('historico_anos',$yd);$keptYears[]=$yearId;
                $disciplines=$year['disciplinas']??null;if(!is_array($disciplines)||!array_is_list($disciplines)||!$disciplines||count($disciplines)>100)throw new RuleViolation('Cadastre de 1 a 100 disciplinas por ano.');$kept=[];
                foreach($disciplines as $disc){if(!is_array($disc))throw new RuleViolation('Disciplina inválida.');$discId=empty($disc['idregistro_disciplina'])?0:Input::id($disc['idregistro_disciplina']);if($discId&&in_array($discId,$kept,true))throw new RuleViolation('Disciplina duplicada.');if($discId&&(int)$this->db->get('historico_disciplinas',$discId,true)['idano']!==$yearId)throw new RuleViolation('Disciplina pertence a outro ano.');
                    $type=Input::id($disc['idtipo_disciplina']??null);$tr=$this->db->get('tipos_disciplina',$type,true);if((int)$tr['codcoligada']!==Coligadas::current())throw new RuleViolation('Tipo pertence a outra coligada.');
                    $existing=$discId?$this->db->get('historico_disciplinas',$discId):null;if(!(int)$tr['ativo']&&(!$existing||(int)$existing['idtipo_disciplina']!==$type))throw new RuleViolation('Selecione um tipo de disciplina ativo.');
                    $sub=empty($disc['idsubtipo_disciplina'])?null:Input::id($disc['idsubtipo_disciplina']);if($sub){$sr=$this->db->get('subtipos_disciplina',$sub,true);if((int)$sr['codcoligada']!==Coligadas::current()||(int)$sr['idtipo_disciplina']!==$type||(!(int)$sr['ativo']&&(!$existing||(int)$existing['idsubtipo_disciplina']!==$sub)))throw new RuleViolation('Subtipo inválido para o tipo selecionado.');}$dd=['idsubtipo_disciplina'=>$sub,'idano'=>$yearId,'nome'=>$this->text($disc,'nome',160),'nota_final'=>$this->text($disc,'nota_final',30),'idtipo_disciplina'=>$type];if($discId)$this->db->update('historico_disciplinas',$discId,$dd);else $discId=$this->db->insert('historico_disciplinas',$dd);$kept[]=$discId;
                }
                foreach($this->db->rows('SELECT idregistro_disciplina FROM '.$this->db->table('historico_disciplinas').' WHERE idano=%d',[$yearId]) as $r)if(!in_array((int)$r['idregistro_disciplina'],$kept,true))$this->db->query('DELETE FROM '.$this->db->table('historico_disciplinas').' WHERE idregistro_disciplina=%d',[$r['idregistro_disciplina']]);
            }
            foreach($this->db->rows('SELECT idano FROM '.$this->db->table('historico_anos').' WHERE idhistorico=%d',[$id]) as $r)if(!in_array((int)$r['idano'],$keptYears,true))$this->deleteYear((int)$r['idano']);
            $after=$this->find($student,$id);$this->db->audit('historicos_anteriores',$id,$before?'editar':'criar',$before,$after,$key);return $after;
        })));
    }
    private function find(int $student,int $id):array {foreach($this->listing($student) as $row)if((int)$row['idhistorico']===$id)return $row;throw new RuleViolation('Histórico não encontrado.');}
    private function deleteYear(int $id):void {$this->db->query('DELETE FROM '.$this->db->table('historico_disciplinas').' WHERE idano=%d',[$id]);$this->db->query('DELETE FROM '.$this->db->table('historico_anos').' WHERE idano=%d',[$id]);}
    public function remove(int $student,int $id,array $d,string $key):array
    {
        Access::requireAdmin();$studentRow=$this->db->get('alunos',$student);return Coligadas::within((int)$studentRow['codcoligada'],fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'excluir_historico_'.$id,$d+['idaluno'=>$student],function()use($student,$id,$d,$key){
            $this->db->get('alunos',$student,true);$row=$this->db->get('historicos_anteriores',$id,true);if((int)$row['idaluno']!==$student)throw new RuleViolation('Histórico pertence a outro aluno.');$this->version($row,$d);$before=$this->find($student,$id);foreach($before['anos'] as $year)$this->deleteYear((int)$year['idano']);$this->db->query('DELETE FROM '.$this->db->table('historicos_anteriores').' WHERE idhistorico=%d',[$id]);$this->db->audit('historicos_anteriores',$id,'excluir',$before,[],$key);return ['excluido'=>true];
        })));
    }
}
