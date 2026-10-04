<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
final class SchoolSettings
{
    public function __construct(private Store $db) {}
    public static function renewalIntroduction():string { return (string)get_option('ederp_renewal_introduction','É uma alegria seguir com sua família em mais um período letivo. Confira a próxima turma e leia o termo com atenção antes de confirmar a rematrícula.'); }
    public static function renewalBlockMessage():string { return (string)get_option('ederp_renewal_block_message',RenewalService::BLOCK_MESSAGE); }
    public static function current():int { return (int)get_option('ederp_current_period',0); }
    public static function resolve(mixed $value):int { return $value===null||$value===''?self::current():($value==='todos'?0:Input::id($value)); }
    public static function canChoose(string $scope='academic'):bool
    {
        return Access::isAdmin() || ($scope==='finance' ? (current_user_can('erp_consultar_financeiro') && \EducacionalERP\Infrastructure\WordPress\MenuPolicy::can('financeiro')) : (current_user_can('erp_gerenciar_academico') && \EducacionalERP\Infrastructure\WordPress\MenuPolicy::any(['academico','matriculas','alunos','rematriculas'])));
    }
    public static function forViewer(mixed $value,string $scope='academic'):int
    {
        if(self::canChoose($scope))return self::resolve($value);
        $period=self::current();if(!$period)throw new RuleViolation('A escola ainda não configurou o período letivo vigente.');return $period;
    }
    public function read():array
    {
        $periods=$this->db->rows('SELECT codperiodo,codigo,descricao,status FROM '.$this->db->table('periodos_letivos').' ORDER BY data_inicio DESC,codperiodo DESC');
        if(!self::canChoose()&&!self::canChoose('finance'))$periods=array_values(array_filter($periods,fn($p)=>(int)$p['codperiodo']===self::current()));
        return ['codperiodo'=>self::current(),'periodos'=>$periods,'texto_apresentacao_rematricula'=>self::renewalIntroduction(),'texto_bloqueio_rematricula'=>self::renewalBlockMessage()];
    }
    public function save(array $data,string $key):array
    {
        Access::requireAdmin();$id=Input::id($data['codperiodo']??null);
        // Shared lock with period deletion; a configured period cannot disappear concurrently.
        $name='ederp_period_'.substr(hash('sha256',$this->db->table('periodos_letivos')),0,32);
        if((int)($this->db->row('SELECT GET_LOCK(%s,5) AS acquired',[$name])['acquired']??0)!==1)throw new RuleViolation('Outra configuração está sendo salva. Tente novamente.');
        try {
            $this->db->get('periodos_letivos',$id);
            $introduction=Input::text($data['texto_apresentacao_rematricula']??self::renewalIntroduction(),20000);
            $blockMessage=Input::text($data['texto_bloqueio_rematricula']??self::renewalBlockMessage(),20000);
            $oldBlockMessage=self::renewalBlockMessage();update_option('ederp_renewal_block_message',$blockMessage,false);
            $before=self::current();$oldIntroduction=self::renewalIntroduction();update_option('ederp_renewal_introduction',$introduction,false);update_option('ederp_current_period',$id,false);
            $this->db->audit('configuracoes',0,'periodo_atual',['codperiodo'=>$before,'texto_apresentacao_rematricula'=>$oldIntroduction,'texto_bloqueio_rematricula'=>$oldBlockMessage],['codperiodo'=>$id,'texto_apresentacao_rematricula'=>$introduction,'texto_bloqueio_rematricula'=>$blockMessage],$key);
            return $this->read();
        } finally { $this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$name]); }
    }
}
