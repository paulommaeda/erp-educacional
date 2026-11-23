<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Signature;

use EducacionalERP\Application\{Coligadas,ContractSignatures};
use EducacionalERP\Domain\{RuleViolation,Store};
use EducacionalERP\Infrastructure\WordPress\SchemaMigration;

final class Notifications
{
    public const HOOK='ederp_consultar_assinaturas';

    public static function register(Store $db):void
    {
        add_action('rest_api_init',static function()use($db){self::routes($db);});
        add_action('init',static function(){if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+300,'hourly',self::HOOK);});
        add_action(self::HOOK,static function()use($db){if(!SchemaMigration::pending())(new ContractSignatures($db))->reconcile();});
    }

    public static function routes(Store $db):void
    {
        register_rest_route('erp-educacional/v1','/assinatura-eletronica/retorno',[
            'methods'=>'POST',
            'permission_callback'=>static fn($req)=>self::authorize($db,$req),
            'callback'=>static function($req)use($db){
                $message=json_decode($req->get_body(),true);
                $known=self::locate($db,$message['external_ref']);
                if(!$known)return new \WP_Error('erp_assinatura_envio','Envio indisponível.',['status'=>409]);
                try{
                    Coligadas::within((int)$known['codcoligada'],fn()=>(new ContractSignatures($db))->receive($known,$message));
                    $response=new \WP_REST_Response(null,204);
                    $response->header('Cache-Control','no-store, private');return $response;
                }catch(RuleViolation $e){return new \WP_Error('erp_assinatura_conciliar',$e->getMessage(),['status'=>409]);}
                catch(\Throwable $e){return new \WP_Error('erp_assinatura_persistir','Retorno pendente de processamento.',['status'=>503]);}
            }
        ]);
    }

    private static function locate(Store $db,string $ref):?array
    {
        return $db->row('SELECT idenvio,idcontrato,codcoligada,api_base,referencia,original_hash FROM '.$db->table('assinatura_envios').' WHERE referencia=%s',[$ref]);
    }

    private static function authorize(Store $db,\WP_REST_Request $req)
    {
        if(SchemaMigration::pending())return new \WP_Error('erp_schema','Migração do ERP pendente.',['status'=>503]);
        $denied=new \WP_Error('erp_assinatura_autenticacao','Aviso não autorizado.',['status'=>401]);
        $body=$req->get_body();$timestamp=(string)$req->get_header('X-Edu-Sign-Timestamp');$signature=(string)$req->get_header('X-Edu-Sign-Signature');
        if(strlen($body)>16384||!preg_match('/^[0-9]{10}$/D',$timestamp)||abs(time()-(int)$timestamp)>300||!preg_match('/^sha256=[a-f0-9]{64}$/D',$signature))return $denied;
        $data=json_decode($body,true,32);
        if(!is_array($data)||!is_string($data['external_ref']??null)||strlen($data['external_ref'])>160)return $denied;
        $row=self::locate($db,$data['external_ref']);
        if(!$row)return $denied;
        $settings=Coligadas::within((int)$row['codcoligada'],fn()=>Settings::read());
        if($settings['base']!==$row['api_base']||$settings['segredo']==='')return $denied;
        try{$secret=Settings::reveal($settings['segredo']);}catch(RuleViolation $e){return $denied;}
        return hash_equals('sha256='.hash_hmac('sha256',$timestamp.'.'.$body,$secret),$signature)?true:$denied;
    }
}
