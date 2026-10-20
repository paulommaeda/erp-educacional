<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;

/** Preserve the account sender through WordPress and FluentSMTP for one message. */
final class UserMail
{
    public function send(array $sender,string $to,string $subject,string $body):bool
    {
        $marker=bin2hex(random_bytes(16));$restore=null;
        $configure=static function($mailer)use($sender,$marker,&$restore):void{
            $matches=false;
            foreach($mailer->getCustomHeaders() as $header){
                if(strcasecmp($header[0],'X-EDERP-Guardian-Mail')===0&&hash_equals($marker,(string)$header[1])){$matches=true;break;}
            }
            if(!$matches)return; // Other messages triggered by mail hooks keep their own sender.
            $mailer->clearCustomHeader('X-EDERP-Guardian-Mail');
            $mailer->setFrom($sender['email'],$sender['nome'],false);
            $mailer->clearReplyTos();$mailer->addReplyTo($sender['email'],$sender['nome']);
            if(!function_exists('fluentMailGetProvider'))return;
            $driver=fluentMailGetProvider($sender['email']);
            if(!$driver||!is_callable([$driver,'getSetting'])||!is_callable([$driver,'setSettings']))return;
            $settings=$driver->getSetting();
            if(!is_array($settings))return;
            // Brevo reads sender_email from the cached provider, after phpmailer_init.
            // Change only that in-memory instance and restore it even when delivery throws.
            $restore=static fn()=>$driver->setSettings($settings);
            $scoped=array_replace($settings,['sender_email'=>$sender['email'],'sender_name'=>$sender['nome'],'force_from_email'=>'no','force_from_name'=>'no']);
            unset($scoped['force_from_email_id']);
            $driver->setSettings($scoped);
        };
        $headers=['From: '.$sender['email'],'Reply-To: '.$sender['email'],'Content-Type: text/plain; charset=UTF-8','X-EDERP-Guardian-Mail: '.$marker];
        add_action('phpmailer_init',$configure,PHP_INT_MAX);
        try{return (bool)wp_mail($to,$subject,$body,$headers);}
        finally{remove_action('phpmailer_init',$configure,PHP_INT_MAX);if($restore)$restore();}
    }
}
