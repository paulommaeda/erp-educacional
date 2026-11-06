<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Admin;
use EducacionalERP\Application\PreservedRecords;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\{Input,RuleViolation};
final class BackupRecovery
{
    public function __construct(private Database $db){}
    public function register():void
    {
        add_action('admin_menu',function(){add_submenu_page('ederp','Registros preservados do backup','Registros preservados','manage_options','ederp-backup-recuperacao',[$this,'screen']);});
        add_action('admin_post_ederp_preserved_record',[$this,'download']);
    }
    public function notice():void
    {
        if(!Access::isAdmin())return;$count=(new PreservedRecords($this->db))->count();
        if($count)echo '<div class="notice notice-warning"><p>'.esc_html((string)$count).' registro(s) do backup foram preservados para conferência. <a href="'.esc_url(admin_url('admin.php?page=ederp-backup-recuperacao')).'">Conferir registros preservados</a></p></div>';
    }
    public static function integrityReport():void
    {
        if(!Access::isAdmin())return;$report=get_option('ederp_schema_integrity',[]);$issues=$report['referencias']??[];if(!$issues)return;
        echo '<details open><summary><strong>Referências pendentes: '.esc_html((string)count($issues)).'</strong></summary><p>Os vínculos abaixo não foram encontrados no banco restaurado. Confira as tabelas correspondentes do backup antes de continuar.</p><ul>';
        foreach($issues as $issue){$label=$issue['tabela'].' ('.implode(', ',$issue['colunas']).') → '.$issue['destino'].' ('.implode(', ',$issue['referencias']).'): '.$issue['quantidade'].' registro(s). Primeiro código: '.$issue['primeiro_id'];echo '<li style="overflow-wrap:anywhere">'.esc_html($label).'</li>';}
        echo '</ul></details>';
    }
    public function screen():void
    {
        if(!Access::isAdmin()){wp_die('Somente administradores podem conferir os registros preservados.','',['response'=>403]);return;}
        $service=new PreservedRecords($this->db);$page=max(1,(int)($_GET['erp_preservados_pagina']??1));$data=$service->listing($page);
        echo '<section class="wrap ederp"><h1>Registros preservados do backup</h1><p>Estas movimentações vieram sem a matrícula correspondente. A cópia integral, os códigos originais e as referências aos documentos foram mantidos para conferência do administrador.</p><p><a href="'.esc_url(admin_url('admin.php?page=ederp-config')).'">Voltar às configurações</a></p><p>'.esc_html((string)$data['total']).' registro(s) preservado(s).</p>';
        foreach($data['items'] as $row){$id=(int)$row['idpreservado'];echo '<article class="ederp-card"><h2>Movimentação #'.esc_html((string)$row['registro_origem_id']).'</h2><p>'.esc_html($row['motivo']).'</p><p>Matrícula original: #'.esc_html((string)$row['idmatricula_origem']).' · Coligada original: #'.esc_html((string)$row['codcoligada_origem']).'</p><p>';
            foreach(['registro'=>'Baixar registro completo','documento'=>'Baixar documento preservado'] as $type=>$label){if($type==='documento'&&!(int)$row['tem_documento'])continue;$url=wp_nonce_url(add_query_arg(['action'=>'ederp_preserved_record','id'=>$id,'tipo'=>$type],admin_url('admin-post.php')),'ederp_preserved_'.$id.'_'.$type);echo '<a class="button erp-link-button" href="'.esc_url($url).'">'.esc_html($label).'</a> ';}
            echo '</p></article>';
        }
        echo '<nav aria-label="Páginas dos registros preservados">';if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'ederp-backup-recuperacao','erp_preservados_pagina'=>$page-1],admin_url('admin.php'))).'">Anterior</a> ';if($data['more'])echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'ederp-backup-recuperacao','erp_preservados_pagina'=>$page+1],admin_url('admin.php'))).'">Próxima</a>';echo '</nav></section>';
    }
    public function download():void
    {
        if(!Access::isAdmin()){wp_die('Somente administradores podem baixar os registros preservados.','',['response'=>403]);return;}
        try{$id=Input::id($_GET['id']??null);}catch(RuleViolation $e){wp_die('Registro inválido.');return;}$type=is_string($_GET['tipo']??null)?$_GET['tipo']:'registro';if(!in_array($type,['registro','documento'],true)){wp_die('Tipo de arquivo inválido.');return;}
        check_admin_referer('ederp_preserved_'.$id.'_'.$type);$previous=$this->db->wp->suppress_errors(true);
        try{$file=(new PreservedRecords($this->db))->file($id,$type);}catch(RuleViolation $e){wp_die(esc_html($e->getMessage()));return;}catch(\Throwable $e){wp_die('Não foi possível ler o arquivo. O registro permanece preservado.');return;}finally{$this->db->wp->suppress_errors($previous);}
        $name=sanitize_file_name($file['nome']);if($name==='')$name='registro-'.$id.'.bin';$ascii=str_replace(['"','\\',"\r","\n"],'_',preg_replace('/[^\x20-\x7E]/','_',$name));
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''".rawurlencode($name));header('Content-Length: '.strlen($file['bytes']));echo $file['bytes'];exit;
    }
}
