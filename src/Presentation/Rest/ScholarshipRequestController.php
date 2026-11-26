<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Rest;

use EducacionalERP\Application\{Coligadas,ScholarshipRequests};
use EducacionalERP\Domain\{Input,RuleViolation,ScholarshipRequestForm};
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\{AdmissionGuard,SchemaMigration,SchoolIdentity};

final class ScholarshipRequestController
{
    public function __construct(private Database $db) {}

    public function register(): void
    {
        foreach(['GET','POST'] as $method){
            register_rest_route(Controller::NS,'/publico/requerimentos-bolsa/(?P<colegio>[1-9][0-9]{0,17})',[
                'methods'=>$method,
                'permission_callback'=>static fn()=>SchemaMigration::pending()
                    ?new \WP_Error('erp_schema','Requerimento temporariamente indisponível.',['status'=>503]):true,
                'callback'=>fn($request)=>$this->handle($request,$method),
            ]);
        }
    }

    private function handle(\WP_REST_Request $request,string $method): mixed
    {
        $previous=$this->db->wp->suppress_errors(true);
        try{
            $company=Input::id($request['colegio']);
            $owner=$this->db->get('coligadas',$company);
            $data=Coligadas::within($company,function()use($request,$method,$company,$owner){
                if(!(int)$owner['ativo']||!ScholarshipRequests::configuration()['ativo'])throw new RuleViolation('O recebimento de requerimentos de bolsa está fechado.');
                $guard=new AdmissionGuard($this->db,'scholarships');
                if($method==='GET')return ['colegio'=>SchoolIdentity::read()['nome'],'logo'=>SchoolIdentity::logo(),
                    'etapas'=>ScholarshipRequestForm::steps(),'token'=>$guard->token($company)];
                if(strlen($request->get_body())>24000)throw new RuleViolation('O requerimento excede o tamanho permitido.');
                $payload=$request->get_json_params();
                if(!is_array($payload)||!is_array($payload['dados']??null)||!is_string($payload['token']??null)||!is_string($payload['website']??null)){
                    throw new RuleViolation('Dados do requerimento inválidos.');
                }
                $guard->verify($company,$payload['token'],$payload['website']);
                return (new ScholarshipRequests($this->db))->submit($payload['dados'],Input::key($request->get_header('Idempotency-Key')));
            });
            $response=new \WP_REST_Response($data,$method==='POST'?202:200);
            $response->header('Cache-Control','private, no-store, max-age=0');
            return $response;
        }catch(RuleViolation $e){
            return new \WP_Error('erp_requerimento_bolsa',$e->getMessage(),['status'=>422]);
        }catch(\Throwable){
            return new \WP_Error('erp_requerimento_bolsa','Não foi possível finalizar o requerimento. Tente novamente.',['status'=>500]);
        }finally{$this->db->wp->suppress_errors($previous);}
    }
}
