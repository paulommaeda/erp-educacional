<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Application\InstallmentReport;

final class InstallmentReportPdf extends SchoolReportPdf
{
    private const MARGIN=5;
    private const WIDTHS=[23,94,42,36,36,56];
    private const LINE_HEIGHT=4;
    private string $month;

    private function __construct(array $school,?string $logo)
    {
        parent::__construct('L',self::MARGIN,$school,$logo,'Parcelas');
    }

    public static function render(array $sheets,array $school):string
    {
        return self::withLogo($school,'o relatório Parcelas',static function(?string $logo) use($sheets,$school){
            $pdf=new self($school,$logo);
            foreach($sheets as $sheet){
                $pdf->month=$sheet['month'];
                $pdf->AddPage();
                $pdf->body($sheet['rows'],$sheet['totals']);
            }
            return $pdf->Output('S');
        });
    }

    public function Header()
    {
        $this->schoolHeader(13);
        $this->SetFont('Helvetica','B',10);
        $this->Cell(array_sum(self::WIDTHS),7,$this->encode($this->month),0,1);
        [$cells,$height]=$this->layout(InstallmentReport::COLUMNS);
        $this->drawRow($cells,$height,232);
    }

    private function body(array $rows,array $totals):void
    {
        $this->SetFont('Helvetica','B',9);
        $footer=array_map(fn(array $total)=>$this->layout(['',$total['label'],$total['original'],'','',$total['net']]),$totals);
        $footerHeight=array_sum(array_column($footer,1));
        $this->SetFont('Helvetica','',9);
        if(!$rows){
            $this->Cell(array_sum(self::WIDTHS),8,$this->encode('Nenhuma parcela encontrada para os filtros selecionados.'),0,1,'C');
        }
        $lastRow=array_key_last($rows);
        foreach($rows as $index=>$row){
            [$cells,$height]=$this->layout($row);
            $required=$height+($index===$lastRow?$footerHeight:0);
            if($this->GetY()+$required>$this->PageBreakTrigger)$this->AddPage();
            $this->drawRow($cells,$height,$index%2===0?247:255);
        }
        $this->SetFont('Helvetica','B',9);
        foreach($footer as [$cells,$height])$this->drawRow($cells,$height,232);
    }

    private function layout(array $row):array
    {
        $cells=array_map(fn(string $value,int $width)=>$this->wrap($value,$width),$row,self::WIDTHS);
        return [$cells,3+max(array_map('count',$cells))*self::LINE_HEIGHT];
    }

    private function drawRow(array $cells,float $height,int $fill):void
    {
        $x=self::MARGIN;
        $y=$this->GetY();
        $this->SetFillColor($fill);
        foreach($cells as $column=>$lines){
            $width=self::WIDTHS[$column];
            $this->Rect($x,$y,$width,$height,'F');
            $this->SetXY($x,$y+1.5);
            $this->MultiCell($width,self::LINE_HEIGHT,implode("\n",$lines),0,$column>=2?'R':'L');
            $x+=$width;
        }
        $this->SetXY(self::MARGIN,$y+$height);
    }
}
