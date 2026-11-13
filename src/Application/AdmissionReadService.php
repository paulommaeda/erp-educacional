<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation,AdmissionForm,StudentFiles};
use EducacionalERP\Infrastructure\WordPress\{AdmissionReadAccess,StudentDocumentFiles};
/** Read-only snapshots of native requests. No account or private-storage DTOs cross this boundary. */
final class AdmissionReadService
{
    private StudentFiles $files;
    public function __construct(private Store $db,?StudentFiles $files=null){$this->files=$files??new StudentDocumentFiles();}
    private function timestamp(mixed $value):string
    {
        if(!is_string($value))throw new RuleViolation('Use data e hora UTC no formato AAAA-MM-DDTHH:MM:SSZ.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$value)throw new RuleViolation('Use data e hora UTC no formato AAAA-MM-DDTHH:MM:SSZ.');return $date->format('Y-m-d H:i:s');
    }
    private function utc(?string $value):?string {return $value===null?null:str_replace(' ','T',$value).'Z';}
    private function request(int $id,int $company):array
    {
        return $this->db->row('SELECT * FROM '.$this->db->table('requerimentos_publicos').' WHERE idrequerimento=%d AND codcoligada=%d',[$id,$company])??throw new \OutOfBoundsException('Requerimento não encontrado.');
    }
    private function data(string $json):array
    {
        $raw=json_decode($json,true,64,JSON_THROW_ON_ERROR);if(!is_array($raw))throw new \RuntimeException('Requerimento sem dados.');
        $raw=AdmissionForm::fromStored($raw);$out=array_intersect_key($raw,array_flip(['responsavel_academico','responsavel_financeiro','declaracao','declaracao_nome','declaracao_versao']));$out['pessoas']=[];
        foreach(AdmissionForm::PEOPLE as $slot=>$label)if(isset($raw['pessoas'][$slot]))$out['pessoas'][$slot]=array_intersect_key($raw['pessoas'][$slot],array_flip([...ExternalPeopleApi::FIELDS,'empresa','parentesco']));
        $out['campos']=array_intersect_key($raw['campos']??[],array_flip(array_column(AdmissionForm::fields(),'chave')));
        foreach(['retiradas','contatos_emergencia'] as $list){$out[$list]=[];foreach($raw[$list]??[] as $row)$out[$list][]=array_intersect_key($row,array_flip(['pessoa','nome','telefone','parentesco','cpf','rg','observacoes']));}
        return $out;
    }
    private function item(array $r):array
    {
        $item=array_intersect_key($r,array_flip(['protocolo','nome_aluno','status','motivo','declaracao_texto','privacidade_texto']));
        foreach(['idrequerimento','codcoligada','versao','idaluno'] as $key)$item[$key]=isset($r[$key])?(string)$r[$key]:null;
        foreach(['criado_em','atualizado_em','conferido_em'] as $key)$item[$key]=$this->utc($r[$key]);
        $item['origem']='formulario_publico';$item['fonte_dados']=!empty($r['aprovados_json'])?'conferidos':'recebidos';$item['dados_recebidos']=$this->data($r['dados_json']);$item['dados']=!empty($r['aprovados_json'])?$this->data($r['aprovados_json']):$item['dados_recebidos'];
        $item['resultado']=null;
        if(!empty($r['resultado_json'])){$result=json_decode($r['resultado_json'],true,64,JSON_THROW_ON_ERROR);$item['resultado']=array_intersect_key($result,array_flip(['idaluno','ra','protocolo']));$item['resultado']['pessoas']=[];foreach(AdmissionForm::PEOPLE as $slot=>$label)if(isset($result['pessoas'][$slot]))$item['resultado']['pessoas'][$slot]=array_intersect_key($result['pessoas'][$slot],array_flip(['codpessoa','acao']));}
        $item['arquivos']=[];
        foreach($this->db->rows('SELECT idarquivo,campo,arquivo_nome,arquivo_mime,tamanho,arquivo_token FROM '.$this->db->table('requerimento_arquivos').' WHERE codcoligada=%d AND idrequerimento=%d ORDER BY idarquivo',[(int)$r['codcoligada'],(int)$r['idrequerimento']]) as $file){$available=!empty($file['arquivo_token']);$item['arquivos'][]=['idarquivo'=>(string)$file['idarquivo'],'campo'=>$file['campo'],'nome'=>$file['arquivo_nome'],'mime'=>$file['arquivo_mime'],'tamanho'=>(int)$file['tamanho'],'disponivel'=>$available,'rota'=>$available?'/integracao/requerimentos/'.$r['idrequerimento'].'/arquivos/'.$file['idarquivo']:null];}
        return $item;
    }
    public function collection(array $query):array
    {
        $company=AdmissionReadAccess::company($this->db,$query);$cursor=$query['cursor']??'0';if((!is_int($cursor)&&!is_string($cursor))||!preg_match('/^(?:0|[1-9][0-9]{0,17})$/D',(string)$cursor))throw new RuleViolation('Cursor inválido.');
        $limit=isset($query['limit'])?Input::id($query['limit']):20;if($limit>50)throw new RuleViolation('Limite máximo de 50 requerimentos.');
        $status=$query['status']??'todos';if(!in_array($status,['todos','pendente','aprovado','rejeitado'],true))throw new RuleViolation('Situação inválida.');
        $where='codcoligada=%d AND idrequerimento>%d';$args=[$company,(int)$cursor];if($status!=='todos'){$where.=' AND status=%s';$args[]=$status;}
        foreach(['protocolo','idaluno'] as $key)if(isset($query[$key])){$value=$key==='idaluno'?Input::id($query[$key]):Input::text($query[$key],32);$where.=' AND '.$key.($key==='idaluno'?'=%d':'=%s');$args[]=$value;}
        $dates=[];foreach(['atualizado_desde'=>'>=','atualizado_ate'=>'<='] as $key=>$operator)if(isset($query[$key])){$dates[$key]=$this->timestamp($query[$key]);$where.=' AND atualizado_em'.$operator.'%s';$args[]=$dates[$key];}
        if(isset($dates['atualizado_desde'],$dates['atualizado_ate'])&&$dates['atualizado_desde']>$dates['atualizado_ate'])throw new RuleViolation('Intervalo de atualização invertido.');
        return $this->db->atomic(function()use($where,$args,$limit,$company){$rows=$this->db->rows('SELECT * FROM '.$this->db->table('requerimentos_publicos')." WHERE $where ORDER BY idrequerimento LIMIT %d",[...$args,$limit+1]);$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);return ['schema_version'=>'1.0','gerado_em'=>gmdate('Y-m-d\TH:i:s\Z'),'codcoligada'=>(string)$company,'items'=>array_map(fn($r)=>$this->item($r),$rows),'next_cursor'=>$more?(string)end($rows)['idrequerimento']:null,'limit'=>$limit];});
    }
    public function detail(int $id,array $query=[]):array
    {
        $company=AdmissionReadAccess::company($this->db,$query);return $this->db->atomic(fn()=>['schema_version'=>'1.0','gerado_em'=>gmdate('Y-m-d\TH:i:s\Z'),'item'=>$this->item($this->request($id,$company))]);
    }
    public function fields(array $query=[]):array
    {
        $company=AdmissionReadAccess::company($this->db,$query);return Coligadas::within($company,fn()=>['schema_version'=>'1.0','codcoligada'=>(string)$company,'pessoas'=>AdmissionForm::PEOPLE,'campos_pessoa'=>[...ExternalPeopleApi::FIELDS,'empresa','parentesco'],'campos'=>AdmissionForm::fields($this->db),'estados_civis'=>$this->db->rows('SELECT idestado_civil,nome FROM '.$this->db->table('estados_civis').' ORDER BY nome'),'situacoes'=>['pendente','aprovado','rejeitado']]);
    }
    public function file(int $request,int $id,array $query=[]):array
    {
        $company=AdmissionReadAccess::company($this->db,$query);$this->request($request,$company);
        $file=$this->db->row('SELECT arquivo_token,arquivo_nome,arquivo_mime,tamanho,sha256 FROM '.$this->db->table('requerimento_arquivos').' WHERE idarquivo=%d AND idrequerimento=%d AND codcoligada=%d',[$id,$request,$company]);
        if(!$file||empty($file['arquivo_token']))throw new \OutOfBoundsException('Arquivo não encontrado.');$bytes=$this->files->read($file['arquivo_token']);if(!hash_equals($file['sha256'],hash('sha256',$bytes)))throw new \RuntimeException('Arquivo sem integridade.');return ['schema_version'=>'1.0','idrequerimento'=>(string)$request,'idarquivo'=>(string)$id,'nome'=>$file['arquivo_nome'],'mime'=>$file['arquivo_mime'],'tamanho'=>(int)$file['tamanho'],'codificacao'=>'base64','conteudo'=>base64_encode($bytes)];
    }
}
