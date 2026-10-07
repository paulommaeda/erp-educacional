<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,CadastroText};
use EducacionalERP\Infrastructure\WordPress\{Access,FieldGroupPolicy};
/** Definitions and values are shared, like people, across companies. */
final class AdditionalFields
{
    public function __construct(private Store $db){}
    private function definitions():array{return $this->db->rows('SELECT * FROM '.$this->db->table('campos_adicionais').' ORDER BY ordem,idcampo');}
    public function groups():array
    {
        $rows=$this->db->rows('SELECT * FROM '.$this->db->table('grupos_campos').' ORDER BY ordem,idgrupo');
        return Access::isAdmin()?$rows:array_values(array_filter($rows,fn($g)=>(int)$g['ativo']&&FieldGroupPolicy::can((int)$g['idgrupo'])));
    }
    public function all():array
    {
        $rows=$this->definitions();if(Access::isAdmin())return $rows;$groups=array_column($this->groups(),null,'idgrupo');
        return array_values(array_filter($rows,fn($d)=>(int)$d['ativo']&&isset($groups[$d['idgrupo']])));
    }
    public function migrateGroups():void
    {
        $table=$this->db->table('grupos_campos');
        $g=$this->db->row('SELECT * FROM '.$table.' ORDER BY idgrupo LIMIT 1');
        if(!$g){$id=$this->db->insert('grupos_campos',['nome'=>'Dados complementares','ordem'=>0,'ativo'=>1]);}else $id=(int)$g['idgrupo'];
        $this->db->query('UPDATE '.$this->db->table('campos_adicionais').' SET idgrupo=%d WHERE idgrupo IS NULL',[$id]);
    }
    public function saveGroup(array $d,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(function()use($d,$key){
            $id=empty($d['idgrupo'])?null:Input::id($d['idgrupo']);$before=$id?$this->db->get('grupos_campos',$id,true):null;
            if($before&&(string)($d['versao']??'')!==(string)$before['versao'])throw new RuleViolation('Grupo alterado. Recarregue a lista.');
            $row=['nome'=>Input::text($d['nome']??null,120),'ordem'=>max(0,min(9999,(int)($d['ordem']??0))),'ativo'=>array_key_exists('ativo',$d)&&empty($d['ativo'])?0:1];
            if($before)$this->db->update('grupos_campos',$id,$row);else $id=$this->db->insert('grupos_campos',$row);
            $this->db->audit('grupos_campos',$id,$before?'editar':'criar',$before,$row,$key);return $this->db->get('grupos_campos',$id);
        });
    }
    public function save(array $d,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(function()use($d,$key){
            $id=empty($d['idcampo'])?null:Input::id($d['idcampo']);$before=$id?$this->db->get('campos_adicionais',$id,true):null;
            $slug=(string)($d['chave']??'');if(!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$slug))throw new RuleViolation('A chave deve usar letras minúsculas, números e sublinhado, começando por letra.');
            if($before&&$before['chave']!==$slug)throw new RuleViolation('A chave de integração não pode ser alterada.');
            $type=$d['tipo']??'texto';if(!in_array($type,['texto','texto_longo','numero','data','email','selecao'],true))throw new RuleViolation('Tipo inválido.');
            if($before&&$before['tipo']!==$type)throw new RuleViolation('O tipo não pode ser alterado após a criação.');
            $section=$d['secao']??'outros';if(!in_array($section,['identificacao','pessoais','endereco','outros'],true))throw new RuleViolation('Seção inválida.');
            $options=trim((string)($d['opcoes']??''));if(strlen($options)>10000)throw new RuleViolation('Lista de opções muito longa.');
            if($type==='selecao'&&!$options)throw new RuleViolation('Informe uma opção por linha.');
            $group=Input::id($d['idgrupo']??($before['idgrupo']??null));$this->db->get('grupos_campos',$group,true);
            $condition=$d['condicao_exibicao']??($before['condicao_exibicao']??'sempre');if(!in_array($condition,['sempre','preenchido','vazio','igual','diferente'],true))throw new RuleViolation('Condição de exibição inválida.');
            if(isset($d['resposta_exibicao'])&&!is_string($d['resposta_exibicao']))throw new RuleViolation('Resposta de exibição inválida.');$answer=trim((string)($d['resposta_exibicao']??($before['resposta_exibicao']??'')));if(strlen($answer)>1000)throw new RuleViolation('Resposta de exibição muito longa.');if(in_array($condition,['igual','diferente'],true)&&$answer==='')throw new RuleViolation('Informe a resposta para comparação.');
            $row=['condicao_exibicao'=>$condition,'resposta_exibicao'=>$answer,'idgrupo'=>$group,'chave'=>$slug,'nome'=>Input::text($d['nome']??null,120),'tipo'=>$type,'secao'=>$section,'exibir_pessoa'=>empty($d['exibir_pessoa'])?0:1,'opcoes'=>$options,'ordem'=>max(0,min(9999,(int)($d['ordem']??0))),'ativo'=>array_key_exists('ativo',$d)&&empty($d['ativo'])?0:1];
            if($before){if((string)($d['versao']??'')!==(string)$before['versao'])throw new RuleViolation('Campo alterado. Recarregue a lista.');$this->db->update('campos_adicionais',$id,$row);}else $id=$this->db->insert('campos_adicionais',$row);
            $this->db->audit('campos_adicionais',$id,$before?'editar':'criar',$before,$row,$key);return $this->db->get('campos_adicionais',$id);
        });
    }
    public function remove(string $type,int $id,array $d,string $key):array
    {
        Access::requireAdmin();if(!in_array($type,['campos_adicionais','grupos_campos'],true))throw new RuleViolation('Exclusão inválida.');
        return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'excluir_'.$type,['id'=>$id]+$d,function()use($type,$id,$d,$key){
            $before=$this->db->get($type,$id,true);if((string)($d['versao']??'')!==(string)$before['versao'])throw new RuleViolation('Cadastro alterado. Recarregue antes de excluir.');
            $count=0;
            if($type==='grupos_campos'){
                if($this->db->row('SELECT idcampo FROM '.$this->db->table('campos_adicionais').' WHERE idgrupo=%d LIMIT 1',[$id]))throw new RuleViolation('Este grupo possui campos vinculados. Transfira ou exclua os campos antes de excluir o grupo.');
            }else{
                $t=$this->db->table('pessoa_campos_adicionais');$count=(int)$this->db->row("SELECT COUNT(*) AS n FROM $t WHERE idcampo=%d",[$id])['n'];
                if(($d['confirmar_exclusao']??null)!==true)throw new RuleViolation('Confirme a exclusão do campo e dos valores cadastrados.');
                $this->db->query("DELETE FROM $t WHERE idcampo=%d",[$id]);
            }
            $pk=$type==='grupos_campos'?'idgrupo':'idcampo';$this->db->query('DELETE FROM '.$this->db->table($type)." WHERE $pk=%d",[$id]);
            $this->db->audit($type,$id,'excluir',$before,['valores_excluidos'=>$count],$key);return ['excluido'=>true,'valores_excluidos'=>$count];
        }));
    }
    public function decorate(array $p):array
    {
        $values=$this->db->rows('SELECT c.chave,v.valor FROM '.$this->db->table('pessoa_campos_adicionais').' v JOIN '.$this->db->table('campos_adicionais').' c ON c.idcampo=v.idcampo WHERE v.codpessoa=%d AND c.ativo=1',[(int)$p['codpessoa']]);
        $permitted=array_column($this->all(),null,'chave');$activeGroups=array_column(array_filter($this->groups(),fn($g)=>(int)$g['ativo']),null,'idgrupo');
        $p['campos_adicionais']=[];foreach($values as $v)if(isset($permitted[$v['chave']])&&isset($activeGroups[$permitted[$v['chave']]['idgrupo']]))$p['campos_adicionais'][$v['chave']]=$v['valor'];return $p;
    }
    public function write(int $person,mixed $values,string $key,bool $onlyVisible=false):void
    {
        if($values===null)return;if(!is_array($values)||count($values)>200)throw new RuleViolation('Campos adicionais inválidos.');
        $definitions=[];foreach($this->all() as $d)$definitions[$d['chave']]=$d;
        $this->db->get('pessoas',$person,true);
        foreach($values as $slug=>$value){$d=$definitions[$slug]??null;if(!$d||!(int)$d['ativo']||!FieldGroupPolicy::can((int)$d['idgrupo'])||!(int)$this->db->get('grupos_campos',(int)$d['idgrupo'])['ativo']||($onlyVisible&&!(int)$d['exibir_pessoa']))throw new RuleViolation('Campo adicional não permitido: '.$slug);
            if(!is_scalar($value)&&$value!==null)throw new RuleViolation('Valor inválido: '.$d['nome']);$v=trim((string)$value);if(strlen($v)>10000)throw new RuleViolation('Valor muito longo: '.$d['nome']);
            if($v!==''){switch($d['tipo']){case 'data':$v=Input::date($v);break;case 'numero':if(!preg_match('/^-?\d+(\.\d+)?$/D',$v))throw new RuleViolation('Número inválido: '.$d['nome']);break;case 'email':if(!is_email($v))throw new RuleViolation('E-mail inválido: '.$d['nome']);$v=strtolower($v);break;case 'selecao':if(!in_array($v,array_map('trim',preg_split('/\r?\n/',$d['opcoes'])),true))throw new RuleViolation('Opção inválida: '.$d['nome']);break;default:$v=CadastroText::upper($v);}}
            $t=$this->db->table('pessoa_campos_adicionais');$before=$this->db->row("SELECT * FROM $t WHERE codpessoa=%d AND idcampo=%d FOR UPDATE",[$person,(int)$d['idcampo']]);$row=['codpessoa'=>$person,'idcampo'=>(int)$d['idcampo'],'valor'=>$v];
            if($before)$this->db->update('pessoa_campos_adicionais',(int)$before['idvalor'],['valor'=>$v]);else $this->db->insert('pessoa_campos_adicionais',$row);
            $this->db->audit('pessoa_campos_adicionais',$person,'editar',$before,$row,$key);
        }
    }
}
