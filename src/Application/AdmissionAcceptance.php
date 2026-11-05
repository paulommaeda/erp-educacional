<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,AdmissionForm};
use EducacionalERP\Infrastructure\WordPress\Accounts;
/** Shared transaction composition for reviewed native and API admission requests. */
final class AdmissionAcceptance
{
    public function __construct(private Store $db,private CatalogService $catalog){}
    public function locked(callable $fn):array
    {
        $lock='erp_admissions_'.substr(hash('sha256',$this->db->table('pessoas')),0,35);
        if((int)($this->db->row('SELECT GET_LOCK(%s,10) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Outra conferência está sendo aprovada. Aguarde e tente novamente.');
        try{return $fn();}finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
    public function inside(array $data,array $choices,string $key,bool $writeEmptyContacts=true):array
    {
            $people=[];
            foreach($data['pessoas'] as $slot=>$p){$choice=$choices[$slot]??[];$action=$choice['acao']??'';
                if($action==='existente'){$pid=Input::id($choice['codpessoa']??null);$person=$this->db->get('pessoas',$pid,true);if(!(int)$person['ativo'])throw new RuleViolation('Pessoa inativa.');(new Accounts($this->db))->ensure($pid);$people[$slot]=['codpessoa'=>$pid,'acao'=>'existente'];}
                elseif($action==='reutilizar'){$ref=$choice['referencia']??'';if(!isset($people[$ref])||$ref==='aluno')throw new RuleViolation('Escolha um responsável já conferido neste requerimento.');$people[$slot]=$people[$ref];$people[$slot]['acao']='reutilizar';}
                elseif($action==='criar'){
                    // Never auto-merge by CPF, shared e-mail or approximate name.
                    $existing=$this->db->row('SELECT codpessoa FROM '.$this->db->table('pessoas').' WHERE nome=%s AND data_nascimento=%s LIMIT 1',[$p['nome'],$p['data_nascimento']]);if($existing&&($choice['confirmar_nova']??false)!==true)throw new RuleViolation((AdmissionForm::PEOPLE[$slot]??'Outro responsável').': já existe uma pessoa com este nome e nascimento. Selecione o cadastro existente ou corrija os dados.');
                    $p['campos_adicionais']=($p['campos_adicionais']??[])+['empresa'=>$p['empresa']??''];unset($p['empresa'],$p['parentesco']);if(isset($choice['user_login']))$p['user_login']=$choice['user_login'];
                    try{$created=$slot==='aluno'?$this->catalog->createAdmissionStudentInside($p,$key):$this->catalog->createInside('pessoas',$p,$key);}catch(\EducacionalERP\Domain\UsernameRequired $e){throw new \EducacionalERP\Domain\UsernameRequired((AdmissionForm::PEOPLE[$slot]??'Outro responsável').': '.$e->getMessage());}$people[$slot]=['codpessoa'=>(int)$created['id'],'acao'=>'criar'];
                }else throw new RuleViolation('Escolha criar ou aproveitar cadastro para '.(AdmissionForm::PEOPLE[$slot]??'Outro responsável').'.');
            }
            $pid=$people['aluno']['codpessoa'];foreach($people as $slot=>$p)if($slot!=='aluno'&&$p['codpessoa']===$pid)throw new RuleViolation('O aluno não pode ser seu próprio responsável.');
            $a=$this->db->row('SELECT * FROM '.$this->db->table('alunos').' WHERE codcoligada=%d AND codpessoa=%d FOR UPDATE',[Coligadas::current(),$pid]);if($a&&!(int)$a['ativo'])throw new RuleViolation('Aluno inativo. Reative o cadastro antes de aprovar.');
            $student=$a?(int)$a['idaluno']:(int)$this->catalog->createInside('alunos',['codpessoa'=>$pid,'ra'=>(string)$this->db->get('pessoas',$pid)['codigo_pessoa'],'tipo_aluno'=>'REGULAR'],$key)['id'];
            $extras=$data['campos'];$definitions=array_column($this->db->rows('SELECT chave,tipo,opcoes FROM '.$this->db->table('campos_adicionais')),null,'chave');foreach($extras as $field=>&$value){$definition=$definitions[$field]??null;if($definition&&$definition['tipo']==='selecao'&&$value!=='')foreach(preg_split('/\r?\n/',$definition['opcoes']) as $option)if(\EducacionalERP\Domain\CadastroText::upper($value)===\EducacionalERP\Domain\CadastroText::upper(trim($option))){$value=trim($option);break;}}unset($value);if($writeEmptyContacts||$data['contatos_emergencia'])$extras['contatos_emergencia']=implode("\n\n",array_map(fn($c)=>$c['nome']."\n".$c['telefone']."\n".$c['parentesco'],$data['contatos_emergencia']));(new AdditionalFields($this->db))->write($pid,$extras,$key);
            $merged=[];foreach($people as $slot=>$p){if($slot==='aluno')continue;$person=$p['codpessoa'];$row=$merged[$person]??['codpessoa_responsavel'=>$person,'parentesco'=>match($slot){'pai'=>'pai','mae'=>'mae',default=>$data['pessoas'][$slot]['parentesco']??'outro'},'responsavel_academico'=>0,'responsavel_financeiro'=>0,'pode_rematricular'=>0];if($data['responsavel_academico']===$slot){$row['responsavel_academico']=1;$row['pode_rematricular']=1;}if($data['responsavel_financeiro']===$slot)$row['responsavel_financeiro']=1;$merged[$person]=$row;}
            foreach($merged as $row){$current=$this->db->row('SELECT * FROM '.$this->db->table('aluno_responsaveis').' WHERE idaluno=%d AND codpessoa_responsavel=%d AND fim_vigencia IS NULL FOR UPDATE',[$student,$row['codpessoa_responsavel']]);if($current){foreach(['responsavel_academico','responsavel_financeiro','pode_rematricular'] as $flag)if((int)$current[$flag]!==$row[$flag])throw new RuleViolation('O aluno já possui vínculos com atribuições diferentes. Ajuste-os na ficha antes de aprovar este requerimento.');}else $this->catalog->linkInside($student,$row,$key);}
            (new StudentPickup($this->db))->importInside($student,$data['retiradas'],$people,$key);
            foreach($data['pessoas'] as $slot=>$person)if(!empty($person['campos_adicionais']))(new AdditionalFields($this->db))->write($people[$slot]['codpessoa'],$person['campos_adicionais'],$key);
            return ['idaluno'=>$student,'ra'=>$this->db->get('alunos',$student)['ra'],'pessoas'=>$people];
    }
}
