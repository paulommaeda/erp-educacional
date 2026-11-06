<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Admin;
use EducacionalERP\Application\RecoveredChatAttachments;
use EducacionalERP\Domain\{Input,RuleViolation};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};
final class ChatRecovery
{
    public function __construct(private Database $db) {}
    public function register():void
    {
        add_action('admin_menu',function(){add_submenu_page('ederp','Anexos preservados do chat','Anexos preservados','manage_options','ederp-chat-recuperacao',[$this,'screen']);});
        add_action('admin_post_ederp_recovered_chat_file',[$this,'download']);
    }
    public function notice():void
    {
        if(!Access::isAdmin()||SchemaMigration::pending())return;
        $count=(new RecoveredChatAttachments($this->db))->count();
        if($count)echo '<div class="notice notice-warning"><p>'.esc_html((string)$count).' anexo(s) do chat foram preservados para conferência após a restauração. <a href="'.esc_url(admin_url('admin.php?page=ederp-chat-recuperacao')).'">Conferir anexos preservados</a></p></div>';
    }
    public function screen():void
    {
        if(!Access::isAdmin()){wp_die('Somente administradores podem conferir os anexos preservados.');return;}
        echo '<section class="wrap ederp erp-chat-recovery"><h1>Anexos preservados do chat</h1><p>Estes arquivos vieram do backup com uma mensagem ausente ou de outra coligada. O arquivo, sua coligada e a referência original foram mantidos para conferência pelo administrador.</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=ederp-config')).'">Voltar às configurações</a></p>';
        if(SchemaMigration::pending()){echo '<p>Conclua a atualização do banco em Configurações para conferir os anexos.</p></section>';return;}
        $page=max(1,(int)($_GET['erp_recuperacao_pagina']??1));$data=(new RecoveredChatAttachments($this->db))->listing($page);
        echo '<p>'.esc_html((string)$data['total']).' anexo(s) aguardando conferência.</p><div class="erp-chat-recovery-list">';
        foreach($data['items'] as $row){
            $id=(int)$row['idanexo'];$url=wp_nonce_url(add_query_arg(['action'=>'ederp_recovered_chat_file','id'=>$id],admin_url('admin-post.php')),'ederp_recovered_chat_'.$id);
            $reason=$row['motivo_recuperacao']==='coligada_divergente'?'Mensagem encontrada em outra coligada.':'Mensagem não encontrada no backup.';
            echo '<article class="ederp-card"><h2 style="overflow-wrap:anywhere">'.esc_html($row['nome']).'</h2><p>'.esc_html($reason).'</p><p>Coligada: '.esc_html($row['coligada']??(string)$row['codcoligada']).' · Mensagem original: '.esc_html((string)$row['idmensagem_original']).' · Tamanho: '.esc_html(size_format((int)$row['tamanho'])).'</p><p><a class="button erp-link-button" href="'.esc_url($url).'">Baixar para conferência</a></p></article>';
        }
        echo '</div><nav aria-label="Páginas dos anexos preservados">';
        if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'ederp-chat-recuperacao','erp_recuperacao_pagina'=>$page-1],admin_url('admin.php'))).'">Anterior</a> ';
        if($data['more'])echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'ederp-chat-recuperacao','erp_recuperacao_pagina'=>$page+1],admin_url('admin.php'))).'">Próxima</a>';
        echo '</nav></section>';
    }
    public function download():void
    {
        if(!Access::isAdmin()){wp_die('Somente administradores podem baixar os anexos preservados.');return;}
        try{$id=Input::id($_GET['id']??null);}catch(RuleViolation $e){wp_die('Anexo inválido.');return;}
        check_admin_referer('ederp_recovered_chat_'.$id);
        $previous=$this->db->wp->suppress_errors(true);
        try{$file=(new RecoveredChatAttachments($this->db))->file($id);}
        catch(RuleViolation $e){wp_die(esc_html($e->getMessage()));return;}
        catch(\Throwable $e){wp_die('Não foi possível baixar o anexo. O registro permanece preservado.');return;}
        finally{$this->db->wp->suppress_errors($previous);}
        $filename=sanitize_file_name($file['nome']);if($filename==='')$filename='anexo-'.$id.'.bin';
        $ascii=preg_replace('/[^\x20-\x7E]/','_',$filename);$ascii=str_replace(['"','\\',"\r","\n"],'_',$ascii);
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
        header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''".rawurlencode($filename));header('Content-Length: '.strlen($file['bytes']));
        echo $file['bytes'];exit;
    }
}
