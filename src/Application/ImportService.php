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
        if(!in_array($type,['pessoas','alunos'],true))throw new RuleViolation('Tipo de importação inválido.');
        $origin=Input::text($data['codpessoa_origem']??null,80);
        return $this->db->atomic(function()use($type,$data,$key,$origin){
            $p=$this->db->table('pessoas');$a=$this->db->table('alunos');
            $person=$this->db->row("SELECT * FROM $p WHERE codpessoa_origem=%s FOR UPDATE",[$origin]);
            if($type==='pessoas') {
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
}
