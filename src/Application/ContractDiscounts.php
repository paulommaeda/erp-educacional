<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,Money,RuleViolation,CadastroText};
use EducacionalERP\Infrastructure\WordPress\Access;
final class ContractDiscounts
{
    public function __construct(private Store $db,private Operations $ops){}
    public static function conditional(array $title,?string $day=null):int
    {
        if(in_array($title['status'],['cancelado','quitado'],true)||($day??Input::today())>$title['vencimento'])return 0;
        // Applied conditional adjustments are preserved; grant this rule only once at settlement.
        if(Money::cents($title['desconto_condicional_aplicado']??'0.00')>0)return 0;
        return min(Money::cents(Coligadas::within((int)($title['codcoligada']??Coligadas::current()),fn()=>get_option(Coligadas::option('ederp_pontualidade'),'0.00'))),max(0,Money::cents($title['valor_liquido'])-Money::cents($title['valor_baixa'])));
    }
    public function settings():array{Access::requireAdmin();return ['valor'=>get_option(Coligadas::option('ederp_pontualidade'),'0.00')];}
    public function saveSettings(array $data,string $key):array
    {
        Access::requireAdmin();$value=Money::cents($data['valor']??null);if($value<0)throw new RuleViolation('Desconto não pode ser negativo.');$name='ederp_pontualidade_'.substr(hash('sha256',$this->db->table('contratos')),0,20);
        if((int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$name])['acquired']??0)!==1)throw new RuleViolation('Configuração em atualização.');
        try{$before=get_option(Coligadas::option('ederp_pontualidade'),'0.00');$after=Money::format($value);$this->db->audit('configuracoes',0,'pontualidade',['valor'=>$before],['valor'=>$after],$key);update_option(Coligadas::option('ederp_pontualidade'),$after,false);return ['valor'=>$after];}finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$name]);}
    }
    private function lock(int $id):array
    {
        $c=$this->db->get('contratos',$id);$m=$this->db->get('matriculas',(int)$c['idmatricula']);$this->db->get('alunos',(int)$m['idaluno'],true);$c=$this->db->get('contratos',$id,true);if($c['status']!=='ativo')throw new RuleViolation('Contrato não está ativo.');return $c;
    }
    public function contracts(int $student):array
    {
        Access::requireAdmin();return $this->db->rows('SELECT c.* FROM '.$this->db->table('contratos').' c JOIN '.$this->db->table('matriculas').' m ON m.idmatricula=c.idmatricula WHERE m.idaluno=%d ORDER BY c.idcontrato DESC',[$student]);
    }
    public function list(int $id):array
    {
        Access::requireAdmin();$contract=$this->db->get('contratos',$id);return ['contrato'=>$contract,'descontos'=>$this->db->rows('SELECT * FROM '.$this->db->table('contrato_descontos').' WHERE idcontrato=%d ORDER BY iddesconto',[$id])];
    }
    public function add(int $id,array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,'contrato_desconto_adicionar',['idcontrato'=>$id]+$data,function()use($id,$data,$key){$contract=$this->lock($id);if((int)$contract['parcelas_geradas']&&(int)$contract['quantidade_parcelas']===0)throw new RuleViolation('Contrato sem parcelas.');$first=Input::id($data['parcela_inicio']??null);$last=Input::id($data['parcela_fim']??null);if($first>$last||$last>(int)$contract['quantidade_parcelas'])throw new RuleViolation('Faixa de parcelas inválida.');$discount=$this->db->insert('contrato_descontos',['idcontrato'=>$id,'nome'=>CadastroText::upper(Input::text($data['nome']??null)),'valor'=>Money::format(Money::positive($data['valor']??null)),'parcela_inicio'=>$first,'parcela_fim'=>$last,'ator_wp_user_id'=>get_current_user_id()]);$this->applyInside($id,$key);$this->db->audit('contrato_descontos',$discount,'criar',null,$this->db->get('contrato_descontos',$discount),$key);return ['iddesconto'=>(string)$discount];}));
    }
    /** Caller holds student and contract locks. Apply once to unpaid titles, including generation later. */
    public function applyInside(int $contract,string $key):void
    {
        $discounts=$this->db->rows('SELECT * FROM '.$this->db->table('contrato_descontos').' WHERE idcontrato=%d AND ativo=1 ORDER BY iddesconto FOR UPDATE',[$contract]);
        $titles=$this->db->rows('SELECT l.*,p.numero FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY l.idlancamento FOR UPDATE',[$contract]);
        foreach($titles as $title){if(in_array($title['status'],['cancelado','quitado'],true)||Money::cents($title['valor_baixa'])>0)continue;$id=(int)$title['idlancamento'];$delta=0;
            foreach($discounts as $discount){if((int)$title['numero']<(int)$discount['parcela_inicio']||(int)$title['numero']>(int)$discount['parcela_fim'])continue;$map=$this->db->row('SELECT idaplicacao FROM '.$this->db->table('desconto_lancamentos').' WHERE iddesconto=%d AND idlancamento=%d',[(int)$discount['iddesconto'],$id]);if($map)continue;$delta+=Money::cents($discount['valor']);$this->db->insert('desconto_lancamentos',['iddesconto'=>$discount['iddesconto'],'idlancamento'=>$id,'valor'=>$discount['valor']]);}
            if($delta)$this->changeTitle($title,$delta,$key,'conceder_bolsa');
        }
    }
    private function changeTitle(array $title,int $delta,string $key,string $action):void
    {
        $discount=Money::cents($title['desconto_incondicional'])+$delta;$net=Money::cents($title['valor_original'])-$discount;$balance=$net-Money::cents($title['desconto_condicional_aplicado'])+Money::cents($title['juros_aplicados'])+Money::cents($title['multa_aplicada'])-Money::cents($title['valor_baixa']);if($discount<0||$net<0||$balance<0)throw new RuleViolation('A soma dos descontos ultrapassa o valor da parcela.');$id=(int)$title['idlancamento'];$this->db->update('lancamentos',$id,['desconto_incondicional'=>Money::format($discount),'valor_liquido'=>Money::format($net),'saldo_aberto'=>Money::format($balance),'status'=>$balance===0?'quitado':'aberto']);$this->db->audit('lancamentos',$id,$action,$title,$this->db->get('lancamentos',$id),$key);
    }
    public function remove(int $id,array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,'contrato_desconto_excluir',['iddesconto'=>$id]+$data,function()use($id,$data,$key){$d=$this->db->get('contrato_descontos',$id);$this->lock((int)$d['idcontrato']);$d=$this->db->get('contrato_descontos',$id,true);if((string)($data['versao']??'')!==(string)$d['versao'])throw new RuleViolation('Desconto alterado. Recarregue.');if(!(int)$d['ativo'])throw new RuleViolation('Desconto já excluído.');$maps=$this->db->rows('SELECT * FROM '.$this->db->table('desconto_lancamentos').' WHERE iddesconto=%d AND revertido_em IS NULL ORDER BY idlancamento FOR UPDATE',[$id]);$count=0;
            foreach($maps as $map){$title=$this->db->get('lancamentos',(int)$map['idlancamento'],true);if($title['status']==='cancelado'||Money::cents($title['valor_baixa'])>0||$title['vencimento']<Input::today())continue;$this->changeTitle($title,-Money::cents($map['valor']),$key,'remover_bolsa_futura');$this->db->update('desconto_lancamentos',(int)$map['idaplicacao'],['revertido_em'=>gmdate('Y-m-d H:i:s')]);$count++;}
            $this->db->update('contrato_descontos',$id,['ativo'=>0,'excluido_em'=>gmdate('Y-m-d H:i:s')]);$this->db->audit('contrato_descontos',$id,'excluir',$d,$this->db->get('contrato_descontos',$id),$key);return ['iddesconto'=>(string)$id,'parcelas_alteradas'=>$count];}));
    }
}
