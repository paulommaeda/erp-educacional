<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Domain\ScholarshipRequestForm;

final class ScholarshipRequestPdf extends SchoolReportPdf
{
    private const MARGIN=15;
    private const WIDTH=180;
    private const LABEL_HEIGHT=3.8;
    private const VALUE_HEIGHT=4.7;
    private const PADDING=2.5;
    private const SECTION_HEIGHT=6.5;
    private array $contactLines;

    private function __construct(private array $request,array $school,?string $logo)
    {
        parent::__construct('P',self::MARGIN,$school,$logo,'Requerimento de bolsa - '.$request['protocolo']);
        $this->AliasNbPages();
        $contacts=[];
        foreach(['telefone'=>'Telefone','email'=>'E-mail','site'=>'Site'] as $key=>$label){
            if(!empty($school[$key]))$contacts[]=$label.' '.$school[$key];
        }
        $this->SetFont('Helvetica','',8);
        $this->contactLines=$this->wrap($contacts?implode('  |  ',$contacts):$school['nome'],143);
        $this->SetAutoPageBreak(false,19+4*count($this->contactLines));
    }

    public static function render(array $request,array $school):string
    {
        return self::withLogo($school,'o requerimento de bolsa',static function(?string $logo)use($request,$school){
            $pdf=new self($request,$school,$logo);
            $pdf->AddPage();
            $pdf->body();
            return $pdf->Output('S');
        });
    }

    public function Header()
    {
        $this->schoolHeader(14);
        $this->SetTextColor(25,56,49);
        $this->SetFont('Helvetica','B',18);
        $this->Cell(122,9,$this->encode('Requerimento de bolsa'));
        $this->SetFont('Helvetica','',9);
        $this->Cell(58,9,$this->encode($this->request['protocolo']),0,1,'R');
        $this->Ln(2);
    }

    public function Footer()
    {
        $this->SetY(-12-4*count($this->contactLines));
        $this->SetDrawColor(181,198,191);
        $this->Line(self::MARGIN,$this->GetY(),self::MARGIN+self::WIDTH,$this->GetY());
        $this->Ln(2.5);
        $this->SetTextColor(64,83,77);
        $this->SetFont('Helvetica','',8);
        $this->Cell(143,4,$this->contactLines[0]);
        $this->SetFont('Helvetica','B',8);
        $this->Cell(37,4,$this->encode('Página '.$this->PageNo().' de {nb}'),0,1,'R');
        $this->SetFont('Helvetica','',8);
        foreach(array_slice($this->contactLines,1) as $line)$this->Cell(143,4,$line,0,1);
    }

    private function body():void
    {
        $request=$this->request;
        $status=['pendente'=>'Pendente','aprovado'=>'Aprovado','rejeitado'=>'Rejeitado'];
        $student=$request['aluno'];
        $this->section('Dados do requerimento',[
            ['Recebido em'=>self::date($request['criado_em']),'Situação'=>$status[$request['situacao']]??$request['situacao']],
            ['Aluno vinculado'=>$student?$student['nome'].' · RA '.$student['ra']:'Aguardando vínculo',
                'Conferência do Comercial'=>$request['conferido_em']?self::date($request['conferido_em']):'Aguardando conferência'],
        ]);
        foreach(ScholarshipRequestForm::steps() as $step){
            $fields=[];
            foreach($step['fields'] as $field){
                $key=$field['key'];
                if($key==='justificativa'||!isset($request['dados'][$key]))continue;
                $value=$request['dados'][$key];
                $fields[$field['adminLabel']??$field['label']]=match($key){
                    'nascimento'=>self::date($value),
                    'percentual'=>self::percent($value),
                    default=>$value,
                };
            }
            if($fields)$this->section($step['title'],array_chunk($fields,$step['title']==='Nível de ensino'?3:2,true));
        }
        $this->textField('Justificativa do pedido',$request['dados']['justificativa']??'');
        if($request['decidido_em']){
            $details=['Decidido em'=>self::date($request['decidido_em'])];
            if($request['percentual_aprovado'])$details['Percentual aprovado']=self::percent($request['percentual_aprovado']);
            $this->section('Decisão',[$details]);
            if($request['observacao'])$this->textField('Mensagem ao solicitante',$request['observacao']);
        }
    }

    private function section(string $title,array $rows):void
    {
        [, $height]=$this->layout($rows[0]);
        if($this->GetY()+self::SECTION_HEIGHT+1.5+$height>$this->PageBreakTrigger)$this->AddPage();
        $this->SetFont('Helvetica','B',10);
        $this->SetFillColor(236,244,240);
        $this->SetTextColor(27,65,54);
        $this->Cell(self::WIDTH,self::SECTION_HEIGHT,$this->encode($title),0,1,'L',true);
        $this->Ln(1.5);
        foreach($rows as $fields){
            [$cells,$height]=$this->layout($fields);
            if($this->GetY()+$height>$this->PageBreakTrigger)$this->AddPage();
            $this->drawRow($cells,$height);
        }
        $this->Ln(2.5);
    }

    private function layout(array $fields):array
    {
        $cells=[];
        $height=0;
        $width=self::WIDTH/count($fields)-2*self::PADDING;
        foreach($fields as $label=>$value){
            $this->SetFont('Helvetica','B',8);
            $labels=$this->wrap($label,$width);
            $this->SetFont('Helvetica','',10);
            $lines=$this->lines($value,$width);
            $height=max($height,$this->rowHeight(count($labels),count($lines)));
            $cells[]=[$labels,$lines];
        }
        return [$cells,$height];
    }

    private function lines(string $text,float $width):array
    {
        $lines=[];
        foreach(preg_split('/\R/u',$text) as $line)array_push($lines,...$this->wrap($line,$width));
        return $lines;
    }

    private function rowHeight(int $labels,int $lines):float
    {
        return 2*self::PADDING+self::LABEL_HEIGHT*$labels+self::VALUE_HEIGHT*$lines;
    }

    private function drawRow(array $cells,float $height):void
    {
        $y=$this->GetY();
        $width=self::WIDTH/count($cells);
        $this->SetDrawColor(192,206,199);
        foreach($cells as $i=>[$labels,$lines]){
            $x=self::MARGIN+$i*$width;
            $this->Rect($x,$y,$width,$height);
            $this->SetXY($x+self::PADDING,$y+self::PADDING);
            $this->SetFont('Helvetica','B',8);
            $this->SetTextColor(59,81,73);
            $this->MultiCell($width-2*self::PADDING,self::LABEL_HEIGHT,implode("\n",$labels),0,'L');
            $this->SetX($x+self::PADDING);
            $this->SetFont('Helvetica','',10);
            $this->SetTextColor(23,37,31);
            $this->MultiCell($width-2*self::PADDING,self::VALUE_HEIGHT,implode("\n",$lines),0,'L');
        }
        $this->SetXY(self::MARGIN,$y+$height);
    }

    private function textField(string $label,string $value):void
    {
        $this->SetFont('Helvetica','',10);
        $lines=$this->lines($value,self::WIDTH-2*self::PADDING);
        while($lines){
            $available=(int)floor(($this->PageBreakTrigger-$this->GetY()-$this->rowHeight(1,0))/self::VALUE_HEIGHT);
            if($available<min(2,count($lines))){
                $this->AddPage();
                continue;
            }
            $part=array_splice($lines,0,$available);
            $this->drawRow([[[$this->encode($label)],$part]],$this->rowHeight(1,count($part)));
            if($lines){
                $this->AddPage();
                if(!str_ends_with($label,' (continuação)'))$label.=' (continuação)';
            }
        }
        $this->Ln(3.5);
    }

    private static function date(string $value):string
    {
        $date=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));
        return strlen($value)===10?$date->format('d/m/Y'):$date->setTimezone(wp_timezone())->format('d/m/Y H:i');
    }

    private static function percent(string $value):string
    {
        return str_replace('.',',',$value).'%';
    }
}
