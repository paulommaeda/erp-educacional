<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {exit;}
require __DIR__.'/sqlite-bootstrap.php';
require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Application\{Operations,CatalogService,AcademicService,StudentWorkflow,ExportService};
use EducacionalERP\Domain\RuleViolation;
$db=new Database($wpdb);$ops=new Operations($db);$catalog=new CatalogService($db,$ops);$academic=new AcademicService($db,$ops);$flow=new StudentWorkflow($db,$ops,$catalog,$academic);$count=0;
function requestKey():string{return bin2hex(random_bytes(16));}
function check(bool $condition,string $label):void{global $count;$count++;if(!$condition)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
function refuse(callable $f,string $label):void{try{$f();}catch(RuleViolation $e){check(true,$label);return;}check(false,$label);}
$father=$catalog->create('pessoas',['data_nascimento'=>'1980-08-15','nome'=>'Carlos da Silva'],requestKey())['id'];
$mother=$catalog->create('pessoas',['data_nascimento'=>'1980-08-15','nome'=>'Ana da Silva'],requestKey())['id'];
$payload=['nome'=>'Sofia da Silva','ra'=>'000045','data_nascimento'=>'2015-04-12'];$key=requestKey();
$student=$flow->createStudent($payload,$key);
check($student===$flow->createStudent($payload,$key),'cadastro integrado idempotente');
$a=(int)$student['idaluno'];check($db->get('pessoas',(int)$student['codpessoa'])['nome']==='SOFIA DA SILVA','pessoa e aluno criados juntos');
$catalog->link($a,['codpessoa_responsavel'=>$father,'parentesco'=>'pai','responsavel_academico'=>1],requestKey());
$catalog->link($a,['codpessoa_responsavel'=>$mother,'parentesco'=>'mae','responsavel_academico'=>1,'responsavel_financeiro'=>1],requestKey());
$edited=$catalog->link($a,['codpessoa_responsavel'=>$mother,'parentesco'=>'mae','responsavel_academico'=>1,'responsavel_financeiro'=>1,'pode_rematricular'=>1],requestKey());
$links=$db->rows('SELECT * FROM '.$db->table('aluno_responsaveis').' WHERE idaluno=%d AND fim_vigencia IS NULL',[$a]);
check(count($links)===2,'edição de atribuições mantém dois vínculos vigentes');
check(count($db->rows('SELECT * FROM '.$db->table('aluno_responsaveis').' WHERE idaluno=%d AND fim_vigencia IS NOT NULL',[$a]))===1,'edição preserva histórico de vínculos');
refuse(fn()=>$catalog->link($a,['codpessoa_responsavel'=>$father,'parentesco'=>'pai','responsavel_financeiro'=>1],requestKey()),'não troca devedor por simples edição de vínculo');
$period=$catalog->create('periodos_letivos',['codigo'=>'2027','descricao'=>'Ano letivo 2027','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31'],requestKey())['id'];
$other=$catalog->create('periodos_letivos',['codigo'=>'2028','descricao'=>'Ano letivo 2028','data_inicio'=>'2028-01-01','data_fim'=>'2028-12-31'],requestKey())['id'];
$course=$catalog->create('cursos',['codigo'=>'EF','nome'=>'Ensino Fundamental'],requestKey())['id'];
$shift=$catalog->create('turnos',['codigo'=>'M','nome'=>'Matutino'],requestKey())['id'];
$plan=$catalog->create('planos_pagamento',['codigo'=>'P','nome'=>'Anuidade','valor_anuidade'=>'1200.00'],requestKey())['id'];
$makeClass=fn($p,$code)=>$catalog->create('turmas',['idplano'=>$plan,'codperiodo'=>$p,'idcurso'=>$course,'idturno'=>$shift,'codigo'=>$code,'nome'=>'6º ano '.$code,'capacidade'=>1],requestKey())['id'];
$t=$makeClass($period,'A');$wrong=$makeClass($other,'B');
$link=$flow->period($a,['codperiodo'=>$period],requestKey());
check($link['status']==='aguardando_turma','período salvo sem exigir turma');
check($db->get('aluno_periodos',(int)$link['idvinculoperiodo'])['idmatricula']===null,'nenhuma matrícula fictícia antes da turma');
check($flow->period($a,['codperiodo'=>$period],requestKey())['idvinculoperiodo']===$link['idvinculoperiodo'],'período não duplicado com outra chave');
refuse(fn()=>$flow->assign($a,(int)$link['idvinculoperiodo'],['idturma'=>$wrong],requestKey()),'rejeita turma de outro período');
check($db->get('aluno_periodos',(int)$link['idvinculoperiodo'])['status']==='aguardando_turma','erro conserva período pendente');
$placementKey=requestKey();$m=$flow->assign($a,(int)$link['idvinculoperiodo'],['idturma'=>$t,'quantidade_parcelas'=>3,'primeiro_vencimento'=>'2027-01-10'],$placementKey);
check($m===$flow->assign($a,(int)$link['idvinculoperiodo'],['idturma'=>$t,'quantidade_parcelas'=>3,'primeiro_vencimento'=>'2027-01-10'],$placementKey),'escolha de turma idempotente');
check(count($db->rows('SELECT * FROM '.$db->table('contratos')))===1,'matrícula inicial cria contrato financeiro');
check($db->get('matriculas',(int)$m['idmatricula'])['idcurso']===$course,'curso derivado da turma');
$otherStudent=$flow->createStudent(['data_nascimento'=>'2015-04-12','nome'=>'Outro aluno','ra'=>'000046'],requestKey());
refuse(fn()=>$flow->assign((int)$otherStudent['idaluno'],(int)$link['idvinculoperiodo'],['idturma'=>$t],requestKey()),'não aceita vínculo de outro aluno');
$secondLink=$flow->period((int)$otherStudent['idaluno'],['codperiodo'=>$period],requestKey());
refuse(fn()=>$flow->assign((int)$otherStudent['idaluno'],(int)$secondLink['idvinculoperiodo'],['idturma'=>$t],requestKey()),'turma lotada é rejeitada');
$contract=$m;
check(count($db->rows('SELECT * FROM '.$db->table('parcelas').' WHERE idcontrato=%d',[(int)$contract['idcontrato']]))===3,'parcelas criadas ao confirmar turma e contrato');
refuse(fn()=>$academic->createContract((int)$m['idmatricula'],['valor_original_total'=>'1200.00','quantidade_parcelas'=>3,'primeiro_vencimento'=>'2027-01-10'],requestKey()),'não duplica contrato em nova solicitação');
$catalog->updatePerson((int)$student['codpessoa'],['nome'=>'Sofia Silva','email'=>'sofia@example.com'],requestKey());
check($db->get('pessoas',(int)$student['codpessoa'])['nome']==='SOFIA SILVA','dados editáveis na ficha');
try{$flow->createStudent(['data_nascimento'=>'2015-04-12','nome'=>'Pessoa que deve reverter','ra'=>'000045'],requestKey());}catch(RuntimeException $e){}
check(!$db->row('SELECT codpessoa FROM '.$db->table('pessoas').' WHERE nome=%s',['Pessoa que deve reverter']),'RA duplicado não deixa pessoa órfã');
$export=(new ExportService($db))->student($a,(int)$period);check(count($export['matriculas'][0]['contratos'])===1,'exportação mantém contrato vinculado à matrícula');
$pendingExport=(new ExportService($db))->student((int)$otherStudent['idaluno'],(int)$period);
check(count($pendingExport['periodos_pendentes'])===1,'exportação inclui período ainda sem turma');
$catalog->link((int)$otherStudent['idaluno'],['codpessoa_responsavel'=>$mother,'parentesco'=>'mae','responsavel_financeiro'=>1],requestKey());
$futureLink=$flow->period((int)$otherStudent['idaluno'],['codperiodo'=>$other],requestKey());
$oldPath=$academic->enroll(['idaluno'=>$otherStudent['idaluno'],'idturma'=>$wrong,'valor_original_total'=>'1000.00','quantidade_parcelas'=>2,'primeiro_vencimento'=>'2028-01-10'],requestKey());
check($db->get('aluno_periodos',(int)$futureLink['idvinculoperiodo'])['idmatricula']===$oldPath['idmatricula'],'fluxo antigo conclui vínculo de período pendente');
echo "PASS: $count verificações do novo fluxo\n";
