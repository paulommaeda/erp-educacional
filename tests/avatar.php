<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\Avatar;
use EducacionalERP\Application\{CatalogService,Operations};
use EducacionalERP\Domain\RuleViolation;
class WP_Post {public function __construct(public int $post_author){}}
class WP_Comment {public function __construct(public int $user_id){}}
$db=new Database($wpdb);$cat=new CatalogService($db,new Operations($db));$avatar=new Avatar($db);update_option('ederp_schema_version',Installer::VERSION);
function keyA():string{return bin2hex(random_bytes(16));}
function checkA(bool $x,string $msg):void{if(!$x)throw new RuntimeException($msg);echo "PASS: $msg\n";}
$p=$cat->create('pessoas',['nome'=>'Pessoa Foto','email'=>'foto@example.test','data_nascimento'=>'1990-01-01','foto_attachment_id'=>10],keyA());$user=(int)$p['wp_user_id'];$default=['url'=>'gravatar','found_avatar'=>false,'size'=>40];
foreach([$user,(string)$user,get_userdata($user),'foto@example.test',new WP_Post($user),new WP_Comment($user)] as $identity){$result=$avatar->filter($default,$identity);checkA($result['url']==='https://test.example/foto.jpg'&&$result['found_avatar']&&$result['size']===40,'foto cadastrada é avatar WordPress no schema atual por '.get_debug_type($identity));}
checkA($avatar->filter($default,99999)===$default,'conta sem pessoa vinculada mantém avatar WordPress');
checkA($avatar->filter($default+['force_default'=>true],$user)===$default+['force_default'=>true],'avatar padrão solicitado explicitamente pelo WordPress é respeitado');
update_option('ederp_schema_error','Migração pendente');checkA($avatar->filter($default,$user)===$default,'erro de migração preserva o avatar nativo');update_option('ederp_schema_error',false);
update_option('ederp_schema_version','4');checkA($avatar->filter($default,$user)===$default,'schema desatualizado não consulta foto');update_option('ederp_schema_version',Installer::VERSION);
try{$cat->updatePerson((int)$p['id'],['foto_attachment_id'=>99],keyA());throw new RuntimeException('invalid photo accepted');}catch(RuleViolation $e){echo "PASS: foto inválida é rejeitada\n";}
$cat->updatePerson((int)$p['id'],['foto_attachment_id'=>''],keyA());checkA($avatar->filter($default,$user)===$default,'remover foto restaura avatar padrão');
$cat->updatePerson((int)$p['id'],['foto_attachment_id'=>10],keyA());checkA($avatar->filter($default,$user)['url']==='https://test.example/foto.jpg','salvar nova foto reaplica o avatar sem recriar conta');
$db->atomic(fn()=>$db->update('pessoas',(int)$p['id'],['foto_attachment_id'=>99]));checkA($avatar->filter($default,$user)===$default,'imagem removida da biblioteca não quebra o avatar WordPress');
