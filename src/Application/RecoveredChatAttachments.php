<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\RuleViolation;
/** Unlinked files are available only to a WordPress administrator for review. */
final class RecoveredChatAttachments
{
    public function __construct(private Database $db) {}
    private function authorize():void
    {
        Access::requireAdmin();
        if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))throw new RuleViolation('Conclua a atualização do banco antes de conferir os anexos preservados.');
    }
    public function count():int
    {
        $this->authorize();return (int)$this->db->row('SELECT COUNT(*) AS n FROM '.$this->db->table('chat_anexos').' WHERE idmensagem IS NULL AND recuperado_em IS NOT NULL')['n'];
    }
    public function listing(int $page=1):array
    {
        $this->authorize();$page=max(1,$page);$a=$this->db->table('chat_anexos');$c=$this->db->table('coligadas');
        $rows=$this->db->rows("SELECT a.idanexo,a.nome,a.mime,a.tamanho,a.codcoligada,c.nome AS coligada,a.idmensagem_original,a.recuperado_em,a.motivo_recuperacao FROM $a a LEFT JOIN $c c ON c.codcoligada=a.codcoligada WHERE a.idmensagem IS NULL AND a.recuperado_em IS NOT NULL ORDER BY a.idanexo LIMIT 51 OFFSET %d",[($page-1)*50]);
        return ['items'=>array_slice($rows,0,50),'more'=>count($rows)>50,'page'=>$page,'total'=>$this->count()];
    }
    public function file(int $id):array
    {
        $this->authorize();$row=$this->db->get('chat_anexos',$id);
        if($row['idmensagem']!==null||empty($row['recuperado_em']))throw new RuleViolation('Anexo fora da recuperação. Abra a conversa correspondente.');
        $bytes=base64_decode($row['conteudo_base64'],true);
        if($bytes===false)throw new RuleViolation('Não foi possível ler o conteúdo do anexo. O registro permanece preservado.');
        return ['idanexo'=>$id,'nome'=>$row['nome'],'bytes'=>$bytes];
    }
}
