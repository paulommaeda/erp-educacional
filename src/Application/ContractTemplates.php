<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Store,Input,Money,RuleViolation,ContractMarkup};
use EducacionalERP\Infrastructure\WordPress\{Access,SchoolIdentity};

/** A fixed token vocabulary, with sanitized HTML formatting and no executable shortcodes. */
final class ContractTemplates
{
    public const TOKENS=[
        'aluno_nome'=>'Nome do aluno','aluno_ra'=>'RA do aluno','aluno_cpf'=>'CPF do aluno','aluno_nascimento'=>'Nascimento do aluno','aluno_email'=>'E-mail do aluno','aluno_endereco'=>'Endereço do aluno',
        'responsavel_nome'=>'Nome do responsável financeiro','responsavel_cpf'=>'CPF do responsável financeiro','responsavel_rg'=>'RG do responsável financeiro','responsavel_email'=>'E-mail do responsável financeiro','responsavel_telefone'=>'Telefone do responsável financeiro','responsavel_endereco'=>'Endereço do responsável financeiro',
        'responsavel_estado_civil'=>'Estado civil do responsável financeiro (assinante)','responsavel_profissao'=>'Profissão do responsável financeiro (assinante)','responsavel_endereco_completo'=>'Endereço completo do responsável financeiro (assinante), incluindo país',
        'responsavel_rua'=>'Rua do responsável financeiro','responsavel_numero'=>'Número do endereço do responsável financeiro','responsavel_complemento'=>'Complemento do endereço do responsável financeiro','responsavel_bairro'=>'Bairro do responsável financeiro','responsavel_cidade'=>'Cidade do responsável financeiro','responsavel_estado'=>'Estado/UF do responsável financeiro','responsavel_cep'=>'CEP do responsável financeiro','responsavel_pais'=>'País do responsável financeiro (código ISO)',
        'pai_nome'=>'Nome do pai','pai_cpf'=>'CPF do pai','mae_nome'=>'Nome da mãe','mae_cpf'=>'CPF da mãe',
        'colegio_nome'=>'Nome do colégio','colegio_razao_social'=>'Razão social do colégio','colegio_cnpj'=>'CNPJ do colégio','colegio_endereco'=>'Endereço do colégio','colegio_email'=>'E-mail do colégio','colegio_telefone'=>'Telefone do colégio',
        'contrato_numero'=>'Número do contrato','contrato_data'=>'Data do contrato','contrato_valor_original'=>'Valor original total','contrato_desconto'=>'Bolsa total','contrato_valor_liquido'=>'Valor líquido total','contrato_parcelas'=>'Quantidade de parcelas','contrato_primeiro_vencimento'=>'Primeiro vencimento',
        'contrato_valor_total'=>'Valor total contratado para o aluno, após bolsas/descontos e sem descontar pontualidade','contrato_valor_parcela'=>'Valor da parcela do aluno; discrimina as faixas quando os valores são diferentes','contrato_parcelamento'=>'Parcelamento do aluno com quantidades e valores, incluindo faixas diferentes','contrato_dia_vencimento'=>'Somente o dia de vencimento das parcelas (01 a 31)',
        'matricula_id'=>'Número da matrícula','periodo_codigo'=>'Período letivo','curso_nome'=>'Curso','turma_nome'=>'Turma','turno_nome'=>'Turno','plano_nome'=>'Plano de pagamento'
    ];
    public function __construct(private Store $db,private Operations $ops) {}

    public function listing(bool $all=false):array
    {
        if($all)Access::requireManageContracts();
        elseif(!Access::canManageContracts()&&!current_user_can('erp_gerenciar_academico'))throw new RuleViolation('Sem permissão para consultar modelos de contrato.');
        return ['items'=>$this->db->rows('SELECT * FROM '.$this->db->table('modelos_contrato').' WHERE codcoligada=%d AND excluido_em IS NULL'.($all?'':' AND ativo=1').' ORDER BY padrao DESC,nome,idmodelo',[Coligadas::current()]),'shortcodes'=>self::TOKENS];
    }

    public static function text(mixed $text,mixed $format='auto'):string
    {
        $text=ContractMarkup::prepare($text,$format)['texto'];
        preg_match_all('/\[([^\]\r\n]+)\]/u',$text,$matches);
        foreach($matches[1] as $token)if(!isset(self::TOKENS[$token]))throw new RuleViolation('Shortcode desconhecido: ['.$token.']. Use os campos disponíveis.');
        return $text;
    }

    private function render(string $text,string $format,array $contract):string
    {
        $values=$this->values($contract);if($format==='html')$values=array_map([ContractMarkup::class,'escape'],$values);
        $rendered=strtr($text,$values);return $format==='html'?ContractMarkup::html($rendered):$rendered;
    }

    public function save(?int $id,array $data,string $key):array
    {
        Access::requireManageContracts();
        if(array_diff(array_keys($data),['nome','texto','formato_texto','versao_texto','padrao','ativo','versao','motivo']))throw new RuleViolation('Campo de modelo inválido.');
        $markup=ContractMarkup::prepare($data['texto']??null,$data['formato_texto']??'auto');
        $fields=['nome'=>Input::text($data['nome']??null,191),'texto'=>self::text($markup['texto'],$markup['formato_texto']),'formato_texto'=>$markup['formato_texto'],'versao_texto'=>Input::text($data['versao_texto']??null,40)];
        foreach(['padrao','ativo'] as $field){if(!in_array($data[$field]??null,[0,1,'0','1'],true))throw new RuleViolation('Situação do modelo inválida.');$fields[$field]=(int)$data[$field];}
        if($fields['padrao']&&!$fields['ativo'])throw new RuleViolation('O modelo padrão precisa estar ativo.');
        $reason=$id?Input::text($data['motivo']??null,2000):'Cadastro do modelo';
        return ContractTextLock::run($this->db,fn()=>$this->db->atomic(fn()=>$this->ops->run($key,'salvar_modelo_contrato',['idmodelo'=>$id]+$data,function()use($id,$data,$fields,$reason,$key){
            // Serialize default selection for each company, including new models.
            $this->db->get('coligadas',Coligadas::current(),true);$before=$id?$this->model($id,true):null;
            if($before&&(int)$before['versao']!==Input::id($data['versao']??null))throw new RuleViolation('Modelo alterado. Reabra a edição.');
            if($before&&$data['texto']===$before['texto']&&!isset($data['formato_texto'])){$fields['texto']=$before['texto'];$fields['formato_texto']=$before['formato_texto']??'texto';}
            if($before&&($before['texto']!==$fields['texto']||($before['formato_texto']??'texto')!==$fields['formato_texto'])&&$before['versao_texto']===$fields['versao_texto'])throw new RuleViolation('Informe uma nova versão do texto ao alterar o modelo.');
            if($fields['padrao'])foreach($this->db->rows('SELECT * FROM '.$this->db->table('modelos_contrato').' WHERE codcoligada=%d AND padrao=1 AND excluido_em IS NULL FOR UPDATE',[Coligadas::current()]) as $old){if((int)$old['idmodelo']===$id)continue;$this->db->update('modelos_contrato',(int)$old['idmodelo'],['padrao'=>0]);$this->db->audit('modelos_contrato',(int)$old['idmodelo'],'trocar_padrao',$old,['padrao'=>0],$key);}
            if($id)$this->db->update('modelos_contrato',$id,$fields);else $id=$this->db->insert('modelos_contrato',$fields);
            $after=$this->db->get('modelos_contrato',$id);$this->db->audit('modelos_contrato',$id,$before?'editar':'criar',$before,['dados'=>$after,'motivo'=>$reason],$key);return $after+['contratos_atualizados'=>$this->syncUnsignedInside($after,$key)];
        })));
    }

    public function remove(int $id,array $data,string $key):array
    {
        Access::requireManageContracts();$reason=Input::text($data['motivo']??null,2000);$version=Input::id($data['versao']??null);
        return $this->db->atomic(fn()=>$this->ops->run($key,'excluir_modelo_contrato',['idmodelo'=>$id]+$data,function()use($id,$version,$reason,$key){
            $this->db->get('coligadas',Coligadas::current(),true);$before=$this->model($id,true);if((int)$before['versao']!==$version)throw new RuleViolation('Modelo alterado. Atualize a tela.');
            $this->db->update('modelos_contrato',$id,['excluido_em'=>gmdate('Y-m-d H:i:s'),'ativo'=>0,'padrao'=>0]);$this->db->audit('modelos_contrato',$id,'excluir',$before,['dados'=>$this->db->get('modelos_contrato',$id),'motivo'=>$reason],$key);return ['excluido'=>true,'idmodelo'=>(string)$id];
        }));
    }

    /** Runs inside the model transaction, after the shared text lock was acquired. */
    private function syncUnsignedInside(array $model,string $key):int
    {
        $rows=$this->db->rows('SELECT idcontrato FROM '.$this->db->table('contratos').' WHERE codcoligada=%d AND idmodelo=%d AND assinado_em IS NULL AND excluido_em IS NULL ORDER BY idcontrato FOR UPDATE',[(int)$model['codcoligada'],(int)$model['idmodelo']]);$count=0;
        foreach($rows as $row){
            $id=(int)$row['idcontrato'];$before=$this->db->get('contratos',$id,true);
            if(\EducacionalERP\Domain\ContractText::signed($before)||!empty($before['excluido_em']))continue;
            $format=$model['formato_texto']??'texto';
            $fields=['termo_snapshot'=>$this->render($model['texto'],$format,$before),'formato_texto'=>$format,'versao_termo'=>$model['versao_texto'],'modelo_nome_snapshot'=>$model['nome'],'modelo_versao_snapshot'=>$model['versao']];
            $changed=false;foreach($fields as $field=>$value)if((string)$before[$field]!==(string)$value)$changed=true;if(!$changed)continue;
            EducbankOutbox::without(fn()=>$this->db->update('contratos',$id,$fields));$this->db->audit('contratos',$id,'sincronizar_modelo_texto',['termo_hash'=>hash('sha256',$before['termo_snapshot']),'modelo_versao'=>$before['modelo_versao_snapshot']],['termo_hash'=>hash('sha256',$fields['termo_snapshot']),'idmodelo'=>$model['idmodelo'],'modelo_versao'=>$model['versao']],$key);$count++;
        }
        return $count;
    }

    private function model(int $id,bool $lock=false):array
    {
        $row=$this->db->get('modelos_contrato',$id,$lock);
        if((int)$row['codcoligada']!==Coligadas::current()||!empty($row['excluido_em']))throw new RuleViolation('Modelo indisponível nesta coligada.');return $row;
    }

    public function preview(array $data):array
    {
        Access::requireManageContracts();$c=$this->db->get('contratos',Input::id($data['idcontrato']??null));
        if((int)$c['codcoligada']!==Coligadas::current()||!empty($c['excluido_em']))throw new RuleViolation('Contrato indisponível nesta coligada.');
        $markup=ContractMarkup::prepare($data['texto']??null,$data['formato_texto']??'auto');
        return ['texto'=>$this->render(self::text($markup['texto'],$markup['formato_texto']),$markup['formato_texto'],$c),'formato_texto'=>$markup['formato_texto']];
    }

    /** Caller owns the contract transaction. Signed texts cannot be replaced. */
    public function applyInside(int $contract,array $data):void
    {
        $c=$this->db->get('contratos',$contract);\EducacionalERP\Domain\ContractText::requireEditable($c);
        Coligadas::within((int)$c['codcoligada'],function()use($c,$contract,$data){
            $model=null;
            if(!empty($data['idmodelo'])){
                $model=$this->model(Input::id($data['idmodelo']),true);
                if(isset($data['modelo_versao'])&&(int)$model['versao']!==Input::id($data['modelo_versao']))throw new RuleViolation('Modelo alterado. Selecione o modelo novamente.');
            }elseif(!array_key_exists('termo',$data)){
                $selected=$this->db->row('SELECT idmodelo FROM '.$this->db->table('modelos_contrato').' WHERE codcoligada=%d AND ativo=1 AND padrao=1 AND excluido_em IS NULL',[Coligadas::current()]);
                if($selected){$model=$this->model((int)$selected['idmodelo'],true);if(!$model['padrao'])throw new RuleViolation('O modelo padrão mudou. Tente novamente.');}
            }
            if($model&&!(int)$model['ativo'])throw new RuleViolation('Modelo de contrato inativo.');
            $markup=ContractMarkup::prepare($model['texto']??($data['termo']??$c['termo_snapshot']),$model['formato_texto']??($data['formato_texto']??(array_key_exists('termo',$data)?'auto':($c['formato_texto']??'texto'))));
            $text=self::text($markup['texto'],$markup['formato_texto']);
            $this->db->update('contratos',$contract,['termo_snapshot'=>$this->render($text,$markup['formato_texto'],$c),'formato_texto'=>$markup['formato_texto'],'versao_termo'=>$model['versao_texto']??($data['versao_termo']??$c['versao_termo']),'idmodelo'=>$model['idmodelo']??null,'modelo_nome_snapshot'=>$model['nome']??null,'modelo_versao_snapshot'=>$model['versao']??null]);
        });
    }

    /** Refresh a linked unsigned model after editing its agreed financial conditions. */
    public function refreshInside(int $contract):void
    {
        $c=$this->db->get('contratos',$contract);if(\EducacionalERP\Domain\ContractText::signed($c)||empty($c['idmodelo']))return;
        $model=$this->db->get('modelos_contrato',(int)$c['idmodelo']);if((int)$model['codcoligada']!==(int)$c['codcoligada']||!empty($model['excluido_em']))return;
        Coligadas::within((int)$c['codcoligada'],function()use($c,$model,$contract){
            $format=$model['formato_texto']??'texto';$fields=['termo_snapshot'=>$this->render($model['texto'],$format,$c),'formato_texto'=>$format,'versao_termo'=>$model['versao_texto'],'modelo_nome_snapshot'=>$model['nome'],'modelo_versao_snapshot'=>$model['versao']];
            foreach($fields as $field=>$value)if((string)$c[$field]!==(string)$value){EducbankOutbox::without(fn()=>$this->db->update('contratos',$contract,$fields));break;}
        });
    }

    private static function currency(int $cents):string {return 'R$ '.number_format($cents/100,2,',','.');}
    private function installmentTokens(array $c):array
    {
        $parcels=$this->db->rows('SELECT numero,valor_original,desconto_incondicional,valor_liquido FROM '.$this->db->table('parcelas').' WHERE idcontrato=%d ORDER BY numero',[(int)$c['idcontrato']]);
        if(!$parcels)$parcels=(new Scholarships($this->db))->plannedInstallments($c);
        $groups=[];$totals=['valor_original'=>0,'desconto_incondicional'=>0,'valor_liquido'=>0];
        foreach($parcels as $p){foreach($totals as $field=>$_)$totals[$field]+=Money::cents($p[$field]);$value=Money::cents($p['valor_liquido']);$number=(int)$p['numero'];$last=count($groups)-1;
            if($last>=0&&$groups[$last]['valor']===$value&&$groups[$last]['fim']+1===$number){$groups[$last]['fim']=$number;$groups[$last]['quantidade']++;}
            else $groups[]=['valor'=>$value,'inicio'=>$number,'fim'=>$number,'quantidade'=>1];
        }
        $amounts=[];$description=[];$varied=count($groups)>1;
        foreach($groups as $g){$amount=self::currency($g['valor']);$range=$g['inicio']===$g['fim']?'parcela '.$g['inicio']:'parcelas '.$g['inicio'].' a '.$g['fim'];$amounts[]=$amount.($varied?' ('.$range.')':'');$description[]=$g['quantidade'].($g['quantidade']===1?' parcela de ':' parcelas de ').$amount.($varied?' ('.$range.')':'');}
        return ['contrato_valor_original'=>self::currency($totals['valor_original']),'contrato_desconto'=>self::currency($totals['desconto_incondicional']),'contrato_valor_liquido'=>self::currency($totals['valor_liquido']),'contrato_valor_total'=>self::currency($totals['valor_liquido']),'contrato_valor_parcela'=>implode('; ',$amounts),'contrato_parcelamento'=>implode('; ',$description),'contrato_dia_vencimento'=>substr((string)$c['primeiro_vencimento'],8,2)];
    }

    private static function date(?string $date):string {return $date?implode('/',array_reverse(explode('-',substr($date,0,10)))):'';}
    private static function address(array $person,bool $includeCountry=false):string
    {
        $parts=[];foreach(['rua','numero','complemento','bairro','cidade','estado','cep'] as $field)if(isset($person[$field])&&trim((string)$person[$field])!=='')$parts[]=(string)$person[$field];
        if($includeCountry&&$parts&&isset($person['pais'])&&trim((string)$person['pais'])!=='')$parts[]=(string)$person['pais'];return implode(', ',$parts);
    }
    private function values(array $c):array
    {
        $m=$this->db->get('matriculas',(int)$c['idmatricula']);$a=$this->db->get('alunos',(int)$m['idaluno']);$p=$this->db->get('pessoas',(int)$a['codpessoa']);$rf=$this->db->get('pessoas',(int)$c['codpessoa_rf_atual']);
        $civilStatus=(string)($rf['estado_civil']??'');if(!empty($rf['idestado_civil']))$civilStatus=(string)$this->db->get('estados_civis',(int)$rf['idestado_civil'])['nome'];
        $period=$this->db->get('periodos_letivos',(int)$m['codperiodo']);$course=$this->db->get('cursos',(int)$m['idcurso']);$class=$this->db->get('turmas',(int)$m['idturma_atual']);$shift=$this->db->get('turnos',(int)$class['idturno']);$school=SchoolIdentity::read();$company=$this->db->get('coligadas',(int)$c['codcoligada']);
        $values=['aluno_nome'=>$p['nome'],'aluno_ra'=>$a['ra'],'aluno_cpf'=>$p['cpf']??'','aluno_nascimento'=>self::date($p['data_nascimento']??null),'aluno_email'=>$p['email']??'','aluno_endereco'=>self::address($p),
            'responsavel_nome'=>$rf['nome'],'responsavel_cpf'=>$rf['cpf']??'','responsavel_rg'=>$rf['rg']??'','responsavel_email'=>$rf['email']??'','responsavel_telefone'=>$rf['telefone']??'','responsavel_endereco'=>self::address($rf),
            'responsavel_estado_civil'=>$civilStatus,'responsavel_profissao'=>$rf['profissao']??'','responsavel_endereco_completo'=>self::address($rf,true),
            'colegio_nome'=>$school['nome'],'colegio_razao_social'=>$company['razao_social']?:$school['razao_social'],'colegio_cnpj'=>($company['cnpj']??'')?:$school['cnpj'],'colegio_endereco'=>self::address($school),'colegio_email'=>$school['email'],'colegio_telefone'=>$school['telefone'],
            'contrato_numero'=>$c['numero'],'contrato_data'=>self::date($c['data_contrato']),'contrato_parcelas'=>(string)$c['quantidade_parcelas'],'contrato_primeiro_vencimento'=>self::date($c['primeiro_vencimento']),
            'matricula_id'=>(string)$m['idmatricula'],'periodo_codigo'=>$period['codigo'],'curso_nome'=>$course['nome'],'turma_nome'=>$class['nome'],'turno_nome'=>$shift['nome'],'plano_nome'=>$c['plano_nome_snapshot']??''];
        $values+=$this->installmentTokens($c);
        foreach(['rua','numero','complemento','bairro','cidade','estado','cep','pais'] as $field)$values['responsavel_'.$field]=(string)($rf[$field]??'');
        foreach(['pai','mae'] as $relationship){$parent=$this->db->row('SELECT p.nome,p.cpf FROM '.$this->db->table('aluno_responsaveis').' r JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=r.codpessoa_responsavel WHERE r.idaluno=%d AND r.parentesco=%s AND r.fim_vigencia IS NULL AND r.inicio_vigencia<=UTC_TIMESTAMP() ORDER BY r.idvinculo DESC LIMIT 1',[(int)$a['idaluno'],$relationship]);$values[$relationship.'_nome']=$parent['nome']??'';$values[$relationship.'_cpf']=$parent['cpf']??'';}
        $out=[];foreach(self::TOKENS as $token=>$_)$out['['.$token.']']=(string)($values[$token]??'');return $out;
    }
}
