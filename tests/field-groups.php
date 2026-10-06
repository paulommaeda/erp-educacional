<?php
require __DIR__.'/additional-fields.php';
use EducacionalERP\Infrastructure\WordPress\{MenuPolicy,FieldGroupPolicy};
$g=$s->saveGroup(['nome'=>'Saúde','ordem'=>2],kk());$field=$s->save(['idgrupo'=>$g['idgrupo'],'nome'=>'Restrição','chave'=>'restricao','tipo'=>'texto','exibir_pessoa'=>0],kk());
$cat->updatePerson($p,['campos_adicionais'=>['restricao'=>'teste']],kk());
$person=$db->get('pessoas',$p);$user=(int)$db->row('SELECT wp_user_id FROM '.$db->table('pessoa_usuarios').' WHERE codpessoa=%d',[$p])['wp_user_id'];get_userdata($user)->add_role('erp_secretaria');
wp_set_current_user($user);check(!$s->groups()&&!$s->all()&&!$s->decorate($person)['campos_adicionais'],'no role permission hides groups, definitions and values');
wp_set_current_user(1);MenuPolicy::save(['role'=>'erp_secretaria','menus'=>['alunos','pessoas'],'field_groups'=>[(string)$g['idgrupo']]]);
wp_set_current_user($user);check(FieldGroupPolicy::can((int)$g['idgrupo']),'role group permission applied');check(count($s->groups())===1&&count($s->all())===1,'only granted group definitions visible');$values=$s->decorate($person)['campos_adicionais'];check(isset($values['restricao'])&&!isset($values['nacionalidade']),'payload strips unauthorized group values');
reject(fn()=>$db->atomic(fn()=>$s->write($p,['nacionalidade'=>'novo'],kk())),'unauthorized writes blocked');
wp_set_current_user(1);MenuPolicy::save(['role'=>'erp_secretaria','menus'=>['alunos','pessoas'],'field_groups'=>[]]);wp_set_current_user($user);check(!$s->groups()&&!$s->decorate($person)['campos_adicionais'],'revoking permission immediately blocks payload');
wp_set_current_user(1);$s->saveGroup(array_merge($g,['ativo'=>0]),kk());check(!isset($s->decorate($person)['campos_adicionais']['restricao']),'inactive group hides its values even for admin');check($db->row('SELECT valor FROM '.$db->table('pessoa_campos_adicionais').' WHERE idcampo=%d',[$field['idcampo']])['valor']==='TESTE','inactivation retains group values');
echo "FIELD_GROUPS_OK\n";
