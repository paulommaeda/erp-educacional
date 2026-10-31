<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Pdf;
use EducacionalERP\Domain\AdmissionForm;
require_once dirname(__DIR__,3).'/lib/fpdf/fpdf.php';
final class AdmissionPdf extends \EducacionalERP\Vendor\Fpdf\FPDF
{
    private function __construct(private array $request,private array $school,private ?string $logo,private ?string $photo)
    {
        parent::__construct('P','mm','A4');$this->SetMargins(16,16,16);$this->SetAutoPageBreak(true,19);$this->AliasNbPages();$this->SetTitle($this->enc('Requerimento de matrícula - '.$request['protocolo']));$this->SetAuthor($this->enc($school['nome']));
    }
    private function enc(string $s):string {return iconv('UTF-8','windows-1252//TRANSLIT',str_replace(["\r",'–','—'],['','-','-'],$s))?:'';}
    public function Header()
    {
        $x=16;if($this->logo){$s=getimagesize($this->logo);$ratio=min(42/$s[0],17/$s[1]);$this->Image($this->logo,16,13,$s[0]*$ratio,$s[1]*$ratio);$x=65;}
        $this->SetXY($x,13);$this->SetFont('Helvetica','B',12);$this->SetTextColor(34,61,57);$this->MultiCell(194-$x,5,$this->enc($this->school['nome']));
        $this->SetFont('Helvetica','',8);$this->SetX($x);$this->MultiCell(194-$x,4,$this->enc('CNPJ: '.($this->school['cnpj']?:'Não informado')));$this->SetX($x);$this->MultiCell(194-$x,4,$this->enc($this->request['protocolo'].' | '.strtoupper($this->request['status'])));
        $this->SetDrawColor(191,211,202);$this->Line(16,34,194,34);$this->SetXY(16,39);
    }
    public function Footer()
    {
        $this->SetY(-14);$this->SetFont('Helvetica','',8);$this->SetTextColor(88,103,99);$this->Cell(145,5,$this->enc('Requerimento sujeito à conferência e à disponibilidade de vaga.'));$this->Cell(33,5,$this->enc($this->PageNo().' / {nb}'),0,0,'R');
    }
    private function heading(string $s):void {if($this->GetY()>248)$this->AddPage();$this->Ln(3);$this->SetFont('Helvetica','B',11);$this->SetFillColor(235,243,238);$this->SetTextColor(34,61,57);$this->Cell(178,9,$this->enc($s),0,1,'L',true);$this->Ln(3);}
    private function row(string $label,mixed $value):void
    {
        if($value===null||$value===''||$value===[])return;if(is_array($value))$value=implode("\n",$value);if($this->GetY()>254)$this->AddPage();$this->SetFont('Helvetica','B',8.5);$this->SetTextColor(79,97,89);$this->MultiCell(178,4.5,$this->enc($label));$this->SetFont('Helvetica','',10);$this->SetTextColor(28,45,39);$this->MultiCell(178,5,$this->enc((string)$value));$this->Ln(2);
    }
    public static function render(array $r,array $school,?string $logo=null,?string $photo=null):string
    {
        $pdf=new self($r,$school,$logo,$photo);$pdf->AddPage();$pdf->SetFont('Helvetica','B',19);$pdf->Cell(178,11,$pdf->enc('Requerimento de matrícula'),0,1);$pdf->row('Recebido em',self::date($r['criado_em']));$pdf->row('Conferido em',self::date($r['conferido_em']??''));$pdf->row('Motivo da rejeição',$r['motivo']??'');
        $data=$r['dados'];$labels=['nome'=>'Nome completo','data_nascimento'=>'Data de nascimento','cpf'=>'CPF','rg'=>'RG','sexo'=>'Sexo','email'=>'E-mail','telefone'=>'Telefone / WhatsApp','local_nascimento'=>'Local de nascimento','uf_nascimento'=>'UF de nascimento','raca_etnia'=>'Raça/Etnia','nacionalidade'=>'Nacionalidade','grau_instrucao'=>'Grau de instrução','profissao'=>'Profissão','empresa'=>'Empresa','religiao'=>'Religião','igreja'=>'Igreja','rua'=>'Rua','numero'=>'Número','complemento'=>'Complemento','bairro'=>'Bairro','cep'=>'CEP','cidade'=>'Cidade','estado'=>'Estado / UF','pais'=>'País','estado_civil_nome'=>'Estado civil'];
        foreach($data['pessoas'] as $slot=>$p){$pdf->heading(AdmissionForm::PEOPLE[$slot]);if($slot==='aluno'&&$pdf->photo){$size=getimagesize($pdf->photo);$scale=min(32/$size[0],32/$size[1]);$top=$pdf->GetY();if($top+35>270){$pdf->AddPage();$top=$pdf->GetY();}$pdf->Image($pdf->photo,16,$top,$size[0]*$scale,$size[1]*$scale,$size['mime']==='image/png'?'PNG':'JPG');$pdf->SetY($top+35);}foreach($labels as $key=>$label)$pdf->row($label,$key==='data_nascimento'?self::date($p[$key]??''):($p[$key]??''));}
        $pdf->heading('Responsáveis');$pdf->row('Responsável acadêmico',$data['pessoas'][$data['responsavel_academico']]['nome']);$pdf->row('Responsável financeiro',$data['pessoas'][$data['responsavel_financeiro']]['nome']);
        foreach(['solicitacao'=>'Solicitação e escola de origem','familia'=>'Núcleo familiar','dificuldades_aprendizagem'=>'Aprendizagem e necessidades educacionais','ficha_medica'=>'Ficha médica'] as $area=>$title){$pdf->heading($title);foreach(AdmissionForm::fields() as $f)if($f['area']===$area)$pdf->row($f['nome'],$data['campos'][$f['chave']]??'');}
        foreach(['retiradas'=>'Autorizações de retirada','contatos_emergencia'=>'Contatos de emergência'] as $key=>$title){$pdf->heading($title);if(!$data[$key])$pdf->row('Informação','Nenhum contato informado.');foreach($data[$key] as $i=>$c){$pdf->row('Contato '.($i+1),$c['nome']);foreach(['parentesco'=>'Parentesco','telefone'=>'Telefone','cpf'=>'CPF','rg'=>'RG','observacoes'=>'Observações'] as $k=>$label)$pdf->row($label,$c[$k]??'');}}
        $pdf->heading('Anexos e declaração');foreach($r['arquivos'] as $f)$pdf->row(['foto'=>'Foto','guarda'=>'Documento de guarda','adocao'=>'Documento de adoção'][$f['campo']]??$f['campo'],$f['arquivo_nome']);$pdf->row('Preenchido por',$data['declaracao_nome']);$pdf->row('Declaração aceita no envio',$r['declaracao_texto']??'Informações declaradas verdadeiras pelo solicitante.');$pdf->row('Informação de uso dos dados no envio',$r['privacidade_texto']??'');if($r['status']==='aprovado')$pdf->row('Conferência','Este PDF apresenta os dados conferidos pela equipe. O envio original permanece preservado no ERP.');return $pdf->Output('S');
    }
    private static function date(string $d):string {if($d==='')return '';if(strlen($d)>10)return (new \DateTimeImmutable($d,new \DateTimeZone('UTC')))->setTimezone(function_exists('wp_timezone')?wp_timezone():new \DateTimeZone('UTC'))->format('d/m/Y H:i T');return substr($d,8,2).'/'.substr($d,5,2).'/'.substr($d,0,4);}
}
