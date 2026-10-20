<?php
require __DIR__.'/sqlite-bootstrap.php';require __DIR__.'/mail-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Application\{GuardianMail,ChatService};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\RuleViolation;
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_textarea_field($s){return strip_tags($s);}
function keyM(){return bin2hex(random_bytes(20));}
function okM($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS: $m\n";}
function denyM($f,$m){try{$f();}catch(RuleViolation $e){okM(true,$m);return;}throw new RuntimeException($m);}
$db=new Database($wpdb);Access::install();
$child=$db->insert('pessoas',['nome'=>'ALUNO TESTE','data_nascimento'=>'2012-01-01']);
$student=$db->insert('alunos',['codpessoa'=>$child,'ra'=>'001']);
$guardian=$db->insert('pessoas',['nome'=>'MARIA RESPONSAVEL','data_nascimento'=>'1980-01-01','email'=>'familia@example.test']);
$link=$db->insert('aluno_responsaveis',['idaluno'=>$student,'codpessoa_responsavel'=>$guardian,'parentesco'=>'mae','responsavel_academico'=>1,'inicio_vigencia'=>'2020-01-01 00:00:00']);
$staff=wp_insert_user(['user_login'=>'staff.mail','user_email'=>'secretaria@example.test','display_name'=>'Secretaria Teste','role'=>'erp_secretaria']);
$familyUser=wp_insert_user(['user_login'=>'family.mail','user_email'=>'familia@example.test','role'=>'erp_responsavel']);
$db->insert('pessoa_usuarios',['codpessoa'=>$guardian,'wp_user_id'=>$familyUser]);
$mail=new GuardianMail($db);wp_set_current_user($staff);$context=$mail->context($student,$link);
$data=['assunto'=>'Conversa sobre o aluno','corpo'=>"Olá, Maria.\nPodemos conversar?",'versao'=>$context['versao'],'remetente'=>'outra@example.test','destinatario'=>'terceiro@example.test'];
$key=keyM();$mail->send($student,$link,$data,$key);$mail->send($student,$link,$data,$key);
okM(count($GLOBALS['mail_sent'])===1,'repetir uma solicitação aceita não duplica o e-mail');
$sent=$GLOBALS['mail_sent'][0];okM($sent['to']==='familia@example.test','destinatário vem do vínculo, sem aceitar destinatário arbitrário');
okM($sent['mailer']->From==='secretaria@example.test'&&$sent['mailer']->FromName==='Secretaria Teste'&&$sent['mailer']->reply===['secretaria@example.test'=>['secretaria@example.test','Secretaria Teste']],'From e Reply-To usam a conta WordPress autenticada');
okM($sent['mailer']->custom===[]&&mailHookCount()===0,'marcador privado e hook do remetente removidos após envio');
okM($sent['body']===$data['corpo'],'corpo preserva as quebras de linha');
denyM(fn()=>$mail->send($student,$link,array_merge($data,['corpo'=>'Diferente']),$key),'chave não pode ser reutilizada para conteúdo diferente');
denyM(fn()=>$mail->send($student,$link,array_merge($data,['assunto'=>"Contato\r\nBcc: outro@example.test"]),keyM()),'assunto com injeção de cabeçalho é rejeitado');
$attempt=keyM();$nested=null;$GLOBALS['mail_callback']=function()use($mail,$student,$link,$data,$attempt,&$nested){$nested=$mail->send($student,$link,$data,$attempt);};
$mail->send($student,$link,$data,$attempt);
okM(!empty($nested['pendente'])&&count($GLOBALS['mail_sent'])===2,'requisição concorrente encontra reserva sem disparar outra mensagem');
$GLOBALS['mail_ok']=false;$attempt=keyM();$failed=$mail->send($student,$link,$data,$attempt);$mail->send($student,$link,$data,$attempt);
okM(!empty($failed['pode_tentar'])&&count($GLOBALS['mail_sent'])===3,'falha de wp_mail é informada sem reenviar no mesmo pedido');
$GLOBALS['mail_ok']=true;$mail->send($student,$link,$data,keyM());okM(count($GLOBALS['mail_sent'])===4,'nova tentativa explícita pode enviar após falha');
wp_set_current_user($familyUser);denyM(fn()=>$mail->context($student,$link),'família não utiliza envio da escola');
wp_set_current_user($staff);$otherStudent=$db->insert('alunos',['codpessoa'=>$guardian,'ra'=>'002']);
denyM(fn()=>$mail->context($otherStudent,$link),'vínculo de outro aluno não é aceito');
$db->update('pessoas',$guardian,['email'=>'nova.familia@example.test']);
denyM(fn()=>$mail->send($student,$link,$data,keyM()),'alteração de contato exige conferir o destinatário novamente');
$db->update('aluno_responsaveis',$link,['fim_vigencia'=>'2025-01-01 00:00:00']);
denyM(fn()=>$mail->context($student,$link),'vínculo encerrado não pode receber novo e-mail por esta ficha');
