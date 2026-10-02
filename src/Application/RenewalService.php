<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\{Input,Money,RuleViolation};
final class RenewalService
{
    public function __construct(private Store $db,private Operations $ops,private AcademicService $academic,private Access $access) {}
    public function createOffer(array $d,string $key): array
    {
        return $this->db->atomic(fn()=>$this->ops->run($key,'criar_oferta',$d,function()use($d,$key){
            $period=Input::id($d['codperiodo_destino']??null); $course=Input::id($d['idcurso_destino']??null);
            $open=Input::date($d['data_abertura']??null); $close=Input::date($d['data_encerramento']??null);
            if($close<$open) { throw new RuleViolation('Janela de rematrícula inválida.'); }
            $count=Input::id($d['numero_parcelas']??null); $total=0; if($count>120)throw new RuleViolation('Máximo de 120 parcelas.');
            $first=Input::date($d['primeiro_vencimento']??null);
            $classes=$d['turmas']??[];
            if(!is_array($classes) || !$classes || count($classes)>100) { throw new RuleViolation('Informe até 100 turmas.'); }
            $ids=[];
            foreach($classes as $cid) {
                $id=Input::id($cid); $c=$this->db->get('turmas',$id);
                if((int)$c['codperiodo']!==$period || (int)$c['idcurso']!==$course) { throw new RuleViolation('Turma incompatível com a oferta.'); }
                $plan=$this->academic->planForClass($id);Money::split(Money::positive($plan['valor_anuidade']),$count);
                $ids[$id]=$id;
            }
            $zone=wp_timezone();
            $start=(new \DateTimeImmutable($open.' 00:00:00',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end=(new \DateTimeImmutable($close.' 23:59:59',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $id=$this->db->insert('ofertas_rematricula',['codperiodo_destino'=>$period,'idcurso_origem'=>Input::id($d['idcurso_origem']??null),'idcurso_destino'=>$course,
                'abertura_em'=>$start,'encerramento_em'=>$end,'versao_termo'=>Input::text($d['versao_termo']??'1',40),'texto_termo'=>Input::text($d['texto_termo']??null,20000),
                'valor_total'=>Money::format($total),'numero_parcelas'=>$count,'primeiro_vencimento'=>$first,'dia_vencimento'=>(int)substr($first,8,2)]);
            foreach($ids as $cid) { $this->db->insert('oferta_turmas',['idoferta'=>$id,'idturma'=>$cid]); }
            $this->db->audit('ofertas_rematricula',$id,'criar',null,['turmas'=>array_values($ids),'valor_total'=>Money::format($total)],$key);
            return ['idoferta'=>(string)$id];
        }));
    }
    public function offers(int $student,int $period=0): array
    {
        $period=SchoolSettings::forViewer($period?:'todos','academic');
        if(!$this->access->canStudent($student,'renew')) { throw new RuleViolation('Sem autorização para rematrícula.'); }
        $o=$this->db->table('ofertas_rematricula'); $m=$this->db->table('matriculas'); $p=$this->db->table('periodos_letivos');
        $offers=$this->db->rows("SELECT o.*,m.idmatricula AS idmatricula_origem FROM $o o JOIN $m m ON m.idcurso=o.idcurso_origem JOIN $p po ON po.codperiodo=m.codperiodo JOIN $p pd ON pd.codperiodo=o.codperiodo_destino WHERE m.idaluno=%d AND m.status='ativa' AND o.ativo=1 AND UTC_TIMESTAMP() BETWEEN o.abertura_em AND o.encerramento_em AND pd.data_inicio>po.data_inicio AND NOT EXISTS (SELECT 1 FROM $m d WHERE d.idaluno=m.idaluno AND d.codperiodo=o.codperiodo_destino AND d.idcurso=o.idcurso_destino) ".($period?' AND m.codperiodo=%d':'')." ORDER BY o.idoferta",$period?[$student,$period]:[$student]);
        $ot=$this->db->table('oferta_turmas'); $t=$this->db->table('turmas');
        $eligible=[];
        foreach($offers as $offer){
            $origin=$this->db->get('matriculas',(int)$offer['idmatricula_origem']);$source=$this->db->get('turmas',(int)$origin['idturma_atual']);$next=(int)($source['idturma_proxima']??0);
            if(!$next)continue;
            $classes=$this->db->rows("SELECT t.idturma,t.nome,t.idcurso,t.codperiodo FROM $ot ot JOIN $t t ON t.idturma=ot.idturma WHERE ot.idoferta=%d AND t.idturma=%d AND t.status='ativa'",[(int)$offer['idoferta'],$next]);
            if(!$classes)continue;$class=$classes[0];if((int)$class['codperiodo']!==(int)$offer['codperiodo_destino']||(int)$class['idcurso']!==(int)$offer['idcurso_destino'])continue;
            try{$class['plano']=$this->academic->planForClass($next);}catch(RuleViolation $e){continue;}
            $class['curso']=$this->db->get('cursos',(int)$class['idcurso'])['nome'];$offer['turmas']=[$class];$offer['destino_fixo']=true;$eligible[]=$offer;
        }
        return $eligible;
    }
    public function renew(array $d,string $key): array
    {
        return $this->db->atomic(fn()=>$this->ops->run($key,'rematricular',$d,function()use($d,$key){
            $origin=$this->db->get('matriculas',Input::id($d['idmatricula_origem']??null));
            $student=(int)$origin['idaluno']; $this->db->get('alunos',$student,true);
            if(!$this->access->canStudent($student,'renew',true) || !$this->access->person()) { throw new RuleViolation('Responsável não autorizado.'); }
            $origin=$this->db->get('matriculas',(int)$origin['idmatricula'],true);
            if(!SchoolSettings::canChoose('academic')&&(int)$origin['codperiodo']!==SchoolSettings::forViewer(null,'academic'))throw new RuleViolation('A rematrícula deve partir do período vigente.');
            $offer=$this->db->get('ofertas_rematricula',Input::id($d['idoferta']??null),true);
            $now=gmdate('Y-m-d H:i:s');
            if(!(int)$offer['ativo'] || $now<$offer['abertura_em'] || $now>$offer['encerramento_em'] || $origin['status']!=='ativa' || $origin['idcurso']!==$offer['idcurso_origem']) { throw new RuleViolation('Oferta fora da janela ou incompatível com a matrícula.'); }
            $from=$this->db->get('periodos_letivos',(int)$origin['codperiodo']); $to=$this->db->get('periodos_letivos',(int)$offer['codperiodo_destino']);
            if($to['data_inicio']<=$from['data_inicio']) { throw new RuleViolation('A renovação exige um período posterior.'); }
            if(($d['aceite']??false)!==true || ($d['versao_termo']??'')!==$offer['versao_termo']) { throw new RuleViolation('Aceite a versão vigente do termo.'); }
            $source=$this->db->get('turmas',(int)$origin['idturma_atual'],true);
            $class=(int)($source['idturma_proxima']??0);if(!$class)throw new RuleViolation('A escola ainda não definiu a próxima turma. Procure a secretaria.');
            if(isset($d['idturma'])&&(int)$d['idturma']!==$class)throw new RuleViolation('O destino da rematrícula mudou. Recarregue a página para consultar a turma definida pela escola.'); $ot=$this->db->table('oferta_turmas');
            if(!$this->db->row("SELECT idturma FROM $ot WHERE idoferta=%d AND idturma=%d",[(int)$offer['idoferta'],$class])) { throw new RuleViolation('Turma não disponível nesta oferta.'); }
            $classRow=$this->db->get('turmas',$class);
            if($classRow['codperiodo']!==$offer['codperiodo_destino'] || $classRow['idcurso']!==$offer['idcurso_destino']) { throw new RuleViolation('Turma incompatível.'); }
            $result=$this->academic->enrollInside(['idaluno'=>$student,'idturma'=>$class,'valor_original_total'=>$offer['valor_total'],'quantidade_parcelas'=>$d['quantidade_parcelas']??$offer['numero_parcelas'],
                'idplano'=>$d['idplano']??$classRow['idplano'],'plano_versao'=>$d['plano_versao']??$this->academic->planForClass($class)['versao'],'primeiro_vencimento'=>$offer['primeiro_vencimento'],'termo'=>$offer['texto_termo'],'versao_termo'=>$offer['versao_termo']],$key,(int)$origin['idmatricula']);
            $renewal=$this->db->insert('rematriculas',['idoferta'=>$offer['idoferta'],'idmatricula_origem'=>$origin['idmatricula'],'idmatricula_destino'=>$result['idmatricula'],
                'codpessoa_solicitante'=>$this->access->person(),'idempotencia'=>$key,'status'=>'concluida','termo_versao'=>$offer['versao_termo'],
                'termo_hash'=>hash('sha256',$offer['texto_termo']),'aceito_em'=>$now,'concluido_em'=>$now]);
            return $result+['idrematricula'=>(string)$renewal];
        }));
    }
}
