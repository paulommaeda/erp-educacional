<?php
/** Shared fictitious schema/data for isolated WordPress migration tests. */
use EducacionalERP\Infrastructure\Database\{Database,SchemaIndexes};
function fixtureM(array $schema):Database{
    global $wpdb;
    $db=new Database($wpdb);
    foreach($schema as $name=>$meta){$lines=[];foreach($meta['columns'] as $col=>$def)$lines[]="$col $def";$lines[]='PRIMARY KEY ('.implode(',',$meta['pk']).')';$lines=array_merge($lines,SchemaIndexes::lines($meta,[]));$db->query('CREATE TABLE '.$db->table($name).' ('.implode(',',$lines).') ENGINE=InnoDB '.$wpdb->get_charset_collate());}
    foreach($schema as $name=>$meta)foreach($meta['fks'] as $i=>$fk){$table=$db->table($name);$constraint='erp_fk_'.substr(hash('sha256',$table.':'.$i),0,32);$db->query("ALTER TABLE $table ADD CONSTRAINT $constraint FOREIGN KEY (".implode(',',$fk['columns']).') REFERENCES '.$db->table($fk['target']).' ('.implode(',',$fk['references']).') ON DELETE RESTRICT ON UPDATE RESTRICT');}
    $fixtures=[
        'coligadas'=>[['codcoligada'=>1,'nome'=>'COLÉGIO FICTÍCIO','razao_social'=>'COLÉGIO FICTÍCIO']],
        'pessoas'=>[['codpessoa'=>1,'nome'=>'ALUNO DO BACKUP'],['codpessoa'=>2,'nome'=>'ASSINANTE DO BACKUP']],
        'alunos'=>[['idaluno'=>1,'codpessoa'=>1,'ra'=>'000001']],
        'periodos_letivos'=>[['codperiodo'=>1,'codigo'=>'2026','descricao'=>'Ano letivo','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']],
        'cursos'=>[['idcurso'=>1,'codigo'=>'EF','nome'=>'Fundamental']],
        'turnos'=>[['idturno'=>1,'codigo'=>'M','nome'=>'Manhã']],
        'planos_pagamento'=>[['idplano'=>1,'codperiodo'=>1,'codigo'=>'AN','nome'=>'Anuidade','valor_anuidade'=>'1000.00']],
        'turmas'=>[['idturma'=>1,'codperiodo'=>1,'idcurso'=>1,'idturno'=>1,'idplano'=>1,'codigo'=>'6A','nome'=>'6º ano','capacidade'=>30]],
        'matriculas'=>[['idmatricula'=>1,'idaluno'=>1,'codperiodo'=>1,'idcurso'=>1,'idturma_atual'=>1,'data_matricula'=>'2026-01-05','origem'=>'inicial','status'=>'cursando']],
        'contratos'=>[['idcontrato'=>1,'numero'=>'BACKUP-000001','idmatricula'=>1,'codpessoa_rf_original'=>2,'codpessoa_rf_atual'=>2,'idplano'=>1,'data_contrato'=>'2026-01-05','quantidade_parcelas'=>1,'versao_termo'=>'backup-1','termo_snapshot'=>'TERMO ORIGINAL DO BACKUP','valor_original_total'=>'1000.00','valor_liquido_total'=>'1000.00']],
        'parcelas'=>[['idparcela'=>1,'idcontrato'=>1,'numero'=>1,'competencia'=>'2026-01-01','vencimento_original'=>'2026-01-31','regra_desconto_condicional'=>'{}','regra_encargos'=>'{}','valor_original'=>'1000.00','valor_liquido'=>'1000.00']],
        'lancamentos'=>[['idlancamento'=>1,'idparcela'=>1,'codpessoa_rf_original'=>2,'codpessoa_rf_atual'=>2,'descricao'=>'PARCELA DO BACKUP','emissao'=>'2026-01-05','vencimento'=>'2026-01-31','valor_original'=>'1000.00','valor_liquido'=>'1000.00','valor_baixa'=>'100.00','saldo_aberto'=>'900.00','status'=>'parcial']],
        'baixas'=>[['idbaixa'=>1,'idlancamento'=>1,'codpessoa_devedor'=>2,'data_pagamento'=>'2026-01-10','registrado_em'=>'2026-01-10 12:00:00','forma_pagamento'=>'pix','valor_pago'=>'100.00','idempotencia'=>'backup-migration-payment','ator_wp_user_id'=>get_current_user_id()]],
        'chat_canais'=>[['idcanal'=>1,'nome'=>'Secretaria']],
        'chat_conversas'=>[['idconversa'=>1,'idcanal'=>1,'codpessoa'=>2,'idaluno'=>1,'ultima_mensagem_id'=>1]],
        'chat_mensagens'=>[['idmensagem'=>1,'idconversa'=>1,'autor_wp_user_id'=>get_current_user_id(),'autor_nome'=>'Responsável','origem'=>'responsavel','texto'=>'MENSAGEM VÁLIDA DO BACKUP']],
        'chat_anexos'=>[['idanexo'=>1,'idmensagem'=>1,'nome'=>'valido.txt','mime'=>'text/plain','tamanho'=>6,'conteudo_base64'=>base64_encode('backup')]],
    ];
    foreach($fixtures as $name=>$rows)foreach($rows as $row)if($wpdb->insert($db->table($name),$row)===false)throw new RuntimeException('Fixture inválida em '.$name.': '.$wpdb->last_error);
    return $db;
}
function snapshotM(Database $db,array $schema):array{
    $out=[];foreach($schema as $name=>$meta)$out[$name]=$db->rows('SELECT '.implode(',',array_keys($meta['columns'])).' FROM '.$db->table($name).' ORDER BY '.implode(',',$meta['pk']));return $out;
}
