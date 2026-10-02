<?php
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Presentation\Rest\Controller;
function sanitize_text_field($v){return trim(strip_tags($v));}
class WP_REST_Request{public function __construct(private array $data){}public function get_param($k){return $this->data[$k]??null;}}
$db=new Database($wpdb);$db->insert('pessoas',['nome'=>'MARIA SILVA','codpessoa_origem'=>'000ABC']);$db->insert('pessoas',['nome'=>'JOAO SILVA','codpessoa_origem'=>'OUTRO']);
$c=(new ReflectionClass(Controller::class))->newInstanceWithoutConstructor();$p=new ReflectionProperty(Controller::class,'db');$p->setValue($c,$db);$method=new ReflectionMethod(Controller::class,'listCatalog');
foreach([['search'=>'MARIA','codigo'=>'000abc'],['codigo'=>'1'],['search'=>'SILVA']] as $i=>$filters){$r=$method->invoke($c,'pessoas',new WP_REST_Request($filters));if((int)$r['total']!==($i===2?2:1))throw new RuntimeException('Filtro incorreto');}
$r=$method->invoke($c,'pessoas',new WP_REST_Request(['search'=>'JOAO','codigo'=>'000ABC']));if((int)$r['total']!==0)throw new RuntimeException('Filtros não combinados');echo "PASS: nome, código interno, código de origem e combinação\n";
