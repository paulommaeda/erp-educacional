<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,Money,RuleViolation,CadastroText};
use EducacionalERP\Infrastructure\WordPress\Access;
final class ScholarshipCatalog
{
    public function __construct(private Store $db){}
    public function seed(int $company):void
    {
        if(!(int)$this->db->get('coligadas',$company)['ativo'])return;
        $name='erp_bolsas_seed_'.substr(hash('sha256',$this->db->table('bolsas').'|'.$company),0,40);if((int)($this->db->row('SELECT GET_LOCK(%s,3) AS acquired',[$name])['acquired']??0)!==1)throw new RuleViolation('Cadastro de bolsas em atualização. Tente novamente.');
        try{foreach(['integral'=>['BOLSA INTEGRAL','100.00'],'cinquenta'=>['BOLSA 50%','50.00']] as $code=>[$label,$value])if(!$this->db->row('SELECT idbolsa FROM '.$this->db->table('bolsas').' WHERE codcoligada=%d AND codigo_padrao=%s',[$company,$code]))$this->db->insert('bolsas',['codcoligada'=>$company,'codigo_padrao'=>$code,'nome'=>$label,'descricao'=>$code==='integral'?'Isenção integral da mensalidade e do acréscimo de pontualidade.':'Metade da mensalidade-base, com pontualidade separada.','forma_calculo'=>'percentual','valor'=>$value]);}
        finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$name]);}
    }
    public function seedAll():void {foreach($this->db->rows('SELECT codcoligada FROM '.$this->db->table('coligadas').' WHERE ativo=1') as $c)$this->seed((int)$c['codcoligada']);}
    public function listing():array
    {
        Access::requireAdmin();$this->seed(Coligadas::current());return ['items'=>$this->db->rows('SELECT * FROM '.$this->db->table('bolsas').' WHERE codcoligada=%d ORDER BY ativo DESC,idbolsa',[Coligadas::current()])];
    }
    public function get(int $id,bool $lock=false):array
    {
        $row=$this->db->get('bolsas',$id,$lock);if((int)$row['codcoligada']!==Coligadas::current())throw new RuleViolation('Bolsa de outra coligada.');return $row;
    }
    public function save(array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'bolsa_cadastrar',$data,function()use($data,$key){$old=isset($data['idbolsa'])?$this->get(Input::id($data['idbolsa']),true):null;if($old&&(string)($data['versao']??'')!==(string)$old['versao'])throw new RuleViolation('Bolsa alterada. Recarregue o cadastro.');
            $type=$data['forma_calculo']??'percentual';$value=Money::positive($data['valor']??null);if(!in_array($type,['percentual','valor'],true)||($type==='percentual'&&$value>10000))throw new RuleViolation('Informe um percentual até 100% ou um valor fixo válido.');if(!in_array($data['ativo']??1,[0,1,'0','1',true,false],true))throw new RuleViolation('Situação da bolsa inválida.');
            if(!empty($old['codigo_padrao'])&&($type!=='percentual'||$value!==($old['codigo_padrao']==='integral'?10000:5000)))throw new RuleViolation('As bolsas padrão mantêm seus percentuais. Cadastre outra bolsa para uma condição diferente.');
            $description=$data['descricao']??'';if(!is_string($description))throw new RuleViolation('Descrição inválida.');$fields=['nome'=>CadastroText::upper(Input::text($data['nome']??null)),'descricao'=>trim($description)===''?null:CadastroText::upper(Input::text($description,2000)),'forma_calculo'=>$type,'valor'=>Money::format($value),'ativo'=>empty($data['ativo']??1)?0:1];if($old){$id=(int)$old['idbolsa'];$this->db->update('bolsas',$id,$fields);}else$id=$this->db->insert('bolsas',$fields);$this->db->audit('bolsas',$id,$old?'editar':'criar',$old,$this->get($id),$key);return ['idbolsa'=>(string)$id];
        }));
    }
}
