<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Infrastructure\Database\Database;
final class Access
{
    public const CAPS=['erp_acessar_admin','erp_gerenciar_pessoas','erp_gerenciar_academico','erp_consultar_financeiro','erp_baixar_lancamentos','erp_estornar_baixas','erp_ajustar_lancamentos','erp_trocar_responsavel_financeiro','erp_exportar_dados','erp_configurar','erp_auditar','erp_gerar_parcelas','erp_chat_atender','erp_chat_supervisao'];
    public function __construct(private Database $db) {}
    public static function install(): void
    {
        $roles=['erp_supervisao'=>['Supervisão',['erp_chat_supervisao']], 'erp_coordenacao'=>['Coordenação',['erp_chat_atender']], 'erp_orientacao'=>['Orientação',['erp_chat_atender']], 'erp_financeiro'=>['Financeiro',['erp_consultar_financeiro','erp_gerar_parcelas']], 'erp_secretaria'=>['Secretaria',['erp_acessar_admin','erp_gerenciar_pessoas','erp_gerenciar_academico','erp_consultar_financeiro','erp_chat_atender']],
            'erp_pessoa'=>['Pessoa',[]],'erp_responsavel_academico'=>['Responsável acadêmico',[]],'erp_responsavel_financeiro'=>['Responsável financeiro',[]],'erp_aluno'=>['Aluno',[]],'erp_responsavel'=>['Responsável',[]]];
        foreach ($roles as $slug=>[$label,$caps]) {
            add_role($slug,$label,['read'=>true]);
            $role=get_role($slug);
            if ($role) { foreach (array_merge(['read'],$caps) as $cap) { $role->add_cap($cap); } }
        }
        if(!get_option('ederp_chat_menus_installed')){$policy=get_option('ederp_menu_policy',[]);foreach(['erp_secretaria','erp_coordenacao','erp_orientacao','erp_supervisao','erp_responsavel','erp_responsavel_academico','erp_responsavel_financeiro'] as $slug)if(isset($policy[$slug]))$policy[$slug]=array_values(array_unique([...$policy[$slug],'chat']));foreach(wp_roles()->roles as $slug=>$meta)if(str_starts_with($slug,'erp_custom_')&&preg_match('/secret|coordena|orienta/iu',$meta['name']))$policy[$slug]=array_values(array_unique([...($policy[$slug]??[]),'chat']));update_option('ederp_menu_policy',$policy,false);update_option('ederp_chat_menus_installed',true,false);}
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
    /** One family entry per shared person, with all authorized local student IDs. */
    public function students(): array
    {
        $a=$this->db->table('alunos');$p=$this->db->table('pessoas');$v=$this->db->table('aluno_responsaveis');$m=$this->db->table('matriculas');$periods=$this->db->table('periodos_letivos');
        $person=$this->person();if(!$person)return [];
        $rows=$this->db->rows("SELECT a.idaluno,a.codpessoa,a.codcoligada,a.ra,p.nome,(SELECT MAX(pl.data_inicio) FROM $m m JOIN $periods pl ON pl.codperiodo=m.codperiodo WHERE m.idaluno=a.idaluno AND m.ativo_unico=1) AS ultimo_periodo FROM $a a JOIN $p p ON p.codpessoa=a.codpessoa WHERE a.codpessoa=%d OR EXISTS (SELECT 1 FROM $v v WHERE v.idaluno=a.idaluno AND v.codpessoa_responsavel=%d AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND (v.responsavel_academico=1 OR v.responsavel_financeiro=1 OR v.pode_rematricular=1)) ORDER BY p.nome,ultimo_periodo DESC,a.idaluno DESC",[$person,$person]);
        $out=[];foreach($rows as $row){$id=(string)$row['codpessoa'];if(!isset($out[$id])){unset($row['ultimo_periodo']);$out[$id]=$row;$out[$id]['idalunos']=[];}$out[$id]['idalunos'][]=(string)$row['idaluno'];}return array_values($out);
    }
    /** Scope checked for every local record; a link in one company grants no access to another. */
    public function accessibleIds(int $student,string $scope):array
    {
        $a=$this->db->table('alunos');$row=$this->db->row("SELECT codpessoa FROM $a WHERE idaluno=%d",[$student]);if(!$row)return [];
        $ids=[];foreach($this->db->rows("SELECT idaluno FROM $a WHERE codpessoa=%d ORDER BY idaluno",[$row['codpessoa']]) as $local)if($this->canStudent((int)$local['idaluno'],$scope))$ids[]=(int)$local['idaluno'];return $ids;
    }
}
