<?php
declare(strict_types=1);
define('WP_INSTALLING',true);
$_SERVER['HTTP_HOST']='127.0.0.1:38547';
$_SERVER['REQUEST_URI']='/';
require getenv('WP_QA_DIR').'/wp-load.php';
if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true)throw new RuntimeException('Base descartável exigida.');
require_once dirname(__DIR__).'/erp-educacional.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
use EducacionalERP\Application\Coligadas;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{MenuPolicy,ReportPolicy,SchemaMigration};
use EducacionalERP\Presentation\Portal\Portal;

add_filter('pre_wp_mail',static fn()=>true);
if(!is_blog_installed())wp_install('Escola QA','gestor_teste','local@example.test',false,'',wp_generate_password(24));
$db=new Database($wpdb);
SchemaMigration::run($db);
$admin=get_users(['role'=>'administrator','number'=>1])[0];
wp_set_current_user($admin->ID);
Portal::setup();
update_option('active_plugins',['erp-educacional/erp-educacional.php']);
$account=static function(string $role):array{
    $login=$role.'_qa';
    $password=wp_generate_password(24,false,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'role'=>$role]);
    if(is_wp_error($id))throw new RuntimeException($id->get_error_message());
    return compact('login','password');
};
$year=static fn(int $suffix)=>$db->insert('periodos_letivos',['codigo'=>'202'.$suffix,'descricao'=>'Ano de teste','data_inicio'=>'202'.$suffix.'-01-01','data_fim'=>'202'.$suffix.'-12-31','codcoligada'=>Coligadas::current()]);
$catalog=static function(int $period,string $label)use($db){
    $company=Coligadas::current();
    $course=$db->insert('cursos',['codigo'=>'EF1','nome'=>'FUNDAMENTAL','codcoligada'=>$company]);
    $shift=$db->insert('turnos',['codigo'=>'MANHA','nome'=>'MANHÃ','codcoligada'=>$company]);
    $class=$db->insert('turmas',['codperiodo'=>$period,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>$label,'nome'=>$label,'capacidade'=>1000,'codcoligada'=>$company]);
    return [$course,$shift,$class];
};
$period=$year(7);
[$course,$shift,$class]=$catalog($period,'5º ANO A');
$previous=$year(6);
$oldClass=$db->insert('turmas',['codperiodo'=>$previous,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'4B','nome'=>'4º ANO B','capacidade'=>1000]);
$emptyClass=$db->insert('turmas',['codperiodo'=>$period,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'5B','nome'=>'5º ANO B','capacidade'=>1000]);
update_option('ederp_current_period',$period,false);
$add=static function(int $period,int $course,int $class,string $ra,string $name,string $status='cursando',?string $deleted=null)use($db){
    $company=Coligadas::current();
    $person=$db->insert('pessoas',['nome'=>$name,'email'=>$ra==='0001'?'aluno.relatorio+turma@exemplo.test':null]);
    $student=$db->insert('alunos',['ra'=>$ra,'codpessoa'=>$person,'codcoligada'=>$company]);
    $db->insert('matriculas',['idaluno'=>$student,'idcurso'=>$course,'codperiodo'=>$period,'idturma_atual'=>$class,
        'codcoligada'=>$company,'status'=>$status,'ativo_unico'=>$status==='cancelada'?null:1,
        'excluido_em'=>$deleted,'data_matricula'=>'2026-11-25','origem'=>'teste']);
    return $student;
};
for($i=42;$i>=1;$i--){
    $code=sprintf('%02d',$i);
    $name='ÁLUNO TESTE '.$code;
    if($i===14)$name.=' DA CONCEIÇÃO SILVA E SOUZA COM UM SOBRENOME EXTRA MUITO COMPRIDO';
    $id=$add($period,$course,$class,'00'.$code,$name);
    if($i===1)$studentWithContacts=$id;
}
$classB=$db->insert('turmas',['codperiodo'=>$period,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'5D','nome'=>'5º ANO D','capacidade'=>1000]);
$add($period,$course,$classB,'0070','ALUNA RESERVADA','reservado');
$contact=static function(string $name,string $email,string $phone,string $type,string $start='2020-01-01 00:00:00',?string $end=null)use($db,$studentWithContacts){
    $id=$db->insert('pessoas',['nome'=>$name,'email'=>$email,'telefone'=>$phone]);
    $db->insert('aluno_responsaveis',['idaluno'=>$studentWithContacts,'codpessoa_responsavel'=>$id,'parentesco'=>$type,'inicio_vigencia'=>$start,'fim_vigencia'=>$end]);
};
$contact('PAULO PAI','pai@exemplo.test','41990000001','pai');
$contact('MARIA MÃE','mae@exemplo.test','41990000002','mae');
$contact('ANA AVÓ','avo@exemplo.test','41990000003','avo_feminina');
$contact('SOFIA TIA','tia@exemplo.test','41990000004','tia');
$contact('VÍNCULO ENCERRADO','antigo@exemplo.test','41990000005','outro','2020-01-01 00:00:00','2023-01-01 00:00:00');
$contact('VÍNCULO FUTURO','futuro@exemplo.test','41990000006','outro','2035-01-01 00:00:00');
$contact('PRÓPRIO ALUNO','proprio@exemplo.test','41990000007','proprio_aluno');
$defaults=ReportPolicy::settings();
if(($defaults['erp_secretaria']??[])!==['assinaturas','alunos']||isset($defaults['erp_responsavel_academico'])){
    throw new RuntimeException('Permissões iniciais não preservam a lista e restringem os perfis familiares.');
}
MenuPolicy::save(['role'=>'erp_coordenacao','menus'=>['chat'],'reports'=>['alunos']]);
MenuPolicy::save(['role'=>'erp_orientacao','menus'=>['chat'],'reports'=>['assinaturas']]);
$add($period,$course,$class,'0080','CANCELADO','cancelada');
$add($period,$course,$class,'0081','TRANSFERIDO','transferencia_externa');
$add($period,$course,$class,'0082','EXCLUÍDO','cursando','2026-11-25 00:00:00');
$add($previous,$course,$oldClass,'0063','ALUNA ANO ANTERIOR','aprovado');
$company=$db->insert('coligadas',['nome'=>'OUTRA ESCOLA','razao_social'=>'OUTRA ESCOLA']);
[$other,$otherClass]=Coligadas::within($company,static function()use($year,$catalog,$add){
    $period=$year(7);
    [$course,,$class]=$catalog($period,'5º ANO C');
    $add($period,$course,$class,'0015','ALUNA OUTRA COLIGADA');
    return [$period,$class];
});
file_put_contents(getenv('WP_QA_DIR').'/../fixture.json',wp_json_encode([
    'portal'=>Portal::url('relatorios'),'period'=>$period,'class'=>$class,
    'previous'=>$previous,'oldClass'=>$oldClass,'other'=>$other,'otherClass'=>$otherClass,'emptyClass'=>$emptyClass,
    'classB'=>$classB,'admin'=>$account('administrator'),'staff'=>$account('erp_secretaria'),'parent'=>$account('erp_responsavel_academico'),'rosterOnly'=>$account('erp_coordenacao'),'signaturesOnly'=>$account('erp_orientacao')
],JSON_UNESCAPED_UNICODE));
echo "Matrículas fictícias e perfis criados.\n";
