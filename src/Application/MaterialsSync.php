<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,Cpf,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Infrastructure\Materials\{Settings,Client};
/** Outbound adapter for the existing K8 student importer; all guardians of one RA travel together. */
final class MaterialsSync
{
    public const BATCH_SIZE=200;
    public const BATCH_BYTES=2097152;
    public function __construct(private Store $db){}
    private function dataset(int $company,int $period,?int $student=null):array
    {
        $a=$this->db->table('alunos');$p=$this->db->table('pessoas');$m=$this->db->table('matriculas');$t=$this->db->table('turmas');$v=$this->db->table('aluno_responsaveis');
        $filter=$student===null?'':' AND a.idaluno=%d';
        $args=$student===null?[$company,$period]:[$company,$period,$student];
        $rows=$this->db->rows("SELECT a.idaluno,a.ra,p.nome,p.cpf,t.nome AS turma
            FROM $m m JOIN $a a ON a.idaluno=m.idaluno AND a.codcoligada=m.codcoligada
            JOIN $p p ON p.codpessoa=a.codpessoa
            JOIN $t t ON t.idturma=m.idturma_atual AND t.codcoligada=m.codcoligada AND t.codperiodo=m.codperiodo
            WHERE m.codcoligada=%d AND m.codperiodo=%d AND m.excluido_em IS NULL AND m.ativo_unico=1
                AND a.ativo=1 AND p.ativo=1$filter ORDER BY a.idaluno,m.idmatricula",$args);
        $students=[];$ids=[];$warnings=[];$now=gmdate('Y-m-d H:i:s');
        foreach($rows as $r){$id=(int)$r['idaluno'];$record=['ra'=>(string)$r['ra'],'nome_aluno'=>(string)$r['nome'],'cpf_aluno'=>(string)($r['cpf']??''),'turma'=>(string)$r['turma'],'responsaveis'=>[]];foreach(['ra','nome_aluno','turma'] as $key)if(trim($record[$key])==='')throw new RuleViolation('Aluno '.$r['idaluno'].' sem '.$key.'. Corrija antes de enviar.');if($record['cpf_aluno']!==''&&!Cpf::valid($record['cpf_aluno']))throw new RuleViolation('CPF do aluno inválido no RA '.$r['ra'].'.');
            if(isset($students[$id])){if($students[$id]!==$record)throw new RuleViolation('RA '.$r['ra'].' possui mais de uma turma atual no período. Confira a matrícula.');continue;}$students[$id]=$record;$ids[]=$id;
        }
        foreach(array_chunk($ids,500) as $chunk){$marks=implode(',',array_fill(0,count($chunk),'%d'));$links=$this->db->rows("SELECT DISTINCT v.idaluno,r.codpessoa,r.nome,r.cpf,r.telefone FROM $v v JOIN $p r ON r.codpessoa=v.codpessoa_responsavel WHERE v.codcoligada=%d AND v.idaluno IN ($marks) AND r.ativo=1 AND v.inicio_vigencia<=%s AND (v.fim_vigencia IS NULL OR v.fim_vigencia>%s) ORDER BY v.idaluno,r.codpessoa",[$company,...$chunk,$now,$now]);
            foreach($links as $link){$id=(int)$link['idaluno'];$cpf=(string)($link['cpf']??'');if(trim((string)$link['nome'])==='')throw new RuleViolation('Responsável sem nome no RA '.$students[$id]['ra'].'.');if($cpf!==''&&!Cpf::valid($cpf))throw new RuleViolation('CPF de responsável inválido no RA '.$students[$id]['ra'].'.');$students[$id]['responsaveis'][]=['nome'=>(string)$link['nome'],'cpf'=>$cpf,'telefone'=>preg_replace('/\D/','',(string)($link['telefone']??''))];}
        }
        foreach($students as $id=>$student){$count=count($student['responsaveis']);if($count>100)throw new RuleViolation('RA '.$student['ra'].' supera 100 responsáveis.');$hasCpf=false;foreach($student['responsaveis'] as $responsible)if($responsible['cpf']!=='')$hasCpf=true;if(!$hasCpf)$warnings[]=['ra'=>$student['ra'],'message'=>'Nenhum responsável com CPF: o aluno não será localizado pela compra por CPF.'];foreach($student['responsaveis'] as $responsible)if($responsible['cpf']===''){$warnings[]=['ra'=>$student['ra'],'message'=>'Há responsável sem CPF neste aluno.'];break;}}
        return [array_values($students),$warnings];
    }
    public function forEnrollment(int $student,int $company,int $period):?array
    {
        [$students]=$this->dataset($company,$period,$student);
        return $students[0]??null;
    }
    private function plan():array
    {
        $s=Settings::read();$company=Coligadas::current();if(empty($s['codperiodo']))throw new RuleViolation('Escolha e salve o período dos alunos antes de conferir.');$period=Input::id($s['codperiodo']);if((int)$this->db->get('periodos_letivos',$period)['codcoligada']!==$company)throw new RuleViolation('Escolha um período desta coligada.');if(!(int)$this->db->get('coligadas',$company)['ativo'])throw new RuleViolation('Coligada inativa.');
        [$students,$warnings]=$this->dataset($company,$period);
        $a=$this->db->table('alunos');$p=$this->db->table('pessoas');
        $totalActive=(int)$this->db->row("SELECT COUNT(*) AS n FROM $a a JOIN $p p ON p.codpessoa=a.codpessoa WHERE a.codcoligada=%d AND a.ativo=1 AND p.ativo=1",[$company])['n'];
        $batches=[];$batch=[];$bytes=80;$expanded=0;
        foreach($students as $student){$size=strlen(wp_json_encode($student))+1;$records=max(1,(int)ceil(count($student['responsaveis'])/2));if($size+80>self::BATCH_BYTES)throw new RuleViolation('RA '.$student['ra'].' excede o tamanho permitido para um lote.');if($batch&&(count($batch)>=self::BATCH_SIZE||$bytes+$size>self::BATCH_BYTES||$expanded+$records>8000)){$batches[]=$batch;$batch=[];$bytes=80;$expanded=0;}$batch[]=$student;$bytes+=$size;$expanded+=$records;}if($batch)$batches[]=$batch;
        $signature=hash_hmac('sha256',wp_json_encode([$company,$s['versao'],$s['url'],$s['usuario'],$period,$students]),wp_salt('auth').'|k8-materials-plan');
        return ['assinatura'=>$signature,'configurada'=>$s['url']!==''&&$s['usuario']!==''&&$s['senha']!=='','codcoligada'=>(string)$company,'codperiodo'=>(string)$period,'total'=>count($students),'sem_turma_no_periodo'=>max(0,$totalActive-count($students)),'avisos'=>$warnings,'batches'=>$batches];
    }
    public function preview():array
    {
        Access::requireAdmin();$plan=$this->db->atomic(fn()=>$this->plan());$plan['lotes']=count($plan['batches']);$plan['alunos']=[];foreach($plan['batches'] as $batch)foreach($batch as $row)$plan['alunos'][]=['ra'=>$row['ra'],'nome_aluno'=>$row['nome_aluno'],'turma'=>$row['turma'],'responsaveis'=>count($row['responsaveis'])];unset($plan['batches']);return $plan;
    }
    public function send(array $data,string $key):array
    {
        Access::requireAdmin();Input::key($key);if(array_diff(array_keys($data),['assinatura','lote','validar_apenas']))throw new RuleViolation('Opção de envio desconhecida. O envio utiliza somente o modo atualizar.');if(!is_bool($data['validar_apenas']??null))throw new RuleViolation('Informe validar_apenas como verdadeiro ou falso.');$validate=$data['validar_apenas'];$index=Input::id($data['lote']??null);$signature=$data['assinatura']??null;if(!is_string($signature)||!preg_match('/^[a-f0-9]{64}$/D',$signature))throw new RuleViolation('Abra a conferência antes do envio.');
        $lock=Settings::lock($this->db);if((int)($this->db->row('SELECT GET_LOCK(%s,3) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Há um envio de materiais em andamento. Aguarde.');
        try{
            $plan=$this->db->atomic(fn()=>$this->plan());if(!hash_equals($plan['assinatura'],$signature))throw new RuleViolation('Os dados ou a configuração mudaram. Abra uma nova conferência.');if(!$plan['configurada'])throw new RuleViolation('Configure a URL, o login e a Senha de aplicação do K8.');$batch=$plan['batches'][$index-1]??null;if(!$batch)throw new RuleViolation('Lote inexistente ou lista vazia.');$settings=Settings::read();
            $option=Coligadas::option('ederp_materials_history');$history=(array)get_option($option,[]);$keyHash=hash('sha256',$key);$hash=hash('sha256',wp_json_encode([$signature,$index,$validate]));
            foreach($history as $attempt)if(($attempt['chave']??'')===$keyHash){if(($attempt['hash']??'')!==$hash)throw new RuleViolation('Chave de operação reutilizada com outros dados.');if(($attempt['status']??'')==='confirmado')return $attempt['resultado'];throw new RuleViolation('Esta tentativa está sem confirmação. Consulte o relatório no K8 antes de iniciar novo envio.');}
            $history=array_slice($history,-39);$history[]=['chave'=>$keyHash,'hash'=>$hash,'status'=>'sem_confirmacao','inicio'=>gmdate('Y-m-d\TH:i:s\Z'),'codperiodo'=>(string)$settings['codperiodo'],'lote'=>$index,'lotes'=>count($plan['batches']),'validar_apenas'=>$validate,'alunos'=>count($batch),'resultado'=>null];$position=count($history)-1;update_option($option,$history,false);
            $result=Client::send($settings,$batch,$validate);$result['lote']=$index;$result['lotes']=count($plan['batches']);$history[$position]['status']='confirmado';$history[$position]['resultado']=$result;update_option($option,$history,false);return $result;
        }finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
}
