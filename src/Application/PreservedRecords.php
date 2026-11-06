<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,PrivateDocuments};
use EducacionalERP\Domain\RuleViolation;
/** Complete backup records are reviewable by administrators, even while another relation is pending. */
final class PreservedRecords
{
    public function __construct(private Database $db){}
    private function available():bool
    {
        if((int)get_option('ederp_schema_version',0)>=42)return true;
        return (bool)$this->db->row('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',[$this->db->table('registros_preservados')]);
    }
    public function count():int
    {
        Access::requireAdmin();if(!$this->available())return 0;
        return (int)$this->db->row('SELECT COUNT(*) AS n FROM '.$this->db->table('registros_preservados'))['n'];
    }
    public function listing(int $page=1):array
    {
        Access::requireAdmin();$page=max(1,min(100000,$page));if(!$this->available())return ['items'=>[],'page'=>$page,'more'=>false,'total'=>0];
        $rows=$this->db->rows('SELECT idpreservado,tabela_origem,registro_origem_id,idmatricula_origem,codcoligada_origem,motivo,tem_documento,preservado_em FROM '.$this->db->table('registros_preservados').' ORDER BY idpreservado DESC LIMIT 51 OFFSET %d',[($page-1)*50]);
        return ['items'=>array_slice($rows,0,50),'page'=>$page,'more'=>count($rows)>50,'total'=>$this->count()];
    }
    public function file(int $id,string $type='registro'):array
    {
        Access::requireAdmin();if(!$this->available())throw new RuleViolation('Nenhum registro preservado disponível.');
        $row=$this->db->get('registros_preservados',$id);if(!hash_equals($row['hash_registro'],hash('sha256',$row['dados_json'])))throw new RuleViolation('A cópia preservada precisa de conferência de integridade.');
        $data=json_decode($row['dados_json'],true,512,JSON_THROW_ON_ERROR);
        if($type==='documento'){
            if(empty($data['documento_token']))throw new RuleViolation('Este registro não possui documento de origem.');
            $document=(new PrivateDocuments())->read((string)$data['documento_token']);$bytes=base64_decode($document['data'],true);
            if($bytes===false)throw new RuleViolation('O documento não pôde ser lido. Sua referência permanece preservada.');
            return ['nome'=>$document['name'],'bytes'=>$bytes];
        }
        if($type!=='registro')throw new RuleViolation('Tipo de arquivo inválido.');
        return ['nome'=>$row['tabela_origem'].'-'.$row['registro_origem_id'].'.json','bytes'=>json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
    }
}
