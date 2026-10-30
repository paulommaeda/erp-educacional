<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Educbank;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Application\EducbankReturns;
use EducacionalERP\Domain\RuleViolation;

/** This one public REST route uses the provider's Basic credential, never a WP password or nonce. */
final class Webhook
{
    private const PATH='/educbank/retorno/(?P<company>[1-9][0-9]{0,17})/(?P<environment>sandbox|producao)';
    private static function header(?object $request=null):string
    {
        $header=$request?(string)$request->get_header('authorization'):(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if($header===''&&isset($_SERVER['PHP_AUTH_USER'],$_SERVER['PHP_AUTH_PW']))$header='Basic '.base64_encode($_SERVER['PHP_AUTH_USER'].':'.$_SERVER['PHP_AUTH_PW']);
        return $header;
    }
    public static function register(Database $db):void
    {
        // Core application-password authentication can reject a non-WP Basic username first.
        // Override only for the exact callback route, POST and a verified integration credential.
        add_filter('rest_authentication_errors',static function($result){
            $route=(string)($GLOBALS['wp']->query_vars['rest_route']??'');
            if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&preg_match('~^/erp-educacional/v1/educbank/retorno/([1-9][0-9]{0,17})/(sandbox|producao)/?$~D',$route,$m)&&ReturnSettings::authenticate((int)$m[1],$m[2],self::header()))return true;
            return $result;
        },100);
        add_action('rest_api_init',static function()use($db){
            register_rest_route('erp-educacional/v1',self::PATH,['methods'=>'POST','permission_callback'=>static function($r){
                if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return new \WP_Error('erp_educbank_schema','O banco do ERP precisa ser atualizado.',['status'=>503]);
                return ReturnSettings::authenticate((int)$r['company'],(string)$r['environment'],self::header($r))?true:new \WP_Error('erp_educbank_auth','Credencial do retorno inválida.',['status'=>401]);
            },'callback'=>static function($r)use($db){
                $previous=$db->wp->suppress_errors(true);
                try{
                    $body=$r->get_body();if(strlen($body)>16384)throw new RuleViolation('Retorno acima do limite permitido.');
                    try{$data=json_decode($body,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException $e){throw new RuleViolation('Envie um objeto JSON válido.');}
                    if(!is_array($data)||array_is_list($data))throw new RuleViolation('Envie um objeto JSON válido.');
                    $result=(new EducbankReturns($db))->receive((int)$r['company'],(string)$r['environment'],$data);
                    $response=new \WP_REST_Response($result,isset($result['idretorno'])?202:200);$response->header('Cache-Control','private, no-store, max-age=0');return $response;
                }catch(RuleViolation $e){return new \WP_Error('erp_educbank_retorno',$e->getMessage(),['status'=>str_contains($e->getMessage(),'ainda não foram vinculados')?409:422]);}
                catch(\Throwable $e){return new \WP_Error('erp_educbank_receber','Não foi possível guardar o retorno. Tente novamente.',['status'=>503]);}
                finally{$db->wp->suppress_errors($previous);}
            }]);
        });
    }
}
