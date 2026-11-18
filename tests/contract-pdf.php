<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Pdf\ContractPdf;
function get_attached_file(int $id):string{return dirname(__DIR__).'/assets/brand/journey.png';}
$school=['logo_id'=>77,'nome'=>'COLÉGIO DE DEMONSTRAÇÃO','rua'=>'Rua das Letras','numero'=>'100','complemento'=>'Bloco A','bairro'=>'Centro','cidade'=>'Curitiba','estado'=>'PR','cep'=>'80000-000','telefone'=>'(41) 3000-0000','site'=>'https://colegio.example.test','email'=>'contato@example.test'];
$text=require __DIR__.'/fixtures/contract-html.php';$text.='<p style="font-family:DejaVu Sans">Fonte incorporada: acentuação, ação e educação — α β γ.</p>';$contract=['numero'=>'DEMO-2027','assinatura_numero_snapshot'=>null,'formato_texto'=>'html','termo_snapshot'=>$text];
$pdf=ContractPdf::render($contract,$school);if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)<10000)throw new RuntimeException('PDF inválido');
if(isset($argv[1]))file_put_contents($argv[1],$pdf);
echo 'CONTRACT_PDF_OK: '.strlen($pdf).' bytes'.PHP_EOL;
