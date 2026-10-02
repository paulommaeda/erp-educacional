<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Application\{CatalogService,Operations,ImportService};
use EducacionalERP\Domain\RuleViolation;
$db=new Database($wpdb);$cat=new CatalogService($db,new Operations($db));$import=new ImportService($db,$cat);$n=0;(new \EducacionalERP\Application\CivilStatus($db))->migrate();
function k():string{return bin2hex(random_bytes(16));}
function ok(bool $x,string $msg):void{global $n;if(!$x)throw new RuntimeException($msg);$n++;echo "PASS: $msg\n";}
function no(callable $f,string $msg):void{try{$f();}catch(RuleViolation $e){ok(true,$msg);return;}throw new RuntimeException($msg);}
$p=['codpessoa_origem'=>'000123','nome'=>'Maria Importada','data_nascimento'=>'15/08/1980','rg'=>'01.234-X','rua'=>'Rua Um','numero'=>'S/N','complemento'=>'Casa','bairro'=>'Centro','cep'=>'01001-000','cidade'=>'São Paulo','estado'=>'SP','pais'=>'BR','estado_civil'=>'Casado(a)','sexo'=>'Feminino','religiao'=>'Cristã','igreja'=>'Comunidade','profissao'=>'Professora'];
$r=$import->row('pessoas',$p,k());$person=$db->get('pessoas',(int)$r['id']);ok($person['codpessoa_origem']==='000123'&&$person['data_nascimento']==='1980-08-15','origem preservada e nascimento brasileiro convertido');
ok($person['rg']==='01.234-X'&&$person['numero']==='S/N'&&$person['cep']==='01001000'&&$person['profissao']==='PROFESSORA','campos ampliados gravados sem converter RG ou número');
$a=$import->row('alunos',['codpessoa_origem'=>'000123','ra'=>'009999','tipo_aluno'=>'AEE'],k());$student=$db->get('alunos',(int)$a['id']);ok($student['ra']==='009999'&&$student['codpessoa']===$r['id']&&$student['tipo_aluno']==='AEE','RA diferente do código de pessoa mantém zeros e vínculo');
ok($import->row('alunos',['codpessoa_origem'=>'000123','ra'=>'009999'],k())['status']==='existente','reimportar aluno não duplica nem muda tipo');
ok($import->row('pessoas',$p+['nome'=>'Outro'],k())['status']==='existente','reimportar pessoa não duplica');
no(fn()=>$import->row('alunos',['codpessoa_origem'=>'000123','ra'=>'000123'],k()),'rejeita mudança silenciosa de RA');
no(fn()=>$import->row('alunos',['codpessoa_origem'=>'999999','ra'=>'999999'],k()),'não presume que RA igual ao código identifica pessoa inexistente');
$p2=$import->row('pessoas',['codpessoa_origem'=>'777','nome'=>'Carlos Importado','data_nascimento'=>'1980-01-01'],k());
no(fn()=>$import->row('alunos',['codpessoa_origem'=>'777','ra'=>'009999'],k()),'RA ocupado por outra pessoa é rejeitado');
$a2=$import->row('alunos',['codpessoa_origem'=>'777','ra'=>'777'],k());ok($db->get('alunos',(int)$a2['id'])['ra']==='777','RA igual ao código de origem também é aceito');
no(fn()=>$cat->updatePerson((int)$r['id'],['estado'=>'RJ'],k()),'cidade e UF incompatíveis rejeitadas');
no(fn()=>$cat->updatePerson((int)$r['id'],['pais'=>'ZZ'],k()),'país inválido rejeitado');
$cat->updatePerson((int)$r['id'],['pais'=>'US','estado'=>'Florida','cidade'=>'Orlando','cep'=>'32801'],k());ok($db->get('pessoas',(int)$r['id'])['cidade']==='ORLANDO','endereço estrangeiro permite região e cidade livres');
$cat->edit('alunos',(int)$a['id'],['tipo_aluno'=>'Regular'],k());ok($db->get('alunos',(int)$a['id'])['tipo_aluno']==='REGULAR','administrador altera tipo de aluno');
no(fn()=>$cat->edit('alunos',(int)$a['id'],['tipo_aluno'=>'Inválido'],k()),'tipo de aluno inválido rejeitado');
wp_set_current_user(999);no(fn()=>$import->row('pessoas',$p,k()),'importação bloqueada para não administrador');wp_set_current_user(1);
echo "PASS: $n verificações de importação e novos campos\n";
