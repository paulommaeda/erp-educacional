<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;

use EducacionalERP\Application\{Coligadas,EnrollmentSignatures,SchoolSettings};
use EducacionalERP\Domain\{Input,RuleViolation,Store};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\Pdf\EnrollmentSignaturesPdf;
use EducacionalERP\Infrastructure\WordPress\{MenuPolicy,SchemaMigration,SchoolIdentity};

final class Reports
{
    public static function assets():void
    {
        wp_enqueue_style('ederp-reports',plugins_url('assets/reports.css',EDERP_FILE),['journey-ui'],EDERP_VERSION);
        wp_enqueue_script('ederp-reports',plugins_url('assets/reports.js',EDERP_FILE),[],EDERP_VERSION,true);
    }

    public static function render(Store $db):void
    {
        echo '<section class="erp-reports"><h1>Relatórios</h1><section class="ederp-card"><h2>Lista de assinaturas</h2>';
        if(SchemaMigration::pending()){
            echo '<p role="status">Atualize o banco do ERP para gerar este relatório.</p></section></section>';
            return;
        }

        $settings=(new SchoolSettings($db))->read();
        $periods=array_column($settings['periodos'],null,'codperiodo');
        $period=isset($periods[$settings['codperiodo']])?$settings['codperiodo']:(int)(array_key_first($periods)??0);
        $service=new EnrollmentSignatures($db);
        $class=0;
        $report=null;
        $error='';
        try{
            if(array_key_exists('relatorio_periodo',$_GET)){
                $selected=Input::id($_GET['relatorio_periodo']);
                if(!isset($periods[$selected]))throw new RuleViolation('Selecione um período letivo da coligada atual.');
                $period=$selected;
            }elseif(isset($_GET['erp_periodo'])&&is_string($_GET['erp_periodo'])&&isset($periods[$_GET['erp_periodo']])){
                $period=(int)$_GET['erp_periodo'];
            }
            if(isset($_GET['relatorio_turma'])&&$_GET['relatorio_turma']!==''){
                $class=Input::id($_GET['relatorio_turma']);
                $report=$service->report($period,$class);
            }
        }catch(RuleViolation $e){
            $error=$e->getMessage();
        }

        if(!$periods){
            echo '<p>Nenhum período letivo cadastrado nesta coligada.</p></section></section>';
            return;
        }
        $url=Portal::url('relatorios');
        $classes=$service->classes($period);
        self::filters($url,$periods,$classes,$period,$class,$report!==null);
        if($error)echo '<p class="erp-feedback error" role="alert">'.esc_html($error).'</p>';
        if(!$classes)echo '<p>Nenhuma turma neste período letivo.</p>';
        echo '</section>';
        if($report)self::preview($report);
        echo '</section>';
    }

    private static function filters(string $url,array $periods,array $classes,int $period,int $class,bool $ready):void
    {
        wp_parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
        echo '<form method="get" action="'.esc_url(explode('?',$url,2)[0]).'" class="erp-report-filters" data-erp-report-filters>';
        foreach($query as $key=>$value){
            if(is_scalar($value))echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        }
        echo '<label>Período Letivo <select name="relatorio_periodo">';
        foreach($periods as $id=>$item){
            $label=$item['codigo']===$item['descricao']?$item['codigo']:$item['codigo'].' · '.$item['descricao'];
            echo '<option value="'.esc_attr((string)$id).'"'.selected($period,(int)$id,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label><label>Turma <select name="relatorio_turma"><option value="">Selecione a turma</option>';
        foreach($classes as $item){
            $label=$item['codigo']===$item['nome']?$item['nome']:$item['nome'].' · '.$item['codigo'];
            echo '<option value="'.esc_attr($item['idturma']).'"'.selected($class,(int)$item['idturma'],false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label><div class="erp-report-actions"><button type="submit">Ver na tela</button>';
        if($ready){
            $download=add_query_arg([
                'relatorio_periodo'=>$period,'relatorio_turma'=>$class,'relatorio_pdf'=>wp_create_nonce(self::nonce($period,$class)),
            ],$url);
            echo '<a class="erp-link-button" href="'.esc_url($download).'">Salvar em PDF</a>';
        }
        echo '</div></form>';
    }

    private static function preview(array $report):void
    {
        $school=SchoolIdentity::read();
        $logo=SchoolIdentity::logo();
        echo '<article class="erp-signature-sheet" aria-label="Prévia da lista de assinaturas"><div class="erp-signature-logo">';
        if($logo)echo '<img src="'.esc_url($logo).'" alt="'.esc_attr($school['nome']).'">';
        else echo '<strong>'.esc_html($school['nome']).'</strong>';
        echo '</div><dl class="erp-signature-details"><div><dt>Período Letivo</dt><dd>'.esc_html($report['periodo']).'</dd></div>';
        echo '<div><dt>Turma</dt><dd>'.esc_html($report['turma']).'</dd></div><div><dt>Data</dt><dd>____ / ____ / ________</dd></div></dl>';
        echo '<table class="erp-signature-table" aria-label="Alunos da turma"><colgroup><col><col><col></colgroup>';
        echo '<thead><tr><th scope="col">RA</th><th scope="col">Nome</th><th scope="col" aria-label="Assinatura"></th></tr></thead><tbody>';
        foreach($report['alunos'] as $student){
            echo '<tr><td>'.esc_html($student['ra']).'</td><td>'.esc_html($student['nome']).'</td><td><span class="erp-signature-line" aria-hidden="true"></span></td></tr>';
        }
        if(!$report['alunos'])echo '<tr><td colspan="3">Nenhum aluno nesta turma.</td></tr>';
        echo '</tbody></table></article>';
    }

    private static function nonce(int $period,int $class):string
    {
        return 'ederp_lista_assinaturas_'.Coligadas::current().'_'.$period.'_'.$class;
    }

    public static function download():void
    {
        $portal=(int)get_option('ederp_portal_page',0);
        if(!$portal||!is_page($portal)||($_GET['erp_tela']??null)!=='relatorios'||!isset($_GET['relatorio_pdf']))return;
        nocache_headers();
        if(!MenuPolicy::can('relatorios'))wp_die('Seu perfil não tem acesso a este relatório.','',['response'=>403]);
        if(SchemaMigration::pending())wp_die('Atualize o banco do ERP para gerar este relatório.','',['response'=>503]);
        try{
            $period=Input::id($_GET['relatorio_periodo']??null);
            $class=Input::id($_GET['relatorio_turma']??null);
            $nonce=$_GET['relatorio_pdf'];
            if(!is_string($nonce)||!wp_verify_nonce($nonce,self::nonce($period,$class))){
                wp_die('Abra novamente a lista no portal para salvar em PDF.','',['response'=>403]);
            }
            $report=(new EnrollmentSignatures(new Database($GLOBALS['wpdb'])))->report($period,$class);
            $school=SchoolIdentity::read();
            $school['logo_padrao']=get_option(SchoolIdentity::option(),false)===false;
            $pdf=EnrollmentSignaturesPdf::render($report,$school);
        }catch(RuleViolation $e){
            wp_die(esc_html($e->getMessage()),'',['response'=>422]);
        }
        $name='lista-assinaturas-'.sanitize_title($report['periodo_codigo'].'-'.$report['turma_codigo']).'.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }
}
