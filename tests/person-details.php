<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Application\{CatalogService,Operations,DataExport};
use EducacionalERP\Domain\{PersonFields,RuleViolation};
use EducacionalERP\Infrastructure\Database\Database;
$db=new Database($wpdb);$catalog=new CatalogService($db,new Operations($db));
function detailKey():string{return bin2hex(random_bytes(16));}
function detailCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function detailReject(callable $f,string $label):void{try{$f();}catch(RuleViolation $e){detailCheck(true,$label);return;}throw new RuntimeException($label);}
$fields=['local_nascimento'=>'São José dos Pinhais','uf_nascimento'=>'pr','raca_etnia'=>'Indigena','nacionalidade'=>'Brasileira','grau_instrucao'=>'Pos-graduacao','profissao'=>'Médica'];
$result=$catalog->create('pessoas',['nome'=>'Ana Detalhes','data_nascimento'=>'1980-01-01']+$fields,detailKey());$id=(int)$result['id'];$person=$db->get('pessoas',$id);
$expected=['local_nascimento'=>'SÃO JOSÉ DOS PINHAIS','uf_nascimento'=>'PR','raca_etnia'=>'INDÍGENA','nacionalidade'=>'BRASILEIRA','grau_instrucao'=>'PÓS-GRADUAÇÃO','profissao'=>'MÉDICA'];
foreach($expected as $key=>$value)detailCheck($person[$key]===$value,'Cadastro grava '.$key.' normalizado');
foreach(PersonFields::RACES as $value)detailCheck(PersonFields::validate(['raca_etnia'=>$value])['raca_etnia']===$value,'Aceita raça/etnia '.$value);
foreach(PersonFields::EDUCATION as $value)detailCheck(PersonFields::validate(['grau_instrucao'=>$value])['grau_instrucao']===$value,'Aceita grau de instrução '.$value);
$catalog->updatePerson($id,['email'=>'Ana.Detalhes@example.test'],detailKey());$person=$db->get('pessoas',$id);
detailCheck($person['email']==='Ana.Detalhes@example.test'&&$person['local_nascimento']===$expected['local_nascimento'],'Edição parcial preserva nascimento e caixa do e-mail');
foreach(['uf_nascimento'=>'ZZ','raca_etnia'=>'OUTRA','grau_instrucao'=>'INDEFINIDO'] as $key=>$value){$before=$db->get('pessoas',$id);detailReject(fn()=>$catalog->updatePerson($id,[$key=>$value],detailKey()),'Rejeita opção inválida em '.$key);detailCheck($db->get('pessoas',$id)===$before,'Rejeição não altera dados nem versão');}
$catalog->updatePerson($id,['pais'=>'US','estado'=>'Florida','cidade'=>'Orlando'],detailKey());detailCheck($db->get('pessoas',$id)['uf_nascimento']==='PR','Nascimento independente do endereço e país atuais');
$export=(new DataExport($db))->page(['coligadas'=>[1],'tabela'=>'pessoas']);detailCheck($export['registros'][0]['raca_etnia']==='INDÍGENA'&&$export['registros'][0]['grau_instrucao']==='PÓS-GRADUAÇÃO','Exportação inclui os novos campos de pessoas');
wp_set_current_user((int)$result['wp_user_id']);$before=$db->get('pessoas',$id);$catalog->updateOwn(['versao'=>$before['versao'],'local_nascimento'=>'Lisboa','uf_nascimento'=>'EX','nacionalidade'=>'Portuguesa','raca_etnia'=>'Não declarada','grau_instrucao'=>'Mestrado','profissao'=>'Docente'],detailKey());$person=$db->get('pessoas',$id);
detailCheck($person['local_nascimento']==='LISBOA'&&$person['uf_nascimento']==='EX'&&$person['nacionalidade']==='PORTUGUESA'&&$person['raca_etnia']==='NÃO DECLARADA'&&$person['grau_instrucao']==='MESTRADO'&&$person['profissao']==='DOCENTE','Perfil pessoal altera todos os novos campos da própria pessoa');
wp_set_current_user(1);$catalog->updatePerson($id,array_fill_keys(array_keys($expected),''),detailKey());$person=$db->get('pessoas',$id);
foreach(array_keys($expected) as $key)detailCheck($person[$key]===null,'Permite limpar o campo opcional '.$key);
$legacy=$catalog->create('pessoas',['nome'=>'Pessoa Sem Detalhes','data_nascimento'=>'2000-01-01'],detailKey());$person=$db->get('pessoas',(int)$legacy['id']);
detailCheck($person['raca_etnia']===null&&$person['grau_instrucao']===null,'Cadastros antigos e novos sem detalhes continuam válidos');
echo "PERSON_DETAILS_OK\n";
