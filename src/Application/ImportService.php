<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
/** One row per transaction. Origin person code is never interpreted as an RA or local PK. */
final class ImportService
{
    public function __construct(private Store $db,private CatalogService $catalog) {}
    public function row(string $type,array $data,string $key):array
    {
        Access::requireAdmin();$data=\EducacionalERP\Domain\CadastroText::normalize($data);
        if(in_array($type,['vinculos','matriculas'],true))return $this->schoolRow($type,$data,$key);
        if($type==='turmas')return $this->classRow($data,$key);
        if(!in_array($type,['pessoas','alunos'],true))throw new RuleViolation('Tipo de importação inválido.');
        $origin=Input::text($data['codigo_pessoa']??$data['codpessoa_origem']??null,80);$data['codpessoa_origem']=$origin;
        return $this->db->atomic(function()use($type,$data,$key,$origin){
            $p=$this->db->table('pessoas');$a=$this->db->table('alunos');
            $person=$this->db->row("SELECT * FROM $p WHERE codigo_pessoa=%s OR codpessoa_origem=%s FOR UPDATE",[$origin,$origin]);
            if($type==='pessoas') {
                if($person&&$person['codpessoa_origem']===null)throw new RuleViolation('Código já utilizado em cadastro manual. Confira o arquivo; a importação não sobrescreve pessoas.');
                if($person)return ['status'=>'existente','id'=>$person['codpessoa'],'mensagem'=>'CODPESSOA de origem já importado; cadastro mantido. Edite em Pessoas para corrigir dados.'];
                if(!empty($data['data_nascimento'])&&preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D',$data['data_nascimento'],$m))$data['data_nascimento']="$m[3]-$m[2]-$m[1]";
                return ['status'=>'criado']+$this->catalog->createInside('pessoas',$data,$key);
            }
            if(!$person)throw new RuleViolation('CODPESSOA de origem não localizado. Importe a pessoa primeiro.');
            $ra=Input::text($data['ra']??null,40);
            $existing=$this->db->row("SELECT * FROM $a WHERE (codpessoa=%d OR ra=%s) AND codcoligada=%d FOR UPDATE",[(int)$person['codpessoa'],$ra,Coligadas::current()]);
            if($existing) {
                if((int)$existing['codpessoa']===(int)$person['codpessoa'] && $existing['ra']===$ra)return ['status'=>'existente','id'=>$existing['idaluno'],'mensagem'=>'Pessoa e RA já vinculados; dados mantidos.'];
                throw new RuleViolation('Conflito: pessoa já possui outro RA ou este RA pertence a outra pessoa. Nenhum vínculo foi alterado.');
            }
            if(!(int)$person['ativo'])throw new RuleViolation('Pessoa inativa.');
            $result=$this->catalog->createInside('alunos',['codpessoa'=>$person['codpessoa'],'ra'=>$ra,'tipo_aluno'=>$data['tipo_aluno']??'Regular'],$key);(new AdditionalFields($this->db))->write((int)$person['codpessoa'],$data['campos_adicionais']??null,$key);return ['status'=>'criado']+$result;
        });
    }
    private function schoolRow(string $type,array $d,string $key):array
    {
        foreach(['codigo_periodo','codigo_turma','codigo_pessoa_responsavel'] as $field)if(isset($d[$field])&&is_string($d[$field]))$d[$field]=\EducacionalERP\Domain\CadastroText::upper(trim($d[$field]));
        return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'importar_'.$type,$d,function()use($type,$d,$key){
            $ra=Input::text($d['ra']??null,40);$student=$this->db->row('SELECT * FROM '.$this->db->table('alunos').' WHERE ra=%s AND codcoligada=%d FOR UPDATE',[$ra,Coligadas::current()]);if(!$student||!(int)$student['ativo'])throw new RuleViolation('Aluno não encontrado ou inativo para este RA na coligada atual.');
            if($type==='vinculos'){
                $code=Input::text($d['codigo_pessoa_responsavel']??null,80);$person=$this->db->row('SELECT * FROM '.$this->db->table('pessoas').' WHERE codigo_pessoa=%s OR codpessoa_origem=%s FOR UPDATE',[$code,$code]);if(!$person||!(int)$person['ativo'])throw new RuleViolation('Responsável não encontrado ou inativo. Importe a pessoa primeiro.');
                $parent=strtolower(trim((string)($d['parentesco']??'')));foreach(\EducacionalERP\Domain\Relationships::LABELS as $slug=>$label)if(\EducacionalERP\Domain\CadastroText::upper($label)===\EducacionalERP\Domain\CadastroText::upper($parent))$parent=$slug;
                if(!isset(\EducacionalERP\Domain\Relationships::LABELS[$parent]))throw new RuleViolation('Parentesco inválido.');$flags=[];foreach(['responsavel_academico','responsavel_financeiro','pode_rematricular'] as $flag){$v=\EducacionalERP\Domain\CadastroText::upper(trim((string)($d[$flag]??'0')));if(!in_array($v,['0','1','SIM','NAO','NÃO'],true))throw new RuleViolation('Informe 0/1 ou Sim/Não em '.$flag);$flags[$flag]=in_array($v,['1','SIM'],true)?1:0;}
                $existing=$this->db->row('SELECT * FROM '.$this->db->table('aluno_responsaveis').' WHERE idaluno=%d AND codpessoa_responsavel=%d AND fim_vigencia IS NULL FOR UPDATE',[(int)$student['idaluno'],(int)$person['codpessoa']]);if($existing){if($existing['parentesco']!==$parent)throw new RuleViolation('Vínculo existente com parentesco diferente. Edite na ficha.');foreach($flags as $f=>$v)if((int)$existing[$f]!==$v)throw new RuleViolation('Vínculo existente com atribuições diferentes. Edite na ficha.');return ['status'=>'existente','id'=>$existing['idvinculo'],'mensagem'=>'Vínculo já cadastrado; mantido.'];}
                $result=$this->catalog->linkInside((int)$student['idaluno'],$flags+['codpessoa_responsavel'=>$person['codpessoa'],'parentesco'=>$parent],$key);return ['status'=>'criado','id'=>$result['idvinculo']];
            }
            $period=$this->db->row('SELECT * FROM '.$this->db->table('periodos_letivos').' WHERE codigo=%s AND codcoligada=%d FOR UPDATE',[Input::text($d['codigo_periodo']??null,30),Coligadas::current()]);if(!$period)throw new RuleViolation('Período não encontrado na coligada atual.');
            $class=$this->db->row('SELECT * FROM '.$this->db->table('turmas').' WHERE codigo=%s AND codperiodo=%d AND codcoligada=%d FOR UPDATE',[Input::text($d['codigo_turma']??null,30),(int)$period['codperiodo'],Coligadas::current()]);if(!$class)throw new RuleViolation('Turma não encontrada neste período e coligada.');
            $status=strtolower(trim((string)($d['status']??'reservado')));if(!in_array($status,['reservado','cursando','aprovado','reprovado'],true))throw new RuleViolation('Situação inválida: use reservado, cursando, aprovado ou reprovado.');$day=$d['data_matricula']??Input::today();if(preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D',(string)$day,$m))$day="$m[3]-$m[2]-$m[1]";$day=Input::date($day);
            $existing=$this->db->row('SELECT * FROM '.$this->db->table('matriculas').' WHERE idaluno=%d AND codperiodo=%d AND idcurso=%d AND ativo_unico=1 FOR UPDATE',[(int)$student['idaluno'],(int)$period['codperiodo'],(int)$class['idcurso']]);if($existing){if((int)$existing['idturma_atual']!==(int)$class['idturma'])throw new RuleViolation('Aluno já matriculado em outra turma deste curso e período.');return ['status'=>'existente','id'=>$existing['idmatricula'],'mensagem'=>'Matrícula já cadastrada; mantida.'];}
            $prior=$this->db->row('SELECT * FROM '.$this->db->table('aluno_periodos').' WHERE idaluno=%d AND codperiodo=%d FOR UPDATE',[(int)$student['idaluno'],(int)$period['codperiodo']]);if(!empty($prior['idmatricula']))throw new RuleViolation('Período já vinculado a outra matrícula. Confira a ficha do aluno.');$academic=new AcademicService($this->db,new Operations($this->db));$result=$academic->placeInside((int)$student['idaluno'],(int)$period['codperiodo'],(int)$class['idturma'],$key);$id=(int)$result['idmatricula'];$this->db->update('matriculas',$id,['data_matricula'=>$day,'status'=>$status]);
            $link=$this->db->row('SELECT * FROM '.$this->db->table('aluno_periodos').' WHERE idaluno=%d AND codperiodo=%d FOR UPDATE',[(int)$student['idaluno'],(int)$period['codperiodo']]);if($link)$this->db->update('aluno_periodos',(int)$link['idvinculoperiodo'],['idmatricula'=>$id,'status'=>'matriculado']);else $this->db->insert('aluno_periodos',['idaluno'=>$student['idaluno'],'codperiodo'=>$period['codperiodo'],'idmatricula'=>$id,'status'=>'matriculado']);
            $academic->movement($id,(int)$class['idturma'],(int)$class['idturma'],'importacao','Matrícula importada via CSV','reservado',$status);$this->db->audit('matriculas',$id,'importar_csv',null,['data_matricula'=>$day,'status'=>$status],$key);return ['status'=>'criado','id'=>(string)$id];
        }));
    }
    private function classRow(array $data,string $key):array
    {
        return $this->db->atomic(function()use($data,$key){
            $resolved=[];
            foreach(['periodos_letivos'=>['codigo_periodo','codperiodo'],'cursos'=>['codigo_curso','idcurso'],'turnos'=>['codigo_turno','idturno']] as $table=>$fields){
                $code=Input::text($data[$fields[0]]??null,30);$t=$this->db->table($table);
                $row=$this->db->row("SELECT * FROM $t WHERE codigo=%s AND codcoligada=%d FOR UPDATE",[$code,Coligadas::current()]);
                if(!$row)throw new RuleViolation('Código não encontrado: '.$fields[0].' = '.$code);
                if(isset($row['ativo'])&&!(int)$row['ativo'])throw new RuleViolation('Cadastro inativo: '.$fields[0]);
                if(($row['status']??'')==='encerrado')throw new RuleViolation('Período encerrado.');
                $resolved[$fields[1]]=(int)$row[$fields[1]];
            }
            $resolved['idplano']=null;
            if(!empty($data['codigo_plano'])){
                $t=$this->db->table('planos_pagamento');$plan=$this->db->row("SELECT * FROM $t WHERE codigo=%s AND codcoligada=%d AND codperiodo=%d FOR UPDATE",[Input::text($data['codigo_plano'],30),Coligadas::current(),$resolved['codperiodo']]);
                if(!$plan||!(int)$plan['ativo']||(int)$plan['codperiodo']!==$resolved['codperiodo'])throw new RuleViolation('Plano não encontrado, inativo ou de outro período.');
                $resolved['idplano']=(int)$plan['idplano'];
            }
            $valid=$resolved+['codigo'=>Input::text($data['codigo']??null,30),'nome'=>Input::text($data['nome']??null,100),'capacidade'=>Input::id($data['capacidade']??null)];
            $t=$this->db->table('turmas');$existing=$this->db->row("SELECT * FROM $t WHERE codperiodo=%d AND codigo=%s FOR UPDATE",[$valid['codperiodo'],$valid['codigo']]);
            if($existing){
                foreach($valid as $field=>$value)if((string)$existing[$field] !== (string)$value)throw new RuleViolation('Turma já existe com dados diferentes. Edite o cadastro; a importação não sobrescreve dados.');
                return ['status'=>'existente','id'=>$existing['idturma'],'mensagem'=>'Turma já cadastrada; dados mantidos.'];
            }
            return ['status'=>'criado']+$this->catalog->createInside('turmas',$valid,$key);
        });
    }

}
