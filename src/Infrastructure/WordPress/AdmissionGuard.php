<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\{Store,RuleViolation};
/** Signed, short-lived form challenge plus bounded same-IP rates; no public record lookup. */
final class AdmissionGuard
{
    public function __construct(private Store $db){}
    private function ip():string {return hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),wp_salt('auth'));}
    private function signature(string $body):string {return hash_hmac('sha256',$body.'|'.$this->ip(),wp_salt('nonce').'|erp-admissions');}
    public function token(int $company):string {$this->rate('abrir',120);$body=$company.'.'.time().'.'.bin2hex(random_bytes(16));return $body.'.'.$this->signature($body);}
    public function verify(int $company,string $token,string $trap):void
    {
        $origin=(string)($_SERVER['HTTP_ORIGIN']??'');$home=parse_url(home_url('/'));$from=$origin!==''?parse_url($origin):[];
        if($origin!==''&&(!$from||strtolower($from['host']??'')!==strtolower($home['host']??'')||strtolower($from['scheme']??'')!==strtolower($home['scheme']??'')||(int)($from['port']??($from['scheme']==='https'?443:80))!==(int)($home['port']??($home['scheme']==='https'?443:80))))throw new RuleViolation('Envie pelo formulário do colégio.');
        $parts=explode('.',$token);if($trap!==''||count($parts)!==4||!ctype_digit($parts[0])||!ctype_digit($parts[1])||!preg_match('/^[a-f0-9]{32}$/D',$parts[2])||!hash_equals($this->signature(implode('.',array_slice($parts,0,3))),$parts[3])||(int)$parts[0]!==$company||time()-(int)$parts[1]<3||time()-(int)$parts[1]>7200)throw new RuleViolation('O formulário expirou ou não pôde ser validado. Atualize a página e tente novamente.');
        $this->rate('enviar',15);
    }
    private function rate(string $action,int $limit):void
    {
        $bucket='ederp_req_'.hash('sha256',$action.'|'.$this->ip().'|'.intdiv(time(),3600));$lock='erp_req_rate_'.substr($bucket,-30);
        if((int)($this->db->row('SELECT GET_LOCK(%s,3) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Aguarde um momento e tente novamente.');
        try{if(function_exists('wp_cache_delete')){wp_cache_delete('_transient_'.$bucket,'options');wp_cache_delete($bucket,'transient');}$count=(int)get_transient($bucket);if($count>=$limit)throw new RuleViolation('Limite temporário de envios atingido. Aguarde ou entre em contato com a secretaria.');set_transient($bucket,$count+1,3700);}finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
}
