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
        $this->requireManager();
        return $this->db->atomic(fn()=>$this->ops->run($key,'criar_oferta',$d,function()use($d,$key){
            [$fields,$ids]=$this->offerData($d);
            $id=$this->db->insert('ofertas_rematricula',$fields);
            foreach($ids as $cid) { $this->db->insert('oferta_turmas',['idoferta'=>$id,'idturma'=>$cid]); }
            $this->db->audit('ofertas_rematricula',$id,'criar',null,['turmas'=>array_values($ids),'valor_total'=>$fields['valor_total']],$key);
            return ['idoferta'=>(string)$id];
        }));
    }
    private function requireManager():void {if(!current_user_can('erp_gerenciar_academico'))throw new RuleViolation('Sem permissão para gerenciar ofertas.');}
    private function offerData(array $d,bool $preserve=false):array
    {
            $period=Input::id($d['codperiodo_destino']??null); $course=Input::id($d['idcurso_destino']??null);
            $open=Input::date($d['data_abertura']??null); $close=Input::date($d['data_encerramento']??null);
            if($close<$open) { throw new RuleViolation('Janela de rematrícula inválida.'); }
            $count=Input::id($d['numero_parcelas']??null); $total=0; if($count>120)throw new RuleViolation('Máximo de 120 parcelas.');
            $first=Input::date($d['primeiro_vencimento']??null);
            $classes=$d['turmas']??[];
            if(!is_array($classes) || !$classes || count($classes)>100) { throw new RuleViolation('Informe até 100 turmas.'); }
            $ids=[];
            foreach($classes as $cid) {
                $id=Input::id($cid); $c=$this->db->get('turmas',$id,true);
                if(!$preserve&&$c['status']!=='ativa')throw new RuleViolation('Selecione somente turmas ativas.');
                if((int)$c['codperiodo']!==$period || (int)$c['idcurso']!==$course) { throw new RuleViolation('Turma incompatível com a oferta.'); }
                if(!$preserve){$plan=$this->academic->planForClass($id);Money::split(Money::positive($plan['valor_anuidade']),$count);}
                $ids[$id]=$id;
            }
            $this->db->get('periodos_letivos',$period);$this->db->get('cursos',Input::id($d['idcurso_origem']??null));
            $zone=wp_timezone();
            $start=(new \DateTimeImmutable($open.' 00:00:00',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end=(new \DateTimeImmutable($close.' 23:59:59',$zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $fields=['codperiodo_destino'=>$period,'idcurso_origem'=>Input::id($d['idcurso_origem']??null),'idcurso_destino'=>$course,
                'abertura_em'=>$start,'encerramento_em'=>$end,'versao_termo'=>Input::text($d['versao_termo']??'1',40),'texto_termo'=>Input::text($d['texto_termo']??null,20000),
                'valor_total'=>Money::format($total),'numero_parcelas'=>$count,'primeiro_vencimento'=>$first,'dia_vencimento'=>(int)substr($first,8,2)];
        if(!in_array($d['ativo']??1,[0,1,'0','1'],true))throw new RuleViolation('Situação inválida.');$fields['ativo']=(int)($d['ativo']??1);
        return [$fields,array_values($ids)];
    }
    private function classIds(int $id):array {return array_map(static fn($r)=>(int)$r['idturma'],$this->db->rows('SELECT idturma FROM '.$this->db->table('oferta_turmas').' WHERE idoferta=%d ORDER BY idturma',[$id]));}
    private function stamp(array $row,array $ids):string {return hash('sha256',json_encode([$row,$ids]));}
    public function listOffers(array $filters):array
    {
        $this->requireManager();$page=max(1,(int)($filters['page']??1));$period=max(0,(int)($filters['codperiodo']??0));
        $o=$this->db->table('ofertas_rematricula');$t=$this->db->table('turmas');$ot=$this->db->table('oferta_turmas');$r=$this->db->table('rematriculas');
        $where=$period?' WHERE o.codperiodo_destino=%d':'';$args=$period?[$period]:[];
        $total=(int)$this->db->row("SELECT COUNT(*) AS n FROM $o o$where",$args)['n'];
        $rows=$this->db->rows("SELECT o.* FROM $o o$where ORDER BY o.idoferta DESC LIMIT 20 OFFSET %d",[...$args,($page-1)*20]);
        foreach($rows as &$row){$id=(int)$row['idoferta'];$row['version']=$this->stamp($row,$this->classIds($id));
            $row['periodo']=$this->db->get('periodos_letivos',(int)$row['codperiodo_destino'])['descricao'];
            $row['curso_origem']=$this->db->get('cursos',(int)$row['idcurso_origem'])['nome'];$row['curso_destino']=$this->db->get('cursos',(int)$row['idcurso_destino'])['nome'];
            $row['turmas']=$this->db->rows("SELECT t.idturma,t.nome FROM $ot ot JOIN $t t ON t.idturma=ot.idturma WHERE ot.idoferta=%d ORDER BY t.nome,t.idturma",[$id]);
            $row['utilizacoes']=(int)$this->db->row("SELECT COUNT(*) AS n FROM $r WHERE idoferta=%d",[$id])['n'];
            foreach(['abertura_em'=>'data_abertura','encerramento_em'=>'data_encerramento'] as $source=>$dest)$row[$dest]=(new \DateTimeImmutable($row[$source],new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d');
            $now=gmdate('Y-m-d H:i:s');$row['situacao']=!(int)$row['ativo']?'Inativa':($now<$row['abertura_em']?'Agendada':($now>$row['encerramento_em']?'Encerrada':'Disponível'));
        }unset($row);return ['items'=>$rows,'total'=>$total];
    }
    public function changeOffer(int $id,array $d,string $key,bool $delete=false):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,$delete?'excluir_oferta':'editar_oferta',['id'=>$id]+$d,function()use($id,$d,$key,$delete){
            $before=$this->db->get('ofertas_rematricula',$id,true);$ids=$this->classIds($id);
            if(!is_string($d['version']??null)||!hash_equals($this->stamp($before,$ids),$d['version']))throw new RuleViolation('Oferta alterada. Atualize a listagem antes de continuar.');
            $used=$this->db->row('SELECT idrematricula FROM '.$this->db->table('rematriculas').' WHERE idoferta=%d LIMIT 1 FOR UPDATE',[$id]);
            if($delete&&$used)throw new RuleViolation('Oferta com rematrículas vinculadas não pode ser excluída. Edite para desativá-la.');
            $ot=$this->db->table('oferta_turmas');
            if($delete){$reason=Input::text($d['motivo']??null,500);$this->db->query("DELETE FROM $ot WHERE idoferta=%d",[$id]);$this->db->query('DELETE FROM '.$this->db->table('ofertas_rematricula').' WHERE idoferta=%d',[$id]);$after=['motivo'=>$reason];}
            else {
                [$fields,$newIds]=$this->offerData($d,(bool)$used);
                if($used){foreach(['codperiodo_destino','idcurso_origem','idcurso_destino','numero_parcelas','primeiro_vencimento','versao_termo','texto_termo'] as $field)if((string)$before[$field]!== (string)$fields[$field])throw new RuleViolation('Oferta utilizada: somente datas de abertura/encerramento e situação podem ser alteradas.');sort($newIds);if($newIds!==$ids)throw new RuleViolation('Oferta utilizada: preserve as turmas vinculadas.');}
                elseif($fields['texto_termo']!==$before['texto_termo']&&$fields['versao_termo']===$before['versao_termo'])throw new RuleViolation('Ao alterar o termo, informe uma nova versão.');
                if($used)$fields=array_intersect_key($fields,array_flip(['abertura_em','encerramento_em','ativo']));
                $this->db->update('ofertas_rematricula',$id,$fields);$this->db->query("DELETE FROM $ot WHERE idoferta=%d",[$id]);foreach($newIds as $cid)$this->db->insert('oferta_turmas',['idoferta'=>$id,'idturma'=>$cid]);$after=$fields+['turmas'=>$newIds];
            }
            $this->db->audit('ofertas_rematricula',$id,$delete?'excluir':'editar',$before+['turmas'=>$ids],$after,$key);return ['idoferta'=>(string)$id,'excluido'=>$delete];
        }));
    }
    public const BLOCK_MESSAGE = 'Queremos continuar a caminhada com sua família. Neste momento, a rematrícula está indisponível porque há parcelas em aberto. Por favor, procure a secretaria para regularizar a situação e receber orientação sobre os próximos passos.';
    private function hasOpenPayments(int $student):bool
    {
        $l=$this->db->table('lancamentos');$p=$this->db->table('parcelas');$c=$this->db->table('contratos');$m=$this->db->table('matriculas');
        foreach($this->db->rows("SELECT l.saldo_aberto FROM $l l JOIN $p p ON p.idparcela=l.idparcela JOIN $c c ON c.idcontrato=p.idcontrato JOIN $m m ON m.idmatricula=c.idmatricula WHERE m.idaluno=%d AND l.status<>'cancelado'",[$student]) as $row)if(Money::cents($row['saldo_aberto'])>0)return true;
        return false;
    }
    public function offers(int $student,int $period=0): array
    {
        $period=SchoolSettings::forViewer($period?:'todos','academic');
        if(!$this->access->canStudent($student,'renew')) { throw new RuleViolation('Sem autorização para rematrícula.'); }
        $o=$this->db->table('ofertas_rematricula'); $m=$this->db->table('matriculas'); $p=$this->db->table('periodos_letivos');$t=$this->db->table('turmas');$ot=$this->db->table('oferta_turmas');
        $offers=$this->db->rows("SELECT o.*,m.idmatricula AS idmatricula_origem FROM $m m JOIN $t source ON source.idturma=m.idturma_atual JOIN $ot offered ON offered.idturma=source.idturma_proxima JOIN $o o ON o.idoferta=offered.idoferta JOIN $p po ON po.codperiodo=m.codperiodo JOIN $p pd ON pd.codperiodo=o.codperiodo_destino WHERE m.idaluno=%d AND m.status IN ('reservado','cursando','ativa','aprovado') AND o.ativo=1 AND UTC_TIMESTAMP() BETWEEN o.abertura_em AND o.encerramento_em AND pd.data_inicio>po.data_inicio AND po.codperiodo_proximo=o.codperiodo_destino AND NOT EXISTS (SELECT 1 FROM $m d WHERE d.idaluno=m.idaluno AND d.codperiodo=o.codperiodo_destino AND d.idcurso=o.idcurso_destino AND d.ativo_unico=1) ".($period?' AND m.codperiodo=%d':'')." ORDER BY o.idoferta",$period?[$student,$period]:[$student]);
        $ot=$this->db->table('oferta_turmas'); $t=$this->db->table('turmas');
        $eligible=[];
        foreach($offers as $offer){
            $origin=$this->db->get('matriculas',(int)$offer['idmatricula_origem']);$source=$this->db->get('turmas',(int)$origin['idturma_atual']);$next=(int)($source['idturma_proxima']??0);
            if(!$next)continue;
            $classes=$this->db->rows("SELECT t.idturma,t.nome,t.idcurso,t.codperiodo FROM $ot ot JOIN $t t ON t.idturma=ot.idturma WHERE ot.idoferta=%d AND t.idturma=%d AND t.status='ativa'",[(int)$offer['idoferta'],$next]);
            if(!$classes)continue;$class=$classes[0];if((int)$class['codperiodo']!==(int)$offer['codperiodo_destino']||(int)$class['idcurso']!==(int)$offer['idcurso_destino'])continue;
            try{$class['plano']=$this->academic->planForClass($next);}catch(RuleViolation $e){continue;}
            $class['curso']=$this->db->get('cursos',(int)$class['idcurso'])['nome'];$offer['turmas']=[$class];$offer['destino_fixo']=true;$offer['indisponivel']=$this->hasOpenPayments($student);$offer['mensagem_indisponivel']=SchoolSettings::renewalBlockMessage();$offer['texto_apresentacao']=SchoolSettings::renewalIntroduction();$eligible[]=$offer;
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
            if($this->hasOpenPayments($student))throw new RuleViolation(SchoolSettings::renewalBlockMessage());
            if(!SchoolSettings::canChoose('academic')&&(int)$origin['codperiodo']!==SchoolSettings::forViewer(null,'academic'))throw new RuleViolation('A rematrícula deve partir do período vigente.');
            $offer=$this->db->get('ofertas_rematricula',Input::id($d['idoferta']??null),true);
            $now=gmdate('Y-m-d H:i:s');
            if(!(int)$offer['ativo'] || $now<$offer['abertura_em'] || $now>$offer['encerramento_em'] || !in_array($origin['status'],['reservado','cursando','ativa','aprovado'],true)) { throw new RuleViolation('Oferta fora da janela ou incompatível com a matrícula.'); }
            $from=$this->db->get('periodos_letivos',(int)$origin['codperiodo']); $to=$this->db->get('periodos_letivos',(int)$offer['codperiodo_destino']);
            if((int)($from['codperiodo_proximo']??0)!==(int)$offer['codperiodo_destino'])throw new RuleViolation('A escola precisa configurar o próximo período letivo correspondente à oferta.');
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
