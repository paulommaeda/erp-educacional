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
        if(Money::cents($title['desconto_condicional_aplicado']??'0.00')>0)return 0;
        return min(Money::cents(Coligadas::within((int)($title['codcoligada']??Coligadas::current()),fn()=>get_option(Coligadas::option('ederp_pontualidade'),'0.00'))),max(0,Money::cents($title['valor_liquido'])-Money::cents($title['valor_baixa'])));
    }
    public function settings():array{Access::requireAdmin();return ['valor'=>get_option(Coligadas::option('ederp_pontualidade'),'0.00')];}
    public function saveSettings(array $data,string $key):array
    {
        Access::requireAdmin();$value=Money::cents($data['valor']??null);if($value<0)throw new RuleViolation('Desconto não pode ser negativo.');$name='ederp_pontualidade_'.substr(hash('sha256',$this->db->table('contratos')),0,20);
        if((int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$name])['acquired']??0)!==1)throw new RuleViolation('Configuração em atualização.');
        try{$before=get_option(Coligadas::option('ederp_pontualidade'),'0.00');$after=Money::format($value);$this->db->audit('configuracoes',0,'pontualidade',['valor'=>$before],['valor'=>$after],$key);update_option(Coligadas::option('ederp_pontualidade'),$after,false);if(get_option('ederp_schema_version')===\EducacionalERP\Infrastructure\Database\Installer::VERSION)EducbankOutbox::refresh($this->db,Coligadas::current());return ['valor'=>$after];}finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$name]);}
    }
    private function contract(int $id,bool $lock=false):array
    {
        $c=$this->db->get('contratos',$id);if(!empty($c['excluido_em']))throw new RuleViolation('Contrato excluído.');if((int)$c['codcoligada']!==Coligadas::current())throw new RuleViolation('Contrato de outra coligada.');
        if($lock){$m=$this->db->get('matriculas',(int)$c['idmatricula']);$this->db->get('alunos',(int)$m['idaluno'],true);$c=$this->db->get('contratos',$id,true);if($c['status']!=='ativo')throw new RuleViolation('Contrato não está ativo.');}return $c;
    }
    public function contracts(int $student):array
    {
        Access::requireAdmin();$a=$this->db->get('alunos',$student);if((int)$a['codcoligada']!==Coligadas::current())throw new RuleViolation('Aluno de outra coligada.');return $this->db->rows('SELECT c.* FROM '.$this->db->table('contratos').' c JOIN '.$this->db->table('matriculas').' m ON m.idmatricula=c.idmatricula WHERE m.idaluno=%d AND c.excluido_em IS NULL ORDER BY c.idcontrato DESC',[$student]);
    }
    public function list(int $id):array
    {
        Access::requireAdmin();$c=$this->contract($id);$m=$this->db->get('matriculas',(int)$c['idmatricula']);$rows=$this->db->rows('SELECT d.*,a.ra AS indicado_ra,p.nome AS indicado_nome FROM '.$this->db->table('contrato_descontos').' d LEFT JOIN '.$this->db->table('alunos').' a ON a.idaluno=d.idaluno_indicado LEFT JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=a.codpessoa WHERE d.idcontrato=%d ORDER BY d.iddesconto',[$id]);
        foreach($rows as &$d){$d['situacao']=!$d['ativo']?'Excluído':(!$d['habilitado']?'Suspenso':($d['tipo']==='indicacao'&&!$d['indicacao_ativa']?'Aguardando indicado cursando':'Ativo'));unset($d['regras_json']);}unset($d);
        $q=$this->db->row('SELECT status,erro,processado_em FROM '.$this->db->table('desconto_recalculos').' WHERE idcontrato=%d',[$id]);
        return ['contrato'=>$c,'codperiodo'=>$m['codperiodo'],'descontos'=>$rows,'recalculo'=>$q];
    }
    public function candidates(int $contract,string $search='',int $page=1):array
    {
        Access::requireAdmin();$c=$this->contract($contract);$m=$this->db->get('matriculas',(int)$c['idmatricula']);$page=max(1,min(100000,$page));$where='a.codcoligada=%d AND a.ativo=1 AND a.idaluno<>%d';$args=[$c['codcoligada'],$m['idaluno']];$search=trim($search);
        if($search!==''){$where.=' AND (a.ra LIKE %s OR p.nome LIKE %s)';$pattern='%'.$this->db->wp->esc_like(substr($search,0,191)).'%';$args[]=$pattern;$args[]=$pattern;}
        $join=' FROM '.$this->db->table('alunos').' a JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=a.codpessoa';$total=(int)$this->db->row('SELECT COUNT(*) AS n'.$join.' WHERE '.$where,$args)['n'];
        $rows=$this->db->rows('SELECT a.idaluno,a.ra,p.nome'.$join.' WHERE '.$where.' ORDER BY p.nome,a.idaluno LIMIT 20 OFFSET %d',[...$args,($page-1)*20]);
        return ['items'=>array_map(fn($r)=>['id'=>$r['idaluno'],'label'=>$r['ra'].' · '.$r['nome']],$rows),'page'=>$page,'more'=>$page*20<$total];
    }
    private function fields(array $data,array $c,?array $old=null):array
    {
        $d=array_replace($old??['descricao'=>'','tipo'=>'comum','habilitado'=>1,'idaluno_indicado'=>null],$data);$first=Input::id($d['parcela_inicio']??null);$last=Input::id($d['parcela_fim']??null);
        if($first>$last||$last>(int)$c['quantidade_parcelas'])throw new RuleViolation('Faixa de parcelas inválida.');
        if(!in_array($d['tipo'],['comum','indicacao'],true)||!in_array($d['habilitado'],[0,1,'0','1',true,false],true))throw new RuleViolation('Tipo ou situação do desconto inválido.');
        $target=null;if($d['tipo']==='indicacao'){$target=Input::id($d['idaluno_indicado']??null);$a=$this->db->get('alunos',$target);$m=$this->db->get('matriculas',(int)$c['idmatricula']);if((int)$a['codcoligada']!==(int)$c['codcoligada']||!$a['ativo']||(int)$m['idaluno']===$target)throw new RuleViolation('Selecione outro aluno ativo da mesma coligada para a indicação.');}
        $description=$d['descricao']??'';if(!is_string($description))throw new RuleViolation('Descrição inválida.');
        return ['nome'=>CadastroText::upper(Input::text($d['nome']??null)),'descricao'=>trim($description)===''?null:CadastroText::upper(Input::text($description,2000)),'valor'=>Money::format(Money::positive($d['valor']??null)),'parcela_inicio'=>$first,'parcela_fim'=>$last,'tipo'=>$d['tipo'],'habilitado'=>empty($d['habilitado'])?0:1,'idaluno_indicado'=>$target];
    }
    private function rules(array $d):array
    {
        if(!empty($d['regras_json'])){$rules=json_decode($d['regras_json'],true);if(!is_array($rules)||!$rules)throw new RuleViolation('Histórico de regras do desconto inválido.');return $rules;}
        return [['partir'=>1,'regra'=>array_intersect_key($d,array_flip(['nome','descricao','tipo','habilitado','idaluno_indicado','valor','parcela_inicio','parcela_fim']))]];
    }
    private function indexTargets(array $d):void
    {
        foreach($this->rules($d) as $version){$rule=$version['regra'];if(($rule['tipo']??'comum')!=='indicacao')continue;$id=(int)$rule['idaluno_indicado'];if(!$this->db->row('SELECT idreferencia FROM '.$this->db->table('desconto_indicados').' WHERE iddesconto=%d AND idaluno=%d',[(int)$d['iddesconto'],$id]))$this->db->insert('desconto_indicados',['iddesconto'=>$d['iddesconto'],'idaluno'=>$id]);}
    }
    public function eligible(array $d,array $c):bool
    {
        if($d['tipo']!=='indicacao')return true;$m=$this->db->get('matriculas',(int)$c['idmatricula']);
        return (bool)$this->db->row('SELECT idmatricula FROM '.$this->db->table('matriculas')." WHERE codcoligada=%d AND codperiodo=%d AND idaluno=%d AND status='cursando' AND ativo_unico=1 LIMIT 1",[$c['codcoligada'],$m['codperiodo'],$d['idaluno_indicado']]);
    }
    public function add(int $id,array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,'contrato_desconto_adicionar',['idcontrato'=>$id]+$data,function()use($id,$data,$key){$c=$this->contract($id,true);$fields=$this->fields($data,$c);$fields['regras_json']=wp_json_encode([['partir'=>1,'regra'=>$fields]]);$discount=$this->db->insert('contrato_descontos',['idcontrato'=>$id,'ator_wp_user_id'=>get_current_user_id()]+$fields);$d=$this->db->get('contrato_descontos',$discount,true);$this->indexTargets($d);$eligible=$this->eligible($d,$c);$this->syncOne($c,$d,1,$d['tipo']==='indicacao',$eligible,$key);$this->db->audit('contrato_descontos',$discount,'criar',null,$this->db->get('contrato_descontos',$discount),$key);return ['iddesconto'=>(string)$discount];}));
    }
    public function edit(int $id,array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,'contrato_desconto_editar',['iddesconto'=>$id]+$data,function()use($id,$data,$key){$d=$this->db->get('contrato_descontos',$id);$c=$this->contract((int)$d['idcontrato'],true);$d=$this->db->get('contrato_descontos',$id,true);$this->version($d,$data);$from=Input::id($data['aplicar_a_partir']??null);if($from>(int)$c['quantidade_parcelas'])throw new RuleViolation('Parcela inicial da alteração fora do contrato.');$this->freeze($d);$fields=$this->fields($data,$c,$d);$rules=array_values(array_filter($this->rules($d),fn($r)=>(int)$r['partir']<$from));$rules[]=['partir'=>$from,'regra'=>$fields];$fields['regras_json']=wp_json_encode($rules);$fields['ultima_alteracao_parcela']=$from;$this->db->update('contrato_descontos',$id,$fields);$after=$this->db->get('contrato_descontos',$id,true);$this->indexTargets($after);$count=$this->syncOne($c,$after,$from,false,$this->eligible($after,$c),$key);$this->db->audit('contrato_descontos',$id,'editar',$d,$this->db->get('contrato_descontos',$id),$key);return ['iddesconto'=>(string)$id,'parcelas_alteradas'=>$count];}));
    }
    private function version(array $d,array $data):void
    {
        if((string)($data['versao']??'')!==(string)$d['versao'])throw new RuleViolation('Desconto alterado. Recarregue.');if(!$d['ativo'])throw new RuleViolation('Desconto já excluído.');
    }
    private function freeze(array $d):void
    {
        foreach($this->db->rows('SELECT * FROM '.$this->db->table('desconto_lancamentos').' WHERE iddesconto=%d AND nome_snapshot IS NULL FOR UPDATE',[(int)$d['iddesconto']]) as $map)$this->db->update('desconto_lancamentos',(int)$map['idaplicacao'],['nome_snapshot'=>$d['nome'],'descricao_snapshot'=>$d['descricao'],'tipo_snapshot'=>$d['tipo']]);
    }
    /** Caller holds the beneficiary student and contract locks, including deferred installment generation. */
    public function applyInside(int $id,string $key):void
    {
        $c=$this->db->get('contratos',$id);foreach($this->db->rows('SELECT * FROM '.$this->db->table('contrato_descontos').' WHERE idcontrato=%d AND ativo=1 ORDER BY iddesconto FOR UPDATE',[$id]) as $d)$this->syncOne($c,$d,1,$d['tipo']==='indicacao',$this->eligible($d,$c),$key);
    }
    /** Called only by the durable referral worker inside its own transaction and lock hierarchy. */
    public function referralsInside(array $c,string $key):int
    {
        if($c['status']!=='ativo')return 0;$count=0;$rows=$this->db->rows('SELECT d.* FROM '.$this->db->table('contrato_descontos').' d WHERE d.idcontrato=%d AND d.ativo=1 AND EXISTS (SELECT 1 FROM '.$this->db->table('desconto_indicados').' r WHERE r.iddesconto=d.iddesconto) ORDER BY d.iddesconto FOR UPDATE',[(int)$c['idcontrato']]);foreach([-1,1] as $phase)foreach($rows as $d)$count+=$this->syncOne($c,$d,1,true,$this->eligible($d,$c),$key,$phase);return $count;
    }
    private function changeable(array $title):bool
    {
        if(!empty($title['excluido_em'])||$title['status']==='cancelado'||Money::cents($title['valor_baixa'])>0)return false;
        return !$this->db->row('SELECT b.idbaixa FROM '.$this->db->table('baixas').' b WHERE b.idlancamento=%d AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('baixa_estornos').' e WHERE e.idbaixa=b.idbaixa) LIMIT 1',[(int)$title['idlancamento']]);
    }
    private function syncOne(array $c,array $d,int $from,bool $futureOnly,bool $eligible,string $key,int $phase=0):int
    {
        $count=0;$rules=$this->rules($d);$eligibility=[];$titles=$this->db->rows('SELECT l.*,p.numero FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND l.excluido_em IS NULL AND p.numero>=%d ORDER BY l.idlancamento FOR UPDATE',[(int)$c['idcontrato'],$from]);
        foreach($titles as $title){if(($futureOnly&&$title['vencimento']<Input::today())||!$this->changeable($title))continue;$id=(int)$title['idlancamento'];$map=$this->db->row('SELECT * FROM '.$this->db->table('desconto_lancamentos').' WHERE iddesconto=%d AND idlancamento=%d FOR UPDATE',[(int)$d['iddesconto'],$id]);
            $rule=$rules[0]['regra'];foreach($rules as $version)if((int)$version['partir']<=(int)$title['numero'])$rule=$version['regra'];$target=$rule['tipo']==='indicacao'?(int)$rule['idaluno_indicado']:0;if(!array_key_exists($target,$eligibility))$eligibility[$target]=$target?$this->eligible($rule,$c):true;
            $previous=$map&&$map['revertido_em']===null?Money::cents($map['valor']):0;$wanted=$d['ativo']&&$rule['habilitado']&&$eligibility[$target]&&(int)$title['numero']>=(int)$rule['parcela_inicio']&&(int)$title['numero']<=(int)$rule['parcela_fim']?Money::cents($rule['valor']):0;
            $snap=['nome_snapshot'=>$rule['nome'],'descricao_snapshot'=>$rule['descricao'],'tipo_snapshot'=>$rule['tipo']];$metadata=$wanted>0&&$map&&array_intersect_key($map,$snap)!==$snap;
            if(($phase<0&&$wanted>$previous)||($phase>0&&$wanted<=$previous)||($wanted===$previous&&!$metadata))continue;$before=$map;
            if($wanted>0){$values=['valor'=>Money::format($wanted),'revertido_em'=>null]+$snap;if($map)$this->db->update('desconto_lancamentos',(int)$map['idaplicacao'],$values);else{$mid=$this->db->insert('desconto_lancamentos',['iddesconto'=>$d['iddesconto'],'idlancamento'=>$id]+$values);$map=$this->db->get('desconto_lancamentos',$mid);}}
            elseif($map)$this->db->update('desconto_lancamentos',(int)$map['idaplicacao'],['revertido_em'=>gmdate('Y-m-d H:i:s')]);
            $this->changeTitle($title,$wanted-$previous,$key,'recalcular_desconto');$this->db->audit('desconto_lancamentos',(int)$map['idaplicacao'],'recalcular',$before,$this->db->get('desconto_lancamentos',(int)$map['idaplicacao']),$key);$count++;
        }
        if($phase>=0&&$d['tipo']==='indicacao')$this->db->update('contrato_descontos',(int)$d['iddesconto'],['indicacao_ativa'=>$eligible?1:0,'ultima_verificacao_em'=>gmdate('Y-m-d H:i:s')]);
        return $count;
    }
    private function changeTitle(array $title,int $delta,string $key,string $action):void
    {
        $discount=Money::cents($title['desconto_incondicional'])+$delta;$net=Money::cents($title['valor_original'])-$discount;$balance=$net-Money::cents($title['desconto_condicional_aplicado'])+Money::cents($title['juros_aplicados'])+Money::cents($title['multa_aplicada'])-Money::cents($title['valor_baixa']);if($discount<0||$net<0||$balance<0)throw new RuleViolation('A soma dos descontos ultrapassa o valor da parcela.');$id=(int)$title['idlancamento'];$this->db->update('lancamentos',$id,['desconto_incondicional'=>Money::format($discount),'valor_liquido'=>Money::format($net),'saldo_aberto'=>Money::format($balance),'status'=>$balance===0?'quitado':'aberto']);$this->db->audit('lancamentos',$id,$action,$title,$this->db->get('lancamentos',$id),$key);
    }
    public function remove(int $id,array $data,string $key):array
    {
        Access::requireAdmin();return $this->db->atomic(fn()=>$this->ops->run($key,'contrato_desconto_excluir',['iddesconto'=>$id]+$data,function()use($id,$data,$key){$d=$this->db->get('contrato_descontos',$id);$c=$this->contract((int)$d['idcontrato'],true);$d=$this->db->get('contrato_descontos',$id,true);$this->version($d,$data);$this->freeze($d);$this->db->update('contrato_descontos',$id,['ativo'=>0,'excluido_em'=>gmdate('Y-m-d H:i:s')]);$after=$this->db->get('contrato_descontos',$id);$count=$this->syncOne($c,$after,1,true,false,$key);$this->db->audit('contrato_descontos',$id,'excluir',$d,$after,$key);return ['iddesconto'=>(string)$id,'parcelas_alteradas'=>$count];}));
    }
    public function decorate(array $titles):array
    {
        $groups=[];foreach(array_chunk(array_column($titles,'idlancamento'),200) as $ids){$slots=implode(',',array_fill(0,count($ids),'%d'));foreach($this->db->rows('SELECT x.idlancamento,COALESCE(x.nome_snapshot,d.nome) AS nome,CASE WHEN x.nome_snapshot IS NOT NULL THEN x.descricao_snapshot ELSE d.descricao END AS descricao,COALESCE(x.tipo_snapshot,d.tipo) AS tipo,x.valor FROM '.$this->db->table('desconto_lancamentos').' x JOIN '.$this->db->table('contrato_descontos')." d ON d.iddesconto=x.iddesconto WHERE x.idlancamento IN ($slots) AND x.revertido_em IS NULL ORDER BY x.idaplicacao",$ids) as $r){$id=$r['idlancamento'];unset($r['idlancamento']);$groups[$id][]=$r;}}
        foreach($titles as &$title)$title['descontos_detalhados']=$this->breakdown($title,$groups[$title['idlancamento']]??[]);unset($title);return $titles;
    }
    /** Only names/descriptions and actual applied values. Never expose the referred student's identity. */
    public function breakdown(array $title,?array $applied=null):array
    {
        $rows=$applied??$this->db->rows('SELECT COALESCE(x.nome_snapshot,d.nome) AS nome,CASE WHEN x.nome_snapshot IS NOT NULL THEN x.descricao_snapshot ELSE d.descricao END AS descricao,COALESCE(x.tipo_snapshot,d.tipo) AS tipo,x.valor FROM '.$this->db->table('desconto_lancamentos').' x JOIN '.$this->db->table('contrato_descontos').' d ON d.iddesconto=x.iddesconto WHERE x.idlancamento=%d AND x.revertido_em IS NULL ORDER BY x.idaplicacao',[(int)$title['idlancamento']]);$sum=0;foreach($rows as $r)$sum+=Money::cents($r['valor']);$total=Money::cents($title['desconto_incondicional']);
        if($sum>$total)return $total>0?[['nome'=>'Desconto incondicional ajustado','descricao'=>null,'tipo'=>'comum','valor'=>Money::format($total)]]:[];
        if($sum<$total)$rows[]=['nome'=>'Desconto incondicional','descricao'=>null,'tipo'=>'comum','valor'=>Money::format($total-$sum)];return $rows;
    }
}
