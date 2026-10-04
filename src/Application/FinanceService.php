<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
use EducacionalERP\Domain\{Input, Money, RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Accounts;
final class FinanceService
{
    public function __construct(private Store $db, private Operations $operations) {}
    /** Acquires the same hierarchy as enrollment and transfer: student -> contract -> title. */
    private function lockTitle(int $id): array
    {
        $l=$this->db->table('lancamentos'); $p=$this->db->table('parcelas'); $c=$this->db->table('contratos'); $m=$this->db->table('matriculas');
        $path=$this->db->row("SELECT m.idaluno,c.idcontrato FROM $l l JOIN $p p ON p.idparcela=l.idparcela JOIN $c c ON c.idcontrato=p.idcontrato JOIN $m m ON m.idmatricula=c.idmatricula WHERE l.idlancamento=%d",[$id]);
        if (!$path) { throw new RuleViolation('Lançamento não encontrado.'); }
        $this->db->get('alunos',(int)$path['idaluno'],true);
        $contract=$this->db->get('contratos',(int)$path['idcontrato'],true);
        $title=$this->db->get('lancamentos',$id,true);
        if ($title['status']==='cancelado' || $contract['status']==='cancelado') { throw new RuleViolation('Título ou contrato cancelado.'); }
        return [$title,$contract];
    }
    private function paidComponents(int $id): array
    {
        $b=$this->db->table('baixas'); $e=$this->db->table('baixa_estornos');
        $rows=$this->db->rows("SELECT b.* FROM $b b WHERE b.idlancamento=%d AND NOT EXISTS (SELECT 1 FROM $e e WHERE e.idbaixa=b.idbaixa) FOR UPDATE",[$id]);
        $totals=['principal_liquidado'=>0,'juros_pagos'=>0,'multa_paga'=>0];
        foreach ($rows as $r) { foreach ($totals as $k=>$_) { $totals[$k]+=Money::cents($r[$k]); } }
        return $totals;
    }
    public function pay(int $id,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->operations->run($key,'baixar',['idlancamento'=>$id]+$data,function() use($id,$data,$key){
            [$l,$contract]=$this->lockTitle($id);
            $amount=Money::cents($data['valor_pago']??null);if($amount<0)throw new RuleViolation('Pagamento não pode ser negativo.');
            $balance=Money::cents($l['saldo_aberto']);

            $date=Input::date($data['data_pagamento']??Input::today());
            if ($date>Input::today()) { throw new RuleViolation('Pagamento não pode estar no futuro.'); }
            $grant=ContractDiscounts::conditional($l,$date);
            if($amount===0&&($grant<=0||$grant!==$balance))throw new RuleViolation('Pagamento zero somente quando a pontualidade liquida todo o saldo.');
            if($grant>0&&$amount>=$balance-$grant){
                $balance-=$grant;if($amount>$balance)throw new RuleViolation('Pagamento excede o saldo com desconto por pontualidade.');
                $this->db->update('lancamentos',$id,['desconto_condicional_aplicado'=>Money::format(Money::cents($l['desconto_condicional_aplicado'])+$grant),'saldo_aberto'=>Money::format($balance)]);
            }else $grant=0;
            if($amount>$balance)throw new RuleViolation('Pagamento excede o saldo aberto.');
            $method=Input::text($data['forma_pagamento']??null,30);
            if (!in_array($method,['pix','boleto','cartao','dinheiro','transferencia','outro'],true)) { throw new RuleViolation('Forma de pagamento inválida.'); }
            $paid=$this->paidComponents($id);
            $interest=min($amount,Money::cents($l['juros_aplicados'])-$paid['juros_pagos']);
            $fine=min($amount-$interest,Money::cents($l['multa_aplicada'])-$paid['multa_paga']);
            $principal=$amount-$interest-$fine;
            if (min($interest,$fine,$principal)<0) { throw new RuleViolation('Composição financeira inconsistente.'); }
            $payer=isset($data['codpessoa_pagador']) ? Input::id($data['codpessoa_pagador']) : null;
            $idbaixa=$this->db->insert('baixas',['idlancamento'=>$id,'codpessoa_devedor'=>$l['codpessoa_rf_atual'],'codpessoa_pagador'=>$payer,
                'data_pagamento'=>$date,'registrado_em'=>gmdate('Y-m-d H:i:s'),'valor_pago'=>Money::format($amount),'principal_liquidado'=>Money::format($principal),
                'juros_pagos'=>Money::format($interest),'multa_paga'=>Money::format($fine),'desconto_condicional_concedido'=>Money::format($grant),'forma_pagamento'=>$method,
                'referencia_externa'=>isset($data['referencia_externa'])?Input::text($data['referencia_externa'],100):null,'idempotencia'=>$key,'ator_wp_user_id'=>get_current_user_id()]);
            $this->db->update('lancamentos',$id,['valor_baixa'=>Money::format(Money::cents($l['valor_baixa'])+$amount),'saldo_aberto'=>Money::format($balance-$amount),'status'=>$balance===$amount?'quitado':'parcial']);
            $after=$this->db->get('lancamentos',$id);
            $this->db->audit('lancamentos',$id,'baixa',$l,$after,$key);
            EnrollmentLifecycle::syncFirstPayment($this->db,(int)$contract['idcontrato'],$key);
            return ['idbaixa'=>(string)$idbaixa,'saldo_aberto'=>$after['saldo_aberto'],'status'=>$after['status']];
        }));
    }
    public function reverse(int $id,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->operations->run($key,'estornar',['idbaixa'=>$id]+$data,function() use($id,$data,$key){
            $b=$this->db->get('baixas',$id);
            [$l,$contract]=$this->lockTitle((int)$b['idlancamento']);
            $b=$this->db->get('baixas',$id,true);
            $e=$this->db->table('baixa_estornos');
            if ($this->db->row("SELECT idestorno FROM $e WHERE idbaixa=%d FOR UPDATE",[$id])) { throw new RuleViolation('Baixa já estornada.'); }
            // Version 0.1 supports full reversals only; partial reversal needs component allocation.
            if (isset($data['valor']) && Money::cents($data['valor'])!==Money::cents($b['valor_pago'])) { throw new RuleViolation('Nesta versão o estorno deve ser integral.'); }
            $amount=Money::cents($b['valor_pago']);
            $estorno=$this->db->insert('baixa_estornos',['idbaixa'=>$id,'valor'=>$b['valor_pago'],'motivo'=>Input::text($data['motivo']??null,2000),
                'efetivado_em'=>gmdate('Y-m-d H:i:s'),'ator_wp_user_id'=>get_current_user_id(),'idempotencia'=>$key]);
            $paid=Money::cents($l['valor_baixa'])-$amount;
            $this->db->update('lancamentos',(int)$l['idlancamento'],['valor_baixa'=>Money::format($paid),'saldo_aberto'=>Money::format(Money::cents($l['saldo_aberto'])+$amount+Money::cents($b['desconto_condicional_concedido'])),'desconto_condicional_aplicado'=>Money::format(Money::cents($l['desconto_condicional_aplicado'])-Money::cents($b['desconto_condicional_concedido'])),
                'status'=>$paid>0?'parcial':'aberto','codpessoa_rf_atual'=>$contract['codpessoa_rf_atual']]);
            $this->db->audit('lancamentos',(int)$l['idlancamento'],'estorno',$l,$this->db->get('lancamentos',(int)$l['idlancamento']),$key);
            EnrollmentLifecycle::syncFirstPayment($this->db,(int)$contract['idcontrato'],$key);
            return ['idestorno'=>(string)$estorno,'valor'=>$b['valor_pago']];
        }));
    }
    public function adjust(int $id,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->operations->run($key,'ajustar',['idlancamento'=>$id]+$data,function()use($id,$data,$key){
            [$l,$contract]=$this->lockTitle($id);
            $component=$data['componente']??'';
            if (!in_array($component,['desconto_incondicional','desconto_condicional_aplicado','juros_aplicados','multa_aplicada'],true)) { throw new RuleViolation('Componente não ajustável.'); }
            $delta=Money::cents($data['valor_delta']??null);
            if ($delta===0) { throw new RuleViolation('Ajuste não pode ser zero.'); }
            $values=[];
            foreach (['valor_original','desconto_incondicional','desconto_condicional_aplicado','juros_aplicados','multa_aplicada','valor_baixa'] as $field) { $values[$field]=Money::cents($l[$field]); }
            $values[$component]+=$delta;
            $net=$values['valor_original']-$values['desconto_incondicional'];
            $paid=$this->paidComponents($id);
            if (min($values)<0 || $net-$values['desconto_condicional_aplicado']<$paid['principal_liquidado'] || $values['juros_aplicados']<$paid['juros_pagos'] || $values['multa_aplicada']<$paid['multa_paga']) { throw new RuleViolation('Ajuste viola valores já liquidados ou gera componente negativo.'); }
            $balance=$net-$values['desconto_condicional_aplicado']+$values['juros_aplicados']+$values['multa_aplicada']-$values['valor_baixa'];
            if ($balance<0) { throw new RuleViolation('Ajuste gera saldo negativo.'); }
            $adjustment=$this->db->insert('lancamento_ajustes',['idlancamento'=>$id,'componente'=>$component,'valor_delta'=>Money::format($delta),
                'motivo'=>Input::text($data['motivo']??null,2000),'efetivado_em'=>gmdate('Y-m-d H:i:s'),'ator_wp_user_id'=>get_current_user_id(),'idempotencia'=>$key]);
            $this->db->update('lancamentos',$id,[$component=>Money::format($values[$component]),'valor_liquido'=>Money::format($net),'saldo_aberto'=>Money::format($balance),
                'status'=>$balance===0?'quitado':($values['valor_baixa']>0?'parcial':'aberto'),'codpessoa_rf_atual'=>$balance>0?$contract['codpessoa_rf_atual']:$l['codpessoa_rf_atual']]);
            $this->db->audit('lancamentos',$id,'ajuste',$l,$this->db->get('lancamentos',$id),$key);
            EnrollmentLifecycle::syncFirstPayment($this->db,(int)$contract['idcontrato'],$key);
            return ['idajuste'=>(string)$adjustment,'saldo_aberto'=>Money::format($balance)];
        }));
    }
    /** Caller holds the student lock. Paid amounts and overdue balances remain intact. */
    public static function cancelFutureInside(Store $db,int $enrollment,string $reason,string $key):void
    {
        $contracts=$db->rows('SELECT * FROM '.$db->table('contratos').' WHERE idmatricula=%d ORDER BY idcontrato FOR UPDATE',[$enrollment]);
        foreach($contracts as $contract){
            $titles=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND l.vencimento>=%s AND l.saldo_aberto>0 AND l.status<>\'cancelado\' ORDER BY l.idlancamento FOR UPDATE',[(int)$contract['idcontrato'],Input::today()]);
            foreach($titles as $title){if(Money::cents($title['saldo_aberto'])<=0)continue;$id=(int)$title['idlancamento'];$db->update('lancamentos',$id,['status'=>'cancelado','valor_cancelado'=>$title['saldo_aberto'],'saldo_aberto'=>'0.00','cancelado_em'=>gmdate('Y-m-d H:i:s'),'motivo_cancelamento'=>$reason]);$db->audit('lancamentos',$id,'cancelar_saldo_futuro',$title,$db->get('lancamentos',$id),$key);}
            if(!(int)$contract['parcelas_geradas']&&$contract['status']!=='cancelado'){$db->update('contratos',(int)$contract['idcontrato'],['status'=>'cancelado']);$db->audit('contratos',(int)$contract['idcontrato'],'cancelar_pendente',$contract,$db->get('contratos',(int)$contract['idcontrato']),$key);}
        }
    }
    public function edit(int $id,array $data,string $key):array
    {
        return $this->editBatch(['lancamentos'=>[['idlancamento'=>$id,'versao'=>$data['versao']??null]],'alteracoes'=>$data,'motivo'=>$data['motivo']??null],$key);
    }
    public function editBatch(array $data,string $key):array
    {
        if(!current_user_can('erp_ajustar_lancamentos'))throw new RuleViolation('Sem permissão para editar lançamentos.');
        $items=$data['lancamentos']??[];$changes=$data['alteracoes']??[];
        if(!is_array($items)||!array_is_list($items)||!count($items)||count($items)>100)throw new RuleViolation('Selecione entre 1 e 100 lançamentos.');
        if(!is_array($changes)||(!array_key_exists('vencimento',$changes)&&!array_key_exists('valor_original',$changes)))throw new RuleViolation('Informe o vencimento ou o valor original.');
        $reason=Input::text($data['motivo']??null,2000);
        $due=array_key_exists('vencimento',$changes)?Input::date($changes['vencimento']):null;
        $original=array_key_exists('valor_original',$changes)?Money::positive($changes['valor_original']):null;
        $ids=[];foreach($items as $item){if(!is_array($item))throw new RuleViolation('Seleção inválida.');$id=Input::id($item['idlancamento']??null);if(isset($ids[$id]))throw new RuleViolation('Lançamento duplicado na seleção.');$ids[$id]=$item['versao']??null;}ksort($ids);
        return $this->db->atomic(fn()=>$this->operations->run($key,'editar_lancamentos',$data,function()use($ids,$due,$original,$reason,$key){
            // Acquire all students in a stable order before any contract/title lock.
            $students=[];$contracts=[];
            foreach($ids as $id=>$_){$path=$this->db->row('SELECT m.idaluno,c.idcontrato FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$this->db->table('contratos').' c ON c.idcontrato=p.idcontrato JOIN '.$this->db->table('matriculas').' m ON m.idmatricula=c.idmatricula WHERE l.idlancamento=%d',[$id]);if(!$path)throw new RuleViolation('Lançamento não encontrado.');$students[(int)$path['idaluno']]=true;$contracts[(int)$path['idcontrato']]=true;}
            ksort($students);ksort($contracts);foreach($students as $id=>$_)$this->db->get('alunos',$id,true);foreach($contracts as $id=>$_)$this->db->get('contratos',$id,true);
            $result=[];
            foreach($ids as $id=>$version){[$title,$contract]=$this->lockTitle($id);if((string)$version!==(string)$title['versao'])throw new RuleViolation('Lançamento alterado. Atualize a listagem antes de salvar.');if(Money::cents($title['saldo_aberto'])===0)throw new RuleViolation('Lançamentos baixados não podem ser editados.');
                $update=[];if($due!==null)$update['vencimento']=$due;
                if($original!==null){$net=$original-Money::cents($title['desconto_incondicional']);$paid=$this->paidComponents($id);if($net-Money::cents($title['desconto_condicional_aplicado'])<$paid['principal_liquidado'])throw new RuleViolation('Valor original inferior aos descontos ou ao principal já baixado.');$balance=$net-Money::cents($title['desconto_condicional_aplicado'])+Money::cents($title['juros_aplicados'])+Money::cents($title['multa_aplicada'])-Money::cents($title['valor_baixa']);if($balance<0)throw new RuleViolation('A edição gera saldo negativo.');$update+=['valor_original'=>Money::format($original),'valor_liquido'=>Money::format($net),'saldo_aberto'=>Money::format($balance),'status'=>$balance===0?'quitado':(Money::cents($title['valor_baixa'])>0?'parcial':'aberto')];}
                $this->db->update('lancamentos',$id,$update);$after=$this->db->get('lancamentos',$id);$this->db->audit('lancamentos',$id,'editar',['dados'=>$title],['dados'=>$after,'motivo'=>$reason],$key);EnrollmentLifecycle::syncFirstPayment($this->db,(int)$contract['idcontrato'],$key);$result[]=\EducacionalERP\Domain\FinancialStatus::present($after);
            }
            return ['alterados'=>count($result),'lancamentos'=>$result];
        }));
    }
    public function changeGuardian(int $id,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->operations->run($key,'trocar_responsavel',['idaluno'=>$id]+$data,function()use($id,$data,$key){
            $this->db->get('alunos',$id,true);
            $new=Input::id($data['codpessoa_nova']??null);
            if (!(int)$this->db->get('pessoas',$new)['ativo']) { throw new RuleViolation('Responsável inativo.'); }
            $v=$this->db->table('aluno_responsaveis');
            $links=$this->db->rows("SELECT * FROM $v WHERE idaluno=%d AND fim_vigencia IS NULL ORDER BY idvinculo FOR UPDATE",[$id]);
            $current=array_values(array_filter($links,fn($r)=>(int)$r['responsavel_financeiro']===1));
            if (count($current)!==1) { throw new RuleViolation('Aluno deve ter exatamente um responsável financeiro vigente.'); }
            $old=(int)$current[0]['codpessoa_responsavel'];
            if ($old===$new) { throw new RuleViolation('Responsável sem alteração.'); }
            $target=array_values(array_filter($links,fn($r)=>(int)$r['codpessoa_responsavel']===$new));
            if (count($target)!==1) { throw new RuleViolation('Vincule a nova pessoa ao aluno antes da troca.'); }
            $now=gmdate('Y-m-d H:i:s');
            foreach ([$current[0],$target[0]] as $link) {
                $this->db->update('aluno_responsaveis',(int)$link['idvinculo'],['fim_vigencia'=>$now]);
                $this->db->insert('aluno_responsaveis',['idaluno'=>$id,'codpessoa_responsavel'=>$link['codpessoa_responsavel'],'parentesco'=>$link['parentesco'],
                    'responsavel_academico'=>$link['responsavel_academico'],'responsavel_financeiro'=>(int)$link['codpessoa_responsavel']===$new?1:0,
                    'pode_rematricular'=>$link['pode_rematricular'],'inicio_vigencia'=>$now]);
            }
            $change=$this->db->insert('trocas_responsavel',['idaluno'=>$id,'codpessoa_anterior'=>$old,'codpessoa_nova'=>$new,'efetivado_em'=>$now,
                'motivo'=>Input::text($data['motivo']??null,2000),'ator_wp_user_id'=>get_current_user_id(),'idempotencia'=>$key]);
            $c=$this->db->table('contratos'); $m=$this->db->table('matriculas'); $p=$this->db->table('parcelas'); $l=$this->db->table('lancamentos');
            $contracts=$this->db->rows("SELECT c.* FROM $c c JOIN $m m ON m.idmatricula=c.idmatricula WHERE m.idaluno=%d AND c.status <> 'cancelado' ORDER BY c.idcontrato FOR UPDATE",[$id]);
            $count=0; $total=0;
            foreach ($contracts as $contract) {
                $titles=$this->db->rows("SELECT l.* FROM $l l JOIN $p p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND l.saldo_aberto>0 AND l.status IN ('aberto','parcial') ORDER BY l.idlancamento FOR UPDATE",[(int)$contract['idcontrato']]);
                if ($contract['status']!=='ativo' && !$titles) { continue; }
                $this->db->insert('troca_contratos',['idtroca'=>$change,'idcontrato'=>$contract['idcontrato'],'codpessoa_anterior'=>$contract['codpessoa_rf_atual'],'codpessoa_nova'=>$new]);
                $this->db->update('contratos',(int)$contract['idcontrato'],['codpessoa_rf_atual'=>$new]);
                foreach ($titles as $title) {
                    $this->db->insert('troca_lancamentos',['idtroca'=>$change,'idlancamento'=>$title['idlancamento'],'codpessoa_anterior'=>$title['codpessoa_rf_atual'],'codpessoa_nova'=>$new,'saldo_transferido'=>$title['saldo_aberto']]);
                    $this->db->update('lancamentos',(int)$title['idlancamento'],['codpessoa_rf_atual'=>$new]);
                    $total+=Money::cents($title['saldo_aberto']); $count++;
                }
            }
            $accounts=new Accounts($this->db);$accounts->sync($old);$accounts->sync($new);
            $result=['idtroca'=>(string)$change,'lancamentos_transferidos'=>$count,'saldo_transferido'=>Money::format($total)];
            $this->db->audit('alunos',$id,'trocar_responsavel',['codpessoa_rf'=>$old],$result+['codpessoa_rf'=>$new],$key);
            return $result;
        }));
    }
}
