<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts,MenuPolicy};
use EducacionalERP\Application\{CatalogService,Operations};
use EducacionalERP\Domain\RuleViolation;
Access::install();$db=new Database($wpdb);$cat=new CatalogService($db,new Operations($db));$n=0;
function kp():string{return bin2hex(random_bytes(16));}
function yes(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);$n++;echo "PASS: $m\n";}
function deny(callable $f,string $m):void{try{$f();}catch(RuleViolation $e){yes(true,$m);return;}throw new RuntimeException($m);}
$p=$cat->create('pessoas',['nome'=>'Ana Perfil','data_nascimento'=>'1980-08-15'],kp());$other=$cat->create('pessoas',['nome'=>'Maria Outra','data_nascimento'=>'1980-01-01'],kp());
wp_set_current_user((int)$p['wp_user_id']);$before=$db->get('pessoas',(int)$p['id']);$r=$cat->updateOwn(['telefone'=>'41999999999','profissao'=>'Professora','versao'=>$before['versao']],kp());
yes($db->get('pessoas',(int)$p['id'])['telefone']==='41999999999','pessoa edita seus próprios dados');
yes($db->get('pessoas',(int)$other['id'])['telefone']===null,'edição pessoal não afeta outra pessoa');
foreach(['codpessoa','codpessoa_origem','ativo','roles','wp_user_id','ra','responsavel_financeiro'] as $field)deny(fn()=>$cat->updateOwn([$field=>'1','versao'=>$r['versao']],kp()),'perfil rejeita campo privilegiado '.$field);
deny(fn()=>$cat->updateOwn(['telefone'=>'x','versao'=>$before['versao']],kp()),'edição concorrente de perfil é rejeitada');
yes(MenuPolicy::can('perfil')&&!MenuPolicy::can('pessoas'),'Pessoa só acessa perfil pessoal por padrão');
yes(!MenuPolicy::route('/cadastros/pessoas','POST'),'menu negado também é bloqueado na API');
deny(fn()=>MenuPolicy::save(['role'=>'erp_pessoa','menus'=>['pessoas']]),'não administrador não modifica permissões');
wp_set_current_user(1);$custom=MenuPolicy::create(['nome'=>'Atendimento']);MenuPolicy::save(['role'=>$custom['slug'],'menus'=>['pessoas']]);$u=get_userdata((int)$p['wp_user_id']);$u->add_role($custom['slug']);wp_set_current_user($u->ID);
yes(MenuPolicy::can('pessoas')&&current_user_can('erp_gerenciar_pessoas'),'perfil personalizado recebe acesso autorizado');
yes(!MenuPolicy::can('alunos')&&!MenuPolicy::route('/cadastros/alunos','POST'),'capability compartilhada não permite menu não concedido');
deny(fn()=>$cat->updatePerson((int)$other['id'],['nome'=>'Alterado'],kp()),'acesso ao cadastro não libera edição geral');
wp_set_current_user(1);MenuPolicy::save(['role'=>$custom['slug'],'menus'=>[]]);wp_set_current_user($u->ID);yes(!MenuPolicy::can('pessoas'),'revogação retira acesso');
wp_set_current_user(1);deny(fn()=>MenuPolicy::save(['role'=>$custom['slug'],'menus'=>['perfis']]),'menu reservado não pode ser concedido');deny(fn()=>MenuPolicy::save(['role'=>'administrator','menus'=>[]]),'administrador não pode ser bloqueado pela matriz');
MenuPolicy::save(['role'=>'erp_secretaria','menus'=>['matriculas']]);$u->add_role('erp_secretaria');wp_set_current_user($u->ID);yes(MenuPolicy::can('matriculas')&&current_user_can('erp_gerenciar_pessoas'),'matrículas permitem buscar alunos');yes(!MenuPolicy::route('/cadastros/pessoas','POST'),'matrículas não concedem criação de pessoa por API');
wp_set_current_user(999);deny(fn()=>$cat->updateOwn(['nome'=>'Sem vínculo','versao'=>'1'],kp()),'conta sem pessoa não pode editar');
echo "PASS: $n verificações de perfil e permissões\n";
