<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Input,RuleViolation};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy};
/** One verified guardian recipient; the authenticated WordPress account supplies From and Reply-To. */
final class GuardianMail
{
    public function __construct(private Database $db){}
    public function context(int $student,int $link):array
    {
        if(!Access::isAdmin()&&(!current_user_can('erp_gerenciar_pessoas')||!MenuPolicy::any(['alunos','matriculas'])))throw new RuleViolation('Seu perfil não pode enviar e-mails aos responsáveis.');
        $a=$this->db->get('alunos',$student);if((int)$a['codcoligada']!==Coligadas::current())throw new RuleViolation('Selecione a coligada deste aluno.');
        $v=$this->db->table('aluno_responsaveis');$p=$this->db->table('pessoas');
        $target=$this->db->row("SELECT v.versao AS vinculo_versao,p.versao AS pessoa_versao,p.codpessoa,p.nome,p.email FROM $v v JOIN $p p ON p.codpessoa=v.codpessoa_responsavel WHERE v.idvinculo=%d AND v.idaluno=%d AND v.codcoligada=%d AND v.fim_vigencia IS NULL AND v.inicio_vigencia<=UTC_TIMESTAMP()",[$link,$student,(int)$a['codcoligada']]);
        if(!$target)throw new RuleViolation('Responsável não vinculado ao aluno ou vínculo encerrado.');
        $user=wp_get_current_user();$from=(string)($user->user_email??'');$to=(string)$target['email'];
        if(!is_email($from)||preg_match('/[\r\n]/',$from))throw new RuleViolation('Cadastre um e-mail válido na sua conta antes de enviar mensagens.');
        if(!is_email($to)||preg_match('/[\r\n]/',$to))throw new RuleViolation('O responsável não possui um e-mail válido cadastrado.');
        $name=trim((string)preg_replace('/[\r\n<>]+/',' ',sanitize_text_field((string)$user->display_name)));
        $child=$this->db->get('pessoas',(int)$a['codpessoa']);
        return ['remetente'=>['nome'=>$name,'email'=>$from],'destinatario'=>['codpessoa'=>$target['codpessoa'],'nome'=>$target['nome'],'email'=>$to],'aluno'=>['nome'=>$child['nome'],'ra'=>$a['ra']],'versao'=>hash('sha256',wp_json_encode([$student,$link,$target,get_current_user_id(),$name,$from]))];
    }
    public function send(int $student,int $link,array $data,string $key):array
    {
        $context=$this->context($student,$link);
        if(!is_string($data['versao']??null)||!hash_equals($context['versao'],$data['versao']))throw new RuleViolation('O contato ou remetente foi alterado. Reabra o modal antes de enviar.');
        if(!is_string($data['assunto']??null)||!is_string($data['corpo']??null))throw new RuleViolation('Preencha assunto e corpo do e-mail.');
        if(preg_match('/[\r\n]/',$data['assunto']))throw new RuleViolation('O assunto deve ocupar apenas uma linha.');
        $subject=trim(sanitize_text_field($data['assunto']));$body=trim(sanitize_textarea_field($data['corpo']));
        if($subject===''||strlen($subject)>250||$body===''||strlen($body)>20000)throw new RuleViolation('Preencha o assunto e a mensagem dentro do limite dos campos.');
        $token=bin2hex(random_bytes(16));$op=new Operations($this->db);
        // Commit the reservation BEFORE wp_mail: concurrent retries never dispatch a second copy.
        $state=$this->db->atomic(fn()=>$op->run($key,'email_responsavel',['idaluno'=>$student,'idvinculo'=>$link,'assunto'=>$subject,'corpo'=>$body,'versao'=>$context['versao']],fn()=>['estado'=>'enviando','token'=>$token]));
        if($state['estado']==='enviado')return ['enviado'=>true,'mensagem'=>'E-mail encaminhado para envio pelo WordPress.'];
        if($state['estado']==='falhou')return ['enviado'=>false,'pode_tentar'=>true,'mensagem'=>'O WordPress não conseguiu enviar. Você pode tentar novamente.'];
        if(!hash_equals($state['token'],$token))return ['enviado'=>false,'pendente'=>true,'mensagem'=>'Este envio já foi iniciado. Aguarde a confirmação antes de solicitar outro.'];
        $sender=$context['remetente'];$recipient=$context['destinatario'];
        $headers=['From: '.$sender['nome'].' <'.$sender['email'].'>','Reply-To: '.$sender['nome'].' <'.$sender['email'].'>','Content-Type: text/plain; charset=UTF-8'];
        try{$sent=(bool)wp_mail($recipient['email'],$subject,$body,$headers);}catch(\Throwable $e){$sent=false;}
        $this->db->atomic(function()use($key,$token,$sent,$link,$sender,$recipient,$subject){
            $row=$this->db->row('SELECT idoperacao FROM '.$this->db->table('operacoes').' WHERE chave=%s FOR UPDATE',[$key]);
            $this->db->update('operacoes',(int)$row['idoperacao'],['resposta_json'=>wp_json_encode(['estado'=>$sent?'enviado':'falhou','token'=>$token])]);
            $this->db->audit('aluno_responsaveis',$link,'enviar_email',null,['remetente'=>$sender['email'],'destinatario'=>$recipient['email'],'assunto'=>$subject,'enviado'=>$sent],$key);
        });
        return $sent?['enviado'=>true,'mensagem'=>'E-mail encaminhado para envio pelo WordPress.']:['enviado'=>false,'pode_tentar'=>true,'mensagem'=>'O WordPress não conseguiu enviar. Você pode tentar novamente.'];
    }
}
