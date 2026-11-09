<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{StudentDocumentFiles,PrivateDocuments};
/** File removal starts only after commit; failed attempts remain durable. */
final class PermanentDeletionFiles
{
    public function __construct(private Database $db){}
    public function cleanup():void
    {
        $lock='ederp_purge_files_'.substr(hash('sha256',$this->db->wp->prefix),0,32);if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)return;
        try{$table=$this->db->table('exclusoes_definitivas');foreach($this->db->rows("SELECT idexclusao,arquivos_json FROM $table WHERE arquivos_json IS NOT NULL AND arquivos_json<>'[]' ORDER BY idexclusao LIMIT 20") as $receipt){$pending=[];foreach(json_decode($receipt['arquivos_json'],true,512,JSON_THROW_ON_ERROR) as $file){try{
            $token=$file['token'];if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new \RuntimeException('Token inválido.');
            if($file['tipo']==='aluno'){$shared=false;foreach(['aluno_documentos','requerimento_arquivos'] as $name)if($this->db->row('SELECT arquivo_token FROM '.$this->db->table($name).' WHERE arquivo_token=%s LIMIT 1',[$token]))$shared=true;if(!$shared&&!(new StudentDocumentFiles())->delete($token))$pending[]=$file;}
            elseif($file['tipo']==='privado'){$shared=$this->db->row('SELECT documento_token FROM '.$this->db->table('matricula_movimentacoes').' WHERE documento_token=%s LIMIT 1',[$token])||$this->db->row('SELECT idpreservado FROM '.$this->db->table('registros_preservados').' WHERE dados_json LIKE %s LIMIT 1',['%'.$token.'%']);if(!$shared&&!(new PrivateDocuments())->delete($token))$pending[]=$file;}
            else $pending[]=$file;
        }catch(\Throwable $ignored){$pending[]=$file;}}$this->db->query("UPDATE $table SET arquivos_json=%s WHERE idexclusao=%s AND arquivos_json=%s",[wp_json_encode($pending),$receipt['idexclusao'],$receipt['arquivos_json']]);}}
        finally{$this->db->row('SELECT RELEASE_LOCK(%s)',[$lock]);}
    }
}
