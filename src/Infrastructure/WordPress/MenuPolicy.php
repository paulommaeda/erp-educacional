<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\RuleViolation;
final class MenuPolicy
{
    public const MENUS=['inicio'=>'Início','usuarios'=>'Usuários','pessoas'=>'Pessoas','alunos'=>'Alunos','academico'=>'Estrutura acadêmica','matriculas'=>'Matrículas','financeiro'=>'Gestão financeira','rematriculas'=>'Ofertas de rematrícula','meus_estudos'=>'Vida acadêmica','meu_financeiro'=>'Meu financeiro','renovacao'=>'Rematrícula','perfil'=>'Meu perfil','exportacao'=>'Exportação','importacao'=>'Importação','perfis'=>'Perfis e acessos','configuracoes'=>'Configurações'];
    private const ADMIN=['exportacao','importacao','perfis','configuracoes'];
    private const CAPS=['pessoas'=>'erp_gerenciar_pessoas','alunos'=>'erp_gerenciar_pessoas','academico'=>'erp_gerenciar_academico','matriculas'=>'erp_gerenciar_academico','rematriculas'=>'erp_gerenciar_academico','financeiro'=>'erp_consultar_financeiro'];
    public static function defaults(string $role):array
    {
        return match($role){
            'erp_financeiro'=>['financeiro'],
            'erp_secretaria'=>['usuarios','pessoas','alunos','academico','matriculas','financeiro','rematriculas'],
            'erp_aluno'=>['meus_estudos'],
            'erp_responsavel_academico'=>['meus_estudos','renovacao'],
            'erp_responsavel_financeiro'=>['meu_financeiro','renovacao'],
            'erp_responsavel'=>['meus_estudos','meu_financeiro','renovacao'],default=>[]};
    }
    public static function can(string $menu):bool
    {
        if(!isset(self::MENUS[$menu])||!is_user_logged_in())return false;
        if(Access::isAdmin())return true;
        if(in_array($menu,['inicio','perfil'],true))return true;
        if(in_array($menu,self::ADMIN,true))return false;
        $settings=get_option('ederp_menu_policy',[]);
        foreach(wp_get_current_user()->roles as $role) {
            if(in_array($menu,$settings[$role]??self::defaults($role),true))return !isset(self::CAPS[$menu])||current_user_can(self::CAPS[$menu]);
        }
        return false;
    }
    public static function any(array $menus):bool {foreach($menus as $menu)if(self::can($menu))return true;return false;}
    public static function available():array {return array_filter(self::MENUS,fn($label,$key)=>self::can($key),ARRAY_FILTER_USE_BOTH);}
    /** Enforced in REST regardless of page/Referer; existing capability and student checks still apply. */
    public static function route(string $path,string $method):bool
    {
        if(Access::isAdmin())return true;
        if(str_starts_with($path,'/usuarios'))return self::can('usuarios');
        if(str_starts_with($path,'/exclusoes')||($path==='/configuracoes'&&$method!=='GET'))return false;
        if(str_starts_with($path,'/perfis')||str_starts_with($path,'/importacao')||str_starts_with($path,'/contas'))return false;
        if(str_starts_with($path,'/contratos/')||str_starts_with($path,'/financeiro/pendentes'))return self::can('financeiro');
        if(str_starts_with($path,'/turmas/')&&str_ends_with($path,'/plano'))return self::any(['matriculas','alunos','academico']);
        if(str_starts_with($path,'/cadastros/'))return self::can(str_contains($path,'/pessoas')?'pessoas':(str_contains($path,'/alunos')?'alunos':'academico'));
        if(str_starts_with($path,'/opcoes/pessoas'))return self::any(['pessoas','alunos','matriculas','financeiro']);
        if(str_starts_with($path,'/opcoes/'))return self::any(['academico','matriculas','alunos','rematriculas']);
        if(str_starts_with($path,'/secretaria/alunos'))return self::any(['alunos','matriculas']);
        if(str_starts_with($path,'/financeiro/alunos'))return self::can('financeiro');
        if(str_starts_with($path,'/pessoas/'))return self::can('pessoas');
        if(str_contains($path,'/ofertas-rematricula'))return self::can(str_starts_with($path,'/alunos/')?'renovacao':'rematriculas');
        if($path==='/rematriculas')return self::can('renovacao');
        if(str_ends_with($path,'/financeiro'))return self::any(['financeiro','meu_financeiro','alunos']);
        if(str_contains($path,'/responsaveis'))return self::can('alunos');
        if(str_contains($path,'/periodos')||str_starts_with($path,'/matriculas'))return self::any(['matriculas','alunos']);
        if(str_ends_with($path,'/matriculas'))return self::any(['alunos','matriculas','meus_estudos']);
        if(str_contains($path,'/lancamentos')||str_contains($path,'/baixas')||str_contains($path,'trocas-responsavel'))return self::can('financeiro');
        return true;
    }
    public static function describe():array
    {
        Access::requireAdmin();$data=get_option('ederp_menu_policy',[]);$rows=[];
        foreach(wp_roles()->roles as $slug=>$r)$rows[]=['slug'=>$slug,'nome'=>$r['name'],'menus'=>$data[$slug]??self::defaults($slug),'user_permissions'=>UserPermissions::forRole($slug),'custom'=>str_starts_with($slug,'erp_custom_')];
        return ['user_actions'=>UserPermissions::ACTIONS,'roles'=>$rows,'menus'=>array_diff_key(self::MENUS,array_flip(['inicio','perfil','exportacao','importacao','perfis','configuracoes']))];
    }
    public static function save(array $data):array
    {
        Access::requireAdmin();$role=(string)($data['role']??'');$r=get_role($role);
        if(!$r||$role==='administrator')throw new RuleViolation('Perfil inválido ou reservado.');
        $menus=$data['menus']??[];
        if(!is_array($menus)||count($menus)>count(self::MENUS))throw new RuleViolation('Menus inválidos.');
        foreach($menus as $menu)if(!is_string($menu)||!isset(self::MENUS[$menu])||in_array($menu,self::ADMIN,true))throw new RuleViolation('Menu reservado ou inválido.');
        if(isset($data['user_permissions'])){if(!is_array($data['user_permissions']))throw new RuleViolation('Permissões inválidas.');UserPermissions::save($role,$data['user_permissions']);}
        $menus=array_values(array_unique($menus));$all=get_option('ederp_menu_policy',[]);$all[$role]=$menus;
        foreach(array_unique(array_values(self::CAPS)) as $cap){$grant=false;foreach(self::CAPS as $menu=>$c)if($cap===$c&&in_array($menu,$menus,true))$grant=true;if($grant)$r->add_cap($cap);else $r->remove_cap($cap);}
        if(in_array('matriculas',$menus,true))$r->add_cap('erp_gerenciar_pessoas');
        update_option('ederp_menu_policy',$all,false);return ['salvo'=>true];
    }
    public static function create(array $data):array
    {
        Access::requireAdmin();$name=\EducacionalERP\Domain\Input::text($data['nome']??null,60);$slug='erp_custom_'.sanitize_title($name);
        if(strlen($slug)>60||get_role($slug))throw new RuleViolation('Já existe um perfil com este nome, ou o nome é muito longo.');
        if(!add_role($slug,$name,['read'=>true]))throw new RuleViolation('Não foi possível criar o perfil.');
        return ['slug'=>$slug,'nome'=>$name];
    }
}
