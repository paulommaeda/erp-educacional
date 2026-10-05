<?php
require __DIR__.'/coligadas.php';
use EducacionalERP\Application\{DataExport,Coligadas};
use EducacionalERP\Infrastructure\WordPress\SchoolIdentity;
wp_set_current_user(1);$export=new DataExport($db);
$catalog=$export->catalog();testP(count($catalog['coligadas'])===2&&isset($catalog['categorias']['personalizacao']),'exportação lista coligadas e personalização');
$one=$export->page(['tabela'=>'alunos','coligadas'=>[1]]);$two=$export->page(['tabela'=>'alunos','coligadas'=>[$second]]);
testP(count($one['registros'])>0&&count($two['registros'])>0&&!array_filter($two['registros'],fn($r)=>(int)$r['codcoligada']!==$second),'exportação respeita coligada selecionada');
$global=$export->page(['tabela'=>'pessoas','coligadas'=>[$second]]);testP($global['compartilhada']&&count($global['registros'])===count($db->rows('SELECT * FROM '.$db->table('pessoas'))),'pessoas compartilhadas exportadas globalmente');
$users=$export->page(['tabela'=>'usuarios','coligadas'=>[1]]);testP(count($users['registros'])>0&&!str_contains(json_encode($users),'user_pass')&&isset($users['registros'][0]['roles']),'usuários preservam perfis sem exportar senhas');
for($i=100;$i<305;$i++)$db->insert('estados_civis',['idestado_civil'=>$i,'nome'=>'TESTE '.$i]);
$page=$export->page(['tabela'=>'estados_civis','coligadas'=>[1]]);$page2=$export->page(['tabela'=>'estados_civis','coligadas'=>[1],'cursor'=>$page['proximo_cursor']]);
$ids=array_column(array_merge($page['registros'],$page2['registros']),'idestado_civil');testP(count($page['registros'])===200&&count($ids)===count(array_unique($ids))&&count($ids)===count($db->rows('SELECT * FROM '.$db->table('estados_civis')))&&$page2['proximo_cursor']===null,'cursor pagina sem omissões ou duplicações');
$links=$export->page(['tabela'=>'oferta_turmas','coligadas'=>[1,$second]]);$first=$links['registros'][0];$after=$export->page(['tabela'=>'oferta_turmas','coligadas'=>[1,$second],'cursor'=>[$first['idoferta'],$first['idturma']]]);testP(count($after['registros'])===count($links['registros'])-1,'cursor considera chave primária composta');
Coligadas::within(1,fn()=>update_option(SchoolIdentity::option(),['nome'=>'ESCOLA UM','cor_primaria'=>'#112233'],false));Coligadas::within($second,fn()=>update_option(SchoolIdentity::option(),['nome'=>'ESCOLA DOIS','cor_primaria'=>'#445566'],false));
$settings=$export->settings(['coligadas'=>[1,$second],'categorias'=>['personalizacao']]);testP($settings['coligadas'][1]['identidade_visual']['cor_primaria']==='#112233'&&$settings['coligadas'][$second]['identidade_visual']['cor_primaria']==='#445566'&&!isset($settings['globais']),'cores isoladas e configuração desmarcada não exportada');
deniedP(fn()=>$export->page(['tabela'=>'operacoes','coligadas'=>[1]]),'tabelas internas não são exportáveis');deniedP(fn()=>$export->page(['tabela'=>'pessoas; DROP TABLE pessoas','coligadas'=>[1]]),'identificadores arbitrários rejeitados');deniedP(fn()=>$export->page(['tabela'=>'pessoas','coligadas'=>[1],'cursor'=>['1 OR 1=1']]),'cursor inválido rejeitado');
wp_set_current_user($rfUser);deniedP(fn()=>$export->catalog(),'responsável não acessa exportação');deniedP(fn()=>$export->page(['tabela'=>'pessoas','coligadas'=>[1]]),'responsável não exporta por REST');
echo "PASS: exportação seletiva, segurança, paginação e personalização\n";
