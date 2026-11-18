<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;

/** Contract formatting only. No WordPress shortcode, script, stylesheet or external resource runs. */
final class ContractMarkup
{
    private const TAGS=['p','div','span','br','hr','strong','b','em','i','u','s','strike','sub','sup','h1','h2','h3','h4','h5','h6','blockquote','pre','ul','ol','li','table','caption','thead','tbody','tfoot','tr','th','td','a'];
    private const DROP=['script','style','iframe','object','embed','form','input','textarea','button','select','option','svg','math','audio','video','img','link','meta','base','template','noscript'];

    public static function prepare(mixed $source,mixed $format='auto'):array
    {
        if(!is_string($source)||trim($source)===''||strlen($source)>40000||!preg_match('//u',$source))throw new RuleViolation('Informe o texto do contrato com até 40.000 bytes em UTF-8.');
        if(!in_array($format,['auto','texto','html'],true))throw new RuleViolation('Formato do contrato inválido.');
        if($format==='auto')$format=preg_match('~<\s*/?\s*[a-z][^>]*>~i',$source)?'html':'texto';
        $text=$format==='html'?self::html($source):trim($source);
        if(trim(html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8'))==='')throw new RuleViolation('O contrato precisa ter conteúdo de texto.');
        return ['texto'=>$text,'formato_texto'=>$format];
    }

    public static function escape(string $value):string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');}

    public static function html(string $source):string
    {
        if(strlen($source)>400000)throw new RuleViolation('Conteúdo do contrato muito extenso.');
        if(!class_exists(\DOMDocument::class))throw new RuleViolation('Habilite a extensão DOM do PHP para formatar contratos em HTML.');
        $doc=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
        try{$ok=$doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$source.'</body></html>',LIBXML_NONET|LIBXML_COMPACT);}
        finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        if(!$ok)throw new RuleViolation('Não foi possível ler o HTML do contrato.');
        $body=$doc->getElementsByTagName('body')->item(0);$count=0;$out='';
        if($body)foreach($body->childNodes as $node)$out.=self::node($node,0,$count);
        return trim($out);
    }

    private static function node(\DOMNode $node,int $depth,int &$count):string
    {
        if(++$count>12000||$depth>48)throw new RuleViolation('Simplifique a estrutura HTML do contrato.');
        if($node instanceof \DOMText)return self::escape($node->nodeValue??'');
        if(!$node instanceof \DOMElement)return '';
        $tag=strtolower($node->tagName);if(in_array($tag,self::DROP,true))return '';
        $inner='';foreach($node->childNodes as $child)$inner.=self::node($child,$depth+1,$count);
        if(!in_array($tag,self::TAGS,true))return $inner;
        $attrs='';$style=self::style($node->getAttribute('style'));
        $align=strtolower($node->getAttribute('align'));if(in_array($align,['left','center','right','justify'],true))$style.='text-align:'.$align.';';
        if($style!=='')$attrs.=' style="'.self::escape($style).'"';
        if(in_array($tag,['td','th'],true))foreach(['colspan','rowspan'] as $key){$v=$node->getAttribute($key);if(preg_match('/^[1-9][0-9]?$/D',$v))$attrs.=' '.$key.'="'.$v.'"';}
        if(in_array($tag,['table','td','th'],true)){
            $v=$node->getAttribute('width');if(preg_match('/^(?:[1-9][0-9]{0,2}|100%|[1-9][0-9]?%)$/D',$v))$attrs.=' width="'.$v.'"';
            foreach(['border','cellpadding','cellspacing'] as $key){$v=$node->getAttribute($key);if($tag==='table'&&preg_match('/^[0-9]{1,2}$/D',$v))$attrs.=' '.$key.'="'.$v.'"';}
        }
        if($tag==='ol'){$v=$node->getAttribute('start');if(preg_match('/^-?[0-9]{1,4}$/D',$v))$attrs.=' start="'.$v.'"';$v=$node->getAttribute('type');if(in_array($v,['1','a','A','i','I'],true))$attrs.=' type="'.$v.'"';}
        if($tag==='a'){
            $href=trim($node->getAttribute('href'));if(preg_match('~^(?:https?://|mailto:|tel:)[^\s<>"\x00-\x1f]+$~iu',$href))$attrs.=' href="'.self::escape($href).'"';
            if($node->hasAttribute('title'))$attrs.=' title="'.self::escape($node->getAttribute('title')).'"';
        }
        return '<'.$tag.$attrs.'>'.(in_array($tag,['br','hr'],true)?'':$inner.'</'.$tag.'>');
    }

    /** A small, identical formatting vocabulary for the screen and PDF. Resource URLs are never accepted. */
    public static function style(string $source):string
    {
        $out='';foreach(explode(';',$source) as $rule){
            $pair=explode(':',$rule,2);if(count($pair)!==2)continue;[$key,$value]=array_map('trim',$pair);$key=strtolower($key);$lower=strtolower($value);
            if($value===''||strlen($value)>150||preg_match('/[\\\\@<>!\x00-\x1f]/',$value))continue;
            $valid=match($key){
                'text-align'=>in_array($lower,['left','right','center','justify'],true),
                'font-weight'=>in_array($lower,['normal','bold','400','500','600','700','800','900'],true),
                'font-style'=>in_array($lower,['normal','italic','oblique'],true),
                'text-decoration'=>in_array($lower,['none','underline','line-through','overline'],true),
                'font-family'=>(bool)preg_match('/^(?:[\'\"]?(?:times new roman|times|arial|helvetica|georgia|dejavu sans|dejavu serif|serif|sans-serif|monospace)[\'\"]?\s*,?\s*)+$/iD',$value),
                'font-size','line-height','text-indent','margin','margin-top','margin-right','margin-bottom','margin-left','padding','padding-top','padding-right','padding-bottom','padding-left','width','max-width','min-width','height','border-width','border-top-width','border-bottom-width'=>(bool)preg_match('/^(?:0|[0-9]{1,3}(?:\.[0-9]{1,2})?(?:pt|px|mm|cm|em|%))(?:\s+(?:0|[0-9]{1,3}(?:\.[0-9]{1,2})?(?:pt|px|mm|cm|em|%))){0,3}$/D',$value)||($key==='line-height'&&(bool)preg_match('/^[1-3](?:\.[0-9]{1,2})?$/D',$value)),
                'color','background-color','border-color'=>(bool)preg_match('/^(?:#[0-9a-f]{3}(?:[0-9a-f]{3})?|black|white|gray|grey|red|green|blue|yellow|transparent)$/iD',$value),
                'border','border-top','border-right','border-bottom','border-left'=>(bool)preg_match('/^(?:0|[0-9]{1,2}(?:\.[0-9])?(?:pt|px))\s+(?:solid|dotted|dashed|double)\s+(?:#[0-9a-f]{3}(?:[0-9a-f]{3})?|black|white|gray|grey)$/iD',$value),
                'border-style'=>in_array($lower,['none','solid','dotted','dashed','double'],true),
                'border-collapse'=>in_array($lower,['collapse','separate'],true),
                'vertical-align'=>in_array($lower,['top','middle','bottom','baseline'],true),
                'list-style-type'=>in_array($lower,['disc','circle','square','decimal','lower-alpha','upper-alpha','lower-roman','upper-roman','none'],true),
                'page-break-before','page-break-after'=>in_array($lower,['auto','always','avoid'],true),
                'page-break-inside'=>in_array($lower,['auto','avoid'],true),
                default=>false
            };
            if($valid)$out.=$key.':'.$value.';';
        }return $out;
    }
}
