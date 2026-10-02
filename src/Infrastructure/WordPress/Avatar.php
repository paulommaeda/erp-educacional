<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\Store;
final class Avatar
{
    public function __construct(private Store $db) {}
    public function filter(array $args,mixed $who):array
    {
        if(get_option('ederp_schema_version')!=='4'||get_option('ederp_schema_error'))return $args;
        $id=0;
        if(is_numeric($who))$id=(int)$who;
        elseif($who instanceof \WP_User)$id=(int)$who->ID;
        elseif($who instanceof \WP_Post)$id=(int)$who->post_author;
        elseif($who instanceof \WP_Comment)$id=(int)$who->user_id;
        elseif(is_string($who)&&is_email($who)){$u=get_user_by('email',$who);$id=$u?(int)$u->ID:0;}
        if(!$id)return $args;
        $p=$this->db->table('pessoas');$u=$this->db->table('pessoa_usuarios');
        try{$row=$this->db->row("SELECT p.foto_attachment_id FROM $p p JOIN $u u ON u.codpessoa=p.codpessoa WHERE u.wp_user_id=%d",[$id]);}catch(\Throwable $e){return $args;}
        if(!empty($row['foto_attachment_id'])) {
            $url=wp_get_attachment_image_url((int)$row['foto_attachment_id'],'thumbnail');
            if($url){$args['url']=$url;$args['found_avatar']=true;}
        }
        return $args;
    }
}
