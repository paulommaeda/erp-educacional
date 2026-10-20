<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\{Store,RuleViolation};
/** Family actions require an actual current guardian link, never a staff capability shortcut. */
final class FamilyAccess
{
    public function __construct(private Store $db){}
    public function student(int $id,bool $lock=false):array
    {
        if(!is_user_logged_in())throw new RuleViolation('Entre na sua conta.');
        $a=$this->db->get('alunos',$id,$lock);$p=$this->db->get('pessoas',(int)$a['codpessoa'],$lock);
        $v=$this->db->table('aluno_responsaveis');$u=$this->db->table('pessoa_usuarios');$people=$this->db->table('pessoas');
        $link=$this->db->row("SELECT v.idvinculo FROM $v v JOIN $u u ON u.codpessoa=v.codpessoa_responsavel JOIN $people p ON p.codpessoa=u.codpessoa WHERE v.idaluno=%d AND v.codcoligada=%d AND u.wp_user_id=%d AND p.ativo=1 AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND (v.responsavel_academico=1 OR v.responsavel_financeiro=1 OR v.pode_rematricular=1)".($lock?' FOR UPDATE':''),[$id,(int)$a['codcoligada'],get_current_user_id()]);
        if(!(int)$a['ativo']||!(int)$p['ativo']||!$link)throw new RuleViolation('Seu usuário não possui um vínculo de responsável vigente com este aluno.');
        return $a;
    }
    public function can(int $id):bool {try{$this->student($id);return true;}catch(RuleViolation $e){return false;}}
    public function localStudents(int $id):array
    {
        $a=$this->student($id);$rows=$this->db->rows('SELECT idaluno FROM '.$this->db->table('alunos').' WHERE codpessoa=%d AND ativo=1 ORDER BY idaluno',[(int)$a['codpessoa']]);
        return array_values(array_filter(array_map(fn($r)=>(int)$r['idaluno'],$rows),fn($local)=>$this->can($local)));
    }
}
