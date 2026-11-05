<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Application\{Operations,CatalogService,AcademicService,StudentWorkflow,FinanceService,ContractDiscounts,PaymentReceipt,Coligadas};
use EducacionalERP\Infrastructure\WordPress\{Access,Accounts,SchoolIdentity,MenuPolicy};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\Pdf\{PaymentReceiptPdf,ReceiptLogo};
use EducacionalERP\Domain\RuleViolation;
Access::install();wp_set_current_user(1);$db=new Database($wpdb);$ops=new Operations($db);$cat=new CatalogService($db,$ops);$academic=new AcademicService($db,$ops);$access=new Access($db);$workflow=new StudentWorkflow($db,$ops,$cat,$academic);$finance=new FinanceService($db,$ops);$receipts=new PaymentReceipt($db,$access);$checks=0;
function rk():string{return bin2hex(random_bytes(16));}
function rc(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;echo 'PASS: '.$label."\n";}
function rd(callable $work,string $label):void{try{$work();}catch(RuleViolation $e){rc(true,$label);return;}throw new RuntimeException($label);}
function ra(string $type,array $data):int{global $cat;return (int)$cat->create($type,$data,rk())['id'];}
function get_attached_file($id){return $GLOBALS['receipt_logo_files'][$id]??false;}
function fixture(int $guardian,string $suffix=''):array{
    global $db,$workflow,$cat;
    $period=ra('periodos_letivos',['codigo'=>'2026'.$suffix,'descricao'=>'Ano de teste','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']);
    $course=ra('cursos',['codigo'=>'EF'.$suffix,'nome'=>'Fundamental']);$shift=ra('turnos',['codigo'=>'M'.$suffix,'nome'=>'Manhã']);
    $plan=ra('planos_pagamento',['codperiodo'=>$period,'codigo'=>'ANUAL'.$suffix,'nome'=>'Anuidade','valor_anuidade'=>'3000.00']);
    $class=ra('turmas',['codigo'=>'6ANO'.$suffix,'nome'=>'6º ANO','idcurso'=>$course,'idturno'=>$shift,'codperiodo'=>$period,'idplano'=>$plan,'capacidade'=>30]);
    $a=$workflow->createStudent(['email'=>'fixture-payment-receipts-2@example.test','nome'=>'João Gonçalves de Sá'.$suffix,'data_nascimento'=>'2015-01-01'],rk());
    $cat->link((int)$a['idaluno'],['codpessoa_responsavel'=>$guardian,'parentesco'=>'mae','responsavel_financeiro'=>1,'responsavel_academico'=>1],rk());
    $m=$workflow->batch(['codperiodo'=>$period,'idturma'=>$class,'alunos'=>[$a['idaluno']],'quantidade_parcelas'=>3,'primeiro_vencimento'=>'2026-01-10'],rk())['items'][0];
    $titles=$db->rows('SELECT l.* FROM '.$db->table('lancamentos').' l JOIN '.$db->table('parcelas').' p ON p.idparcela=l.idparcela WHERE p.idcontrato=%d ORDER BY p.numero',[(int)$m['idcontrato']]);
    return [$a,$m,$titles];
}
$db->update('coligadas',1,['nome'=>'COLÉGIO DE TESTE','razao_social'=>'COLEGIO TESTE LTDA','cnpj'=>'11222333000181']);
$GLOBALS['receipt_logo_files'][900]=dirname(__DIR__).'/assets/brand/journey.png';
update_option(SchoolIdentity::option(),array_replace(SchoolIdentity::defaults(),['nome'=>'COLÉGIO DE TESTE','logo_id'=>900,'rua'=>'AVENIDA DA ESCOLA','numero'=>'123','bairro'=>'CENTRO','cidade'=>'CURITIBA','estado'=>'PR','cep'=>'80010-000','telefone'=>'(41) 3333-4444','email'=>'contato@example.test']));
$guardian=ra('pessoas',['email'=>'fixture-payment-receipts-1@example.test','nome'=>'Maria da Conceição de Sá','data_nascimento'=>'1980-01-01','cpf'=>'52998224725']);$other=ra('pessoas',['email'=>'fixture-payment-receipts-0@example.test','nome'=>'Outro responsável','data_nascimento'=>'1981-01-01']);
[$student,$enrollment,$titles]=fixture($guardian);$id=(int)$titles[0]['idlancamento'];
update_option('ederp_pontualidade','50.00');(new ContractDiscounts($db,$ops))->add((int)$enrollment['idcontrato'],['nome'=>'Bolsa teste','valor'=>'100.00','parcela_inicio'=>1,'parcela_fim'=>3],rk());
rd(fn()=>$receipts->details($id),'Parcela em aberto não gera recibo');
$first=$finance->pay($id,['valor_pago'=>'200.00','data_pagamento'=>'2026-01-09','forma_pagamento'=>'pix','codpessoa_pagador'=>$other],rk());
rd(fn()=>$receipts->details($id),'Pagamento parcial não gera recibo de quitação');
$last=$finance->pay($id,['valor_pago'=>'650.00','data_pagamento'=>'2026-01-10','forma_pagamento'=>'boleto'],rk());$data=$receipts->details($id);
rc($data['valor_pago']==='850.00'&&$data['valor_liquido']==='900.00'&&$data['desconto_condicional']==='50.00','Recibo usa valor efetivo, líquido contratual e pontualidade concedida separados');
rc(count($data['pagamentos'])===2&&$data['pagamentos'][0]['forma_pagamento']==='pix'&&$data['pagamentos'][1]['data_pagamento']==='2026-01-10','Recibo conserva as datas e formas de cada baixa');
rc($data['pagamentos'][0]['pagador']===$db->get('pessoas',$other)['nome']&&$data['pagamentos'][1]['pagador']===$db->get('pessoas',$guardian)['nome'],'Pagador informado e devedor da baixa são identificados sem inventar nomes');
rc($data['colegio']['cnpj']==='11222333000181'&&$data['colegio']['nome']==='COLÉGIO DE TESTE','Emissor pertence à coligada proprietária do lançamento');
$file=$receipts->download($id);$pdf=base64_decode($file['conteudo'],true);
rc($data['colegio']['logo_id']===900&&ReceiptLogo::load($data['colegio'])['path']===realpath($GLOBALS['receipt_logo_files'][900]),'Logo configurada na identidade do colégio é usada no PDF');
rc($file['mime']==='application/pdf'&&str_starts_with($pdf,'%PDF-')&&str_contains($pdf,'/Subtype /Image'),'Download autenticado devolve PDF com logo embutida');
rc(!class_exists('FPDF',false)&&class_exists('EducacionalERP\\Vendor\\Fpdf\\FPDF',false),'Biblioteca PDF isolada não conflita com FPDF de outros plugins');
if(defined('ERP_RECEIPT_PDF_PATH'))file_put_contents(ERP_RECEIPT_PDF_PATH,$pdf);
$long=$data;$long['pagamentos']=array_fill(0,45,$data['pagamentos'][0]);$longPdf=PaymentReceiptPdf::render($long);rc(preg_match('~/Count ([2-9][0-9]*)~',$longPdf)===1,'Listas extensas têm paginação automática');if(defined('ERP_RECEIPT_PDF_PATH'))file_put_contents(str_replace('.pdf','-multipagina.pdf',ERP_RECEIPT_PDF_PATH),$longPdf);
$family=(int)(new Accounts($db))->summary($guardian)['wp_user_id'];wp_set_current_user($family);
rc($receipts->details($id)['numero']===$data['numero'],'Responsável financeiro acessa o próprio recibo');
rc(MenuPolicy::route('/lancamentos/(?P<id>[1-9][0-9]{0,17})/recibo','GET')&&!MenuPolicy::route('/lancamentos/(?P<id>[1-9][0-9]{0,17})/recibo','POST'),'Permissão do recibo não concede acesso às operações financeiras');
$oldPolicy=get_option('ederp_menu_policy',[]);$disabled=[];foreach(wp_get_current_user()->roles as $role)$disabled[$role]=[];update_option('ederp_menu_policy',$disabled);rd(fn()=>$receipts->download($id),'Revogação do menu bloqueia emissão mesmo por chamada direta');update_option('ederp_menu_policy',$oldPolicy);
wp_set_current_user((int)(new Accounts($db))->summary($other)['wp_user_id']);rd(fn()=>$receipts->download($id),'Outro pagador ou responsável não recebe recibo de um devedor diferente');
wp_set_current_user((int)(new Accounts($db))->summary((int)$student['codpessoa'])['wp_user_id']);rd(fn()=>$receipts->download($id),'Perfil de aluno não acessa recibo financeiro sem autorização');
wp_set_current_user(0);rd(fn()=>$receipts->download($id),'Visitante não acessa recibos');wp_set_current_user(1);
$second=(int)$titles[1]['idlancamento'];$db->update('lancamentos',$second,['status'=>'quitado','saldo_aberto'=>'0.00','valor_baixa'=>'900.00']);rd(fn()=>$receipts->download($second),'Pago importado sem detalhes de baixa exige conferência, sem recibo fictício');
$db->update('lancamentos',$second,['status'=>'aberto','saldo_aberto'=>'900.00','valor_baixa'=>'0.00']);$payment=$finance->pay($second,['valor_pago'=>'900.00','data_pagamento'=>'2026-02-11','forma_pagamento'=>'cartao'],rk());$finance->reverse((int)$payment['idbaixa'],['motivo'=>'Estorno de teste'],rk());rd(fn()=>$receipts->download($second),'Estorno reabre a parcela e impede reemitir quitação');
$replacement=$finance->pay($second,['valor_pago'=>'900.00','data_pagamento'=>'2026-02-12','forma_pagamento'=>'dinheiro'],rk());$effective=$receipts->details($second);rc(count($effective['pagamentos'])===1&&(int)$effective['pagamentos'][0]['idbaixa']===(int)$replacement['idbaixa'],'Recibo após novo pagamento exclui a baixa estornada');
$before=$db->get('lancamentos',$id);$db->update('lancamentos',$id,['status'=>'cancelado']);rd(fn()=>$receipts->download($id),'Parcela cancelada não apresenta recibo de quitação');$db->update('lancamentos',$id,['status'=>$before['status']]);
$third=(int)$titles[2]['idlancamento'];update_option('ederp_pontualidade','900.00');$finance->pay($third,['valor_pago'=>'0.00','data_pagamento'=>'2026-03-10','forma_pagamento'=>'outro'],rk());rc($receipts->details($third)['valor_pago']==='0.00','Quitação integral por desconto registra zero efetivamente pago');
$company=$db->insert('coligadas',['nome'=>'OUTRO COLÉGIO','razao_social'=>'OUTRO COLEGIO LTDA','cnpj'=>'11444777000161']);
[$student2,$enrollment2,$titles2]=Coligadas::within($company,fn()=>fixture($guardian,'-B'));$id2=(int)$titles2[0]['idlancamento'];
Coligadas::within($company,fn()=>$finance->pay($id2,['valor_pago'=>'1000.00','data_pagamento'=>'2026-01-11','forma_pagamento'=>'transferencia'],rk()));
rd(fn()=>$receipts->download($id2),'Gestão precisa selecionar a coligada correta');
wp_set_current_user($family);$secondCompany=$receipts->details($id2);rc($secondCompany['colegio']['cnpj']==='11444777000161'&&$secondCompany['colegio']['nome']==='OUTRO COLÉGIO'&&!$secondCompany['colegio']['logo_padrao'],'Família obtém recibo de outro filho/coligada sem trocar contexto nem usar identidade da primeira');
$saved=$data['colegio'];$saved['logo_id']=700;$saved['logo_padrao']=false;$GLOBALS['receipt_logo_files'][700]='https://example.test/logo.png';rd(fn()=>ReceiptLogo::load($saved),'Logo remota não causa requisições HTTP durante emissão');
// Exercise the actual REST boundary and its private cache headers.
function register_rest_route($namespace,$path,$args){$GLOBALS['receipt_routes'][$path.' '.$args['methods']]=$args;}
class WP_REST_Request extends ArrayObject {public function get_param($key){return $this[$key]??null;}}
class WP_REST_Response {public array $headers=[];public function __construct(public mixed $data,public int $status){}public function header($key,$value){$this->headers[$key]=$value;}}
update_option('ederp_schema_version',EducacionalERP\Infrastructure\Database\Installer::VERSION);
$renewal=new EducacionalERP\Application\RenewalService($db,$ops,$academic,$access);
$controller=new EducacionalERP\Presentation\Rest\Controller($db,$access,$academic,$cat,$finance,new EducacionalERP\Application\ExportService($db),$renewal,$workflow);$controller->register();
$route=$GLOBALS['receipt_routes']['/lancamentos/(?P<id>[1-9][0-9]{0,17})/recibo GET'];$request=new WP_REST_Request(['id'=>$id2]);
rc(($route['permission_callback'])($request)===true,'REST libera recibo ao responsável com menu financeiro');$response=($route['callback'])($request);
rc($response instanceof WP_REST_Response&&$response->status===200&&$response->data['mime']==='application/pdf'&&str_contains($response->headers['Cache-Control'],'private, no-store'),'REST devolve PDF autenticado sem permitir cache compartilhado');
wp_set_current_user((int)(new Accounts($db))->summary($other)['wp_user_id']);rc(is_wp_error(($route['permission_callback'])($request))&&is_wp_error(($route['callback'])($request)),'Permissão e serviço bloqueiam recibos de outra família');
wp_set_current_user(0);rc(is_wp_error(($route['permission_callback'])($request)),'REST rejeita acesso anônimo');
echo 'PAYMENT_RECEIPTS_OK: '.$checks." verificações\n";
