<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Cpf,FinancialStatus,Input,Money,RuleViolation,Store};

final class AnnualClearance
{
    public const TITLE = 'Declaração de Quitação Anual de Débitos';
    public const COLUMNS = ['Tipo', 'Parcela', 'Vencimento', 'V. original', 'V. pago', 'Situação', 'Data pagamento'];
    public const NOTICE = 'Esta declaração substitui os comprovantes mensais de quitação dos débitos que abrange, nos termos da Lei nº 12.007/2009.';

    public function __construct(private Store $db) {}

    public static function year(mixed $input): int
    {
        if ((!is_string($input) && !is_int($input)) || !preg_match('/^[1-9][0-9]{3}$/D', (string)$input)
            || (int)$input >= (int)substr(Input::today(), 0, 4)) {
            throw new RuleViolation('Selecione um ano-calendário já encerrado.');
        }
        return (int)$input;
    }

    public function years(): array
    {
        return array_column($this->db->rows('SELECT DISTINCT SUBSTR(l.vencimento,1,4) AS ano'.$this->titles()
            .' WHERE '.self::scope().' AND l.vencimento<%s ORDER BY ano DESC',
            [Coligadas::current(), substr(Input::today(), 0, 4).'-01-01']), 'ano');
    }

    public function responsibles(int $year): array
    {
        $payments = $this->db->table('baixas');
        $people = $this->db->table('pessoas');
        return $this->db->rows('SELECT DISTINCT rf.codpessoa,rf.nome'.$this->titles()
            ." LEFT JOIN $payments b ON b.idlancamento=l.idlancamento AND b.codcoligada=l.codcoligada
                AND CAST(l.saldo_aberto AS DECIMAL(15,2))=0 AND ".$this->unreversed('b')
            ." JOIN $people rf ON rf.codpessoa=l.codpessoa_rf_atual OR rf.codpessoa=b.codpessoa_devedor"
            .' WHERE '.self::scope().' AND l.vencimento BETWEEN %s AND %s ORDER BY rf.nome,rf.codpessoa',
            [Coligadas::current(), $year.'-01-01', $year.'-12-31']);
    }

    public function details(int $year, int $responsible, array $school): array
    {
        self::year($year);
        $company = Coligadas::current();
        $payments = $this->db->table('baixas');
        $students = $this->db->table('alunos');
        $people = $this->db->table('pessoas');
        $titles = $this->db->rows('SELECT l.idlancamento,l.status,l.saldo_aberto,l.vencimento,l.valor_original,l.valor_baixa,
            p.numero,c.quantidade_parcelas,student.nome,b.codpessoa_devedor,b.valor_pago,b.data_pagamento'.$this->titles()
            ." JOIN $students a ON a.idaluno=m.idaluno AND a.codcoligada=l.codcoligada
                JOIN $people student ON student.codpessoa=a.codpessoa
                LEFT JOIN $payments b ON b.idlancamento=l.idlancamento AND b.codcoligada=l.codcoligada AND ".$this->unreversed('b')
            .' WHERE '.self::scope()." AND l.vencimento BETWEEN %s AND %s
                AND (l.codpessoa_rf_atual=%d OR (CAST(l.saldo_aberto AS DECIMAL(15,2))=0 AND EXISTS (
                    SELECT 1 FROM $payments own WHERE own.idlancamento=l.idlancamento AND own.codcoligada=l.codcoligada
                    AND own.codpessoa_devedor=%d AND ".$this->unreversed('own').')))
                ORDER BY l.vencimento,student.nome,p.numero,l.idlancamento,b.data_pagamento,b.idbaixa',
            [$company, $year.'-01-01', $year.'-12-31', $responsible, $responsible]);
        if (!$titles) throw new RuleViolation('Nenhum título encontrado para este responsável no ano e na coligada selecionados.');

        $groups = [];
        $pending = [];
        foreach ($titles as $row) {
            $id = (int)$row['idlancamento'];
            if (FinancialStatus::value($row) !== 'baixado') $pending[$id] = true;
            $groups[$id] ??= ['title'=>$row, 'payment_sum'=>0, 'paid'=>0, 'last_payment'=>null];
            if ($row['codpessoa_devedor'] === null) continue;
            $value = Money::cents((string)$row['valor_pago']);
            if ($value < 0) throw new RuleViolation('Há baixas que precisam ser conferidas pelo financeiro antes da emissão.');
            $groups[$id]['payment_sum'] += $value;
            if ((int)$row['codpessoa_devedor'] === $responsible) {
                $groups[$id]['paid'] += $value;
                $groups[$id]['last_payment'] = Input::date($row['data_pagamento']);
            }
        }
        if ($pending) {
            $count = count($pending);
            $message = $count === 1 ? 'Há 1 título com saldo em aberto neste ano.' : 'Há '.$count.' títulos com saldo em aberto neste ano.';
            throw new RuleViolation($message.' Confira a Gestão financeira antes de emitir a declaração.');
        }
        $rows = [];
        $total = 0;
        foreach ($groups as $group) {
            $title = $group['title'];
            if ($group['payment_sum'] !== Money::cents((string)$title['valor_baixa'])) {
                throw new RuleViolation('Há títulos com baixas divergentes. Confira os pagamentos na Gestão financeira antes de emitir a declaração.');
            }
            $total += $group['paid'];
            $rows[] = [
                'Mensalidade · '.$title['nome'], $title['numero'].'/'.$title['quantidade_parcelas'],
                self::date($title['vencimento']), self::currency(Money::cents((string)$title['valor_original'])),
                self::currency($group['paid']), 'Quitado', $group['last_payment'] === null ? '—' : self::date($group['last_payment']),
            ];
        }

        $person = $this->db->get('pessoas', $responsible);
        $cpf = preg_replace('/\D/', '', (string)$person['cpf']);
        if (!Cpf::valid($cpf)) throw new RuleViolation('Cadastre um CPF válido para o responsável financeiro antes de emitir a declaração.');
        $owner = $this->db->get('coligadas', $company);
        $cnpj = (string)$owner['cnpj'];
        $legalName = trim((string)$owner['razao_social']);
        if ($cnpj === '' && !empty($school['razao_social'])) {
            $cnpj = preg_replace('/\D/', '', (string)($school['cnpj'] ?? ''));
            $legalName = trim($school['razao_social']);
        }
        if (!Coligadas::validCnpj($cnpj) || $legalName === '') {
            throw new RuleViolation('Preencha a razão social e um CNPJ válido para a coligada antes de emitir a declaração.');
        }
        if (!empty($school['logo_padrao']) && $company !== 1) $school['logo_padrao'] = false;
        if (empty($school['logo_id']) && empty($school['logo_padrao'])) $school['nome'] = $owner['nome'];
        $cnpj = preg_replace('/^(..)(...)(...)(....)(..)$/', '$1.$2.$3/$4-$5', $cnpj);
        $cpf = preg_replace('/^(...)(...)(...)(..)$/', '$1.$2.$3-$4', $cpf);
        $statement = $legalName.', inscrita no CNPJ nº '.$cnpj.', declara, nos termos da Lei nº 12.007, de 29 de julho de 2009, '
            .'que os débitos com vencimento no ano de '.$year.' vinculados a '.$person['nome'].', CPF nº '.$cpf.', encontram-se quitados, conforme os títulos abaixo.';
        $city = trim((string)($school['cidade'] ?? ''));
        $address = trim(implode(' ', array_filter([$school['rua'] ?? '', $school['numero'] ?? '', $school['complemento'] ?? ''])));
        $locality = implode(' – ', array_filter([$school['bairro'] ?? '', trim($city.' '.($school['estado'] ?? '')), $school['cep'] ?? '']));
        $contact = implode(' · ', array_filter([$school['telefone'] ?? '', $school['site'] ?? '', $school['email'] ?? '']));
        return ['school'=>$school, 'statement'=>$statement, 'rows'=>$rows, 'total'=>self::currency($total),
            'issue_date'=>($city === '' ? '' : $city.', ').self::date(Input::today()),
            'footer'=>array_filter([implode(' – ', array_filter([$address, $locality])), $contact])];
    }

    private function titles(): string
    {
        $titles = $this->db->table('lancamentos');
        $installments = $this->db->table('parcelas');
        $contracts = $this->db->table('contratos');
        $enrollments = $this->db->table('matriculas');
        return " FROM $titles l
            JOIN $installments p ON p.idparcela=l.idparcela AND p.codcoligada=l.codcoligada
            JOIN $contracts c ON c.idcontrato=p.idcontrato AND c.codcoligada=l.codcoligada
            JOIN $enrollments m ON m.idmatricula=c.idmatricula AND m.codcoligada=l.codcoligada";
    }

    private static function scope(): string
    {
        return "l.codcoligada=%d AND l.excluido_em IS NULL AND c.excluido_em IS NULL AND m.excluido_em IS NULL AND l.status<>'cancelado'";
    }

    private function unreversed(string $alias): string
    {
        $reversals = $this->db->table('baixa_estornos');
        return "NOT EXISTS (SELECT 1 FROM $reversals e WHERE e.idbaixa=$alias.idbaixa AND e.codcoligada=$alias.codcoligada)";
    }

    private static function date(string $date): string
    {
        return implode('/', array_reverse(explode('-', $date)));
    }

    private static function currency(int $cents): string
    {
        $amount = abs($cents);
        return ($cents < 0 ? '-' : '').'R$ '.number_format(intdiv($amount, 100), 0, ',', '.').','.str_pad((string)($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
