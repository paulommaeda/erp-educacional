<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Domain\RuleViolation;
/** Follows incoming relations only: shared parents never enter implicitly. */
final class PermanentDeletionPlan
{
    public array $rows=[];
    public array $accounts=[];
    public array $options=[];
    public array $preferences=[];
    public ?array $fallback=null;
    public ?array $defaultCompany=null;
    public array $request;
    public array $root;
    private PermanentDeletionCatalog $catalog;
    public function __construct(private Database $db,array $request)
    {
        $this->catalog=new PermanentDeletionCatalog($db);$type=$this->catalog->type($request['tipo']??null);$key=$this->catalog->key($type,$request['chave']??null);
        if(isset($request['incluir_pessoa'])&&!is_bool($request['incluir_pessoa']))throw new RuleViolation('Opção de pessoa inválida.');
        $this->request=['tipo'=>$type,'chave'=>$key,'incluir_pessoa'=>$type==='alunos'&&($request['incluir_pessoa']??false)];
        [$where,$args]=$this->catalog->where($key);$this->root=$db->row('SELECT * FROM '.$db->table($type).' WHERE '.$where.' FOR UPDATE',$args)??throw new RuleViolation('Registro não encontrado.');$this->add($type,[$this->root]);
        if($this->request['incluir_pessoa'])$this->add('pessoas',[$db->get('pessoas',(int)$this->root['codpessoa'],true)]);
        $done=[];
        do{
            $front=[];foreach($this->rows as $name=>$rows)foreach($rows as $id=>$row)if(!isset($done[$name][$id])){$front[$name][]=$row;$done[$name][$id]=true;}
            foreach($front as $parent=>$parents){
                foreach($db->schema() as $child=>$meta)foreach($meta['fks'] as $fk)if($fk['target']===$parent){foreach(array_chunk($parents,150) as $batch){$parts=[];$values=[];foreach($batch as $row){$terms=[];$tuple=[];$valid=true;foreach($fk['columns'] as $i=>$col){$v=$row[$fk['references'][$i]];if($v===null){$valid=false;break;}$terms[]='`'.$col.'`=%s';$tuple[]=$v;}if($valid){$parts[]='('.implode(' AND ',$terms).')';array_push($values,...$tuple);}}if($parts)$this->select($child,implode(' OR ',$parts),$values);}}
                // References preserved from older backups do not have operational FKs.
                foreach($parents as $row){
                    if($parent==='matriculas')$this->select('registros_preservados','idmatricula_origem=%s',[$row['idmatricula']]);
                    if($parent==='matricula_movimentacoes')$this->select('registros_preservados','tabela_origem=%s AND registro_origem_id=%s',['matricula_movimentacoes',$row['idmovimentacao']]);
                    if($parent==='coligadas')$this->select('registros_preservados','codcoligada_origem=%s',[$row['codcoligada']]);
                    if($parent==='chat_mensagens')$this->select('chat_anexos','codcoligada=%s AND idmensagem_original=%s',[$row['codcoligada'],$row['idmensagem']]);
                    if($parent==='pessoa_usuarios'){
                        $user=(int)$row['wp_user_id'];if($user===get_current_user_id())throw new RuleViolation('A seleção inclui sua própria conta. Entre com outro administrador para excluir esta pessoa.');
                        $this->accounts[(int)$row['codpessoa']]=$user;
                        foreach(['usuario_acessos','chat_membros','chat_leituras'] as $name)$this->select($name,'wp_user_id=%s',[$user]);
                    }
                    if(count($db->schema()[$parent]['pk'])===1&&!in_array($parent,['auditoria','exclusoes_definitivas'],true)){$pk=$db->schema()[$parent]['pk'][0];$scope=isset($row['codcoligada'])?' AND codcoligada=%s':'';$this->select('auditoria','entidade=%s AND entidade_id=%s'.$scope,isset($row['codcoligada'])?[$parent,$row[$pk],$row['codcoligada']]:[$parent,$row[$pk]]);}
                }
            }
        }while($front);
        // Keep approval receipts from APIs from pointing back to deleted students/people.
        if(!empty($this->rows['alunos'])||!empty($this->rows['pessoas'])){
            $students=[];$people=[];foreach($this->rows['alunos']??[] as $r)$students[(string)$r['idaluno']]=true;foreach($this->rows['pessoas']??[] as $r)$people[(string)$r['codpessoa']]=true;
            foreach($db->rows('SELECT * FROM '.$db->table('api_requerimentos').' WHERE resultado_json IS NOT NULL FOR UPDATE') as $r){$result=json_decode($r['resultado_json'],true)??[];$match=isset($students[(string)($result['idaluno']??'')]);foreach(($result['pessoas']??[]) as $p)if(is_scalar($p)&&isset($people[(string)$p]))$match=true;if($match)$this->add('api_requerimentos',[$r]);}
        }
        // Old idempotency responses are data too, and must not resurrect a deleted record on retry.
        $ids=[];foreach($this->rows as $name=>$rows){$pk=$db->schema()[$name]['pk'][0];if(in_array($pk,['idvinculo','idvalor','idrequerimento'],true))continue;foreach($rows as $r)$ids[$pk][(string)$r[$pk]]=true;}
        $matches=function(array $value)use(&$matches,$ids):bool{foreach($value as $field=>$v){if(is_array($v)){if($matches($v))return true;}elseif(isset($ids[$field][(string)$v]))return true;}return false;};
        foreach($db->rows('SELECT * FROM '.$db->table('operacoes').' WHERE resposta_json IS NOT NULL FOR UPDATE') as $r){$result=json_decode($r['resposta_json'],true);$match=is_array($result)&&$matches($result);foreach($this->rows as $name=>$rows)if(in_array($r['tipo'],['criar_'.$name,'editar_'.$name],true)&&isset($result['id'])){foreach($rows as $selected)if((string)$selected[$db->schema()[$name]['pk'][0]]===(string)$result['id'])$match=true;}if($match)$this->add('operacoes',[$r]);}
        foreach(['api_requerimentos','operacoes'] as $name)foreach($this->rows[$name]??[] as $r){$pk=$db->schema()[$name]['pk'][0];$this->select('auditoria','entidade=%s AND entidade_id=%s',[$name,$r[$pk]]);}
        $wp=$db->wp;
        foreach($this->rows['periodos_letivos']??[] as $r){$name=Coligadas::within((int)$r['codcoligada'],fn()=>Coligadas::option('ederp_current_period'));$option=$db->row("SELECT option_id,option_name,option_value FROM $wp->options WHERE option_name=%s AND option_value=%s FOR UPDATE",[$name,$r['codperiodo']]);if($option)$this->options[(string)$option['option_id']]=$option;}
        if(!empty($this->rows['coligadas'])){
            $removed=array_map(fn($r)=>(string)$r['codcoligada'],array_values($this->rows['coligadas']));$placeholders=implode(',',array_fill(0,count($removed),'%s'));
            $this->fallback=$db->row('SELECT codcoligada FROM '.$db->table('coligadas').' WHERE ativo=1 AND codcoligada NOT IN ('.$placeholders.') ORDER BY codcoligada LIMIT 1 FOR UPDATE',$removed);
            $this->defaultCompany=$db->row("SELECT option_id,option_name,option_value FROM $wp->options WHERE option_name='ederp_default_company' FOR UPDATE");
            $this->preferences=$db->rows("SELECT umeta_id,user_id,meta_value FROM $wp->usermeta WHERE meta_key='ederp_coligada' AND meta_value IN ($placeholders) ORDER BY umeta_id FOR UPDATE",$removed);
            foreach($removed as $company){$names=$company==='1'?['ederp_school_identity','ederp_pontualidade','ederp_educbank','ederp_educbank_retorno','ederp_public_admissions','ederp_renewal_introduction','ederp_renewal_block_message','ederp_student_manual','ederp_current_period']:[];$where=$names?'option_name IN ('.implode(',',array_fill(0,count($names),'%s')).')':'option_name LIKE %s';$values=$names?:['%'.$wp->esc_like('_coligada_'.$company)];foreach($db->rows("SELECT option_id,option_name,option_value FROM $wp->options WHERE $where ORDER BY option_id FOR UPDATE",$values) as $option)$this->options[(string)$option['option_id']]=$option;}
        }
        ksort($this->rows);foreach($this->rows as &$rows)ksort($rows);unset($rows);ksort($this->accounts);ksort($this->options);
    }
    private function add(string $name,array $rows):void {foreach($rows as $row){ksort($row);$key=json_encode($this->catalog->rowKey($name,$row));$this->rows[$name][$key]=$row;}}
    private function select(string $name,string $where,array $args):void
    {
        $cols=array_keys($this->db->schema()[$name]['columns']);$sql=implode(',',array_map(fn($c)=>$c==='conteudo_base64'?'SHA2(conteudo_base64,256) AS conteudo_base64':'`'.$c.'`',$cols));$this->add($name,$this->db->rows('SELECT '.$sql.' FROM '.$this->db->table($name).' WHERE '.$where.' ORDER BY '.implode(',',$this->db->schema()[$name]['pk']).' FOR UPDATE',$args));
    }
    public function signature():string {return hash_hmac('sha256',serialize([get_current_user_id(),$this->request,$this->rows,$this->accounts,$this->options,$this->preferences,$this->fallback,$this->defaultCompany]),wp_salt('auth'));}
    public function summary():array
    {
        $groups=[];$total=0;foreach($this->rows as $name=>$rows){$count=count($rows);$total+=$count;$samples=[];foreach(array_slice($rows,0,3) as $row)$samples[]=implode(' / ',$this->catalog->rowKey($name,$row)).(($title=$this->catalog->title($name,$row))!==''?' · '.$title:'');$groups[]=['tipo'=>$name,'nome'=>PermanentDeletionCatalog::label($name),'quantidade'=>$count,'exemplos'=>$samples];}
        $affected=[];$removed=[];foreach($this->rows['alunos']??[] as $r){$affected[(string)$r['idaluno']]=true;$removed[(string)$r['idaluno']]=true;}foreach($this->rows['matriculas']??[] as $r)$affected[(string)$r['idaluno']]=true;
        foreach($this->rows['contratos']??[] as $r){$m=$this->db->row('SELECT idaluno FROM '.$this->db->table('matriculas').' WHERE idmatricula=%s',[$r['idmatricula']]);if($m)$affected[(string)$m['idaluno']]=true;}
        $students=[];foreach(array_slice(array_keys($affected),0,100) as $id){$r=$this->db->row('SELECT a.idaluno,a.ra,a.codcoligada,p.nome FROM '.$this->db->table('alunos').' a JOIN '.$this->db->table('pessoas').' p ON p.codpessoa=a.codpessoa WHERE a.idaluno=%s',[$id]);if($r){$r['excluir_ficha']=isset($removed[(string)$id]);$students[]=$r;}}
        return ['tipo'=>$this->request['tipo'],'chave'=>$this->request['chave'],'rotulo'=>$this->catalog->title($this->request['tipo'],$this->root),'incluir_pessoa'=>$this->request['incluir_pessoa'],'assinatura'=>$this->signature(),'total'=>$total,'grupos'=>$groups,'usuarios'=>count($this->accounts),'configuracoes'=>count($this->options),'arquivos'=>count($this->files()),'educbank'=>isset($this->rows['educbank_fila']),'pessoa_disponivel'=>$this->request['tipo']==='alunos','nova_coligada'=>isset($this->rows['coligadas'])&&$this->fallback===null,'alunos_afetados'=>$students,'total_alunos_afetados'=>count($affected)];
    }
    public function files():array
    {
        $files=[];foreach($this->rows as $name=>$rows)foreach($rows as $row){
            if(in_array($name,['aluno_documentos','requerimento_arquivos'],true)&&!empty($row['arquivo_token']))$files['aluno:'.$row['arquivo_token']]=['tipo'=>'aluno','token'=>$row['arquivo_token']];
            if($name==='matricula_movimentacoes'&&!empty($row['documento_token']))$files['privado:'.$row['documento_token']]=['tipo'=>'privado','token'=>$row['documento_token']];
            if($name==='registros_preservados'){$d=json_decode($row['dados_json'],true);if(!empty($d['documento_token']))$files['privado:'.$d['documento_token']]=['tipo'=>'privado','token'=>$d['documento_token']];}
            if($name==='exclusoes_definitivas')foreach(json_decode($row['arquivos_json']??'[]',true)??[] as $f)$files[$f['tipo'].':'.$f['token']]=$f;
        }return array_values($files);
    }
    /** Nullable links are cleared only on records that are all about to be deleted. */
    public function deleteOrder():array
    {
        $nodes=[];$parents=[];$incoming=[];$maps=[];$schema=$this->db->schema();
        foreach($this->rows as $type=>$rows)foreach($rows as $id=>$row){$node=$type.':'.$id;$nodes[$node]=[$type,$row];$incoming[$node]=0;}
        foreach($nodes as $child=>[$type,$row])foreach($schema[$type]['fks'] as $fk){$nullable=false;$values=[];foreach($fk['columns'] as $col){if(!str_contains($schema[$type]['columns'][$col],'NOT NULL'))$nullable=true;$values[]=$row[$col];}if($nullable||in_array(null,$values,true)||empty($this->rows[$fk['target']]))continue;$mk=$fk['target'].':'.implode(',',$fk['references']);if(!isset($maps[$mk]))foreach($this->rows[$fk['target']] as $id=>$target)$maps[$mk][serialize(array_map(fn($c)=>(string)$target[$c],$fk['references']))]=$fk['target'].':'.$id;$parent=$maps[$mk][serialize(array_map('strval',$values))]??null;if($parent&&!isset($parents[$child][$parent])){$parents[$child][$parent]=true;$incoming[$parent]++;}}
        $queue=[];foreach($incoming as $node=>$count)if(!$count)$queue[]=$node;$order=[];
        for($i=0;$i<count($queue);$i++){$node=$queue[$i];$order[]=$nodes[$node];foreach($parents[$node]??[] as $parent=>$ignored)if(--$incoming[$parent]===0)$queue[]=$parent;}
        if(count($order)!==count($nodes))throw new RuleViolation('Dependência circular obrigatória encontrada. Nenhum dado foi excluído.');return $order;
    }
}
