<?php
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Application\PersonNumbering;
$db=new Database($wpdb);$number=new PersonNumbering($db);
$a=$db->insert('pessoas',['nome'=>'IMPORTADA','codpessoa_origem'=>'2']);$b=$db->insert('pessoas',['nome'=>'MANUAL']);$c=$db->insert('pessoas',['nome'=>'ZEROS','codpessoa_origem'=>'000001']);
$db->atomic(fn()=>$number->migrate());
function migCheck($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS: $label\n";}
migCheck($db->get('pessoas',$a)['codigo_pessoa']==='2','migração prioriza código importado');migCheck($db->get('pessoas',$b)['codigo_pessoa']!=='2','migração resolve conflito com código interno sem renumerar PK');migCheck($db->get('pessoas',$c)['codigo_pessoa']==='000001','migração preserva zeros iniciais');$before=$db->rows('SELECT codpessoa,codigo_pessoa FROM '.$db->table('pessoas'));$db->atomic(fn()=>$number->migrate());migCheck($db->rows('SELECT codpessoa,codigo_pessoa FROM '.$db->table('pessoas'))===$before,'migração de códigos é idempotente');
