<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,CadastroText};
use EducacionalERP\Infrastructure\WordPress\Access;
/** Legal ownership is separate from WordPress accounts and shared people. */
final class Coligadas
{
    private static ?int $context=null;
    public function __construct(private Store $db){}
    public static function current():int {return self::$context??max(1,function_exists('get_user_meta')?(int)get_user_meta(get_current_user_id(),'ederp_coligada',true):1);}
    public static function option(string $key):string {return self::current()===1?$key:$key.'_coligada_'.self::current();}
    public static function within(int $id,callable $work):mixed {$old=self::$context;self::$context=$id;try{return $work();}finally{self::$context=$old;}}
    public static function management():bool {return Access::isAdmin()||current_user_can('erp_gerenciar_academico')||current_user_can('erp_gerenciar_pessoas')||current_user_can('erp_consultar_financeiro');}
    public function listing():array {return ['codcoligada'=>self::current(),'items'=>$this->db->rows('SELECT * FROM '.$this->db->table('coligadas').' ORDER BY nome,codcoligada')];}
    public function choose(array $data):array {
        if(!self::management())throw new RuleViolation('Somente a equipe pode selecionar a coligada de trabalho.');
        $id=Input::id($data['codcoligada']??null);if(!(int)$this->db->get('coligadas',$id)['ativo'])throw new RuleViolation('Coligada inativa.');
        update_user_meta(get_current_user_id(),'ederp_coligada',$id);return ['codcoligada'=>$id];
    }
    public function save(array $d,string $key):array {
        Access::requireAdmin();return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'salvar_coligada',$d,function()use($d,$key){
            $cnpj=preg_replace('/\D/','',(string)($d['cnpj']??''));if(!self::validCnpj($cnpj))throw new RuleViolation('Informe um CNPJ válido.');
            $fields=['nome'=>CadastroText::upper(Input::text($d['nome']??null)),'razao_social'=>CadastroText::upper(Input::text($d['razao_social']??null)),'cnpj'=>$cnpj];
            if(isset($d['ativo'])&&!in_array($d['ativo'],[0,1,'0','1'],true))throw new RuleViolation('Situação inválida.');$fields['ativo']=(int)($d['ativo']??1);
            if(!empty($d['codcoligada'])){$id=Input::id($d['codcoligada']);$old=$this->db->get('coligadas',$id,true);if((string)($d['versao']??'')!==(string)$old['versao'])throw new RuleViolation('Coligada alterada. Recarregue.');if(!$fields['ativo']&&$id===self::current())throw new RuleViolation('Selecione outra coligada antes de inativar esta.');$this->db->update('coligadas',$id,$fields);}else{$old=null;$id=$this->db->insert('coligadas',$fields);}
            $this->db->audit('coligadas',$id,$old?'editar':'criar',$old,$fields,$key);return ['codcoligada'=>$id];
        }));
    }
    public function nextPeriod(int $source,int $company):array {
        $from=$this->db->get('periodos_letivos',$source);$configured=empty($from['codperiodo_proximo'])?null:$this->db->get('periodos_letivos',(int)$from['codperiodo_proximo']);
        if(!$configured)throw new RuleViolation('Configure o próximo período da origem primeiro.');
        $this->db->get('coligadas',$company);$period=$this->db->row('SELECT * FROM '.$this->db->table('periodos_letivos')." WHERE codcoligada=%d AND codigo=%s AND data_inicio=%s AND data_fim=%s AND status<>'encerrado'",[$company,$configured['codigo'],$configured['data_inicio'],$configured['data_fim']]);
        if(!$period)throw new RuleViolation('Cadastre na coligada de destino o próximo período com o mesmo código e datas do período configurado na origem.');
        return $period;
    }
    public static function destinationPeriod(Store $db,array $from,array $to):bool {
        $id=(int)($from['codperiodo_proximo']??0);if(!$id)return false;
        $configured=$db->get('periodos_letivos',$id);
        if((int)$from['codcoligada']===(int)$to['codcoligada'])return $id===(int)$to['codperiodo'];
        return $configured['codigo']===$to['codigo']&&$configured['data_inicio']===$to['data_inicio']&&$configured['data_fim']===$to['data_fim'];
    }
    public function destinationStudent(array $origin,int $company,string $key):array {
        $person=$this->db->get('pessoas',(int)$origin['codpessoa'],true);
        $table=$this->db->table('alunos');$target=$this->db->row("SELECT * FROM $table WHERE codpessoa=%d AND codcoligada=%d FOR UPDATE",[(int)$person['codpessoa'],$company]);
        if(!$target){
            $conflict=$this->db->row("SELECT idaluno FROM $table WHERE ra=%s AND codcoligada=%d FOR UPDATE",[$origin['ra'],$company]);if($conflict)throw new RuleViolation('O RA já pertence a outra pessoa na coligada de destino. Procure a secretaria.');
            $id=$this->db->insert('alunos',['codcoligada'=>$company,'codpessoa'=>$person['codpessoa'],'ra'=>$origin['ra'],'tipo_aluno'=>$origin['tipo_aluno']]);$target=$this->db->get('alunos',$id);
            $this->db->audit('alunos',$id,'entrada_coligada',null,['idaluno_origem'=>$origin['idaluno'],'codcoligada_origem'=>$origin['codcoligada'],'codcoligada_destino'=>$company],$key);
        }
        if(!(int)$target['ativo'])throw new RuleViolation('Aluno inativo na coligada de destino. Procure a secretaria.');
        $links=$this->db->table('aluno_responsaveis');$source=$this->db->rows("SELECT * FROM $links WHERE idaluno=%d AND fim_vigencia IS NULL AND inicio_vigencia<=UTC_TIMESTAMP() ORDER BY idvinculo",[(int)$origin['idaluno']]);
        $existing=$this->db->rows("SELECT * FROM $links WHERE idaluno=%d AND fim_vigencia IS NULL ORDER BY idvinculo",[(int)$target['idaluno']]);
        if(!$existing){foreach($source as $link)$this->db->insert('aluno_responsaveis',['idaluno'=>$target['idaluno'],'codpessoa_responsavel'=>$link['codpessoa_responsavel'],'parentesco'=>$link['parentesco'],'responsavel_academico'=>$link['responsavel_academico'],'responsavel_financeiro'=>$link['responsavel_financeiro'],'pode_rematricular'=>$link['pode_rematricular'],'inicio_vigencia'=>gmdate('Y-m-d H:i:s')]);}
        else { // Do not overwrite the target's established family/financial authority silently.
            $oldRf=array_values(array_filter($source,fn($v)=>(int)$v['responsavel_financeiro']===1));$newRf=array_values(array_filter($existing,fn($v)=>(int)$v['responsavel_financeiro']===1));
            if(count($oldRf)!==1||count($newRf)!==1||(int)$oldRf[0]['codpessoa_responsavel']!==(int)$newRf[0]['codpessoa_responsavel'])throw new RuleViolation('Responsáveis financeiros diferentes entre as coligadas. Procure a secretaria para confirmar os vínculos.');
            foreach($source as $link){$matches=array_values(array_filter($existing,fn($v)=>(int)$v['codpessoa_responsavel']===(int)$link['codpessoa_responsavel']));if(!$matches||(($link['responsavel_academico']||$link['pode_rematricular'])&&((int)$matches[0]['responsavel_academico']!==(int)$link['responsavel_academico']||(int)$matches[0]['pode_rematricular']!==(int)$link['pode_rematricular'])))throw new RuleViolation('Vínculos de responsáveis diferentes na coligada de destino. Procure a secretaria para conferir a ficha antes de rematricular.');}
        }
        return $target;
    }
    private static function validCnpj(string $s):bool {
        if(!preg_match('/^\d{14}$/D',$s)||preg_match('/^(\d)\1{13}$/D',$s))return false;
        foreach([[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]] as $i=>$weights){$sum=0;foreach($weights as $j=>$w)$sum+=(int)$s[$j]*$w;$mod=$sum%11;if((int)$s[12+$i]!==($mod<2?0:11-$mod))return false;}return true;
    }
    /** Initial insertion happens before ownership FKs are added; DDL is repeatable. */
    public function seed():void {
        if($this->db->row('SELECT codcoligada FROM '.$this->db->table('coligadas').' WHERE codcoligada=1'))return;
        $identity=get_option('ederp_school_identity',[]);$this->db->insert('coligadas',['codcoligada'=>1,'nome'=>$identity['nome']??'COLIGADA PRINCIPAL','razao_social'=>$identity['razao_social']??'COLIGADA PRINCIPAL','cnpj'=>self::validCnpj(preg_replace('/\D/','',(string)($identity['cnpj']??'')))?preg_replace('/\D/','',(string)$identity['cnpj']):null]);
    }
}
