<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,AccountDeletion};
use EducacionalERP\Domain\{Input,RuleViolation};
/** Preview, compare, then commit the entire selected dependency graph. */
final class PermanentDeletion
{
    public function __construct(private Database $db){}
    public function preview(array $data):array {Access::requireAdmin();return $this->db->atomic(fn()=>(new PermanentDeletionPlan($this->db,$data))->summary());}
    public function remove(array $data,string $key):array
    {
        Access::requireAdmin();Input::key($key);$reason=Input::text($data['motivo']??null,2000);
        if(($data['confirmacao']??'')!=='EXCLUIR'||!is_string($data['assinatura']??null))throw new RuleViolation('Confira a prévia e digite EXCLUIR para confirmar.');
        $normalize=static function(array $value)use(&$normalize):array{ksort($value);foreach($value as &$item)if(is_array($item))$item=$normalize($item);return $value;};
        $requestHash=hash('sha256',serialize([get_current_user_id(),$normalize($data)]));$locks=['ederp_purge_'.substr(hash('sha256',$this->db->wp->prefix),0,32),'ederp_period_'.substr(hash('sha256',$this->db->table('periodos_letivos')),0,32)];$held=[];
        try{
            foreach($locks as $lock){if((int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Outra operação está em andamento. Tente novamente.');$held[]=$lock;}
            $result=$this->db->atomic(function()use($data,$key,$reason,$requestHash){
                $table=$this->db->table('exclusoes_definitivas');$receipt=$this->db->row("SELECT * FROM $table WHERE chave=%s FOR UPDATE",[$key]);
                if($receipt){if(!hash_equals($receipt['request_hash'],$requestHash))throw new RuleViolation('Esta confirmação já foi usada em outra exclusão.');return json_decode($receipt['resposta_json'],true,512,JSON_THROW_ON_ERROR);}
                $plan=new PermanentDeletionPlan($this->db,$data);if(!hash_equals($plan->signature(),$data['assinatura']))throw new RuleViolation('Os dados ou vínculos mudaram. Gere uma nova prévia antes de excluir.');
                $summary=$plan->summary();$order=$plan->deleteOrder();$catalog=new PermanentDeletionCatalog($this->db);$files=$plan->files();
                $id=$this->db->insert('exclusoes_definitivas',['chave'=>$key,'request_hash'=>$requestHash,'ator_wp_user_id'=>get_current_user_id(),'tipo'=>$plan->request['tipo'],'alvo_json'=>wp_json_encode($plan->request['chave']),'motivo'=>$reason,'resumo_json'=>wp_json_encode(array_map(fn($g)=>['tipo'=>$g['tipo'],'quantidade'=>$g['quantidade']],$summary['grupos'])),'arquivos_json'=>wp_json_encode($files),'ocorrido_em'=>gmdate('Y-m-d H:i:s')]);
                foreach($plan->accounts as $person=>$user)(new AccountDeletion($this->db))->remove($person);
                foreach($plan->rows as $type=>$rows){$columns=[];foreach($this->db->schema()[$type]['fks'] as $fk)foreach($fk['columns'] as $col)if(!str_contains($this->db->schema()[$type]['columns'][$col],'NOT NULL'))$columns[$col]=true;if(!$columns)continue;foreach($rows as $row){[$where,$args]=$catalog->where($catalog->rowKey($type,$row));$this->db->query('UPDATE '.$this->db->table($type).' SET '.implode(',',array_map(fn($c)=>'`'.$c.'`=NULL',array_keys($columns))).' WHERE '.$where,$args);}}
                foreach($order as [$type,$row]){[$where,$args]=$catalog->where($catalog->rowKey($type,$row));$deleted=$this->db->query('DELETE FROM '.$this->db->table($type).' WHERE '.$where,$args);if($deleted!==1&&$type!=='pessoa_usuarios')throw new RuleViolation('Um registro mudou durante a exclusão. Toda a operação foi revertida.');}
                $wp=$this->db->wp;
                if($plan->options||isset($plan->rows['coligadas'])){$engine=$this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',[$wp->options]);if(strtoupper($engine['ENGINE']??'')!=='INNODB')throw new RuleViolation('As configurações WordPress precisam ser InnoDB para a exclusão transacional.');foreach($plan->options as $option)$this->db->query("DELETE FROM $wp->options WHERE option_id=%s",[$option['option_id']]);$this->db->onCompletion(static function()use($plan){wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');wp_cache_delete('ederp_default_company','options');foreach($plan->options as $o)wp_cache_delete($o['option_name'],'options');foreach($plan->preferences as $p)wp_cache_delete((int)$p['user_id'],'user_meta');});}
                if(isset($plan->rows['coligadas'])){
                    if($plan->preferences){$engine=$this->db->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',[$wp->usermeta]);if(strtoupper($engine['ENGINE']??'')!=='INNODB')throw new RuleViolation('As preferências dos usuários precisam ser InnoDB para a exclusão transacional.');}
                    $fallback=(int)($plan->fallback['codcoligada']??$this->db->insert('coligadas',['nome'=>'NOVA COLIGADA','razao_social'=>'NOVA COLIGADA']));
                    $this->db->query("INSERT INTO $wp->options (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",['ederp_default_company',(string)$fallback]);
                    foreach($plan->preferences as $pref)$this->db->query("UPDATE $wp->usermeta SET meta_value=%s WHERE umeta_id=%s",[(string)$fallback,$pref['umeta_id']]);
                }
                $result=['excluido'=>true,'total'=>$summary['total'],'usuarios'=>$summary['usuarios'],'comprovante'=>(string)$id];
                $this->db->query("UPDATE $table SET resposta_json=%s WHERE idexclusao=%d",[wp_json_encode($result),$id]);return $result;
            });
        }finally{foreach(array_reverse($held) as $lock)$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}
        try{(new PermanentDeletionFiles($this->db))->cleanup();}catch(\Throwable $ignored){/* Durable cleanup is retried by WordPress. */}
        $receipt=$this->db->row('SELECT arquivos_json FROM '.$this->db->table('exclusoes_definitivas').' WHERE idexclusao=%s',[$result['comprovante']]);$result['arquivos_pendentes']=count(json_decode($receipt['arquivos_json']??'[]',true)??[]);return $result;
    }
}
