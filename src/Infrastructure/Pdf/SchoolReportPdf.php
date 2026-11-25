<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Vendor\Fpdf\FPDF;

require_once dirname(__DIR__,3).'/lib/fpdf/fpdf.php';

abstract class SchoolReportPdf extends FPDF
{
    protected function __construct(string $orientation,protected float $margin,private array $school,private ?string $logo,string $title)
    {
        parent::__construct($orientation,'mm','A4');
        $this->fontpath=dirname(__DIR__,3).'/lib/fpdf/font/';
        $this->SetMargins($margin,$margin,$margin);
        $this->SetAutoPageBreak(false,$margin);
        $this->SetTitle($title,true);
        $this->SetAuthor($school['nome'],true);
        $this->SetCreator('ERP Educacional');
    }

    protected static function withLogo(array $school,string $document,callable $render):string
    {
        $logo=ReceiptLogo::load($school,$document);
        try{
            return $render($logo['path']);
        }finally{
            foreach($logo['cleanup'] as $path)@unlink($path);
        }
    }

    protected function schoolHeader(float $maxHeight):void
    {
        $width=$this->GetPageWidth()-2*$this->margin;
        $this->SetTextColor(25,25,25);
        if($this->logo){
            [$w,$h,$type]=getimagesize($this->logo);
            $scale=min(70/$w,$maxHeight/$h);
            $this->Image($this->logo,($this->GetPageWidth()-$w*$scale)/2,$this->margin,$w*$scale,$h*$scale,$type===IMAGETYPE_PNG?'PNG':'JPG');
            $y=$this->margin+$h*$scale+5;
        }else{
            $this->SetFont('Helvetica','B',13);
            $this->MultiCell($width,6,$this->encode($this->school['nome']),0,'C');
            $y=$this->GetY()+4;
        }
        $this->SetDrawColor(89,89,89);
        $this->Line($this->margin,$y,$this->margin+$width,$y);
        $this->SetXY($this->margin,$y+3);
    }

    protected function wrap(string $value,float $width):array
    {
        $text=$this->encode($value);
        $width-=2*$this->cMargin;
        $lines=[];
        while($this->GetStringWidth($text)>$width){
            $length=1;
            while($this->GetStringWidth(substr($text,0,$length+1))<=$width)$length++;
            $space=strrpos(substr($text,0,$length+1),' ');
            if($space>0)$length=$space;
            $lines[]=substr($text,0,$length);
            $text=ltrim(substr($text,$length));
        }
        $lines[]=$text;
        return $lines;
    }

    protected function encode(string $value):string
    {
        $value=preg_replace('/[\x00-\x20]+/u',' ',trim($value))??'';
        return mb_convert_encoding($value,'Windows-1252','UTF-8');
    }
}
