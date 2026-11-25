<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;

use EducacionalERP\Application\{Coligadas,EnrollmentReports,SchoolSettings,StudentRoster};
use EducacionalERP\Domain\{Input,RuleViolation,Store};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\Pdf\{EnrollmentSignaturesPdf,StudentRosterPdf};
use EducacionalERP\Infrastructure\WordPress\{ReportPolicy,SchemaMigration,SchoolIdentity};

final class Reports
{
    private const ALL_CLASSES='todas';

    public static function assets():void
    {
        wp_enqueue_style('ederp-reports',plugins_url('assets/reports.css',EDERP_FILE),['journey-ui'],EDERP_VERSION);
        wp_enqueue_script('ederp-reports',plugins_url('assets/reports.js',EDERP_FILE),[],EDERP_VERSION,true);
    }

    public static function render(Store $db):void
    {
        $available=ReportPolicy::available();
        $report=$_GET['relatorio']??array_key_first($available);
        $url=Portal::url('relatorios');
        echo '<section class="erp-reports"><h1>Relatórios</h1><nav class="erp-report-menu" aria-label="Relatórios disponíveis">';
        foreach($available as $key=>$title){
            echo '<a href="'.esc_url(add_query_arg('relatorio',$key,$url)).'"'.($report===$key?' aria-current="page"':'').'>'.esc_html($title).'</a>';
        }
        echo '</nav>';
        if(!is_string($report)||!isset($available[$report])){
            echo '<p role="alert">Seu perfil não tem acesso a este relatório.</p></section>';
            return;
        }
        echo '<section class="ederp-card"><h2>'.esc_html($available[$report]).'</h2>';
        if(SchemaMigration::pending()){
            echo '<p role="status">Atualize o banco do ERP para gerar este relatório.</p></section></section>';
            return;
        }

        $service=new EnrollmentReports($db);
        $periods=$service->periods();
        if(!$periods){
            echo '<p>Nenhum período letivo cadastrado nesta coligada.</p></section></section>';
            return;
        }
        $current=SchoolSettings::current();
        $period=isset($periods[$current])?$current:(int)array_key_first($periods);
        $classValue='';
        $statuses=[];
        $preview=null;
        $error='';
        $classes=$service->classes($period);
        try{
            $requestedPeriod=$_GET['relatorio_periodo']??$_GET['erp_periodo']??null;
            if($requestedPeriod!==null&&$requestedPeriod!=='todos'){
                $period=self::period($periods,$requestedPeriod);
                $classes=$service->classes($period);
            }
            if($report==='alunos')$statuses=StudentRoster::statuses($_GET['relatorio_situacao']??[]);
            $requestedClass=$_GET['relatorio_turma']??'';
            if($requestedClass!==''){
                $class=self::classId($classes,$requestedClass);
                $classValue=$class===null?self::ALL_CLASSES:(string)$class;
                $preview=self::rows($service,$report,$periods[$period],$classes,$class,$statuses);
            }
        }catch(RuleViolation $e){
            $error=$e->getMessage();
        }

        self::filters(add_query_arg('relatorio',$report,$url),$report,$periods,$classes,$period,$classValue,$statuses,$preview!==null);
        if($error)echo '<p class="erp-feedback error" role="alert">'.esc_html($error).'</p>';
        if(!$classes)echo '<p>Nenhuma turma neste período letivo.</p>';
        echo '</section>';
        if($preview!==null){
            $logo=self::logo();
            if($report==='alunos'){
                self::studentPreview($preview,$logo);
            }else{
                foreach($preview as $sheet)self::signaturePreview($sheet,$logo);
            }
        }
        echo '</section>';
    }

    private static function filters(string $url,string $report,array $periods,array $classes,int $period,string $class,array $statuses,bool $ready):void
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
        echo '<option value="'.self::ALL_CLASSES.'"'.selected($class,self::ALL_CLASSES,false).'>Todas</option>';
        foreach($classes as $id=>$item){
            $label=$item['codigo']===$item['nome']?$item['nome']:$item['nome'].' · '.$item['codigo'];
            echo '<option value="'.esc_attr((string)$id).'"'.selected($class,(string)$id,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label>';
        if($report==='alunos')self::statusFilter($statuses);

        echo '<div class="erp-report-actions"><button type="submit">Ver na tela</button>';
        if($ready){
            $params=[
                'relatorio_periodo'=>$period,'relatorio_turma'=>$class,
                'relatorio_pdf'=>wp_create_nonce(self::nonce($report,$period,$class,$statuses)),
            ];
            if($statuses)$params['relatorio_situacao']=$statuses;
            echo '<a class="erp-link-button" data-erp-report-download href="'.esc_url(add_query_arg($params,$url)).'">Salvar em PDF</a>';
        }
        echo '</div></form>';
    }

    private static function statusFilter(array $statuses):void
    {
        echo '<details class="erp-report-status"><summary>Situação de matrícula <span data-erp-report-status-count>'.esc_html($statuses?(string)count($statuses).' selecionada(s)':'Todas').'</span></summary><fieldset aria-label="Situação de matrícula">';
        foreach(StudentRoster::STATUSES as $key=>$label){
            echo '<label class="ederp-check"><input type="checkbox" name="relatorio_situacao[]" value="'.esc_attr($key).'"'.checked(in_array($key,$statuses,true),true,false).'><span>'.esc_html($label).'</span></label>';
        }
        echo '</fieldset></details>';
    }

    private static function logo():string
    {
        $school=SchoolIdentity::read();
        $logo=SchoolIdentity::logo();
        $identity=$logo?'<img src="'.esc_url($logo).'" alt="'.esc_attr($school['nome']).'">':'<strong>'.esc_html($school['nome']).'</strong>';
        return '<div class="erp-signature-logo">'.$identity.'</div>';
    }

    private static function signaturePreview(array $report,string $logo):void
    {
        echo '<article class="erp-signature-sheet" aria-label="Prévia da lista de assinaturas">'.$logo;
        echo '<dl class="erp-signature-details"><div><dt>Período Letivo</dt><dd>'.esc_html($report['periodo']).'</dd></div>';
        echo '<div><dt>Turma</dt><dd>'.esc_html($report['turma']).'</dd></div><div><dt>Data</dt><dd>____ / ____ / ________</dd></div></dl>';
        echo '<table class="erp-signature-table" aria-label="Alunos da turma"><colgroup><col><col><col></colgroup>';
        echo '<thead><tr><th scope="col">RA</th><th scope="col">Nome</th><th scope="col" aria-label="Assinatura"></th></tr></thead><tbody>';
        foreach($report['alunos'] as $student){
            echo '<tr><td>'.esc_html($student['ra']).'</td><td>'.esc_html($student['nome']).'</td><td><span class="erp-signature-line" aria-hidden="true"></span></td></tr>';
        }
        if(!$report['alunos'])echo '<tr><td colspan="3">Nenhum aluno nesta turma.</td></tr>';
        echo '</tbody></table></article>';
    }

    private static function studentPreview(array $students,string $logo):void
    {
        echo '<article class="erp-signature-sheet erp-roster-sheet" aria-label="Prévia do relatório Alunos">'.$logo;
        echo '<div class="erp-roster-scroll" role="region" aria-label="Tabela de alunos" tabindex="0"><table class="erp-roster-table"><thead><tr>';
        foreach(StudentRoster::COLUMNS as $label)echo '<th scope="col">'.esc_html($label).'</th>';
        echo '</tr></thead>';
        foreach($students as $student){
            echo '<tbody>';
            $rows=count($student);
            foreach($student as $index=>$line){
                echo '<tr>';
                foreach($line as $column=>$cell){
                    if($column>=5||$index===0){
                        $span=$column<5&&$rows>1?' rowspan="'.$rows.'"':'';
                        echo '<td'.$span.'>'.esc_html($cell).'</td>';
                    }
                }
                echo '</tr>';
            }
            echo '</tbody>';
        }
        if(!$students)echo '<tbody><tr><td colspan="14">Nenhum aluno encontrado para os filtros selecionados.</td></tr></tbody>';
        echo '</table></div></article>';
    }

    private static function period(array $periods,mixed $value):int
    {
        $period=Input::id($value);
        if(!isset($periods[$period]))throw new RuleViolation('Selecione um período letivo da coligada atual.');
        return $period;
    }

    private static function classId(array $classes,mixed $value):?int
    {
        if($value===self::ALL_CLASSES)return null;
        $class=Input::id($value);
        if(!isset($classes[$class]))throw new RuleViolation('Selecione uma turma do período letivo e da coligada atuais.');
        return $class;
    }

    private static function rows(EnrollmentReports $service,string $report,array $period,array $classes,?int $class,array $statuses):array
    {
        return $report==='alunos'?$service->students((int)$period['codperiodo'],$class,$statuses):$service->signatures($period,$classes,$class);
    }

    private static function nonce(string $report,int $period,string $class,array $statuses):string
    {
        return 'ederp_relatorio_'.Coligadas::current().'_'.$report.'_'.$period.'_'.$class.'_'.implode(',',$statuses);
    }

    public static function download():void
    {
        $portal=(int)get_option('ederp_portal_page',0);
        if(!$portal||!is_page($portal)||($_GET['erp_tela']??null)!=='relatorios'||!isset($_GET['relatorio_pdf']))return;
        nocache_headers();
        $available=ReportPolicy::available();
        $report=$_GET['relatorio']??'assinaturas';
        if(!is_string($report)||!isset($available[$report]))wp_die('Seu perfil não tem acesso a este relatório.','',['response'=>403]);
        if(SchemaMigration::pending())wp_die('Atualize o banco do ERP para gerar este relatório.','',['response'=>503]);
        try{
            $period=Input::id($_GET['relatorio_periodo']??null);
            $requestedClass=$_GET['relatorio_turma']??null;
            $classValue=$requestedClass===self::ALL_CLASSES?self::ALL_CLASSES:(string)Input::id($requestedClass);
            $statuses=$report==='alunos'?StudentRoster::statuses($_GET['relatorio_situacao']??[]):[];
            $nonce=$_GET['relatorio_pdf'];
            if(!is_string($nonce)||!wp_verify_nonce($nonce,self::nonce($report,$period,$classValue,$statuses))){
                wp_die('Abra novamente o relatório no portal para salvar em PDF.','',['response'=>403]);
            }
            $service=new EnrollmentReports(new Database($GLOBALS['wpdb']));
            $periods=$service->periods();
            self::period($periods,$period);
            $classes=$service->classes($period);
            $class=self::classId($classes,$classValue);
            $rows=self::rows($service,$report,$periods[$period],$classes,$class,$statuses);
            $school=SchoolIdentity::read();
            $school['logo_padrao']=get_option(SchoolIdentity::option(),false)===false;
            $pdf=$report==='alunos'?StudentRosterPdf::render($rows,$school):EnrollmentSignaturesPdf::render($rows,$school);
        }catch(RuleViolation $e){
            wp_die(esc_html($e->getMessage()),'',['response'=>422]);
        }
        $kind=$report==='alunos'?'alunos':'lista-assinaturas';
        $classCode=$class===null?self::ALL_CLASSES:$classes[$class]['codigo'];
        $name=$kind.'-'.sanitize_title($periods[$period]['codigo'].'-'.$classCode).'.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }
}
