<?php
/** Native WordPress REST/roles, DECIMAL aggregation and rendering. Local fixtures only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');if(!$bootstrap||!is_file($bootstrap))exit(2);$_SERVER['HTTP_HOST']='erp-test.local';$_SERVER['REQUEST_URI']='/';require $bootstrap;
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true||defined('EDERP_SQLITE_TEST'))throw new RuntimeException('Exige WordPress/MySQL de testes.');
require_once dirname(__DIR__).'/autoload.php';require __DIR__.'/fixtures/financial-dashboard.php';require_once ABSPATH.'wp-admin/includes/user.php';
if(!defined('EDERP_FILE'))define('EDERP_FILE',dirname(__DIR__).'/erp-educacional.php');if(!defined('EDERP_VERSION'))define('EDERP_VERSION','0.19.28');
use EducacionalERP\Application\{AnnualClearance,Operations,CatalogService,AcademicService,FinanceService,ExportService,RenewalService,StudentWorkflow};
use EducacionalERP\Domain\Input;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};
use EducacionalERP\Presentation\Rest\Controller;
use EducacionalERP\Presentation\Portal\{Portal,Reports};
$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);Access::install();$prefix=$wpdb->prefix;$saved=[];$users=[];$roles=[];$checks=0;$tag=bin2hex(random_bytes(4));$oldGet=$_GET;
foreach(['ederp_schema_version','ederp_schema_error','ederp_schema_integrity','ederp_schema_attempt_release','ederp_pontualidade','ederp_menu_policy','ederp_report_policy','ederp_field_group_policy','ederp_user_permissions','ederp_educbank','ederp_school_identity'] as $key)$saved[$key]=get_option($key,null);
add_filter('pre_wp_mail',static fn()=>true,999,2);add_filter('pre_http_request',static fn()=>new WP_Error('test_http_blocked','Transporte bloqueado no teste.'),999,3);
function fwpOK(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);throw new RuntimeException($label);}$checks++;echo "PASS: $label\n";}
function fwpActor(int $id):void{wp_set_current_user(0);wp_set_current_user($id);}
function fwpReq(string $path,?array $body=null,array $query=[]):WP_REST_Response{$r=new WP_REST_Request($body===null?'GET':'POST','/erp-educacional/v1/'.$path);$r->set_query_params($query);if($body!==null){$r->set_header('Content-Type','application/json');$r->set_header('Idempotency-Key',bin2hex(random_bytes(16)));$r->set_body(wp_json_encode($body));}return rest_do_request($r);}
try{
    $wpdb->prefix='fd_'.$tag.'_';$db=new Database($wpdb);delete_option('ederp_schema_version');delete_option('ederp_schema_error');delete_option('ederp_educbank');SchemaMigration::run($db);update_option('ederp_pontualidade','103.00');$fixture=seedFinancialDashboard($db);$ops=new Operations($db);$access=new Access($db);$academic=new AcademicService($db,$ops);$catalog=new CatalogService($db,$ops);rest_get_server();(new Controller($db,$access,$academic,$catalog,new FinanceService($db,$ops),new ExportService($db),new RenewalService($db,$ops,$academic,$access),new StudentWorkflow($db,$ops,$catalog,$academic)))->register();
    $description=fwpReq('perfis')->get_data();fwpOK(isset($description['menus']['gestao_financeira'])&&$description['menus']['financeiro']==='Financeiro','matriz distingue o painel da operação financeira');
    $actors=[];foreach(['none'=>[],'dashboard'=>['gestao_financeira'],'finance'=>['financeiro']] as $name=>$menus){$created=fwpReq('perfis',['nome'=>'Financeiro '.$name.' '.$tag]);$role=$created->get_data()['slug'];$roles[]=$role;fwpOK(fwpReq('perfis/menus',['role'=>$role,'menus'=>$menus])->get_status()===200,'administrador configura perfil '.$name);$actor=wp_insert_user(['user_login'=>'fd_'.$name.'_'.$tag,'user_pass'=>wp_generate_password(40),'role'=>$role]);if(is_wp_error($actor))throw new RuntimeException($actor->get_error_message());$users[]=$actor;$actors[$name]=$actor;}
    foreach([0,$actors['none'],$actors['finance']] as $actor){fwpActor($actor);foreach(['gestao-financeira','gestao-financeira/filtros'] as $path)fwpOK(in_array(fwpReq($path)->get_status(),[401,403],true),'sem liberação específica não acessa '.$path);}
    fwpActor($actors['dashboard']);$response=fwpReq('gestao-financeira',null,['codperiodo'=>'todos']);fwpOK($response->get_status()===200,'perfil liberado consulta o painel');fwpOK(str_contains($response->get_headers()['Cache-Control']??'','no-store'),'REST impede cache compartilhado do relatório');$data=$response->get_data();$totals=$data['totais'];
    foreach(['previsto_bruto'=>'7394.50','previsto'=>'6776.50','realizado'=>'1600.00','recebido_total'=>'1670.00','encargos_recebidos'=>'70.00','a_receber_bruto'=>'5691.50','a_receber_liquido'=>'5176.50','inadimplencia_bruta'=>'4338.50','inadimplencia_liquida'=>'3926.50','base_vencida_bruta'=>'5841.50','base_vencida_liquida'=>'5326.50'] as $key=>$expected)fwpOK($totals[$key]===$expected,'DECIMAL/InnoDB: '.$key.' fecha com os valores esperados');
    fwpOK($totals['parcelas']===8&&$totals['parcelas_vencidas']===4&&count($data['competencias'])===3,'agrupamento não duplica parcelas e separa vencimento de hoje');
    $filtered=fwpReq('gestao-financeira',null,['codperiodo'=>$fixture['period'],'competencia_inicio'=>'2027-01','competencia_fim'=>'2027-01','idaluno'=>$fixture['a']['idaluno']])->get_data()['totais'];fwpOK($filtered['inadimplencia_bruta']==='1682.50'&&$filtered['inadimplencia_liquida']==='1476.50','filtros combinados mantêm bolsas fora das duas bases');
    fwpOK(fwpReq('gestao-financeira',null,['idturma'=>$fixture['classB']])->get_data()['totais']['realizado']==='50.00','filtro de turma separa o principal dos encargos');
    $options=fwpReq('gestao-financeira/filtros',null,['tipo'=>'alunos','search'=>'DASH-A'])->get_data()['items'];fwpOK(count($options)===1&&array_keys($options[0])===['id','label'],'lookup restrito a nome e identificação do aluno');fwpOK(!fwpReq('gestao-financeira/filtros',null,['tipo'=>'alunos','search'=>'%'])->get_data()['items'],'LIKE trata caracteres especiais como texto');
    fwpOK(fwpReq('gestao-financeira',null,['competencia_inicio'=>'2027-13'])->get_status()===422&&fwpReq('gestao-financeira',null,['competencia_inicio'=>'2027-02','competencia_fim'=>'2027-01'])->get_status()===422,'competências inválidas recusadas pela REST');
    foreach(['financeiro/alunos','contratos','perfis'] as $path)fwpOK(fwpReq($path)->get_status()===403,'permissão do painel não amplia acesso a '.$path);
    fwpOK(fwpReq('lancamentos/'.$fixture['titles']['a'][1].'/baixas',['valor_pago'=>'1.00','forma_pagamento'=>'pix'])->get_status()===403,'permissão de indicadores não registra baixa');fwpOK(fwpReq('gestao-financeira',[])->get_status()===404,'painel não oferece operação de escrita');
    $_GET=['erp_tela'=>'gestao_financeira'];$html=(new Portal())->render('inicio');fwpOK(str_contains($html,'data-erp-financial-dashboard')&&!str_contains($html,'erp_tela=financeiro"'),'portal do operador apresenta somente os acessos liberados');$localized=(string)wp_scripts()->get_data('ederp','data');fwpOK(str_contains($localized,'"financialDashboard":true')&&str_contains($localized,'"periods":true'),'operador recebe os controles de coligada e período na interface');file_put_contents(dirname(__DIR__,2).'/dashboard-portal.html',$html);file_put_contents(dirname(__DIR__,2).'/dashboard-data.json',wp_json_encode($data));
    fwpActor($admin->ID);$_GET=['erp_tela'=>'financeiro'];$html=(new Portal())->render('inicio');fwpOK(str_contains($html,'erp-finance-shortcuts')&&str_contains($html,'<h1>Financeiro</h1>'),'Financeiro mantém atalhos em um único grupo alinhável');file_put_contents(dirname(__DIR__,2).'/finance-portal.html',$html);$_GET=['erp_tela'=>'matriculas'];file_put_contents(dirname(__DIR__,2).'/enrollments-portal.html',(new Portal())->render('inicio'));
    $rows=fwpReq('matriculas',null,['codperiodo'=>'todos','search'=>'DASH-A'])->get_data()['items'];fwpOK($rows[0]['curso_codigo']==='BOLSA-EF'&&$rows[0]['turno_codigo']==='BOLSA-M','REST de matrículas fornece identificação de curso e turno');
    $company=$db->insert('coligadas',['nome'=>'OUTRA ESCOLA','razao_social'=>'OUTRA ESCOLA']);fwpActor($actors['dashboard']);fwpOK(fwpReq('coligadas/selecionar',['codcoligada'=>$company])->get_status()===200,'operador do painel seleciona coligada');fwpOK(fwpReq('gestao-financeira',null,['codperiodo'=>'todos'])->get_data()['totais']['parcelas']===0,'relatório não mistura coligadas');fwpOK(fwpReq('gestao-financeira',null,['idaluno'=>$fixture['a']['idaluno']])->get_status()===422,'identificador de aluno de outra coligada recusado');fwpReq('coligadas/selecionar',['codcoligada'=>1]);
    $before=serialize([$db->rows('SELECT * FROM '.$db->table('lancamentos').' ORDER BY idlancamento'),$db->rows('SELECT * FROM '.$db->table('baixas').' ORDER BY idbaixa')]);fwpReq('gestao-financeira',null,['codperiodo'=>'todos']);fwpReq('gestao-financeira/filtros',null,['tipo'=>'turmas']);fwpOK($before===serialize([$db->rows('SELECT * FROM '.$db->table('lancamentos').' ORDER BY idlancamento'),$db->rows('SELECT * FROM '.$db->table('baixas').' ORDER BY idbaixa')]),'consultas não alteram cobranças nem pagamentos');
    fwpActor($admin->ID);fwpReq('perfis/menus',['role'=>$roles[1],'menus'=>[]]);fwpActor($actors['dashboard']);fwpOK(fwpReq('gestao-financeira')->get_status()===403&&fwpReq('gestao-financeira/filtros')->get_status()===403,'revogação bloqueia resumo e filtros imediatamente');
    fwpActor($admin->ID);
    fwpOK(fwpReq('perfis/menus',['role'=>$roles[1],'menus'=>[],'reports'=>['parcelas']])->get_status()===200,'Parcelas pode ser liberado por perfil como relatório independente');
    prepareInstallmentReportDueDates($db,$fixture);
    $_GET=['erp_tela'=>'relatorios','relatorio'=>'parcelas','relatorio_aluno'=>$fixture['a']['idaluno'],'relatorio_mes'=>'2027-01'];
    fwpActor($actors['finance']);
    $portal=new Portal();
    $html=$portal->render('inicio');
    fwpOK(!str_contains($html,'ALUNO DASH-A')&&!str_contains($html,'data-erp-report-download'),'perfil financeiro sem permissão para Parcelas não recebe valores');
    fwpActor($actors['dashboard']);
    $html=$portal->render('inicio');
    $preview=strstr($html,'<table class="erp-roster-table erp-installment-report-table">')?:'';
    fwpOK(str_contains($preview,'ALUNO DASH-A')&&!str_contains($preview,'ALUNO DASH-B')&&str_contains($preview,'R$ 1.003,00'),'prévia consulta aluno e vencimento mesmo quando a competência difere');
    fwpOK(str_contains($preview,'<tfoot><tr><th scope="row" colspan="2">Total do mês · 1 aluno</th><td>R$ 4.059,00</td><td colspan="2"></td><td>R$ 3.135,50</td>'),
        'rodapé da prévia conta o aluno uma vez e soma os valores do filtro');
    fwpOK(str_contains($html,'Mês de vencimento')&&substr_count($html,'<option value="todos">Todos</option>')===2&&str_contains($html,'data-erp-report-download'),'filtros oferecem Todos e PDF usa a mesma seleção');
    $source=new \EducacionalERP\Application\InstallmentReport($db);
    $pdf=\EducacionalERP\Infrastructure\Pdf\InstallmentReportPdf::render($source->sheets((int)$fixture['a']['idaluno'],'2027-01'),['nome'=>'Colégio fictício']);
    fwpOK(str_starts_with($pdf,'%PDF-')&&str_contains($pdf,'841.89 595.28'),'relatório gera PDF paisagem com filtro de vencimento');

    $year=min(2025,(int)substr(Input::today(),0,4)-1);
    $db->update('coligadas',1,['razao_social'=>'ESCOLA FICTÍCIA LTDA','cnpj'=>'11222333000181']);
    $db->update('lancamentos',$fixture['titles']['a'][3],['vencimento'=>$year.'-01-10']);
    $db->update('lancamentos',$fixture['titles']['a'][2],['vencimento'=>$year.'-02-10']);
    $_GET=['erp_tela'=>'relatorios','relatorio'=>'quitacao_anual','relatorio_ano'=>$year,'relatorio_responsavel'=>$fixture['responsible']];
    $html=$portal->render('inicio');
    fwpOK(!str_contains($html,'relatorio_responsavel')&&!str_contains($html,'data-erp-report-download'),'acesso a Parcelas não libera a declaração anual');
    fwpActor($admin->ID);
    fwpOK(fwpReq('perfis/menus',['role'=>$roles[1],'menus'=>[],'reports'=>['parcelas','quitacao_anual']])->get_status()===200,
        'declaração anual pode ser concedida individualmente ao perfil');
    fwpActor($actors['dashboard']);
    $html=$portal->render('inicio');
    fwpOK(str_contains($html,'1 título com saldo em aberto')&&!str_contains($html,'data-erp-report-download'),'prévia bloqueia emissão quando há saldo no ano selecionado');
    $db->update('lancamentos',$fixture['titles']['a'][2],['vencimento'=>'2027-01-20']);
    $html=$portal->render('inicio');
    fwpOK(str_contains($html,'id="erp-clearance-title">'.AnnualClearance::TITLE)&&str_contains($html,'ESCOLA FICTÍCIA LTDA')
        &&str_contains($html,'Valor total pago</th><td>R$ 1.250,00')&&str_contains($html,'data-erp-report-download'),
        'declaração no portal inclui identidade, soma efetiva e acesso ao PDF');

    fwpActor($admin->ID);
    $_GET=['erp_tela'=>'relatorios','relatorio'=>'quitacao_anual','relatorio_ano'=>$year,
        'quitacao_aluno'=>$fixture['a']['idaluno'],'quitacao_periodo'=>$fixture['period']];
    $html=$portal->render('inicio');
    fwpOK(str_contains($html,'name="quitacao_aluno"')&&str_contains($html,'name="quitacao_periodo"')
        &&str_contains($html,'RA DASH-A')&&str_contains($html,'período letivo BOLSA-2027')
        &&str_contains($html,'data-erp-report-download'),'filtros administrativos combinam aluno e período e resolvem responsável único');
    $_GET['quitacao_aluno']=$fixture['b']['idaluno'];
    fwpOK(!str_contains($portal->render('inicio'),'data-erp-report-download'),'aluno fora da seleção não recebe o PDF administrativo');

    $other=$db->insert('pessoas',['nome'=>'RESPONSÁVEL TESTE OUTRO','cpf'=>'12345678909','data_nascimento'=>'1980-01-02']);
    $db->update('lancamentos',$fixture['titles']['b'][1],['vencimento'=>$year.'-01-20','codpessoa_rf_atual'=>$other]);
    $guardians=[];
    foreach([$fixture['responsible'],$other] as $person){
        $guard=wp_insert_user(['user_login'=>'fd_guard_'.$person.'_'.$tag,'user_pass'=>wp_generate_password(40),'role'=>'erp_responsavel_financeiro']);
        if(is_wp_error($guard))throw new RuntimeException($guard->get_error_message());
        $guardians[]=$guard;
        $users[]=$guard;
        $db->insert('pessoa_usuarios',['codpessoa'=>$person,'wp_user_id'=>$guard]);
    }
    [$guardian,$otherGuardian]=$guardians;
    fwpActor($guardian);
    $_GET=['erp_tela'=>'meu_financeiro','relatorio'=>'quitacao_anual','quitacao_emissor'=>'1-'.$year,
        'relatorio_responsavel'=>$other,'quitacao_aluno'=>$fixture['b']['idaluno']];
    $html=$portal->render('inicio');
    fwpOK(str_contains($html,'name="quitacao_emissor"')&&str_contains($html,'Valor total pago</th><td>R$ 1.250,00')
        &&str_contains($html,'ALUNO DASH-A')&&!str_contains($html,'ALUNO DASH-B')&&!str_contains($html,'name="relatorio_responsavel"'),
        'Meu financeiro vincula a prévia ao usuário logado e ignora filtros administrativos');
    preg_match('/[?&](?:amp;|#038;)?relatorio_pdf=([a-z0-9]+)/',$html,$pdfLink);
    fwpOK(isset($pdfLink[1]),'responsável recebe link próprio de PDF');
    fwpActor($otherGuardian);
    $html=$portal->render('inicio');
    fwpOK(str_contains($html,'1 título com saldo em aberto')&&!str_contains($html,'ALUNO DASH-A')&&!str_contains($html,'data-erp-report-download'),
        'outro responsável vê apenas o estado da própria declaração');
    $previousQuery=$GLOBALS['wp_query'];
    $portalOption=get_option('ederp_portal_page',0);
    $GLOBALS['wp_query']=new WP_Query();
    $GLOBALS['wp_query']->is_page=true;
    $GLOBALS['wp_query']->queried_object=new stdClass();
    $GLOBALS['wp_query']->queried_object->ID=100;
    update_option('ederp_portal_page',100);
    $deny=static fn()=>static function($message,$title,$args){throw new RuntimeException((string)$message,(int)($args['response']??0));};
    add_filter('wp_die_handler',$deny);
    $_GET['relatorio_pdf']=$pdfLink[1];
    try{
        Reports::download();
        fwpOK(false,'PDF copiado deve ser bloqueado para outro usuário');
    }catch(RuntimeException $error){
        fwpOK($error->getCode()===403,'PDF copiado é bloqueado para outro responsável');
    }finally{
        remove_filter('wp_die_handler',$deny);
        update_option('ederp_portal_page',$portalOption);
        $GLOBALS['wp_query']=$previousQuery;
    }
    echo "WORDPRESS_FINANCIAL_DASHBOARD_OK: $checks verificações / WordPress $wp_version / MariaDB ".$wpdb->db_version()."\n";
}finally{fwpActor($admin->ID);foreach($users as $user)wp_delete_user($user);foreach($roles as $role)remove_role($role);$wpdb->prefix=$prefix;$_GET=$oldGet;foreach($saved as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}}
