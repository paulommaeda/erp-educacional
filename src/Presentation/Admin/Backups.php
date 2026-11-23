<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Admin;

use EducacionalERP\Infrastructure\Backup\SiteBackup;

final class Backups
{
    private const PAGE='ederp-backups';

    public static function register():void
    {
        add_action('admin_menu',static fn()=>add_submenu_page('ederp','Backup e restauração','Backup e restauração','manage_options',self::PAGE,[self::class,'screen']));
        add_action('admin_post_ederp_backup', [self::class,'handle']);
        if(defined('WP_CLI')&&WP_CLI)\WP_CLI::add_command('erp backup',[self::class,'cli'],['synopsis'=>[
            ['type'=>'positional','name'=>'operacao','optional'=>false],['type'=>'positional','name'=>'valor','optional'=>true],
            ['type'=>'assoc','name'=>'banco','optional'=>true],['type'=>'assoc','name'=>'usuario','optional'=>true]
        ]]);
    }

    private static function form(string $action,bool $upload=false):void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"'.($upload?' enctype="multipart/form-data"':'').'>';
        echo '<input type="hidden" name="action" value="ederp_backup"><input type="hidden" name="operacao" value="'.esc_attr($action).'">';
        wp_nonce_field('ederp_backup_'.$action);
    }

    private static function password():void
    {
        echo '<p><label>Senha do backup<br><input type="password" name="senha" required minlength="12" maxlength="256" autocomplete="new-password"></label></p>';
    }

    public static function screen():void
    {
        if(!current_user_can('manage_options'))wp_die('Acesso restrito.');
        echo '<div class="wrap"><h1>Backup e restauração do ERP</h1>';
        $token=isset($_GET['resultado'])?sanitize_key(wp_unslash($_GET['resultado'])):'';
        if($token&&preg_match('/^[a-f0-9]{32}$/D',$token)){
            $key='ederp_bkp_'.get_current_user_id().'_'.$token;$result=get_transient($key);
            if(is_array($result)){echo '<div class="notice notice-'.($result['ok']?'success':'error').'"><p>'.nl2br(esc_html($result['texto'])).'</p></div>';delete_transient($key);}
        }
        try{
            $history=(new SiteBackup())->history();
        }catch(\Throwable $e){
            echo '<div class="notice notice-error inline"><p>'.esc_html($e->getMessage()).'</p></div>';
            echo '<p><a href="https://github.com/paulommaeda/erp-educacional/blob/main/docs/BACKUP.md#a-tela-avisa-que-um-diretório-está-indisponível">Como configurar a pasta privada de backup</a></p></div>';
            return;
        }
        echo '<h2>Criar backup</h2><p>O banco inclui as coligadas, o financeiro, as contas do WordPress e as tabelas dos plugins desta instalação. A cópia completa também guarda os arquivos do site e a versão instalada do ERP. Guarde a senha e baixe o ZIP para uma cópia fora da hospedagem.</p>';
        self::form('criar');
        echo '<fieldset><legend>Conteúdo</legend><label><input type="radio" name="tipo" value="completo" checked> Completo · banco e arquivos</label><br><label><input type="radio" name="tipo" value="banco"> Somente banco de dados</label></fieldset>';
        self::password();submit_button('Criar backup');echo '</form><h2>Histórico de backups</h2>';
        if(!$history)echo '<p>Ainda não há backups neste site.</p>';
        else{
            echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Data e hora</th><th>Tipo</th><th>ERP</th><th>WordPress</th><th>Esquema</th><th>Tamanho</th><th>Arquivo</th></tr></thead><tbody>';
            foreach($history as $row){
                $download=wp_nonce_url(add_query_arg(['action'=>'ederp_backup','operacao'=>'baixar','id'=>$row['id']],admin_url('admin-post.php')),'ederp_backup_baixar_'.$row['id']);
                $date=wp_date('d/m/Y H:i T',strtotime($row['data_utc']));
                echo '<tr><td>'.esc_html($date).'</td><td>'.esc_html($row['tipo']==='completo'?'Completo':'Banco').'</td><td>'.esc_html($row['erp']).'</td><td>'.esc_html($row['wordpress']).'</td><td>'.esc_html($row['schema']).'</td><td>'.esc_html(size_format($row['bytes'])).'</td><td><a href="'.esc_url($download).'">Baixar ZIP</a></td></tr>';
            }echo '</tbody></table></div>';
        }
        echo '<h2>Importar backup do ERP</h2>';self::form('importar',true);
        echo '<p><label>ZIP gerado pelo ERP <input type="file" name="pacote" accept=".zip" required></label></p>';
        self::password();submit_button('Verificar e adicionar ao histórico','secondary');echo '</form>';
        if($history){
            echo '<h2>Restaurar uma versão</h2><p>Prepare um banco vazio no mesmo servidor MySQL. O ERP confere o pacote e reconstrói as tabelas nesse banco. Depois da conferência, a troca do site é feita na hospedagem. A cópia completa recupera também a versão do código. Para uma cópia somente do banco, prepare os arquivos da versão registrada.</p>';
            self::form('restaurar');
            echo '<p><label>Backup<br><select name="id" required>';
            foreach($history as $row)echo '<option value="'.esc_attr($row['id']).'">'.esc_html(wp_date('d/m/Y H:i:s',strtotime($row['data_utc'])).' · '.$row['tipo'].' · ERP '.$row['erp'].' · '.substr($row['id'],0,8)).'</option>';
            echo '</select></label></p>';self::password();
            echo '<p><label>Nome do banco vazio de destino<br><input name="banco" required autocomplete="off"></label></p><details><summary>Credenciais do banco de destino</summary><p>Preencha se o banco de destino usar outro usuário MySQL.</p><p><label>Usuário MySQL<br><input name="usuario" autocomplete="off"></label></p><p><label>Senha MySQL<br><input type="password" name="senha_banco" autocomplete="new-password"></label></p></details>';
            submit_button('Restaurar e conferir no destino','secondary');echo '</form>';
        }
        echo '</div>';
    }

    public static function handle():void
    {
        if(!current_user_can('manage_options'))wp_die('Somente administradores podem acessar backups.','',['response'=>403]);
        $get=static fn($key)=>isset($_POST[$key])&&is_string($_POST[$key])?wp_unslash($_POST[$key]):'';
        $action=$get('operacao')?:sanitize_key(wp_unslash($_GET['operacao']??''));
        if($action==='baixar'){
            $id=sanitize_key(wp_unslash($_GET['id']??''));check_admin_referer('ederp_backup_baixar_'.$id);
            try{$service=new SiteBackup();$file=$service->archive($id);$info=null;foreach($service->history() as $item)if($item['id']===$id)$info=$item;
                if(!$info)throw new \RuntimeException('Backup indisponível.');
            }catch(\Throwable $e){wp_die(esc_html($e->getMessage()));}
            $filename=sanitize_file_name('erp-'.$info['erp'].'-'.$info['tipo'].'-'.gmdate('Ymd-His',strtotime($info['data_utc'])).'Z-'.substr($id,0,8).'.zip');
            nocache_headers();header('Content-Type: application/zip');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.filesize($file));readfile($file);exit;
        }
        if($_SERVER['REQUEST_METHOD']!=='POST'||!in_array($action,['criar','restaurar','importar'],true))wp_die('Operação de backup inválida.');
        check_admin_referer('ederp_backup_'.$action);if(function_exists('set_time_limit'))@set_time_limit(0);
        try{
            $service=new SiteBackup();
            if($action==='criar'){$service->create($get('tipo'),$get('senha'));$message='Backup criado e verificado. O arquivo está disponível no histórico.';}
            elseif($action==='importar'){
                $upload=$_FILES['pacote']??[];
                if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']))throw new \RuntimeException('O upload falhou. Confira o limite de arquivo do PHP ou use o WP-CLI.');
                $service->import($upload['tmp_name'],$get('senha'));$message='Pacote verificado e adicionado ao histórico.';
            }else{
                $r=$service->restore($get('id'),$get('senha'),$get('banco'),$get('usuario'),$get('senha_banco'));
                $message='Restauração conferida no banco '.$r['banco'].'. Prefixo '.$r['prefixo'].'. ERP '.$r['backup']['erp'].'.';
                if($r['pasta'])$message.="\nArquivos recuperados em ".$r['pasta'].'/site. A configuração original está em config/wp-config.php, ao lado de site.';
                $message.="\nPara colocar esta cópia em uso, prepare a versão indicada com o wp-config.php apontando para o banco recuperado e faça a troca na hospedagem.";
            }
            $result=['ok'=>true,'texto'=>$message];
        }catch(\Throwable $e){$result=['ok'=>false,'texto'=>$e instanceof \mysqli_sql_exception?'Falha no MySQL ('.$e->getCode().'). Confira o espaço e as permissões do banco.':$e->getMessage()];
            if($action==='restaurar')$result['texto'].=' Em caso de importação parcial, use um novo banco vazio para repetir. O site em uso continua no banco original.';}
        $token=bin2hex(random_bytes(16));set_transient('ederp_bkp_'.get_current_user_id().'_'.$token,$result,300);
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'resultado'=>$token],admin_url('admin.php')));exit;
    }

    /** Executa criar, listar, importar ou restaurar. As senhas são recebidas pelo ambiente do processo. */
    public static function cli(array $args,array $options):void
    {
        try{
            $service=new SiteBackup();$password=(string)getenv('EDERP_BACKUP_PASSWORD');
            $result=match($args[0]??''){
                'listar'=>$service->history(),
                'criar'=>$service->create($args[1]??'completo',$password),
                'importar'=>$service->import($args[1]??'',$password),
                'restaurar'=>$service->restore($args[1]??'',$password,$options['banco']??'',$options['usuario']??'',(string)getenv('EDERP_RESTORE_DB_PASSWORD')),
                default=>throw new \RuntimeException('Use criar completo|banco, listar, importar <zip> ou restaurar <id> --banco=<destino>.')
            };
            \WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }catch(\Throwable $e){\WP_CLI::error($e instanceof \mysqli_sql_exception?'MySQL: erro '.$e->getCode():$e->getMessage());}
    }
}
