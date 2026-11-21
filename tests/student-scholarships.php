<?php
declare(strict_types=1);

use EducacionalERP\Application\{AcademicService,Coligadas,Contracts,ContractTemplates,FinanceService,Operations,Scholarships,StudentScholarships};
use EducacionalERP\Domain\{FinancialStatus,Input,RuleViolation,Store};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;

/** Shared by SQLite and by the native WordPress/InnoDB scholarship suite. */
function exerciseStudentScholarships(Store $db,array $fixture,callable $check):int
{
    $key=static fn()=>bin2hex(random_bytes(16));
    $ops=new Operations($db);
    $academic=new AcademicService($db,$ops);
    $finance=new FinanceService($db,$ops);
    $contracts=new Contracts($db,$ops);
    $models=new ContractTemplates($db,$ops);
    $service=new StudentScholarships($db);
    $deny=static function(callable $action,string $label)use($check){
        try{$action();}catch(RuleViolation $failure){$check(true,$label);return;}
        throw new RuntimeException($label);
    };
    $person=$db->insert('pessoas',['nome'=>'ALUNO COM BOLSA','data_nascimento'=>'2015-01-01']);
    $student=$db->insert('alunos',['codpessoa'=>$person,'ra'=>'BA-001']);
    $db->insert('aluno_responsaveis',['idaluno'=>$student,'codpessoa_responsavel'=>$fixture['responsible'],'parentesco'=>'mae','responsavel_financeiro'=>1,'inicio_vigencia'=>'2000-01-01']);
    $plan=$db->insert('planos_pagamento',['codperiodo'=>$fixture['period'],'codigo'=>'BA-18K','nome'=>'Plano de 18 mil','valor_anuidade'=>'18000.00']);
    $model=$models->save(null,['nome'=>'Bolsas do aluno','texto'=>'<p>[bolsas_do_aluno]</p><p>Valor [contrato_valor_parcela]</p>','versao_texto'=>'1','ativo'=>1,'padrao'=>0],$key());
    $make=function(string $suffix,bool $defer=false)use($db,$academic,$fixture,$student,$plan,$model,$key):int{
        $course=$db->insert('cursos',['codigo'=>'BA-'.$suffix,'nome'=>'Curso '.$suffix]);
        $class=$db->insert('turmas',['codperiodo'=>$fixture['period'],'idcurso'=>$course,'idturno'=>$fixture['shift'],'idplano'=>$plan,'codigo'=>'BA-'.$suffix,'nome'=>'Turma '.$suffix,'capacidade'=>100]);
        return $db->atomic(function()use($academic,$fixture,$student,$class,$model,$defer,$key){
            $m=$academic->placeInside($student,(int)$fixture['period'],$class,$key());
            $r=$academic->createContractInside((int)$m['idmatricula'],['idmodelo'=>$model['idmodelo'],'quantidade_parcelas'=>12,'primeiro_vencimento'=>(new DateTimeImmutable(Input::today()))->modify('+10 days')->format('Y-m-d')],$key(),$defer);
            return (int)$r['idcontrato'];
        });
    };
    $titles=fn(int $id)=>$db->rows('SELECT l.*,p.numero FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY p.numero',[$id]);
    $data=['titulo'=>'Bolsa Família','descricao'=>'Concedida aos irmãos & responsáveis.','percentual'=>'10.00','ativo'=>1];
    $fixed=$key();
    $inactive=$service->save($student,null,array_replace($data,['ativo'=>0]),$fixed);
    $check($inactive['contratos_atualizados']===0&&$service->save($student,null,array_replace($data,['ativo'=>0]),$fixed)===$inactive,'bolsa pode ser cadastrada antes da primeira matrícula e a repetição é idempotente');
    $grant=(int)$inactive['idbolsa_aluno'];
    $save=function(array $fields)use($service,$student,$grant,$db,$key){return $service->save($student,$grant,$fields+['versao'=>$db->get('aluno_bolsas',$grant)['versao']],$key());};
    $first=$make('A');$pending=$make('B',true);$cancelled=$make('C',true);
    $db->update('contratos',$cancelled,['status'=>'cancelado']);
    $saved=$db->get('contratos',$first);$contracts->sign($first,['confirmado'=>true,'versao'=>$saved['versao']],$key());
    $signed=$db->get('contratos',$first);
    $old=$titles($first);$finance->pay((int)$old[0]['idlancamento'],['valor_pago'=>'50.00','forma_pagamento'=>'pix'],$key());
    $partial=$db->get('lancamentos',(int)$old[0]['idlancamento']);
    $reply=$save($data);
    $check($reply['contratos_atualizados']===2&&$reply['parcelas_alteradas']===11&&$reply['parcelas_preservadas']===1,'ativação alcança os contratos ativos do aluno e preserva parcela paga parcialmente');
    $check($db->get('lancamentos',(int)$old[0]['idlancamento'])===$partial&&!$service->forContract($cancelled),'pagamento parcial e contrato cancelado preservados');
    $r=$titles($first)[1];$breakdown=json_decode($r['bolsas_json'],true,512,JSON_THROW_ON_ERROR)[0];
    $check($r['valor_original']==='1500.00'&&$r['valor_liquido']==='1453.00'&&FinancialStatus::present($r)['saldo_a_pagar']==='1350.00','R$ 1.500 com 10% e R$ 103 de pontualidade gera R$ 1.453 e R$ 1.350 em dia');
    $check($breakdown['origem']==='aluno'&&(int)$breakdown['idbolsa_aluno']===$grant&&$breakdown['nome']===$data['titulo']&&$breakdown['descricao']===$data['descricao']&&$breakdown['valor_calculado']==='150.00'&&$breakdown['valor']==='47.00','parcela registra a origem, o título, a descrição e a composição da bolsa');
    $snapshot=$contracts->detail($pending)['bolsas_aluno'][0];
    $check($snapshot['titulo']===$data['titulo']&&$snapshot['descricao']===$data['descricao']&&$snapshot['percentual']==='10.00','contrato pendente guarda uma cópia identificável da concessão');
    $pendingC=$db->get('contratos',$pending);$check($pendingC['valor_liquido_total']==='17436.00'&&str_contains($pendingC['termo_snapshot'],'Bolsa Família (10%) — Concedida aos irmãos &amp; responsáveis.'),'modelo não assinado reflete o texto e os totais da bolsa antes da geração');
    $check($db->get('contratos',$first)['assinatura_texto_hash']===$signed['assinatura_texto_hash']&&$db->get('contratos',$first)['termo_snapshot']===$signed['termo_snapshot'],'concessão financeira preserva o texto assinado');
    $finance->pay((int)$r['idlancamento'],['valor_pago'=>'1350.00','forma_pagamento'=>'pix'],$key());$paid=$db->get('lancamentos',(int)$r['idlancamento']);
    $check($paid['valor_baixa']==='1350.00'&&$paid['desconto_condicional_aplicado']==='103.00'&&$paid['saldo_aberto']==='0.00','baixa em dia quita a parcela com uma única aplicação da pontualidade');
    $future=$make('D');$check($titles($future)[0]['valor_liquido']==='1453.00'&&count($service->forContract($future,true))===1,'novo contrato herda a bolsa ativa uma única vez');
    $after=new Scholarships($db);$after->add($pending,scholarshipSelection($fixture['half']),$key());
    $check($db->get('contratos',$pending)['valor_liquido_total']==='8436.00','50% do contrato somada a 10% do aluno resulta em R$ 703 sem pontualidade');
    $earlier=$titles($first)[2];$version=$db->get('aluno_bolsas',$grant)['versao'];
    $deny(fn()=>$save(array_replace($data,['percentual'=>'60.00'])),'excesso de 100% em um dos contratos bloqueia a alteração inteira');
    $check($titles($first)[2]===$earlier&&$db->get('aluno_bolsas',$grant)['versao']===$version,'falha no segundo contrato reverte a edição e o recálculo do primeiro');
    $academic->generateInstallments($pending,$key());$check($titles($pending)[0]['valor_liquido']==='703.00'&&FinancialStatus::present($titles($pending)[0])['saldo_a_pagar']==='600.00','geração posterior respeita as duas bolsas sem duplicação');
    $change=array_replace($data,['titulo'=>'Programa atualizado','descricao'=>'Condição revisada','percentual'=>'20.00']);
    $save($change);$check($service->forContract($future,true)[0]['titulo']===$change['titulo']&&$titles($future)[0]['valor_liquido']==='1303.00','alterar dados no aluno atualiza a descrição e as parcelas em todos os contratos elegíveis');
    $save(array_replace($change,['ativo'=>0]));$check($titles($future)[0]['valor_liquido']==='1500.00'&&!$service->forContract($future,true)&&$db->get('lancamentos',(int)$r['idlancamento'])===$paid,'desativar remove o efeito nas parcelas em aberto e mantém a baixa histórica');
    $later=$make('E',true);$check(!$service->forContract($later)&&$db->get('contratos',$later)['valor_liquido_total']==='18000.00','novo contrato não recebe a bolsa inativa');
    $save($change);$check(count($service->forContract($first))===1&&count($service->forContract($later))===1,'reativação reaproveita cada vínculo e alcança novo contrato');
    $deny(fn()=>$service->save($student,$grant,$change+['versao'=>$version],$key()),'revisão antiga é recusada');
    foreach([['percentual'=>'0.00'],['percentual'=>'101.00'],['descricao'=>''],['forma_calculo'=>'valor']] as $bad)$deny(fn()=>$service->save($student,null,array_replace($data,$bad),$key()),'cadastro exige descrição e percentual válido');
    $other=$db->insert('coligadas',['nome'=>'Coligada do teste de bolsa','razao_social'=>'Coligada do teste de bolsa']);
    $deny(fn()=>Coligadas::within($other,fn()=>$service->listing($student)),'consulta respeita a coligada do aluno');
    $deny(fn()=>Coligadas::within($other,fn()=>$save($change)),'outra coligada não pode alterar a bolsa');
    return $student;
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';require __DIR__.'/fixtures/scholarships.php';
    Access::install();update_option('ederp_pontualidade','103.00');
    $db=new Database($wpdb);$count=0;
    exerciseStudentScholarships($db,seedScholarships($db),static function(bool $ok,string $label)use(&$count){if(!$ok)throw new RuntimeException($label);$count++;});
    echo "STUDENT_SCHOLARSHIPS_OK: $count verificações\n";
}
