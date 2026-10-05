<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,EnrollmentStatus};
use EducacionalERP\Infrastructure\WordPress\Access;
final class EnrollmentLifecycle
{
    private AcademicService $academic;
    public function __construct(private Store $db,private Operations $ops){$this->academic=new AcademicService($db,$ops);}
    private function lock(int $id,array $data):array
    {
        $row=$this->db->get('matriculas',$id);$this->db->get('alunos',(int)$row['idaluno'],true);$row=$this->db->get('matriculas',$id,true);
        if((string)($data['versao']??'')!==(string)$row['versao'])throw new RuleViolation('Matrícula alterada. Atualize a ficha.');return $row;
    }
    public function history(int $id):array
    {
        if(!current_user_can('erp_gerenciar_academico'))throw new RuleViolation('Sem permissão para consultar o histórico.');$this->db->get('matriculas',$id);
        $rows=$this->db->rows('SELECT * FROM '.$this->db->table('matricula_movimentacoes').' WHERE idmatricula=%d ORDER BY registrado_em DESC,idmovimentacao DESC',[$id]);
        foreach($rows as &$row){$u=get_userdata((int)$row['ator_wp_user_id']);$row['autor']=$u?$u->display_name:'Usuário removido (#'.$row['ator_wp_user_id'].')';$row['data_hora']=wp_date('d/m/Y H:i:s',strtotime($row['registrado_em'].' UTC'),wp_timezone());if(!Access::isAdmin())unset($row['documento_token'],$row['documento_nome'],$row['documento_mime']);}unset($row);return $rows;
    }
    public function external(int $id,array $d,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,'transferencia_externa',['id'=>$id]+$d,function()use($id,$d,$key){
            $m=$this->lock($id,$d);if(!EnrollmentStatus::open($m['status']))throw new RuleViolation('Matrícula não permite transferência externa.');
            $period=$this->db->get('periodos_letivos',(int)$m['codperiodo']);$day=Input::date($d['data_solicitacao']??null);if($day>Input::today()||$day<$period['data_inicio']||$day>$period['data_fim'])throw new RuleViolation('A solicitação deve estar dentro do período letivo e não pode ser futura.');
            $person=null;if(($d['solicitante_tipo']??'')==='responsavel'){
                $person=Input::id($d['solicitante_codpessoa']??null);$link=$this->db->row('SELECT idvinculo FROM '.$this->db->table('aluno_responsaveis').' WHERE idaluno=%d AND codpessoa_responsavel=%d AND fim_vigencia IS NULL AND inicio_vigencia<=UTC_TIMESTAMP() FOR UPDATE',[(int)$m['idaluno'],$person]);if(!$link)throw new RuleViolation('Selecione um responsável vigente vinculado ao aluno.');$name=$this->db->get('pessoas',$person)['nome'];
            }elseif(($d['solicitante_tipo']??'')==='outro')$name=Input::text($d['solicitante_nome']??null,191);else throw new RuleViolation('Selecione o tipo de solicitante.');
            $school=Input::text($d['colegio_destino']??null,191);$doc=[];if(!empty($d['documento_token']))$doc=(new \EducacionalERP\Infrastructure\WordPress\PrivateDocuments())->validate((string)$d['documento_token']);
            FinanceService::cancelFutureInside($this->db,$id,'Transferência externa',$key);
            $this->changeInside($m,'transferencia_externa','Transferência externa para '.$school,$key);
            $now=gmdate('Y-m-d H:i:s');$movement=$this->db->insert('matricula_movimentacoes',['idmatricula'=>$id,'tipo'=>'solicitacao_transferencia','status_anterior'=>$m['status'],'status_novo'=>'transferencia_externa','efetivado_em'=>$now,'registrado_em'=>$now,'motivo'=>'Solicitação de transferência externa','ator_wp_user_id'=>get_current_user_id(),'solicitante_codpessoa'=>$person,'solicitante_nome'=>\EducacionalERP\Domain\CadastroText::upper($name),'data_solicitacao'=>$day,'colegio_destino'=>\EducacionalERP\Domain\CadastroText::upper($school)]+$doc);
            $this->db->audit('matriculas',$id,'solicitacao_transferencia',null,['idmovimentacao'=>$movement,'solicitante'=>$name,'colegio_destino'=>$school,'data_solicitacao'=>$day,'documento'=>!empty($doc)],$key);return ['idmatricula'=>(string)$id,'status'=>'transferencia_externa'];
        }));
    }
    public function outcome(int $id,array $d,string $key):array
    {
        Access::requireAdmin();$status=$d['status']??'';if(!in_array($status,['aprovado','reprovado'],true))throw new RuleViolation('Selecione Aprovado ou Reprovado.');
        return $this->db->atomic(fn()=>$this->ops->run($key,'resultado_letivo',['id'=>$id]+$d,function()use($id,$d,$key,$status){
            $m=$this->lock($id,$d);if(!in_array($m['status'],[...EnrollmentStatus::OPEN,'aprovado','reprovado'],true))throw new RuleViolation('Esta matrícula não permite resultado letivo.');if($m['status']===$status)return ['idmatricula'=>(string)$id,'status'=>$status,'rematricula_substituta'=>null];
            $replacement=null;
            if($status==='reprovado'){
                $period=$this->db->get('periodos_letivos',(int)$m['codperiodo']);$next=(int)($period['codperiodo_proximo']??0);
                $future=$this->db->rows('SELECT * FROM '.$this->db->table('matriculas').' WHERE idmatricula_origem=%d AND ativo_unico=1 ORDER BY idmatricula FOR UPDATE',[$id]);
                if(count($future)>1)throw new RuleViolation('Mais de uma rematrícula vinculada. Regularize a ficha antes de registrar a reprovação.');
                if($future){$f=$future[0];if(!Coligadas::destinationPeriod($this->db,$period,$this->db->get('periodos_letivos',(int)$f['codperiodo']))||!EnrollmentStatus::open($f['status']))throw new RuleViolation('Rematrícula posterior incompatível com o próximo período.');
                    $source=$this->db->get('turmas',(int)$m['idturma_atual']);$target=$this->db->row('SELECT * FROM '.$this->db->table('turmas').' WHERE codperiodo=%d AND idcurso=%d AND idturno=%d AND codigo=%s FOR UPDATE',[$next,(int)$m['idcurso'],(int)$source['idturno'],$source['codigo']]);
                    if(!$target)throw new RuleViolation('Cadastre ou copie a turma atual (mesmo código, curso e turno) no próximo período antes de reprovar.');
                    $this->academic->planForClass((int)$target['idturma']);$contracts=$this->db->rows('SELECT * FROM '.$this->db->table('contratos').' WHERE idmatricula=%d ORDER BY idcontrato FOR UPDATE',[(int)$f['idmatricula']]);
                    $cross=(int)$f['codcoligada']!==(int)$m['codcoligada'];
                    $this->cancelInside($f,'Rematrícula cancelada por reprovação no período anterior',$key,$cross);
                    $replacement=$this->academic->placeInside((int)$m['idaluno'],$next,(int)$target['idturma'],$key);$newId=(int)$replacement['idmatricula'];$this->db->update('matriculas',$newId,['idmatricula_origem'=>$id,'origem'=>'reprovacao']);
                    if($cross&&$contracts){$first=$contracts[0];$replacement+=$this->academic->createContractInside($newId,['quantidade_parcelas'=>$first['quantidade_parcelas'],'primeiro_vencimento'=>$first['primeiro_vencimento'],'termo'=>'Contrato de rematrícula por reprovação na coligada de origem.'],$key,true);}
                    if(!$cross) foreach($contracts as $contract){$this->db->update('contratos',(int)$contract['idcontrato'],['idmatricula'=>$newId]);$this->db->audit('contratos',(int)$contract['idcontrato'],'reassociar_reprovacao',$contract,['idmatricula'=>$newId,'condicoes_financeiras_preservadas'=>true],$key);}
                    $links=$cross?[]:$this->db->rows('SELECT * FROM '.$this->db->table('aluno_periodos').' WHERE idmatricula=%d FOR UPDATE',[(int)$f['idmatricula']]);foreach($links as $link)$this->db->update('aluno_periodos',(int)$link['idvinculoperiodo'],['idmatricula'=>$newId]);
                    $this->academic->movement($newId,null,(int)$target['idturma'],'rematricula_reprovacao','Repetição automática; matrícula anterior #'.$f['idmatricula'],null,'reservado');
                    if(!$cross)foreach($contracts as $contract)self::syncFirstPayment($this->db,(int)$contract['idcontrato'],$key);
                }
            }
            $this->changeInside($m,$status,'Resultado do período letivo: '.$status,$key);return ['idmatricula'=>(string)$id,'status'=>$status,'rematricula_substituta'=>$replacement];
        }));
    }
    public function cancel(int $id,array $d,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,'cancelar_rematricula',['id'=>$id]+$d,function()use($id,$d,$key){$m=$this->lock($id,$d);if(!EnrollmentStatus::open($m['status']))throw new RuleViolation('Matrícula não permite cancelamento.');$this->cancelInside($m,'Cancelamento de matrícula solicitado pela escola',$key);return ['idmatricula'=>(string)$id,'status'=>'cancelada'];}));
    }
    private function cancelInside(array $m,string $reason,string $key,bool $stopPending=true):void
    {
        $this->changeInside($m,'cancelada',$reason,$key);$r=$this->db->table('rematriculas');foreach($this->db->rows("SELECT * FROM $r WHERE idmatricula_destino=%d AND ativo_unico=1 FOR UPDATE",[(int)$m['idmatricula']]) as $row){$this->db->update('rematriculas',(int)$row['idrematricula'],['status'=>'cancelada','ativo_unico'=>null]);$this->db->audit('rematriculas',(int)$row['idrematricula'],'cancelar',$row,['status'=>'cancelada'],$key);}
        if($stopPending)FinanceService::cancelFutureInside($this->db,(int)$m['idmatricula'],$reason,$key);
        if($stopPending)foreach($this->db->rows('SELECT * FROM '.$this->db->table('contratos').' WHERE idmatricula=%d AND parcelas_geradas=0 AND status<>\'cancelado\' FOR UPDATE',[(int)$m['idmatricula']]) as $contract){$this->db->update('contratos',(int)$contract['idcontrato'],['status'=>'cancelado']);$this->db->audit('contratos',(int)$contract['idcontrato'],'cancelar_pendente',$contract,['status'=>'cancelado'],$key);}
    }
    private function changeInside(array $m,string $status,string $reason,string $key):void
    {
        $id=(int)$m['idmatricula'];$this->db->update('matriculas',$id,['status'=>$status,'ativo_unico'=>$status==='cancelada'?null:1,'encerrado_em'=>gmdate('Y-m-d H:i:s'),'motivo_encerramento'=>$reason]);$this->academic->movement($id,(int)$m['idturma_atual'],(int)$m['idturma_atual'],'situacao',$reason,$m['status'],$status);$this->db->audit('matriculas',$id,'situacao',$m,$this->db->get('matriculas',$id),$key);
    }
    /** Called within payment/reversal transactions after student has been locked. Full first installment required. */
    public static function syncFirstPayment(Store $db,int $contractId,string $key):void
    {
        $contract=$db->get('contratos',$contractId);$m=$db->get('matriculas',(int)$contract['idmatricula'],true);if(!EnrollmentStatus::open($m['status']))return;
        $titles=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND p.numero=1 FOR UPDATE',[$contractId]);
        $paid=(bool)$titles;foreach($titles as $l)if($l['status']!=='quitado'||\EducacionalERP\Domain\Money::cents($l['saldo_aberto'])!==0||\EducacionalERP\Domain\Money::cents($l['valor_baixa'])<=0)$paid=false;
        $status=$paid?'cursando':'reservado';if($m['status']===$status)return;$db->update('matriculas',(int)$m['idmatricula'],['status'=>$status]);(new AcademicService($db,new Operations($db)))->movement((int)$m['idmatricula'],(int)$m['idturma_atual'],(int)$m['idturma_atual'],'situacao_financeiro',$paid?'Primeira parcela baixada integralmente':'Baixa da primeira parcela estornada',$m['status'],$status);$db->audit('matriculas',(int)$m['idmatricula'],'situacao_financeiro',$m,['status'=>$status,'idcontrato'=>$contractId],$key);
    }
}
