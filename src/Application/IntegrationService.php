<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,FinancialStatus};
use EducacionalERP\Infrastructure\WordPress\Access;
/** Integration DTOs never contain WordPress credentials, hashes or private document tokens. */
final class IntegrationService
{
    public function __construct(private Store $db,private Operations $ops,private CatalogService $catalog){}
    private function related(string $table,string $field,int $id,string $order):array
    {
        return $this->db->rows('SELECT * FROM '.$this->db->table($table)." WHERE $field=%d ORDER BY $order",[$id]);
    }
    private function person(int $id):array
    {
        $person=(new CivilStatus($this->db))->decorate($this->db->get('pessoas',$id));
        $person['enderecos']=$this->related('pessoa_enderecos','codpessoa',$id,'idendereco');return $person;
    }
    private function student(int $id):array
    {
        $student=$this->db->get('alunos',$id);$student['pessoa']=$this->person((int)$student['codpessoa']);
        $student['vinculos']=$this->related('aluno_responsaveis','idaluno',$id,'idvinculo');$student['pai']=[];$student['mae']=[];$student['outros']=[];
        foreach($student['vinculos'] as &$link){$link['pessoa']=$this->person((int)$link['codpessoa_responsavel']);$link['vigente']=$link['fim_vigencia']===null&&$link['inicio_vigencia']<=gmdate('Y-m-d H:i:s');
            if($link['vigente']){$group=match($link['parentesco']){'pai'=>'pai','mae'=>'mae',default=>'outros'};$student[$group][]=$link;}
        }unset($link);return $student;
    }
    private function enrollment(int $id,bool $financial=true):array
    {
        $row=$this->db->get('matriculas',$id);$row['aluno']=$this->student((int)$row['idaluno']);$row['periodo']=$this->db->get('periodos_letivos',(int)$row['codperiodo']);$row['curso']=$this->db->get('cursos',(int)$row['idcurso']);$row['turma']=$this->db->get('turmas',(int)$row['idturma_atual']);$row['turno']=$this->db->get('turnos',(int)$row['turma']['idturno']);
        $row['movimentacoes']=$this->related('matricula_movimentacoes','idmatricula',$id,'idmovimentacao');foreach($row['movimentacoes'] as &$move){unset($move['documento_token']);}unset($move);
        if($financial){$row['contratos']=$this->related('contratos','idmatricula',$id,'idcontrato');foreach($row['contratos'] as &$contract){$contract['descontos']=$this->related('contrato_descontos','idcontrato',(int)$contract['idcontrato'],'iddesconto');$contract['responsavel_financeiro']=$this->person((int)$contract['codpessoa_rf_atual']);$contract['parcelas']=$this->related('parcelas','idcontrato',(int)$contract['idcontrato'],'numero');foreach($contract['parcelas'] as &$parcel){$parcel['lancamentos']=array_map(fn($t)=>$this->title($t),$this->related('lancamentos','idparcela',(int)$parcel['idparcela'],'idlancamento'));}unset($parcel);}unset($contract);}
        return $row;
    }
    private function title(array $row):array
    {
        $row=FinancialStatus::present($row);$row['baixas']=$this->related('baixas','idlancamento',(int)$row['idlancamento'],'idbaixa');foreach($row['baixas'] as &$payment){$payment['estornos']=$this->related('baixa_estornos','idbaixa',(int)$payment['idbaixa'],'idestorno');}unset($payment);
        $row['ajustes']=$this->related('lancamento_ajustes','idlancamento',(int)$row['idlancamento'],'idajuste');return $row;
    }
    private function financial(int $id):array
    {
        $row=$this->title($this->db->get('lancamentos',$id));$parcel=$this->db->get('parcelas',(int)$row['idparcela']);$contract=$this->db->get('contratos',(int)$parcel['idcontrato']);$contract['descontos']=$this->related('contrato_descontos','idcontrato',(int)$contract['idcontrato'],'iddesconto');$enrollment=$this->enrollment((int)$contract['idmatricula'],false);
        return ['lancamento'=>$row,'parcela'=>$parcel,'contrato'=>$contract,'responsavel_financeiro'=>$this->person((int)$row['codpessoa_rf_atual']),'aluno'=>$enrollment['aluno'],'matricula'=>$enrollment];
    }
    private function build(string $resource,int $id):array
    {
        return match($resource){'alunos'=>$this->student($id),'matriculas'=>$this->enrollment($id),'financeiro'=>$this->financial($id),default=>throw new RuleViolation('Recurso inválido.')};
    }
    public function detail(string $resource,int $id):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>['schema_version'=>'1.0','gerado_em'=>gmdate('Y-m-d\TH:i:s\Z'),'item'=>$this->build($resource,$id)]);
    }
    public function collection(string $resource,array $query):array
    {
        Access::requireAdmin();$cursor=$query['cursor']??'0';if(!is_scalar($cursor)||!preg_match('/^\d{1,18}$/D',(string)$cursor))throw new RuleViolation('Cursor inválido.');$limit=isset($query['limit'])?Input::id($query['limit']):20;if($limit>50)throw new RuleViolation('Limite máximo de 50 registros.');
        if(!empty($query['vencimento_de'])&&!empty($query['vencimento_ate'])&&Input::date($query['vencimento_de'])>Input::date($query['vencimento_ate']))throw new RuleViolation('Intervalo de vencimentos invertido.');
        $period=!empty($query['codperiodo'])?Input::id($query['codperiodo']):null;$student=!empty($query['idaluno'])?Input::id($query['idaluno']):null;
        return $this->db->atomic(function()use($resource,$cursor,$limit,$period,$student,$query){
            $args=[(int)$cursor];$where='';$joins='';
            if($resource==='alunos'){$table=$this->db->table('alunos');$pk='idaluno';if($period){$where.=' AND EXISTS (SELECT 1 FROM '.$this->db->table('matriculas').' m WHERE m.idaluno=r.idaluno AND m.codperiodo=%d)';$args[]=$period;}if(!empty($query['ra'])){$where.=' AND r.ra=%s';$args[]=Input::text($query['ra'],40);}if($student){$where.=' AND r.idaluno=%d';$args[]=$student;}}
            elseif($resource==='matriculas'){$table=$this->db->table('matriculas');$pk='idmatricula';if($period){$where.=' AND r.codperiodo=%d';$args[]=$period;}if($student){$where.=' AND r.idaluno=%d';$args[]=$student;}if(!empty($query['idturma'])){$where.=' AND r.idturma_atual=%d';$args[]=Input::id($query['idturma']);}}
            elseif($resource==='financeiro'){$table=$this->db->table('lancamentos');$pk='idlancamento';$joins=' JOIN '.$this->db->table('parcelas').' p ON p.idparcela=r.idparcela JOIN '.$this->db->table('contratos').' c ON c.idcontrato=p.idcontrato JOIN '.$this->db->table('matriculas').' m ON m.idmatricula=c.idmatricula';if($period){$where.=' AND m.codperiodo=%d';$args[]=$period;}if($student){$where.=' AND m.idaluno=%d';$args[]=$student;}if(!empty($query['idturma'])){$where.=' AND m.idturma_atual=%d';$args[]=Input::id($query['idturma']);}
                if(!empty($query['status'])){$status=$query['status'];if(!in_array($status,['em_aberto','vencido','baixado','cancelado'],true))throw new RuleViolation('Situação financeira inválida.');if($status==='cancelado')$where.=" AND r.status='cancelado'";elseif($status==='baixado')$where.=" AND r.status<>'cancelado' AND CAST(r.saldo_aberto AS DECIMAL(15,2))=0";else{$where.=" AND r.status<>'cancelado' AND CAST(r.saldo_aberto AS DECIMAL(15,2))>0 AND r.vencimento".($status==='vencido'?'<':'>=').'%s';$args[]=Input::today();}}
                foreach(['vencimento_de'=>'>=','vencimento_ate'=>'<='] as $field=>$operator)if(!empty($query[$field])){$where.=" AND r.vencimento$operator%s";$args[]=Input::date($query[$field]);}
            }else throw new RuleViolation('Recurso inválido.');
            $args[]=$limit+1;$ids=$this->db->rows("SELECT r.$pk FROM $table r$joins WHERE r.$pk>%d$where ORDER BY r.$pk LIMIT %d",$args);$more=count($ids)>$limit;$ids=array_slice($ids,0,$limit);$items=[];foreach($ids as $row)$items[]=$this->build($resource,(int)$row[$pk]);return ['schema_version'=>'1.0','gerado_em'=>gmdate('Y-m-d\TH:i:s\Z'),'items'=>$items,'next_cursor'=>$more?(string)end($ids)[$pk]:null,'limit'=>$limit];
        });
    }
    public function createStudent(array $data,string $key):array
    {
        Access::requireAdmin();$links=$data['vinculos']??[];if(!is_array($links)||!array_is_list($links)||count($links)>20)throw new RuleViolation('Informe até 20 vínculos.');$student=$data['aluno']??null;if(!is_array($student))throw new RuleViolation('Informe o objeto aluno.');
        return $this->db->atomic(fn()=>$this->ops->run($key,'integracao_criar_aluno',$data,function()use($data,$student,$links,$key){
            if(!empty($data['codpessoa'])){$person=Input::id($data['codpessoa']);if(!(int)$this->db->get('pessoas',$person,true)['ativo'])throw new RuleViolation('Pessoa inativa.');}
            else{if(!is_array($data['pessoa']??null))throw new RuleViolation('Informe pessoa ou codpessoa.');$person=(int)$this->catalog->createInside('pessoas',$data['pessoa'],$key)['id'];}
            $created=$this->catalog->createInside('alunos',['codpessoa'=>$person,'ra'=>$student['ra']??null,'tipo_aluno'=>$student['tipo_aluno']??'REGULAR'],$key);$id=(int)$created['id'];
            foreach($links as $link){if(!is_array($link))throw new RuleViolation('Vínculo inválido.');if(empty($link['codpessoa_responsavel'])){if(!is_array($link['pessoa']??null))throw new RuleViolation('Informe pessoa do responsável ou codpessoa_responsavel.');$link['codpessoa_responsavel']=$this->catalog->createInside('pessoas',$link['pessoa'],$key)['id'];}$this->catalog->linkInside($id,$link,$key);}
            return ['idaluno'=>(string)$id,'codpessoa'=>(string)$person,'item'=>$this->student($id)];
        }));
    }
}
