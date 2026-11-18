<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Domain\{ContractMarkup,RuleViolation};
use EducacionalERP\Vendor\Dompdf\{Dompdf,Options,FontMetrics};

/** A4 document with the reference layout: first-page logo and a numbered footer on every page. */
final class ContractPdf
{
    public static function render(array $contract,array $school):string
    {
        if(!extension_loaded('dom')||!extension_loaded('mbstring'))throw new RuleViolation('Habilite as extensões DOM e MBString do PHP para imprimir contratos em PDF.');
        require_once dirname(__DIR__,3).'/lib/dompdf/autoload.inc.php';
        $root=function_exists('get_temp_dir')?get_temp_dir():sys_get_temp_dir();$temp=rtrim($root,'/\\').'/ederp-contract-'.bin2hex(random_bytes(12));
        if(!@mkdir($temp,0700))throw new RuleViolation('O WordPress precisa de uma pasta temporária gravável para preparar a impressão do contrato.');
        $logo=['path'=>null,'cleanup'=>[]];
        try{
            $logo=ReceiptLogo::load($school,'o contrato');$image='';
            if($logo['path']){
                $mime=getimagesize($logo['path'])['mime'];if($mime==='image/png'&&!extension_loaded('gd'))throw new RuleViolation('Habilite a extensão GD do PHP para imprimir a logo PNG no contrato.');
                $image='data:'.$mime.';base64,'.base64_encode((string)file_get_contents($logo['path']));
            }
            $options=new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false,'isFontSubsettingEnabled'=>true,'defaultFont'=>'Times-Roman','defaultPaperSize'=>'a4','defaultMediaType'=>'print','tempDir'=>$temp,'fontCache'=>$temp,'logOutputFile'=>null]);
            $options->setChroot([dirname(__DIR__,3).'/lib/dompdf/vendor/dompdf/dompdf']);$options->setAllowedProtocols(['data://','file://']);
            $pdf=new Dompdf($options);$pdf->setPaper('A4','portrait');$metrics=$pdf->getFontMetrics();$font=$metrics->getFont('Helvetica');
            $footer=[];foreach(self::footer($school) as $line)array_push($footer,...self::wrap($line,$metrics,$font,8,566.9));
            // Reserve enough body space for the full configured footer, even when an address wraps.
            $footerHeight=25+count($footer)*11;$bottom=$footerHeight+11;
            $pdf->loadHtml(self::document($contract,$school,$image,$bottom),'UTF-8');$pdf->render();
            $number=(string)($contract['assinatura_numero_snapshot']?:$contract['numero']);
            $pdf->addInfo('Title','Contrato '.$number);$pdf->addInfo('Author',(string)($school['nome']??''));$pdf->addInfo('Creator','ERP Educacional');
            $pdf->getCanvas()->page_script(static function(int $page,int $total,$canvas,FontMetrics $fm)use($footer,$footerHeight,$font){
                $width=$canvas->get_width();$height=$canvas->get_height();$left=14.17;$right=$width-$left;$top=$height-$footerHeight;
                $label='Página: '.$page.' de '.$total;$labelWidth=$fm->getTextWidth($label,$font,8);$x=$right-$labelWidth;
                $canvas->line($x-7,$top-1,$x-7,$top+10,[0,0,0],.4);$canvas->text($x,$top,$label,$font,8);
                $canvas->line($left,$top+14,$right,$top+14,[0,0,0],.5);
                foreach($footer as $i=>$line){$textWidth=$fm->getTextWidth($line,$font,8);$canvas->text(($width-$textWidth)/2,$top+18+$i*11,$line,$font,8);}
            });
            return $pdf->output();
        }finally{
            foreach($logo['cleanup'] as $path)@unlink($path);
            foreach(glob($temp.'/*')?:[] as $path)if(is_file($path))@unlink($path);
            @rmdir($temp);
        }
    }

    public static function footer(array $school):array
    {
        $get=static fn(string $key):string=>trim(strip_tags((string)($school[$key]??'')));
        $street=implode(', ',array_filter([$get('rua'),$get('numero'),$get('complemento')],static fn($v)=>$v!==''));
        $city=implode(' / ',array_filter([$get('cidade'),$get('estado')],static fn($v)=>$v!==''));
        $address=implode(' - ',array_filter([$street,$get('bairro'),$city,$get('cep')!==''?'CEP '.$get('cep'):'',$get('telefone')],static fn($v)=>$v!==''));
        $contacts=implode(' - ',array_filter([$get('site'),$get('email')],static fn($v)=>$v!==''));
        return array_values(array_filter([$address,$contacts],static fn($v)=>$v!==''));
    }

    private static function wrap(string $line,FontMetrics $metrics,string $font,float $size,float $width):array
    {
        $lines=[];$current='';foreach(preg_split('/\s+/u',$line,-1,PREG_SPLIT_NO_EMPTY)?:[] as $word){
            if($current!==''&&$metrics->getTextWidth($current.' '.$word,$font,$size)>$width){$lines[]=$current;$current='';}
            foreach(preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){if($current!==''&&$metrics->getTextWidth($current.$char,$font,$size)>$width){$lines[]=$current;$current='';}$current.=$char;}
            $current.=' ';
        }if(trim($current)!=='')$lines[]=trim($current);return array_map('trim',$lines);
    }

    private static function document(array $contract,array $school,string $image,float $bottom):string
    {
        $body=($contract['formato_texto']??'texto')==='html'?ContractMarkup::html((string)$contract['termo_snapshot']):'<pre class="legacy">'.ContractMarkup::escape((string)$contract['termo_snapshot']).'</pre>';
        $header=$image!==''?'<img src="'.$image.'" alt="Logo do colégio">':'<strong>'.ContractMarkup::escape((string)($school['nome']??'')).'</strong>';
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><style>
            @page{size:A4 portrait;margin:14.17pt 14.17pt '.$bottom.'pt}body{margin:0;color:#111;font-family:"Times New Roman",serif;font-size:9pt;line-height:1.35;word-wrap:break-word}
            .school-header{border-bottom:.5pt solid #111;padding:0 0 4pt;margin:0 0 10pt;font-family:Helvetica,sans-serif;font-size:12pt;line-height:1}
            .school-header img{width:auto;height:auto;max-width:100pt;max-height:26pt}p{margin:0 0 6pt;text-align:justify}h1{font-family:Helvetica,sans-serif;font-size:14pt;text-align:center;margin:8pt 0 12pt}h2{font-size:11pt}h3,h4,h5,h6{font-size:10pt}h1,h2,h3,h4,h5,h6{page-break-after:avoid}table{border-collapse:collapse;width:100%;margin:6pt 0}td,th{padding:4pt;vertical-align:top;word-wrap:break-word}tr{page-break-inside:avoid}thead{display:table-header-group}tfoot{display:table-footer-group}ul,ol{padding-left:20pt}blockquote{margin:6pt 18pt}pre{white-space:pre-wrap;word-wrap:break-word;font-family:inherit;font-size:inherit}.legacy{line-height:1.5;margin:0}a{color:inherit;text-decoration:underline}
            </style></head><body><div class="school-header">'.$header.'</div>'.$body.'</body></html>';
    }
}
