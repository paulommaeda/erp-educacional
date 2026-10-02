<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';
require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Accounts;
use EducacionalERP\Application\{Operations,CatalogService};
use EducacionalERP\Domain\{UsernameRequired,RuleViolation};
$db=new Database($wpdb);$catalog=new CatalogService($db,new Operations($db));$accounts=new Accounts($db);$count=0;
function k():string{return bin2hex(random_bytes(16));}
function ok(bool $v,string $label):void{global $count;if(!$v)throw new RuntimeException($label);$count++;echo "PASS: $label\n";}
function person(string $name,array $extra=[]):array{global $catalog;return $catalog->create('pessoas',$extra+['nome'=>$name,'data_nascimento'=>'1980-08-15'],k());}
$a=person('João de Souza Silva');$b=person('João de Souza Silva');
ok($a['user_login']==='joao.silva'&&$b['user_login']==='joao.souza','homônimos usam sobrenome anterior e normalizam acentos');
$before=$wpdb->pdo->query('SELECT COUNT(*) FROM mock_users')->fetchColumn();
try{person('João de Souza Silva');throw new RuntimeException('collision accepted');}catch(UsernameRequired $e){ok(true,'sem sobrenome disponível pede login manual');}
ok($before===$wpdb->pdo->query('SELECT COUNT(*) FROM mock_users')->fetchColumn(),'falha de login não deixa conta órfã');
$c=person('João de Souza Silva',['user_login'=>'joao.familia']);ok($c['user_login']==='joao.familia','login manual disponível aceito');
$u=$accounts->summary((int)$a['id']);ok($u['roles']===['erp_pessoa'],'pessoa nasce com perfil Pessoa');
$row=$wpdb->pdo->query('SELECT user_pass FROM mock_users WHERE ID='.(int)$u['wp_user_id'])->fetch();ok($row[0]==='15081980','senha inicial enviada ao WordPress em DDMMAAAA');
$student=$catalog->create('alunos',['codpessoa'=>$a['id'],'ra'=>'001'],k());
ok($accounts->summary((int)$a['id'])['roles']===['erp_aluno'],'virar aluno sincroniza perfil');
$child=person('Maria Oliveira');$cs=$catalog->create('alunos',['codpessoa'=>$child['id'],'ra'=>'002'],k());
$catalog->link((int)$cs['id'],['codpessoa_responsavel'=>$a['id'],'parentesco'=>'padrasto','responsavel_academico'=>1,'responsavel_financeiro'=>1],k());
$roles=$accounts->summary((int)$a['id'])['roles'];ok(count($roles)===3&&in_array('erp_aluno',$roles)&&in_array('erp_responsavel_academico',$roles)&&in_array('erp_responsavel_financeiro',$roles),'aluno e dois responsáveis acumulam perfis');
$catalog->updatePerson((int)$a['id'],['nome'=>'João Silva Atualizado','data_nascimento'=>'1981-01-01'],k());
ok($accounts->summary((int)$a['id'])['user_login']==='joao.silva'&&$wpdb->pdo->query('SELECT user_pass FROM mock_users WHERE ID='.(int)$u['wp_user_id'])->fetchColumn()==='15081980','edição mantém login e senha');
wp_set_current_user((int)$u['wp_user_id']);try{$catalog->updatePerson((int)$a['id'],['nome'=>'Inválido'],k());throw new RuntimeException('edit accepted');}catch(RuleViolation $e){ok(true,'não administrador não edita pessoa');}
try{$catalog->edit('alunos',(int)$student['id'],['ra'=>'003'],k());throw new RuntimeException('edit accepted');}catch(RuleViolation $e){ok(true,'não administrador não edita aluno');}
wp_set_current_user(1);$catalog->edit('alunos',(int)$student['id'],['ra'=>'003'],k());ok($db->get('alunos',(int)$student['id'])['ra']==='003','administrador edita aluno');
$course=$catalog->create('cursos',['codigo'=>'EF','nome'=>'Fundamental'],k());$catalog->edit('cursos',(int)$course['id'],['nome'=>'Fundamental II','descricao'=>'Novo nome'],k());ok($db->get('cursos',(int)$course['id'])['nome']==='FUNDAMENTAL II','administrador edita curso');
try{person('Pessoa Sem Data',['data_nascimento'=>'']);throw new RuntimeException('missing date accepted');}catch(RuleViolation $e){ok(true,'nascimento obrigatório');}
$raw=$db->insert('pessoas',['nome'=>'Legado Sem Nascimento']);$result=$accounts->migrate();ok(in_array('pendente',array_column($result['items'],'status')),'cadastro legado sem nascimento aparece pendente');
$catalog->updatePerson($raw,['data_nascimento'=>'1970-01-01'],k());ok($accounts->summary($raw)['status']==='criada','corrigir legado cria conta');
$period=$catalog->create('periodos_letivos',['codigo'=>'2027','descricao'=>'Ano','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31'],k());
$catalog->edit('periodos_letivos',(int)$period['id'],['descricao'=>'Ano letivo','status'=>'aberto'],k());ok($db->get('periodos_letivos',(int)$period['id'])['status']==='aberto','administrador edita período');
$shift=$catalog->create('turnos',['codigo'=>'M','nome'=>'Manhã'],k());$catalog->edit('turnos',(int)$shift['id'],['hora_inicio'=>'07:00','hora_fim'=>'12:00'],k());ok($db->get('turnos',(int)$shift['id'])['hora_inicio']==='07:00','administrador edita turno');
$class=$catalog->create('turmas',['codigo'=>'A','nome'=>'Turma A','codperiodo'=>$period['id'],'idcurso'=>$course['id'],'idturno'=>$shift['id'],'capacidade'=>20],k());$catalog->edit('turmas',(int)$class['id'],['nome'=>'Turma B','capacidade'=>25],k());ok($db->get('turmas',(int)$class['id'])['nome']==='TURMA B','administrador edita turma');
$GLOBALS['test_update_error']=new WP_Error('test_update_rejected','Atualização recusada no teste.');
$before=$wpdb->pdo->query('SELECT COUNT(*) FROM mock_users')->fetchColumn();
try{person('Pessoa Erro Sincronizacao');throw new RuntimeException('error ignored');}catch(RuleViolation $e){ok(str_contains($e->getMessage(),'test_update_rejected')&&str_contains($e->getMessage(),'Atualização recusada'),'sincronização informa código e mensagem do WordPress');}
unset($GLOBALS['test_update_error']);
ok($before===$wpdb->pdo->query('SELECT COUNT(*) FROM mock_users')->fetchColumn()&&!$db->row('SELECT codpessoa FROM '.$db->table('pessoas').' WHERE nome=%s',['Pessoa Erro Sincronizacao']),'falha de sincronização reverte conta e pessoa');
echo "PASS: $count verificações de contas\n";
