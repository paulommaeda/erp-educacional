<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Vendor\Fpdf\FPDF;

require_once dirname(__DIR__,3).'/lib/fpdf/fpdf.php';

final class EnrollmentSignaturesPdf extends FPDF
{
    private const MARGIN=12;
    private const WIDTH=186;
    private const RA=33;
    private const NAME=91;

    private function __construct(private array $report,private string $school,private ?string $logo)
    {
        parent::__construct('P','mm','A4');
        $this->fontpath=dirname(__DIR__,3).'/lib/fpdf/font/';
        $this->SetMargins(self::MARGIN,self::MARGIN,self::MARGIN);
        $this->SetAutoPageBreak(false,self::MARGIN);
        $this->SetTitle('Lista de assinaturas',true);
        $this->SetAuthor($school,true);
        $this->SetCreator('ERP Educacional');
    }

    public static function render(array $report,array $school):string
    {
        $logo=ReceiptLogo::load($school,'a lista de assinaturas');
        try{
            $pdf=new self($report,(string)$school['nome'],$logo['path']);
            $pdf->AddPage();
            $pdf->body();
            return $pdf->Output('S');
        }finally{
            foreach($logo['cleanup'] as $path)@unlink($path);
        }
    }

    public function Header()
    {
        $this->SetTextColor(25,25,25);
        if($this->logo){
            [$w,$h,$type]=getimagesize($this->logo);
            $scale=min(70/$w,20/$h);
            $this->Image($this->logo,(210-$w*$scale)/2,self::MARGIN,$w*$scale,$h*$scale,$type===IMAGETYPE_PNG?'PNG':'JPG');
            $y=self::MARGIN+$h*$scale+5;
        }else{
            $this->SetFont('Helvetica','B',13);
            $this->MultiCell(self::WIDTH,6,$this->encode($this->school),0,'C');
            $y=$this->GetY()+4;
        }
        $this->SetDrawColor(89,89,89);
        $this->Line(self::MARGIN,$y,self::MARGIN+self::WIDTH,$y);
        $this->SetXY(self::MARGIN,$y+3);
        $width=self::WIDTH/3;
        $this->SetFont('Helvetica','B',9);
        $this->Cell($width,5,$this->encode('Período Letivo'));
        $this->Cell($width,5,'Turma',0,0,'C');
        $this->Cell($width,5,'Data',0,1,'R');
        $this->SetFont('Helvetica','',10);
        $y=$this->GetY();
        $this->MultiCell($width,4.5,$this->encode($this->report['periodo']),0,'L');
        $periodEnd=$this->GetY();
        $this->SetXY(self::MARGIN+$width,$y);
        $this->MultiCell($width,4.5,$this->encode($this->report['turma']),0,'C');
        $classEnd=$this->GetY();
        $this->SetXY(self::MARGIN+2*$width,$y);
        $this->Cell($width,4.5,'____ / ____ / ________',0,0,'R');
        $this->SetXY(self::MARGIN,max($periodEnd,$classEnd)+4);
        $this->SetFont('Helvetica','B',10);
        $this->SetFillColor(232,232,232);
        $this->Cell(self::RA,8,'RA',0,0,'L',true);
        $this->Cell(self::NAME,8,'Nome',0,0,'L',true);
        $this->Cell(self::WIDTH-self::RA-self::NAME,8,'',0,1,'L',true);
    }

    private function body():void
    {
        $this->SetFont('Helvetica','',10);
        if(!$this->report['alunos']){
            $this->Cell(self::WIDTH,12,$this->encode('Nenhum aluno nesta turma.'),0,1,'C');
            return;
        }

        foreach($this->report['alunos'] as $index=>$student){
            $ra=$this->wrap($student['ra'],self::RA);
            $name=$this->wrap($student['nome'],self::NAME);
            $height=max(10,4.5*max(count($ra),count($name))+4);
            if($this->GetY()+$height>$this->PageBreakTrigger)$this->AddPage();

            $y=$this->GetY();
            $this->SetFillColor($index%2===0?247:255);
            $this->Rect(self::MARGIN,$y,self::WIDTH,$height,'F');
            $this->SetXY(self::MARGIN,$y+($height-4.5*count($ra))/2);
            $this->MultiCell(self::RA,4.5,implode("\n",$ra),0,'L');
            $this->SetXY(self::MARGIN+self::RA,$y+($height-4.5*count($name))/2);
            $this->MultiCell(self::NAME,4.5,implode("\n",$name),0,'L');
            $this->Line(self::MARGIN+self::RA+self::NAME+2,$y+$height-2.5,self::MARGIN+self::WIDTH-2,$y+$height-2.5);
            $this->SetXY(self::MARGIN,$y+$height);
        }
    }

    private function wrap(string $value,float $width):array
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

    private function encode(string $value):string
    {
        $value=preg_replace('/[\x00-\x20]+/u',' ',trim($value))??'';
        return mb_convert_encoding($value,'Windows-1252','UTF-8');
    }
}
