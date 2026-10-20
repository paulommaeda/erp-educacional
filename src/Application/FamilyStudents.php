<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,PersonFields,CadastroText};
use EducacionalERP\Infrastructure\WordPress\{FamilyAccess,Accounts};
final class FamilyStudents
{
    public const FIELDS=['email','telefone','rua','numero','complemento','bairro','cep','cidade','estado','pais'];
    public function __construct(private Store $db){}
    public function detail(int $student):array
    {
        $a=(new FamilyAccess($this->db))->student($student);$p=$this->db->get('pessoas',(int)$a['codpessoa']);
        return ['idaluno'=>$student,'nome'=>$p['nome'],'ra'=>$a['ra'],'versao'=>$p['versao'],'contato'=>array_intersect_key($p,array_flip(self::FIELDS))];
    }
    public function update(int $student,array $data,string $key):array
    {
        if(array_diff(array_keys($data),[...self::FIELDS,'versao']))throw new RuleViolation('Envie apenas os dados de contato permitidos.');
        foreach($data as $value)if($value!==null&&!is_scalar($value))throw new RuleViolation('Contato inválido.');
        $a=(new FamilyAccess($this->db))->student($student);
        return Coligadas::within((int)$a['codcoligada'],fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'familia_contato_aluno',['idaluno'=>$student]+$data,function()use($student,$data,$key){
            $a=(new FamilyAccess($this->db))->student($student,true);$id=(int)$a['codpessoa'];$before=$this->db->get('pessoas',$id,true);
            if(!isset($data['versao'])||(string)$data['versao']!==$before['versao'])throw new RuleViolation('Os dados do aluno foram atualizados. Reabra o cadastro antes de salvar.');
            $merged=array_merge($before,$data);$address=PersonFields::validate(array_merge($merged,['foto_attachment_id'=>null]));
            $email=trim((string)($merged['email']??''));if($email!==''&&!is_email($email))throw new RuleViolation('Informe um e-mail válido.');
            $valid=array_intersect_key($address,array_flip(self::FIELDS));$valid['email']=$email===''?null:sanitize_email($email);$valid['telefone']=empty($merged['telefone'])?null:Input::text($merged['telefone'],30);$valid=CadastroText::normalize($valid);
            $this->db->update('pessoas',$id,$valid);(new Accounts($this->db))->sync($id);
            $this->db->audit('pessoas',$id,'contato_aluno_pelo_responsavel',array_intersect_key($before,array_flip(self::FIELDS)),$valid+['idaluno'=>$student],$key);
            return ['salvo'=>true,'versao'=>(string)((int)$before['versao']+1)];
        })));
    }
}
