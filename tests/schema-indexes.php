<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\SchemaIndexes;
function checkI(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function rowsI(array $indexes):array{$rows=[];foreach($indexes as $name=>$def)foreach($def[1] as $i=>$col)$rows[]=['Key_name'=>$name,'Non_unique'=>$def[0]?0:1,'Seq_in_index'=>$i+1,'Column_name'=>$col,'Sub_part'=>null,'Index_type'=>'BTREE'];return $rows;}
function generatedI(array $lines):array{$all=[];foreach($lines as $line){preg_match('/^(UNIQUE )?KEY `([^`]+)` \(([^)]+)\)$/',$line,$m);$all[$m[2]]=[!empty($m[1]),explode(',',$m[3])];}return $all;}
$schema=json_decode(file_get_contents(dirname(__DIR__).'/src/Infrastructure/Database/schema.json'),true);$meta=$schema['contratos'];
$old=['uq_0'=>[true,['numero']],'ix_0'=>[false,['idmatricula']],'ix_1'=>[false,['codpessoa_rf_original']],'ix_2'=>[false,['codpessoa_rf_atual']]];
$lines=SchemaIndexes::lines($meta,rowsI($old));$new=generatedI($lines);
foreach($old as $name=>$def)checkI($new[$name]===$def,"preserva $name sem mudar suas colunas");
checkI(count($new)===6,'adiciona índices de geração e plano');
$existing=$old;foreach($new as $name=>$def){if(isset($existing[$name]))checkI($existing[$name]===$def,'nenhum nome reutilizado para definição diferente');$existing[$name]=$def;}
SchemaIndexes::verify($meta,rowsI($existing));checkI(SchemaIndexes::lines($meta,rowsI($existing))===$lines,'reexecução não cria novos nomes');
$current=['uq_0'=>[true,['numero']],'ix_0'=>[false,['parcelas_geradas','idmatricula']],'ix_1'=>[false,['idmatricula']],'ix_2'=>[false,['codpessoa_rf_original']],'ix_3'=>[false,['codpessoa_rf_atual']],'ix_4'=>[false,['idplano']]];
checkI(generatedI(SchemaIndexes::lines($meta,rowsI($current)))===$current,'preserva instalação recente sem índices duplicados');
try{SchemaIndexes::verify($meta,rowsI($old));throw new LogicException('aceitou ausência');}catch(RuntimeException $e){checkI(true,'bloqueia sucesso se índice está ausente');}
foreach($schema as $table=>$def){$fresh=SchemaIndexes::lines($def,[]);$rows=rowsI(generatedI($fresh));SchemaIndexes::verify($def,$rows);checkI(SchemaIndexes::lines($def,$rows)===$fresh,"instalação nova e repetição: $table");}
$single=['unique'=>[],'indexes'=>[['vencimento']],'fks'=>[]];$fresh=generatedI(SchemaIndexes::lines($single,[]));$name=array_key_first($fresh);$upper=strtoupper($name);$collided=generatedI(SchemaIndexes::lines($single,rowsI([$upper=>[false,['status']]])));checkI(!array_key_exists($name,$collided),'nomes de índices não colidem ignorando maiúsculas');
$installer=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Database/Installer.php');checkI(!str_contains($installer,'$lines=array_merge($lines,SchemaIndexes::lines'),'dbDelta não recria índices secundários legados');checkI(str_contains($installer,'SchemaIndexes::ensure('),'instalador garante índices explicitamente após colunas');
