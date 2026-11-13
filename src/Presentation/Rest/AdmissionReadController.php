<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Rest;
use EducacionalERP\Application\AdmissionReadService;
use EducacionalERP\Domain\RuleViolation;
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\AdmissionReadAccess;
final class AdmissionReadController
{
    public function __construct(private Database $db,private AdmissionReadService $service){}
    public function register():void
    {
        $base='/integracao/requerimentos';$id='(?P<id>[1-9][0-9]{0,17})';
        $this->route($base,fn($r)=>$this->service->collection($r->get_query_params()));
        $this->route($base.'/campos',fn($r)=>$this->service->fields($r->get_query_params()));
        $this->route($base.'/'.$id,fn($r)=>$this->service->detail((int)$r['id'],$r->get_query_params()));
        $this->route($base.'/'.$id.'/arquivos/(?P<arquivo>[1-9][0-9]{0,17})',fn($r)=>$this->service->file((int)$r['id'],(int)$r['arquivo'],$r->get_query_params()));
    }
    private function route(string $path,callable $handler):void
    {
        register_rest_route(Controller::NS,$path,['methods'=>'GET','permission_callback'=>function($r){
            if(!is_user_logged_in())return new \WP_Error('erp_login','Autenticação necessária.',['status'=>401]);
            if(!AdmissionReadAccess::canRead())return new \WP_Error('erp_forbidden','Sem autorização para consultar requerimentos.',['status'=>403]);
            if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))return new \WP_Error('erp_schema','Banco do ERP requer migração.',['status'=>503]);
            $previous=$this->db->wp->suppress_errors(true);
            try{AdmissionReadAccess::company($this->db,$r->get_query_params());return true;}
            catch(RuleViolation $e){return new \WP_Error('erp_coligada','Confira a coligada autorizada para esta integração.',['status'=>403]);}
            catch(\Throwable $e){return new \WP_Error('erp_indisponivel','A consulta está temporariamente indisponível.',['status'=>503]);}
            finally{$this->db->wp->suppress_errors($previous);}
        },'callback'=>function($r)use($handler){
            $previous=$this->db->wp->suppress_errors(true);
            try{$response=new \WP_REST_Response($handler($r),200);$response->header('Cache-Control','private, no-store, max-age=0');$response->header('X-Content-Type-Options','nosniff');return $response;}
            catch(\OutOfBoundsException $e){return new \WP_Error('erp_not_found',$e->getMessage(),['status'=>404]);}
            catch(RuleViolation $e){return new \WP_Error('erp_regra',$e->getMessage(),['status'=>422]);}
            catch(\Throwable $e){return new \WP_Error('erp_consulta','Não foi possível consultar o requerimento.',['status'=>500]);}
            finally{$this->db->wp->suppress_errors($previous);}
        }]);
    }
}
