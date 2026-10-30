<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;
use EducacionalERP\Domain\RuleViolation;

/** Local WordPress media only. Generated conversions contain a public logo, never receipt data. */
final class ReceiptLogo
{
    public static function load(array $school):array
    {
        $path=!empty($school['logo_id'])?(string)get_attached_file((int)$school['logo_id']):(!empty($school['logo_padrao'])?dirname(__DIR__,3).'/assets/brand/journey.png':'');
        if($path==='')return ['path'=>null,'cleanup'=>[]];
        $path=realpath($path);
        if(!$path||!is_file($path)||!is_readable($path)||filesize($path)>10485760)throw new RuleViolation('A logo configurada não está disponível para o recibo. Confira a imagem nas configurações do colégio.');
        $size=@getimagesize($path);
        if(!$size||$size[0]>10000||$size[1]>10000||$size[0]*$size[1]>20000000)throw new RuleViolation('A logo configurada não é uma imagem válida para o recibo.');
        if(in_array($size['mime'],['image/png','image/jpeg'],true))return ['path'=>$path,'cleanup'=>[]];
        if(!in_array($size['mime'],['image/gif','image/webp'],true)||!function_exists('wp_get_image_editor'))throw new RuleViolation('Use uma logo PNG ou JPG, ou habilite o editor de imagens do WordPress para converter a logo.');
        $editor=wp_get_image_editor($path);
        if(is_wp_error($editor))throw new RuleViolation('Não foi possível preparar a logo para o recibo. Selecione uma imagem PNG ou JPG.');
        $resize=$editor->resize(1000,500,false);
        if(is_wp_error($resize))throw new RuleViolation('Não foi possível redimensionar a logo para o recibo.');
        $temp=wp_tempnam('erp-recibo-logo.png');
        if(!$temp)throw new RuleViolation('Não foi possível preparar a logo para o recibo.');
        $saved=$editor->save($temp,'image/png');
        if(is_wp_error($saved)||empty($saved['path'])){@unlink($temp);throw new RuleViolation('Não foi possível converter a logo para o recibo.');}
        return ['path'=>$saved['path'],'cleanup'=>array_unique([$temp,$saved['path']])];
    }
}
