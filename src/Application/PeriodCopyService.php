<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
final class PeriodCopyService
{
    public function __construct(private Store $db,private CatalogService $catalog,private Operations $ops) {}
    public function copy(array $d,string $key):array
    {
        Access::requireAdmin();
        return $this->db->atomic(fn()=>$this->ops->run($key,'copiar_periodo',$d,function()use($d,$key){
            $origin=Input::id($d['codperiodo_origem']??null);$from=$this->db->get('periodos_letivos',$origin,true);
            if(!empty($d['codperiodo_destino']))$target=Input::id($d['codperiodo_destino']);
            else {
                if(!empty($from['codperiodo_proximo']))throw new RuleViolation('A origem já possui próximo período. Selecione esse destino existente.');
                $target=(int)$this->catalog->createInside('periodos_letivos',$d['novo_periodo']??[],$key)['id'];
                $this->db->update('periodos_letivos',$target,['status'=>'planejado']);
            }
            $to=$this->db->get('periodos_letivos',$target,true);
            if($origin===$target||$to['data_inicio']<=$from['data_inicio']||$to['status']==='encerrado')throw new RuleViolation('Escolha um período de destino posterior e não encerrado.');
            if(!empty($from['codperiodo_proximo'])&&(int)$from['codperiodo_proximo']!==$target)throw new RuleViolation('O destino deve ser o próximo período configurado na origem.');
            $t=$this->db->table('turmas');$p=$this->db->table('planos_pagamento');
            $classes=$this->db->rows("SELECT * FROM $t WHERE codperiodo=%d AND status='ativa' ORDER BY idturma FOR UPDATE",[$origin]);
            $plans=$this->db->rows("SELECT * FROM $p WHERE codperiodo=%d AND ativo=1 ORDER BY idplano FOR UPDATE",[$origin]);
            if(!$classes&&!$plans)throw new RuleViolation('Não há turmas ou planos ativos para copiar.');
            if(count($classes)+count($plans)>1000)throw new RuleViolation('Limite de 1.000 cadastros por cópia.');
            $map=[];$classMap=[];
            foreach($plans as $plan){
                // Plan codes are globally unique; preserve the name and append the destination PK.
                $suffix='-P'.$target;$prefix=substr($plan['codigo'],0,30-strlen($suffix));while(!preg_match('//u',$prefix))$prefix=substr($prefix,0,-1);$code=$prefix.$suffix;
                if($this->db->row("SELECT idplano FROM $p WHERE codigo=%s",[$code]))throw new RuleViolation('Já existe plano com código '.$code.'. Nenhum cadastro foi copiado.');
                $map[(int)$plan['idplano']]=(int)$this->catalog->createInside('planos_pagamento',['codperiodo'=>$target,'codigo'=>$code,'nome'=>$plan['nome'],'valor_anuidade'=>$plan['valor_anuidade']],$key)['id'];
            }
            foreach($classes as $class){
                foreach(['cursos'=>'idcurso','turnos'=>'idturno'] as $table=>$field)if(!(int)$this->db->get($table,(int)$class[$field],true)['ativo'])throw new RuleViolation('Turma '.$class['codigo'].' possui curso ou turno inativo.');
                if($this->db->row("SELECT idturma FROM $t WHERE codperiodo=%d AND codigo=%s",[$target,$class['codigo']]))throw new RuleViolation('Turma '.$class['codigo'].' já existe no destino. Nenhum cadastro foi copiado.');
                if(!empty($class['idplano'])&&!isset($map[(int)$class['idplano']]))throw new RuleViolation('Turma '.$class['codigo'].' possui plano inativo ou de outro período. Corrija antes de copiar.');
                $classMap[(int)$class['idturma']]=(int)$this->catalog->createInside('turmas',['codperiodo'=>$target,'codigo'=>$class['codigo'],'nome'=>$class['nome'],'idcurso'=>$class['idcurso'],'idturno'=>$class['idturno'],'capacidade'=>$class['capacidade'],'idplano'=>empty($class['idplano'])?null:$map[(int)$class['idplano']]],$key)['id'];
            }
            $next=$this->catalog->nextPeriod(['codperiodo_proximo'=>$target],$from['data_inicio'],$origin);
            $this->db->update('periodos_letivos',$origin,['codperiodo_proximo'=>$next]);
            $this->db->audit('periodos_letivos',$origin,'definir_proximo_periodo',$from,['codperiodo_proximo'=>$next],$key);
            $result=['codperiodo_destino'=>$target,'turmas'=>count($classMap),'planos'=>count($map),'mapa_turmas'=>$classMap,'mapa_planos'=>$map];
            $this->db->audit('periodos_letivos',$target,'copiar_periodo',null,['origem'=>$origin]+$result,$key);
            return $result;
        }));
    }
}
