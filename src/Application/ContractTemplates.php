<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{Store,Input,Money,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\{Access,SchoolIdentity};

/** A fixed text vocabulary; contract models never execute WordPress shortcodes or HTML. */
final class ContractTemplates
{
    public const TOKENS=[
        'aluno_nome'=>'Nome do aluno','aluno_ra'=>'RA do aluno','aluno_cpf'=>'CPF do aluno','aluno_nascimento'=>'Nascimento do aluno','aluno_email'=>'E-mail do aluno','aluno_endereco'=>'Endereço do aluno',
        'responsavel_nome'=>'Nome do responsável financeiro','responsavel_cpf'=>'CPF do responsável financeiro','responsavel_rg'=>'RG do responsável financeiro','responsavel_email'=>'E-mail do responsável financeiro','responsavel_telefone'=>'Telefone do responsável financeiro','responsavel_endereco'=>'Endereço do responsável financeiro',
        'pai_nome'=>'Nome do pai','pai_cpf'=>'CPF do pai','mae_nome'=>'Nome da mãe','mae_cpf'=>'CPF da mãe',
        'colegio_nome'=>'Nome do colégio','colegio_razao_social'=>'Razão social do colégio','colegio_cnpj'=>'CNPJ do colégio','colegio_endereco'=>'Endereço do colégio','colegio_email'=>'E-mail do colégio','colegio_telefone'=>'Telefone do colégio',
        'contrato_numero'=>'Número do contrato','contrato_data'=>'Data do contrato','contrato_valor_original'=>'Valor original total','contrato_desconto'=>'Bolsa total','contrato_valor_liquido'=>'Valor líquido total','contrato_parcelas'=>'Quantidade de parcelas','contrato_primeiro_vencimento'=>'Primeiro vencimento',
        'matricula_id'=>'Número da matrícula','periodo_codigo'=>'Período letivo','curso_nome'=>'Curso','turma_nome'=>'Turma','turno_nome'=>'Turno','plano_nome'=>'Plano de pagamento'
    ];
    public function __construct(private Store $db,private Operations $ops) {}

    public function listing(bool $all=false):array
    {
        if($all)Access::requireAdmin();
        elseif(!Access::isAdmin()&&!current_user_can('erp_gerenciar_academico'))throw new RuleViolation('Sem permissão para consultar modelos de contrato.');
        return ['items'=>$this->db->rows('SELECT * FROM '.$this->db->table('modelos_contrato').' WHERE codcoligada=%d AND excluido_em IS NULL'.($all?'':' AND ativo=1').' ORDER BY padrao DESC,nome,idmodelo',[Coligadas::current()]),'shortcodes'=>self::TOKENS];
    }

    public static function text(mixed $text):string
    {
        $text=Input::text($text,40000);
        preg_match_all('/\[([^\]\r\n]+)\]/u',$text,$matches);
        foreach($matches[1] as $token)if(!isset(self::TOKENS[$token]))throw new RuleViolation('Shortcode desconhecido: ['.$token.']. Use os campos disponíveis.');
        return $text;
    }

    public function save(?int $id,array $data,string $key):array
    {
        Access::requireAdmin();
        if(array_diff(array_keys($data),['nome','texto','versao_texto','padrao','ativo','versao','motivo']))throw new RuleViolation('Campo de modelo inválido.');
        $fields=['nome'=>Input::text($data['nome']??null,191),'texto'=>self::text($data['texto']??null),'versao_texto'=>Input::text($data['versao_texto']??null,40)];
        foreach(['padrao','ativo'] as $field){if(!in_array($data[$field]??null,[0,1,'0','1'],true))throw new RuleViolation('Situação do modelo inválida.');$fields[$field]=(int)$data[$field];}
        if($fields['padrao']&&!$fields['ativo'])throw new RuleViolation('O modelo padrão precisa estar ativo.');
        $reason=$id?Input::text($data['motivo']??null,2000):'Cadastro do modelo';
        return $this->db->atomic(fn()=>$this->ops->run($key,'salvar_modelo_contrato',['idmodelo'=>$id]+$data,function()use($id,$data,$fields,$reason,$key){
            // Serialize default selection for each company, including new models.
            $this->db->get('coligadas',Coligadas::current(),true);$before=$id?$this->model($id,true):null;
            if($before&&(int)$before['versao']!==Input::id($data['versao']??null))throw new RuleViolation('Modelo alterado. Reabra a edição.');
            if($before&&$before['texto']!==$fields['texto']&&$before['versao_texto']===$fields['versao_texto'])throw new RuleViolation('Informe uma nova versão do texto ao alterar o modelo.');
            if($fields['padrao'])foreach($this->db->rows('SELECT * FROM '.$this->db->table('modelos_contrato').' WHERE codcoligada=%d AND padrao=1 AND excluido_em IS NULL FOR UPDATE',[Coligadas::current()]) as $old){if((int)$old['idmodelo']===$id)continue;$this->db->update('modelos_contrato',(int)$old['idmodelo'],['padrao'=>0]);$this->db->audit('modelos_contrato',(int)$old['idmodelo'],'trocar_padrao',$old,['padrao'=>0],$key);}
            if($id)$this->db->update('modelos_contrato',$id,$fields);else $id=$this->db->insert('modelos_contrato',$fields);
            $after=$this->db->get('modelos_contrato',$id);$this->db->audit('modelos_contrato',$id,$before?'editar':'criar',$before,['dados'=>$after,'motivo'=>$reason],$key);return $after;
        }));
    }

    public function remove(int $id,array $data,string $key):array
    {
        Access::requireAdmin();$reason=Input::text($data['motivo']??null,2000);$version=Input::id($data['versao']??null);
        return $this->db->atomic(fn()=>$this->ops->run($key,'excluir_modelo_contrato',['idmodelo'=>$id]+$data,function()use($id,$version,$reason,$key){
            $this->db->get('coligadas',Coligadas::current(),true);$before=$this->model($id,true);if((int)$before['versao']!==$version)throw new RuleViolation('Modelo alterado. Atualize a tela.');
            $this->db->update('modelos_contrato',$id,['excluido_em'=>gmdate('Y-m-d H:i:s'),'ativo'=>0,'padrao'=>0]);$this->db->audit('modelos_contrato',$id,'excluir',$before,['dados'=>$this->db->get('modelos_contrato',$id),'motivo'=>$reason],$key);return ['excluido'=>true,'idmodelo'=>(string)$id];
        }));
    }

    private function model(int $id,bool $lock=false):array
    {
        $row=$this->db->get('modelos_contrato',$id,$lock);
        if((int)$row['codcoligada']!==Coligadas::current()||!empty($row['excluido_em']))throw new RuleViolation('Modelo indisponível nesta coligada.');return $row;
    }

    public function preview(array $data):array
    {
        Access::requireAdmin();$c=$this->db->get('contratos',Input::id($data['idcontrato']??null));
        if((int)$c['codcoligada']!==Coligadas::current()||!empty($c['excluido_em']))throw new RuleViolation('Contrato indisponível nesta coligada.');
        return ['texto'=>strtr(self::text($data['texto']??null),$this->values($c))];
    }

    /** Caller owns the contract transaction. Existing issued texts are never updated by model edits. */
    public function applyInside(int $contract,array $data):void
    {
        $c=$this->db->get('contratos',$contract);
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
            $text=self::text($model['texto']??($data['termo']??$c['termo_snapshot']));
            $this->db->update('contratos',$contract,['termo_snapshot'=>strtr($text,$this->values($c)),'versao_termo'=>$model['versao_texto']??($data['versao_termo']??$c['versao_termo']),'idmodelo'=>$model['idmodelo']??null,'modelo_nome_snapshot'=>$model['nome']??null,'modelo_versao_snapshot'=>$model['versao']??null]);
        });
    }

    private static function date(?string $date):string {return $date?implode('/',array_reverse(explode('-',substr($date,0,10)))):'';}
    private static function address(array $person):string
    {
        $parts=[];foreach(['rua','numero','complemento','bairro','cidade','estado','cep'] as $field)if(!empty($person[$field]))$parts[]=$person[$field];return implode(', ',$parts);
    }
    private function values(array $c):array
    {
        $m=$this->db->get('matriculas',(int)$c['idmatricula']);$a=$this->db->get('alunos',(int)$m['idaluno']);$p=$this->db->get('pessoas',(int)$a['codpessoa']);$rf=$this->db->get('pessoas',(int)$c['codpessoa_rf_atual']);
        $period=$this->db->get('periodos_letivos',(int)$m['codperiodo']);$course=$this->db->get('cursos',(int)$m['idcurso']);$class=$this->db->get('turmas',(int)$m['idturma_atual']);$shift=$this->db->get('turnos',(int)$class['idturno']);$school=SchoolIdentity::read();$company=$this->db->get('coligadas',(int)$c['codcoligada']);
        $values=['aluno_nome'=>$p['nome'],'aluno_ra'=>$a['ra'],'aluno_cpf'=>$p['cpf']??'','aluno_nascimento'=>self::date($p['data_nascimento']??null),'aluno_email'=>$p['email']??'','aluno_endereco'=>self::address($p),
            'responsavel_nome'=>$rf['nome'],'responsavel_cpf'=>$rf['cpf']??'','responsavel_rg'=>$rf['rg']??'','responsavel_email'=>$rf['email']??'','responsavel_telefone'=>$rf['telefone']??'','responsavel_endereco'=>self::address($rf),
            'colegio_nome'=>$school['nome'],'colegio_razao_social'=>$company['razao_social']?:$school['razao_social'],'colegio_cnpj'=>($company['cnpj']??'')?:$school['cnpj'],'colegio_endereco'=>self::address($school),'colegio_email'=>$school['email'],'colegio_telefone'=>$school['telefone'],
            'contrato_numero'=>$c['numero'],'contrato_data'=>self::date($c['data_contrato']),'contrato_parcelas'=>(string)$c['quantidade_parcelas'],'contrato_primeiro_vencimento'=>self::date($c['primeiro_vencimento']),
            'matricula_id'=>(string)$m['idmatricula'],'periodo_codigo'=>$period['codigo'],'curso_nome'=>$course['nome'],'turma_nome'=>$class['nome'],'turno_nome'=>$shift['nome'],'plano_nome'=>$c['plano_nome_snapshot']??''];
        foreach(['contrato_valor_original'=>'valor_original_total','contrato_desconto'=>'desconto_incondicional_total','contrato_valor_liquido'=>'valor_liquido_total'] as $token=>$field)$values[$token]='R$ '.number_format(Money::cents($c[$field])/100,2,',','.');
        foreach(['pai','mae'] as $relationship){$parent=$this->db->row('SELECT p.nome,p.cpf FROM '.$this->db->table('aluno_responsaveis').' r JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=r.codpessoa_responsavel WHERE r.idaluno=%d AND r.parentesco=%s AND r.fim_vigencia IS NULL AND r.inicio_vigencia<=UTC_TIMESTAMP() ORDER BY r.idvinculo DESC LIMIT 1',[(int)$a['idaluno'],$relationship]);$values[$relationship.'_nome']=$parent['nome']??'';$values[$relationship.'_cpf']=$parent['cpf']??'';}
        $out=[];foreach(self::TOKENS as $token=>$_)$out['['.$token.']']=(string)($values[$token]??'');return $out;
    }
}
