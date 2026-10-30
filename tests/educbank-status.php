<?php
require __DIR__.'/payment-plans.php';
use EducacionalERP\Application\{EducbankInvoiceStatus,Coligadas};
use EducacionalERP\Infrastructure\WordPress\Accounts;
// Reading these states must not decrypt a token or access the partner.
$GLOBALS['eb_status_http']=0;function wp_remote_request($url,$args){$GLOBALS['eb_status_http']++;throw new RuntimeException('Unexpected HTTP');}
wp_set_current_user(1);$rows=$db->rows('SELECT l.*,c.idmatricula FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela JOIN '.$db->table('contratos').' c ON c.idcontrato=p.idcontrato ORDER BY l.idlancamento');testP(count($rows)>=8,'fixture contém parcelas para todos os estados da integração');
update_option('ederp_educbank',['ativo'=>1,'ambiente'=>'sandbox','sandbox'=>['tenant'=>'colegio','token'=>'not-a-token'],'producao'=>['tenant'=>'colegio','token'=>'not-a-token']]);
$states=[0=>['sincronizado',true],1=>['pendente',true],2=>['erro',true],4=>['sincronizado',false],5=>['ignorado',false],6=>['revisao',true]];
foreach($states as $i=>[$status,$hasId])$db->insert('educbank_fila',['codcoligada'=>$rows[$i]['codcoligada'],'ambiente'=>'sandbox','tenant'=>'colegio','tipo'=>'fatura','idlocal'=>$rows[$i]['idlancamento'],'idlancamento'=>$rows[$i]['idlancamento'],'idmatricula'=>$rows[$i]['idmatricula'],'external_id'=>$rows[$i]['idlancamento'],'idremoto'=>$hasId?sprintf('00000000-0000-0000-0000-%012d',$i+1):null,'status'=>$status,'url_fatura'=>$i===2?'javascript:alert(1)':'https://colegio.sandbox.educbank.com.br/invoice/'.($i+1)]);
$base=['codcoligada'=>$rows[7]['codcoligada'],'tipo'=>'fatura','idlocal'=>$rows[7]['idlancamento'],'idlancamento'=>$rows[7]['idlancamento'],'idmatricula'=>$rows[7]['idmatricula'],'external_id'=>$rows[7]['idlancamento'],'idremoto'=>'00000000-0000-0000-0000-000000000099','status'=>'sincronizado','url_fatura'=>'https://colegio.educbank.com.br/invoice/producao'];
$db->insert('educbank_fila',$base+['ambiente'=>'producao','tenant'=>'colegio']);$db->insert('educbank_fila',$base+['ambiente'=>'sandbox','tenant'=>'outro-tenant']);
$decorator=new EducbankInvoiceStatus($db);$out=$decorator->decorate($rows);
testP($out[0]['educbank']['integrado']===true,'Fatura com UUID e sincronização confirmada está integrada');
foreach([1,2,3,4,5,6,7] as $i)testP($out[$i]['educbank']['integrado']===false,'Pendente, erro, ausente, incompleta ou outro ambiente não recebe confirmação: '.$i);
testP($out[3]['educbank']['situacao']==='nao_enfileirado'&&$out[7]['educbank']['ambiente']==='sandbox','Ausência na fila e isolamento de tenant/ambiente aparecem corretamente');
foreach($rows as $i=>$r)testP(array_diff_assoc($r,$out[$i])===[],'Indicador não altera valores ou situação financeira: '.$i);
$expectedUrl='https://colegio.sandbox.educbank.com.br/invoice/1';
testP($out[0]['url_fatura']===$expectedUrl&&$out[1]['url_fatura']!==null,'Gestão recebe o mesmo link salvo, inclusive para conferência de atualização pendente');
testP($out[2]['url_fatura']===null&&$out[4]['url_fatura']===null&&$out[7]['url_fatura']===null,'URLs inseguras, faturas sem UUID e vínculos de outro ambiente não são expostos');
$payable=$rows[0];$payable['status']='em_aberto';$familyLink=$decorator->decorate([$payable],false)[0];
testP($familyLink['url_fatura']===$expectedUrl&&!isset($familyLink['educbank']),'Responsável recebe apenas o link confirmado, sem diagnóstico ou identificadores remotos');
$pending=$rows[1];$pending['status']='em_aberto';testP($decorator->decorate([$pending],false)[0]['url_fatura']===null,'Atualização pendente aguarda confirmação antes de liberar pagamento ao responsável');
foreach(['baixado','quitado','cancelado'] as $state){$closed=$payable;$closed['status']=$state;testP($decorator->decorate([$closed],false)[0]['url_fatura']===null,'Título encerrado não oferece novo pagamento: '.$state);}
$firstJob=$db->row('SELECT idintegracao FROM '.$db->table('educbank_fila').' WHERE idlancamento=%d AND ambiente=%s AND tenant=%s',[$rows[0]['idlancamento'],'sandbox','colegio']);
foreach(['Paid','Canceled'] as $state){$db->update('educbank_fila',(int)$firstJob['idintegracao'],['status_remoto'=>$state]);testP($decorator->decorate([$payable],false)[0]['url_fatura']===null,'Situação encerrada no Educbank impede novo pagamento: '.$state);}
$db->update('educbank_fila',(int)$firstJob['idintegracao'],['status_remoto'=>null]);
foreach(['http://example.test/invoice','https://user:pass@example.test/invoice',"https://example.test/\ninvoice",'https://','//example.test/invoice'] as $bad){$db->update('educbank_fila',(int)$firstJob['idintegracao'],['url_fatura'=>$bad]);testP($decorator->decorate([$payable])[0]['url_fatura']===null,'Link inválido ou com credenciais é omitido');}
$db->update('educbank_fila',(int)$firstJob['idintegracao'],['url_fatura'=>$expectedUrl]);
$company=$db->insert('coligadas',['nome'=>'OUTRA ESCOLA DE TESTE','razao_social'=>'OUTRA ESCOLA DE TESTE','cnpj'=>'11444777000161']);$otherScope=$payable;$otherScope['codcoligada']=$company;
Coligadas::within($company,fn()=>update_option(Coligadas::option('ederp_educbank'),['ativo'=>1,'ambiente'=>'sandbox','sandbox'=>['tenant'=>'colegio','token'=>''],'producao'=>['tenant'=>'colegio','token'=>'']]));
testP($decorator->decorate([$otherScope],false)[0]['url_fatura']===null,'Mesmo tenant em outra coligada não reaproveita o link de pagamento');
update_option('ederp_educbank',['ativo'=>0,'ambiente'=>'producao','sandbox'=>['tenant'=>'colegio','token'=>''],'producao'=>['tenant'=>'colegio','token'=>'']]);$production=$decorator->decorate([$rows[7]]);testP($production[0]['educbank']['integrado']===true,'Produção usa seu próprio vínculo e conserva confirmação ao pausar envios');testP($production[0]['url_fatura']==='https://colegio.educbank.com.br/invoice/producao','Link de produção usa o ambiente selecionado e continua disponível na gestão pausada');testP(str_contains($production[0]['educbank']['mensagem'],'desativados'),'Pausa de envios é informada sem apagar o histórico');
update_option('ederp_educbank',['ativo'=>1,'ambiente'=>'sandbox','sandbox'=>['tenant'=>'colegio','token'=>''],'producao'=>['tenant'=>'colegio','token'=>'']]);
$class=new ReflectionClass(EducacionalERP\Presentation\Rest\Controller::class);$controller=$class->newInstanceWithoutConstructor();$class->getProperty('db')->setValue($controller,$db);$class->getProperty('access')->setValue($controller,new EducacionalERP\Infrastructure\WordPress\Access($db));
$student=(int)$db->get('matriculas',(int)$rows[0]['idmatricula'])['idaluno'];$managed=$controller->financeView($student);testP($managed!==[]&&isset($managed[0]['educbank']['integrado']),'Endpoint financeiro retorna indicador para a gestão');
$account=(new Accounts($db))->summary((int)$rows[0]['codpessoa_rf_atual']);wp_set_current_user((int)$account['wp_user_id']);$family=$controller->financeView($student);testP($family!==[]&&!array_filter($family,fn($r)=>isset($r['educbank'])),'Portal do responsável mantém apenas os dados financeiros, sem aviso técnico de integração');
testP(!array_filter($family,fn($r)=>!array_key_exists('url_fatura',$r)),'Endpoint do responsável inclui o campo de pagamento sem expor a fila');$otherAccount=(new Accounts($db))->summary((int)$rows[0]['codpessoa_rf_atual']===$rf?$rf2:$rf);wp_set_current_user((int)$otherAccount['wp_user_id']);$otherFamily=$controller->financeView($student);testP(!array_filter($otherFamily,fn($r)=>(int)$r['idlancamento']===(int)$rows[0]['idlancamento']),'Outro responsável não recebe os lançamentos nem os links deste pagador');
wp_set_current_user(1);testP($decorator->decorate([])===[]&&$GLOBALS['eb_status_http']===0,'Consulta vazia funciona e nenhuma leitura dispara envios');
echo "EDUCBANK_STATUS_OK\n";
