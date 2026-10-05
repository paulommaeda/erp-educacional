<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
final class ExportService
{
    public function __construct(private Store $db) {}
    public function student(int $id,int $period): array
    {
        return $this->db->atomic(fn()=>$this->build($id,$period));
    }
    public function collection(int $period,int $cursor,int $limit): array
    {
        return $this->db->atomic(function()use($period,$cursor,$limit){
            $m=$this->db->table('matriculas');
            $rows=$this->db->rows("SELECT DISTINCT idaluno FROM $m WHERE codperiodo=%d AND idaluno>%d ORDER BY idaluno LIMIT %d",[$period,$cursor,$limit+1]);
            $more=count($rows)>$limit; $rows=array_slice($rows,0,$limit); $out=[];
            foreach($rows as $r) { $out[]=$this->build((int)$r['idaluno'],$period); }
            return ['items'=>$out,'next_cursor'=>$more?(string)end($rows)['idaluno']:null];
        });
    }
    private function related(string $table,string $field,int $id,string $order=''): array
    {
        return $this->db->rows('SELECT * FROM '.$this->db->table($table)." WHERE $field=%d".($order?' ORDER BY '.$order:''),[$id]);
    }
    private function person(int $id): array
    {
        $p=(new CivilStatus($this->db))->decorate($this->db->get('pessoas',$id));
        $p['enderecos']=$this->related('pessoa_enderecos','codpessoa',$id);
        return $p;
    }
    private function build(int $id,int $period): array
    {
        $student=$this->db->get('alunos',$id);$student['coligada']=$this->db->get('coligadas',(int)$student['codcoligada']);
        $student['pessoa']=$this->person((int)$student['codpessoa']);
        $links=$this->related('aluno_responsaveis','idaluno',$id,'inicio_vigencia,idvinculo');
        foreach($links as &$v) { $v['pessoa']=$this->person((int)$v['codpessoa_responsavel']); }
        unset($v);
        $m=$this->db->table('matriculas');
        $enrollments=$this->db->rows("SELECT * FROM $m WHERE idaluno=%d AND codperiodo=%d ORDER BY idmatricula",[$id,$period]);
        foreach($enrollments as &$enrollment) {
            $enrollment['periodo']=$this->db->get('periodos_letivos',(int)$enrollment['codperiodo']);
            $enrollment['curso']=$this->db->get('cursos',(int)$enrollment['idcurso']);
            $enrollment['turma_atual']=$this->db->get('turmas',(int)$enrollment['idturma_atual']);
            $enrollment['turma_atual']['turno']=$this->db->get('turnos',(int)$enrollment['turma_atual']['idturno']);
            $enrollment['movimentacoes']=$this->related('matricula_movimentacoes','idmatricula',(int)$enrollment['idmatricula'],'idmovimentacao');
            $contracts=$this->related('contratos','idmatricula',(int)$enrollment['idmatricula'],'idcontrato');
            foreach($contracts as &$contract) {
                $contract['descontos']=$this->related('contrato_descontos','idcontrato',(int)$contract['idcontrato'],'iddesconto');
                $parcels=$this->related('parcelas','idcontrato',(int)$contract['idcontrato'],'numero');
                foreach($parcels as &$parcel) {
                    $title=$this->related('lancamentos','idparcela',(int)$parcel['idparcela'])[0]??null;
                    if($title) {
                        $title=\EducacionalERP\Domain\FinancialStatus::present($title);
                        $payments=$this->related('baixas','idlancamento',(int)$title['idlancamento'],'idbaixa');
                        foreach($payments as &$payment) { $payment['estornos']=$this->related('baixa_estornos','idbaixa',(int)$payment['idbaixa'],'idestorno'); }
                        unset($payment);
                        $title['baixas']=$payments;
                        $title['ajustes']=$this->related('lancamento_ajustes','idlancamento',(int)$title['idlancamento'],'idajuste');
                        $tl=$this->db->table('troca_lancamentos'); $tr=$this->db->table('trocas_responsavel');
                        $title['trocas_responsavel']=$this->db->rows("SELECT tl.*,tr.efetivado_em,tr.motivo FROM $tl tl JOIN $tr tr ON tr.idtroca=tl.idtroca WHERE tl.idlancamento=%d ORDER BY tl.idtroca",[(int)$title['idlancamento']]);
                    }
                    $parcel['lancamento']=$title;
                }
                unset($parcel); $contract['parcelas']=$parcels;
            }
            unset($contract); $enrollment['contratos']=$contracts;
        }
        unset($enrollment);
        $vp=$this->db->table('aluno_periodos');
        $pending=$this->db->rows("SELECT * FROM $vp WHERE idaluno=%d AND codperiodo=%d AND status='aguardando_turma'",[$id,$period]);
        return $this->normalize(['schema_version'=>'1.0','gerado_em'=>gmdate('Y-m-d\TH:i:s\Z'),'contexto'=>['codperiodo'=>(string)$period,'moeda'=>'BRL'],
            'aluno'=>$student,'responsaveis'=>$links,'matriculas'=>$enrollments,'periodos_pendentes'=>$pending]);
    }
    private function normalize(array $data): array
    {
        foreach($data as $key=>&$value) {
            if(is_array($value)) { $value=$this->normalize($value); }
            elseif(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)) { $value=str_replace(' ','T',$value).'Z'; }
            elseif(in_array($key,['parcelas_geradas','ativo','responsavel_academico','responsavel_financeiro','pode_rematricular'],true)) { $value=(bool)$value; }
        }
        return $data;
    }
}
