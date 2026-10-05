<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,SchoolIdentity};
use EducacionalERP\Domain\RuleViolation;
/** Selective logical export. Identifiers come exclusively from the bundled schema. */
final class DataExport
{
    public const GROUPS=[
        'pessoas'=>['nome'=>'Pessoas e endereços','tabelas'=>['pessoas','pessoa_enderecos','estados_civis','numeracao_pessoas']],
        'usuarios'=>['nome'=>'Usuários vinculados (sem senhas)','tabelas'=>['pessoa_usuarios','usuarios']],
        'alunos'=>['nome'=>'Alunos e responsáveis','tabelas'=>['alunos','aluno_responsaveis']],
        'academico'=>['nome'=>'Estrutura acadêmica e planos','tabelas'=>['coligadas','periodos_letivos','cursos','turnos','planos_pagamento','turmas']],
        'matriculas'=>['nome'=>'Matrículas e rematrículas','tabelas'=>['aluno_periodos','matriculas','matricula_movimentacoes','ofertas_rematricula','oferta_turmas','rematriculas']],
        'financeiro'=>['nome'=>'Contratos, parcelas e financeiro','tabelas'=>['contratos','parcelas','lancamentos','baixas','baixa_estornos','lancamento_ajustes','trocas_responsavel','troca_contratos','troca_lancamentos','contrato_descontos','desconto_lancamentos']],
        'chat'=>['nome'=>'Chat, canais e anexos privados','tabelas'=>['chat_canais','chat_membros','chat_conversas','chat_mensagens','chat_anexos','chat_leituras']],
        'historico_escolar'=>['nome'=>'Históricos escolares anteriores','tabelas'=>['tipos_disciplina','historicos_anteriores','historico_anos','historico_disciplinas']],
        'historico'=>['nome'=>'Log de modificações','tabelas'=>['auditoria']],
        'personalizacao'=>['nome'=>'Identidade visual e cores','tabelas'=>[]],
        'configuracoes'=>['nome'=>'Configurações e perfis de acesso','tabelas'=>[]],
    ];
    public function __construct(private Database $db) {}
    public function catalog():array
    {
        Access::requireAdmin();return ['categorias'=>self::GROUPS,'coligadas'=>$this->db->rows('SELECT codcoligada,nome,cnpj,ativo FROM '.$this->db->table('coligadas').' ORDER BY nome'),'coligada_atual'=>Coligadas::current(),'versao'=>defined('EDERP_VERSION')?EDERP_VERSION:'0.12.9'];
    }
    private function companies(array $d):array
    {
        $ids=$d['coligadas']??[];if(!is_array($ids)||!$ids||count($ids)>1000)throw new RuleViolation('Selecione pelo menos uma coligada.');
        $out=[];foreach($ids as $id){if(!is_scalar($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id))throw new RuleViolation('Coligada inválida.');$id=(int)$id;$this->db->get('coligadas',$id);$out[]=$id;}return array_values(array_unique($out));
    }
    public function settings(array $d):array
    {
        Access::requireAdmin();$companies=$this->companies($d);$groups=$d['categorias']??[];
        if(!is_array($groups)||array_diff($groups,array_keys(self::GROUPS)))throw new RuleViolation('Categoria inválida.');
        $result=['coligadas'=>[]];foreach($companies as $id)$result['coligadas'][(string)$id]=Coligadas::within($id,function()use($groups){
            $out=[];if(in_array('personalizacao',$groups,true)){$out['identidade_visual']=SchoolIdentity::read();$out['logo_url']=SchoolIdentity::logo();}
            if(in_array('configuracoes',$groups,true))$out['configuracoes']=['codperiodo'=>SchoolSettings::current(),'texto_apresentacao_rematricula'=>SchoolSettings::renewalIntroduction(),'texto_bloqueio_rematricula'=>SchoolSettings::renewalBlockMessage(),'manual_aluno'=>SchoolSettings::manual(),'desconto_pontualidade'=>get_option(Coligadas::option('ederp_pontualidade'),'0.00')];return $out;
        });
        if(in_array('configuracoes',$groups,true)){
            $roles=[];foreach(wp_roles()->roles as $slug=>$role)if(str_starts_with($slug,'erp_'))$roles[$slug]=$role;
            $result['globais']=['menus'=>get_option('ederp_menu_policy',[]),'permissoes_usuarios'=>get_option('ederp_user_permissions',[]),'perfis'=>$roles];
        }return $result;
    }
    public function page(array $d):array
    {
        Access::requireAdmin();$companies=$this->companies($d);$table=$d['tabela']??'';$allowed=[];foreach(self::GROUPS as $g)$allowed=array_merge($allowed,$g['tabelas']);
        if(!is_string($table)||!in_array($table,$allowed,true))throw new RuleViolation('Tabela não exportável.');
        $source=$table==='usuarios'?'pessoa_usuarios':$table;$schema=$this->db->schema()[$source];$pk=$schema['pk'];$cursor=$d['cursor']??[];
        if(!is_array($cursor)||($cursor&&count($cursor)!==count($pk)))throw new RuleViolation('Cursor inválido.');
        foreach($cursor as $v)if(!is_scalar($v)||!preg_match('/^[0-9]{1,18}$/D',(string)$v))throw new RuleViolation('Cursor inválido.');
        $where=[];$args=[];
        if(isset($schema['columns']['codcoligada'])){$where[]='codcoligada IN ('.implode(',',array_fill(0,count($companies),'%d')).')';$args=$companies;}
        if($cursor){$or=[];foreach($pk as $i=>$column){$and=[];for($j=0;$j<$i;$j++){$and[]=$pk[$j].'=%d';$args[]=(int)$cursor[$j];}$and[]=$column.'>%d';$args[]=(int)$cursor[$i];$or[]='('.implode(' AND ',$and).')';}$where[]='('.implode(' OR ',$or).')';}
        $limit=200;$rows=$this->db->rows('SELECT * FROM '.$this->db->table($source).($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.implode(',',$pk).' LIMIT '.($limit+1),$args);
        $more=count($rows)>$limit;if($more)array_pop($rows);$last=$rows?end($rows):null;$next=$more?array_map(fn($k)=>(string)$last[$k],$pk):null;
        if($table==='usuarios'){$users=[];foreach($rows as $link){$u=get_userdata((int)$link['wp_user_id']);if($u)$users[]=['ID'=>(int)$u->ID,'codpessoa'=>$link['codpessoa'],'user_login'=>$u->user_login,'display_name'=>$u->display_name,'user_email'=>$u->user_email,'roles'=>array_values($u->roles)];}$rows=$users;}
        if(in_array($table,['contratos','lancamentos'],true)){$companiesData=new CompanyData($this->db);$rows=array_map(fn($r)=>$companiesData->decorate($r),$rows);}
        return ['tabela'=>$table,'registros'=>$rows,'proximo_cursor'=>$next,'compartilhada'=>!isset($schema['columns']['codcoligada'])];
    }
}
