<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\WordPress\AdmissionPhoto;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Domain\RuleViolation;
$root=sys_get_temp_dir().'/erp-admission-media-test';@mkdir($root.'/wp-admin/includes',0700,true);file_put_contents($root.'/wp-admin/includes/image.php','<?php');file_put_contents($root.'/wp-admin/includes/file.php','<?php');define('ABSPATH',$root.'/');
function wp_upload_bits($name,$unused,$bytes){$file=ABSPATH.$name;file_put_contents($file,$bytes);$GLOBALS['photo_paths'][]=$file;return ['file'=>$file,'url'=>'https://test.example/'.$name,'error'=>false];}
function wp_insert_attachment($row,$file,$parent,$error){$GLOBALS['photo_insert']=$row;return 45;}
function wp_generate_attachment_metadata($id,$file){$thumb=dirname($file).'/thumb-'.basename($file);file_put_contents($thumb,'test thumb');$GLOBALS['photo_paths'][]=$thumb;return ['sizes'=>['thumbnail'=>['file'=>basename($thumb)]]];}
function wp_update_attachment_metadata($id,$meta){$GLOBALS['photo_metadata']=$meta;}
function wp_tempnam($name){return tempnam(sys_get_temp_dir(),'erp-photo-');}
function pc($b,$label){if(!$b)throw new RuntimeException($label);echo "PASS: $label\n";}
$db=new Database($wpdb);$bytes=file_get_contents(dirname(__DIR__).'/assets/brand/journey.png');$GLOBALS['photo_paths']=[];
try{$db->atomic(function()use($db,$bytes){$id=AdmissionPhoto::create($db,$bytes,'image/png');pc($id===45,'foto aprovada usa cadastro de mídia WordPress');throw new RuleViolation('Simular rollback');});}catch(RuleViolation $e){}
foreach($GLOBALS['photo_paths'] as $p)pc(!is_file($p),'rollback remove foto e miniaturas físicas');
$GLOBALS['photo_paths']=[];$id=$db->atomic(fn()=>AdmissionPhoto::create($db,$bytes,'image/png'));pc($GLOBALS['photo_insert']['post_mime_type']==='image/png'&&isset($GLOBALS['photo_metadata']['sizes']['thumbnail']),'metadados e miniatura disponíveis para avatar');foreach($GLOBALS['photo_paths'] as $p){pc(is_file($p),'commit preserva mídia aprovada');unlink($p);}
$temp=AdmissionPhoto::forPdf($bytes,'image/png');pc(is_file($temp['path'])&&file_get_contents($temp['path'])===$bytes,'foto privada é preparada para PDF em arquivo temporário');foreach($temp['cleanup'] as $p)unlink($p);
try{AdmissionPhoto::create($db,'<?php echo 1;','image/png');throw new RuntimeException('imagem falsa aceita');}catch(RuleViolation $e){pc(true,'conteúdo executável não vira avatar');}
echo "ADMISSION PHOTO OK\n";
