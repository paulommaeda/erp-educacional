<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
final class StudentWorkflow
{
    public function __construct(private Store $db,private Operations $ops,private CatalogService $catalog,private AcademicService $academic) {}
    public function batch(array $data,string $key):array
    {
        if(!current_user_can('erp_gerenciar_academico') || !\EducacionalERP\Infrastructure\WordPress\MenuPolicy::can('matriculas'))throw new RuleViolation('Sem permissão para matricular.');
        $ids=$data['alunos']??[];if(!is_array($ids)||!count($ids)||count($ids)>100)throw new RuleViolation('Selecione entre 1 e 100 alunos.');
        $ids=array_values(array_unique(array_map(fn($v)=>Input::id($v),$ids)));sort($ids,SORT_NUMERIC);
        $period=Input::id($data['codperiodo']??null);$class=Input::id($data['idturma']??null);
        return $this->db->atomic(fn()=>$this->ops->run($key,'matricular_lote',array_merge($data,['alunos'=>$ids,'codperiodo'=>$period,'idturma'=>$class]),function()use($ids,$period,$class,$key,$data){
            foreach($ids as $id)$this->db->get('alunos',$id,true);
            $items=[];$v=$this->db->table('aluno_periodos');
            foreach($ids as $id){
                try{
                    $link=$this->db->row("SELECT * FROM $v WHERE idaluno=%d AND codperiodo=%d FOR UPDATE",[$id,$period]);
                    if($link&&($link['idmatricula']!==null||$link['status']!=='aguardando_turma'))throw new RuleViolation('Já possui vínculo concluído neste período.');
                    $result=$this->academic->placeInside($id,$period,$class,$key);
                    $result+=$this->academic->createContractInside((int)$result['idmatricula'],$data,$key);
                    if($link)$this->db->update('aluno_periodos',(int)$link['idvinculoperiodo'],['idmatricula'=>$result['idmatricula'],'status'=>'matriculado']);
                    else $this->db->insert('aluno_periodos',['idaluno'=>$id,'codperiodo'=>$period,'idmatricula'=>$result['idmatricula'],'status'=>'matriculado']);
                    $items[]=['idaluno'=>(string)$id]+$result;
                }catch(RuleViolation $e){$a=$this->db->get('alunos',$id);throw new RuleViolation('RA '.$a['ra'].': '.$e->getMessage().' Nenhum aluno deste lote foi matriculado.');}
            }
            return ['items'=>$items,'total'=>count($items)];
        }));
    }
    public function createStudent(array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->ops->run($key,'novo_aluno',$data,function()use($data,$key){
            if(!empty($data['codpessoa'])) {
                $person=Input::id($data['codpessoa']);
                if(!(int)$this->db->get('pessoas',$person,true)['ativo']) { throw new RuleViolation('Pessoa inativa.'); }
            } else { $person=(int)$this->catalog->createInside('pessoas',$data,$key)['id']; }
            $a=$this->db->table('alunos');
            if($this->db->row("SELECT idaluno FROM $a WHERE codpessoa=%d AND codcoligada=%d FOR UPDATE",[$person,Coligadas::current()])) { throw new RuleViolation('Essa pessoa já possui ficha de aluno. Localize-a na lista de alunos.'); }
            $student=$this->catalog->createInside('alunos',['codpessoa'=>$person,'ra'=>(string)($this->db->get('pessoas',$person)['codigo_pessoa']??$person),'tipo_aluno'=>$data['tipo_aluno']??'Regular'],$key);
            return ['idaluno'=>$student['id'],'codpessoa'=>(string)$person];
        }));
    }
    public function period(int $student,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->ops->run($key,'vincular_periodo',['idaluno'=>$student]+$data,function()use($student,$data,$key){
            if(!(int)$this->db->get('alunos',$student,true)['ativo']) { throw new RuleViolation('Aluno inativo.'); }
            $period=Input::id($data['codperiodo']??null);
            if($this->db->get('periodos_letivos',$period,true)['status']==='encerrado') { throw new RuleViolation('Período encerrado.'); }
            $v=$this->db->table('aluno_periodos');
            $existing=$this->db->row("SELECT * FROM $v WHERE idaluno=%d AND codperiodo=%d FOR UPDATE",[$student,$period]);
            if($existing) { return ['idvinculoperiodo'=>$existing['idvinculoperiodo'],'status'=>$existing['status']]; }
            $m=$this->db->table('matriculas');
            if($this->db->row("SELECT idmatricula FROM $m WHERE idaluno=%d AND codperiodo=%d AND ativo_unico=1 FOR UPDATE",[$student,$period])) { throw new RuleViolation('O aluno já possui matrícula neste período. Consulte a turma na ficha.'); }
            $id=$this->db->insert('aluno_periodos',['idaluno'=>$student,'codperiodo'=>$period,'status'=>'aguardando_turma']);
            $result=['idvinculoperiodo'=>(string)$id,'status'=>'aguardando_turma'];
            $this->db->audit('aluno_periodos',$id,'vincular_periodo',null,['idaluno'=>$student,'codperiodo'=>$period],$key);
            return $result;
        }));
    }
    public function assign(int $student,int $link,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->ops->run($key,'vincular_turma',['idaluno'=>$student,'idvinculoperiodo'=>$link]+$data,function()use($student,$link,$data,$key){
            $this->db->get('alunos',$student,true);
            $v=$this->db->get('aluno_periodos',$link,true);
            if((int)$v['idaluno']!==$student) { throw new RuleViolation('Vínculo não pertence a este aluno.'); }
            if($v['idmatricula']!==null || $v['status']!=='aguardando_turma') { throw new RuleViolation('Turma já definida. Use Transferir turma para uma mudança posterior.'); }
            $result=$this->academic->placeInside($student,(int)$v['codperiodo'],Input::id($data['idturma']??null),$key);
            $result+=$this->academic->createContractInside((int)$result['idmatricula'],$data,$key);
            $this->db->update('aluno_periodos',$link,['idmatricula'=>$result['idmatricula'],'status'=>'matriculado']);
            $this->db->audit('aluno_periodos',$link,'vincular_turma',$v,$result,$key);
            return $result;
        }));
    }
}
