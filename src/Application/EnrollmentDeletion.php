<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;

/** Remove from operation while keeping the parent required by financial and integration history. */
final class EnrollmentDeletion
{
    public function __construct(private Database $db,private Operations $ops) {}
    public function remove(int $id,array $data,string $key):array
    {
        Access::requireAdmin();
        if(array_diff(array_keys($data),['versao','motivo']))throw new RuleViolation('Dados de exclusão inválidos.');
        $reason=Input::text($data['motivo']??null,500);$version=Input::id($data['versao']??null);
        return $this->db->atomic(fn()=>$this->ops->run($key,'excluir',['tipo'=>'matriculas','id'=>$id]+$data,function()use($id,$version,$reason,$key){
            $initial=$this->db->get('matriculas',$id);
            if((int)$initial['codcoligada']!==Coligadas::current())throw new RuleViolation('Matrícula de outra coligada.');
            $this->db->get('alunos',(int)$initial['idaluno'],true);$row=$this->db->get('matriculas',$id,true);
            if(!empty($row['excluido_em']))throw new RuleViolation('Matrícula já excluída. Atualize a listagem.');
            if((int)$row['versao']!==$version)throw new RuleViolation('Registro alterado. Atualize a tela antes de excluir.');
            if(!empty($row['idmatricula_origem']))throw new RuleViolation('Matrícula com origem em rematrícula: preserve o histórico e use o cancelamento.');
            $movements=$this->db->rows('SELECT * FROM '.$this->db->table('matricula_movimentacoes').' WHERE idmatricula=%d ORDER BY idmovimentacao FOR UPDATE',[$id]);
            foreach($movements as $movement)if(!in_array($movement['tipo'],['matricula','situacao_financeiro','migracao_situacao'],true))throw new RuleViolation('Matrícula com histórico de movimentação acadêmica: exclusão bloqueada.');
            $contracts=$this->db->rows('SELECT * FROM '.$this->db->table('contratos').' WHERE idmatricula=%d ORDER BY idcontrato FOR UPDATE',[$id]);
            foreach($contracts as $contract)if(empty($contract['excluido_em']))throw new RuleViolation('Exclua primeiro os contratos financeiros desta matrícula em Contratos.');
            if($this->db->row('SELECT l.idlancamento FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$this->db->table('contratos').' c ON c.idcontrato=p.idcontrato WHERE c.idmatricula=%d AND l.excluido_em IS NULL LIMIT 1 FOR UPDATE',[$id]))throw new RuleViolation('Ainda existem lançamentos financeiros disponíveis nesta matrícula. Exclua-os na Gestão financeira.');
            // Preserve the former dependency protection for renewals and any future academic children.
            foreach($this->db->schema() as $child=>$meta)foreach($meta['fks'] as $fk){
                if($fk['target']!=='matriculas'||in_array($child,['contratos','matricula_movimentacoes','aluno_periodos','educbank_fila'],true))continue;
                $where=[];$args=[];foreach($fk['columns'] as $i=>$column){$where[]="$column=%s";$args[]=$row[$fk['references'][$i]];}
                if($this->db->row('SELECT '.$meta['pk'][0].' FROM '.$this->db->table($child).' WHERE '.implode(' AND ',$where).' LIMIT 1 FOR UPDATE',$args))throw new RuleViolation('Matrícula com '.($child==='matriculas'?'matrículas posteriores':($child==='rematriculas'?'rematrículas':str_replace('_',' ',$child))).' vinculadas: exclusão bloqueada.');
            }
            foreach($this->db->rows('SELECT * FROM '.$this->db->table('aluno_periodos').' WHERE idmatricula=%d ORDER BY idvinculoperiodo FOR UPDATE',[$id]) as $link){
                $this->db->update('aluno_periodos',(int)$link['idvinculoperiodo'],['idmatricula'=>null,'status'=>'aguardando_turma']);$this->db->audit('aluno_periodos',(int)$link['idvinculoperiodo'],'reabrir_apos_exclusao',$link,['motivo'=>$reason],$key);
            }
            $when=gmdate('Y-m-d H:i:s');
            EducbankOutbox::without(fn()=>$this->db->update('matriculas',$id,['status'=>'cancelada','ativo_unico'=>null,'encerrado_em'=>$row['encerrado_em']??$when,'motivo_encerramento'=>$row['motivo_encerramento']??$reason,'excluido_em'=>$when,'excluido_por_wp_user_id'=>get_current_user_id(),'motivo_exclusao'=>$reason]));
            foreach($this->db->rows('SELECT * FROM '.$this->db->table('educbank_fila').' WHERE idmatricula=%d ORDER BY idintegracao FOR UPDATE',[$id]) as $job)self::ignoreJob($this->db,$job);
            $this->db->audit('matriculas',$id,'excluir',$row,['dados'=>$this->db->get('matriculas',$id),'motivo'=>$reason,'movimentos_preservados'=>array_column($movements,'idmovimentacao'),'contratos_preservados'=>array_column($contracts,'idcontrato')],$key);
            return ['excluido'=>true,'id'=>(string)$id,'preservado_no_historico'=>true];
        }));
    }
    public static function excludedJob(Store $db,array $job):bool
    {
        return !empty($job['idmatricula'])&&!empty($db->get('matriculas',(int)$job['idmatricula'])['excluido_em']);
    }
    public static function ignoreJob(Store $db,array $job):void
    {
        $db->query('UPDATE '.$db->table('educbank_fila')." SET status='ignorado',geracao=geracao+1,proxima_tentativa=NULL,erro=%s,versao=versao+1 WHERE idintegracao=%d",['Matrícula excluída no ERP. Os registros Educbank são preservados para conferência.',(int)$job['idintegracao']]);
        $db->query('UPDATE '.$db->table('educbank_retornos')." SET status='processado',erro=NULL,proxima_tentativa=NULL,processado_em=%s,resultado_json=%s,versao=versao+1 WHERE idintegracao=%d AND status IN ('pendente','erro','revisao')",[gmdate('Y-m-d H:i:s'),wp_json_encode(['acao'=>'matricula_excluida','ignorado'=>true]),(int)$job['idintegracao']]);
    }
}
