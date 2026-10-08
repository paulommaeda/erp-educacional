<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
final class SchoolSettings
{
    public function __construct(private Store $db) {}
    public static function renewalIntroduction():string { return (string)get_option(Coligadas::option('ederp_renewal_introduction'),'É uma alegria seguir com sua família em mais um período letivo. Confira a próxima turma e leia o termo com atenção antes de confirmar a rematrícula.'); }
    public static function renewalBlockMessage():string {  $message=(string)get_option(Coligadas::option('ederp_renewal_block_message'),RenewalService::BLOCK_MESSAGE);return $message===str_replace('parcelas vencidas','parcelas em aberto',RenewalService::BLOCK_MESSAGE)?RenewalService::BLOCK_MESSAGE:$message; }
    public static function manual():?array
    {
        $id=(int)get_option(Coligadas::option('ederp_student_manual'),0);if(!$id)return null;
        if(get_post_type($id)!=='attachment'||get_post_mime_type($id)!=='application/pdf')return null;
        $url=wp_get_attachment_url($id);$file=get_attached_file($id);if(!$url||!$file||!is_readable($file))return null;
        return ['id'=>$id,'url'=>$url,'versao'=>hash_file('sha256',$file)];
    }
    public static function current():int { return (int)get_option(Coligadas::option('ederp_current_period'),0); }
    public static function resolve(mixed $value):int { return $value===null||$value===''?self::current():($value==='todos'?0:Input::id($value)); }
    public static function canChoose(string $scope='academic'):bool
    {
        return Access::isAdmin() || ($scope==='finance' ? (current_user_can('erp_consultar_financeiro') && \EducacionalERP\Infrastructure\WordPress\MenuPolicy::can('financeiro')) : (current_user_can('erp_gerenciar_academico') && \EducacionalERP\Infrastructure\WordPress\MenuPolicy::any(['academico','professores','matriculas','alunos','rematriculas'])));
    }
    public static function forViewer(mixed $value,string $scope='academic'):int
    {
        if(self::canChoose($scope))return self::resolve($value);
        $period=self::current();if(!$period)throw new RuleViolation('A escola ainda não configurou o período letivo vigente.');return $period;
    }
    public function read():array
    {
        $periods=$this->db->rows('SELECT codperiodo,codigo,descricao,status FROM '.$this->db->table('periodos_letivos').' WHERE codcoligada=%d ORDER BY data_inicio DESC,codperiodo DESC',[Coligadas::current()]);
        if(!self::canChoose()&&!self::canChoose('finance'))$periods=array_values(array_filter($periods,fn($p)=>(int)$p['codperiodo']===self::current()));
        return ['codperiodo'=>self::current(),'periodos'=>$periods,'texto_apresentacao_rematricula'=>self::renewalIntroduction(),'texto_bloqueio_rematricula'=>self::renewalBlockMessage(),'manual_aluno'=>self::manual()];
    }
    public function save(array $data,string $key):array
    {
        Access::requireAdmin();$id=Input::id($data['codperiodo']??null);
        // Shared lock with period deletion; a configured period cannot disappear concurrently.
        $name='ederp_period_'.substr(hash('sha256',$this->db->table('periodos_letivos')),0,32);
        if((int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$name])['acquired']??0)!==1)throw new RuleViolation('Outra configuração está sendo salva. Tente novamente.');
        try {
            $period=$this->db->get('periodos_letivos',$id);if((int)$period['codcoligada']!==Coligadas::current())throw new RuleViolation('Selecione um período da coligada atual.');
            $introduction=Input::text($data['texto_apresentacao_rematricula']??self::renewalIntroduction(),20000);
            $blockMessage=Input::text($data['texto_bloqueio_rematricula']??self::renewalBlockMessage(),20000);
            $manualId=filter_var($data['manual_aluno_id']??get_option(Coligadas::option('ederp_student_manual'),0),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if($manualId===false||($manualId&&(get_post_type($manualId)!=='attachment'||get_post_mime_type($manualId)!=='application/pdf'||!is_readable((string)get_attached_file($manualId)))))throw new RuleViolation('Selecione um PDF válido para o Manual do Aluno.');
            $oldManual=(int)get_option(Coligadas::option('ederp_student_manual'),0);update_option(Coligadas::option('ederp_student_manual'),$manualId,false);
            $oldBlockMessage=self::renewalBlockMessage();update_option(Coligadas::option('ederp_renewal_block_message'),$blockMessage,false);
            $before=self::current();$oldIntroduction=self::renewalIntroduction();update_option(Coligadas::option('ederp_renewal_introduction'),$introduction,false);update_option(Coligadas::option('ederp_current_period'),$id,false);
            $this->db->audit('configuracoes',0,'periodo_atual',['codperiodo'=>$before,'texto_apresentacao_rematricula'=>$oldIntroduction,'texto_bloqueio_rematricula'=>$oldBlockMessage,'manual_aluno_id'=>$oldManual],['codperiodo'=>$id,'texto_apresentacao_rematricula'=>$introduction,'texto_bloqueio_rematricula'=>$blockMessage,'manual_aluno_id'=>$manualId],$key);
            return $this->read();
        } finally { $this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$name]); }
    }
}
