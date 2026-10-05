<?php
require __DIR__.'/integration-routes.php';
foreach(['/tipos-disciplina','/secretaria/alunos/(?P<id>[1-9][0-9]{0,17})/historicos-anteriores'] as $path){testP(isset($routes[$path]['GET'],$routes[$path]['POST']),'histórico registra rotas '.$path);wp_set_current_user($rfUser);testP($routes[$path]['POST']['permission_callback'](null) instanceof WP_Error,'responsável não altera '.$path);wp_set_current_user(1);testP($routes[$path]['POST']['permission_callback'](null)===true,'admin altera '.$path);}
echo "PASS: autorização REST de históricos\n";
