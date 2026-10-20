<?php
/** WordPress hook/PHPMailer boundary for mail tests. No real messages are sent. */
$GLOBALS['mail_hooks']=[];$GLOBALS['mail_sent']=[];$GLOBALS['mail_ok']=true;
function add_action($hook,$callback,$priority=10,$args=1){$GLOBALS['mail_hooks'][$hook][$priority][]=[$callback,$args];}
function remove_action($hook,$callback,$priority=10){foreach($GLOBALS['mail_hooks'][$hook][$priority]??[] as $i=>$item)if($item[0]===$callback)unset($GLOBALS['mail_hooks'][$hook][$priority][$i]);}
function do_action($hook,...$args){$priorities=$GLOBALS['mail_hooks'][$hook]??[];ksort($priorities);foreach($priorities as $callbacks)foreach($callbacks as [$callback,$count])$callback(...array_slice($args,0,$count));}
function mailHookCount(){return array_sum(array_map('count',$GLOBALS['mail_hooks']['phpmailer_init']??[]));}
final class TestMailer
{
    public $From='wordpress@example.test',$FromName='WordPress',$CharSet='UTF-8',$ContentType='text/plain',$Subject='',$Body='',$AltBody='',$Sender='';
    public array $custom=[],$reply=[],$to=[];
    public function setFrom($email,$name='',$auto=true){$this->From=$email;$this->FromName=$name;}
    public function addReplyTo($email,$name=''){$this->reply[$email]=[$email,$name];}
    public function clearReplyTos(){$this->reply=[];}
    public function getReplyToAddresses(){return $this->reply;}
    public function getCustomHeaders(){return $this->custom;}
    public function clearCustomHeaders(){$this->custom=[];}
    public function addCustomHeader($name,$value){$this->custom[]=[$name,$value];}
    public function clearCustomHeader($name){$this->custom=array_values(array_filter($this->custom,fn($h)=>strcasecmp($h[0],$name)!==0));}
    public function getToAddresses(){return $this->to;}
    public function getCcAddresses(){return [];}
    public function getBccAddresses(){return [];}
    public function getAttachments(){return [];}
    public function send(){return $GLOBALS['mail_ok'];}
}
function wp_mail($to,$subject,$body,$headers=[]){
    $mailer=new TestMailer();$mailer->to=[[$to,'']];$mailer->Subject=$subject;$mailer->Body=$body;
    foreach($headers as $header){[$name,$value]=explode(':',$header,2);$value=trim($value);
        if($name==='From')$mailer->setFrom($value);
        elseif($name==='Reply-To')$mailer->addReplyTo($value);
        elseif($name!=='Content-Type')$mailer->custom[]=[$name,$value];
    }
    do_action('phpmailer_init',$mailer);
    $GLOBALS['mail_sent'][]=compact('to','subject','body','headers','mailer');
    if(isset($GLOBALS['mail_callback'])){$callback=$GLOBALS['mail_callback'];unset($GLOBALS['mail_callback']);$callback();}
    return isset($GLOBALS['mail_transport'])?($GLOBALS['mail_transport'])($mailer):$mailer->send();
}
