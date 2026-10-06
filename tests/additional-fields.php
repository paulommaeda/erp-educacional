<?php
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Application\{AdditionalFields,CatalogService,Operations,CivilStatus};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\RuleViolation;
Access::install();wp_set_current_user(1);$db=new Database($wpdb);$s=new AdditionalFields($db);$s->migrateGroups();$group=(int)$s->groups()[0]['idgrupo'];$cat=new CatalogService($db,new Operations($db));
function kk(){return bin2hex(random_bytes(16));}function check($x,$m){if(!$x)throw new RuntimeException($m);echo "PASS $m\n";}function reject($f,$m){try{$f();}catch(RuleViolation $e){check(true,$m);return;}throw new RuntimeException($m);}
$a=$s->save(['idgrupo'=>$group,'nome'=>'Nacionalidade','chave'=>'nacionalidade','tipo'=>'texto','exibir_pessoa'=>1,'secao'=>'pessoais'],kk());
$b=$s->save(['idgrupo'=>$group,'nome'=>'Observação','chave'=>'observacao','tipo'=>'texto_longo','exibir_pessoa'=>0],kk());
$p=(int)$cat->create('pessoas',['nome'=>'Pessoa Adicional','data_nascimento'=>'2000-01-01','campos_adicionais'=>['nacionalidade'=>'brasileira','observacao'=>'via api']],kk())['id'];
$d=(new CivilStatus($db))->decorate($db->get('pessoas',$p));check($d['campos_adicionais']['nacionalidade']==='BRASILEIRA'&&$d['campos_adicionais']['observacao']==='VIA API','API saves visible and hidden values, normalized');
$cat->updatePerson($p,['campos_adicionais'=>['nacionalidade'=>'argentina']],kk());$d=$s->decorate($db->get('pessoas',$p));check($d['campos_adicionais']['observacao']==='VIA API','partial edit preserves hidden fields');
reject(fn()=>$cat->updatePerson($p,['nome'=>'Nome indevido','campos_adicionais'=>['desconhecido'=>'x']],kk()),'unknown field rejects update');check($db->get('pessoas',$p)['nome']==='PESSOA ADICIONAL','invalid extra rolls back person update');
reject(fn()=>$s->save(array_merge($a,['tipo'=>'numero']),kk()),'cannot mutate field type');
$c=$s->save(['idgrupo'=>$group,'nome'=>'Opções','chave'=>'opcao','tipo'=>'selecao','opcoes'=>"A\nB"],kk());reject(fn()=>$cat->updatePerson($p,['campos_adicionais'=>['opcao'=>'C']],kk()),'selection validates allowed options');
$s->save(array_merge($b,['ativo'=>0]),kk());check(!isset($s->decorate($db->get('pessoas',$p))['campos_adicionais']['observacao']),'inactive field hidden but stored');check(count($db->rows('SELECT * FROM '.$db->table('pessoa_campos_adicionais').' WHERE codpessoa=%d',[$p]))===2,'inactive values retained');
echo "ADDITIONAL_FIELDS_OK\n";
