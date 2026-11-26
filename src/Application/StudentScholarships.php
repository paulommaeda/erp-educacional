<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Input,Money,RuleViolation,Store};
use EducacionalERP\Infrastructure\WordPress\Access;

final class StudentScholarships
{
    public function __construct(private Store $db) {}

    private function student(int $id,bool $lock=false):array
    {
        $student=$this->db->get('alunos',$id,$lock);
        if((int)$student['codcoligada']!==Coligadas::current())throw new RuleViolation('Aluno indisponível nesta coligada.');
        return $student;
    }

    private function grants(int $student,bool $lock=false):array
    {
        return $this->db->rows('SELECT * FROM '.$this->db->table('aluno_bolsas').' WHERE idaluno=%d ORDER BY idbolsa_aluno'.($lock?' FOR UPDATE':''),[$student]);
    }

    public function listing(int $student):array
    {
        Access::requireManageDiscounts();
        $row=$this->student($student);
        $person=$this->db->get('pessoas',(int)$row['codpessoa']);
        return ['aluno'=>['idaluno'=>$student,'nome'=>$person['nome'],'ra'=>$row['ra']],'items'=>$this->grants($student)];
    }

    public function activePercentage(int $student):string
    {
        $this->student($student);
        $row=$this->db->row('SELECT SUM(percentual) AS total FROM '.$this->db->table('aluno_bolsas').' WHERE idaluno=%d AND ativo=1',[$student]);
        return number_format((float)($row['total']??0),2,'.','');
    }

    /** Only the snapshots on this contract participate in its pricing and text. */
    public function forContract(int $contract,bool $activeOnly=false,bool $lock=false):array
    {
        return $this->db->rows('SELECT idbolsa_aluno,titulo,descricao,percentual,ativo FROM '.$this->db->table('contrato_bolsas_aluno').' WHERE idcontrato=%d'.($activeOnly?' AND ativo=1':'').' ORDER BY idbolsa_aluno'.($lock?' FOR UPDATE':''),[$contract]);
    }

    public function save(int $student,?int $id,array $data,string $key):array
    {
        Access::requireManageDiscounts();
        return ContractTextLock::run($this->db,fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'salvar_bolsa_aluno',
            ['idaluno'=>$student,'idbolsa_aluno'=>$id]+$data,fn()=>$this->saveInside($student,$id,$data,$key))));
    }

    /** Caller authorizes the operation and holds the contract text lock and transaction. */
    public function saveInside(int $student,?int $id,array $data,string $key):array
    {
        if(array_diff(array_keys($data),['titulo','descricao','percentual','ativo','versao']))throw new RuleViolation('Informe título, descrição, percentual e situação da bolsa do aluno.');
        $percent=Money::positive($data['percentual']??null);
        if($percent>10000)throw new RuleViolation('O percentual da bolsa deve ser de até 100%.');
        $active=$data['ativo']??1;
        if(!in_array($active,[0,1,'0','1',false,true],true))throw new RuleViolation('Situação da bolsa inválida.');
        $fields=['titulo'=>Input::text($data['titulo']??null,191),'descricao'=>Input::text($data['descricao']??null,2000),'percentual'=>Money::format($percent),'ativo'=>(int)$active];
        $this->student($student,true);
        $before=null;$total=$fields['ativo']?$percent:0;
        foreach($this->grants($student,true) as $grant){
            if((int)$grant['idbolsa_aluno']===$id)$before=$grant;
            elseif((int)$grant['ativo'])$total+=Money::cents($grant['percentual']);
        }
        if($id&&(!$before||(string)($data['versao']??'')!==(string)$before['versao']))throw new RuleViolation('Bolsa indisponível ou alterada. Reabra o cadastro do aluno.');
        if($total>10000)throw new RuleViolation('A soma das bolsas ativas do aluno ultrapassa 100%.');
        if($id)$this->db->update('aluno_bolsas',$id,$fields);
        else $id=$this->db->insert('aluno_bolsas',['idaluno'=>$student]+$fields);
        $grant=$this->db->get('aluno_bolsas',$id,true);
        $this->db->audit('aluno_bolsas',$id,$before?'editar':'conceder',$before,$grant,$key);
        $result=['idbolsa_aluno'=>(string)$id,'contratos_atualizados'=>0,'parcelas_alteradas'=>0,'parcelas_preservadas'=>0];
        if(!(int)$grant['ativo']&&empty($before['ativo']))return $result;

        $contracts=$this->db->rows('SELECT c.idcontrato,c.numero,c.quantidade_parcelas FROM '.$this->db->table('contratos').' c JOIN '.$this->db->table('matriculas')." m ON m.idmatricula=c.idmatricula WHERE m.idaluno=%d AND c.codcoligada=%d AND c.status='ativo' AND c.excluido_em IS NULL ORDER BY c.idcontrato FOR UPDATE",[$student,Coligadas::current()]);
        $scholarships=new Scholarships($this->db);
        $templates=new ContractTemplates($this->db,new Operations($this->db));
        foreach($contracts as $contract){
            $cid=(int)$contract['idcontrato'];
            if(!$this->copyToContract($cid,$grant,$key))continue;
            try {
                $counts=$scholarships->repriceInside($cid,1,(int)$contract['quantidade_parcelas'],$key,true);
                $templates->refreshInside($cid);
            } catch(RuleViolation $error) {
                throw new RuleViolation('Contrato '.$contract['numero'].'. '.$error->getMessage(),0,$error);
            }
            $result['contratos_atualizados']++;
            $result['parcelas_alteradas']+=$counts['parcelas_alteradas'];
            $result['parcelas_preservadas']+=$counts['parcelas_preservadas'];
        }
        return $result;
    }

    /** Called under the student row lock by every contract creation path, including renewal. */
    public function inheritInside(int $contract,int $student,string $key,mixed $expectedPercent=null):bool
    {
        $rows=$this->db->rows('SELECT * FROM '.$this->db->table('aluno_bolsas').' WHERE idaluno=%d AND ativo=1 ORDER BY idbolsa_aluno FOR UPDATE',[$student]);
        if($expectedPercent!==null){
            $total=0;
            foreach($rows as $grant)$total+=Money::cents($grant['percentual']);
            if($total!==Money::cents($expectedPercent))throw new RuleViolation('As bolsas do aluno mudaram. Atualize a prévia da matrícula e confira os valores.');
        }
        foreach($rows as $grant)$this->copyToContract($contract,$grant,$key);
        return (bool)$rows;
    }

    private function copyToContract(int $contract,array $grant,string $key):bool
    {
        $id=(int)$grant['idbolsa_aluno'];
        $old=$this->db->row('SELECT * FROM '.$this->db->table('contrato_bolsas_aluno').' WHERE idcontrato=%d AND idbolsa_aluno=%d FOR UPDATE',[$contract,$id]);
        $fields=array_intersect_key($grant,array_flip(['titulo','descricao','percentual','ativo']));
        if(!(int)$grant['ativo']){
            if(!$old||!(int)$old['ativo'])return false;
            $fields=['ativo'=>0];
        }
        if($old){
            if(!array_diff_assoc($fields,$old))return false;
            $row=(int)$old['idaplicacao'];
            $this->db->update('contrato_bolsas_aluno',$row,$fields);
        }else{
            $row=$this->db->insert('contrato_bolsas_aluno',['idcontrato'=>$contract,'idbolsa_aluno'=>$id]+$fields);
        }
        $this->db->audit('contrato_bolsas_aluno',$row,$old?'atualizar':'aplicar',$old,$this->db->get('contrato_bolsas_aluno',$row,true),$key);
        return true;
    }
}
