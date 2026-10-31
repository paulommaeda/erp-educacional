<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\{Store,RuleViolation};
/** Called only inside a reviewed approval. Pending photos remain encrypted. */
final class AdmissionPhoto
{
    public static function create(Store $db,string $bytes,string $mime):int
    {
        $size=@getimagesizefromstring($bytes);if(!$size||$size[0]*$size[1]>20000000||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||$size['mime']!==$mime)throw new RuleViolation('Foto inválida ou muito grande.');
        require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/file.php';
        $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];$saved=wp_upload_bits('aluno-'.bin2hex(random_bytes(12)).'.'.$ext,null,$bytes);if(!empty($saved['error']))throw new RuleViolation('Não foi possível cadastrar a foto do aluno.');
        $paths=[$saved['file']];$db->onCompletion(static function($ok)use(&$paths){if(!$ok)foreach($paths as $p)if(is_file($p))@unlink($p);});
        $id=wp_insert_attachment(['post_mime_type'=>$mime,'post_title'=>'Foto do aluno','post_status'=>'inherit'],$saved['file'],0,true);if(is_wp_error($id))throw new RuleViolation('Não foi possível cadastrar a foto do aluno.');
        $meta=wp_generate_attachment_metadata($id,$saved['file']);if(is_array($meta)){foreach($meta['sizes']??[] as $s)$paths[]=dirname($saved['file']).'/'.$s['file'];if(!empty($meta['original_image']))$paths[]=dirname($saved['file']).'/'.$meta['original_image'];wp_update_attachment_metadata($id,$meta);}return (int)$id;
    }
    public static function forPdf(string $bytes,string $mime):array
    {
        $size=@getimagesizefromstring($bytes);if(!$size||$size[0]*$size[1]>20000000||$size['mime']!==$mime)throw new RuleViolation('Foto inválida para o requerimento.');$path=wp_tempnam('erp-requerimento-foto');if(!$path)throw new RuleViolation('Não foi possível preparar a foto.');$cleanup=[$path];
        try{if(file_put_contents($path,$bytes)!==strlen($bytes))throw new RuleViolation('Não foi possível preparar a foto.');@chmod($path,0600);
            if($mime==='image/webp'){$editor=wp_get_image_editor($path);if(is_wp_error($editor))throw new RuleViolation('Não foi possível converter a foto WebP para o PDF.');$cleanup[]=$path.'.png';$saved=$editor->save($path.'.png','image/png');if(is_wp_error($saved)||empty($saved['path']))throw new RuleViolation('Não foi possível converter a foto para o PDF.');$cleanup[]=$saved['path'];$path=$saved['path'];}
            return ['path'=>$path,'cleanup'=>$cleanup];
        }catch(\Throwable $e){foreach($cleanup as $file)if(is_file($file))@unlink($file);throw $e;}
    }

}
