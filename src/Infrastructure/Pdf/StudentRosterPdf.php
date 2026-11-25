<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Application\StudentRoster;

final class StudentRosterPdf extends SchoolReportPdf
{
    private const MARGIN=5;
    private const WIDTHS=[12,27,27,14,12,24,24,18,24,24,18,22,18,23];
    private const LINE_HEIGHT=3;
    private float $bodyStart;

    private function __construct(array $school,?string $logo)
    {
        parent::__construct('L',self::MARGIN,$school,$logo,'Alunos');
    }

    public static function render(array $students,array $school):string
    {
        return self::withLogo($school,'o relatório Alunos',static function(?string $logo) use($students,$school){
            $pdf=new self($school,$logo);
            $pdf->AddPage();
            $pdf->body($students);
            return $pdf->Output('S');
        });
    }

    public function Header()
    {
        $this->schoolHeader(13);
        $this->SetFont('Helvetica','B',8);
        [$cells,$height]=$this->layout(StudentRoster::COLUMNS);
        $this->drawRow($cells,$height,232);
        $this->bodyStart=$this->GetY();
    }

    private function body(array $students):void
    {
        $this->SetFont('Helvetica','',7);
        if(!$students){
            $this->Cell(array_sum(self::WIDTHS),8,$this->encode('Nenhum aluno encontrado para os filtros selecionados.'),0,1,'C');
            return;
        }
        foreach($students as $index=>$student){
            $lines=array_map(fn(array $row)=>$this->layout($row),$student);
            $height=array_sum(array_column($lines,1));
            if($this->GetY()+$height>$this->PageBreakTrigger&&$height<=$this->PageBreakTrigger-$this->bodyStart){
                $this->AddPage();
            }
            foreach($lines as [$cells,$lineHeight]){
                if($this->GetY()+$lineHeight>$this->PageBreakTrigger)$this->AddPage();
                $this->drawRow($cells,$lineHeight,$index%2===0?247:255);
            }
        }
    }

    private function layout(array $row):array
    {
        $cells=array_map(fn(string $value,int $width)=>$this->wrap($value,$width),$row,self::WIDTHS);
        return [$cells,2+max(array_map('count',$cells))*self::LINE_HEIGHT];
    }

    private function drawRow(array $cells,float $height,int $fill):void
    {
        $x=self::MARGIN;
        $y=$this->GetY();
        $this->SetFillColor($fill);
        foreach($cells as $column=>$lines){
            $width=self::WIDTHS[$column];
            $this->Rect($x,$y,$width,$height,'F');
            $this->SetXY($x,$y+1);
            $this->MultiCell($width,self::LINE_HEIGHT,implode("\n",$lines),0,'L');
            $x+=$width;
        }
        $this->SetXY(self::MARGIN,$y+$height);
    }
}
