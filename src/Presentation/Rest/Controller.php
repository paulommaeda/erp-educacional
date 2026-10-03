<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Rest;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts,MenuPolicy};
use EducacionalERP\Application\{AcademicService,CatalogService,FinanceService,ExportService,RenewalService,StudentWorkflow,SchoolSettings,EnrollmentDirectory,DeletionService,Operations};
use EducacionalERP\Domain\{Input,RuleViolation,UsernameRequired};
final class Controller
{
    public const NS='erp-educacional/v1';
    public function __construct(private Database $db,private Access $access,private AcademicService $academic,private CatalogService $catalog,private FinanceService $finance,private ExportService $export,private RenewalService $renewal,private StudentWorkflow $workflow) {}
    private function route(string $path,string $method,callable $permission,callable $handler): void
    {
        register_rest_route(self::NS,$path,['methods'=>$method,'permission_callback'=>function($r)use($permission,$path,$method){
            if(get_option('ederp_schema_version')!==Installer::VERSION || get_option('ederp_schema_error')) { return new \WP_Error('erp_schema','Banco do ERP requer migração.',['status'=>503]); }
            if(!is_user_logged_in()) { return new \WP_Error('erp_login','Autenticação necessária.',['status'=>401]); }
            return (MenuPolicy::route($path,$method) && $permission($r))?true:new \WP_Error('erp_forbidden','Sem permissão para este recurso.',['status'=>403]);
        },'callback'=>function($r)use($handler){
            $before=$this->db->wp->suppress_errors(true);
            try {
                $result=$handler($r);
                $response=new \WP_REST_Response($result,200);
                $response->header('Cache-Control','private, no-store, max-age=0');
                return $response;
            } catch(UsernameRequired $e) { return new \WP_Error('erp_username_required',$e->getMessage(),['status'=>409,'field'=>'user_login']); }
            catch(RuleViolation $e) { return new \WP_Error('erp_regra',$e->getMessage(),['status'=>422]); }
            catch(\Throwable $e) { return new \WP_Error('erp_operacao','Operação não concluída. Verifique dados, duplicidades e diagnóstico do banco.',['status'=>500]); }
            finally { $this->db->wp->suppress_errors($before); }
        }]);
    }
    private function payload(\WP_REST_Request $r): array
    {
        $d=$r->get_json_params();
        if(!is_array($d) || array_is_list($d)) { throw new RuleViolation('Envie um objeto JSON.'); }
        if(strlen($r->get_body())>100000) { throw new RuleViolation('Requisição acima do limite.'); }
        return $d;
    }
    private function key(\WP_REST_Request $r): string { return Input::key($r->get_header('Idempotency-Key')); }
    public function register(): void
    {
        $cap=static fn(string $c)=>static fn()=>current_user_can($c);
        $id='(?P<id>[1-9][0-9]{0,17})';
        $this->route('/turmas/'.$id.'/plano','GET',$cap('erp_gerenciar_academico'),fn($r)=>$this->academic->planForClass((int)$r['id']));
        $this->route('/financeiro/pendentes','GET',fn()=>Access::canGenerate(),fn($r)=>$this->pendingContracts($r));
        $this->route('/contratos/'.$id.'/parcelas','POST',fn()=>Access::canGenerate(),fn($r)=>$this->academic->generateInstallments((int)$r['id'],$this->key($r)));
        $this->route('/estados-civis','GET',fn()=>true,fn()=>(new \EducacionalERP\Application\CivilStatus($this->db))->all());
        $this->route('/estados-civis','POST',fn()=>Access::isAdmin(),fn($r)=>(new \EducacionalERP\Application\CivilStatus($this->db))->save($this->payload($r),$this->key($r)));
        $this->route('/configuracoes','GET',fn()=>true,fn()=>(new SchoolSettings($this->db))->read());
        $this->route('/configuracoes','POST',fn()=>Access::isAdmin(),fn($r)=>(new SchoolSettings($this->db))->save($this->payload($r),$this->key($r)));
        foreach(DeletionService::TYPES as $type)$this->route('/exclusoes/'.$type.'/'.$id,'POST',fn()=>Access::isAdmin(),fn($r)=>(new DeletionService($this->db,new Operations($this->db)))->remove($type,(int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/matriculas','GET',$cap('erp_gerenciar_academico'),fn($r)=>(new EnrollmentDirectory($this->db))->search($r->get_params()));
        $this->route('/matriculas/lote','POST',$cap('erp_gerenciar_academico'),fn($r)=>$this->workflow->batch($this->payload($r),$this->key($r)));
        $this->route('/matriculas/filtros','GET',$cap('erp_gerenciar_academico'),fn()=>[
            'turmas'=>$this->db->rows('SELECT idturma,codperiodo,idcurso,idturno,nome FROM '.$this->db->table('turmas').' ORDER BY nome'),
            'cursos'=>$this->db->rows('SELECT idcurso,nome FROM '.$this->db->table('cursos').' ORDER BY nome'),
            'turnos'=>$this->db->rows('SELECT idturno,nome FROM '.$this->db->table('turnos').' ORDER BY nome')]);
        $this->route('/importacao/(?P<tipo>pessoas|alunos|turmas)','POST',fn()=>Access::isAdmin(),fn($r)=>(new \EducacionalERP\Application\ImportService($this->db,$this->catalog))->row($r['tipo'],$this->payload($r),$this->key($r)));
        $this->route('/me/perfil','GET',fn()=>true,fn()=>$this->ownProfile());
        $this->route('/me/perfil','POST',fn()=>true,fn($r)=>$this->catalog->updateOwn($this->payload($r),$this->key($r)));
        $this->route('/me/foto','POST',fn()=>$this->access->person()!==null,fn($r)=>$this->uploadPhoto($r));
        $this->route('/financeiro/alunos','GET',fn()=>current_user_can('erp_consultar_financeiro') && MenuPolicy::can('financeiro'),fn($r)=>$this->studentDirectory($r));
        $this->route('/painel','GET',fn()=>true,fn($r)=>$this->dashboard(SchoolSettings::forViewer($r->get_param('codperiodo'))));
        $this->route('/perfis','GET',fn()=>Access::isAdmin(),fn()=>MenuPolicy::describe());
        $this->route('/perfis','POST',fn()=>Access::isAdmin(),fn($r)=>$this->policyChange('criar',$this->payload($r),$this->key($r)));
        $this->route('/perfis/menus','POST',fn()=>Access::isAdmin(),fn($r)=>$this->policyChange('menus',$this->payload($r),$this->key($r)));
        $this->route('/perfis/pessoa/'.$id,'GET',fn()=>Access::isAdmin(),fn($r)=>(new Accounts($this->db))->summary((int)$r['id']));
        $this->route('/perfis/atribuicao','POST',fn()=>Access::isAdmin(),fn($r)=>$this->assignRole($this->payload($r),$this->key($r)));
        $this->route('/contas/sincronizar','POST',fn()=>Access::isAdmin(),fn($r)=>(new Accounts($this->db))->migrate(max(0,(int)($this->payload($r)['cursor']??0))));
        $this->route('/me','GET',static fn()=>true,fn()=>['wp_user_id'=>(string)get_current_user_id(),'codpessoa'=>$this->access->person(),'alunos'=>$this->access->students()]);
        $this->route('/me/alunos','GET',static fn()=>true,fn()=>$this->access->students());
        foreach(CatalogService::TABLES as $table) {
            $permission=$cap(in_array($table,['pessoas','alunos'],true)?'erp_gerenciar_pessoas':'erp_gerenciar_academico');
            $this->route('/cadastros/'.$table,'POST',$permission,fn($r)=>$this->catalog->create($table,$this->payload($r),$this->key($r)));
            if($table==='periodos_letivos')$this->route('/cadastros/periodos_letivos/'.$id,'GET',$permission,fn($r)=>$this->db->get('periodos_letivos',(int)$r['id']));
            $this->route('/cadastros/'.$table,'GET',$permission,fn($r)=>$this->listCatalog($table,$r));
            $this->route('/cadastros/'.$table.'/'.$id,'POST',fn()=>Access::isAdmin(),fn($r)=>$this->catalog->edit($table,(int)$r['id'],$this->payload($r),$this->key($r)));
        }
        $this->route('/periodos/copiar','POST',fn()=>Access::isAdmin(),fn($r)=>(new \EducacionalERP\Application\PeriodCopyService($this->db,$this->catalog,new Operations($this->db)))->copy($this->payload($r),$this->key($r)));
        $this->route('/secretaria/alunos','GET',$cap('erp_gerenciar_pessoas'),fn($r)=>$this->studentDirectory($r));
        $this->route('/secretaria/alunos','POST',$cap('erp_gerenciar_pessoas'),fn($r)=>$this->workflow->createStudent($this->payload($r),$this->key($r)));
        $this->route('/secretaria/alunos/'.$id,'GET',$cap('erp_gerenciar_pessoas'),fn($r)=>$this->studentSheet((int)$r['id']));
        $this->route('/pessoas/'.$id,'POST',fn()=>Access::isAdmin(),fn($r)=>$this->catalog->updatePerson((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/alunos/'.$id.'/periodos','POST',$cap('erp_gerenciar_academico'),fn($r)=>$this->workflow->period((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/alunos/'.$id.'/periodos/(?P<link>[1-9][0-9]{0,17})/turma','POST',$cap('erp_gerenciar_academico'),fn($r)=>$this->workflow->assign((int)$r['id'],(int)$r['link'],$this->payload($r),$this->key($r)));
        $this->route('/matriculas/'.$id.'/contrato','POST',$cap('erp_gerenciar_academico'),fn($r)=>$this->academic->createContract((int)$r['id'],$this->payload($r),$this->key($r)));
        foreach(['pessoas','periodos_letivos','cursos','turnos','turmas','planos_pagamento'] as $type) {
            $this->route('/opcoes/'.$type,'GET',$cap($type==='pessoas'?'erp_gerenciar_pessoas':'erp_gerenciar_academico'),fn($r)=>$this->options($type,$r));
        }
        $this->route('/pessoa-usuarios','POST',fn()=>Access::isAdmin(),fn($r)=>$this->catalog->bindUser($this->payload($r),$this->key($r)));
        $this->route('/alunos/'.$id.'/responsaveis','GET',$cap('erp_gerenciar_pessoas'),fn($r)=>$this->guardianView((int)$r['id']));
        $this->route('/alunos/'.$id.'/responsaveis','POST',$cap('erp_gerenciar_pessoas'),fn($r)=>$this->catalog->link((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/matriculas','POST',$cap('erp_gerenciar_academico'),fn($r)=>$this->academic->enroll($this->payload($r),$this->key($r)));
        $this->route('/matriculas/'.$id.'/transferencias','POST',fn()=>Access::isAdmin(),fn($r)=>$this->academic->transfer((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/alunos/'.$id.'/trocas-responsavel-financeiro','POST',$cap('erp_trocar_responsavel_financeiro'),fn($r)=>$this->finance->changeGuardian((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/lancamentos/'.$id.'/baixas','POST',$cap('erp_baixar_lancamentos'),fn($r)=>$this->finance->pay((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/lancamentos/'.$id.'/ajustes','POST',$cap('erp_ajustar_lancamentos'),fn($r)=>$this->finance->adjust((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/baixas/'.$id.'/estornos','POST',$cap('erp_estornar_baixas'),fn($r)=>$this->finance->reverse((int)$r['id'],$this->payload($r),$this->key($r)));
        $this->route('/ofertas-rematricula','POST',$cap('erp_gerenciar_academico'),fn($r)=>$this->renewal->createOffer($this->payload($r),$this->key($r)));
        $this->route('/alunos/'.$id.'/ofertas-rematricula','GET',fn($r)=>$this->access->canStudent((int)$r['id'],'renew'),fn($r)=>$this->renewal->offers((int)$r['id'],SchoolSettings::forViewer($r->get_param('codperiodo'))));
        $this->route('/rematriculas','POST',fn()=>$this->access->person()!==null,fn($r)=>$this->renewal->renew($this->payload($r),$this->key($r)));
        $this->route('/alunos/'.$id.'/matriculas','GET',fn($r)=>$this->access->canStudent((int)$r['id']),fn($r)=>$this->academicView((int)$r['id'],SchoolSettings::forViewer($r->get_param('codperiodo'))));
        $this->route('/alunos/'.$id.'/financeiro','GET',fn($r)=>$this->access->canStudent((int)$r['id'],'finance'),fn($r)=>$this->financeView((int)$r['id'],SchoolSettings::forViewer($r->get_param('codperiodo'),'finance')));
        $this->route('/exportacao/alunos/'.$id,'GET',$cap('erp_exportar_dados'),fn($r)=>$this->export->student((int)$r['id'],Input::id($r->get_param('codperiodo')?:SchoolSettings::current())));
        $this->route('/exportacao/alunos','GET',$cap('erp_exportar_dados'),fn($r)=>$this->export->collection(Input::id($r->get_param('codperiodo')?:SchoolSettings::current()),max(0,(int)$r->get_param('cursor')),max(1,min(20,(int)($r->get_param('limit')?:10)))));
    }
    private function pendingContracts(\WP_REST_Request $r):array
    {
        $ct=$this->db->table('contratos');$m=$this->db->table('matriculas');$a=$this->db->table('alunos');$p=$this->db->table('pessoas');$t=$this->db->table('turmas');$pl=$this->db->table('periodos_letivos');
        $join=" FROM $ct c JOIN $m m ON m.idmatricula=c.idmatricula JOIN $a a ON a.idaluno=m.idaluno JOIN $p p ON p.codpessoa=a.codpessoa JOIN $t t ON t.idturma=m.idturma_atual JOIN $pl pl ON pl.codperiodo=m.codperiodo WHERE c.parcelas_geradas=0 AND c.status='ativo' AND m.status='ativa'";$args=[];
        $period=SchoolSettings::resolve($r->get_param('codperiodo'));if($period){$join.=' AND m.codperiodo=%d';$args[]=$period;}
        $search=trim((string)$r->get_param('search'));if($search!==''){$join.=' AND (p.nome LIKE %s OR a.ra LIKE %s)';$term='%'.$this->db->wp->esc_like($search).'%';$args[]=$term;$args[]=$term;}
        $page=max(1,(int)$r->get_param('page'));$total=(int)$this->db->row('SELECT COUNT(*) AS n'.$join,$args)['n'];
        return ['items'=>$this->db->rows('SELECT c.idcontrato,c.numero,a.ra,p.nome AS aluno,t.nome AS turma,pl.codigo AS periodo,c.plano_nome_snapshot AS plano,c.valor_original_total,c.valor_liquido_total,c.quantidade_parcelas,c.primeiro_vencimento'.$join.' ORDER BY c.idcontrato LIMIT 20 OFFSET %d',[...$args,($page-1)*20]),'total'=>$total,'page'=>$page];
    }
    public function studentDirectory(\WP_REST_Request $r): array
    {
        $a=$this->db->table('alunos'); $p=$this->db->table('pessoas');
        $page=max(1,min(100000,(int)($r->get_param('page')?:1))); $where=''; $args=[];
        $q=sanitize_text_field((string)$r->get_param('search'));
        if($q!=='') { $where=' WHERE (p.nome LIKE %s OR a.ra LIKE %s)'; $term='%'.$this->db->wp->esc_like($q).'%'; $args=[$term,$term]; }
        $count=$this->db->row("SELECT COUNT(*) AS n FROM $a a JOIN $p p ON p.codpessoa=a.codpessoa$where",$args);
        return ['items'=>$this->db->rows("SELECT a.idaluno,a.ra,a.ativo,a.versao,p.nome,p.data_nascimento FROM $a a JOIN $p p ON p.codpessoa=a.codpessoa$where ORDER BY p.nome,a.idaluno LIMIT 20 OFFSET %d",array_merge($args,[($page-1)*20])),'page'=>$page,'total'=>(int)$count['n']];
    }
    private function ownProfile():array
    {
        $person=$this->access->person();if(!$person)throw new RuleViolation('Sua conta não está vinculada a uma pessoa. Procure a secretaria.');
        $p=(new \EducacionalERP\Application\CivilStatus($this->db))->decorate($this->db->get('pessoas',$person));unset($p['codpessoa_origem'],$p['ativo']);
        $p['foto_url']=!empty($p['foto_attachment_id'])?wp_get_attachment_image_url((int)$p['foto_attachment_id'],'thumbnail'):null;
        return ['pessoa'=>$p,'conta'=>(new Accounts($this->db))->summary($person),'redefinir_senha'=>wp_lostpassword_url(\EducacionalERP\Presentation\Portal\Portal::url('perfil'))];
    }
    private function dashboard(int $period=0):array
    {
        $cards=[];
        foreach(['pessoas'=>['pessoas','Pessoas cadastradas'],'alunos'=>['alunos','Alunos cadastrados'],'academico'=>['turmas','Turmas cadastradas'],'matriculas'=>['matriculas','Matrículas registradas']] as $menu=>[$table,$title])if(MenuPolicy::can($menu))$cards[]=['menu'=>$menu,'titulo'=>$title,'total'=>(int)$this->db->row('SELECT COUNT(*) AS n FROM '.$this->db->table($table).($period&&in_array($table,['turmas','matriculas'],true)?' WHERE codperiodo=%d':''),$period&&in_array($table,['turmas','matriculas'],true)?[$period]:[])['n']];
        return ['cards'=>$cards,'alunos'=>$this->access->students()];
    }
    private function uploadPhoto(\WP_REST_Request $r):array
    {
        $files=$r->get_file_params();$f=$files['foto']??null;
        if(!$f||!empty($f['error'])||(int)$f['size']>5*1024*1024)throw new RuleViolation('Envie uma imagem JPG, PNG ou WebP de até 5 MB.');
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/media.php';
        $info=wp_getimagesize($f['tmp_name']);
        if(!$info||$info[0]>4096||$info[1]>4096||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true))throw new RuleViolation('Imagem inválida ou maior que 4096 × 4096 pixels.');
        $id=media_handle_upload('foto',0,['post_author'=>get_current_user_id(),'post_title'=>'Foto do perfil'],['test_form'=>false,'mimes'=>['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']]);
        if(is_wp_error($id))throw new RuleViolation('Não foi possível enviar a foto: '.$id->get_error_message());
        return ['id'=>(string)$id,'url'=>wp_get_attachment_image_url($id,'thumbnail')];
    }
    private function policyChange(string $action,array $data,string $key):array
    {
        Access::requireAdmin();$result=$action==='criar'?MenuPolicy::create($data):MenuPolicy::save($data);
        $this->db->audit('perfis_acesso',get_current_user_id(),$action,null,$data,$key);return $result;
    }
    private function assignRole(array $d,string $key):array
    {
        Access::requireAdmin();$role=(string)($d['role']??'');
        if((!in_array($role,['erp_secretaria','erp_financeiro'],true)&&!str_starts_with($role,'erp_custom_'))||!get_role($role))throw new RuleViolation('Selecione Secretaria, Financeiro ou um perfil personalizado. Os perfis Aluno e Responsável são definidos pelos vínculos.');
        $person=Input::id($d['codpessoa']??null);$link=$this->db->row('SELECT wp_user_id FROM '.$this->db->table('pessoa_usuarios').' WHERE codpessoa=%d',[$person]);
        $u=$link?get_userdata((int)$link['wp_user_id']):false;if(!$u)throw new RuleViolation('A pessoa não possui conta WordPress.');
        if(($d['acao']??'')==='adicionar')$u->add_role($role);elseif(($d['acao']??'')==='remover')$u->remove_role($role);else throw new RuleViolation('Ação inválida.');
        $this->db->audit('pessoa_usuarios',$person,'perfil_operacional',null,$d,$key);
        return ['salvo'=>true];
    }
    public function studentSheet(int $id): array
    {
        $a=$this->db->get('alunos',$id); $p=(new \EducacionalERP\Application\CivilStatus($this->db))->decorate($this->db->get('pessoas',(int)$a['codpessoa']));
        $p['foto_url']=!empty($p['foto_attachment_id'])?wp_get_attachment_image_url((int)$p['foto_attachment_id'],'thumbnail'):null;
        $result=['aluno'=>$a,'pessoa'=>$p,'conta'=>(new Accounts($this->db))->summary((int)$a['codpessoa']),'responsaveis'=>$this->guardianView($id),'matriculas'=>[],'periodos_pendentes'=>[]];
        if(current_user_can('erp_gerenciar_academico')) {
            $result['matriculas']=$this->academicView($id);
            $v=$this->db->table('aluno_periodos'); $periods=$this->db->table('periodos_letivos');
            $result['periodos_pendentes']=$this->db->rows("SELECT v.idvinculoperiodo,v.codperiodo,v.status,v.versao,p.codigo,p.descricao FROM $v v JOIN $periods p ON p.codperiodo=v.codperiodo WHERE v.idaluno=%d AND v.status='aguardando_turma' ORDER BY p.data_inicio DESC",[$id]);
        }
        return $result;
    }
    public function options(string $type,\WP_REST_Request $r): array
    {
        $t=$this->db->table($type); $pk=$this->db->schema()[$type]['pk'][0]; $page=max(1,min(100000,(int)($r->get_param('page')?:1)));
        $where=[]; $args=[]; $q=sanitize_text_field((string)$r->get_param('search'));
        $name=$type==='periodos_letivos'?'descricao':'nome';
        $where[]=in_array($type,['pessoas','cursos','turnos','planos_pagamento'],true)?'ativo=1':($type==='turmas'?"status='ativa'":"status<>'encerrado'");
        if($q!=='') { $where[]="$name LIKE %s"; $args[]='%'.$this->db->wp->esc_like($q).'%'; }
        if($type==='planos_pagamento'){$where[]='codperiodo=%d';$args[]=Input::id($r->get_param('codperiodo')?:SchoolSettings::current());}
        if($type==='turmas') {
            $where[]='codperiodo=%d'; $args[]=Input::id($r->get_param('codperiodo')?:SchoolSettings::current());
            if($r->get_param('idturno')) { $where[]='idturno=%d'; $args[]=Input::id($r->get_param('idturno')); }
            if($r->get_param('idcurso')) { $where[]='idcurso=%d'; $args[]=Input::id($r->get_param('idcurso')); }
        }
        $filter=' WHERE '.implode(' AND ',$where);
        $count=$this->db->row("SELECT COUNT(*) AS n FROM $t$filter",$args);
        $rows=$this->db->rows("SELECT * FROM $t$filter ORDER BY $name,$pk LIMIT 20 OFFSET %d",array_merge($args,[($page-1)*20])); $items=[];
        foreach($rows as $row) {
            $label=$row[$name];
            if($type==='pessoas') {
                $hints=[];
                if($row['cpf']) { $hints[]='CPF final '.substr($row['cpf'],-4); }
                if($row['data_nascimento']) { $hints[]='nasc. '.implode('/',array_reverse(explode('-',$row['data_nascimento']))); }
                if($row['email']) { $hints[]=$row['email']; }
                if($hints) { $label.=' · '.implode(' · ',$hints); }
            } elseif($type==='periodos_letivos') { $label=$row['codigo'].' — '.$row['descricao']; }
            elseif($type==='turmas') {
                $course=$this->db->get('cursos',(int)$row['idcurso']); $shift=$this->db->get('turnos',(int)$row['idturno']);
                if(!(int)$course['ativo'] || !(int)$shift['ativo']) { continue; }
                $label.=' · '.$course['nome'].' · '.$shift['nome'];
            }
            $items[]=['id'=>(string)$row[$pk],'label'=>$label]+($type==='periodos_letivos'?['codperiodo_proximo'=>$row['codperiodo_proximo']??null]:[]);
        }
        return ['items'=>$items,'page'=>$page,'more'=>$page*20<(int)$count['n']];
    }
    private function listCatalog(string $name,\WP_REST_Request $r): array
    {
        $t=$this->db->table($name); $pk=$this->db->schema()[$name]['pk'][0];
        $page=max(1,min(100000,(int)($r->get_param('page')?:1))); $where=''; $args=[];
        $q=sanitize_text_field((string)$r->get_param('search'));
        $col=in_array($name,['pessoas','cursos','turnos','turmas','planos_pagamento'],true)?'nome':($name==='alunos'?'ra':'descricao');
        if($q!=='') { $where=" WHERE $col LIKE %s"; $args[]='%'.$this->db->wp->esc_like($q).'%'; }
        if($name==='pessoas' && ($code=trim(sanitize_text_field((string)$r->get_param('codigo'))))!==''){
            $where.=($where?' AND ':' WHERE ').'(CAST(codpessoa AS CHAR)=%s OR codpessoa_origem=%s)';$args[]=$code;$args[]=\EducacionalERP\Domain\CadastroText::upper($code);
        }
        if($name==='turmas' && ($period=SchoolSettings::forViewer($r->get_param('codperiodo')))){$where.=($where?' AND ':' WHERE ').'codperiodo=%d';$args[]=$period;}
        if($name==='turmas' && $r->get_param('idcurso')){$where.=($where?' AND ':' WHERE ').'idcurso=%d';$args[]=Input::id($r->get_param('idcurso'));}
        $order=$name==='turmas'?'nome ASC, codigo ASC, idturma ASC':"$pk DESC";
        $count=$this->db->row("SELECT COUNT(*) AS n FROM $t$where",$args);
        $rows=$this->db->rows("SELECT * FROM $t$where ORDER BY $order LIMIT 20 OFFSET %d",array_merge($args,[($page-1)*20]));
        if($name==='periodos_letivos')foreach($rows as &$row){$row['proximo_periodo_nome']=empty($row['codperiodo_proximo'])?'Não definido':$this->db->get('periodos_letivos',(int)$row['codperiodo_proximo'])['descricao'];}unset($row);
        if($name==='planos_pagamento')foreach($rows as &$row){$row['periodo_nome']=empty($row['codperiodo'])?'Não definido — editar':$this->db->get('periodos_letivos',(int)$row['codperiodo'])['descricao'];}unset($row);
        if($name==='turmas')foreach($rows as &$row){if(!empty($row['idturma_proxima'])){$row['proxima_turma']=$this->db->get('turmas',(int)$row['idturma_proxima']);}}unset($row);
        if($name==='pessoas') {
            $accounts=new Accounts($this->db);$a=$this->db->table('alunos');
            foreach($rows as &$row) {
                $row=(new \EducacionalERP\Application\CivilStatus($this->db))->decorate($row);
                $row['conta']=$accounts->summary((int)$row['codpessoa']);
                $student=$this->db->row("SELECT idaluno FROM $a WHERE codpessoa=%d",[(int)$row['codpessoa']]);
                $row['idaluno']=$student['idaluno']??null;
            }
        }
        return ['items'=>$rows,'page'=>$page,'total'=>(int)$count['n']];
    }
    public function guardianView(int $student): array
    {
        $v=$this->db->table('aluno_responsaveis'); $p=$this->db->table('pessoas');
        return $this->db->rows("SELECT v.versao,v.idvinculo,v.codpessoa_responsavel,p.nome,v.parentesco,v.responsavel_academico,v.responsavel_financeiro,v.pode_rematricular,v.inicio_vigencia,v.fim_vigencia FROM $v v JOIN $p p ON p.codpessoa=v.codpessoa_responsavel WHERE v.idaluno=%d ORDER BY v.idvinculo DESC",[$student]);
    }
    public function academicView(int $student,int $period=0): array
    {
        $m=$this->db->table('matriculas'); $t=$this->db->table('turmas'); $c=$this->db->table('cursos'); $p=$this->db->table('periodos_letivos'); $u=$this->db->table('turnos'); $ct=$this->db->table('contratos');
        return $this->db->rows("SELECT m.versao,m.idmatricula,m.codperiodo,m.idcurso,m.idturma_atual,m.status,m.data_matricula,(SELECT COUNT(*) FROM $ct ct WHERE ct.idmatricula=m.idmatricula) AS contratos,p.codigo AS periodo,c.nome AS curso,t.nome AS turma,u.nome AS turno FROM $m m JOIN $t t ON t.idturma=m.idturma_atual JOIN $c c ON c.idcurso=m.idcurso JOIN $p p ON p.codperiodo=m.codperiodo JOIN $u u ON u.idturno=t.idturno WHERE m.idaluno=%d".($period?' AND m.codperiodo=%d':'')." ORDER BY p.data_inicio DESC",$period?[$student,$period]:[$student]);
    }
    public function financeView(int $student,int $period=0): array
    {
        $l=$this->db->table('lancamentos'); $p=$this->db->table('parcelas'); $c=$this->db->table('contratos'); $m=$this->db->table('matriculas');
        $where=$period?' AND m.codperiodo=%d':''; $args=$period?[$student,$period]:[$student];
        if(!current_user_can('erp_consultar_financeiro')) { $where.=' AND l.codpessoa_rf_atual=%d'; $args[]=$this->access->person()??0; }
        return $this->db->rows("SELECT l.idlancamento,c.numero AS contrato,p.numero AS parcela,l.vencimento,l.valor_original,l.desconto_incondicional,l.valor_liquido,l.desconto_condicional_aplicado,l.juros_aplicados,l.multa_aplicada,l.valor_baixa,l.saldo_aberto,l.status FROM $l l JOIN $p p ON p.idparcela=l.idparcela JOIN $c c ON c.idcontrato=p.idcontrato JOIN $m m ON m.idmatricula=c.idmatricula WHERE m.idaluno=%d$where ORDER BY l.vencimento,l.idlancamento",$args);
    }
}
