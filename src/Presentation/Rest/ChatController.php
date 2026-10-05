<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Rest;
use EducacionalERP\Application\ChatService;
use EducacionalERP\Infrastructure\WordPress\MenuPolicy;
use EducacionalERP\Infrastructure\Database\Installer;
use EducacionalERP\Domain\{Input,RuleViolation};
final class ChatController
{
    public function __construct(private ChatService $chat) {}
    public function register():void
    {
        $this->route('/canais','GET',fn($r)=>$this->chat->channels((bool)$r->get_param('gestao')));
        $this->route('/atendentes','GET',fn($r)=>$this->chat->staff((string)$r->get_param('search')));
        $this->route('/canais','POST',fn($r)=>$this->chat->saveChannel($this->data($r),$this->key($r)));
        $this->route('/canais/(?P<id>\d+)/responsaveis','GET',fn($r)=>$this->chat->recipients((int)$r['id'],(string)$r->get_param('search'),(int)$r->get_param('page')));
        $this->route('/conversas','GET',fn($r)=>$this->chat->conversations((int)$r->get_param('before')));
        $this->route('/conversas/(?P<id>\d+)','GET',fn($r)=>$this->chat->detail((int)$r['id']));
        $this->route('/conversas','POST',fn($r)=>$this->chat->start($this->data($r),$this->key($r)));
        $this->route('/conversas/(?P<id>\d+)/mensagens','GET',fn($r)=>$this->chat->messages((int)$r['id'],(int)$r->get_param('after'),(int)$r->get_param('before')));
        $this->route('/conversas/(?P<id>\d+)/mensagens','POST',fn($r)=>$this->chat->send((int)$r['id'],$this->data($r),$this->key($r)));
        $this->route('/conversas/(?P<id>\d+)/leitura','POST',fn($r)=>$this->chat->read((int)$r['id'],(int)($this->data($r)['idmensagem']??0)));
        // JSON/base64 download avoids public media URLs; every download repeats conversation authorization.
        $this->route('/anexos/(?P<id>\d+)','GET',fn($r)=>$this->chat->attachment((int)$r['id']));
    }
    private function data(\WP_REST_Request $r):array
    {
        if(strlen($r->get_body())>7500000)throw new RuleViolation('Requisição acima do limite de anexos.');$d=$r->get_json_params();if(!is_array($d)||array_is_list($d))throw new RuleViolation('Envie um objeto JSON.');return $d;
    }
    private function key(\WP_REST_Request $r):string {return Input::key($r->get_header('Idempotency-Key'));}
    private function route(string $path,string $method,callable $handler):void
    {
        register_rest_route(Controller::NS,'/chat'.$path,['methods'=>$method,'permission_callback'=>static function(){
            if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return new \WP_Error('chat_schema','Entre no admin do WordPress para atualizar o banco do ERP.',['status'=>503]);
            return is_user_logged_in()&&MenuPolicy::can('chat')?true:new \WP_Error('chat_acesso','Acesso ao chat não permitido.',['status'=>403]);
        },'callback'=>function($r)use($handler){try{$result=new \WP_REST_Response($handler($r));$result->header('Cache-Control','private, no-store, max-age=0');return $result;}catch(RuleViolation $e){return new \WP_Error('chat_regra',$e->getMessage(),['status'=>422]);}catch(\Throwable $e){return new \WP_Error('chat_erro','Não foi possível concluir. Tente novamente.',['status'=>500]);}}]);
    }
}
