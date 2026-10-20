<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use EducacionalERP\Domain\RuleViolation;
final class SchoolIdentity
{
    public const OPTION='ederp_school_identity';
    public const FIELDS=['nome'=>'Nome do colégio','descricao'=>'Descrição do site','razao_social'=>'Razão social','cnpj'=>'CNPJ','telefone'=>'Telefone','email'=>'E-mail','site'=>'Site','rua'=>'Rua','numero'=>'Número','complemento'=>'Complemento','bairro'=>'Bairro','cep'=>'CEP','cidade'=>'Cidade','estado'=>'Estado','pais'=>'País'];
    public const COLORS=['cor_primaria'=>'Botões e links','cor_destaque'=>'Destaque','cor_secundaria'=>'Cor secundária','cor_texto'=>'Texto','cor_fundo'=>'Fundo da página','cor_superficie'=>'Cartões e menus'];
    public static function option():string {return \EducacionalERP\Application\Coligadas::option(self::OPTION);}
    public const LOGIN_COLORS=['login_fundo'=>'Cor do fundo / início do degradê','login_fundo_final'=>'Final do degradê','login_form_fundo'=>'Fundo do formulário','login_texto'=>'Texto do formulário','login_botao'=>'Botão de entrar','login_botao_texto'=>'Texto do botão','login_campo_fundo'=>'Fundo dos campos','login_campo_borda'=>'Borda dos campos','login_painel_texto'=>'Texto da apresentação'];
    public const LOGIN_TEXTS=['login_etiqueta'=>'Etiqueta ao lado da logo','login_chamada_painel'=>'Chamada da apresentação','login_titulo_painel'=>'Título da apresentação','login_descricao_painel'=>'Descrição da apresentação','login_rodape_painel'=>'Frase no rodapé da apresentação','login_boas_vindas'=>'Chamada do formulário','login_titulo_form'=>'Título do formulário','login_descricao_form'=>'Descrição do formulário','login_botao_rotulo'=>'Texto do botão de entrar','login_rodape'=>'Rodapé do login'];
    public static function loginDefaults():array {return ['login_cores'=>'colegio','login_etiqueta'=>'Portal educacional','login_chamada_painel'=>'Parceria que transforma','login_titulo_painel'=>"Família e escola.\nJuntos em cada\nnova conquista.",'login_descricao_painel'=>'Acompanhe a vida escolar, converse com a equipe e cuide de cada etapa da jornada de quem você ama.','login_rodape_painel'=>'Aprender · Crescer · Transformar','login_boas_vindas'=>'Bem-vindo ao seu portal','login_titulo_form'=>'Vamos caminhar juntos?','login_descricao_form'=>'Acesse sua conta para acompanhar a vida escolar e ficar perto do que acontece no colégio.','login_botao_rotulo'=>'Entrar na minha conta','login_rodape'=>'{colegio} · Educação que aproxima.','login_painel_texto'=>'#ffffff','login_tipo'=>'solida','login_imagem_id'=>0,'login_angulo'=>135,'login_largura'=>460,'login_raio'=>24,'login_fundo'=>'#f5f8f5','login_fundo_final'=>'#087862','login_form_fundo'=>'#ffffff','login_texto'=>'#193d38','login_botao'=>'#087862','login_botao_texto'=>'#ffffff','login_campo_fundo'=>'#ffffff','login_campo_borda'=>'#b9ccc3'];}
    public static function defaults():array {return self::loginDefaults()+array_fill_keys(array_keys(self::FIELDS),'')+['logo_id'=>0,'cor_primaria'=>'#087862','cor_destaque'=>'#26ba9b','cor_secundaria'=>'#f8ef70','cor_texto'=>'#193d38','cor_fundo'=>'#f5f8f5','cor_superficie'=>'#ffffff'];}
    public static function read():array {
        $saved=get_option(self::option(),[]);$data=array_merge(self::defaults(),is_array($saved)?array_intersect_key($saved,self::defaults()):[]);
        // Existing custom login colors keep their appearance; untouched defaults follow the school palette.
        if(is_array($saved)&&!array_key_exists('login_cores',$saved))foreach(self::LOGIN_COLORS as $key=>$label)if(isset($saved[$key])&&strtolower((string)$saved[$key])!==self::loginDefaults()[$key]){$data['login_cores']='personalizadas';break;}
        if(!in_array($data['login_cores'],['colegio','personalizadas'],true))$data['login_cores']='colegio';
        foreach(self::LOGIN_TEXTS as $key=>$label)if(!is_string($data[$key]))$data[$key]=self::loginDefaults()[$key];
        if(!$data['nome'])$data['nome']='Colégio Journey';
        foreach(self::COLORS+self::LOGIN_COLORS as $key=>$label)if(!preg_match('/^#[a-f0-9]{6}$/i',(string)$data[$key]))$data[$key]=self::defaults()[$key];
        if(is_array($saved)&&!array_key_exists('login_painel_texto',$saved))$data['login_painel_texto']=self::contrast($data['login_fundo']);
        return $data;
    }
    public static function logo():string { $d=self::read();if($d['logo_id'])return (string)(wp_get_attachment_image_url((int)$d['logo_id'],'full')?:'');return get_option(self::option(),false)===false?plugins_url('assets/brand/journey.png',EDERP_FILE):''; }
    public static function version():string {return hash('sha256',wp_json_encode(self::read()));}
    public static function validate(array $d):array {
        $result=[];foreach(self::FIELDS as $key=>$label){if(!is_string($d[$key]??''))throw new RuleViolation('Campo inválido: '.$label);$value=sanitize_text_field($d[$key]??'');if(strlen($value)>250)throw new RuleViolation('Campo muito longo: '.$label);$result[$key]=$value;}
        if(!$result['nome'])throw new RuleViolation('Informe o nome do colégio.');
        if($result['email']&&!is_email($result['email']))throw new RuleViolation('E-mail inválido.');
        if($result['site']&&(!filter_var($result['site'],FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($result['site'],PHP_URL_SCHEME)),['http','https'],true)))throw new RuleViolation('Informe o site completo com https:// ou http://.');
        foreach(self::COLORS as $key=>$label){$value=$d[$key]??self::defaults()[$key];if(!is_string($value)||!preg_match('/^#[a-f0-9]{6}$/i',$value))throw new RuleViolation('Cor inválida: '.$label);$result[$key]=strtolower($value);}
        $logo=filter_var($d['logo_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($logo===false||($logo&&!self::validLogo($logo)))throw new RuleViolation('Selecione uma imagem válida para o logo.');$result['logo_id']=$logo;
        $login=array_intersect_key(array_merge(self::read(),$d),self::loginDefaults());
        if(!in_array($login['login_cores'],['colegio','personalizadas'],true))throw new RuleViolation('Escolha a origem das cores do login.');
        foreach(self::LOGIN_TEXTS as $key=>$label){
            if(!is_string($login[$key]))throw new RuleViolation('Texto inválido: '.$label);
            $text=in_array($key,['login_titulo_painel','login_descricao_painel','login_descricao_form'],true)?sanitize_textarea_field($login[$key]):sanitize_text_field($login[$key]);
            if(strlen($text)>(in_array($key,['login_descricao_painel','login_descricao_form'],true)?1800:500))throw new RuleViolation('Texto muito longo: '.$label);
            if(in_array($key,['login_titulo_form','login_botao_rotulo'],true)&&$text==='')throw new RuleViolation('Preencha: '.$label);
            $login[$key]=$text;
        }
        if(!in_array($login['login_tipo'],['solida','degrade','imagem'],true))throw new RuleViolation('Escolha um tipo de fundo válido.');
        foreach(self::LOGIN_COLORS as $k=>$label){if(!is_string($login[$k])||!preg_match('/^#[a-f0-9]{6}$/i',$login[$k]))throw new RuleViolation('Cor inválida: '.$label);$login[$k]=strtolower($login[$k]);}
        foreach(['login_angulo'=>[0,360],'login_largura'=>[320,640],'login_raio'=>[0,48],'login_imagem_id'=>[0,PHP_INT_MAX]] as $k=>$range){$v=filter_var($login[$k],FILTER_VALIDATE_INT,['options'=>['min_range'=>$range[0],'max_range'=>$range[1]]]);if($v===false)throw new RuleViolation('Valor inválido: '.$k);$login[$k]=$v;}
        if($login['login_imagem_id']&&!self::validLogo($login['login_imagem_id']))throw new RuleViolation('Selecione uma imagem válida para o fundo.');
        if($login['login_tipo']==='imagem'&&!$login['login_imagem_id'])throw new RuleViolation('Selecione a imagem de fundo do login.');
        return $result+$login;
    }
    public static function validLogo(int $id):bool {return get_post_type($id)==='attachment'&&in_array(get_post_mime_type($id),['image/png','image/jpeg','image/webp','image/gif'],true);}
    public static function save(array $d):array {
        Access::requireAdmin();if(!is_string($d['version']??null)||!hash_equals(self::version(),$d['version']))throw new RuleViolation('Configuração alterada. Recarregue antes de salvar.');
        $validated=self::validate($d);update_option(self::option(),$validated,false);update_option('blogname',$validated['nome']);update_option('blogdescription',$validated['descricao']);return self::payload();
    }
    public static function payload():array {Access::requireAdmin();return ['data'=>self::read(),'logo_url'=>self::logo(),'login_colors'=>self::LOGIN_COLORS,'login_texts'=>self::LOGIN_TEXTS,'login_image_url'=>self::loginImage(),'version'=>self::version(),'fields'=>self::FIELDS,'colors'=>self::COLORS,'customizer_url'=>add_query_arg(['url'=>\EducacionalERP\Presentation\Portal\Portal::url(),'autofocus[section]'=>'ederp_school'],admin_url('customize.php'))];}
    public static function loginImage():string { $id=(int)self::read()['login_imagem_id'];return $id&&self::validLogo($id)?(string)(wp_get_attachment_image_url($id,'full')?:''):''; }
    public static function loginStyle():string {
        $d=self::loginAppearance();$bg=$d['login_fundo'];
        if($d['login_tipo']==='degrade')$bg='linear-gradient('.(int)$d['login_angulo'].'deg,'.$bg.','.$d['login_fundo_final'].')';
        elseif($d['login_tipo']==='imagem'&&self::loginImage())$bg='linear-gradient(180deg,'.$bg.'e6,'.$bg.'99),'.$bg.' url("'.esc_url(self::loginImage()).'") center / cover no-repeat';
        $style='--erp-login-bg:'.$bg.';--erp-login-width:'.(int)$d['login_largura'].'px;--erp-login-radius:'.(int)$d['login_raio'].'px;';
        foreach(self::LOGIN_COLORS as $k=>$label)$style.='--'.str_replace('_','-',$k).':'.$d[$k].';';
        return $style;
    }
    /** The stored custom palette stays intact when the school palette is selected. */
    public static function loginAppearance():array {
        $d=self::read();if($d['login_cores']==='personalizadas')return $d;
        $d['login_fundo']=$d['cor_texto'];$d['login_fundo_final']=$d['cor_primaria'];$d['login_form_fundo']=$d['cor_superficie'];$d['login_texto']=$d['cor_texto'];$d['login_botao']=$d['cor_primaria'];$d['login_botao_texto']=self::contrast($d['cor_primaria']);$d['login_campo_fundo']=$d['cor_superficie'];$d['login_painel_texto']=self::contrast($d['cor_texto']);
        $ink=sscanf($d['cor_texto'],'#%02x%02x%02x');$surface=sscanf($d['cor_superficie'],'#%02x%02x%02x');$border=[];foreach($ink as $i=>$n)$border[]=(int)round($n*.24+$surface[$i]*.76);$d['login_campo_borda']=sprintf('#%02x%02x%02x',...$border);
        return $d;
    }
    private static function contrast(string $hex):string { $rgb=sscanf($hex,'#%02x%02x%02x');$v=array_map(static function($n){$s=$n/255;return $s<=0.04045?$s/12.92:(($s+0.055)/1.055)**2.4;},$rgb);return 0.2126*$v[0]+0.7152*$v[1]+0.0722*$v[2]>0.179?'#111111':'#ffffff'; }
    public static function css():string {
        $d=self::read();$map=['--j-action'=>$d['cor_primaria'],'--erp-accent'=>$d['cor_primaria'],'--j-green'=>$d['cor_destaque'],'--j-yellow'=>$d['cor_secundaria'],'--j-ink'=>$d['cor_texto'],'--j-dark'=>$d['cor_texto'],'--j-paper'=>$d['cor_fundo'],'--j-surface'=>$d['cor_superficie'],'--j-onaction'=>self::contrast($d['cor_primaria'])];
        $css='';foreach($map as $k=>$v)$css.=$k.':'.$v.';';
        return ':root:root,.ederp{'.$css.'--j-soft:color-mix(in srgb,var(--j-green) 12%,var(--j-surface));--j-line:color-mix(in srgb,var(--j-ink) 18%,var(--j-surface));--j-muted:color-mix(in srgb,var(--j-ink) 75%,var(--j-surface));}';
    }
    public static function register():void {
        add_filter('option_blogname',static fn($value)=>self::read()['nome']);
        add_filter('option_blogdescription',static fn($value)=>self::read()['descricao']);
        add_action('customize_register',static function($manager){
            if(!Access::isAdmin())return;$manager->add_section('ederp_school',['title'=>'ERP — Identidade do colégio','priority'=>35,'capability'=>'manage_options']);$defaults=self::read();
            foreach(self::COLORS as $key=>$label){$id=self::option().'['.$key.']';$manager->add_setting($id,['type'=>'option','capability'=>'manage_options','default'=>$defaults[$key],'transport'=>'refresh','sanitize_callback'=>static fn($v)=>is_string($v)&&preg_match('/^#[a-f0-9]{6}$/i',$v)?strtolower($v):null]);$manager->add_control(new \WP_Customize_Color_Control($manager,$id,['section'=>'ederp_school','label'=>$label]));}
            $id=self::option().'[nome]';$manager->add_setting($id,['type'=>'option','capability'=>'manage_options','default'=>$defaults['nome'],'transport'=>'refresh','sanitize_callback'=>static fn($v)=>is_string($v)&&trim($v)!==''?sanitize_text_field($v):null]);$manager->add_control($id,['section'=>'ederp_school','label'=>'Nome do colégio','type'=>'text']);
            $id=self::option().'[logo_id]';$manager->add_setting($id,['type'=>'option','capability'=>'manage_options','default'=>$defaults['logo_id'],'transport'=>'refresh','sanitize_callback'=>static fn($v)=>(int)$v===0||self::validLogo((int)$v)?absint($v):null]);$manager->add_control(new \WP_Customize_Media_Control($manager,$id,['section'=>'ederp_school','label'=>'Logo do colégio','mime_type'=>'image']));
        });
    }
}
