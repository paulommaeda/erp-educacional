<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts,MenuPolicy};
use EducacionalERP\Application\{Operations,CatalogService,AcademicService,StudentWorkflow,SchoolSettings,EnrollmentDirectory,DeletionService};
use EducacionalERP\Domain\RuleViolation;
Access::install();$db=new Database($wpdb);$ops=new Operations($db);$cat=new CatalogService($db,$ops);$academic=new AcademicService($db,$ops);$flow=new StudentWorkflow($db,$ops,$cat,$academic);$settings=new SchoolSettings($db);$listing=new EnrollmentDirectory($db);$del=new DeletionService($db,$ops);$n=0;
function k():string{return bin2hex(random_bytes(16));}
function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS: $label\n";}
function reject(callable $f,string $label):void{try{$f();}catch(RuleViolation $e){ok(true,$label);return;}throw new RuntimeException($label);}
function create(string $t,array $d):int{global $cat;return (int)$cat->create($t,$d,k())['id'];}
function drop(string $t,int $id):array{global $db,$del;return $del->remove($t,$id,['versao'=>$db->get($t,$id)['versao'],'motivo'=>'Correção de cadastro de teste'],k());}
$period=create('periodos_letivos',['codigo'=>'2026','descricao'=>'Ano 2026','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']);
$next=create('periodos_letivos',['codigo'=>'2027','descricao'=>'Ano 2027','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31']);
$c=create('cursos',['codigo'=>'EF','nome'=>'Fundamental']);$u=create('turnos',['codigo'=>'M','nome'=>'Manhã']);
$plan=create('planos_pagamento',['codigo'=>'P','nome'=>'Anuidade','valor_anuidade'=>'1000.00']);
$t=create('turmas',['idplano'=>$plan,'codigo'=>'A','nome'=>'Turma A','codperiodo'=>$period,'idcurso'=>$c,'idturno'=>$u,'capacidade'=>2]);
$t2=create('turmas',['idplano'=>$plan,'codigo'=>'B','nome'=>'Turma B','codperiodo'=>$next,'idcurso'=>$c,'idturno'=>$u,'capacidade'=>10]);
$students=[];for($i=1;$i<=3;$i++)$students[]=$flow->createStudent(['nome'=>'Aluno Teste '.$i,'user_login'=>'aluno.teste'.$i,'ra'=>'000'.$i,'data_nascimento'=>'2015-01-01','tipo_aluno'=>$i===1?'AEE':'Regular'],k());
$guardian=create('pessoas',['nome'=>'Responsavel Teste','data_nascimento'=>'1980-01-01']);foreach($students as $a)$cat->link((int)$a['idaluno'],['codpessoa_responsavel'=>$guardian,'parentesco'=>'pai','responsavel_financeiro'=>1],k());
$ids=array_map(fn($a)=>(int)$a['idaluno'],$students);
$settings->save(['codperiodo'=>$period],k());ok(SchoolSettings::current()===$period,'configura período atual');
reject(fn()=>$settings->save(['codperiodo'=>9999],k()),'período inexistente rejeitado');
reject(fn()=>drop('periodos_letivos',$period),'período configurado não pode ser excluído');
reject(fn()=>drop('cursos',$c),'curso com turmas protegido');reject(fn()=>drop('turnos',$u),'turno com turmas protegido');
$payment=['quantidade_parcelas'=>2,'primeiro_vencimento'=>'2026-12-10'];
$payload=$payment+['codperiodo'=>$period,'idturma'=>$t,'alunos'=>$ids];reject(fn()=>$flow->batch($payload,k()),'lote acima das vagas é rejeitado');ok($listing->search([])['total']===0,'rollback remove todas as matrículas do lote inválido');ok(!$db->rows('SELECT * FROM '.$db->table('aluno_periodos')),'rollback remove vínculos ao período');
$pending=$flow->period($ids[0],['codperiodo'=>$period],k());$payload['alunos']=array_slice($ids,0,2);$key=k();$batch=$flow->batch($payload,$key);ok($batch['total']===2,'lote cria duas matrículas');ok($flow->batch($payload,$key)===$batch,'repetir envio não duplica lote');
ok($db->get('aluno_periodos',(int)$pending['idvinculoperiodo'])['status']==='matriculado','lote reaproveita vínculo pendente');
reject(fn()=>$flow->batch($payload,k()),'duplicidade bloqueia outro lote');
$flow->batch($payment+['codperiodo'=>$next,'idturma'=>$t2,'alunos'=>[$ids[2]]],k());
ok($listing->search([])['total']===2,'listagem usa período configurado por padrão');ok($listing->search(['codperiodo'=>'todos'])['total']===3,'consulta pode selecionar todos os períodos');
$r=$listing->search(['tipo_aluno'=>'AEE','idcurso'=>$c,'idturno'=>$u,'idturma'=>$t,'search'=>'0001','status'=>'ativa','origem'=>'secretaria','data_inicio'=>'2000-01-01']);ok($r['total']===1&&$r['items'][0]['ra']==='0001','combinação de filtros mantém RA e dados de turma curso e turno');
ok($listing->search(['idturma'=>$t2])['total']===0,'filtros de outro período não vazam registros');
reject(fn()=>drop('pessoas',(int)$students[0]['codpessoa']),'pessoa com aluno protegido');reject(fn()=>drop('alunos',$ids[0]),'aluno com matrícula protegido');reject(fn()=>drop('turmas',$t),'turma com matrícula protegida');
// Simulate an existing academic-only enrollment from the prior version for deletion coverage.
$legacy=$flow->createStudent(['nome'=>'Legado Teste','ra'=>'LEGADO','data_nascimento'=>'2015-01-01'],k());$legacyId=(int)$legacy['idaluno'];$legacyClass=create('turmas',['idplano'=>$plan,'codigo'=>'LEG','nome'=>'Legado','codperiodo'=>$period,'idcurso'=>$c,'idturno'=>$u,'capacidade'=>10]);$pending=$flow->period($legacyId,['codperiodo'=>$period],k());$m=(int)$db->atomic(fn()=>$academic->placeInside($legacyId,$period,$legacyClass,k()))['idmatricula'];$db->update('aluno_periodos',(int)$pending['idvinculoperiodo'],['idmatricula'=>$m,'status'=>'matriculado']);reject(fn()=>$del->remove('matriculas',$m,['versao'=>999,'motivo'=>'Teste'],k()),'exclusão verifica concorrência pela versão');
drop('matriculas',$m);ok($listing->search([])['total']===2,'matrícula sem dependências pode ser excluída');ok($db->get('aluno_periodos',(int)$pending['idvinculoperiodo'])['idmatricula']===null,'exclusão reabre vínculo de período');
ok((bool)$db->row('SELECT idauditoria FROM '.$db->table('auditoria')." WHERE entidade='matriculas' AND entidade_id=%d AND acao='excluir'",[$m]),'exclusão guarda auditoria e motivo');
drop('aluno_periodos',(int)$pending['idvinculoperiodo']);drop('alunos',$legacyId);ok(!in_array('erp_aluno',(new Accounts($db))->summary((int)$legacy['codpessoa'])['roles'],true),'exclusão de aluno sincroniza perfil');
$account=(new Accounts($db))->summary((int)$legacy['codpessoa']);drop('pessoas',(int)$legacy['codpessoa']);ok((bool)get_userdata((int)$account['wp_user_id']),'exclusão de pessoa preserva conta WordPress');ok(!in_array('erp_pessoa',get_userdata((int)$account['wp_user_id'])->roles,true),'conta desvinculada perde papéis ERP');
// A contract prevents deletion even with no payment.
$m2=(int)$batch['items'][1]['idmatricula'];reject(fn()=>drop('matriculas',$m2),'contrato bloqueia exclusão da matrícula');
$mt=$listing->search(['codperiodo'=>$next])['items'][0];$td=create('turmas',['idplano'=>$plan,'codigo'=>'C','nome'=>'Turma C','codperiodo'=>$next,'idcurso'=>$c,'idturno'=>$u,'capacidade'=>10]);$academic->transfer((int)$mt['idmatricula'],['idturma_destino'=>$td,'motivo'=>'Transferência teste'],k());reject(fn()=>drop('matriculas',(int)$mt['idmatricula']),'histórico de transferência bloqueia exclusão');reject(fn()=>drop('turmas',$t2),'turma de origem preserva histórico');
$free=create('cursos',['codigo'=>'L','nome'=>'Livre']);drop('cursos',$free);ok(!$db->row('SELECT idcurso FROM '.$db->table('cursos').' WHERE idcurso=%d',[$free]),'cadastro sem dependências pode ser excluído');
wp_set_current_user((int)$account['wp_user_id']);reject(fn()=>$settings->save(['codperiodo'=>$next],k()),'não admin não configura período');reject(fn()=>drop('turmas',$td),'não admin não exclui cadastros');reject(fn()=>$flow->batch($payload,k()),'sem permissão não matricula em lote');ok(!MenuPolicy::route('/exclusoes/alunos/1','POST'),'API de exclusão reservada ao administrador');
echo "PASS: $n verificações de gestão acadêmica\n";
