<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;

use EducacionalERP\Domain\Money;
require_once dirname(__DIR__,3).'/lib/fpdf/fpdf.php';

final class PaymentReceiptPdf extends \EducacionalERP\Vendor\Fpdf\FPDF
{
    private array $receipt;
    private ?string $logo;
    private array $accent;

    private function __construct(array $data,?string $logo)
    {
        parent::__construct('P','mm','A4');
        $this->fontpath=dirname(__DIR__,3).'/lib/fpdf/font/';
        $this->receipt=$data;$this->logo=$logo;
        $hex=ltrim($data['colegio']['cor_primaria'],'#');
        $this->accent=[hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
        $this->SetMargins(18,18,18);$this->SetAutoPageBreak(true,23);$this->AliasNbPages();
        $this->SetTitle('Recibo de pagamento '.$data['numero'],true);
        $this->SetAuthor($data['colegio']['nome'],true);$this->SetCreator('ERP Educacional');
    }

    public static function render(array $data):string
    {
        $logo=ReceiptLogo::load($data['colegio']);
        try{$pdf=new self($data,$logo['path']);$pdf->AddPage();$pdf->body();return $pdf->Output('S');}
        finally{foreach($logo['cleanup'] as $path)@unlink($path);}
    }

    /** Core Helvetica is WinAnsi; Portuguese accents stay searchable, without requiring a server font. */
    private function enc(string $text):string
    {
        $text=str_replace(["\r","\t"],['',' '],strip_tags($text));
        $text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text)??'';
        if(function_exists('iconv')){$value=iconv('UTF-8','Windows-1252//TRANSLIT',$text);if($value!==false)return $value;}
        if(function_exists('mb_convert_encoding'))return mb_convert_encoding($text,'Windows-1252','UTF-8');
        return preg_replace_callback('/[^\x00-\x7F]/u',static function($m){$bytes=array_values(unpack('C*',$m[0]));$code=count($bytes)===2?(($bytes[0]&31)<<6)|($bytes[1]&63):0;return $code>=160&&$code<=255?chr($code):'?';},$text)??'';
    }

    private function font(string $style='',float $size=10):void {$this->SetFont('Helvetica',$style,$size);}
    private function muted():void {$this->SetTextColor(92,108,105);}
    private function ink():void {$this->SetTextColor(30,55,49);}
    private static function money(string $value):string {$c=Money::cents($value);return 'R$ '.($c<0?'-':'').number_format(intdiv(abs($c),100),0,',','.').','.str_pad((string)(abs($c)%100),2,'0',STR_PAD_LEFT);}
    private static function date(string $date):string {return substr($date,8,2).'/'.substr($date,5,2).'/'.substr($date,0,4);}
    private static function document(?string $value):string
    {
        $d=preg_replace('/\D/','',(string)$value);
        return match(strlen($d)){11=>substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9),14=>substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12),default=>(string)$value};
    }

    public function Header()
    {
        $s=$this->receipt['colegio'];$x=18;
        if($this->logo){$size=getimagesize($this->logo);$ratio=min(44/$size[0],22/$size[1]);$this->Image($this->logo,18,17,$size[0]*$ratio,$size[1]*$ratio,$size['mime']==='image/png'?'PNG':'JPG');$x=72;}
        $this->SetXY($x,17);$this->ink();$this->font('B',13);$this->MultiCell(192-$x,6,$this->enc($s['nome']),0,'L');
        $this->font('',8.5);$this->muted();
        if($s['razao_social']!==$s['nome']){$this->SetX($x);$this->MultiCell(192-$x,4.4,$this->enc($s['razao_social']),0,'L');}
        if($s['cnpj']){$this->SetX($x);$this->MultiCell(192-$x,4.4,$this->enc('CNPJ '.self::document($s['cnpj'])),0,'L');}
        $address=implode(', ',array_filter([$s['rua'],$s['numero'],$s['complemento'],$s['bairro']]));
        $city=implode(' - ',array_filter([$s['cidade'],$s['estado'],$s['cep']]));
        foreach(array_filter([$address,$city,implode(' | ',array_filter([$s['telefone'],$s['email']]))]) as $line){$this->SetX($x);$this->MultiCell(192-$x,4.4,$this->enc($line),0,'L');}
        $y=max(44,$this->GetY()+4);$this->SetDrawColor(...$this->accent);$this->SetLineWidth(.6);$this->Line(18,$y,192,$y);$this->SetLineWidth(.2);
        $this->SetXY(18,$y+6);$this->font('B',18);$this->ink();$this->Cell(116,9,$this->enc('Recibo de pagamento'));
        $this->font('',9);$this->Cell(58,9,$this->enc($this->receipt['numero']),0,1,'R');$this->Ln(6);
    }

    public function Footer()
    {
        $this->SetY(-18);$this->muted();$this->font('',8);
        $this->Cell(130,5,$this->enc('Emitido em '.$this->receipt['emitido_em'].' pelo portal do colégio.'));
        $this->Cell(44,5,$this->enc('Página '.$this->PageNo().' de {nb}'),0,0,'R');
    }

    private function heading(string $title):void
    {
        if($this->GetY()+20>$this->PageBreakTrigger)$this->AddPage();
        $this->Ln(2);$this->font('B',11);$this->SetTextColor(...$this->accent);$this->Cell(174,7,$this->enc($title),0,1);$this->ink();
    }

    private function lines(string $text,float $width):array
    {
        $text=$this->enc($text);$out=[];
        foreach(explode("\n",$text) as $line){
            while($this->GetStringWidth($line)>$width){$n=1;while($n<strlen($line)&&$this->GetStringWidth(substr($line,0,$n+1))<=$width)$n++;$space=strrpos(substr($line,0,$n),' ');if($space!==false&&$space>0)$n=$space;$out[]=substr($line,0,$n);$line=ltrim(substr($line,$n));}
            $out[]=$line;
        }
        return $out;
    }

    private function row(array $values,array $widths,bool $header=false):void
    {
        $this->font($header?'B':'',9);$wrapped=[];$count=1;
        foreach($values as $i=>$value){$wrapped[$i]=$this->lines((string)$value,$widths[$i]-5);$count=max($count,count($wrapped[$i]));}
        $height=$count*4.8+5;
        if($this->GetY()+$height>$this->PageBreakTrigger){$this->AddPage();if(!$header)$this->row(['Data','Forma de pagamento','Pagador','Valor pago'],$widths,true);$this->font($header?'B':'',9);}
        $x=18;$y=$this->GetY();$this->SetDrawColor(220,230,226);
        if($header){$this->SetFillColor(238,246,242);$this->Rect(18,$y,174,$height,'F');}
        foreach($values as $i=>$value){$this->SetXY($x+2.5,$y+2.5);$this->MultiCell($widths[$i]-5,4.8,implode("\n",$wrapped[$i]),0,$i===count($values)-1?'R':'L');$x+=$widths[$i];}
        $this->Line(18,$y+$height,192,$y+$height);$this->SetXY(18,$y+$height);
    }

    private function field(string $label,string $value):void
    {
        $this->font('',9);$lines=$this->lines($value,123);$height=max(5,count($lines)*4.8);
        if($this->GetY()+$height>$this->PageBreakTrigger)$this->AddPage();
        $y=$this->GetY();$this->muted();$this->Cell(46,5,$this->enc($label));$this->ink();$this->SetXY(64,$y);$this->MultiCell(128,4.8,implode("\n",$lines),0,'L');$this->SetXY(18,$y+$height+.3);
    }

    private function body():void
    {
        $d=$this->receipt;$y=$this->GetY();$this->SetFillColor(238,246,242);$this->Rect(18,$y,174,27,'F');
        $this->SetXY(24,$y+4);$this->muted();$this->font('',9);$this->Cell(100,5,$this->enc('VALOR EFETIVAMENTE PAGO'),0,1);
        $this->SetX(24);$this->ink();$this->font('B',21);$this->Cell(162,12,$this->enc(self::money($d['valor_pago'])),0,1);$this->SetY($y+30);
        $statement=Money::cents($d['valor_pago'])===0?'Declaramos a quitação por descontos concedidos, sem recebimento em dinheiro. Esta quitação refere-se à ':'Recebemos a quantia de '.self::money($d['valor_pago']).', referente à ';
        $this->font('',10);$this->MultiCell(174,5.8,$this->enc($statement.'parcela nº '.$d['parcela'].' do contrato '.$d['contrato'].', do(a) aluno(a) '.$d['aluno']['nome'].', RA '.$d['aluno']['ra'].'. Os registros que compõem este recibo estão detalhados abaixo.'),0,'L');
        $this->heading('Identificação da parcela');
        $this->field('Aluno',$d['aluno']['nome']);$this->field('RA',$d['aluno']['ra']);$this->field('Responsável financeiro',$d['responsavel']['nome']);
        if($d['responsavel']['cpf'])$this->field('CPF',self::document($d['responsavel']['cpf']));
        $this->field('Contrato',$d['contrato']);$this->field('Parcela',$d['parcela'].' de '.$d['quantidade_parcelas']);$this->field('Vencimento',self::date($d['vencimento']));
        $this->heading('Composição financeira');
        foreach(['Valor original'=>'valor_original','Desconto incondicional'=>'desconto_incondicional','Valor líquido contratual'=>'valor_liquido','Pontualidade concedida'=>'desconto_condicional','Juros'=>'juros','Multa'=>'multa','Valor pago'=>'valor_pago'] as $label=>$key)$this->field($label,self::money($d[$key]));
        $this->heading('Pagamentos registrados');$widths=[25,43,72,34];$this->row(['Data','Forma de pagamento','Pagador','Valor pago'],$widths,true);
        $methods=['pix'=>'Pix','boleto'=>'Boleto','cartao'=>'Cartão','dinheiro'=>'Dinheiro','transferencia'=>'Transferência','outro'=>'Outro','deposito'=>'Depósito','escola'=>'Recebimento na escola','multiplo'=>'Múltiplas formas'];
        foreach($d['pagamentos'] as $payment){$payer=$payment['pagador'];if($payment['referencia_externa'])$payer.="\nReferência: ".$payment['referencia_externa'];$this->row([self::date($payment['data_pagamento']),$methods[$payment['forma_pagamento']]??($payment['forma_pagamento']?:'Não informado'),$payer,self::money($payment['valor_pago'])],$widths);}
        $this->Ln(7);$this->muted();$this->font('',8.5);$this->MultiCell(174,4.8,$this->enc('Este recibo refere-se exclusivamente à parcela identificada e aos pagamentos registrados que não foram estornados.'),0,'L');
    }
}
