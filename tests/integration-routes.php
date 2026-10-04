<?php
require __DIR__.'/payment-plans.php';
use EducacionalERP\Presentation\Rest\Controller;
use EducacionalERP\Application\ExportService;
use EducacionalERP\Infrastructure\Database\Installer;
$GLOBALS['integration_routes']=[];
function register_rest_route($ns,$path,$args){$GLOBALS['integration_routes'][$path][$args['methods']]=$args;}
update_option('ederp_schema_version',Installer::VERSION);update_option('ederp_schema_error',false);
$controller=new Controller($db,$access,$academic,$cat,new EducacionalERP\Application\FinanceService($db,$ops),new ExportService($db),$renew,$flow);$controller->register();
$routes=$GLOBALS['integration_routes'];
foreach(['/integracao/alunos','/integracao/matriculas','/integracao/financeiro'] as $path){testP(isset($routes[$path]['GET'],$routes[$path]['POST']),'rota bidirecional '.$path);}
wp_set_current_user($rfUser);foreach($routes as $path=>$methods)if(str_starts_with($path,'/integracao/'))foreach($methods as $method=>$route){$permission=$route['permission_callback'](null);testP($permission instanceof WP_Error,'responsável bloqueado em '.$method.' '.$path);}
wp_set_current_user(1);foreach($routes as $path=>$methods)if(str_starts_with($path,'/integracao/'))foreach($methods as $method=>$route)testP($route['permission_callback'](null)===true,'administrador autorizado em '.$method.' '.$path);
echo "PASS: permissões das rotas de integração concluídas\n";
