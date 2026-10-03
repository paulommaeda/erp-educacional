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
            $existing=$this->db->row("SELECT * FROM $a WHERE codpessoa=%d OR ra=%s FOR UPDATE",[(int)$person['codpessoa'],$ra]);
            if($existing) {
                if((int)$existing['codpessoa']===(int)$person['codpessoa'] && $existing['ra']===$ra)return ['status'=>'existente','id'=>$existing['idaluno'],'mensagem'=>'Pessoa e RA já vinculados; dados mantidos.'];
                throw new RuleViolation('Conflito: pessoa já possui outro RA ou este RA pertence a outra pessoa. Nenhum vínculo foi alterado.');
            }
            if(!(int)$person['ativo'])throw new RuleViolation('Pessoa inativa.');
            return ['status'=>'criado']+$this->catalog->createInside('alunos',['codpessoa'=>$person['codpessoa'],'ra'=>$ra,'tipo_aluno'=>$data['tipo_aluno']??'Regular'],$key);
        });
    }
    private function classRow(array $data,string $key):array
    {
        return $this->db->atomic(function()use($data,$key){
            $resolved=[];
            foreach(['periodos_letivos'=>['codigo_periodo','codperiodo'],'cursos'=>['codigo_curso','idcurso'],'turnos'=>['codigo_turno','idturno']] as $table=>$fields){
                $code=Input::text($data[$fields[0]]??null,30);$t=$this->db->table($table);
                $row=$this->db->row("SELECT * FROM $t WHERE codigo=%s FOR UPDATE",[$code]);
                if(!$row)throw new RuleViolation('Código não encontrado: '.$fields[0].' = '.$code);
                if(isset($row['ativo'])&&!(int)$row['ativo'])throw new RuleViolation('Cadastro inativo: '.$fields[0]);
                if(($row['status']??'')==='encerrado')throw new RuleViolation('Período encerrado.');
                $resolved[$fields[1]]=(int)$row[$fields[1]];
            }
            $resolved['idplano']=null;
            if(!empty($data['codigo_plano'])){
                $t=$this->db->table('planos_pagamento');$plan=$this->db->row("SELECT * FROM $t WHERE codigo=%s FOR UPDATE",[Input::text($data['codigo_plano'],30)]);
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
