<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Rest;
use EducacionalERP\Application\{Coligadas,AdmissionSettings,PublicAdmissions};
use EducacionalERP\Domain\{AdmissionForm,RuleViolation,Input};
use EducacionalERP\Infrastructure\Database\{Database,Installer};
use EducacionalERP\Infrastructure\WordPress\{AdmissionGuard,SchoolIdentity};
/** Only schema/challenge and POST submission are anonymous. All management uses Controller. */
final class AdmissionController
{
    public function __construct(private Database $db,private PublicAdmissions $service){}
    public function register():void
    {
        $path='/publico/requerimentos/(?P<colegio>[1-9][0-9]{0,8})';
        foreach(['GET','POST'] as $method)register_rest_route(Controller::NS,$path,['methods'=>$method,'permission_callback'=>fn()=>get_option('ederp_schema_version')===Installer::VERSION&&!get_option('ederp_schema_error')?true:new \WP_Error('erp_schema','Requerimento temporariamente indisponível. Procure a secretaria.',['status'=>503]),'callback'=>fn($r)=>$this->handle($r,$method)]);
    }
    public function handle(\WP_REST_Request $r,string $method):mixed
    {
        $previous=$this->db->wp->suppress_errors(true);
        try{
            $company=Input::id($r['colegio']);$c=$this->db->get('coligadas',$company);if(!(int)$c['ativo'])throw new RuleViolation('Requerimento indisponível para este colégio.');
            $result=Coligadas::within($company,function()use($r,$method,$company){
                $settings=AdmissionSettings::read();if(!(int)$settings['ativo'])throw new RuleViolation('O recebimento de requerimentos está encerrado.');$guard=new AdmissionGuard($this->db);
                if($method==='GET'){$civil=$this->db->rows('SELECT idestado_civil,nome FROM '.$this->db->table('estados_civis').' ORDER BY nome');return ['colegio'=>SchoolIdentity::read()['nome'],'logo'=>SchoolIdentity::logo(),'configuracao'=>$settings,'campos'=>AdmissionForm::fields(),'estados_civis'=>$civil,'token'=>$guard->token($company),'versao'=>'1'];}
                if((int)($_SERVER['CONTENT_LENGTH']??0)>16000000)throw new RuleViolation('O envio supera 15 MB. Reduza os anexos.');
                $raw=$r->get_param('dados');if(!is_string($raw)||strlen($raw)>100000)throw new RuleViolation('Dados do requerimento inválidos ou acima do limite.');
                $guard->verify($company,(string)$r->get_param('token'),(string)$r->get_param('website'));
                try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuleViolation('Dados do requerimento inválidos.');}if(!is_array($data)||array_is_list($data))throw new RuleViolation('Dados do requerimento inválidos.');
                return $this->service->submit($data,$r->get_file_params(),Input::key($r->get_header('Idempotency-Key')));
            });
            $response=new \WP_REST_Response($result,$method==='POST'?202:200);$response->header('Cache-Control','private, no-store, max-age=0');$response->header('X-Content-Type-Options','nosniff');return $response;
        }catch(RuleViolation $e){return new \WP_Error('erp_requerimento',$e->getMessage(),['status'=>422]);}catch(\Throwable $e){return new \WP_Error('erp_requerimento','Não foi possível concluir. Confira o envio ou procure a secretaria.',['status'=>500]);}finally{$this->db->wp->suppress_errors($previous);}
    }
}
