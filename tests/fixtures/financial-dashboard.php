<?php
declare(strict_types=1);
require_once __DIR__.'/scholarships.php';
function seedFinancialDashboard(\EducacionalERP\Domain\Store $db):array
{
    $f=seedScholarships($db);$classB=$db->insert('turmas',['codperiodo'=>$f['period'],'idcurso'=>$f['course'],'idturno'=>$f['shift'],'idplano'=>$f['plan'],'codigo'=>'DASH-B','nome'=>'TURMA B','capacidade'=>100]);
    $a=createScholarshipContract($db,$f,'DASH-A',[scholarshipSelection($f['half'],1,1),scholarshipSelection($f['full'],7,7)]);
    $b=createScholarshipContract($db,array_replace($f,['class'=>$classB]),'DASH-B');
    $titles=[];foreach(['a'=>$a,'b'=>$b] as $name=>$created){$rows=$db->rows('SELECT l.*,p.numero FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY p.numero',[(int)$created['idcontrato']]);foreach($rows as $r){$n=(int)$r['numero'];$titles[$name][$n]=(int)$r['idlancamento'];if(($name==='a'&&$n>7)||($name==='b'&&$n>1))$db->update('lancamentos',(int)$r['idlancamento'],['excluido_em'=>'2026-01-01 00:00:00']);else{$db->update('parcelas',(int)$r['idparcela'],['competencia'=>$name==='b'||$n<=3?'2027-01-01':($n<=5?'2027-02-01':'2027-03-01')]);$db->update('lancamentos',(int)$r['idlancamento'],['pontualidade_snapshot'=>'103.00']);}}}
    $ops=new \EducacionalERP\Application\Operations($db);$finance=new \EducacionalERP\Application\FinanceService($db,$ops);$discounts=new \EducacionalERP\Application\ContractDiscounts($db,$ops);$key=static fn()=>bin2hex(random_bytes(16));
    $discounts->add((int)$a['idcontrato'],['nome'=>'BOLSA FIXA','valor'=>'350.00','parcela_inicio'=>2,'parcela_fim'=>2],$key());
    $finance->pay($titles['a'][2],['valor_pago'=>'60.00','forma_pagamento'=>'pix'],$key());$finance->pay($titles['a'][2],['valor_pago'=>'40.00','forma_pagamento'=>'pix'],$key());
    $db->update('lancamentos',$titles['a'][3],['vencimento'=>\EducacionalERP\Domain\Input::today()]);$finance->pay($titles['a'][3],['valor_pago'=>'1250.00','forma_pagamento'=>'pix'],$key());
    $finance->pay($titles['a'][5],['valor_pago'=>'200.00','forma_pagamento'=>'pix'],$key());$db->update('lancamentos',$titles['a'][5],['status'=>'cancelado','saldo_aberto'=>'0.00','valor_cancelado'=>'1153.00']);
    $paid=$finance->pay($titles['a'][6],['valor_pago'=>'500.00','forma_pagamento'=>'pix'],$key());$finance->reverse((int)$paid['idbaixa'],['motivo'=>'Estorno de teste'],$key());
    $finance->adjust($titles['b'][1],['componente'=>'juros_aplicados','valor_delta'=>'50.00','motivo'=>'Encargo de teste'],$key());$finance->adjust($titles['b'][1],['componente'=>'multa_aplicada','valor_delta'=>'20.00','motivo'=>'Encargo de teste'],$key());$finance->pay($titles['b'][1],['valor_pago'=>'120.00','forma_pagamento'=>'pix'],$key());
    $today=\EducacionalERP\Domain\Input::today();$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    foreach($titles['a'] as $n=>$id)if($n<=7&&$n!==5)$db->update('lancamentos',$id,['vencimento'=>$n===4?$today:$yesterday]);$db->update('lancamentos',$titles['b'][1],['vencimento'=>$yesterday]);
    return $f+['a'=>$a,'b'=>$b,'classB'=>$classB,'titles'=>$titles];
}

function prepareInstallmentReportDueDates(\EducacionalERP\Domain\Store $db,array $fixture):void
{
    $dates=[1=>'2027-01-15',2=>'2027-01-20',3=>'2027-01-31',4=>'2027-02-12',5=>'2027-02-14',6=>'2027-03-18',7=>'2027-03-25'];
    foreach($dates as $number=>$date){
        $db->update('lancamentos',$fixture['titles']['a'][$number],['vencimento'=>$date]);
    }
    $db->update('lancamentos',$fixture['titles']['b'][1],['vencimento'=>'2027-01-05']);
    // Keep the January due dates while reproducing installments from another competence.
    foreach([$fixture['titles']['a'][2],$fixture['titles']['b'][1]] as $id){
        $title=$db->get('lancamentos',$id);
        $db->update('parcelas',(int)$title['idparcela'],['competencia'=>'2026-12-01']);
    }
}
