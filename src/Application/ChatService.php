<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\{Input,RuleViolation};
/** Conversations always have a channel and a guardian person; there are no user-to-user recipients. */
final class ChatService
{
    public function __construct(private Database $db) {}
    public static function all():bool {return Access::isAdmin()||current_user_can('erp_chat_supervisao');}
    public static function eligible(object $user):bool
    {
        if(array_intersect($user->roles,['erp_secretaria','erp_coordenacao','erp_orientacao']))return true;
        foreach($user->roles as $role){$r=get_role($role);if(str_starts_with($role,'erp_custom_')&&$r&&(!empty($r->capabilities['erp_chat_atender'])||preg_match('/secret|coordena|orienta/iu',wp_roles()->roles[$role]['name']??'')))return true;}return false;
    }
    private function member(int $channel):bool
    {
        if(self::all())return true;
        if(!self::eligible(wp_get_current_user()))return false;
        return (bool)$this->db->row('SELECT idmembro FROM '.$this->db->table('chat_membros').' WHERE idcanal=%d AND wp_user_id=%d',[$channel,get_current_user_id()]);
    }
    private function linked(int $person,int $company):bool
    {
        $v=$this->db->table('aluno_responsaveis');
        return (bool)$this->db->row("SELECT idvinculo FROM $v WHERE codpessoa_responsavel=%d AND codcoligada=%d AND inicio_vigencia<=UTC_TIMESTAMP() AND fim_vigencia IS NULL AND (responsavel_academico=1 OR responsavel_financeiro=1 OR pode_rematricular=1) LIMIT 1",[$person,$company]);
    }
    private function person():int {return (new Access($this->db))->person()??0;}
    public function channels(bool $admin=false):array
    {
        if($admin)Access::requireAdmin();
        $items=$this->db->rows('SELECT idcanal,codcoligada,nome,descricao,ativo,versao FROM '.$this->db->table('chat_canais').' ORDER BY nome,idcanal');$out=[];
        foreach($items as $row){$member=$this->member((int)$row['idcanal']);if(!$admin&&!self::all()&&!$member&&(!(int)$row['ativo']||!$this->linked($this->person(),(int)$row['codcoligada'])))continue;
            $row['atendente']=$member;$row['membros']=$admin?array_column($this->db->rows('SELECT wp_user_id FROM '.$this->db->table('chat_membros').' WHERE idcanal=%d',[(int)$row['idcanal']]),'wp_user_id'):[];$out[]=$row;}
        return ['items'=>$out,'admin'=>Access::isAdmin(),'supervisao'=>self::all()];
    }
    public function staff(string $search=''):array
    {
        Access::requireAdmin();$roles=[];foreach(wp_roles()->roles as $slug=>$meta)if(in_array($slug,['erp_secretaria','erp_coordenacao','erp_orientacao'],true)||(str_starts_with($slug,'erp_custom_')&&(!empty($meta['capabilities']['erp_chat_atender'])||preg_match('/secret|coordena|orienta/iu',$meta['name']))))$roles[]=$slug;$users=get_users(['role__in'=>$roles,'number'=>100,'search'=>$search?'*'.$search.'*':'','search_columns'=>['display_name','user_login','user_email'],'orderby'=>'display_name']);$items=[];
        foreach($users as $u)if(self::eligible($u))$items[]=['id'=>$u->ID,'nome'=>$u->display_name];
        return ['items'=>$items];
    }
    public function saveChannel(array $data,string $key):array
    {
        Access::requireAdmin();$id=(int)($data['idcanal']??0);$name=Input::text($data['nome']??null,120);$members=$data['membros']??[];
        if(!is_array($members)||count($members)>100)throw new RuleViolation('Lista de atendentes inválida.');
        $members=array_values(array_unique(array_map(fn($id)=>Input::id($id),$members)));foreach($members as $uid){$u=get_userdata($uid);if(!$u||!self::eligible($u))throw new RuleViolation('Inclua apenas Secretaria, Coordenação ou Orientação.');}
        $company=$id?(int)$this->db->get('chat_canais',$id)['codcoligada']:Input::id($data['codcoligada']??Coligadas::current());
        return Coligadas::within($company,fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'chat_canal',$data,function()use($id,$data,$name,$members,$company,$key){
            $values=['nome'=>$name,'descricao'=>sanitize_textarea_field((string)($data['descricao']??'')),'ativo'=>empty($data['ativo'])?0:1,'codcoligada'=>$company];if(strlen($values['descricao'])>500)throw new RuleViolation('Descrição muito longa.');
            if($id){$old=$this->db->get('chat_canais',$id,true);if((int)$old['versao']!==Input::id($data['versao']??null))throw new RuleViolation('Canal alterado. Reabra o cadastro.');$this->db->update('chat_canais',$id,$values);$channel=$id;}else{$old=null;$channel=$this->db->insert('chat_canais',$values);}
            $this->db->query('DELETE FROM '.$this->db->table('chat_membros').' WHERE idcanal=%d',[$channel]);foreach($members as $uid)$this->db->insert('chat_membros',['idcanal'=>$channel,'wp_user_id'=>$uid]);
            $this->db->audit('chat_canais',$channel,'configurar',$old,['nome'=>$name,'membros'=>$members],$key);return ['idcanal'=>$channel];
        })));
    }
    private function conversation(int $id,bool $write=false):array
    {
        $c=$this->db->get('chat_conversas',$id);$channel=$this->db->get('chat_canais',(int)$c['idcanal']);$staff=$this->member((int)$c['idcanal']);
        if(!$staff&&((int)$c['codpessoa']!==$this->person()||!$this->linked($this->person(),(int)$c['codcoligada'])))throw new RuleViolation('Sem acesso a esta conversa.');
        if($write&&!(int)$channel['ativo'])throw new RuleViolation('Canal desativado. O histórico continua disponível.');$c['atendente']=$staff;return $c;
    }
    public function recipients(int $channel,string $search,int $page):array
    {
        $c=$this->db->get('chat_canais',$channel);if(!$this->member($channel))throw new RuleViolation('Somente atendentes podem iniciar contato com uma família.');
        $p=$this->db->table('pessoas');$v=$this->db->table('aluno_responsaveis');$u=$this->db->table('pessoa_usuarios');$term='%'.$this->db->wp->esc_like($search).'%';$page=max(1,$page);
        $rows=$this->db->rows("SELECT p.codpessoa,p.nome FROM $p p JOIN $u u ON u.codpessoa=p.codpessoa WHERE p.ativo=1 AND p.nome LIKE %s AND EXISTS (SELECT 1 FROM $v v WHERE v.codpessoa_responsavel=p.codpessoa AND v.codcoligada=%d AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND (v.responsavel_academico=1 OR v.responsavel_financeiro=1 OR v.pode_rematricular=1)) ORDER BY p.nome,p.codpessoa LIMIT 21 OFFSET %d",[$term,(int)$c['codcoligada'],($page-1)*20]);return ['items'=>array_slice($rows,0,20),'more'=>count($rows)>20];
    }
    public function start(array $data,string $key):array
    {
        $id=Input::id($data['idcanal']??null);$channel=$this->db->get('chat_canais',$id);$staff=$this->member($id);$person=$staff?Input::id($data['codpessoa']??null):$this->person();
        if(!$staff&&isset($data['codpessoa'])&&(int)$data['codpessoa']!==$person)throw new RuleViolation('Não é permitido conversar como outra família.');
        if(!(int)$channel['ativo']||!$this->linked($person,(int)$channel['codcoligada']))throw new RuleViolation('Canal indisponível para esta família.');
        if(!$this->db->row('SELECT wp_user_id FROM '.$this->db->table('pessoa_usuarios').' WHERE codpessoa=%d',[$person]))throw new RuleViolation('Responsável sem conta vinculada.');
        return Coligadas::within((int)$channel['codcoligada'],fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'chat_iniciar',$data,function()use($id,$person){
            $this->db->get('chat_canais',$id,true);$c=$this->db->row('SELECT idconversa FROM '.$this->db->table('chat_conversas').' WHERE idcanal=%d AND codpessoa=%d',[$id,$person]);return ['idconversa'=>$c?(int)$c['idconversa']:$this->db->insert('chat_conversas',['idcanal'=>$id,'codpessoa'=>$person])];
        })));
    }
    public function conversations(int $before=0):array
    {
        $c=$this->db->table('chat_conversas');$ch=$this->db->table('chat_canais');$p=$this->db->table('pessoas');$members=$this->db->table('chat_membros');$v=$this->db->table('aluno_responsaveis');$reads=$this->db->table('chat_leituras');$m=$this->db->table('chat_mensagens');$uid=get_current_user_id();$person=$this->person();
        $where='1=1';$args=[$uid];if(!self::all()){$where="((c.codpessoa=%d AND EXISTS (SELECT 1 FROM $v v WHERE v.codpessoa_responsavel=c.codpessoa AND v.codcoligada=c.codcoligada AND v.inicio_vigencia<=UTC_TIMESTAMP() AND v.fim_vigencia IS NULL AND (v.responsavel_academico=1 OR v.responsavel_financeiro=1 OR v.pode_rematricular=1)))";$args[]=$person;if(self::eligible(wp_get_current_user())){$where.=" OR EXISTS (SELECT 1 FROM $members mb WHERE mb.idcanal=c.idcanal AND mb.wp_user_id=%d)";$args[]=$uid;}$where.=')';}
        // Recent conversations first; cursor is the last conversation in the loaded page.
        if($before){$cursor=$this->conversation($before);$where.=' AND (c.ultima_mensagem_id<%d OR (c.ultima_mensagem_id=%d AND c.idconversa<%d))';$args[]=(int)$cursor['ultima_mensagem_id'];$args[]=(int)$cursor['ultima_mensagem_id'];$args[]=$before;}
        $rows=$this->db->rows("SELECT c.idconversa,c.idcanal,c.codpessoa,c.codcoligada,c.ultima_mensagem_id,ch.nome AS canal,ch.ativo,p.nome AS responsavel,(SELECT COUNT(*) FROM $m mm WHERE mm.idconversa=c.idconversa AND mm.idmensagem>COALESCE(r.ultima_mensagem_id,0) AND mm.autor_wp_user_id<>%d) AS nao_lidas FROM $c c JOIN $ch ch ON ch.idcanal=c.idcanal JOIN $p p ON p.codpessoa=c.codpessoa LEFT JOIN $reads r ON r.idconversa=c.idconversa AND r.wp_user_id=$uid WHERE $where ORDER BY c.ultima_mensagem_id DESC,c.idconversa DESC LIMIT 51",$args);
        return ['items'=>array_slice($rows,0,50),'more'=>count($rows)>50];
    }
    public function detail(int $id):array
    {
        $c=$this->conversation($id);$ch=$this->db->get('chat_canais',(int)$c['idcanal']);$p=$this->db->get('pessoas',(int)$c['codpessoa']);return ['idconversa'=>$id,'idcanal'=>$c['idcanal'],'codpessoa'=>$c['codpessoa'],'canal'=>$ch['nome'],'responsavel'=>$p['nome'],'ativo'=>$ch['ativo'],'ultima_mensagem_id'=>$c['ultima_mensagem_id']];
    }
    public function messages(int $id,int $after=0,int $before=0):array
    {
        $c=$this->conversation($id);$where='idconversa=%d';$args=[$id];if($after){$where.=' AND idmensagem>%d';$args[]=$after;}if($before){$where.=' AND idmensagem<%d';$args[]=$before;}
        $rows=$this->db->rows('SELECT idmensagem,autor_wp_user_id,autor_nome,origem,texto,criado_em FROM '.$this->db->table('chat_mensagens')." WHERE $where ORDER BY idmensagem ".($after?'ASC':'DESC').' LIMIT 51',$args);$more=count($rows)>50;$rows=array_slice($rows,0,50);if(!$after)$rows=array_reverse($rows);
        foreach($rows as &$row){$row['minha']=(int)$row['autor_wp_user_id']===get_current_user_id();$row['anexos']=$this->db->rows('SELECT idanexo,nome,mime,tamanho FROM '.$this->db->table('chat_anexos').' WHERE idmensagem=%d',[(int)$row['idmensagem']]);}unset($row);
        return ['items'=>$rows,'more'=>$more,'atendente'=>$c['atendente']];
    }
    private function attachments(mixed $files):array
    {
        if(!is_array($files)||count($files)>3)throw new RuleViolation('Envie no máximo 3 anexos.');$out=[];$total=0;
        $types=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','txt'=>'text/plain'];
        foreach($files as $file){if(!is_array($file))throw new RuleViolation('Anexo inválido.');$name=sanitize_file_name((string)($file['nome']??''));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$bytes=base64_decode((string)($file['conteudo']??''),true);if(!$name||strlen($name)>191||!isset($types[$ext])||$bytes===false||!strlen($bytes))throw new RuleViolation('Use PDF, JPG, PNG, WEBP ou TXT.');$total+=strlen($bytes);if($total>5*1024*1024)throw new RuleViolation('Os anexos juntos devem ter no máximo 5 MB.');
            if(!class_exists('finfo'))throw new RuleViolation('O servidor precisa habilitar Fileinfo para validar anexos.');$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);if($mime!==$types[$ext])throw new RuleViolation('O conteúdo do arquivo não corresponde ao tipo permitido.');if(str_starts_with($mime,'image/')&&!@getimagesizefromstring($bytes))throw new RuleViolation('Imagem inválida.');
            $out[]=['nome'=>$name,'mime'=>$mime,'tamanho'=>strlen($bytes),'conteudo_base64'=>base64_encode($bytes)];}return $out;
    }
    public function send(int $id,array $data,string $key):array
    {
        $c=$this->conversation($id,true);$text=trim(sanitize_textarea_field((string)($data['texto']??'')));if(strlen($text)>10000)throw new RuleViolation('Mensagem acima do limite de 10.000 bytes.');$files=$this->attachments($data['anexos']??[]);if($text===''&&!$files)throw new RuleViolation('Escreva uma mensagem ou anexe um arquivo.');
        return Coligadas::within((int)$c['codcoligada'],fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'chat_mensagem',['idconversa'=>$id]+$data,function()use($id,$text,$files){
            $c=$this->conversation($id,true);$this->db->get('chat_canais',(int)$c['idcanal'],true);$c=$this->conversation($id,true);$this->db->get('chat_conversas',$id,true);$recent=$this->db->row('SELECT COUNT(*) AS n FROM '.$this->db->table('chat_mensagens').' WHERE autor_wp_user_id=%d AND criado_em>=%s',[get_current_user_id(),gmdate('Y-m-d H:i:s',time()-60)]);if((int)$recent['n']>=60)throw new RuleViolation('Muitas mensagens em sequência. Aguarde um minuto para continuar.');$mid=$this->db->insert('chat_mensagens',['idconversa'=>$id,'autor_wp_user_id'=>get_current_user_id(),'autor_nome'=>wp_get_current_user()->display_name,'origem'=>$c['atendente']?'escola':'responsavel','texto'=>$text]);foreach($files as $file)$this->db->insert('chat_anexos',['idmensagem'=>$mid]+$file);$this->db->update('chat_conversas',$id,['ultima_mensagem_id'=>$mid]);return ['idmensagem'=>$mid];
        })));
    }
    public function read(int $id,int $message):array
    {
        $c=$this->conversation($id);$message=min(max(0,$message),(int)$c['ultima_mensagem_id']);
        return Coligadas::within((int)$c['codcoligada'],fn()=>$this->db->atomic(function()use($id,$message){$this->db->get('chat_conversas',$id,true);$t=$this->db->table('chat_leituras');$r=$this->db->row("SELECT * FROM $t WHERE idconversa=%d AND wp_user_id=%d",[$id,get_current_user_id()]);if($r)$this->db->update('chat_leituras',(int)$r['idleitura'],['ultima_mensagem_id'=>max($message,(int)$r['ultima_mensagem_id'])]);else $this->db->insert('chat_leituras',['idconversa'=>$id,'wp_user_id'=>get_current_user_id(),'ultima_mensagem_id'=>$message]);return ['lido'=>true];}));
    }
    public function attachment(int $id):array
    {
        $f=$this->db->get('chat_anexos',$id);$m=$this->db->get('chat_mensagens',(int)$f['idmensagem']);$this->conversation((int)$m['idconversa']);return ['nome'=>$f['nome'],'mime'=>$f['mime'],'conteudo'=>$f['conteudo_base64']];
    }
}
