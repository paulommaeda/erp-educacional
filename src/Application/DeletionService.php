<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Input,RuleViolation};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts};
final class DeletionService
{
    public const TYPES=['estados_civis','planos_pagamento','pessoas','alunos','periodos_letivos','cursos','turnos','turmas','matriculas','aluno_periodos','aluno_responsaveis'];
    public function __construct(private Database $db,private Operations $ops) {}
    public function remove(string $type,int $id,array $data,string $key):array
    {
        Access::requireAdmin();if(!in_array($type,self::TYPES,true))throw new RuleViolation('Cadastro não permite exclusão.');
        $reason=Input::text($data['motivo']??null,500);$version=Input::id($data['versao']??null);
        $lock='ederp_period_'.substr(hash('sha256',$this->db->table('periodos_letivos')),0,32);
        if($type==='periodos_letivos'&&(int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Período em uso por outra operação. Tente novamente.');
        try{return $this->db->atomic(fn()=>$this->ops->run($key,'excluir',['tipo'=>$type,'id'=>$id]+$data,function()use($type,$id,$reason,$version,$key){
            $initial=$this->db->get($type,$id);
            if(isset($initial['idaluno'])&&$type!=='alunos')$this->db->get('alunos',(int)$initial['idaluno'],true);
            $row=$this->db->get($type,$id,true);
            if((int)$row['versao']!==$version)throw new RuleViolation('Registro alterado. Atualize a tela antes de excluir.');
            if($type==='periodos_letivos'&&SchoolSettings::current()===$id)throw new RuleViolation('Este é o período letivo atual. Selecione outro nas Configurações antes de excluir.');
            $skip=[];$children=[];
            if($type==='pessoas')$skip=['pessoa_usuarios'];
            if($type==='aluno_responsaveis'){
                if($row['fim_vigencia']!==null)throw new RuleViolation('Vínculo histórico preservado.');
                if($this->db->row('SELECT idmatricula FROM '.$this->db->table('matriculas').' WHERE idaluno=%d LIMIT 1',[(int)$row['idaluno']]))throw new RuleViolation('Aluno com matrícula: preserve o vínculo e use a edição de atribuições ou troca de responsável.');
            }
            if($type==='aluno_periodos'&&$row['idmatricula']!==null)throw new RuleViolation('O período possui uma matrícula. Exclua a matrícula primeiro, se permitido.');
            if($type==='matriculas'){
                $mov=$this->db->rows('SELECT * FROM '.$this->db->table('matricula_movimentacoes').' WHERE idmatricula=%d FOR UPDATE',[$id]);
                foreach($mov as $item)if($item['tipo']!=='matricula')throw new RuleViolation('Matrícula com histórico de movimentação: exclusão bloqueada.');
                $skip=['matricula_movimentacoes','aluno_periodos'];$children=$mov;
            }
            $labels=['alunos'=>'alunos','aluno_responsaveis'=>'vínculos com responsáveis','aluno_periodos'=>'vínculos a períodos','turmas'=>'turmas','matriculas'=>'matrículas','matricula_movimentacoes'=>'histórico de movimentações','contratos'=>'contratos financeiros','oferta_turmas'=>'ofertas de rematrícula','ofertas_rematricula'=>'ofertas de rematrícula','rematriculas'=>'rematrículas'];
            foreach($this->db->schema() as $child=>$meta)foreach($meta['fks'] as $fk){
                if($fk['target']!==$type||in_array($child,$skip,true))continue;
                $where=[];$args=[];foreach($fk['columns'] as $i=>$column){$where[]="$column=%s";$args[]=$row[$fk['references'][$i]];}
                if($this->db->row('SELECT '.$meta['pk'][0].' FROM '.$this->db->table($child).' WHERE '.implode(' AND ',$where).' LIMIT 1 FOR UPDATE',$args))throw new RuleViolation('Não é possível excluir: existem '.($labels[$child]??str_replace('_',' ',$child)).' vinculados.');
            }
            if($type==='matriculas'){
                $links=$this->db->rows('SELECT * FROM '.$this->db->table('aluno_periodos').' WHERE idmatricula=%d FOR UPDATE',[$id]);
                foreach($links as $link){$this->db->update('aluno_periodos',(int)$link['idvinculoperiodo'],['idmatricula'=>null,'status'=>'aguardando_turma']);$this->db->audit('aluno_periodos',(int)$link['idvinculoperiodo'],'reabrir_apos_exclusao',$link,['motivo'=>$reason],$key);}
                $this->db->query('DELETE FROM '.$this->db->table('matricula_movimentacoes').' WHERE idmatricula=%d',[$id]);
            }
            if($type==='pessoas'){
                $link=$this->db->row('SELECT * FROM '.$this->db->table('pessoa_usuarios').' WHERE codpessoa=%d FOR UPDATE',[$id]);
                if($link){$uid=(int)$link['wp_user_id'];$u=get_userdata($uid);if($u){foreach($u->roles as $role)if(str_starts_with($role,'erp_'))$u->remove_role($role);if(!$u->roles)$u->add_role('subscriber');$this->db->onCompletion(static fn()=>clean_user_cache($uid));}$this->db->query('DELETE FROM '.$this->db->table('pessoa_usuarios').' WHERE codpessoa=%d',[$id]);}
            }
            $pk=$this->db->schema()[$type]['pk'][0];$this->db->query('DELETE FROM '.$this->db->table($type)." WHERE $pk=%d",[$id]);
            if($type==='alunos')(new Accounts($this->db))->sync((int)$row['codpessoa']);
            if($type==='aluno_responsaveis')(new Accounts($this->db))->sync((int)$row['codpessoa_responsavel']);
            $this->db->audit($type,$id,'excluir',$row,['motivo'=>$reason,'movimentos_iniciais'=>$children],$key);
            return ['excluido'=>true,'id'=>(string)$id];
        }));}finally{if($type==='periodos_letivos')$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
}
