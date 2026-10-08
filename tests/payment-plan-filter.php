<?php
require __DIR__.'/class-filter.php';
for($i=1;$i<=22;$i++)$db->insert('planos_pagamento',['codperiodo'=>$p,'codigo'=>'AT'.$i,'nome'=>'PLANO ATUAL '.$i,'valor_anuidade'=>'1000.00']);
$db->insert('planos_pagamento',['codperiodo'=>$other,'codigo'=>'FUT','nome'=>'PLANO FUTURO','valor_anuidade'=>'2000.00']);
$plans=fn($filters)=>$method->invoke($controller,'planos_pagamento',new WP_REST_Request($filters));
foreach([['codperiodo'=>$p],['codperiodo'=>$p,'page'=>2],[]] as $filters){$result=$plans($filters);if($result['total']!==22)throw new RuntimeException('Plan count ignores query/current period');foreach($result['items'] as $row)if((int)$row['codperiodo']!==$p)throw new RuntimeException('Plan from another period leaked');}
if($plans(['codperiodo'=>$other])['total']!==1||$plans(['codperiodo'=>'todos'])['total']!==23||$plans(['codperiodo'=>$p,'search'=>'FUTURO'])['total']!==0)throw new RuntimeException('Incorrect period/search combination');
echo "PAYMENT_PLAN_FILTER_OK\n";
