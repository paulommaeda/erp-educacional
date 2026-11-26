<?php
declare(strict_types=1);

use EducacionalERP\Application\{Coligadas,ContractDiscounts,ContractFinancialSummary,ContractRepricing,Contracts,FinanceService,Operations};
use EducacionalERP\Domain\RuleViolation;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\Pdf\ContractsReportPdf;
use EducacionalERP\Infrastructure\WordPress\{Access,SchemaMigration};

$bootstrap=getenv('EDERP_TEST_BOOTSTRAP');
if($bootstrap){
    if(PHP_SAPI!=='cli')exit(2);
    $_SERVER['HTTP_HOST']='erp-test.local';
    $_SERVER['REQUEST_URI']='/';
    require $bootstrap;
    if(!defined('EDERP_TEST_DATABASE')||EDERP_TEST_DATABASE!==true)throw new RuntimeException('Exige WordPress de teste.');
    wp_set_current_user(get_users(['role'=>'administrator','number'=>1])[0]->ID);
}else{
    require __DIR__.'/sqlite-bootstrap.php';
}
require_once dirname(__DIR__).'/autoload.php';
require __DIR__.'/fixtures/scholarships.php';

function repricingCheck(bool $condition,string $label):void
{
    if(!$condition)throw new RuntimeException($label);
    echo "PASS: $label\n";
}

function repricingReject(callable $work,string $expected):void
{
    try{$work();}catch(RuleViolation $e){repricingCheck(str_contains($e->getMessage(),$expected),$e->getMessage());return;}
    throw new RuntimeException('Operação deveria ser recusada: '.$expected);
}

Access::install();
update_option('ederp_pontualidade','103.00');
$db=new Database($wpdb);
if($bootstrap){
    if(!defined('EDERP_VERSION'))define('EDERP_VERSION','0.19.56');
    $wpdb->prefix='repricing_'.bin2hex(random_bytes(4)).'_';
    delete_option('ederp_schema_version');
    delete_option('ederp_schema_error');
    SchemaMigration::run($db);
}
$fixture=seedScholarships($db);
$created=createScholarshipContract($db,$fixture,'REGERAR',[scholarshipSelection($fixture['half'],1,1)]);
$id=(int)$created['idcontrato'];
$ops=new Operations($db);
$service=new ContractRepricing($db,$ops);
$contracts=new Contracts($db,$ops);
$finance=new FinanceService($db,$ops);
$key=static fn()=>bin2hex(random_bytes(16));
$request=static fn(int $id,array $more=[])=>$more+['versao'=>$db->get('contratos',$id)['versao'],'confirmado'=>true,'motivo'=>'Revisão de teste'];
$titles=$db->table('lancamentos');$parcels=$db->table('parcelas');
$charge=static fn(int $id,int $number)=>$db->row("SELECT l.* FROM $titles l JOIN $parcels p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d AND p.numero=%d",[$id,$number]);
$discounts=new ContractDiscounts($db,$ops);
$discounts->add($id,['nome'=>'Desconto cadastrado','valor'=>'50.00','parcela_inicio'=>2,'parcela_fim'=>2],$key());
$finance->adjust((int)$charge($id,2)['idlancamento'],['componente'=>'desconto_incondicional','valor_delta'=>'25.00','motivo'=>'Desconto extra auditado'],$key());
$finance->pay((int)$charge($id,3)['idlancamento'],['valor_pago'=>'100.00','forma_pagamento'=>'pix'],$key());
$paid=$charge($id,3);
$corrupted=$charge($id,2);
$db->update('lancamentos',(int)$corrupted['idlancamento'],['desconto_incondicional'=>'375.00','valor_liquido'=>'978.00','saldo_aberto'=>'978.00']);
$data=$request($id);$operation=$key();
$updated=$service->run($id,$data,$operation);
$second=$charge($id,2);
repricingCheck($updated['parcelas_preservadas']===1&&$paid===$charge($id,3)&&$second['desconto_incondicional']==='75.00'
    &&$second['valor_liquido']==='1278.00'&&$second['vencimento']===$corrupted['vencimento']
    &&$charge($id,1)['desconto_incondicional']==='573.50','recálculo remove desconto sem origem e preserva baixa, bolsa, ajuste e vencimento');
repricingCheck($updated===$service->run($id,$data,$operation),'repetição idempotente devolve o resultado anterior');
repricingCheck($service->run($id,$request($id),$key())['parcelas_alteradas']===0,'recálculo repetido mantém os valores e lançamentos');
$row=$contracts->financialSummary($id);
$line=ContractFinancialSummary::reportRow($row);
repricingCheck($row['pontualidade_total']==='1236.00'&&$row['original_parcela_min']==='1353.00'
    &&$row['desconto_parcela_min']==='0.00'&&$row['desconto_parcela_max']==='573.50'
    &&count($line)===7&&$line[3]===[substr($charge($id,1)['vencimento'],8,2)],'resumo e relatório exibem originais, dia e faixas de descontos');
$pdf=ContractsReportPdf::render([$row],['nome'=>'Colégio de teste']);
repricingCheck(str_starts_with($pdf,'%PDF-')&&str_contains($pdf,'841.89 595.28'),'PDF das sete colunas permanece em A4 paisagem');

$plan=$db->insert('planos_pagamento',['codperiodo'=>$fixture['period'],'codigo'=>'NOVO','nome'=>'Novo plano','valor_anuidade'=>'18000.00']);
$newPlan=['idplano'=>$plan,'plano_versao'=>$db->get('planos_pagamento',$plan)['versao']];
repricingReject(fn()=>$service->run($id,$request($id,$newPlan),$key()),'sem histórico de baixa');
$another=createScholarshipContract($db,$fixture,'TROCAR');
$secondId=(int)$another['idcontrato'];
$old=$charge($secondId,1);
repricingReject(fn()=>$service->run($secondId,$request($secondId,['idplano'=>$plan,'plano_versao'=>999]),$key()),'plano de pagamento mudou');
repricingCheck($service->run($secondId,$request($secondId,$newPlan),$key())['plano_alterado'],'plano ativo do mesmo período pode ser selecionado');
$after=$charge($secondId,1);
repricingCheck($after['idlancamento']===$old['idlancamento']&&$after['vencimento']===$old['vencimento']&&$after['valor_original']==='1500.00'
    &&(int)$db->get('contratos',$secondId)['idplano']===$plan,'troca mantém lançamento, calendário e número de parcelas');
$contracts->sign($secondId,['versao'=>$db->get('contratos',$secondId)['versao'],'confirmado'=>true],$key());
$signed=$db->get('contratos',$secondId);
repricingReject(fn()=>$service->run($secondId,$request($secondId,$newPlan),$key()),'assinado e bloqueado');
$service->run($secondId,$request($secondId),$key());
$repaired=$db->get('contratos',$secondId);
repricingCheck($repaired['termo_snapshot']===$signed['termo_snapshot']&&$repaired['assinado_em']===$signed['assinado_em'],
    'regerar financeiro preserva documento e assinatura do contrato assinado');
$outside=$db->insert('coligadas',['nome'=>'Outra','razao_social'=>'Outra']);
Coligadas::within($outside,fn()=>repricingReject(fn()=>$service->options($secondId),'indisponível nesta coligada'));
echo "CONTRACT_REPRICING_OK\n";
