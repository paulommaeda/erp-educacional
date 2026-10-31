<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,AdmissionForm,RuleViolation};
/** The API uses the same form and acceptance as the native request, retaining its own source log. */
final class ExternalAdmissionReview
{
    public function __construct(private Store $db,private CatalogService $catalog){}
    public function access():void {(new PublicAdmissions($this->db,$this->catalog))->checkReviewAccess();}
    public function form(array $data):array
    {
        $fields=AdmissionForm::fields($this->db);$known=array_column($fields,null,'chave');$defs=array_column((new AdditionalFields($this->db))->all(),null,'chave');$per=[];
        foreach($fields as &$f)$f['obrigatorio']=false;unset($f);
        foreach($data['campos']??[] as $key=>$value)if(!isset($known[$key])&&isset($defs[$key]))$fields[]=$this->definition($defs[$key]);
        foreach($data['pessoas']??[] as $slot=>$p)foreach($p['campos_adicionais']??[] as $key=>$value)if(isset($defs[$key]))$per[$slot][]=$this->definition($defs[$key]);
        return ['origem_api'=>true,'campos'=>$fields,'campos_por_pessoa'=>$per,'estados_civis'=>$this->db->rows('SELECT idestado_civil,nome FROM '.$this->db->table('estados_civis').' ORDER BY nome'),'configuracao'=>['privacidade'=>'Confira os dados recebidos da API. Campos ausentes podem ser completados antes da aprovação.','declaracao'=>'Conferi as informações e autorizo o cadastro da família no ERP.']];
    }
    private function definition(array $d):array {return ['chave'=>$d['chave'],'nome'=>$d['nome'],'tipo'=>$d['tipo'],'area'=>'familia','opcoes'=>$d['tipo']==='selecao'?array_values(array_filter(array_map('trim',preg_split('/\r?\n/',$d['opcoes'])))):[],'obrigatorio'=>false];}
    public function approve(array $original,array $d,string $key):array
    {
        $this->access();if(($d['conferencia_confirmada']??false)!==true)throw new RuleViolation('Confirme que conferiu os dados recebidos da API.');if(!is_array($d['dados']??null)||!is_array($d['pessoas']??null))throw new RuleViolation('Confira as pessoas e os dados do requerimento.');
        $data=AdmissionForm::validate($d['dados'],true,$this->form($original)['campos'],$original,true);
        if(array_diff(array_keys($d['pessoas']),array_keys($data['pessoas'])))throw new RuleViolation('Confira todas as pessoas do requerimento.');
        // Only fields actually supplied by mapping, or filled by the reviewer, replace existing values.
        foreach($data['campos'] as $field=>$value)if($value===''&&($original['campos'][$field]??'')==='')unset($data['campos'][$field]);
        foreach($data['pessoas'] as $slot=>&$person)foreach($person['campos_adicionais']??[] as $field=>$value)if($value===''&&($original['pessoas'][$slot]['campos_adicionais'][$field]??'')==='')unset($person['campos_adicionais'][$field]);unset($person);
        $result=(new AdmissionAcceptance($this->db,$this->catalog))->inside($data,$d['pessoas'],$key,!empty($original['contatos_emergencia']));return ['dados'=>$data,'resultado'=>$result];
    }
}
