<?php
/** Isolated runtime loader; no global Dompdf/Composer autoloader is installed. */
declare(strict_types=1);
spl_autoload_register(static function(string $class):void{
    $prefix='EducacionalERP\\Vendor\\';if(!str_starts_with($class,$prefix))return;$class=substr($class,strlen($prefix));
    if($class==='Dompdf\\Cpdf'){require_once __DIR__.'/vendor/dompdf/dompdf/lib/Cpdf.php';return;}
    foreach(['Dompdf\\'=>'dompdf/dompdf/src/','FontLib\\'=>'dompdf/php-font-lib/src/FontLib/','Svg\\'=>'dompdf/php-svg-lib/src/Svg/','Masterminds\\'=>'masterminds/html5/src/','Sabberworm\\CSS\\'=>'sabberworm/php-css-parser/src/'] as $namespace=>$path){
        if(!str_starts_with($class,$namespace))continue;$file=__DIR__.'/vendor/'.$path.str_replace('\\','/',substr($class,strlen($namespace))).'.php';if(is_file($file))require_once $file;return;
    }
},true,true);
