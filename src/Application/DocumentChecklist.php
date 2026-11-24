<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,RuleViolation};
/** One definition of actionable missing documents, shared by the home screen and reminders. */
final class DocumentChecklist
{
    public const CONDITIONS=['todos','pai','mae','pai_e_mae','financeiro_outro'];

    public function __construct(private Store $db){}
    public function activeStudent(int $id):?array
    {
        return $this->db->row('SELECT a.idaluno,a.codcoligada,a.ra,p.nome,c.nome AS colegio FROM '.$this->db->table('alunos').' a JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=a.codpessoa JOIN '.$this->db->table('coligadas').' c ON c.codcoligada=a.codcoligada WHERE a.idaluno=%d AND a.ativo=1 AND p.ativo=1 AND c.ativo=1',[$id]);
    }
    public function conditions(int $student,int $company):array
    {
        $links=$this->db->table('aluno_responsaveis');
        $people=$this->db->table('pessoas');
        $rows=$this->db->rows(
            "SELECT v.parentesco,v.responsavel_financeiro FROM $links v JOIN $people p ON p.codpessoa=v.codpessoa_responsavel
             WHERE v.idaluno=%d AND v.codcoligada=%d AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND p.ativo=1",
            [$student,$company]
        );
        $conditions=['todos'=>true,'pai'=>false,'mae'=>false,'financeiro_outro'=>false];
        foreach($rows as $row){
            if(in_array($row['parentesco'],['pai','mae'],true)){
                $conditions[$row['parentesco']]=true;
            }elseif((int)$row['responsavel_financeiro']===1&&$row['parentesco']!=='proprio_aluno'){
                $conditions['financeiro_outro']=true;
            }
        }
        $conditions['pai_e_mae']=$conditions['pai']&&$conditions['mae'];
        return $conditions;
    }
    public function pending(int $student):array
    {
        $a=$this->activeStudent($student);if(!$a)return [];$types=$this->db->table('tipos_documento_aluno');$docs=$this->db->table('aluno_documentos');
        $rows=$this->db->rows("SELECT t.idtipo_documento,t.nome,t.obrigatorio,t.condicao,d.iddocumento,d.status,d.motivo FROM $types t LEFT JOIN $docs d ON d.iddocumento=(SELECT MAX(lastdoc.iddocumento) FROM $docs lastdoc WHERE lastdoc.idaluno=%d AND lastdoc.idtipo_documento=t.idtipo_documento) WHERE t.codcoligada=%d AND t.ativo=1 AND ((d.iddocumento IS NULL AND t.obrigatorio=1) OR d.status='rejeitado') ORDER BY t.ordem,t.nome,t.idtipo_documento",[$student,(int)$a['codcoligada']]);
        if(!$rows)return [];
        $conditions=$this->conditions($student,(int)$a['codcoligada']);
        $pending=[];
        foreach($rows as $row){
            if(!($conditions[$row['condicao']]??false))continue;
            unset($row['condicao']);
            $pending[]=$row;
        }
        return $pending;
    }
    public function guardians(int $student,string $scope='ambos'):array
    {
        $a=$this->activeStudent($student);if(!$a)return [];
        $filter=match($scope){'academico'=>'v.responsavel_academico=1','financeiro'=>'v.responsavel_financeiro=1',default=>'(v.responsavel_academico=1 OR v.responsavel_financeiro=1 OR v.pode_rematricular=1)'};
        $rows=$this->db->rows('SELECT p.codpessoa,p.nome,p.email FROM '.$this->db->table('aluno_responsaveis').' v JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=v.codpessoa_responsavel WHERE v.idaluno=%d AND v.codcoligada=%d AND p.ativo=1 AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND '.$filter.' ORDER BY v.idvinculo',[$student,(int)$a['codcoligada']]);
        $out=[];foreach($rows as $row){$email=strtolower(trim((string)$row['email']));if(is_email($email)&&!preg_match('/[\r\n]/',$email)&&!isset($out[$email]))$out[$email]=$row+['destinatario'=>$email];}return $out;
    }
    public function homeAlerts():array
    {
        if(!is_user_logged_in())throw new RuleViolation('Entre na sua conta.');
        $rows=$this->db->rows('SELECT DISTINCT v.idaluno FROM '.$this->db->table('aluno_responsaveis').' v JOIN '.$this->db->table('pessoa_usuarios').' u ON u.codpessoa=v.codpessoa_responsavel JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=v.codpessoa_responsavel WHERE u.wp_user_id=%d AND p.ativo=1 AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND (v.responsavel_academico=1 OR v.responsavel_financeiro=1 OR v.pode_rematricular=1) ORDER BY v.idaluno',[get_current_user_id()]);
        $out=[];foreach($rows as $row){$id=(int)$row['idaluno'];$a=$this->activeStudent($id);if(!$a)continue;$pending=$this->pending($id);if($pending)$out[]=['idaluno'=>$id,'aluno'=>$a['nome'],'ra'=>$a['ra'],'colegio'=>$a['colegio'],'pendencias'=>$pending,'total'=>count($pending)];}return $out;
    }
}
