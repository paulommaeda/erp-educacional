<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Infrastructure\Database\Database;
final class Access
{
    public const CAPS=['erp_acessar_admin','erp_gerenciar_pessoas','erp_gerenciar_academico','erp_consultar_financeiro','erp_baixar_lancamentos','erp_estornar_baixas','erp_ajustar_lancamentos','erp_trocar_responsavel_financeiro','erp_exportar_dados','erp_configurar','erp_auditar','erp_gerar_parcelas'];
    public function __construct(private Database $db) {}
    public static function install(): void
    {
        $roles=['erp_financeiro'=>['Financeiro',['erp_consultar_financeiro','erp_gerar_parcelas']], 'erp_secretaria'=>['Secretaria',['erp_acessar_admin','erp_gerenciar_pessoas','erp_gerenciar_academico','erp_consultar_financeiro']],
            'erp_pessoa'=>['Pessoa',[]],'erp_responsavel_academico'=>['Responsável acadêmico',[]],'erp_responsavel_financeiro'=>['Responsável financeiro',[]],'erp_aluno'=>['Aluno',[]],'erp_responsavel'=>['Responsável',[]]];
        foreach ($roles as $slug=>[$label,$caps]) {
            add_role($slug,$label,['read'=>true]);
            $role=get_role($slug);
            if ($role) { foreach (array_merge(['read'],$caps) as $cap) { $role->add_cap($cap); } }
        }
        $admin=get_role('administrator');
        if ($admin) { foreach (self::CAPS as $cap) { $admin->add_cap($cap); } }
    }
    public static function canGenerate():bool
    {
        return self::isAdmin() || (current_user_can('erp_gerar_parcelas') && in_array('erp_financeiro',wp_get_current_user()->roles,true) && MenuPolicy::can('financeiro'));
    }
    public static function isAdmin(): bool
    {
        return current_user_can('manage_options') && in_array('administrator',wp_get_current_user()->roles,true);
    }
    public static function requireAdmin(): void
    {
        if(!self::isAdmin()) { throw new \EducacionalERP\Domain\RuleViolation('Somente administradores do WordPress podem editar cadastros existentes.'); }
    }
    public function person(): ?int
    {
        $t=$this->db->table('pessoa_usuarios'); $p=$this->db->table('pessoas');
        $r=$this->db->row("SELECT pu.codpessoa FROM $t pu JOIN $p p ON p.codpessoa=pu.codpessoa WHERE pu.wp_user_id=%d AND p.ativo=1",[get_current_user_id()]);
        return $r?(int)$r['codpessoa']:null;
    }
    public function canStudent(int $id,string $scope='academic',bool $currentRead=false): bool
    {
        if (!is_user_logged_in()) { return false; }
        if (current_user_can($scope==='finance'?'erp_consultar_financeiro':'erp_gerenciar_academico')) { return true; }
        $person=$this->person();
        if (!$person) { return false; }
        $a=$this->db->table('alunos'); $lock=$currentRead?' FOR UPDATE':'';
        if ($scope==='academic' && $this->db->row("SELECT idaluno FROM $a WHERE idaluno=%d AND codpessoa=%d$lock",[$id,$person])) { return true; }
        $v=$this->db->table('aluno_responsaveis');
        $field=$scope==='finance'?'responsavel_financeiro':($scope==='renew'?'pode_rematricular':'responsavel_academico');
        return (bool)$this->db->row("SELECT idvinculo FROM $v WHERE idaluno=%d AND codpessoa_responsavel=%d AND $field=1 AND inicio_vigencia<=UTC_TIMESTAMP() AND fim_vigencia IS NULL$lock",[$id,$person]);
    }
    public function students(): array
    {
        $a=$this->db->table('alunos'); $p=$this->db->table('pessoas'); $v=$this->db->table('aluno_responsaveis');
        $person=$this->person(); if (!$person) { return []; }
        return $this->db->rows("SELECT a.idaluno,a.ra,p.nome FROM $a a JOIN $p p ON p.codpessoa=a.codpessoa WHERE a.codpessoa=%d OR EXISTS (SELECT 1 FROM $v v WHERE v.idaluno=a.idaluno AND v.codpessoa_responsavel=%d AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND (v.responsavel_academico=1 OR v.responsavel_financeiro=1 OR v.pode_rematricular=1)) ORDER BY p.nome",[$person,$person]);
    }
}
