<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\{Store,Input,RuleViolation};
use EducacionalERP\Application\Coligadas;
use EducacionalERP\Infrastructure\Database\Installer;
/** A dedicated reader has one explicit company grant and no management capabilities. */
final class AdmissionReadAccess
{
    public const ROLE='erp_leitor_requerimentos';
    public const CAP='erp_ler_requerimentos';
    public const COMPANY='ederp_requerimentos_api_coligada';
    public function __construct(private Store $db){}
    public static function install():void
    {
        if(!get_role(self::ROLE))add_role(self::ROLE,'Integração: leitura de requerimentos',['read'=>true,self::CAP=>true]);
    }
    public static function canRead():bool
    {
        return is_user_logged_in()&&(Access::isAdmin()||(current_user_can(self::CAP)&&(int)get_user_meta(get_current_user_id(),self::COMPANY,true)>0));
    }
    public static function company(Store $db,array $query):int
    {
        if(!self::canRead())throw new RuleViolation('Sem autorização para consultar requerimentos.');
        if(Access::isAdmin())$id=isset($query['codcoligada'])?Input::id($query['codcoligada']):Coligadas::current();
        else{
            $id=Input::id(get_user_meta(get_current_user_id(),self::COMPANY,true));
            if(isset($query['codcoligada'])&&Input::id($query['codcoligada'])!==$id)throw new RuleViolation('Coligada não autorizada para esta integração.');
        }
        $company=$db->get('coligadas',$id);
        if(!Access::isAdmin()&&!(int)$company['ativo'])throw new RuleViolation('Coligada não autorizada para esta integração.');
        return $id;
    }
    public function register():void
    {
        add_action('init',[self::class,'install']);
        add_action('show_user_profile',[$this,'profile']);add_action('edit_user_profile',[$this,'profile']);
        add_action('user_profile_update_errors',[$this,'validateProfile'],10,3);
        add_action('profile_update',[$this,'saveProfile']);
    }
    public function profile(\WP_User $user):void
    {
        if(!Access::isAdmin()||!current_user_can('edit_user',$user->ID))return;
        if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error')){echo '<h2>API de leitura de requerimentos</h2><p>Conclua a atualização do banco em ERP Educacional → Configurações antes de autorizar a integração.</p>';return;}
        echo '<h2>API de leitura de requerimentos</h2><p>Para conectar outro sistema, atribua o perfil <strong>Integração: leitura de requerimentos</strong>, escolha a coligada abaixo e gere uma Senha de aplicativo para este usuário. A autorização inclui dados pessoais, respostas e anexos dos requerimentos desta coligada.</p>';
        wp_nonce_field('ederp_req_api_'.$user->ID,'ederp_req_api_nonce');
        echo '<table class="form-table"><tr><th><label for="ederp-req-api-company">Coligada autorizada</label></th><td><select id="ederp-req-api-company" name="ederp_req_api_company"><option value="">Sem acesso</option>';
        $selected=(string)get_user_meta($user->ID,self::COMPANY,true);
        foreach($this->db->rows('SELECT codcoligada,nome,ativo FROM '.$this->db->table('coligadas').' ORDER BY nome') as $c){$id=(string)$c['codcoligada'];echo '<option value="'.esc_attr($id).'"'.($selected===$id?' selected':'').'>'.esc_html($c['nome'].(!(int)$c['ativo']?' (inativa)':'')).'</option>';}
        echo '</select><p class="description">Sem coligada autorizada, o perfil de integração não consulta dados. Este perfil não aprova, altera nem exclui requerimentos.</p></td></tr></table>';
    }
    private function submittedCompany(int $id):?int
    {
        if(!Access::isAdmin()||!current_user_can('edit_user',$id))throw new RuleViolation('Somente o administrador pode autorizar a integração.');
        $nonce=$_POST['ederp_req_api_nonce']??null;
        if(!is_string($nonce)||!wp_verify_nonce(wp_unslash($nonce),'ederp_req_api_'.$id))throw new RuleViolation('Recarregue a edição do usuário para salvar a autorização.');
        $value=$_POST['ederp_req_api_company']??'';if($value==='')return null;
        if(get_option('ederp_schema_version')!==Installer::VERSION||get_option('ederp_schema_error'))throw new RuleViolation('Conclua a atualização do banco do ERP antes de autorizar a integração.');
        $company=Input::id($value);if(!(int)$this->db->get('coligadas',$company)['ativo'])throw new RuleViolation('Escolha uma coligada ativa para a integração.');return $company;
    }
    public function validateProfile(\WP_Error $errors,bool $update,object $user):void
    {
        if(!$update||!isset($_POST['ederp_req_api_nonce']))return;
        try{$this->submittedCompany((int)$user->ID);}catch(RuleViolation $e){$errors->add('ederp_req_api',$e->getMessage());}
    }
    public function saveProfile(int $id):void
    {
        if(!isset($_POST['ederp_req_api_nonce']))return;
        try{$company=$this->submittedCompany($id);}catch(RuleViolation $e){return;}
        if($company===null)delete_user_meta($id,self::COMPANY);else update_user_meta($id,self::COMPANY,$company);
    }
}
