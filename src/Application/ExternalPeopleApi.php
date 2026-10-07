<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\{Access,FieldGroupPolicy};
/** Server-side authenticated reader. Credentials never enter browser responses. */
final class ExternalPeopleApi
{
    private const OPTION='ederp_external_people_api';
    public const FIELDS=['nome','data_nascimento','cpf','rg','email','telefone','sexo','idestado_civil','profissao','religiao','igreja','rua','numero','complemento','bairro','cep','cidade','estado','pais'];
    public function __construct(private Store $db,private CatalogService $catalog){}
    private function config():array{return get_option(self::OPTION,[]);}
    private function secret(string $value,bool $decrypt=false):string
    {
        if(!function_exists('openssl_encrypt'))throw new RuleViolation('A integração exige OpenSSL.');$key=hash('sha256',wp_salt('auth').'|external-people-api',true);
        if($decrypt){$raw=base64_decode($value,true);if(!$raw||strlen($raw)<28)throw new RuleViolation('Senha inválida. Cadastre-a novamente.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($plain===false)throw new RuleViolation('Senha indisponível. Cadastre-a novamente.');return $plain;}
        $iv=random_bytes(12);$tag='';$out=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);if($out===false)throw new RuleViolation('Não foi possível guardar a senha.');return base64_encode($iv.$tag.$out);
    }
    public function settings():array
    {
        Access::requireAdmin();$c=$this->config();$out=$c;unset($out['senha']);$out['senha_configurada']=!empty($c['senha']);$out['campos']=array_combine(self::FIELDS,self::FIELDS);foreach((new AdditionalFields($this->db))->all() as $f)if((int)$f['ativo'])$out['campos']['extra:'.$f['chave']]=$f['nome'].' (adicional)';return $out;
    }
    public function save(array $d,string $key):array
    {
        Access::requireAdmin();$old=$this->config();$url=trim((string)($d['url']??''));if(strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'||!wp_http_validate_url($url)||parse_url($url,PHP_URL_USER)||parse_url($url,PHP_URL_PASS))throw new RuleViolation('Informe uma URL HTTPS pública válida, sem credenciais na URL.');
        $user=Input::text($d['usuario']??null,191);if(str_contains($user,':'))throw new RuleViolation('Usuário inválido.');$password=(string)($d['senha']??'');
        $mapping=$d['mapeamento']??[];if(!is_array($mapping)||count($mapping)>200)throw new RuleViolation('Mapeamento inválido.');$allowed=array_keys($this->settings()['campos']);$seen=[];
        foreach($mapping as $m){if(!is_array($m)||!in_array($m['destino']??'', $allowed,true)||!in_array($m['pessoa']??'',['aluno','pai','mae','outro','financeiro'],true))throw new RuleViolation('Destino do mapeamento inválido.');$this->path((string)($m['origem']??''));$slot=$m['pessoa'].':'.$m['destino'];if(isset($seen[$slot]))throw new RuleViolation('Campo de destino repetido para a mesma pessoa.');$seen[$slot]=true;if(isset($m['traducao'])&&!is_array($m['traducao']))throw new RuleViolation('Tradução deve ser um objeto JSON.');}
        $list=(string)($d['caminho_lista']??'data');$identifier=(string)($d['campo_id']??'');$this->path($list);if($identifier!=='')$this->path($identifier);
        $c=['url'=>$url,'usuario'=>$user,'senha'=>$password!==''?$this->secret($password):($old['senha']??''),'caminho_lista'=>$list,'campo_id'=>$identifier,'parametro_pagina'=>Input::text($d['parametro_pagina']??'page',50),'mapeamento'=>$mapping];if(!$c['senha'])throw new RuleViolation('Informe a senha da API.');
        $this->db->audit('configuracoes',0,'api_pessoas',null,['url'=>$url,'mapeamento'=>$mapping],$key);update_option(self::OPTION,$c,false);$t=$this->db->table('api_requerimentos');foreach($this->db->rows("SELECT * FROM $t WHERE status='pendente' AND origem_json IS NOT NULL") as $pending){if(($old['url']??'')===$c['url'])$this->db->atomic(function()use($pending){$locked=$this->db->get('api_requerimentos',(int)$pending['idrequerimento'],true);if($locked['status']!=='pendente')return;$raw=json_decode($locked['origem_json'],true);if(is_array($raw)){$people=$this->map($raw);if($people)$this->db->update('api_requerimentos',(int)$locked['idrequerimento'],['pessoas_json'=>wp_json_encode($people)]);}});}return $this->settings();
    }
    private function path(string $path):void{if(strlen($path)>250||($path!==''&&!preg_match('/^[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)*$/D',$path)))throw new RuleViolation('Use caminhos JSON separados por ponto (ex.: data ou pessoa.nome).');}
    public static function value(array $row,string $path):mixed
    {
        if($path==='')return $row;$v=$row;foreach(explode('.',$path) as $part){if(!is_array($v)||!array_key_exists($part,$v))return null;$v=$v[$part];}return $v;
    }
    private function fetch(int $page):array
    {
        $c=$this->config();if(!$c)throw new RuleViolation('Configure a conexão primeiro.');$url=add_query_arg($c['parametro_pagina'],$page,$c['url']);
        $response=wp_safe_remote_get($url,['headers'=>['Authorization'=>'Basic '.base64_encode($c['usuario'].':'.$this->secret($c['senha'],true)),'Accept'=>'application/json'],'timeout'=>20,'redirection'=>0,'limit_response_size'=>2097153]);
        if(is_wp_error($response))throw new RuleViolation('Não foi possível conectar à API. Verifique o endereço e a disponibilidade.');$code=wp_remote_retrieve_response_code($response);if($code!==200)throw new RuleViolation('API retornou HTTP '.$code.'. Verifique autenticação e bloqueios do servidor de origem.');$body=wp_remote_retrieve_body($response);if(strlen($body)>2097152)throw new RuleViolation('Resposta acima de 2 MB. Reduza per_page na URL.');try{$json=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuleViolation('A API não retornou JSON válido.');}if(!is_array($json))throw new RuleViolation('Resposta JSON inválida.');$rows=self::value($json,$c['caminho_lista']);if(!is_array($rows)||!array_is_list($rows)||count($rows)>500)throw new RuleViolation('O caminho da lista deve apontar para uma lista de até 500 registros.');foreach($rows as $row)if(!is_array($row))throw new RuleViolation('Cada registro deve ser um objeto JSON.');return ['rows'=>$rows,'meta'=>is_array($json['meta']??null)?$json['meta']:[]];
    }
    public function test():array
    {
        Access::requireAdmin();$data=$this->fetch(1);$keys=[];$walk=function(array $r,string $prefix='')use(&$walk,&$keys){foreach($r as $k=>$v){$path=$prefix.(string)$k;if(is_array($v)&&$v&&!array_is_list($v))$walk($v,$path.'.');else $keys[$path]=true;}};foreach($data['rows'] as $r)$walk($r);return ['campos_origem'=>array_keys($keys),'amostra'=>array_slice($data['rows'],0,3),'meta'=>$data['meta'],'quantidade'=>count($data['rows'])];
    }
    public function map(array $row):array
    {
        $out=[];foreach($this->config()['mapeamento']??[] as $m){$v=empty($m['origem'])?null:self::value($row,$m['origem']);if($v===null||$v==='')$v=$m['padrao']??'';if(is_array($v))$v=implode("\n",array_map(fn($x)=>is_scalar($x)?(string)$x:'', $v));if(!is_scalar($v)&&$v!==null)throw new RuleViolation('Campo de origem inválido.');$v=(string)$v;if(isset($m['traducao'][$v]))$v=(string)$m['traducao'][$v];$target=$m['destino'];if($target==='data_nascimento'&&preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D',$v,$match))$v="$match[3]-$match[2]-$match[1]";if($target==='sexo')$v=['M'=>'MASCULINO','F'=>'FEMININO'][strtoupper($v)]??strtoupper($v);$person=$m['pessoa'];if(str_starts_with($target,'extra:'))$out[$person]['campos_adicionais'][substr($target,6)]=$v;else $out[$person][$target]=$v;}
        return array_filter($out,fn($p)=>!empty($p['nome']));
    }
    private static function signature(array $value):string
    {
        $sort=function(array $a)use(&$sort):array{if(!array_is_list($a))ksort($a);foreach($a as &$v)if(is_array($v))$v=$sort($v);return $a;};return hash('sha256',wp_json_encode($sort($value)));
    }
    public function sync(int $page,string $key):array
    {
        Access::requireAdmin();if($page<1||$page>100000)throw new RuleViolation('Página inválida.');$c=$this->config();$response=$this->fetch($page);$counts=['novos'=>0,'existentes'=>0,'sem_pessoas'=>0];$t=$this->db->table('api_requerimentos');
        foreach($response['rows'] as $row){$people=$this->map($row);if(!$people){$counts['sem_pessoas']++;continue;}$external=null;$path=$c['campo_id']??'';if($path!==''&&!str_contains(strtolower($path),'cpf'))$external=self::value($row,$path);
            if(!is_scalar($external)||trim((string)$external)===''){$external=null;foreach(['entry_id','submission_id','id'] as $candidate){$value=self::value($row,$candidate);if(is_scalar($value)&&trim((string)$value)!==''){$external=$value;break;}}}
            $fingerprint=self::signature($row);$source=hash('sha256',strtolower((string)parse_url($c['url'],PHP_URL_HOST)).'|'.parse_url($c['url'],PHP_URL_PATH).'|'.($external!==null?'id|'.(string)$external:'json|'.$fingerprint));$display=$external!==null?Input::text((string)$external,191):'REGISTRO-'.substr($fingerprint,0,16);
            $created=$this->db->atomic(function()use($t,$source,$display,$external,$people,$key,$row){$existing=$this->db->row("SELECT * FROM $t WHERE chave_origem=%s FOR UPDATE",[$source]);if($existing){if($existing['status']==='pendente'&&($existing['pessoas_json']!==wp_json_encode($people)||empty($existing['origem_json'])))$this->db->update('api_requerimentos',(int)$existing['idrequerimento'],['pessoas_json'=>wp_json_encode($people),'origem_json'=>wp_json_encode($row)]);return false;}
                // Legacy requests had no record identity; reconcile identical mapped snapshots once.
                foreach($this->db->rows("SELECT * FROM $t WHERE identidade_registro=0 FOR UPDATE") as $old){$snapshot=json_decode($old['pessoas_json'],true);if(is_array($snapshot)&&self::signature($snapshot)===self::signature($people)){$this->db->update('api_requerimentos',(int)$old['idrequerimento'],['chave_origem'=>$source,'identificador'=>$display,'origem_json'=>wp_json_encode($row),'identidade_registro'=>1,'id_externo'=>$external===null?null:(string)$external]);return false;}}
                $id=$this->db->insert('api_requerimentos',['origem_json'=>wp_json_encode($row),'identidade_registro'=>1,'id_externo'=>$external===null?null:(string)$external,'chave_origem'=>$source,'identificador'=>$display,'pessoas_json'=>wp_json_encode($people),'status'=>'pendente']);$this->db->audit('api_requerimentos',$id,'buscar_api',null,['identificador'=>$display],$key);return true;});$counts[$created?'novos':'existentes']++;}
        return $counts+['pagina'=>$page,'meta'=>$response['meta'],'recebidos'=>count($response['rows'])];
    }
    public function listing(int $page):array
    {
        $page=max(1,$page);$t=$this->db->table('api_requerimentos');$rows=$this->db->rows("SELECT * FROM $t WHERE status='pendente' ORDER BY idrequerimento DESC LIMIT 20 OFFSET %d",[($page-1)*20]);foreach($rows as &$row){$p=json_decode($row['pessoas_json'],true);$row['nome']=$p['aluno']['nome']??reset($p)['nome']??'Pessoa';unset($row['pessoas_json'],$row['origem_json']);}return ['items'=>$rows,'total'=>(int)$this->db->row("SELECT COUNT(*) AS n FROM $t WHERE status='pendente'")['n'],'page'=>$page];
    }
    public function importLog(int $page):array
    {
        $page=max(1,$page);$t=$this->db->table('api_requerimentos');$rows=$this->db->rows("SELECT idrequerimento,identificador,conferido_por,criado_em,atualizado_em,importado_em,pessoas_json,resultado_json FROM $t WHERE status='importado' ORDER BY COALESCE(importado_em,atualizado_em) DESC,idrequerimento DESC LIMIT 20 OFFSET %d",[($page-1)*20]);
        foreach($rows as &$row){$people=json_decode($row['pessoas_json'],true);$row['nome']=$people['aluno']['nome']??reset($people)['nome']??'Pessoa';$row['resultado']=json_decode($row['resultado_json'],true);$user=get_userdata((int)$row['conferido_por']);$row['autor']=$user?$user->display_name:'Usuário indisponível';unset($row['pessoas_json'],$row['resultado_json']);}
        return ['items'=>$rows,'total'=>(int)$this->db->row("SELECT COUNT(*) AS n FROM $t WHERE status='importado'")['n'],'page'=>$page];
    }
    public function importLogDetail(int $id):array
    {
        $row=$this->db->get('api_requerimentos',$id);$row['pessoas']=json_decode($row['pessoas_json'],true);unset($row['pessoas_json'],$row['origem_json']);if($row['status']!=='importado')throw new RuleViolation('Este requerimento ainda não foi importado.');
        $permitted=array_column((new AdditionalFields($this->db))->all(),null,'chave');$row['recebidos']=$row['pessoas'];foreach($row['recebidos'] as &$received)if(isset($received['campos_adicionais']))$received['campos_adicionais']=array_intersect_key($received['campos_adicionais'],$permitted);unset($received);foreach($row['recebidos'] as &$person)unset($person['sugestoes']);unset($person);
        $row['aprovados']=json_decode($row['dados_importados_json']??'null',true);$row['resultado']=json_decode($row['resultado_json'],true);$user=get_userdata((int)$row['conferido_por']);$row['autor']=$user?$user->display_name:'Usuário indisponível';unset($row['pessoas'],$row['dados_importados_json'],$row['resultado_json']);
        if($row['aprovados']){$allowed=array_column((new AdditionalFields($this->db))->all(),null,'chave');foreach($row['aprovados'] as &$item)if(isset($item['dados']['campos_adicionais']))$item['dados']['campos_adicionais']=array_intersect_key($item['dados']['campos_adicionais'],$allowed);unset($item);}
        return $row;
    }
    public function detail(int $id):array
    {
        $row=$this->db->get('api_requerimentos',$id);$people=json_decode($row['pessoas_json'],true);$service=new AdditionalFields($this->db);$active=array_column(array_filter($service->groups(),fn($g)=>(int)$g['ativo']),null,'idgrupo');$permitted=array_column(array_filter($service->all(),fn($f)=>(int)$f['ativo']&&(empty($f['idgrupo'])||isset($active[$f['idgrupo']]))),null,'chave');
        foreach($people as &$p){foreach(array_keys($p['campos_adicionais']??[]) as $k)if(!isset($permitted[$k]))throw new RuleViolation('Há campos de grupos sem acesso para seu perfil. Solicite autorização ao administrador.');$cpf=preg_replace('/\D/','',(string)($p['cpf']??''));$p['sugestoes']=$cpf!==''?$this->db->rows('SELECT codpessoa,nome,cpf,data_nascimento FROM '.$this->db->table('pessoas').' WHERE cpf=%s',[$cpf]):[];}unset($p);unset($row['pessoas_json'],$row['origem_json']);$ordered=[];foreach(['aluno','pai','mae','outro','financeiro'] as $slot)if(isset($people[$slot]))$ordered[$slot]=$people[$slot];return $row+['pessoas'=>$ordered];
    }
    public function confirm(int $id,array $d,string $key):array
    {
        return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'confirmar_api_pessoas',['id'=>$id]+$d,function()use($id,$d,$key){$before=$this->db->get('api_requerimentos',$id,true);if($before['status']==='importado')return json_decode($before['resultado_json'],true);if((string)($d['versao']??'')!==(string)$before['versao'])throw new RuleViolation('Requerimento alterado. Recarregue.');$original=$this->detail($id)['pessoas'];$input=$d['pessoas']??[];if(!is_array($input)||array_diff(array_keys($input),array_keys($original)))throw new RuleViolation('Pessoas inválidas.');$result=[];foreach($original as $slot=>$old){$p=$input[$slot]??null;if(!is_array($p))throw new RuleViolation('Confira todas as pessoas.');$action=$p['acao']??'';if($action==='ignorar')continue;if($action==='reutilizar'){$reference=(string)($p['referencia']??'');if(!isset($result[$reference]['id']))throw new RuleViolation('Escolha uma pessoa já confirmada neste requerimento.');$result[$slot]=$result[$reference];}elseif($action==='vincular'){$person=$this->db->get('pessoas',Input::id($p['codpessoa']??null),true);if(!(int)$person['ativo'])throw new RuleViolation('Pessoa inativa.');$result[$slot]=['id'=>$person['codpessoa'],'existente'=>true];}elseif($action==='criar'){$data=$p['dados']??null;if(!is_array($data)||array_diff(array_keys($data),array_merge(self::FIELDS,['campos_adicionais','user_login'])))throw new RuleViolation('Dados inválidos.');$cpf=preg_replace('/\D/','',(string)($data['cpf']??''));$existing=$cpf!==''?$this->db->row('SELECT * FROM '.$this->db->table('pessoas').' WHERE cpf=%s FOR UPDATE',[$cpf]):null;if($existing){if(!(int)$existing['ativo'])throw new RuleViolation('CPF pertence a uma pessoa inativa.');$result[$slot]=['id'=>$existing['codpessoa'],'existente'=>true];}else $result[$slot]=$this->catalog->createInside('pessoas',$data,$key);}else throw new RuleViolation('Escolha criar, aproveitar ou ignorar cada pessoa.');}foreach($result as $slot=>$entry)if(!empty($entry['existente'])){$extra=$input[$slot]['dados']['campos_adicionais']??($original[$slot]['campos_adicionais']??[]);(new AdditionalFields($this->db))->write((int)$entry['id'],$extra,$key);}if(!$result)throw new RuleViolation('Selecione pelo menos uma pessoa.');$snapshot=[];foreach($original as $slot=>$old){$choice=$input[$slot];$snapshot[$slot]=['acao'=>!empty($result[$slot]['existente'])?'aproveitar_existente':$choice['acao'],'codpessoa'=>$result[$slot]['id']??null,'dados'=>isset($result[$slot]['id'])?(new AdditionalFields($this->db))->decorate($this->db->get('pessoas',(int)$result[$slot]['id'])):null];}$this->db->update('api_requerimentos',$id,['importado_em'=>gmdate('Y-m-d H:i:s'),'dados_importados_json'=>wp_json_encode($snapshot),'status'=>'importado','resultado_json'=>wp_json_encode($result),'conferido_por'=>get_current_user_id()]);$this->db->audit('api_requerimentos',$id,'confirmar',null,$result,$key);return $result;}));
    }
}
