<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Educbank;
/** Safe, bounded diagnostics from documented ABP and validation-problem responses. */
final class ResponseError
{
    public static function message(int $code,string $method,string $path,mixed $data,string $token):string
    {
        $invoice=str_starts_with($path,'/invoice');$resource=$invoice?'fatura':'matrícula';
        $action=match($method){'POST'=>str_ends_with($path,'/cancel')?'cancelar '.$resource:'criar '.$resource,'PUT'=>'atualizar '.$resource,default=>'consultar '.($invoice?'faturas':'matrículas')};
        $parts=[];$root=is_array($data)&&is_array($data['error']??null)?$data['error']:[];
        foreach([$root['validationErrors']??null,is_array($data)?($data['validationErrors']??null):null] as $validation){
            if(!is_array($validation))continue;
            foreach(array_slice($validation,0,5) as $error){if(!is_array($error)||!is_string($error['message']??null))continue;$members=array_slice(array_values(array_filter(is_array($error['members']??null)?$error['members']:[],'is_string')),0,3);$parts[]=($members?implode(', ',$members).': ':'').$error['message'];}
        }
        foreach([$root['errors']??null,is_array($data)?($data['errors']??null):null] as $errors){
            if(!is_array($errors))continue;
            foreach(array_slice($errors,0,5,true) as $field=>$value){$label=is_string($field)?$field.': ':'';
                if(is_string($value))$parts[]=$label.$value;
                elseif(is_array($value)&&is_string($value['message']??null))$parts[]=$label.$value['message'];
                elseif(is_array($value))foreach(array_slice($value,0,2) as $v)if(is_string($v))$parts[]=$label.$v;
            }
        }
        if(!$parts&&is_array($data)){
            foreach([$root['message']??null,$data['message']??null,$data['title']??null,is_string($data['error']??null)?$data['error']:null] as $text)if(is_string($text)&&trim($text)!==''){$parts[]=$text;break;}
            // Only a short textual detail; never store request dumps, stack traces or response data.
            $detail=$root['details']??$data['detail']??null;if(is_string($detail)&&trim($detail)!==''&&!preg_match('/^[\s]*[\[{]/',$detail)){$line=preg_split('/\R/u',$detail)[0]??'';if($line!==''&&!preg_match('/^\s*(at |Stack trace)/i',$line))$parts[]=self::clip($line,200);}
        }
        $parts=array_values(array_unique(array_filter(array_map(fn($p)=>self::clean($p,$token),$parts))));
        $prefix='Educbank HTTP '.$code.' ao '.$action.'. ';
        if(!$parts)$parts[]=match($code){401,403=>'Confira o token, o ambiente e as permissões para esta operação.',400,422=>'A requisição foi recusada; a resposta não informou os campos inválidos.',default=>'O parceiro não retornou detalhes de validação em JSON.'};
        return self::clip($prefix.implode(' | ',$parts),480);
    }
    private static function clean(string $text,string $token):string
    {
        $text=strip_tags(html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $text=preg_replace('/[\x00-\x1F\x7F]+/u',' ',$text)??'';
        if($token!=='')$text=str_replace(array_unique([$token,rawurlencode($token),urlencode($token)]),'[credencial omitida]',$text);
        $text=preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i','Bearer [credencial omitida]',$text)??'';
        $text=preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/','[credencial omitida]',$text)??'';
        $text=preg_replace('/\b(authorization|access[_-]?token|token|api[_-]?key|password|senha|secret)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i','$1: [credencial omitida]',$text)??'';
        $text=preg_replace('/(?<!\d)(?:\d{3}\.\d{3}\.\d{3}-\d{2}|\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\d{11}|\d{14})(?!\d)/u','[documento omitido]',$text)??'';
        $text=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i','[e-mail omitido]',$text)??'';
        return trim(preg_replace('/\s+/u',' ',$text)??'');
    }
    public static function clip(string $text,int $bytes=500):string
    {
        if(strlen($text)<=$bytes)return $text;
        if(function_exists('mb_strcut'))return mb_strcut($text,0,$bytes,'UTF-8');
        $text=substr($text,0,$bytes);while($text!==''&&!preg_match('//u',$text))$text=substr($text,0,-1);return $text;
    }
}
