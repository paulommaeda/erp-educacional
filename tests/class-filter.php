<?php
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Presentation\Rest\Controller;
use EducacionalERP\Infrastructure\WordPress\Access;
function sanitize_text_field($v){return trim(strip_tags($v));}
class WP_REST_Request{public function __construct(private array $data){}public function get_param($k){return $this->data[$k]??null;}}
Access::install();$db=new Database($wpdb);
$p=$db->insert('periodos_letivos',['codigo'=>'2026','descricao'=>'Atual','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']);$other=$db->insert('periodos_letivos',['codigo'=>'2027','descricao'=>'Futuro','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31']);update_option('ederp_current_period',$p);
$c=$db->insert('cursos',['codigo'=>'A','nome'=>'Curso A']);$c2=$db->insert('cursos',['codigo'=>'B','nome'=>'Curso B']);$t=$db->insert('turnos',['codigo'=>'M','nome'=>'Manhã']);
for($i=25;$i>=1;$i--)$db->insert('turmas',['codperiodo'=>$p,'codigo'=>(string)$i,'nome'=>sprintf('TURMA %02d',$i),'idcurso'=>$c,'idturno'=>$t,'capacidade'=>30]);
$db->insert('turmas',['codperiodo'=>$other,'codigo'=>'X','nome'=>'OUTRO PERIODO','idcurso'=>$c,'idturno'=>$t,'capacidade'=>30]);$db->insert('turmas',['codperiodo'=>$p,'codigo'=>'B','nome'=>'OUTRO CURSO','idcurso'=>$c2,'idturno'=>$t,'capacidade'=>30]);
$controller=(new ReflectionClass(Controller::class))->newInstanceWithoutConstructor();(new ReflectionProperty(Controller::class,'db'))->setValue($controller,$db);$method=new ReflectionMethod(Controller::class,'listCatalog');
$read=fn($filters)=>$method->invoke($controller,'turmas',new WP_REST_Request($filters));
$a=$read(['codperiodo'=>$p,'idcurso'=>$c]);$b=$read(['codperiodo'=>$p,'idcurso'=>$c,'page'=>2]);
if((int)$a['total']!==25||count($a['items'])!==20||$a['items'][0]['nome']!=='TURMA 01'||$b['items'][0]['nome']!=='TURMA 21')throw new RuntimeException('Ordenação ou paginação incorreta');
if((int)$read(['codperiodo'=>$p,'idcurso'=>$c,'search'=>'25'])['total']!==1)throw new RuntimeException('Busca não combinada');
if((int)$read(['codperiodo'=>$p])['total']!==26)throw new RuntimeException('Todos os cursos incorreto');
echo "PASS: curso e período combinados, busca e ordem alfabética antes da paginação\n";
