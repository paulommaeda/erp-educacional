<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Application\{Operations,CatalogService,AcademicService,StudentWorkflow,Contracts,ContractTemplates,Coligadas,FinancialDeletion,FinanceService,ExportService,IntegrationService,DataExport};
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\RuleViolation;

Access::install();$db=new Database($wpdb);$ops=new Operations($db);$catalog=new CatalogService($db,$ops);$academic=new AcademicService($db,$ops);$flow=new StudentWorkflow($db,$ops,$catalog,$academic);$contracts=new Contracts($db,$ops);$models=new ContractTemplates($db,$ops);$checks=0;
function ck():string{return bin2hex(random_bytes(16));}
function checkC(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function denyC(callable $fn,string $label):void{try{$fn();}catch(RuleViolation $e){checkC(true,$label);return;}throw new RuntimeException($label);}
function createC(string $type,array $data):int{global $catalog;return (int)$catalog->create($type,$data,ck())['id'];}
function fixtureC(string $suffix='',array $term=[]):array{
    global $db,$academic,$flow,$catalog;
    $period=createC('periodos_letivos',['codigo'=>'2027'.$suffix,'descricao'=>'Ano letivo','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31']);
    $course=createC('cursos',['codigo'=>'EF'.$suffix,'nome'=>'Fundamental']);$shift=createC('turnos',['codigo'=>'M'.$suffix,'nome'=>'Manhã']);$plan=createC('planos_pagamento',['codperiodo'=>$period,'codigo'=>'AN'.$suffix,'nome'=>'Plano anual','valor_anuidade'=>'12000.00']);
    $class=createC('turmas',['codigo'=>'6ANO'.$suffix,'nome'=>'6º ano','idcurso'=>$course,'idturno'=>$shift,'codperiodo'=>$period,'idplano'=>$plan,'capacidade'=>30]);
    $student=$flow->createStudent(['nome'=>'Aluno Contratos '.$suffix,'email'=>'aluno'.$suffix.'@example.test','data_nascimento'=>'2015-02-04'],ck());$guardian=createC('pessoas',['nome'=>'Maria Contratos '.$suffix,'email'=>'mae'.$suffix.'@example.test','data_nascimento'=>'1980-01-01']);
    $catalog->link((int)$student['idaluno'],['codpessoa_responsavel'=>$guardian,'parentesco'=>'mae','responsavel_financeiro'=>1,'responsavel_academico'=>1],ck());
    $result=$db->atomic(function()use($db,$academic,$student,$period,$class,$term){$m=$academic->placeInside((int)$student['idaluno'],$period,$class,ck());return $academic->createContractInside((int)$m['idmatricula'],$term+['quantidade_parcelas'=>12,'primeiro_vencimento'=>'2027-01-31'],ck(),true)+$m;});
    return [$result,(int)$student['idaluno'],$period];
}
$modelData=['nome'=>'Matrícula anual','texto'=>'[colegio_nome] / [aluno_nome] / RA [aluno_ra] / [responsavel_nome] / [mae_nome] / [periodo_codigo] / [turma_nome] / [contrato_numero] / [contrato_valor_liquido] / [contrato_parcelas] / [contrato_primeiro_vencimento]','versao_texto'=>'1','ativo'=>1,'padrao'=>1];
$key=ck();$model=$models->save(null,$modelData,$key);checkC($models->save(null,$modelData,$key)===$model,'cadastro de modelo idempotente');
denyC(fn()=>$models->save(null,$modelData+['invasao'=>'x'],ck()),'campos de modelo estranhos bloqueados');
denyC(fn()=>$models->save(null,array_replace($modelData,['texto'=>'[wp_shortcode]']),ck()),'shortcode fora da lista bloqueado');
denyC(fn()=>$models->save(null,array_replace($modelData,['ativo'=>0]),ck()),'padrão inativo bloqueado');
[$created,$student,$period]=fixtureC();$id=(int)$created['idcontrato'];$original=$db->get('contratos',$id);$text=$original['termo_snapshot'];
checkC(str_contains($text,'ALUNO CONTRATOS')&&str_contains($text,'MARIA CONTRATOS')&&str_contains($text,'R$ 12.000,00')&&str_contains($text,'31/01/2027'),'modelo preenche aluno, responsável, moeda e data');
checkC(!str_contains($text,'[aluno_nome]')&&(int)$original['idmodelo']===(int)$model['idmodelo']&&$original['modelo_nome_snapshot']==='Matrícula anual','texto preenchido e origem do modelo salvos no contrato');
checkC($contracts->listing(['codperiodo'=>'todos','search'=>'Aluno Contratos'])['total']===1,'busca por aluno');checkC($contracts->listing(['codperiodo'=>$period,'status'=>'pendente'])['total']===1,'consulta de contratos aguardando parcelas');
checkC($contracts->listing(['codperiodo'=>'todos','search'=>$original['numero']])['total']===1,'busca por número do contrato');checkC($contracts->listing(['codperiodo'=>'todos','page'=>2])['items']===[],'paginação não repete resultados');
checkC($contracts->detail($id)['lancamentos_ativos']===0&&$contracts->detail($id)['responsavel']==='MARIA CONTRATOS','detalhe identifica responsável e títulos vinculados');
$preview=$models->preview(['idcontrato'=>$id,'texto'=>'Aluno: [aluno_nome]\n[contrato_valor_liquido]']);checkC(str_contains($preview['texto'],'12.000,00')&&$db->get('contratos',$id)===$original,'prévia não altera contrato');
denyC(fn()=>$models->preview(['idcontrato'=>$id,'texto'=>'[erp_app]']),'prévia bloqueia shortcode executável');
denyC(fn()=>$models->save((int)$model['idmodelo'],array_replace($modelData,['texto'=>'Novo [aluno_nome]','versao'=>$model['versao'],'motivo'=>'Novo texto']),ck()),'alteração de texto exige nova versão');
$model=$models->save((int)$model['idmodelo'],array_replace($modelData,['texto'=>'Novo [aluno_nome] [contrato_valor_liquido]','versao_texto'=>'2','versao'=>$model['versao'],'motivo'=>'Novo texto']),ck());checkC($db->get('contratos',$id)===$original,'edição do modelo preserva contratos emitidos');
denyC(fn()=>$contracts->update($id,['versao'=>$original['versao'],'idmodelo'=>$model['idmodelo'],'modelo_versao'=>1,'motivo'=>'Reaplicar'],ck()),'modelo selecionado com versão antiga bloqueado');
$edited=$contracts->update($id,['versao'=>$original['versao'],'idmodelo'=>$model['idmodelo'],'modelo_versao'=>$model['versao'],'valor_original_total'=>'15000.00','desconto_incondicional_total'=>'3000.00','quantidade_parcelas'=>10,'primeiro_vencimento'=>'2027-02-28','motivo'=>'Revisão comercial'],ck());checkC($edited['valor_liquido_total']==='12000.00'&&$edited['versao_termo']==='2'&&str_starts_with($edited['termo_snapshot'],'Novo'),'edição pendente recalcula totais e aplica versão atual do modelo');
denyC(fn()=>$contracts->update($id,['versao'=>$original['versao'],'numero'=>'NOVO','motivo'=>'Teste'],ck()),'versão antiga do contrato bloqueada');
denyC(fn()=>$contracts->update($id,['versao'=>$edited['versao'],'termo'=>'Texto alterado','versao_termo'=>'2','motivo'=>'Teste'],ck()),'nova redação exige nova versão do termo');
denyC(fn()=>$contracts->update($id,['versao'=>$edited['versao'],'valor_original_total'=>'100.00','desconto_incondicional_total'=>'200.00','motivo'=>'Teste'],ck()),'bolsa maior que total bloqueada');checkC($db->get('contratos',$id)===$edited,'falhas deixam contrato intacto');
$edited=$contracts->update($id,['versao'=>$edited['versao'],'data_contrato'=>'2026-11-06','termo'=>$edited['termo_snapshot'],'motivo'=>'Data corrigida'],ck());checkC($edited['idmodelo']===$model['idmodelo'],'edição de metadados mantém origem do texto');
$otherModel=$models->save(null,['nome'=>'Segundo','texto'=>'Contrato [aluno_nome]','versao_texto'=>'1','ativo'=>1,'padrao'=>1],ck());$model=$db->get('modelos_contrato',(int)$model['idmodelo']);checkC((int)$model['padrao']===0&&count(array_filter($models->listing(true)['items'],fn($r)=>(int)$r['padrao']))===1,'existe um único modelo padrão');
[$manual,$manualStudent]=fixtureC('-manual',['termo'=>'Termo aceito pelo responsável','versao_termo'=>'oferta-1']);checkC($db->get('contratos',(int)$manual['idcontrato'])['termo_snapshot']==='Termo aceito pelo responsável','termo explícito de rematrícula prevalece sobre o padrão');
$removeKey=ck();$removeModel=['versao'=>$otherModel['versao'],'motivo'=>'Modelo descontinuado'];$removed=$models->remove((int)$otherModel['idmodelo'],$removeModel,$removeKey);checkC($models->remove((int)$otherModel['idmodelo'],$removeModel,$removeKey)===$removed,'exclusão de modelo idempotente');checkC($db->get('contratos',$id)['termo_snapshot']===$edited['termo_snapshot'],'exclusão de modelo preserva texto contratado');
denyC(fn()=>$contracts->update($id,['versao'=>$edited['versao'],'idmodelo'=>$otherModel['idmodelo'],'motivo'=>'Teste'],ck()),'modelo excluído não pode ser aplicado');
$actor=wp_insert_user(['user_login'=>'finance-contracts','user_pass'=>'x','user_email'=>'finance-contracts@example.test','role'=>'erp_financeiro']);wp_set_current_user($actor);
foreach([fn()=>$contracts->listing([]),fn()=>$contracts->detail($id),fn()=>$contracts->update($id,['versao'=>$edited['versao'],'motivo'=>'Teste'],ck()),fn()=>$contracts->remove($id,['versao'=>$edited['versao'],'motivo'=>'Teste','confirmado'=>true],ck()),fn()=>$models->save(null,$modelData,ck()),fn()=>$models->listing(true)] as $fn)denyC($fn,'Financeiro não administra contratos e modelos');
$secretary=wp_insert_user(['user_login'=>'secret-contracts','user_pass'=>'x','user_email'=>'secret-contracts@example.test','role'=>'erp_secretaria']);wp_set_current_user($secretary);checkC(count($models->listing()['items'])===1,'secretaria consulta modelos ativos para emitir contrato');denyC(fn()=>$models->listing(true),'secretaria não consulta modelos inativos');wp_set_current_user(1);
$academic->generateInstallments($id,ck());$withParcels=$db->get('contratos',$id);checkC($contracts->detail($id)['lancamentos_ativos']===10,'detalhe conta parcelas geradas');
denyC(fn()=>$contracts->update($id,['versao'=>$withParcels['versao'],'quantidade_parcelas'=>12,'motivo'=>'Teste'],ck()),'não reescreve cronograma já gerado');
denyC(fn()=>$contracts->remove($id,['versao'=>$withParcels['versao'],'motivo'=>'Teste','confirmado'=>true],ck()),'lançamentos vinculados impedem exclusão do contrato');
denyC(fn()=>$contracts->remove((int)$manual['idcontrato'],['versao'=>$db->get('contratos',(int)$manual['idcontrato'])['versao'],'motivo'=>'Teste'],ck()),'confirmação de exclusão obrigatória');
$titles=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY l.idlancamento',[$id]);$finance=new FinanceService($db,$ops);$finance->pay((int)$titles[0]['idlancamento'],['valor_pago'=>'1200.00','forma_pagamento'=>'pix'],ck());
$delete=new FinancialDeletion($db,$ops);foreach($titles as $title){$current=$db->get('lancamentos',(int)$title['idlancamento']);$delete->remove((int)$title['idlancamento'],['versao'=>$current['versao'],'motivo'=>'Correção contratual'],ck());}
$enrollmentBefore=$db->get('matriculas',(int)$withParcels['idmatricula']);$paymentsBefore=$db->rows('SELECT * FROM '.$db->table('baixas'));$parcelsBefore=$db->rows('SELECT * FROM '.$db->table('parcelas').' WHERE idcontrato=%d',[$id]);
$key=ck();$data=['versao'=>$withParcels['versao'],'motivo'=>'Contrato duplicado','confirmado'=>true];$result=$contracts->remove($id,$data,$key);checkC($contracts->remove($id,$data,$key)===$result,'exclusão do contrato idempotente');
$deleted=$db->get('contratos',$id);checkC($deleted['status']==='cancelado'&&!empty($deleted['excluido_em'])&&(int)$deleted['excluido_por_wp_user_id']===1&&$deleted['motivo_exclusao']==='Contrato duplicado','exclusão registra data, ator e motivo');
checkC($db->get('matriculas',(int)$withParcels['idmatricula'])===$enrollmentBefore&&$paymentsBefore===$db->rows('SELECT * FROM '.$db->table('baixas'))&&$parcelsBefore===$db->rows('SELECT * FROM '.$db->table('parcelas').' WHERE idcontrato=%d',[$id]),'exclusão mantém matrícula, parcelas e baixas');
checkC($contracts->listing(['codperiodo'=>$period])['total']===0,'contrato excluído desaparece da consulta');denyC(fn()=>$contracts->detail($id),'detalhe direto bloqueia contrato excluído');denyC(fn()=>$models->preview(['idcontrato'=>$id,'texto'=>'[aluno_nome]']),'prévia bloqueia contrato excluído');denyC(fn()=>$academic->generateInstallments($id,ck()),'contrato excluído não gera novas parcelas');
checkC((new ExportService($db))->student($student,$period)['matriculas'][0]['contratos']===[],'exportação operacional omite contrato excluído');
checkC(count((new DataExport($db))->page(['tabela'=>'contratos','coligadas'=>[1]])['registros'])===2,'backup administrativo conserva contrato excluído');
$new=$academic->createContract((int)$withParcels['idmatricula'],['quantidade_parcelas'=>12,'primeiro_vencimento'=>'2027-03-01'],ck());$replacement=$db->get('contratos',(int)$new['idcontrato']);checkC($replacement['numero']!==$deleted['numero']&&$replacement['idmatricula']===$deleted['idmatricula'],'recriação permite novo contrato sem reutilizar número histórico');
$company=$db->insert('coligadas',['nome'=>'SEGUNDA COLIGADA','razao_social'=>'SEGUNDA COLIGADA']);
Coligadas::within($company,function()use($contracts,$models,$id,$replacement,$model){checkC($contracts->listing(['codperiodo'=>'todos'])['total']===0,'listagem respeita coligada');denyC(fn()=>$contracts->detail((int)$replacement['idcontrato']),'detalhe bloqueia outra coligada');denyC(fn()=>$models->preview(['idcontrato'=>$replacement['idcontrato'],'texto'=>'[aluno_nome]']),'prévia bloqueia outra coligada');checkC($models->listing()['items']===[],'modelos são específicos da coligada');});
checkC($wpdb->pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'chaves estrangeiras preservadas');
$audit=$db->row('SELECT * FROM '.$db->table('auditoria')." WHERE entidade='contratos' AND entidade_id=%d AND acao='excluir'",[$id]);checkC(json_decode($audit['antes_json'],true)['excluido_em']===null&&json_decode($audit['depois_json'],true)['motivo_exclusao']==='Contrato duplicado','auditoria preserva antes e depois');
function register_rest_route($ns,$path,$args){$GLOBALS['contract_routes'][$path][$args['methods']]=$args;}
$access=new Access($db);$export=new ExportService($db);$renew=new \EducacionalERP\Application\RenewalService($db,$ops,$academic,$access);
update_option('ederp_schema_version',Installer::VERSION);update_option('ederp_schema_error',false);
(new \EducacionalERP\Presentation\Rest\Controller($db,$access,$academic,$catalog,$finance,$export,$renew,$flow))->register();
foreach(['/contratos'=>['GET'],'/contratos/(?P<id>[1-9][0-9]{0,17})'=>['GET','POST'],'/contratos/(?P<id>[1-9][0-9]{0,17})/excluir'=>['POST'],'/modelos-contrato'=>['POST'],'/modelos-contrato/previa'=>['POST'],'/modelos-contrato/(?P<id>[1-9][0-9]{0,17})'=>['POST'],'/modelos-contrato/(?P<id>[1-9][0-9]{0,17})/excluir'=>['POST']] as $path=>$methods)foreach($methods as $method){
    $route=$GLOBALS['contract_routes'][$path][$method];checkC(isset($route),'rota de gestão registrada');
    foreach([0,$actor,$secretary] as $user){wp_set_current_user($user);checkC($route['permission_callback'](null) instanceof WP_Error,'rota de gestão bloqueia perfil sem permissão');}
    wp_set_current_user(1);checkC($route['permission_callback'](null)===true,'rota de gestão permite administrador');
}
wp_set_current_user($secretary);checkC($GLOBALS['contract_routes']['/modelos-contrato']['GET']['permission_callback'](null)===true,'secretaria pode carregar modelos na matrícula');wp_set_current_user(1);
checkC(!in_array('contratos',\EducacionalERP\Infrastructure\WordPress\MenuPolicy::defaults('erp_financeiro'),true),'gestão de contratos fica reservada ao administrador');
echo "PASS: $checks verificações de contratos e modelos\n";
