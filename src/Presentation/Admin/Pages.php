<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Admin;
final class Pages
{
    public function register(): void
    {
        add_menu_page('ERP Educacional','ERP Educacional','erp_acessar_admin','ederp',[$this,'home'],'dashicons-welcome-learn-more',26);
        foreach(['alunos'=>['Alunos','erp_gerenciar_pessoas'],'pessoas'=>['Pessoas','erp_gerenciar_pessoas'],'academico'=>['Estrutura acadêmica','erp_gerenciar_academico'],'matriculas'=>['Matrículas','erp_gerenciar_academico'],
            'financeiro'=>['Financeiro','erp_consultar_financeiro'],'rematriculas'=>['Rematrículas','erp_gerenciar_academico'],'exportacao'=>['Exportação','manage_options'],'importacao'=>['Importação','manage_options'],'perfis'=>['Perfis e acessos','manage_options'],'config'=>['Configurações','erp_configurar']] as $slug=>[$title,$cap]) {
            add_submenu_page('ederp',$title,$title,$cap,'ederp-'.$slug,fn()=>$this->screen($slug,$title,$cap));
        }
        add_action('admin_enqueue_scripts',function($hook){ if(str_contains((string)$hook,'ederp')) { self::assets(); } });
    }
    public static function assets(): void
    {
        global $post;
        $photoPage=in_array(sanitize_key((string)($_GET['erp_tela']??'')),['pessoas','alunos','configuracoes'],true)||($post instanceof \WP_Post && preg_match('/\[erp_(pessoas|alunos|configuracoes)\b/',$post->post_content));
        if(current_user_can('upload_files') && (is_admin()||$photoPage)) { wp_enqueue_media(); }
        wp_enqueue_script('ederp-person-fields',plugins_url('assets/person-fields.js',EDERP_FILE),[],EDERP_VERSION,true);
        wp_enqueue_script('ederp-history',plugins_url('assets/history.js',EDERP_FILE),['ederp'],EDERP_VERSION,true);
        wp_enqueue_script('ederp-export',plugins_url('assets/export.js',EDERP_FILE),['ederp'],EDERP_VERSION,true);
        wp_enqueue_script('ederp-import',plugins_url('assets/import.js',EDERP_FILE),['ederp'],EDERP_VERSION,true);
        wp_enqueue_style('ederp-fonts','https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',[],'1');
        wp_enqueue_style('ederp',plugins_url('assets/app.css',EDERP_FILE),['ederp-fonts'],EDERP_VERSION);
        wp_add_inline_style('ederp',\EducacionalERP\Infrastructure\WordPress\SchoolIdentity::css());
        wp_enqueue_script('ederp-modals',plugins_url('assets/modals.js',EDERP_FILE),[],EDERP_VERSION,true);
        wp_enqueue_script('ederp',plugins_url('assets/app.js',EDERP_FILE),['ederp-modals'],EDERP_VERSION,true);
        wp_localize_script('ederp','EDERP',['root'=>esc_url_raw(rest_url('erp-educacional/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'assets'=>plugins_url('assets/',EDERP_FILE),'parentescos'=>\EducacionalERP\Domain\Relationships::LABELS,
            'coligada'=>\EducacionalERP\Application\Coligadas::current(),'currentPeriod'=>\EducacionalERP\Application\SchoolSettings::current(),'period'=>sanitize_text_field((string)($_GET['erp_periodo']??(\EducacionalERP\Application\SchoolSettings::current()?:'todos'))),'front'=>!is_admin(),'portal'=>\EducacionalERP\Presentation\Portal\Portal::url(),'allowed'=>array_keys(\EducacionalERP\Infrastructure\WordPress\MenuPolicy::available()),'admin'=>admin_url('admin.php'),'caps'=>['editFinance'=>current_user_can('erp_ajustar_lancamentos'),'generate'=>\EducacionalERP\Infrastructure\WordPress\Access::canGenerate(),'periods'=>\EducacionalERP\Application\SchoolSettings::canChoose()||\EducacionalERP\Application\SchoolSettings::canChoose('finance'),'people'=>current_user_can('erp_gerenciar_pessoas'),'academic'=>current_user_can('erp_gerenciar_academico'),'finance'=>current_user_can('erp_consultar_financeiro'),'settle'=>current_user_can('erp_baixar_lancamentos'),'adjust'=>current_user_can('erp_ajustar_lancamentos'),'reverse'=>current_user_can('erp_estornar_baixas'),'changeGuardian'=>current_user_can('erp_trocar_responsavel_financeiro'),'admin'=>\EducacionalERP\Infrastructure\WordPress\Access::isAdmin()]]);
        { wp_enqueue_script('ederp-workflow',plugins_url('assets/workflow.js',EDERP_FILE),['ederp','ederp-person-fields','ederp-history'],EDERP_VERSION,true); }
    }
    public function home(): void
    {
        if(!current_user_can('erp_acessar_admin')) { return; }
        echo '<div class="wrap ederp"><h1>Secretaria escolar</h1><p>Cadastre a família, organize os responsáveis e acompanhe a matrícula pela ficha do aluno.</p><div class="erp-home-grid">';
        foreach(['pessoas'=>['1','Cadastre as pessoas','Cada pessoa recebe uma conta WordPress e pode assumir vários papéis.'],'alunos'=>['2','Abra a ficha do aluno','Selecione uma pessoa cadastrada como aluno e vincule os familiares.'],'matriculas'=>['3','Defina período e turma','Vincule ao período letivo e escolha uma turma disponível.']] as $slug=>$card) {
            echo '<a class="ederp-card erp-home-card" href="'.esc_url(admin_url('admin.php?page=ederp-'.$slug)).'"><span class="erp-step-number">'.esc_html($card[0]).'</span><h2>'.esc_html($card[1]).'</h2><p>'.esc_html($card[2]).'</p></a>';
        }
        echo '</div><p>Os dados, responsáveis e matrículas anteriores ficam reunidos na ficha de cada aluno.</p></div>';
    }
    public function screen(string $slug,string $title,string $cap): void
    {
        if($slug==='importacao' && !\EducacionalERP\Infrastructure\WordPress\Access::isAdmin()) { wp_die('Somente administradores.'); }
        if(!current_user_can($cap)) { wp_die('Sem permissão.'); }
        if(in_array($slug,['alunos','pessoas','matriculas','academico','exportacao','importacao','rematriculas','configuracoes'],true)) {
            echo '<div class="wrap ederp erp-workflow" data-erp-screen="'.esc_attr($slug).'"><p role="status">Carregando...</p></div>';
            return;
        }
        if($slug==='config'){echo '<div class="wrap ederp" data-erp-screen="configuracoes"></div>';}
        if($slug==='perfis') { echo (new \EducacionalERP\Presentation\Portal\Portal())->render('perfis');return; }
        echo '<div class="wrap ederp"><h1>'.esc_html($title).'</h1>';
        if($slug==='financeiro') {
            echo '<section class="erp-finance-workspace"><nav class="erp-tabs erp-finance-tabs" aria-label="Consultas financeiras"><button type="button" data-finance-tab="titles" aria-pressed="true" class="active">Lançamentos</button>';
            if(\EducacionalERP\Infrastructure\WordPress\Access::canGenerate())echo '<button type="button" data-finance-tab="pending" aria-pressed="false">Contratos aguardando parcelas</button>';
            echo '</nav>';
            if(\EducacionalERP\Infrastructure\WordPress\Access::canGenerate())echo '<section class="erp-finance-panel" data-ederp-pending data-finance-panel="pending" hidden><h2>Contratos aguardando parcelas</h2></section>';
            echo '<section class="erp-finance-panel" data-finance-panel="titles" data-ederp-finance-search></section><section class="erp-finance-operations"><h2>Operações financeiras</h2><p>Use as ações de cada lançamento ou abra uma operação abaixo.</p><div class="erp-finance-action-grid">';
            if(current_user_can('erp_baixar_lancamentos')) { $this->form('Registrar baixa','lancamentos/{id}/baixas',['id:number'=>'Lançamento','valor_pago'=>'Valor pago (ex.: 100.00)','data_pagamento:date'=>'Data do pagamento','forma_pagamento'=>'Forma de pagamento','referencia_externa?'=>'Referência externa']); }
            if(current_user_can('erp_trocar_responsavel_financeiro')) { $this->form('Trocar responsável financeiro','alunos/{id}/trocas-responsavel-financeiro',['id:number'=>'Aluno','codpessoa_nova:number'=>'Novo responsável financeiro','motivo'=>'Motivo']); }
            if(current_user_can('erp_ajustar_lancamentos')) { $this->form('Ajuste financeiro auditado','lancamentos/{id}/ajustes',['id:number'=>'Lançamento','componente'=>'Componente do ajuste','valor_delta'=>'Variação decimal (ex.: 10.00 ou -10.00)','motivo'=>'Motivo']); }
            if(current_user_can('erp_estornar_baixas')) { $this->form('Estornar baixa integral','baixas/{id}/estornos',['id:number'=>'Baixa','motivo'=>'Motivo']); }
            echo '</div></section></section>';
        } elseif($slug==='rematriculas') {
            $this->form('Publicar oferta de rematrícula','ofertas-rematricula',['codperiodo_destino:number'=>'Código do próximo período','idcurso_origem:number'=>'ID do curso de origem','idcurso_destino:number'=>'ID do curso de destino',
                'data_abertura:date'=>'Data de abertura','data_encerramento:date'=>'Data de encerramento','valor_total'=>'Valor total (ex.: 12000.00)','numero_parcelas:number'=>'Número de parcelas','primeiro_vencimento:date'=>'Primeiro vencimento','turmas'=>'IDs das turmas separados por vírgula','versao_termo'=>'Versão do termo','texto_termo:textarea'=>'Texto integral do termo']);
            echo '<p>O responsável verá as ofertas elegíveis no Portal e confirmará a renovação com aceite do termo.</p>';
        } elseif($slug==='config') {
            echo '<p><a href="'.esc_url(\EducacionalERP\Presentation\Portal\Portal::url()).'">Abrir portal escolar</a></p>';
            echo '<h2>Contas WordPress automáticas</h2><p>Cadastre as pessoas em Pessoas. A conta é criada com o nascimento informado, e os perfis são sincronizados pelos vínculos.</p>';
            echo '<h2>Banco de dados</h2><p>Versão instalada: '.esc_html((string)get_option('ederp_schema_version','não instalada')).'</p>';
            if(get_option('ederp_schema_error')) { echo '<p>'.esc_html((string)get_option('ederp_schema_error')).'</p>'; }
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ederp_migrate">';
            wp_nonce_field('ederp_migrate'); submit_button('Verificar/aplicar migrações'); echo '</form>';
        }
        echo '</div>';
    }
    private function form(string $title,string $route,array $fields): void
    {
        echo '<section class="erp-finance-action"><h3 hidden>'.esc_html($title).'</h3><form data-ederp-form="'.esc_attr($route).'" class="ederp-form">';
        foreach($fields as $spec=>$label) {
            [$key,$type]=array_pad(explode(':',$spec,2),2,'text'); $optional=str_ends_with($key,'?'); $key=rtrim($key,'?');
            echo '<label>'.esc_html($label);
            if($type==='textarea') { echo '<textarea name="'.esc_attr($key).'" rows="5"'.($optional?'':' required').'></textarea>'; }
            else { echo '<input name="'.esc_attr($key).'" type="'.esc_attr($type).'"'.($type==='number'?' min="1" step="1"':'').($optional?'':' required').'>'; }
            echo '</label>';
        }
        echo '<button class="button button-primary" type="submit">Confirmar</button><p role="status" class="ederp-status"></p></form></section>';
    }
}
