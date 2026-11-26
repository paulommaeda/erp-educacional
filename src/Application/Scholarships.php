<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,Money,RuleViolation,ScholarshipPricing};
use EducacionalERP\Infrastructure\WordPress\Access;
/** Catalogue grants are snapshots. Explicit changes rebuild unpaid installments, never payments. */
final class Scholarships
{
    public function __construct(private Store $db){}
    private function contract(int $id,bool $lock=false):array
    {
        $c=$this->db->get('contratos',$id);if((int)$c['codcoligada']!==Coligadas::current()||!empty($c['excluido_em']))throw new RuleViolation('Contrato indisponível nesta coligada.');
        if($lock){$m=$this->db->get('matriculas',(int)$c['idmatricula']);$this->db->get('alunos',(int)$m['idaluno'],true);$c=$this->db->get('contratos',$id,true);if($c['status']!=='ativo')throw new RuleViolation('Contrato não está ativo.');}return $c;
    }
    private function concessions(int $contract,bool $lock=false):array {return $this->db->rows('SELECT * FROM '.$this->db->table('contrato_bolsas').' WHERE idcontrato=%d ORDER BY idconcessao'.($lock?' FOR UPDATE':''),[$contract]);}
    private function ruleAt(array $row,int $number):?array
    {
        if(!(int)$row['ativo'])return null;$versions=json_decode($row['regras_json'],true,64,JSON_THROW_ON_ERROR);$rule=null;foreach($versions as $version)if((int)$version['partir']<=$number)$rule=$version['regra'];
        return $rule&&!empty($rule['habilitado'])&&$number>=(int)$rule['parcela_inicio']&&$number<=(int)$rule['parcela_fim']?$rule+['idconcessao'=>(string)$row['idconcessao'],'idbolsa'=>(string)$row['idbolsa']]:null;
    }
    private function pricingRules(int $contract,bool $lock=false):array
    {
        $individual=[];
        foreach((new StudentScholarships($this->db))->forContract($contract,true,$lock) as $grant){
            $individual[]=['idbolsa_aluno'=>$grant['idbolsa_aluno'],'nome'=>$grant['titulo'],'descricao'=>$grant['descricao'],'forma_calculo'=>'percentual','valor'=>$grant['percentual'],'base_percentual'=>'valor_original'];
        }
        return ['contrato'=>$this->concessions($contract,$lock),'aluno'=>$individual];
    }

    private function activeRules(array $rules,int $number):array
    {
        $out=[];
        foreach($rules['contrato'] as $row)if($rule=$this->ruleAt($row,$number))$out[]=$rule;
        return array_merge($out,$rules['aluno']);
    }

    /** Reconcile unpaid issued titles from the contract's financial rules and retained audited adjustments. */
    public function rebuildTitles(array $contract,array $titles,int $manual,bool $replacePlan,string $key):array
    {
        $id=(int)$contract['idcontrato'];
        $count=(int)$contract['quantidade_parcelas'];
        $manualParts=array_pad($manual?Money::split($manual,min($count,$manual)):[],$count,0);
        $faces=$replacePlan?Money::split(Money::positive($contract['valor_original_total']),$count):[];
        $rules=$this->pricingRules($id,true);
        $discounts=new ContractDiscounts($this->db,new Operations($this->db));
        $changed=[];
        foreach($titles as $title){
            $number=(int)$title['numero'];
            $titleId=(int)$title['idlancamento'];
            $punctuality=ContractDiscounts::punctuality($replacePlan?$contract:$title);
            $face=$faces[$number-1]??Money::cents($title['valor_original']);
            $adjustment=$title['ajuste_incondicional'];
            $fixed=$discounts->rebuildApplications($contract,$title,$key);
            $quote=ScholarshipPricing::quote($face-$punctuality,($manualParts[$number-1]??0)+$fixed+$adjustment,$punctuality,$this->activeRules($rules,$number));
            $balance=$quote['liquido']-Money::cents($title['desconto_condicional_aplicado'])+Money::cents($title['juros_aplicados'])+Money::cents($title['multa_aplicada']);
            if($balance<0)throw new RuleViolation('O saldo recalculado ficou negativo na parcela '.$number.'. Confira seus descontos e ajustes.');
            $values=['valor_original'=>Money::format($quote['original']),'desconto_incondicional'=>Money::format($quote['desconto']),
                'valor_liquido'=>Money::format($quote['liquido']),'pontualidade_acrescimo'=>Money::format($punctuality),
                'pontualidade_snapshot'=>Money::format($punctuality),'bolsa_aplicada'=>Money::format($quote['bolsa']),'bolsas_json'=>wp_json_encode($quote['aplicacoes'])];
            $parcel=$this->db->get('parcelas',(int)$title['idparcela'],true);
            $parcelValues=$values+['desconto_condicional_previsto'=>Money::format(min($punctuality,$quote['liquido'])),'limite_desconto_condicional'=>$title['vencimento']];
            $titleValues=$values+['saldo_aberto'=>Money::format($balance),'status'=>$balance===0?'quitado':'aberto'];
            $titleChanged=$this->saveRebuilt('lancamentos',$titleId,$title,$titleValues,$key);
            $parcelChanged=$this->saveRebuilt('parcelas',(int)$title['idparcela'],$parcel,$parcelValues,$key);
            if($titleChanged||$parcelChanged)$changed[]=$titleId;
        }
        return $changed;
    }

    private function saveRebuilt(string $table,int $id,array $before,array $values,string $key):bool
    {
        $different=false;
        foreach($values as $field=>$value)if($before[$field]!==$value){$different=true;break;}
        if(!$different)return false;
        $this->db->update($table,$id,$values);
        $this->db->audit($table,$id,'regerar_contrato',$before,$values,$key);
        return true;
    }
    private function range(array $data,int $count):array
    {
        $first=Input::id($data['parcela_inicio']??1);$last=Input::id($data['parcela_fim']??$count);if($first>$last||$last>$count)throw new RuleViolation('Faixa de parcelas da bolsa inválida.');return [$first,$last];
    }
    private function enable(mixed $value):int {if(!in_array($value,[0,1,'0','1',true,false],true))throw new RuleViolation('Situação da bolsa inválida.');return $value?1:0;}
    private function from(array $data,int $count):int {$from=Input::id($data['aplicar_a_partir']??1);if($from>$count)throw new RuleViolation('Parcela inicial fora do contrato.');return $from;}
    private function available(array $c,array $rule,int $except=0):void
    {
        foreach($this->concessions((int)$c['idcontrato']) as $row)if((int)$row['idconcessao']!==$except&&(int)$row['idbolsa']===(int)$rule['idbolsa'])for($n=(int)$rule['parcela_inicio'];$n<=(int)$rule['parcela_fim'];$n++)if($this->ruleAt($row,$n))throw new RuleViolation('Esta bolsa já está aplicada nessa faixa. Edite a concessão existente.');
    }
    private function pendingPolicy(array $c):array
    {
        if($c['mensalidade_base_total']!==null||$this->db->row('SELECT idparcela FROM '.$this->db->table('parcelas').' WHERE idcontrato=%d LIMIT 1 FOR UPDATE',[(int)$c['idcontrato']]))return $c;
        $punctuality=get_option(Coligadas::option('ederp_pontualidade'),'0.00');$base=Money::cents($c['valor_original_total'])-Money::cents($punctuality)*(int)$c['quantidade_parcelas'];if($base<=Money::cents($c['desconto_incondicional_total']))throw new RuleViolation('O valor do contrato deve superar a pontualidade e os descontos manuais.');
        $fields=['mensalidade_base_total'=>Money::format($base),'desconto_base_total'=>$c['desconto_incondicional_total'],'pontualidade_snapshot'=>$punctuality];$this->db->update('contratos',(int)$c['idcontrato'],$fields);return array_replace($c,$fields);
    }
    public function attachInside(int $contract,array $selection,string $key):array
    {
        if(!$selection)return [];Access::requireManageDiscounts();if(!array_is_list($selection)||count($selection)>20)throw new RuleViolation('Selecione até 20 bolsas por operação.');$c=$this->pendingPolicy($this->contract($contract));$ids=[];
        foreach($selection as $data){if(!is_array($data))throw new RuleViolation('Bolsa selecionada inválida.');$catalog=(new ScholarshipCatalog($this->db))->get(Input::id($data['idbolsa']??null),true);if(!(int)$catalog['ativo'])throw new RuleViolation('Bolsa inativa.');if((string)($data['bolsa_versao']??'')!==(string)$catalog['versao'])throw new RuleViolation('O cadastro da bolsa mudou. Confira a seleção novamente.');[$first,$last]=$this->range($data,(int)$c['quantidade_parcelas']);$rule=['idbolsa'=>(string)$catalog['idbolsa'],'nome'=>$catalog['nome'],'descricao'=>$catalog['descricao'],'forma_calculo'=>$catalog['forma_calculo'],'valor'=>$catalog['valor'],'base_percentual'=>'valor_original','parcela_inicio'=>$first,'parcela_fim'=>$last,'habilitado'=>1];$this->available($c,$rule);
            $id=$this->db->insert('contrato_bolsas',['idcontrato'=>$contract,'idbolsa'=>$catalog['idbolsa'],'nome_snapshot'=>$catalog['nome'],'descricao_snapshot'=>$catalog['descricao'],'forma_calculo'=>$catalog['forma_calculo'],'valor'=>$catalog['valor'],'bolsa_versao'=>$catalog['versao'],'parcela_inicio'=>$first,'parcela_fim'=>$last,'regras_json'=>wp_json_encode([['partir'=>1,'regra'=>$rule]]),'ator_wp_user_id'=>get_current_user_id()]);$ids[]=(string)$id;$this->db->audit('contrato_bolsas',$id,'conceder',null,$this->db->get('contrato_bolsas',$id),$key);
        }
        $this->totalsInside($contract);return $ids;
    }
    public function listing(int $id):array
    {
        Access::requireManageDiscounts();$c=$this->contract($id);$rows=$this->concessions($id);foreach($rows as &$row)unset($row['regras_json']);unset($row);return ['contrato'=>Access::canManageContracts()?$c:Contracts::summary($c),'items'=>$rows,'bolsas_aluno'=>(new StudentScholarships($this->db))->forContract($id)];
    }
    public function add(int $id,array $data,string $key):array
    {
        Access::requireManageDiscounts();return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'bolsa_conceder',['idcontrato'=>$id]+$data,function()use($id,$data,$key){$c=$this->contract($id,true);$ids=$this->attachInside($id,[$data],$key);[$first,$last]=$this->range($data,(int)$c['quantidade_parcelas']);return ['idconcessao'=>$ids[0]]+$this->repriceInside($id,$first,$last,$key,true);}));
    }
    public function change(int $id,array $data,string $key,bool $remove=false):array
    {
        Access::requireManageDiscounts();return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,$remove?'bolsa_retirar':'bolsa_alterar',['idconcessao'=>$id]+$data,function()use($id,$data,$key,$remove){$old=$this->db->get('contrato_bolsas',$id);$c=$this->contract((int)$old['idcontrato'],true);$old=$this->db->get('contrato_bolsas',$id,true);if(!(int)$old['ativo']||(string)($data['versao']??'')!==(string)$old['versao'])throw new RuleViolation('Concessão alterada ou encerrada. Recarregue.');$from=$this->from($data,(int)$c['quantidade_parcelas']);[$first,$last]=$remove?[(int)$old['parcela_inicio'],(int)$old['parcela_fim']]:$this->range($data,(int)$c['quantidade_parcelas']);$enabled=$remove?0:$this->enable($data['habilitado']??1);$savedRules=json_decode($old['regras_json'],true,64,JSON_THROW_ON_ERROR);$basis=$savedRules[0]['regra']['base_percentual']??null;
            $rule=['idbolsa'=>(string)$old['idbolsa'],'nome'=>$old['nome_snapshot'],'descricao'=>$old['descricao_snapshot'],'forma_calculo'=>$old['forma_calculo'],'valor'=>$old['valor'],'parcela_inicio'=>$first,'parcela_fim'=>$last,'habilitado'=>$enabled];if($enabled)$this->available($c,$rule,$id);if($basis!==null)$rule['base_percentual']=$basis;$versions=array_values(array_filter(json_decode($old['regras_json'],true,64,JSON_THROW_ON_ERROR),fn($v)=>(int)$v['partir']<$from));$versions[]=['partir'=>$from,'regra'=>$rule];$fields=['parcela_inicio'=>$first,'parcela_fim'=>$last,'habilitado'=>$enabled,'regras_json'=>wp_json_encode($versions)];if($remove&&$from===1)$fields+=['ativo'=>0,'excluido_em'=>gmdate('Y-m-d H:i:s')];$this->db->update('contrato_bolsas',$id,$fields);$result=$this->repriceInside((int)$c['idcontrato'],$from,(int)$c['quantidade_parcelas'],$key,true);$this->db->audit('contrato_bolsas',$id,$remove?'retirar':'alterar',$old,$this->db->get('contrato_bolsas',$id),$key);return ['idconcessao'=>(string)$id]+$result;
        }));
    }
    public function repriceInside(int $id,int $from,int $to,string $key,bool $upgrade=false):array
    {
        if($upgrade)$this->pendingPolicy($this->db->get('contratos',$id,true));
        $rows=$this->pricingRules($id,true);$changed=0;$preserved=0;$titles=$this->db->rows('SELECT l.*,p.numero FROM '.$this->db->table('lancamentos').' l JOIN '.$this->db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND p.numero>=%d AND p.numero<=%d AND l.excluido_em IS NULL ORDER BY l.idlancamento FOR UPDATE',[$id,$from,$to]);
        foreach($titles as $title){$result=$this->titleInside($title,$rows,$key,$upgrade);if($result===null)$preserved++;elseif($result)$changed++;}
        $this->totalsInside($id);if($titles)EnrollmentLifecycle::syncFirstPayment($this->db,$id,$key);return ['parcelas_alteradas'=>$changed,'parcelas_preservadas'=>$preserved];
    }
    /** Existing fixed/referral discounts call this after changing their own amount. */
    public function afterDiscountInside(array $title,string $key):void
    {
        $p=$this->db->get('parcelas',(int)$title['idparcela']);$id=(int)$p['idcontrato'];
        if($title['pontualidade_snapshot']!==null||Money::cents($title['bolsa_aplicada'])>0)$this->titleInside($title+['numero'=>$p['numero']],$this->pricingRules($id,true),$key,false);
        else $this->db->update('parcelas',(int)$p['idparcela'],array_intersect_key($title,array_flip(['valor_original','desconto_incondicional','valor_liquido'])));
        $this->totalsInside($id);EnrollmentLifecycle::syncFirstPayment($this->db,$id,$key);
    }
    private function titleInside(array $title,array $rows,string $key,bool $upgrade):?bool
    {
        if($title['status']==='cancelado'||!empty($title['excluido_em'])||Money::cents($title['valor_baixa'])>0||$this->db->row('SELECT b.idbaixa FROM '.$this->db->table('baixas').' b WHERE b.idlancamento=%d AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('baixa_estornos').' e WHERE e.idbaixa=b.idbaixa) LIMIT 1',[(int)$title['idlancamento']]))return null;
        if($title['pontualidade_snapshot']===null&&!$upgrade)return false;
        $punctuality=Money::cents($title['pontualidade_snapshot']??get_option(Coligadas::option('ederp_pontualidade'),'0.00'));
        $component=$title['pontualidade_snapshot']===null?$punctuality:Money::cents($title['pontualidade_acrescimo']);$base=Money::cents($title['valor_original'])-$component;
        $manual=Money::cents($title['desconto_incondicional'])-Money::cents($title['bolsa_aplicada']);$quote=ScholarshipPricing::quote($base,$manual,$punctuality,$this->activeRules($rows,(int)$title['numero']));$balance=$quote['liquido']-Money::cents($title['desconto_condicional_aplicado'])+Money::cents($title['juros_aplicados'])+Money::cents($title['multa_aplicada'])-Money::cents($title['valor_baixa']);if($balance<0)throw new RuleViolation('A bolsa gera saldo incompatível com descontos ou pagamentos já registrados.');
        $values=['valor_original'=>Money::format($quote['original']),'desconto_incondicional'=>Money::format($quote['desconto']),'valor_liquido'=>Money::format($quote['liquido']),'pontualidade_acrescimo'=>Money::format($punctuality),'pontualidade_snapshot'=>Money::format($punctuality),'bolsa_aplicada'=>Money::format($quote['bolsa']),'bolsas_json'=>wp_json_encode($quote['aplicacoes'])];$changes=$values+['saldo_aberto'=>Money::format($balance),'status'=>$balance===0?'quitado':'aberto'];$different=false;foreach($changes as $field=>$value)if((string)$title[$field]!==$value){$different=true;break;}$p=$this->db->get('parcelas',(int)$title['idparcela']);$parcelValues=$values+['desconto_condicional_previsto'=>Money::format(min($punctuality,$quote['liquido'])),'limite_desconto_condicional'=>$title['vencimento']];$parcelChanged=false;foreach($parcelValues as $field=>$value)if((string)$p[$field]!==$value){$parcelChanged=true;break;}if(!$different&&!$parcelChanged)return false;
        if($different){$this->db->update('lancamentos',(int)$title['idlancamento'],$changes);$this->db->audit('lancamentos',(int)$title['idlancamento'],'recalcular_bolsas',$title,$this->db->get('lancamentos',(int)$title['idlancamento']),$key);}if($parcelChanged){$this->db->update('parcelas',(int)$p['idparcela'],$parcelValues);$this->db->audit('parcelas',(int)$p['idparcela'],'recalcular_bolsas',$p,$this->db->get('parcelas',(int)$p['idparcela']),$key);}return true;
    }
    /** The agreed contract plan, before generating charges; also used by contract shortcodes. */
    public function plannedInstallments(array $c,bool $lock=false):array
    {
        $count=(int)$c['quantidade_parcelas'];$newPricing=$c['mensalidade_base_total']!==null;
        $base=Money::split(Money::positive($c['mensalidade_base_total']??$c['valor_original_total']),$count);
        $manual=Money::cents($c['desconto_base_total']??$c['desconto_incondicional_total']);$parts=array_pad($manual?Money::split($manual,min($count,$manual)):[],$count,0);
        $rows=$newPricing?$this->pricingRules((int)$c['idcontrato'],$lock):[];$discounts=new ContractDiscounts($this->db,new Operations($this->db));$out=[];
        for($i=0;$i<$count;$i++){
            $discount=$parts[$i]+$discounts->plannedAmount($c,$i+1);
            if(!$newPricing&&$discount>$base[$i])throw new RuleViolation('Os descontos superam o valor da parcela do contrato.');
            $quote=$newPricing?ScholarshipPricing::quote($base[$i],$discount,Money::cents($c['pontualidade_snapshot']),$this->activeRules($rows,$i+1)):['original'=>$base[$i],'desconto'=>$discount,'liquido'=>$base[$i]-$discount];
            $out[]=['numero'=>$i+1,'valor_original'=>Money::format($quote['original']),'desconto_incondicional'=>Money::format($quote['desconto']),'valor_liquido'=>Money::format($quote['liquido'])];
        }
        return $out;
    }
    public function totalsInside(int $id):void
    {
        $c=$this->db->get('contratos',$id,true);$parcels=$this->db->rows('SELECT valor_original,desconto_incondicional,valor_liquido FROM '.$this->db->table('parcelas').' WHERE idcontrato=%d ORDER BY numero FOR UPDATE',[$id]);$totals=['valor_original_total'=>0,'desconto_incondicional_total'=>0,'valor_liquido_total'=>0];
        if($parcels)foreach($parcels as $p){$totals['valor_original_total']+=Money::cents($p['valor_original']);$totals['desconto_incondicional_total']+=Money::cents($p['desconto_incondicional']);$totals['valor_liquido_total']+=Money::cents($p['valor_liquido']);}
        elseif($c['mensalidade_base_total']!==null)foreach($this->plannedInstallments($c,true) as $p)foreach($totals as $field=>$_)$totals[$field]+=Money::cents($p[str_replace('_total','',$field)]);
        else return;
        $values=array_map(fn($amount)=>Money::format($amount),$totals);$changed=false;foreach($values as $field=>$value)if($c[$field]!==$value)$changed=true;if($changed)EducbankOutbox::without(fn()=>$this->db->update('contratos',$id,$values));
    }
}
