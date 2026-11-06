<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Database;
/** Retain restored files with invalid references as explicitly unlinked records. */
final class ChatAttachmentRecovery
{
    public function __construct(private Database $db) {}
    public function prepare():void
    {
        $table=$this->db->table('chat_anexos');
        // dbDelta does not reconcile a nullability-only change of an existing column.
        foreach($this->db->rows("SHOW COLUMNS FROM $table") as $column){
            if($column['Field']==='idmensagem'&&strtoupper($column['Null'])==='NO'){
                $this->db->query("ALTER TABLE $table MODIFY COLUMN idmensagem bigint(20) unsigned NULL");
                break;
            }
        }
    }
    public function recover():int
    {
        $files=$this->db->table('chat_anexos');$messages=$this->db->table('chat_mensagens');$total=0;
        do{
            $count=$this->db->atomic(function()use($files,$messages){
                $rows=$this->db->rows("SELECT a.idanexo,a.idmensagem,a.codcoligada,a.versao,m.idmensagem AS mensagem_encontrada FROM $files a LEFT JOIN $messages m ON m.idmensagem=a.idmensagem WHERE a.idmensagem IS NOT NULL AND (m.idmensagem IS NULL OR m.codcoligada<>a.codcoligada) ORDER BY a.idanexo LIMIT 200 FOR UPDATE");
                foreach($rows as $row){
                    $id=(int)$row['idanexo'];$reason=$row['mensagem_encontrada']===null?'mensagem_ausente':'coligada_divergente';$now=gmdate('Y-m-d H:i:s');
                    $changed=$this->db->query("UPDATE $files SET idmensagem_original=COALESCE(idmensagem_original,idmensagem),idmensagem=NULL,recuperado_em=COALESCE(recuperado_em,%s),motivo_recuperacao=%s,atualizado_em=%s,versao=versao+1 WHERE idanexo=%d AND idmensagem=%d AND versao=%d",[$now,$reason,$now,$id,(int)$row['idmensagem'],(int)$row['versao']]);
                    if($changed!==1)throw new \RuntimeException('Anexo alterado durante a recuperação. Repita a migração.');
                    $before=['idmensagem'=>(int)$row['idmensagem'],'codcoligada'=>(int)$row['codcoligada']];
                    $this->db->audit('chat_anexos',$id,'preservar_anexo_backup',$before,['idmensagem'=>null,'idmensagem_original'=>(int)$row['idmensagem'],'codcoligada'=>(int)$row['codcoligada'],'motivo_recuperacao'=>$reason],'schema-40-chat-anexos');
                }
                return count($rows);
            });
            $total+=$count;
        }while($count===200);
        return $total;
    }
}
