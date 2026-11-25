<?php
declare(strict_types=1);

require __DIR__.'/sqlite-bootstrap.php';
require dirname(__DIR__).'/autoload.php';
require __DIR__.'/fixtures/scholarships.php';

use EducacionalERP\Application\{AnnualClearance,Coligadas,FinanceService,Operations};
use EducacionalERP\Domain\{Input,RuleViolation};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\Pdf\AnnualClearancePdf;
use EducacionalERP\Infrastructure\WordPress\Access;

function clearanceCheck(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}

function clearanceReject(callable $run, string $message): void
{
    try {
        $run();
    } catch (RuleViolation $error) {
        clearanceCheck(str_contains($error->getMessage(), $message), $message.' | '.$error->getMessage());
        return;
    }
    throw new RuntimeException($message);
}

Access::install();
$db = new Database($wpdb);
$db->update('coligadas', 1, ['nome'=>'COLÉGIO TESTE', 'razao_social'=>'ESCOLA FICTÍCIA LTDA', 'cnpj'=>'11222333000181']);
$school = ['nome'=>'COLÉGIO TESTE', 'cidade'=>'Curitiba', 'estado'=>'PR', 'rua'=>'Rua de Teste', 'numero'=>'12', 'logo_id'=>0];
$fixture = seedScholarships($db);
$responsible = $fixture['responsible'];
$first = createScholarshipContract($db, $fixture, 'QUIT-A', [scholarshipSelection($fixture['full'], 2, 2)]);
$second = createScholarshipContract($db, $fixture, 'QUIT-B');
$source = $db->table('lancamentos');
$installments = $db->table('parcelas');
$ids = static fn (array $contract) => array_column($db->rows("SELECT l.idlancamento FROM $source l JOIN $installments p
    ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY p.numero", [(int)$contract['idcontrato']]), 'idlancamento');
[$multiple, $full, $canceled, $deleted] = array_map('intval', $ids($first));
[$pending, $transferred] = array_map('intval', $ids($second));
$current = (int)substr(Input::today(), 0, 4);
$year = $current-2;
$later = $year+1;
$dates = [$multiple=>'-01-10', $full=>'-02-10', $pending=>'-03-10', $transferred=>'-04-10', $canceled=>'-05-10', $deleted=>'-06-10'];
foreach ($dates as $id=>$date) $db->update('lancamentos', $id, ['vencimento'=>$year.$date]);
$db->update('lancamentos', $canceled, ['status'=>'cancelado', 'saldo_aberto'=>'0.00', 'valor_cancelado'=>'1353.00']);
$db->update('lancamentos', $deleted, ['excluido_em'=>$year.'-01-01 00:00:00']);
$other = $db->insert('pessoas', ['nome'=>'OUTRO RESPONSÁVEL TESTE', 'cpf'=>'12345678909', 'data_nascimento'=>'1980-01-02']);

$report = new AnnualClearance($db);
$finance = new FinanceService($db, new Operations($db));
$key = static fn () => bin2hex(random_bytes(16));
$pay = static fn (int $id, string $amount, string $date) => $finance->pay($id,
    ['valor_pago'=>$amount, 'forma_pagamento'=>'pix', 'data_pagamento'=>$date], $key());

clearanceCheck(array_map('intval', $report->years()) === [$year] && array_map('intval', array_column($report->responsibles($year), 'codpessoa')) === [$responsible],
    'opções usam anos encerrados de vencimento e responsáveis da coligada');
clearanceReject(fn () => AnnualClearance::year($current), 'ano-calendário já encerrado');
clearanceReject(fn () => $report->details($year, $responsible, $school), '3 títulos com saldo em aberto');
$pay($multiple, '500.00', $year.'-10-10');
$pay($multiple, '853.00', $later.'-01-10');
$pay($pending, '1353.00', $later.'-01-10');
$reversed = $pay($transferred, '300.00', $later.'-01-15');
$finance->reverse((int)$reversed['idbaixa'], ['motivo'=>'Estorno de teste'], $key());
clearanceReject(fn () => $report->details($year, $responsible, $school), '1 título com saldo em aberto');
$pay($transferred, '350.00', $later.'-01-11');
$db->insert('aluno_responsaveis', ['idaluno'=>(int)$second['idaluno'], 'codpessoa_responsavel'=>$other, 'parentesco'=>'pai',
    'responsavel_financeiro'=>0, 'inicio_vigencia'=>'2000-01-01 00:00:00']);
$finance->changeGuardian((int)$second['idaluno'], ['codpessoa_nova'=>$other, 'motivo'=>'Troca de teste'], $key());
$pay($transferred, '1003.00', $later.'-01-14');

$data = $report->details($year, $responsible, $school);
clearanceCheck(count($data['rows']) === 4 && $data['total'] === 'R$ 3.056,00' && str_contains($data['statement'], (string)$year)
    && str_contains($data['statement'], '11.222.333/0001-81'), 'seleção reúne os dois alunos e identifica o emissor e o ano');
clearanceCheck($data['rows'][0] === ['Mensalidade · ALUNO QUIT-A', '1/12', '10/01/'.$year, 'R$ 1.353,00', 'R$ 1.353,00', 'Quitado', '10/01/'.$later]
    && $data['rows'][1][4] === 'R$ 0,00' && $data['rows'][1][6] === '—', 'baixas parciais são somadas e bolsa integral aparece sem data de pagamento');
clearanceCheck($data['rows'][3][4] === 'R$ 350,00' && $data['rows'][3][6] === '11/01/'.$later
    && $report->details($year, $other, $school)['total'] === 'R$ 1.003,00', 'troca de responsável preserva valores históricos e ignora estorno');

$scope = $report->details($year, $responsible, $school, (int)$first['idaluno'], $fixture['period']);
clearanceCheck(count($scope['rows']) === 2 && $scope['total'] === 'R$ 1.353,00'
    && str_contains($scope['statement'], 'RA QUIT-A') && str_contains($scope['statement'], 'período letivo BOLSA-2027'),
    'filtros de aluno e período letivo restringem a tabela e identificam o recorte no documento');
clearanceCheck(count($report->responsibles($year, (int)$first['idaluno'], $fixture['period'])) === 1
    && $report->responsibles($year, (int)$first['idaluno'], $fixture['period']+100) === [],
    'lista de responsáveis segue os filtros administrativos');
clearanceCheck(array_keys($report->personalOptions($responsible)) === ['1-'.$year]
    && array_keys($report->personalOptions($other)) === ['1-'.$year]
    && $report->personalOptions((int)$first['codpessoa']) === [], 'opções particulares usam vínculos financeiros do titular');

$outside = $db->insert('coligadas', ['nome'=>'OUTRA', 'razao_social'=>'OUTRA']);
Coligadas::within($outside, function () use ($report, $year, $responsible, $school) {
    clearanceCheck($report->years() === [] && $report->responsibles($year) === [], 'filtros respeitam a coligada');
    clearanceReject(fn () => $report->details($year, $responsible, $school), 'Nenhum título');
});
$db->update('lancamentos', $multiple, ['valor_baixa'=>'1352.00']);
clearanceReject(fn () => $report->details($year, $responsible, $school), 'baixas divergentes');

$pdf = AnnualClearancePdf::render($data);
clearanceCheck(str_starts_with($pdf, '%PDF-') && str_contains($pdf, '595.28 841.89')
    && preg_match_all('~/Type /Page\b~', $pdf) === 1, 'PDF da declaração em A4 retrato');
$large = $data;
$large['rows'] = array_fill(0, 90, $data['rows'][0]);
$paginated = AnnualClearancePdf::render($large);
preg_match_all('~\bstream\n(.*?)\nendstream\b~s', $paginated, $streams);
$count = preg_match_all('~/Type /Page\b~', $paginated);
$last = gzuncompress($streams[1][$count-1]);
clearanceCheck($count >= 3 && str_contains($last, 'Valor total pago') && str_contains($last, 'DEPARTAMENTO FINANCEIRO')
    && str_contains($last, $count.' de '.$count), 'PDF multipágina mantém o fechamento e numera as páginas');
if ($path = getenv('EDERP_CLEARANCE_TEST_PDF')) file_put_contents($path, $pdf);
