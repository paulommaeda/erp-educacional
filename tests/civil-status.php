<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Application\{CivilStatus,CatalogService,ImportService,Operations,DeletionService};
use EducacionalERP\Domain\RuleViolation;
Access::install();$db=new Database($wpdb);$ops=new Operations($db);$cat=new CatalogService($db,$ops);$civil=new CivilStatus($db);$import=new ImportService($db,$cat);$n=0;
function ck(bool $v,string $label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS: $label\n";}
function kk(){return bin2hex(random_bytes(16));}
function no(callable $fn,string $label){try{$fn();}catch(RuleViolation $e){ck(true,$label);return;}throw new RuntimeException($label);}
$legacy=$db->insert('pessoas',['nome'=>'Pessoa Legada','estado_civil'=>'Viúvo(a)']);$civil->migrate();ck((int)$db->get('pessoas',$legacy)['idestado_civil']===3,'migração preserva estado civil legado');ck(count($civil->all())===5,'códigos iniciais criados');ck($civil->resolve('1')['estado_civil']==='SOLTEIRO','ID resolve nome');ck($civil->resolve('Casado(a)')['idestado_civil']==='2','nome legado reconhecido');$civil->migrate();ck(count($civil->all())===5,'migração pode ser repetida');
$civil->save(['idestado_civil'=>8,'nome'=>'união estável'],kk());ck($civil->resolve(8)['estado_civil']==='UNIÃO ESTÁVEL','admin cadastra código e nome com acentos');
$data=['codpessoa_origem'=>'abc01','nome'=>'João da Conceição','data_nascimento'=>'1990-01-01','email'=>'Joao.Teste@example.com','idestado_civil'=>'8','rua'=>'Rua das flores','cidade'=>'Curitiba','estado'=>'pr','profissao'=>'Técnico','sexo'=>'Masculino','religiao'=>'Cristã','igreja'=>'Comunidade'];$p=$import->row('pessoas',$data,kk());$id=(int)$p['id'];$row=$db->get('pessoas',$id);
ck($row['nome']==='JOÃO DA CONCEIÇÃO'&&$row['profissao']==='TÉCNICO','importação normaliza texto e acentos');ck($row['email']==='Joao.Teste@example.com','e-mail preservado');ck($row['cidade']==='CURITIBA'&&$row['rua']==='RUA DAS FLORES'&&$row['sexo']==='MASCULINO','campos complementares em maiúsculas');ck($row['idestado_civil']==='8'&&$row['estado_civil']==='UNIÃO ESTÁVEL','pessoa grava ID e nome do estado civil');
ck($import->row('pessoas',$data,kk())['status']==='existente','reimportação com código minúsculo encontra normalizado');
$student=$import->row('alunos',['codpessoa_origem'=>'abc01','ra'=>'ra01','tipo_aluno'=>'regular'],kk());ck($db->get('alunos',(int)$student['id'])['ra']==='RA01','RA normalizado');ck($db->get('alunos',(int)$student['id'])['tipo_aluno']==='REGULAR','tipo do aluno normalizado');
$cat->updatePerson($id,['nome'=>'josé da conceição','idestado_civil'=>'1'],kk());ck($db->get('pessoas',$id)['nome']==='JOSÉ DA CONCEIÇÃO','edição manual normaliza nome');ck($db->get('pessoas',$id)['estado_civil']==='SOLTEIRO','edição troca referência de estado civil');
$civil->save(['idestado_civil'=>1,'nome'=>'solteiro(a)','versao'=>1],kk());ck($db->get('pessoas',$id)['estado_civil']==='SOLTEIRO(A)','renomeação atualiza nome exibido');
no(fn()=>$civil->save(['idestado_civil'=>1,'nome'=>'Outro'],kk()),'código já usado exige edição explícita');no(fn()=>$civil->resolve(99),'ID não cadastrado rejeitado');no(fn()=>(new DeletionService($db,$ops))->remove('estados_civis',1,['versao'=>2,'motivo'=>'Teste'],kk()),'exclusão de estado civil utilizado bloqueada');
$cat->updatePerson($id,['idestado_civil'=>''],kk());ck($db->get('pessoas',$id)['idestado_civil']===null,'campo opcional pode ser limpo');
$course=$cat->create('cursos',['codigo'=>'ef','nome'=>'Ensino médio'],kk());ck($db->get('cursos',(int)$course['id'])['nome']==='ENSINO MÉDIO','outros cadastros também padronizados');
wp_set_current_user((int)$p['wp_user_id']);no(fn()=>$civil->save(['idestado_civil'=>9,'nome'=>'Outro'],kk()),'pessoa não altera configuração');$v=$db->get('pessoas',$id)['versao'];$cat->updateOwn(['versao'=>$v,'profissao'=>'analista','idestado_civil'=>8],kk());ck($db->get('pessoas',$id)['profissao']==='ANALISTA','perfil próprio usa mesma regra');
echo "PASS: $n verificações de estado civil e maiúsculas\n";
