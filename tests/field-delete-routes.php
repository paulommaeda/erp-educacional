<?php
require __DIR__.'/integration-routes.php';
foreach(['grupos-campos','campos-adicionais'] as $prefix){$match=null;foreach($routes as $path=>$methods)if(preg_match('~^'.str_replace('~','\\~',$path).'$~','/'.$prefix.'/123/excluir',$m)){$match=$methods['POST']??null;break;}testP($match!==null&&($m['id']??'')==='123','numeric deletion route registered: '.$prefix);wp_set_current_user($rfUser);testP($match['permission_callback'](null) instanceof WP_Error,'guardian denied delete route');wp_set_current_user(1);testP($match['permission_callback'](null)===true,'admin allowed delete route');}
echo "DELETE_ROUTES_OK\n";
