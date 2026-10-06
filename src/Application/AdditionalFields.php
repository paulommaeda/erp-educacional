<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,CadastroText};
use EducacionalERP\Infrastructure\WordPress\Access;
/** Definitions and values are shared, like people, across companies. */
final class AdditionalFields
{
    public function __construct(private Store $db){}
    public function all():array{return $this->db->rows('SELECT * FROM '.$this->db->table('campos_adicionais').' ORDER BY ordem,idcampo');}
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
            $row=['chave'=>$slug,'nome'=>Input::text($d['nome']??null,120),'tipo'=>$type,'secao'=>$section,'exibir_pessoa'=>empty($d['exibir_pessoa'])?0:1,'opcoes'=>$options,'ordem'=>max(0,min(9999,(int)($d['ordem']??0))),'ativo'=>array_key_exists('ativo',$d)&&empty($d['ativo'])?0:1];
            if($before){if((string)($d['versao']??'')!==(string)$before['versao'])throw new RuleViolation('Campo alterado. Recarregue a lista.');$this->db->update('campos_adicionais',$id,$row);}else $id=$this->db->insert('campos_adicionais',$row);
            $this->db->audit('campos_adicionais',$id,$before?'editar':'criar',$before,$row,$key);return $this->db->get('campos_adicionais',$id);
        });
    }
    public function decorate(array $p):array
    {
        $values=$this->db->rows('SELECT c.chave,v.valor FROM '.$this->db->table('pessoa_campos_adicionais').' v JOIN '.$this->db->table('campos_adicionais').' c ON c.idcampo=v.idcampo WHERE v.codpessoa=%d AND c.ativo=1',[(int)$p['codpessoa']]);
        $p['campos_adicionais']=[];foreach($values as $v)$p['campos_adicionais'][$v['chave']]=$v['valor'];return $p;
    }
    public function write(int $person,mixed $values,string $key,bool $onlyVisible=false):void
    {
        if($values===null)return;if(!is_array($values)||count($values)>200)throw new RuleViolation('Campos adicionais inválidos.');
        $definitions=[];foreach($this->all() as $d)$definitions[$d['chave']]=$d;
        $this->db->get('pessoas',$person,true);
        foreach($values as $slug=>$value){$d=$definitions[$slug]??null;if(!$d||!(int)$d['ativo']||($onlyVisible&&!(int)$d['exibir_pessoa']))throw new RuleViolation('Campo adicional não permitido: '.$slug);
            if(!is_scalar($value)&&$value!==null)throw new RuleViolation('Valor inválido: '.$d['nome']);$v=trim((string)$value);if(strlen($v)>10000)throw new RuleViolation('Valor muito longo: '.$d['nome']);
            if($v!==''){switch($d['tipo']){case 'data':$v=Input::date($v);break;case 'numero':if(!preg_match('/^-?\d+(\.\d+)?$/D',$v))throw new RuleViolation('Número inválido: '.$d['nome']);break;case 'email':if(!is_email($v))throw new RuleViolation('E-mail inválido: '.$d['nome']);$v=strtolower($v);break;case 'selecao':if(!in_array($v,array_map('trim',preg_split('/\r?\n/',$d['opcoes'])),true))throw new RuleViolation('Opção inválida: '.$d['nome']);break;default:$v=CadastroText::upper($v);}}
            $t=$this->db->table('pessoa_campos_adicionais');$before=$this->db->row("SELECT * FROM $t WHERE codpessoa=%d AND idcampo=%d FOR UPDATE",[$person,(int)$d['idcampo']]);$row=['codpessoa'=>$person,'idcampo'=>(int)$d['idcampo'],'valor'=>$v];
            if($before)$this->db->update('pessoa_campos_adicionais',(int)$before['idvalor'],['valor'=>$v]);else $this->db->insert('pessoa_campos_adicionais',$row);
            $this->db->audit('pessoa_campos_adicionais',$person,'editar',$before,$row,$key);
        }
    }
}
