<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Store,Input,Money,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;

final class Contracts
{
    public function __construct(private Store $db,private Operations $ops) {}
    public function listing(array $query):array
    {
        Access::requireAdmin();$page=max(1,min(100000,(int)($query['page']??1)));$period=SchoolSettings::resolve($query['codperiodo']??null);
        $from=' FROM '.$this->db->table('contratos').' c JOIN '.$this->db->table('matriculas').' m ON m.idmatricula=c.idmatricula JOIN '.$this->db->table('alunos').' a ON a.idaluno=m.idaluno JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=a.codpessoa JOIN '.$this->db->table('turmas').' t ON t.idturma=m.idturma_atual JOIN '.$this->db->table('periodos_letivos').' pl ON pl.codperiodo=m.codperiodo WHERE c.codcoligada=%d AND c.excluido_em IS NULL';$args=[Coligadas::current()];
        if($period){$from.=' AND m.codperiodo=%d';$args[]=$period;}
        $search=trim((string)($query['search']??''));if(strlen($search)>191)throw new RuleViolation('Busca muito longa.');
        if($search!==''){$from.=' AND (c.numero LIKE %s OR p.nome LIKE %s OR a.ra LIKE %s)';$term='%'.addcslashes($search,'_%\\').'%';array_push($args,$term,$term,$term);}
        if(!empty($query['idaluno'])){$from.=' AND m.idaluno=%d';$args[]=Input::id($query['idaluno']);}
        $status=(string)($query['status']??'');if($status!==''&&!in_array($status,['ativo','cancelado','pendente'],true))throw new RuleViolation('Situação de contrato inválida.');
        if($status==='pendente')$from.=" AND c.status='ativo' AND c.parcelas_geradas=0";elseif($status!==''){$from.=' AND c.status=%s';$args[]=$status;}
        $total=(int)$this->db->row('SELECT COUNT(*) AS n'.$from,$args)['n'];
        return ['items'=>$this->db->rows('SELECT c.idcontrato,c.numero,c.versao,c.status,c.parcelas_geradas,c.valor_liquido_total,c.quantidade_parcelas,c.primeiro_vencimento,a.idaluno,a.ra,p.nome AS aluno,t.nome AS turma,pl.codigo AS periodo'.$from.' ORDER BY c.idcontrato DESC LIMIT 20 OFFSET %d',[...$args,($page-1)*20]),'total'=>$total,'page'=>$page];
    }
    private function contract(int $id,bool $lock=false):array
    {
        $c=$this->db->get('contratos',$id);
        if((int)$c['codcoligada']!==Coligadas::current()||!empty($c['excluido_em']))throw new RuleViolation('Contrato indisponível nesta coligada.');
        if($lock){$m=$this->db->get('matriculas',(int)$c['idmatricula']);$this->db->get('alunos',(int)$m['idaluno'],true);$this->db->get('matriculas',(int)$m['idmatricula'],true);$c=$this->db->get('contratos',$id,true);if(!empty($c['excluido_em']))throw new RuleViolation('Contrato já excluído.');}return $c;
    }
    public function detail(int $id):array
    {
        Access::requireAdmin();$c=$this->contract($id);$m=$this->db->get('matriculas',(int)$c['idmatricula']);$a=$this->db->get('alunos',(int)$m['idaluno']);$p=$this->db->get('pessoas',(int)$a['codpessoa']);$rf=$this->db->get('pessoas',(int)$c['codpessoa_rf_atual']);
        $c['aluno']=$p['nome'];$c['ra']=$a['ra'];$c['idaluno']=$a['idaluno'];$c['responsavel']=$rf['nome'];$c['periodo']=$this->db->get('periodos_letivos',(int)$m['codperiodo'])['codigo'];$c['turma']=$this->db->get('turmas',(int)$m['idturma_atual'])['nome'];
        $c['total_parcelas']=(int)$this->db->row('SELECT COUNT(*) AS n FROM '.$this->db->table('parcelas').' WHERE idcontrato=%d',[$id])['n'];
        $c['lancamentos_ativos']=(int)$this->db->row('SELECT COUNT(*) AS n FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND l.excluido_em IS NULL',[$id])['n'];return $c;
    }
    public function update(int $id,array $data,string $key):array
    {
        Access::requireAdmin();
        if(array_diff(array_keys($data),['versao','motivo','numero','data_contrato','termo','versao_termo','idmodelo','modelo_versao','valor_original_total','desconto_incondicional_total','quantidade_parcelas','primeiro_vencimento']))throw new RuleViolation('Campo de contrato inválido.');
        $reason=Input::text($data['motivo']??null,2000);$version=Input::id($data['versao']??null);
        return $this->db->atomic(fn()=>$this->ops->run($key,'editar_contrato',['idcontrato'=>$id]+$data,function()use($id,$data,$version,$reason,$key){
            $c=$this->contract($id,true);if((int)$c['versao']!==$version)throw new RuleViolation('Contrato alterado. Reabra a edição.');
            if($c['status']!=='ativo')throw new RuleViolation('Somente contratos ativos podem ser editados.');$fields=[];
            foreach(['numero'=>100,'versao_termo'=>40] as $field=>$max)if(isset($data[$field]))$fields[$field]=Input::text($data[$field],$max);
            if(isset($data['data_contrato']))$fields['data_contrato']=Input::date($data['data_contrato']);
            if(isset($fields['numero'])&&$this->db->row('SELECT idcontrato FROM '.$this->db->table('contratos').' WHERE codcoligada=%d AND numero=%s AND idcontrato<>%d',[$c['codcoligada'],$fields['numero'],$id]))throw new RuleViolation('Número de contrato já utilizado.');
            $financial=['valor_original_total','desconto_incondicional_total','quantidade_parcelas','primeiro_vencimento'];$changed=false;
            foreach($financial as $field)if(isset($data[$field])&&(string)$data[$field]!== (string)$c[$field])$changed=true;
            if($changed){
                if((int)$c['parcelas_geradas']||$this->db->row('SELECT idparcela FROM '.$this->db->table('parcelas').' WHERE idcontrato=%d LIMIT 1 FOR UPDATE',[$id]))throw new RuleViolation('As parcelas já foram geradas. Ajuste os lançamentos na Gestão financeira.');
                $original=Money::positive($data['valor_original_total']??$c['valor_original_total']);$discount=Money::cents($data['desconto_incondicional_total']??$c['desconto_incondicional_total']);$count=Input::id($data['quantidade_parcelas']??$c['quantidade_parcelas']);
                if($discount<0||$discount>=$original)throw new RuleViolation('Bolsa deve ser menor que o valor original e não pode ser negativa.');Money::split($original-$discount,$count);
                $fields+=['valor_original_total'=>Money::format($original),'desconto_incondicional_total'=>Money::format($discount),'valor_liquido_total'=>Money::format($original-$discount),'quantidade_parcelas'=>$count,'primeiro_vencimento'=>Input::date($data['primeiro_vencimento']??$c['primeiro_vencimento'])];
            }
            if(isset($data['termo'])&&$data['termo']!==$c['termo_snapshot']&&($fields['versao_termo']??$c['versao_termo'])===$c['versao_termo']&&empty($data['idmodelo']))throw new RuleViolation('Informe uma nova versão do termo ao alterar o texto.');
            if(!$fields&&!isset($data['termo'])&&empty($data['idmodelo']))throw new RuleViolation('Informe os campos que deseja editar.');
            if($fields)$this->db->update('contratos',$id,$fields);
            if((array_key_exists('termo',$data)&&$data['termo']!==$c['termo_snapshot'])||!empty($data['idmodelo']))(new ContractTemplates($this->db,$this->ops))->applyInside($id,$data);
            $after=$this->db->get('contratos',$id);$this->db->audit('contratos',$id,'editar',$c,['dados'=>$after,'motivo'=>$reason],$key);return $after;
        }));
    }
    public function remove(int $id,array $data,string $key):array
    {
        Access::requireAdmin();if(array_diff(array_keys($data),['versao','motivo','confirmado']))throw new RuleViolation('Dados de exclusão inválidos.');
        if(($data['confirmado']??false)!==true)throw new RuleViolation('Confirme a exclusão do contrato.');$reason=Input::text($data['motivo']??null,2000);$version=Input::id($data['versao']??null);
        return $this->db->atomic(fn()=>$this->ops->run($key,'excluir_contrato',['idcontrato'=>$id]+$data,function()use($id,$reason,$version,$key){
            $c=$this->contract($id,true);if((int)$c['versao']!==$version)throw new RuleViolation('Contrato alterado. Atualize a tela.');
            if($this->db->row('SELECT l.idlancamento FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND l.excluido_em IS NULL LIMIT 1 FOR UPDATE',[$id]))throw new RuleViolation('Exclua primeiro os lançamentos deste contrato na Gestão financeira. Há lançamentos vinculados ainda disponíveis.');
            $this->db->update('contratos',$id,['status'=>'cancelado','excluido_em'=>gmdate('Y-m-d H:i:s'),'excluido_por_wp_user_id'=>get_current_user_id(),'motivo_exclusao'=>$reason]);
            $this->db->audit('contratos',$id,'excluir',$c,$this->db->get('contratos',$id),$key);return ['excluido'=>true,'idcontrato'=>(string)$id];
        }));
    }
}
