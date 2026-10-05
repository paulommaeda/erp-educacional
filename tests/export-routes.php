<?php
require __DIR__.'/integration-routes.php';
foreach(['/exportacao/catalogo'=>'GET','/exportacao/dados'=>'POST','/exportacao/configuracoes'=>'POST'] as $path=>$method){testP(isset($routes[$path][$method]),'rota de exportação registrada '.$path);wp_set_current_user($rfUser);testP($routes[$path][$method]['permission_callback'](null) instanceof WP_Error,'exportação bloqueada ao responsável '.$path);wp_set_current_user(1);testP($routes[$path][$method]['permission_callback'](null)===true,'exportação autorizada ao admin '.$path);}
echo "PASS: autorização REST da exportação\n";
