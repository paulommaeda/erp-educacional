<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\RuleViolation;
final class SchoolIdentity
{
    public const OPTION='ederp_school_identity';
    public const FIELDS=['nome'=>'Nome do colégio','razao_social'=>'Razão social','cnpj'=>'CNPJ','telefone'=>'Telefone','email'=>'E-mail','site'=>'Site','rua'=>'Rua','numero'=>'Número','complemento'=>'Complemento','bairro'=>'Bairro','cep'=>'CEP','cidade'=>'Cidade','estado'=>'Estado','pais'=>'País'];
    public const COLORS=['cor_primaria'=>'Botões e links','cor_destaque'=>'Destaque','cor_secundaria'=>'Cor secundária','cor_texto'=>'Texto','cor_fundo'=>'Fundo da página','cor_superficie'=>'Cartões e menus'];
    public static function defaults():array {return array_fill_keys(array_keys(self::FIELDS),'')+['logo_id'=>0,'cor_primaria'=>'#087862','cor_destaque'=>'#26ba9b','cor_secundaria'=>'#f8ef70','cor_texto'=>'#193d38','cor_fundo'=>'#f5f8f5','cor_superficie'=>'#ffffff'];}
    public static function read():array {
        $saved=get_option(self::OPTION,[]);$data=array_merge(self::defaults(),is_array($saved)?array_intersect_key($saved,self::defaults()):[]);
        if(!$data['nome'])$data['nome']='Colégio Journey';
        foreach(self::COLORS as $key=>$label)if(!preg_match('/^#[a-f0-9]{6}$/i',(string)$data[$key]))$data[$key]=self::defaults()[$key];
        return $data;
    }
    public static function logo():string { $d=self::read();if($d['logo_id'])return (string)(wp_get_attachment_image_url((int)$d['logo_id'],'full')?:'');return get_option(self::OPTION,false)===false?plugins_url('assets/brand/journey.png',EDERP_FILE):''; }
    public static function version():string {return hash('sha256',wp_json_encode(self::read()));}
    public static function validate(array $d):array {
        $result=[];foreach(self::FIELDS as $key=>$label){if(!is_string($d[$key]??''))throw new RuleViolation('Campo inválido: '.$label);$value=sanitize_text_field($d[$key]??'');if(strlen($value)>250)throw new RuleViolation('Campo muito longo: '.$label);$result[$key]=$value;}
        if(!$result['nome'])throw new RuleViolation('Informe o nome do colégio.');
        if($result['email']&&!is_email($result['email']))throw new RuleViolation('E-mail inválido.');
        if($result['site']&&(!filter_var($result['site'],FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($result['site'],PHP_URL_SCHEME)),['http','https'],true)))throw new RuleViolation('Informe o site completo com https:// ou http://.');
        foreach(self::COLORS as $key=>$label){$value=$d[$key]??self::defaults()[$key];if(!is_string($value)||!preg_match('/^#[a-f0-9]{6}$/i',$value))throw new RuleViolation('Cor inválida: '.$label);$result[$key]=strtolower($value);}
        $logo=filter_var($d['logo_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($logo===false||($logo&&!self::validLogo($logo)))throw new RuleViolation('Selecione uma imagem válida para o logo.');$result['logo_id']=$logo;return $result;
    }
    public static function validLogo(int $id):bool {return get_post_type($id)==='attachment'&&in_array(get_post_mime_type($id),['image/png','image/jpeg','image/webp','image/gif'],true);}
    public static function save(array $d):array {
        Access::requireAdmin();if(!is_string($d['version']??null)||!hash_equals(self::version(),$d['version']))throw new RuleViolation('Configuração alterada. Recarregue antes de salvar.');
        update_option(self::OPTION,self::validate($d),false);return self::payload();
    }
    public static function payload():array {Access::requireAdmin();return ['data'=>self::read(),'logo_url'=>self::logo(),'version'=>self::version(),'fields'=>self::FIELDS,'colors'=>self::COLORS,'customizer_url'=>add_query_arg(['url'=>\EducacionalERP\Presentation\Portal\Portal::url(),'autofocus[section]'=>'ederp_school'],admin_url('customize.php'))];}
    private static function contrast(string $hex):string { $rgb=sscanf($hex,'#%02x%02x%02x');$v=array_map(static function($n){$s=$n/255;return $s<=0.04045?$s/12.92:(($s+0.055)/1.055)**2.4;},$rgb);return 0.2126*$v[0]+0.7152*$v[1]+0.0722*$v[2]>0.179?'#111111':'#ffffff'; }
    public static function css():string {
        $d=self::read();$map=['--j-action'=>$d['cor_primaria'],'--erp-accent'=>$d['cor_primaria'],'--j-green'=>$d['cor_destaque'],'--j-yellow'=>$d['cor_secundaria'],'--j-ink'=>$d['cor_texto'],'--j-dark'=>$d['cor_texto'],'--j-paper'=>$d['cor_fundo'],'--j-surface'=>$d['cor_superficie'],'--j-onaction'=>self::contrast($d['cor_primaria'])];
        $css='';foreach($map as $k=>$v)$css.=$k.':'.$v.';';
        return ':root:root,.ederp{'.$css.'--j-soft:color-mix(in srgb,var(--j-green) 12%,var(--j-surface));--j-line:color-mix(in srgb,var(--j-ink) 18%,var(--j-surface));--j-muted:color-mix(in srgb,var(--j-ink) 75%,var(--j-surface));}';
    }
    public static function register():void {
        add_action('customize_register',static function($manager){
            if(!Access::isAdmin())return;$manager->add_section('ederp_school',['title'=>'ERP — Identidade do colégio','priority'=>35,'capability'=>'manage_options']);$defaults=self::read();
            foreach(self::COLORS as $key=>$label){$id=self::OPTION.'['.$key.']';$manager->add_setting($id,['type'=>'option','capability'=>'manage_options','default'=>$defaults[$key],'transport'=>'refresh','sanitize_callback'=>static fn($v)=>is_string($v)&&preg_match('/^#[a-f0-9]{6}$/i',$v)?strtolower($v):null]);$manager->add_control(new \WP_Customize_Color_Control($manager,$id,['section'=>'ederp_school','label'=>$label]));}
            $id=self::OPTION.'[nome]';$manager->add_setting($id,['type'=>'option','capability'=>'manage_options','default'=>$defaults['nome'],'transport'=>'refresh','sanitize_callback'=>static fn($v)=>is_string($v)&&trim($v)!==''?sanitize_text_field($v):null]);$manager->add_control($id,['section'=>'ederp_school','label'=>'Nome do colégio','type'=>'text']);
            $id=self::OPTION.'[logo_id]';$manager->add_setting($id,['type'=>'option','capability'=>'manage_options','default'=>$defaults['logo_id'],'transport'=>'refresh','sanitize_callback'=>static fn($v)=>(int)$v===0||self::validLogo((int)$v)?absint($v):null]);$manager->add_control(new \WP_Customize_Media_Control($manager,$id,['section'=>'ederp_school','label'=>'Logo do colégio','mime_type'=>'image']));
        });
    }
}
