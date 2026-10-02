<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { exit; }
require_once dirname(__DIR__).'/autoload.php';
use EducacionalERP\Domain\{Money,Input,RuleViolation};
$tests=0;
function expect(bool $ok,string $label): void { global $tests; $tests++; if(!$ok) { throw new RuntimeException('FAIL: '.$label); } }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(RuleViolation $e) { expect(true,$label); return; } expect(false,$label); }
expect(Money::cents('1000.01')===100001,'decimal exato');
expect(Money::format(-123)==='-1.23','formatação negativa');
expect(Money::cents('0.10')+Money::cents('0.20')===30,'sem perda float');
expect(Money::cents('9999999999999.99')===Money::MAX,'limite DECIMAL(15,2)');
expect(Money::split(10000,3)===[3334,3333,3333],'resíduo de centavos');
expect(array_sum(Money::split(1000001,120))===1000001,'total parcelado');
rejects(fn()=>Money::cents(100.0),'rejeita float');
rejects(fn()=>Money::cents('10,00'),'rejeita separador ambíguo');
rejects(fn()=>Money::cents('1.001'),'rejeita precisão indevida');
rejects(fn()=>Money::cents('10000000000000.00'),'rejeita overflow');
rejects(fn()=>Money::positive('-1.00'),'rejeita valor não positivo');
expect(Input::date('2028-02-29')==='2028-02-29','ano bissexto');
rejects(fn()=>Input::date('2026-02-29'),'data inválida');
rejects(fn()=>Input::id('1 OR 1=1'),'id inválido');
rejects(fn()=>Input::key('curta'),'chave curta');
expect(Input::id('12')===12,'id válido');
echo "PASS: $tests testes de domínio\n";
