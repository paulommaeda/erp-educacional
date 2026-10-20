<?php
/** Run with an unmodified FluentSMTP checkout: php tests/fluent-brevo.php /path/to/fluent-smtp */
require __DIR__.'/mail-bootstrap.php';require dirname(__DIR__).'/autoload.php';
use EducacionalERP\Infrastructure\WordPress\UserMail;
$upstream=$argv[1]??'';
if(!is_file($upstream.'/app/Services/Mailer/Providers/SendInBlue/Handler.php'))throw new RuntimeException('Informe o checkout oficial do FluentSMTP como primeiro argumento.');
spl_autoload_register(static function($class)use($upstream){if(str_starts_with($class,'FluentMail\\')){$path=substr($class,11);$path=lcfirst($path);$path=str_replace('\\','/',$path);$path=preg_replace('#^includes/#','includes/',$path);require_once $upstream.'/'.$path.'.php';}});
function fluentMail($class=null){return null;}
function fluentMailGetProvider($email){return $GLOBALS['brevo_driver'];}
function wp_specialchars_decode($value,$flags){return html_entity_decode($value,$flags,'UTF-8');}
function is_wp_error($r){return false;}
function wp_remote_retrieve_body($r){return '{"messageId":"test-only-no-send"}';}
function wp_remote_retrieve_response_code($r){return 201;}
function wp_safe_remote_post($url,$params){
    if($url!=='https://api.brevo.com/v3/smtp/email')throw new RuntimeException('URL inesperada');
    $GLOBALS['brevo_payloads'][]=json_decode($params['body'],true,512,JSON_THROW_ON_ERROR);
    if(!empty($GLOBALS['brevo_throw']))throw new RuntimeException('Falha simulada de transporte');
    return [];
}
final class TestBrevo extends \FluentMail\App\Services\Mailer\Providers\SendInBlue\Handler
{
    public function __construct(){}
    protected function shouldBeLogged($status){return false;}
}
function okB($condition,$message){if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
$settings=['provider'=>'SendInBlue','key_store'=>'db','api_key'=>'test-only','sender_email'=>'site@example.test','sender_name'=>'Colégio','force_from_email'=>'yes','force_from_email_id'=>'site@example.test','force_from_name'=>'yes','return_path'=>'yes'];
$GLOBALS['brevo_driver']=$driver=(new TestBrevo())->setSettings($settings);
$GLOBALS['mail_transport']=static fn($mailer)=>(new \FluentMail\App\Services\Mailer\FluentPHPMailer($mailer))->send();
$sender=['nome'=>'Secretaria Teste','email'=>'secretaria@example.test'];$mail=new UserMail();
// Reproduce the reported failure first, using the real FluentSMTP provider/wrapper.
wp_mail('familia@example.test','Sem correção','Teste',['From: secretaria@example.test']);
okB($GLOBALS['brevo_payloads'][0]['sender']['email']==='site@example.test','FluentSMTP com remetente forçado reproduz o problema');
// A different message launched by another mail hook must not claim the ERP sender.
$foreign=static function($mailer){if($mailer->Subject==='Com correção')wp_mail('outro@example.test','Notificação','Outro envio');};
add_action('phpmailer_init',$foreign,1);
$mail->send($sender,'familia@example.test','Com correção',"Olá, família.\nMensagem.");
$payload=end($GLOBALS['brevo_payloads']);
okB($payload['sender']===['name'=>'Secretaria Teste','email'=>'secretaria@example.test'],'payload real do Brevo recebe nome e e-mail do usuário apesar do remetente forçado');
okB($payload['replyTo']==$payload['sender']&&$payload['textContent']==="Olá, família.\nMensagem.",'resposta e conteúdo de texto preservados no payload');
okB($GLOBALS['brevo_payloads'][1]['sender']['email']==='site@example.test','mensagem de outro hook mantém o remetente próprio');
okB($driver->getSetting()===$settings&&mailHookCount()===1,'configuração do provedor e hooks restaurados após sucesso');
remove_action('phpmailer_init',$foreign,1);
wp_mail('familia@example.test','Depois do ERP','Outro envio');
okB(end($GLOBALS['brevo_payloads'])['sender']['email']==='site@example.test','próximo envio comum mantém o remetente configurado no FluentSMTP');
$GLOBALS['brevo_throw']=true;$caught=false;
try{$mail->send($sender,'familia@example.test','Falha','Teste');}catch(RuntimeException $e){$caught=true;}
okB($caught&&$driver->getSetting()===$settings&&mailHookCount()===0,'exceção também restaura provedor e remove hook temporário');
$GLOBALS['brevo_throw']=false;$GLOBALS['mail_transport']=static fn($mailer)=>false;
okB(!$mail->send($sender,'familia@example.test','Rejeitado','Teste')&&$driver->getSetting()===$settings&&mailHookCount()===0,'rejeição mantém retorno de falha e restaura configuração');
$GLOBALS['brevo_driver']=false;unset($GLOBALS['mail_transport']);
$mail->send($sender,'familia@example.test','Sem FluentSMTP','Teste');$last=end($GLOBALS['mail_sent']);
okB($last['mailer']->From===$sender['email']&&$last['mailer']->FromName===$sender['nome']&&$last['mailer']->custom===[],'WordPress sem FluentSMTP também mantém o usuário como remetente sem expor marcador');
