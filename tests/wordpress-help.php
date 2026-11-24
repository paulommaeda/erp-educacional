<?php
declare(strict_types=1);
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy};
use EducacionalERP\Presentation\Portal\Portal;

if(PHP_SAPI!=='cli'||!getenv('EDERP_TEST_BOOTSTRAP'))exit(2);
$_SERVER['HTTP_HOST']='erp-test.local';
$_SERVER['REQUEST_URI']='/';
require getenv('EDERP_TEST_BOOTSTRAP');
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true)throw new RuntimeException('Exige WordPress de testes.');
require_once dirname(__DIR__).'/erp-educacional.php';

function helpCheck(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException($label);
    echo "PASS $label\n";
}

$portal=new Portal();
$portal->register();
Access::install();
$admin=get_users(['role'=>'administrator','number'=>1])[0];
$tag=bin2hex(random_bytes(5));
$users=[];
foreach(['erp_secretaria','erp_responsavel_academico','erp_pessoa'] as $role){
    $id=wp_insert_user(['user_login'=>$role.'_'.$tag,'user_pass'=>wp_generate_password(24),'role'=>$role]);
    if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
    $users[$role]=$id;
}
$prior=get_option('ederp_menu_policy',null);
$oldGet=$_GET;
try{
    $policy=$prior??[];
    unset($policy['erp_secretaria'],$policy['erp_responsavel_academico']);
    update_option('ederp_menu_policy',$policy);
    wp_set_current_user($admin->ID);
    $_GET=['erp_tela'=>'ajuda'];
    $index=$portal->render('inicio');
    helpCheck(substr_count($index,'>Ajuda</a>')===2,'Ajuda na barra lateral e no menu do celular');
    helpCheck(substr_count($index,'erp_artigo=')===7 && wp_style_is('ederp-help','enqueued'),'índice com sete artigos e estilo registrado');
    $_GET['erp_artigo']='documentos-configurar';
    $article=$portal->render('inicio');
    helpCheck(str_contains($article,'Abrir Configurações')&&str_contains($article,'Solicitar para')&&str_contains($article,'#ajuda-secao-1'),'artigo com seções, condição documental e atalho');
    $_GET=['erp_tela'=>'ajuda','erp_busca_ajuda'=>'MAT RICULA'];
    helpCheck(str_contains($portal->render('inicio'),'Matricular alunos individualmente ou em lote'),'busca por palavras no artigo');
    $_GET['erp_busca_ajuda']='responsavel financeiro';
    helpCheck(str_contains($portal->render('inicio'),'Vincular pais e responsáveis'),'busca sem acentos localiza artigos');
    $_GET['erp_busca_ajuda']='teste-sem-resultados';
    helpCheck(str_contains($portal->render('inicio'),'Nenhum artigo encontrado'),'tratamento da busca sem resultados');
    $_GET=['erp_tela'=>'ajuda','erp_artigo'=>'inexistente'];
    helpCheck(str_contains($portal->render('inicio'),'Artigo indisponível'),'link inválido oferece retorno ao índice');
    wp_set_current_user($users['erp_secretaria']);
    $_GET=['erp_tela'=>'ajuda','erp_artigo'=>'documentos-configurar'];
    helpCheck(str_contains($portal->render('inicio'),'Cadastrar um tipo como administrador')&&!str_contains($portal->render('inicio'),'Abrir Configurações'),'secretaria lê a rotina sem atalho administrativo');
    $policy['erp_secretaria']=['matriculas'];
    update_option('ederp_menu_policy',$policy);
    $_GET=['erp_tela'=>'ajuda'];
    $restricted=$portal->render('inicio');
    helpCheck(str_contains($restricted,'erp_artigo=matricular')&&!str_contains($restricted,'erp_artigo=requerimentos'),'perfil limitado vê somente a rotina habilitada');
    $_GET['erp_artigo']='requerimentos';
    helpCheck(str_contains($portal->render('inicio'),'Artigo indisponível'),'link direto respeita acesso aos artigos');
    wp_set_current_user($users['erp_responsavel_academico']);
    helpCheck(!MenuPolicy::can('ajuda')&&str_contains($portal->render('inicio'),'Acesso indisponível'),'portal da família preserva o acesso às suas telas');
    wp_set_current_user(0);
    helpCheck(str_contains($portal->render('inicio'),'method="post"'),'ajuda direta exige autenticação');

    if(getenv('EDERP_TEST_HELP_DIR')){
        $dir=getenv('EDERP_TEST_HELP_DIR');
        file_put_contents($dir.'/index.html',$index);
        file_put_contents($dir.'/article.html',$article);
    }
}finally{
    wp_set_current_user($admin->ID);
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach($users as $id)wp_delete_user($id);
    if($prior===null)delete_option('ederp_menu_policy');
    else update_option('ederp_menu_policy',$prior);
    $_GET=$oldGet;
}
