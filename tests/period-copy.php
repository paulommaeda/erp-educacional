<?php
declare(strict_types=1);
require __DIR__.'/sqlite-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Application\{Operations,CatalogService,PeriodCopyService,ImportService};
use EducacionalERP\Domain\RuleViolation;
Access::install();$db=new Database($wpdb);$ops=new Operations($db);$cat=new CatalogService($db,$ops);$copy=new PeriodCopyService($db,$cat,$ops);$import=new ImportService($db,$cat);$n=0;
function keyC(){return bin2hex(random_bytes(16));}
function okC($b,$text){global $n;if(!$b)throw new RuntimeException($text);$n++;echo "PASS: $text\n";}
function noC($f,$text){try{$f();}catch(RuleViolation $e){okC(true,$text);return;}throw new RuntimeException($text);}
function addC($type,$data){global $cat;return (int)$cat->create($type,$data,keyC())['id'];}
$p=addC('periodos_letivos',['codigo'=>'2026','descricao'=>'Atual','data_inicio'=>'2026-01-01','data_fim'=>'2026-12-31']);
$c=addC('cursos',['codigo'=>'EF','nome'=>'Fundamental']);$t=addC('turnos',['codigo'=>'M','nome'=>'Manhã']);$plan=addC('planos_pagamento',['codperiodo'=>$p,'codigo'=>'AN','nome'=>'Anual','valor_anuidade'=>'1200.50']);
$row=['codigo_periodo'=>'2026','codigo'=>'01A','nome'=>'Primeiro A','codigo_curso'=>'EF','codigo_turno'=>'M','codigo_plano'=>'AN','capacidade'=>'30'];
$a=$import->row('turmas',$row,keyC());$b=$import->row('turmas',array_merge($row,['codigo'=>'02A','nome'=>'Segundo A']),keyC());
okC($a['status']==='criado','importa turma por códigos e vincula plano');okC($import->row('turmas',$row,keyC())['status']==='existente','reimportar preserva turma');
noC(fn()=>$import->row('turmas',array_merge($row,['capacidade'=>20]),keyC()),'conflito não sobrescreve turma');
noC(fn()=>$import->row('turmas',array_merge($row,['codigo_curso'=>'INEXISTENTE']),keyC()),'código inválido é rejeitado');
$d=['codperiodo_origem'=>$p,'novo_periodo'=>['codigo'=>'2027','descricao'=>'Futuro','data_inicio'=>'2027-01-01','data_fim'=>'2027-12-31']];$key=keyC();$result=$copy->copy($d,$key);$dest=$result['codperiodo_destino'];
okC($result['turmas']===2&&$result['planos']===1,'copia duas turmas e um único plano compartilhado');
$newA=$db->get('turmas',$result['mapa_turmas'][(int)$a['id']]);$newB=$db->get('turmas',$result['mapa_turmas'][(int)$b['id']]);
okC($newA['idplano']===$newB['idplano']&&(int)$newA['idplano']!==$plan,'turmas apontam ao mesmo novo plano');
okC((int)$db->get('planos_pagamento',(int)$newA['idplano'])['codperiodo']===$dest,'plano pertence ao destino');
okC($db->get('planos_pagamento',(int)$newA['idplano'])['valor_anuidade']==='1200.50','centavos da anuidade preservados');
okC((int)$db->get('periodos_letivos',$p)['codperiodo_proximo']===$dest,'vincula próximo período');
okC(empty($newA['idturma_proxima'])&&empty($db->get('turmas',(int)$a['id'])['idturma_proxima']),'não inventa progressão de série');
okC($copy->copy($d,$key)===$result,'reenvio idempotente não duplica');
noC(fn()=>$copy->copy(['codperiodo_origem'=>$p,'codperiodo_destino'=>$dest],keyC()),'nova cópia rejeita duplicidades');
noC(fn()=>$copy->copy(['codperiodo_origem'=>$p,'codperiodo_destino'=>$p],keyC()),'rejeita copiar para si mesmo');
noC(fn()=>$import->row('turmas',array_merge($row,['codigo_periodo'=>'2027','codigo'=>'ERR']),keyC()),'importação rejeita plano de outro período');
// Fault after plan insertion: transaction rolls back both destination and plans.
$bad=addC('periodos_letivos',['codigo'=>'2028','descricao'=>'Origem teste','data_inicio'=>'2028-01-01','data_fim'=>'2028-12-31']);
$bp=addC('planos_pagamento',['codperiodo'=>$bad,'codigo'=>'BAD','nome'=>'Plano','valor_anuidade'=>'500.00']);
$bt=addC('turmas',['codperiodo'=>$bad,'codigo'=>'BAD','nome'=>'Turma','idcurso'=>$c,'idturno'=>$t,'idplano'=>$bp,'capacidade'=>20]);$db->update('planos_pagamento',$bp,['ativo'=>0]);
noC(fn()=>$copy->copy(['codperiodo_origem'=>$bad,'novo_periodo'=>['codigo'=>'2029','descricao'=>'Rollback','data_inicio'=>'2029-01-01','data_fim'=>'2029-12-31']],keyC()),'plano inativo interrompe cópia');
okC(!$db->row('SELECT codperiodo FROM '.$db->table('periodos_letivos').' WHERE codigo=%s',['2029']),'falha reverte novo período');
okC(!$db->rows('SELECT * FROM '.$db->table('matriculas'))&&!$db->rows('SELECT * FROM '.$db->table('contratos')),'não copia matrículas nem contratos');
$existingPeriod=addC('periodos_letivos',['codigo'=>'2030','descricao'=>'Destino existente','data_inicio'=>'2030-01-01','data_fim'=>'2030-12-31']);
$duplicate=addC('turmas',['codperiodo'=>$existingPeriod,'codigo'=>'01A','nome'=>'Duplicada','idcurso'=>$c,'idturno'=>$t,'capacidade'=>10]);
noC(fn()=>$copy->copy(['codperiodo_origem'=>$dest,'codperiodo_destino'=>$existingPeriod],keyC()),'conflito em turma reverte planos já inseridos');
okC(!$db->rows('SELECT * FROM '.$db->table('planos_pagamento').' WHERE codperiodo=%d',[$existingPeriod]),'destino não conserva planos de cópia abortada');
$db->query('DELETE FROM '.$db->table('turmas').' WHERE idturma=%d',[$duplicate]);
$r=$copy->copy(['codperiodo_origem'=>$dest,'codperiodo_destino'=>$existingPeriod],keyC());okC($r['turmas']===2&&$r['planos']===1,'copia em período existente sem conflitos');
$u=wp_insert_user(['user_login'=>'secretaria.copy','role'=>'erp_secretaria']);wp_set_current_user($u);noC(fn()=>$copy->copy($d,keyC()),'somente admin copia');noC(fn()=>$import->row('turmas',$row,keyC()),'somente admin importa');echo "$n verificações aprovadas\n";
