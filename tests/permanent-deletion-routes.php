<?php
require __DIR__.'/integration-routes.php';
foreach(['/exclusao-definitiva/tipos'=>'GET','/exclusao-definitiva/registros'=>'GET','/exclusao-definitiva/previa'=>'POST','/exclusao-definitiva/excluir'=>'POST'] as $path=>$method){
    testP(isset($routes[$path][$method]),'rota de exclusão registrada '.$path);wp_set_current_user($rfUser);testP($routes[$path][$method]['permission_callback'](null) instanceof WP_Error,'exclusão definitiva bloqueada ao responsável '.$path);wp_set_current_user(1);testP($routes[$path][$method]['permission_callback'](null)===true,'exclusão definitiva autorizada ao administrador '.$path);
}
echo "PERMANENT_DELETION_ROUTES_OK\n";
