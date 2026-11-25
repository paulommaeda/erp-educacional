<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Application\AnnualClearance;

final class AnnualClearancePdf extends SchoolReportPdf
{
    private const MARGIN = 12;
    private const WIDTHS = [58, 14, 22, 22, 22, 20, 28];
    private const ALIGNMENTS = ['L', 'C', 'C', 'R', 'R', 'C', 'C'];
    private const LINE_HEIGHT = 4;
    private array $contactLines = [];
    private float $footerHeight;

    private function __construct(private array $data, ?string $logo)
    {
        parent::__construct('P', self::MARGIN, $data['school'], $logo, AnnualClearance::TITLE);
        $this->AliasNbPages();
        $this->SetFont('Helvetica', '', 8);
        foreach ($data['footer'] as $line) array_push($this->contactLines, ...$this->wrap($line, array_sum(self::WIDTHS)));
        $this->footerHeight = 10 + count($this->contactLines)*3.6;
        $this->SetAutoPageBreak(false, $this->footerHeight + 10);
    }

    public static function render(array $data): string
    {
        return self::withLogo($data['school'], 'a declaração de quitação', static function (?string $logo) use ($data) {
            $pdf = new self($data, $logo);
            $pdf->AddPage();
            $pdf->body();
            return $pdf->Output('S');
        });
    }

    public function Header()
    {
        $this->schoolHeader(18);
        $width = array_sum(self::WIDTHS);
        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell($width, 9, $this->encode(mb_strtoupper(AnnualClearance::TITLE, 'UTF-8')), 0, 1, 'C');
        if ($this->PageNo() === 1) {
            $this->SetFont('Helvetica', '', 10);
            $this->MultiCell($width, 5, $this->encode($this->data['statement']), 0, 'L');
            $this->Ln(4);
        }
        $this->SetFont('Helvetica', 'B', 8);
        [$cells, $height] = $this->layout(AnnualClearance::COLUMNS);
        $this->row($cells, $height, 232);
    }

    public function Footer()
    {
        $this->SetY(-$this->footerHeight-5);
        $width = array_sum(self::WIDTHS);
        $this->SetDrawColor(89, 89, 89);
        $this->Line(self::MARGIN, $this->GetY(), self::MARGIN+$width, $this->GetY());
        $this->SetFont('Helvetica', '', 8);
        $this->Cell($width, 5, $this->encode('Página '.$this->PageNo().' de').' {nb}', 0, 1, 'R');
        foreach ($this->contactLines as $line) $this->Cell($width, 3.6, $line, 0, 1, 'C');
    }

    private function body(): void
    {
        $width = array_sum(self::WIDTHS);
        $this->SetFont('Helvetica', '', 10);
        $notice = $this->wrap(AnnualClearance::NOTICE, $width);
        $date = $this->wrap($this->data['issue_date'], $width);
        $closingHeight = 38 + 5*(count($notice)+count($date));
        $this->SetFont('Helvetica', '', 8);
        $last = array_key_last($this->data['rows']);
        foreach ($this->data['rows'] as $index => $row) {
            [$cells, $height] = $this->layout($row);
            if ($this->GetY()+$height+($index === $last ? $closingHeight : 0) > $this->PageBreakTrigger) $this->AddPage();
            $this->row($cells, $height, $index%2 === 0 ? 247 : 255);
        }
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(232);
        $this->Cell($width, 8, $this->encode('Valor total pago  '.$this->data['total']), 0, 1, 'R', true);
        $this->Ln(6);
        $this->SetFont('Helvetica', '', 10);
        $this->MultiCell($width, 5, implode("\n", $notice), 0, 'L');
        $this->Ln(8);
        $this->MultiCell($width, 5, implode("\n", $date), 0, 'L');
        $this->Ln(13);
        $this->Line(($this->GetPageWidth()-72)/2, $this->GetY(), ($this->GetPageWidth()+72)/2, $this->GetY());
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell($width, 6, $this->encode('DEPARTAMENTO FINANCEIRO'), 0, 1, 'C');
    }

    private function layout(array $row): array
    {
        $cells = array_map(fn (string $value, int $width) => $this->wrap($value, $width), $row, self::WIDTHS);
        return [$cells, 3+max(array_map('count', $cells))*self::LINE_HEIGHT];
    }

    private function row(array $cells, int $height, int $fill): void
    {
        $x = self::MARGIN;
        $y = $this->GetY();
        $this->SetFillColor($fill);
        foreach ($cells as $column => $lines) {
            $width = self::WIDTHS[$column];
            $this->Rect($x, $y, $width, $height, 'F');
            $this->SetXY($x, $y+1.5);
            $this->MultiCell($width, self::LINE_HEIGHT, implode("\n", $lines), 0, self::ALIGNMENTS[$column]);
            $x += $width;
        }
        $this->SetXY(self::MARGIN, $y+$height);
    }
}
