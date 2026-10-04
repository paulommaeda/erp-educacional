<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
use EducacionalERP\Domain\{Input, Money, RuleViolation};
final class AcademicService
{
    public function __construct(private Store $db, private Operations $operations) {}
    public function enroll(array $data, string $key): array
    {
        return $this->db->atomic(fn() => $this->operations->run($key, 'matricular', $data, fn() => $this->enrollInside($data, $key)));
    }
    /** Internal composition for enrollment and renewal; caller must own transaction. */
    public function enrollInside(array $data, string $key, ?int $origin = null): array
    {
        $student = $this->db->get('alunos', Input::id($data['idaluno'] ?? null), true);
        if (!(int)$student['ativo']) { throw new RuleViolation('Aluno inativo.'); }
        $class = $this->db->get('turmas', Input::id($data['idturma'] ?? null), true);
        $this->checkClass($class);
        $matriculas = $this->db->table('matriculas');
        if ($this->db->row("SELECT idmatricula FROM $matriculas WHERE idaluno=%d AND codperiodo=%d AND idcurso=%d AND ativo_unico=1 FOR UPDATE", [(int)$student['idaluno'],(int)$class['codperiodo'],(int)$class['idcurso']])) { throw new RuleViolation('O aluno já possui matrícula neste curso e período.'); }
        $enrollment = $this->db->insert('matriculas', ['idaluno'=>$student['idaluno'],'codperiodo'=>$class['codperiodo'],'idcurso'=>$class['idcurso'],
            'idturma_atual'=>$class['idturma'],'idmatricula_origem'=>$origin,'data_matricula'=>Input::today(),'status'=>'reservado','origem'=>$origin ? 'portal' : 'secretaria']);
        $pre=$this->db->table('aluno_periodos');
        $pending=$this->db->row("SELECT idvinculoperiodo FROM $pre WHERE idaluno=%d AND codperiodo=%d AND status='aguardando_turma' FOR UPDATE",[(int)$student['idaluno'],(int)$class['codperiodo']]);
        if($pending) { $this->db->update('aluno_periodos',(int)$pending['idvinculoperiodo'],['idmatricula'=>$enrollment,'status'=>'matriculado']); }
        $financial=$this->createContractInside($enrollment,$data,$key,$origin!==null);
        $this->movement($enrollment,null,(int)$class['idturma'],'matricula','Matrícula inicial',null,'reservado');
        $result = ['idmatricula'=>(string)$enrollment]+$financial;
        $this->db->audit('matriculas',$enrollment,'criar',null,$result,$key);
        return $result;
    }
    public function createContract(int $enrollment,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->operations->run($key,'gerar_contrato',['idmatricula'=>$enrollment]+$data,fn()=>$this->createContractInside($enrollment,$data,$key,$this->db->get('matriculas',$enrollment)['origem']==='portal')));
    }
    /** Internal composition: caller must own a transaction; renewal defers installments. */
    public function createContractInside(int $enrollment,array $data,string $key,bool $defer=false): array
    {
        $initial=$this->db->get('matriculas',$enrollment);
        $student=$this->db->get('alunos',(int)$initial['idaluno'],true);
        $matricula=$this->db->get('matriculas',$enrollment,true);
        if(!\EducacionalERP\Domain\EnrollmentStatus::open($matricula['status'])) { throw new RuleViolation('A matrícula deve estar ativa para gerar o contrato.'); }
        $contracts=$this->db->table('contratos');
        if($this->db->row("SELECT idcontrato FROM $contracts WHERE idmatricula=%d FOR UPDATE",[$enrollment])) { throw new RuleViolation('Esta matrícula já possui contrato financeiro.'); }
        $links = $this->db->table('aluno_responsaveis');
        $rf = $this->db->rows("SELECT * FROM $links WHERE idaluno=%d AND responsavel_financeiro=1 AND fim_vigencia IS NULL AND inicio_vigencia <= UTC_TIMESTAMP() FOR UPDATE", [(int)$student['idaluno']]);
        if (count($rf) !== 1) { throw new RuleViolation('Cadastre exatamente um responsável financeiro vigente.'); }
        $person = $this->db->get('pessoas', (int)$rf[0]['codpessoa_responsavel']);
        if (!(int)$person['ativo']) { throw new RuleViolation('Responsável inativo.'); }
        $class=$this->db->get('turmas',(int)$matricula['idturma_atual'],true);
        $plan=$this->planForClass((int)$class['idturma'],true);
        if(isset($data['plano_versao'])&&(string)$data['plano_versao']!==(string)$plan['versao'])throw new RuleViolation('O plano de pagamento mudou. Confira os valores novamente.');
        if(isset($data['idplano'])&&(int)$data['idplano']!==(int)$plan['idplano'])throw new RuleViolation('O plano da turma mudou. Confira os valores novamente.');
        $original=Money::positive($plan['valor_anuidade']);
        $discount=Money::cents($data['desconto_incondicional_total']??'0.00');
        if($discount<0||$discount>=$original)throw new RuleViolation('Bolsa deve ser menor que a anuidade e não pode ser negativa.');
        $count=Input::id($data['quantidade_parcelas']??null);Money::split($original-$discount,$count);
        $first=Input::date($data['primeiro_vencimento']??null);
        $contract=$this->db->insert('contratos',['numero'=>'CT-'.gmdate('Y').'-'.$enrollment,'idmatricula'=>$enrollment,
            'codpessoa_rf_original'=>$person['codpessoa'],'codpessoa_rf_atual'=>$person['codpessoa'],'data_contrato'=>Input::today(),
            'valor_original_total'=>Money::format($original),'desconto_incondicional_total'=>Money::format($discount),'valor_liquido_total'=>Money::format($original-$discount),
            'quantidade_parcelas'=>$count,'primeiro_vencimento'=>$first,'versao_termo'=>Input::text($data['versao_termo']??'1',40),
            'termo_snapshot'=>Input::text($data['termo']??'Contrato registrado pela secretaria.',20000),
            'idplano'=>$plan['idplano'],'plano_nome_snapshot'=>$plan['nome'],'plano_versao_snapshot'=>$plan['versao'],'parcelas_geradas'=>0]);
        $result=['idcontrato'=>(string)$contract,'quantidade_parcelas'=>$count,'valor_anuidade'=>Money::format($original),'parcelas_geradas'=>!$defer];
        $this->db->audit('contratos',$contract,'criar',null,$this->db->get('contratos',$contract),$key);
        if(!$defer)$this->generateInside($contract,$key);
        return $result;
    }
    public function planForClass(int $classId,bool $lock=false):array
    {
        $class=$this->db->get('turmas',$classId,$lock);
        if(empty($class['idplano']))throw new RuleViolation('Esta turma não possui plano de pagamento. Peça ao administrador para vinculá-lo.');
        $plan=$this->db->get('planos_pagamento',(int)$class['idplano'],$lock);
        if(empty($plan['codperiodo'])||(int)$plan['codperiodo']!==(int)$class['codperiodo'])throw new RuleViolation('Plano sem período ou incompatível com o período da turma. Ajuste a estrutura acadêmica.');
        if(!(int)$plan['ativo'])throw new RuleViolation('Plano de pagamento inativo.');
        return $plan;
    }
    public function generateInstallments(int $id,string $key):array
    {
        if(!\EducacionalERP\Infrastructure\WordPress\Access::canGenerate())throw new RuleViolation('Somente o perfil Financeiro ou administrador pode gerar parcelas pendentes.');
        return $this->db->atomic(fn()=>$this->operations->run($key,'gerar_parcelas',['idcontrato'=>$id],fn()=>$this->generateInside($id,$key)));
    }
    private function generateInside(int $id,string $key):array
    {
        $initial=$this->db->get('contratos',$id);$m=$this->db->get('matriculas',(int)$initial['idmatricula']);
        $student=$this->db->get('alunos',(int)$m['idaluno'],true);$m=$this->db->get('matriculas',(int)$m['idmatricula'],true);$contract=$this->db->get('contratos',$id,true);
        if((int)$contract['parcelas_geradas'])return ['idcontrato'=>(string)$id,'quantidade_parcelas'=>(int)$contract['quantidade_parcelas'],'ja_geradas'=>true];
        if(!\EducacionalERP\Domain\EnrollmentStatus::open($m['status'])||$contract['status']!=='ativo')throw new RuleViolation('Matrícula e contrato devem estar ativos.');
        if($this->db->row('SELECT idparcela FROM '.$this->db->table('parcelas').' WHERE idcontrato=%d FOR UPDATE',[$id]))throw new RuleViolation('Contrato com parcelas existentes e situação divergente. Procure o administrador.');
        $person=$this->db->get('pessoas',(int)$contract['codpessoa_rf_atual']);if(!(int)$person['ativo'])throw new RuleViolation('Responsável financeiro inativo.');
        $count=(int)$contract['quantidade_parcelas'];$original=Money::positive($contract['valor_original_total']);$discount=Money::cents($contract['desconto_incondicional_total']);
        $originals=Money::split($original,$count);$discounts=array_pad($discount?Money::split($discount,min($count,$discount)):[],$count,0);
        $start=new \DateTimeImmutable(Input::date($contract['primeiro_vencimento']));
        for($i=0;$i<$count;$i++){
            $month=$start->modify('first day of this month')->modify("+$i months");$day=min((int)$start->format('d'),(int)$month->format('t'));
            $due=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),$day)->format('Y-m-d');$net=$originals[$i]-$discounts[$i];
            if($net<=0)throw new RuleViolation('O parcelamento deve produzir parcelas líquidas positivas.');
            $values=['valor_original'=>Money::format($originals[$i]),'desconto_incondicional'=>Money::format($discounts[$i]),'valor_liquido'=>Money::format($net)];
            $parcel=$this->db->insert('parcelas',$values+['idcontrato'=>$id,'numero'=>$i+1,'competencia'=>$month->format('Y-m-01'),'vencimento_original'=>$due,'regra_desconto_condicional'=>'{"tipo":"nenhum"}','regra_encargos'=>'{"tipo":"manual_auditado"}']);
            $this->db->insert('lancamentos',$values+['idparcela'=>$parcel,'codpessoa_rf_original'=>$contract['codpessoa_rf_original'],'codpessoa_rf_atual'=>$contract['codpessoa_rf_atual'],
                'descricao'=>'Parcela '.($i+1).' - RA '.$student['ra'],'emissao'=>Input::today(),'vencimento'=>$due,'saldo_aberto'=>Money::format($net)]);
        }
        (new ContractDiscounts($this->db,$this->operations))->applyInside($id,$key);
        $this->db->update('contratos',$id,['parcelas_geradas'=>1,'parcelas_geradas_em'=>gmdate('Y-m-d H:i:s')]);
        $result=['idcontrato'=>(string)$id,'quantidade_parcelas'=>$count,'ja_geradas'=>false];$this->db->audit('contratos',$id,'gerar_parcelas',null,$result,$key);return $result;
    }
    /** Academic placement only; a contract can be configured after assignment. */
    public function placeInside(int $studentId,int $periodId,int $classId,string $key): array
    {
        $student=$this->db->get('alunos',$studentId,true);
        if(!(int)$student['ativo']) { throw new RuleViolation('Aluno inativo.'); }
        $class=$this->db->get('turmas',$classId,true);
        if((int)$class['codperiodo']!==$periodId) { throw new RuleViolation('A turma não pertence ao período selecionado.'); }
        $this->checkClass($class);
        $m=$this->db->table('matriculas');
        if($this->db->row("SELECT idmatricula FROM $m WHERE idaluno=%d AND codperiodo=%d AND idcurso=%d AND ativo_unico=1 FOR UPDATE",[$studentId,$periodId,(int)$class['idcurso']])) { throw new RuleViolation('O aluno já está matriculado neste curso e período.'); }
        $id=$this->db->insert('matriculas',['idaluno'=>$studentId,'codperiodo'=>$periodId,'idcurso'=>$class['idcurso'],'idturma_atual'=>$classId,'data_matricula'=>Input::today(),'status'=>'reservado','origem'=>'secretaria']);
        $this->movement($id,null,$classId,'matricula','Vinculação de turma pela ficha do aluno',null,'reservado');
        $result=['idmatricula'=>(string)$id,'idturma'=>(string)$classId];
        $this->db->audit('matriculas',$id,'criar',null,$result,$key);
        return $result;
    }
    public function transfer(int $id, array $data, string $key): array
    {
        return $this->db->atomic(fn() => $this->operations->run($key,'transferir', ['idmatricula'=>$id]+$data, function () use ($id,$data,$key) {
            // All academic/financial mutations acquire the student row first.
            $initial = $this->db->get('matriculas',$id);
            $this->db->get('alunos',(int)$initial['idaluno'],true);
            $m = $this->db->get('matriculas',$id,true);
            if(isset($data['versao'])&&(string)$data['versao']!==(string)$m['versao'])throw new RuleViolation('Matrícula alterada. Atualize a ficha.');
            $destination = Input::id($data['idturma_destino'] ?? null);
            if (!\EducacionalERP\Domain\EnrollmentStatus::open($m['status']) || $destination === (int)$m['idturma_atual']) { throw new RuleViolation('Matrícula inativa ou turma sem alteração.'); }
            $ids = [(int)$m['idturma_atual'],$destination]; sort($ids);
            foreach ($ids as $classId) { $this->db->get('turmas',$classId,true); }
            $class = $this->db->get('turmas',$destination);
            if ($class['codperiodo'] !== $m['codperiodo'] || $class['idcurso'] !== $m['idcurso']) { throw new RuleViolation('Transferência deve manter curso e período.'); }
            $this->checkClass($class);
            $reason = Input::text($data['motivo'] ?? null,2000);
            $this->db->update('matriculas',$id,['idturma_atual'=>$destination]);
            $this->movement($id,(int)$m['idturma_atual'],$destination,'transferencia_turma',$reason,$m['status'],$m['status']);
            $after = $this->db->get('matriculas',$id);
            $this->db->audit('matriculas',$id,'transferir',$m,$after,$key);
            return ['idmatricula'=>(string)$id,'idturma_atual'=>(string)$destination];
        }));
    }
    public function checkClass(array $class): void
    {
        if ($class['status'] !== 'ativa') { throw new RuleViolation('Turma inativa.'); }
        $period = $this->db->get('periodos_letivos',(int)$class['codperiodo']);
        if ($period['status'] === 'encerrado') { throw new RuleViolation('Período encerrado.'); }
        foreach (['cursos'=>'idcurso','turnos'=>'idturno'] as $table=>$id) {
            if (!(int)$this->db->get($table,(int)$class[$id])['ativo']) { throw new RuleViolation('Curso ou turno inativo.'); }
        }
        $table = $this->db->table('matriculas');
        // Current locking read, not an older consistent snapshot: prevents overbooking.
        $occupied = $this->db->rows("SELECT idmatricula FROM $table WHERE idturma_atual=%d AND status IN ('reservado','cursando','ativa') FOR UPDATE", [(int)$class['idturma']]);
        if (count($occupied) >= (int)$class['capacidade']) { throw new RuleViolation('Turma sem vagas.'); }
    }
    public function movement(int $id, ?int $from, ?int $to, string $type, string $reason, ?string $old, string $new): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('matricula_movimentacoes',['idmatricula'=>$id,'tipo'=>$type,'idturma_origem'=>$from,'idturma_destino'=>$to,
            'status_anterior'=>$old,'status_novo'=>$new,'efetivado_em'=>$now,'registrado_em'=>$now,'motivo'=>$reason,'ator_wp_user_id'=>get_current_user_id()]);
    }
}
