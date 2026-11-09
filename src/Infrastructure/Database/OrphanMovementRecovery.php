<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
use EducacionalERP\Application\Coligadas;
/** Preserve the complete row before moving an orphan history out of the active relation. */
final class OrphanMovementRecovery
{
    public function __construct(private Database $db){}
    public function recover():int
    {
        $source=$this->db->table('matricula_movimentacoes');$parent=$this->db->table('matriculas');$archive=$this->db->table('registros_preservados');$total=0;
        foreach($this->db->schema() as $meta)foreach($meta['fks'] as $fk)if($fk['target']==='matricula_movimentacoes')throw new \RuntimeException('O histórico possui dependências e exige conferência antes da preservação.');
        do{
            $count=$this->db->atomic(function()use($source,$parent,$archive){
                $rows=$this->db->rows("SELECT h.* FROM $source h LEFT JOIN $parent m ON m.idmatricula=h.idmatricula WHERE m.idmatricula IS NULL ORDER BY h.idmovimentacao LIMIT 200 FOR UPDATE");
                foreach($rows as $row){
                    ksort($row);$json=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$json);$id=(string)$row['idmovimentacao'];
                    $saved=$this->db->row("SELECT * FROM $archive WHERE tabela_origem=%s AND registro_origem_id=%s AND hash_registro=%s FOR UPDATE",['matricula_movimentacoes',$id,$hash]);
                    if(!$saved){
                        $preserved=$this->db->insert('registros_preservados',['tabela_origem'=>'matricula_movimentacoes','registro_origem_id'=>$id,'idmatricula_origem'=>$row['idmatricula'],'codcoligada_origem'=>$row['codcoligada'],'dados_json'=>$json,'hash_registro'=>$hash,'motivo'=>'Matrícula não encontrada no banco restaurado.','tem_documento'=>empty($row['documento_token'])?0:1,'preservado_em'=>gmdate('Y-m-d H:i:s')]);
                        $saved=$this->db->get('registros_preservados',$preserved);
                        $company=$this->db->row('SELECT codcoligada FROM '.$this->db->table('coligadas').' ORDER BY ativo DESC,codcoligada LIMIT 1');
                        Coligadas::within((int)$company['codcoligada'],fn()=>$this->db->audit('registros_preservados',$preserved,'preservar_movimento_backup',null,['tabela_origem'=>'matricula_movimentacoes','registro_origem_id'=>$id,'idmatricula_origem'=>$row['idmatricula'],'codcoligada_origem'=>$row['codcoligada'],'hash_registro'=>$hash],'schema-42-movimentos'));
                    }
                    if($saved['dados_json']!==$json||!hash_equals($hash,hash('sha256',$saved['dados_json'])))throw new \RuntimeException('Não foi possível confirmar a cópia integral do histórico. O registro original foi mantido.');
                    if($this->db->query("DELETE FROM $source WHERE idmovimentacao=%s AND versao=%s",[$id,$row['versao']])!==1)throw new \RuntimeException('Histórico alterado durante a preservação. Repita a migração.');
                }
                return count($rows);
            });$total+=$count;
        }while($count===200);
        return $total;
    }
}
