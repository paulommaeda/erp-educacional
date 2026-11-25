<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

final class EnrollmentSignaturesPdf extends SchoolReportPdf
{
    private const MARGIN=12;
    private const WIDTH=186;
    private const RA=33;
    private const NAME=91;

    private array $report;

    private function __construct(array $school,?string $logo)
    {
        parent::__construct('P',self::MARGIN,$school,$logo,'Lista de assinaturas');
    }

    public static function render(array $reports,array $school):string
    {
        return self::withLogo($school,'a lista de assinaturas',static function(?string $logo) use($reports,$school){
            $pdf=new self($school,$logo);
            foreach($reports as $report){
                $pdf->report=$report;
                $pdf->AddPage();
                $pdf->body();
            }
            return $pdf->Output('S');
        });
    }

    public function Header()
    {
        $this->schoolHeader(20);
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

}
