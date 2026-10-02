<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Avatar;
use EducacionalERP\Application\{CatalogService,Operations};
use EducacionalERP\Domain\RuleViolation;
$db=new Database($wpdb);$cat=new CatalogService($db,new Operations($db));$avatar=new Avatar($db);update_option('ederp_schema_version','4');
function keyA():string{return bin2hex(random_bytes(16));}
function checkA(bool $x,string $msg):void{if(!$x)throw new RuntimeException($msg);echo "PASS: $msg\n";}
$p=$cat->create('pessoas',['nome'=>'Pessoa Foto','data_nascimento'=>'1990-01-01','foto_attachment_id'=>10],keyA());
checkA($avatar->filter(['url'=>'gravatar'],(int)$p['wp_user_id'])['url']==='https://test.example/foto.jpg','foto da pessoa substitui URL do avatar WordPress');
try{$cat->updatePerson((int)$p['id'],['foto_attachment_id'=>99],keyA());throw new RuntimeException('invalid photo accepted');}catch(RuleViolation $e){echo "PASS: foto inválida é rejeitada\n";}
$cat->updatePerson((int)$p['id'],['foto_attachment_id'=>''],keyA());checkA($avatar->filter(['url'=>'gravatar'],(int)$p['wp_user_id'])['url']==='gravatar','remover foto restaura avatar padrão');
