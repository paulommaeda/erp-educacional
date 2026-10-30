<?php
require __DIR__.'/payment-plans.php';
use EducacionalERP\Application\{EducbankInvoiceStatus,Coligadas};
use EducacionalERP\Infrastructure\WordPress\Accounts;
// Reading these states must not decrypt a token or access the partner.
$GLOBALS['eb_status_http']=0;function wp_remote_request($url,$args){$GLOBALS['eb_status_http']++;throw new RuntimeException('Unexpected HTTP');}
wp_set_current_user(1);$rows=$db->rows('SELECT l.*,c.idmatricula FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$db->table('contratos').' c ON c.idcontrato=p.idcontrato ORDER BY l.idlancamento');testP(count($rows)>=8,'fixture contém parcelas para todos os estados da integração');
update_option('ederp_educbank',['ativo'=>1,'ambiente'=>'sandbox','sandbox'=>['tenant'=>'colegio','token'=>'not-a-token'],'producao'=>['tenant'=>'colegio','token'=>'not-a-token']]);
$states=[0=>['sincronizado',true],1=>['pendente',true],2=>['erro',true],4=>['sincronizado',false],5=>['ignorado',false],6=>['revisao',true]];
foreach($states as $i=>[$status,$hasId])$db->insert('educbank_fila',['codcoligada'=>$rows[$i]['codcoligada'],'ambiente'=>'sandbox','tenant'=>'colegio','tipo'=>'fatura','idlocal'=>$rows[$i]['idlancamento'],'idlancamento'=>$rows[$i]['idlancamento'],'idmatricula'=>$rows[$i]['idmatricula'],'external_id'=>$rows[$i]['idlancamento'],'idremoto'=>$hasId?sprintf('00000000-0000-0000-0000-%012d',$i+1):null,'status'=>$status]);
$base=['codcoligada'=>$rows[7]['codcoligada'],'tipo'=>'fatura','idlocal'=>$rows[7]['idlancamento'],'idlancamento'=>$rows[7]['idlancamento'],'idmatricula'=>$rows[7]['idmatricula'],'external_id'=>$rows[7]['idlancamento'],'idremoto'=>'00000000-0000-0000-0000-000000000099','status'=>'sincronizado'];
$db->insert('educbank_fila',$base+['ambiente'=>'producao','tenant'=>'colegio']);$db->insert('educbank_fila',$base+['ambiente'=>'sandbox','tenant'=>'outro-tenant']);
$decorator=new EducbankInvoiceStatus($db);$out=$decorator->decorate($rows);
testP($out[0]['educbank']['integrado']===true,'Fatura com UUID e sincronização confirmada está integrada');
foreach([1,2,3,4,5,6,7] as $i)testP($out[$i]['educbank']['integrado']===false,'Pendente, erro, ausente, incompleta ou outro ambiente não recebe confirmação: '.$i);
testP($out[3]['educbank']['situacao']==='nao_enfileirado'&&$out[7]['educbank']['ambiente']==='sandbox','Ausência na fila e isolamento de tenant/ambiente aparecem corretamente');
foreach($rows as $i=>$r)testP(array_diff_assoc($r,$out[$i])===[],'Indicador não altera valores ou situação financeira: '.$i);
update_option('ederp_educbank',['ativo'=>0,'ambiente'=>'producao','sandbox'=>['tenant'=>'colegio','token'=>''],'producao'=>['tenant'=>'colegio','token'=>'']]);$production=$decorator->decorate([$rows[7]]);testP($production[0]['educbank']['integrado']===true,'Produção usa seu próprio vínculo e conserva confirmação ao pausar envios');testP(str_contains($production[0]['educbank']['mensagem'],'desativados'),'Pausa de envios é informada sem apagar o histórico');
update_option('ederp_educbank',['ativo'=>1,'ambiente'=>'sandbox','sandbox'=>['tenant'=>'colegio','token'=>''],'producao'=>['tenant'=>'colegio','token'=>'']]);
$class=new ReflectionClass(EducacionalERP\Presentation\Rest\Controller::class);$controller=$class->newInstanceWithoutConstructor();$class->getProperty('db')->setValue($controller,$db);$class->getProperty('access')->setValue($controller,new EducacionalERP\Infrastructure\WordPress\Access($db));
$student=(int)$db->get('matriculas',(int)$rows[0]['idmatricula'])['idaluno'];$managed=$controller->financeView($student);testP($managed!==[]&&isset($managed[0]['educbank']['integrado']),'Endpoint financeiro retorna indicador para a gestão');
$account=(new Accounts($db))->summary((int)$rows[0]['codpessoa_rf_atual']);wp_set_current_user((int)$account['wp_user_id']);$family=$controller->financeView($student);testP($family!==[]&&!array_filter($family,fn($r)=>isset($r['educbank'])),'Portal do responsável mantém apenas os dados financeiros, sem aviso técnico de integração');
wp_set_current_user(1);testP($decorator->decorate([])===[]&&$GLOBALS['eb_status_http']===0,'Consulta vazia funciona e nenhuma leitura dispara envios');
echo "EDUCBANK_STATUS_OK\n";
