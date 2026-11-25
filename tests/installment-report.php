<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';
require dirname(__DIR__).'/autoload.php';
require __DIR__.'/fixtures/financial-dashboard.php';

use EducacionalERP\Application\{Coligadas,InstallmentReport};
use EducacionalERP\Domain\RuleViolation;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\Pdf\InstallmentReportPdf;
use EducacionalERP\Infrastructure\WordPress\Access;

function reportCheck(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    echo "PASS: $label\n";
}

function rejectReportFilter(callable $fn,string $label):void
{
    try{$fn();}catch(RuleViolation){reportCheck(true,$label);return;}
    throw new RuntimeException($label);
}

Access::install();
update_option('ederp_pontualidade','103.00');
$db=new Database($wpdb);
$f=seedFinancialDashboard($db);
prepareInstallmentReportDueDates($db,$f);
$report=new InstallmentReport($db);
$student=(int)$f['a']['idaluno'];
$sheets=$report->sheets(...InstallmentReport::filters('todos','todos'));
reportCheck(array_column($sheets,'month')===['Março/2027','Fevereiro/2027','Janeiro/2027']&&array_map('count',array_column($sheets,'rows'))===[2,1,4],
    'Todos agrupa por vencimento e exclui cancelados e excluídos');
reportCheck($sheets[2]['totals']===[
    ['label'=>'Total do mês · 2 alunos','original'=>'R$ 5.412,00','net'=>'R$ 4.488,50'],
    ['label'=>'Total geral · 2 alunos','original'=>'R$ 9.471,00','net'=>'R$ 7.194,50'],
]&&count($sheets[0]['totals'])===1&&count($sheets[1]['totals'])===1,
    'totais mensais e geral somam as parcelas e contam o aluno uma única vez');
reportCheck($report->sheets($student,'2027-01')[0]['totals']===[
    ['label'=>'Total do mês · 1 aluno','original'=>'R$ 4.059,00','net'=>'R$ 3.135,50'],
]&&$report->sheets(null,'2026-12')[0]['totals']===[
    ['label'=>'Total do mês · 0 alunos','original'=>'R$ 0,00','net'=>'R$ 0,00'],
],'rodapé respeita o aluno selecionado e mostra zeros em mês vazio');
reportCheck($report->months()===['2027-03','2027-02','2027-01']&&array_column($report->students(),'ra')===['DASH-A','DASH-B'],
    'filtros oferecem meses de vencimento e alunos da coligada');
$january=$report->sheets($student,'2027-01')[0]['rows'];
reportCheck(count($january)===3&&array_unique(array_column($january,0))===['DASH-A'],'aluno e mês de vencimento podem ser combinados mesmo com outra competência');
reportCheck(count($report->sheets((int)$f['b']['idaluno'],'2027-01')[0]['rows'])===1&&$report->sheets(null,'2026-12')[0]['rows']===[],
    'parcelas aparecem no mês de vencimento e saem do mês da competência original');
reportCheck(in_array(['DASH-A','ALUNO DASH-A','R$ 1.353,00','R$ 350,00','R$ 103,00','R$ 1.003,00'],$january,true),
    'valor líquido deduz desconto incondicional e mantém pontualidade em coluna separada após baixas');

update_option('ederp_pontualidade','200.00');
$db->update('lancamentos',$f['titles']['a'][2],['pontualidade_snapshot'=>null]);
$january=$report->sheets($student,'2027-01')[0]['rows'];
reportCheck(in_array('R$ 200,00',array_column($january,4),true)&&in_array('R$ 103,00',array_column($january,4),true),
    'título legado usa a configuração atual e o título novo mantém o valor gravado');
$zero=array_values(array_filter($report->sheets($student,'2027-03')[0]['rows'],fn($r)=>$r[5]==='R$ 0,00'));
reportCheck(count($zero)===1&&$zero[0][4]==='R$ 0,00','bolsa integral mantém o valor líquido e a pontualidade em zero');
rejectReportFilter(fn()=>InstallmentReport::filters('todos','2027-13'),'mês inválido é recusado');
rejectReportFilter(fn()=>InstallmentReport::filters(['todos'],'todos'),'aluno inválido é recusado');

$company=$db->insert('coligadas',['nome'=>'OUTRA','razao_social'=>'OUTRA']);
Coligadas::within($company,function()use($report,$student){
    reportCheck($report->students()===[]&&$report->months()===[]&&$report->sheets(null,null)[0]['rows']===[],'opções e parcelas respeitam a coligada');
    rejectReportFilter(fn()=>$report->sheets($student,null),'aluno de outra coligada é recusado');
});
$pdf=InstallmentReportPdf::render($sheets,['nome'=>'Colégio de teste']);
reportCheck(str_starts_with($pdf,'%PDF-')&&preg_match_all('~/Type /Page\b~',$pdf)===3&&str_contains($pdf,'841.89 595.28'),'PDF gera uma folha por mês de vencimento em A4 paisagem');
$splitSheet=$sheets[2];
$splitSheet['rows']=array_fill(0,24,$january[0]);
$splitSheet['rows'][23][1]='ULTIMA PARCELA';
$splitPdf=InstallmentReportPdf::render([$splitSheet],['nome'=>'Colégio de teste']);
preg_match_all('~\bstream\n(.*?)\nendstream\b~s',$splitPdf,$streams);
$pages=array_map('gzuncompress',array_slice($streams[1],0,2));
reportCheck(preg_match_all('~/Type /Page\b~',$splitPdf)===2&&count($pages)===2
    &&!str_contains($pages[0],'Total do m')&&str_contains($pages[1],'ULTIMA PARCELA')
    &&str_contains($pages[1],mb_convert_encoding('Total do mês · 2 alunos','Windows-1252','UTF-8'))
    &&str_contains($pages[1],'Total geral')&&str_contains($pages[1],'R$ 7.194,50'),
    'PDF mantém a última parcela junto dos totais quando há quebra de página');
if($path=getenv('EDERP_INSTALLMENT_REPORT_PDF'))file_put_contents($path,$pdf);
