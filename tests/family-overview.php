<?php
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Application\{FamilyOverview,Coligadas};
function okO(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);echo "PASS: $message\n";}
$db=new Database($wpdb);Access::install();$access=new Access($db);$overview=new FamilyOverview($db);
function structureO(int $company):array {
 global $db;
 return Coligadas::within($company,function()use($db){
  $course=$db->insert('cursos',['codigo'=>'EF','nome'=>'ENSINO FUNDAMENTAL']);$shift=$db->insert('turnos',['codigo'=>'M','nome'=>'MANHÃ']);$out=[];
  foreach([2026=>'5 ANO A',2027=>'6 ANO A'] as $year=>$name){$period=$db->insert('periodos_letivos',['codigo'=>(string)$year,'descricao'=>'ANO '.$year,'data_inicio'=>"$year-01-01",'data_fim'=>"$year-12-31"]);$class=$db->insert('turmas',['codperiodo'=>$period,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>'T'.$year,'nome'=>$name,'capacidade'=>30]);$out[$year]=['period'=>$period,'class'=>$class,'course'=>$course];}
  update_option(Coligadas::option('ederp_current_period'),$out[2026]['period']);return $out;
 });
}
function enrollO(int $student,array $structure):int {global $db;return $db->insert('matriculas',['idaluno'=>$student,'codperiodo'=>$structure['period'],'idcurso'=>$structure['course'],'idturma_atual'=>$structure['class'],'data_matricula'=>'2026-01-01','origem'=>'inicial']);}
function resultO(int $person):array {global $overview,$access;foreach($overview->decorate($access->students()) as $row)if((int)$row['codpessoa']===$person)return $row;throw new RuntimeException('Aluno ausente');}
$school=structureO(1);
$child=$db->insert('pessoas',['nome'=>'ALUNO COM FOTO','data_nascimento'=>'2015-01-01','foto_attachment_id'=>10]);$student=$db->insert('alunos',['codpessoa'=>$child,'ra'=>'601']);$current=enrollO($student,$school[2026]);enrollO($student,$school[2027]);
$other=$db->insert('pessoas',['nome'=>'ALUNO SEM FOTO','data_nascimento'=>'2016-01-01']);$otherStudent=$db->insert('alunos',['codpessoa'=>$other,'ra'=>'602']);enrollO($otherStudent,$school[2027]);
$mother=$db->insert('pessoas',['nome'=>'MÃE DOS ALUNOS','data_nascimento'=>'1980-01-01']);$parent=wp_insert_user(['user_login'=>'mae','role'=>'erp_responsavel_academico']);$db->insert('pessoa_usuarios',['codpessoa'=>$mother,'wp_user_id'=>$parent]);
foreach([$student,$otherStudent] as $id)$db->insert('aluno_responsaveis',['idaluno'=>$id,'codpessoa_responsavel'=>$mother,'parentesco'=>'mae','responsavel_academico'=>1,'inicio_vigencia'=>'2020-01-01 00:00:00']);
wp_set_current_user($parent);$row=resultO($child);
okO(count($overview->decorate($access->students()))===2,'responsável recebe um cartão para cada filho');
okO($row['academico_atual']===[['periodo'=>'2026','turmas'=>['5 ANO A']]],'período parametrizado e turma atual prevalecem sobre a rematrícula futura');
okO($row['foto_url']==='https://test.example/foto.jpg'&&$row['pode_editar_contato'],'foto cadastrada e acesso ao modal de contato preservados');
$noClass=resultO($other);okO($noClass['foto_url']===null&&$noClass['academico_atual']===[['periodo'=>'2026','turmas'=>[]]],'aluno sem foto ou turma vigente conserva identificação e período sem usar turma futura');
$db->update('matriculas',$current,['status'=>'transferencia_externa']);okO(resultO($child)['academico_atual'][0]['turmas']===[],'transferência externa não aparece como turma atual');
$db->update('matriculas',$current,['status'=>'cancelada','ativo_unico'=>null]);okO(resultO($child)['academico_atual'][0]['turmas']===[],'matrícula cancelada não aparece como turma atual');
$db->update('matriculas',$current,['status'=>'cursando','ativo_unico'=>1]);
update_option('ederp_current_period',$school[2027]['period']);okO(resultO($child)['academico_atual']===[['periodo'=>'2027','turmas'=>['6 ANO A']]],'mudança da configuração atualiza período e turma do cartão');update_option('ederp_current_period',$school[2026]['period']);
$company=$db->insert('coligadas',['nome'=>'SEGUNDO COLÉGIO','razao_social'=>'SEGUNDO COLÉGIO']);$second=structureO($company);$local=$db->insert('alunos',['codcoligada'=>$company,'codpessoa'=>$child,'ra'=>'701']);$db->update('turmas',$second[2026]['class'],['nome'=>'TURMA EXCLUSIVA']);enrollO($local,$second[2026]);
okO(resultO($child)['academico_atual'][0]['turmas']===['5 ANO A'],'vínculo em uma coligada não revela turma de outra coligada');
$link=$db->insert('aluno_responsaveis',['codcoligada'=>$company,'idaluno'=>$local,'codpessoa_responsavel'=>$mother,'parentesco'=>'mae','responsavel_academico'=>1,'inicio_vigencia'=>'2020-01-01 00:00:00']);$row=resultO($child);
okO(count($overview->decorate($access->students()))===2&&$row['academico_atual']===[['periodo'=>'2026','turmas'=>['5 ANO A','TURMA EXCLUSIVA']]],'registros autorizados entre coligadas são reunidos sem duplicar o filho');
Coligadas::within($company,fn()=>update_option(Coligadas::option('ederp_current_period'),$second[2027]['period']));enrollO($local,$second[2027]);$row=resultO($child);
okO(count($row['academico_atual'])===2&&$row['academico_atual'][0]['periodo']==='2027'&&$row['academico_atual'][1]['periodo']==='2026'&&Coligadas::current()===1,'cada coligada usa o próprio período vigente sem alterar o contexto do responsável');
$db->update('aluno_responsaveis',$link,['fim_vigencia'=>'2025-01-01 00:00:00']);okO(resultO($child)['academico_atual']===[['periodo'=>'2026','turmas'=>['5 ANO A']]],'encerrar vínculo remove informações acadêmicas da coligada revogada');
delete_option('ederp_current_period');okO(resultO($child)['academico_atual']===[],'período não configurado não consulta histórico nem usa outra coligada');update_option('ederp_current_period',$school[2026]['period']);
$self=wp_insert_user(['user_login'=>'aluno','role'=>'erp_aluno']);$db->insert('pessoa_usuarios',['codpessoa'=>$child,'wp_user_id'=>$self]);wp_set_current_user($self);okO(!resultO($child)['pode_editar_contato']&&resultO($child)['foto_url']!==null,'aluno mantém sua visão sem receber permissão de edição exclusiva do responsável');
$stranger=wp_insert_user(['user_login'=>'outro','role'=>'erp_responsavel_academico']);wp_set_current_user($stranger);okO($overview->decorate($access->students())===[],'usuário sem vínculo não recebe foto ou dados acadêmicos');
