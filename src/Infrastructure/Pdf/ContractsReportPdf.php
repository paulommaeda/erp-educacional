<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Application\ContractFinancialSummary;

final class ContractsReportPdf extends SchoolReportPdf
{
    private const MARGIN=5;
    private const WIDTHS=[82,43,57,44,43,18];
    private const LINE_HEIGHT=4;

    private function __construct(array $school,?string $logo)
    {
        parent::__construct('L',self::MARGIN,$school,$logo,'Contratos');
        $this->SetAutoPageBreak(false,10);
    }

    public static function render(array $contracts,array $school):string
    {
        return self::withLogo($school,'o relatório Contratos',static function(?string $logo)use($contracts,$school){
            $pdf=new self($school,$logo);
            $pdf->AddPage();
            $pdf->body($contracts);
            return $pdf->Output('S');
        });
    }

    public function Header()
    {
        $this->schoolHeader(13);
        $this->SetFont('Helvetica','B',11);
        $this->Cell(array_sum(self::WIDTHS),7,'Contratos',0,1);
        $this->SetFont('Helvetica','B',8.5);
        $fields=array_map(static fn(string $column)=>[$column],ContractFinancialSummary::COLUMNS);
        [$cells,$height]=$this->layout($fields);
        $this->drawRow($cells,$height,232,null);
    }

    private function body(array $contracts):void
    {
        $this->SetFont('Helvetica','',8.5);
        if(!$contracts)$this->Cell(array_sum(self::WIDTHS),8,$this->encode('Nenhum contrato encontrado para os filtros selecionados.'),0,1,'C');
        foreach($contracts as $index=>$contract){
            $fields=ContractFinancialSummary::reportRow($contract);
            $signed=$fields[5][0]==='Sim';
            $fields[5]=[''];
            [$cells,$height]=$this->layout($fields);
            if($this->GetY()+$height>$this->PageBreakTrigger)$this->AddPage();
            $this->drawRow($cells,$height,$index%2===0?247:255,$signed);
        }
    }

    private function layout(array $fields):array
    {
        $cells=[];
        foreach($fields as $column=>$lines){
            $wrapped=[];
            foreach($lines as $line)array_push($wrapped,...$this->wrap((string)$line,self::WIDTHS[$column]));
            $cells[]=$wrapped;
        }
        return [$cells,3+max(array_map('count',$cells))*self::LINE_HEIGHT];
    }

    private function drawRow(array $cells,int $height,int $fill,?bool $signed):void
    {
        $x=self::MARGIN;
        $y=$this->GetY();
        $this->SetFillColor($fill);
        foreach($cells as $column=>$lines){
            $width=self::WIDTHS[$column];
            $this->Rect($x,$y,$width,$height,'F');
            if($column===5&&$signed!==null){
                $left=$x+($width-3.5)/2;
                $top=$y+($height-3.5)/2;
                $this->Rect($left,$top,3.5,3.5);
                if($signed){
                    $this->Line($left+.6,$top+1.7,$left+1.4,$top+2.6);
                    $this->Line($left+1.4,$top+2.6,$left+2.9,$top+.8);
                }
            }else{
                $this->SetXY($x,$y+1.5);
                $this->MultiCell($width,self::LINE_HEIGHT,implode("\n",$lines),0,$column===5?'C':'L');
            }
            $x+=$width;
        }
        $this->SetXY(self::MARGIN,$y+$height);
    }
}
