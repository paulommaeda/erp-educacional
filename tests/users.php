<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy,UserPermissions};
use EducacionalERP\Application\{UserService,CatalogService,Operations};
use EducacionalERP\Domain\RuleViolation;
function sanitize_text_field($s){return trim(strip_tags($s));}function wp_salt($s){return 'test-only-salt';}function wp_cache_delete(...$a){}function shortcode_exists($s){return $GLOBALS['vendor']??false;}function user_can($u,$cap){foreach($u->roles as $r)if(!empty($GLOBALS['test_roles'][$r]['capabilities'][$cap]))return true;return false;}function is_super_admin($id){return $id===1;}
function home_url($p=''){return 'https://example.test'.$p;}function add_query_arg($k,$v,$url){return $url.'?'.$k.'='.$v;}function wp_parse_url($s){return parse_url($s);}function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}function do_shortcode($s){$GLOBALS['rendered_shortcode']=$s;return '<a href="https://example.test/vendor?nonce=test">Acessar</a>';}
class WP_User_Query {private array $rows;public function __construct($a){global $wpdb;$rows=$wpdb->pdo->query('SELECT * FROM mock_users ORDER BY display_name')->fetchAll(PDO::FETCH_ASSOC);$search=trim($a['search'],'*');$this->rows=array_values(array_filter(array_map(fn($r)=>new WP_User($r),$rows),fn($u)=>!$search||str_contains($u->display_name.$u->user_login.$u->user_email,$search)));}public function get_results(){return $this->rows;}public function get_total(){return count($this->rows);}}
Access::install();$db=new Database($wpdb);$cat=new CatalogService($db,new Operations($db));$service=new UserService($db);$n=0;
function kU(){return bin2hex(random_bytes(16));}function okU($b,$m){global $n;if(!$b)throw new RuntimeException($m);$n++;echo "PASS: $m\n";}function denyU($f,$m){try{$f();}catch(RuleViolation $e){okU(true,$m);return;}throw new RuntimeException($m);}
$p=$cat->create('pessoas',['nome'=>'Familia Teste','data_nascimento'=>'1980-01-01','email'=>'familia@example.test'],kU());$uid=(int)$p['wp_user_id'];$person=(int)$p['id'];$secretary=wp_insert_user(['user_login'=>'secretaria','role'=>'erp_secretaria']);wp_set_current_user($secretary);
$list=$service->listing(['search'=>'familia']);okU($list['total']===1&&$list['permissions']['password']&&!isset($list['permissions']['switch']),'secretaria consulta e edita, sem impersonação padrão');$version=$list['items'][0]['version'];
$service->update($uid,['email'=>'novo@example.test','password'=>'New!Pass987654','version'=>$version],kU());okU(get_userdata($uid)->user_email==='novo@example.test'&&$db->get('pessoas',$person)['email']==='novo@example.test','e-mail sincronizado entre conta e pessoa');
okU(get_userdata($uid)->user_pass==='New!Pass987654','nova senha encaminhada à API WordPress');
$audit=wp_json_encode($db->rows('SELECT * FROM '.$db->table('auditoria')));okU(!str_contains($audit,'New!Pass987654'),'auditoria não guarda senha');
denyU(fn()=>$service->update($uid,['email'=>'stale@example.test','version'=>$version],kU()),'versão antiga rejeitada');
denyU(fn()=>$service->update(1,['password'=>'BreakAdmin12345','version'=>$version],kU()),'secretaria não altera administrador');
denyU(fn()=>$service->update($secretary,['password'=>'BreakStaff12345','version'=>$version],kU()),'secretaria não altera conta operacional');
$version=$service->listing(['search'=>'familia'])['items'][0]['version'];denyU(fn()=>$service->update($uid,['roles'=>['administrator'],'version'=>$version],kU()),'elevação por campos extras bloqueada');
$other=wp_insert_user(['user_login'=>'outro','user_email'=>'ocupado@example.test','role'=>'erp_pessoa']);denyU(fn()=>$service->update($uid,['email'=>'ocupado@example.test','version'=>$version],kU()),'e-mail duplicado rejeitado');
denyU(fn()=>$service->update($uid,['password'=>'curta','version'=>$version],kU()),'senha curta rejeitada');
$GLOBALS['test_update_error']=new WP_Error('fail','Falha simulada');denyU(fn()=>$service->update($uid,['email'=>'falha@example.test','version'=>$version],kU()),'falha WordPress propagada');unset($GLOBALS['test_update_error']);okU($db->get('pessoas',$person)['email']==='novo@example.test','falha reverte e-mail da pessoa');
wp_set_current_user(1);MenuPolicy::save(['role'=>'erp_secretaria','menus'=>['usuarios'],'user_permissions'=>['email']]);wp_set_current_user($secretary);denyU(fn()=>$service->update($uid,['password'=>'Revoked1234567','version'=>$version],kU()),'revogação de senha vale na API');
okU(!UserPermissions::can('switch'),'acesso assistido removido do ERP');
wp_set_current_user($uid);denyU(fn()=>$service->listing([]),'pessoa sem permissão não lista usuários');echo "$n verificações aprovadas\n";

wp_set_current_user(1);$entry=$service->listing(['search'=>'familia'])['items'][0];$before=get_userdata($uid)->roles;$service->update($uid,['roles'=>['erp_coordenacao'],'version'=>$entry['version']],kU());okU(in_array('erp_coordenacao',get_userdata($uid)->roles,true)&&!array_diff($before,get_userdata($uid)->roles),'admin adds operational role preserving automatic roles');denyU(fn()=>$service->update($uid,['roles'=>[],'version'=>$entry['version']],kU()),'role change invalidates old account version');$entry=$service->listing(['search'=>'familia'])['items'][0];$service->update($uid,['roles'=>[],'version'=>$entry['version']],kU());okU(!in_array('erp_coordenacao',get_userdata($uid)->roles,true),'admin removes operational role');$entry=$service->listing(['search'=>'familia'])['items'][0];denyU(fn()=>$service->update($uid,['roles'=>['erp_aluno'],'version'=>$entry['version']],kU()),'automatic academic role cannot be assigned manually');echo "USER_ROLES_OK\n";
