<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Educbank;
use EducacionalERP\Application\Coligadas;
use EducacionalERP\Domain\{Store,Input,Money,RuleViolation};
use EducacionalERP\Infrastructure\WordPress\Access;
final class Settings
{
    public const PROGRAMS=['EI:1','EI:2','EI:3','EI:4','EI:5','EF:1','EF:2','EF:3','EF:4','EF:5','EF:6','EF:7','EF:8','EF:9','EM:1','EM:2','EM:3','Extras'];
    public const PLANS=['ZeroDefault','Gateway','PreEnrollment','EducbankGateway'];
    public static function read(int $company):array
    {
        return Coligadas::within($company,fn()=>array_replace(['ativo'=>0,'ambiente'=>'sandbox','sandbox'=>['tenant'=>'','token'=>''],'producao'=>['tenant'=>'','token'=>''],'item_nome'=>'MENSALIDADE','plano'=>'','multa'=>'0.00','juros_diarios'=>'0.00','periodos'=>[],'turmas'=>[],'versao'=>0],(array)get_option(Coligadas::option('ederp_educbank'),[])));
    }
    public static function scope(array $s):array{return $s[$s['ambiente']];}
    public static function token(array $s):string
    {
        $raw=base64_decode(self::scope($s)['token'],true);
        if($raw===false||strlen($raw)<29)throw new RuleViolation('Configure o token Educbank deste ambiente.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        if($plain===false)throw new RuleViolation('Não foi possível ler o token Educbank. Cadastre-o novamente.');return $plain;
    }
    private static function encrypt(string $value):string
    {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new RuleViolation('Não foi possível proteger o token.');return base64_encode($iv.$tag.$cipher);
    }
    public static function publicData(int $company):array
    {
        Access::requireAdmin();$s=self::read($company);foreach(['sandbox','producao'] as $env){$s[$env]['token_configurado']=$s[$env]['token']!=='';unset($s[$env]['token']);}return $s;
    }
    public static function save(Store $db,array $d):array
    {
        Access::requireAdmin();$company=Coligadas::current();$lock='ederp_educbank_settings_'.$company;
        if((int)($db->row('SELECT GET_LOCK(%s,5) AS acquired',[$lock])['acquired']??0)!==1)throw new RuleViolation('Configuração em atualização. Tente novamente.');
        try{
            $s=self::read($company);if((int)($d['versao']??-1)!==(int)$s['versao'])throw new RuleViolation('Configuração alterada. Recarregue.');
            if(!in_array($d['ativo']??0,[true,false,0,1,'0','1'],true))throw new RuleViolation('Situação da integração inválida.');$s['ativo']=!empty($d['ativo'])?1:0;$s['ambiente']=$d['ambiente']??'';if(!in_array($s['ambiente'],['sandbox','producao'],true))throw new RuleViolation('Ambiente inválido.');
            foreach(['sandbox','producao'] as $env){$row=$d[$env]??[];if(!is_array($row)||isset($row['tenant'])&&!is_string($row['tenant'])||isset($row['token'])&&!is_string($row['token']))throw new RuleViolation('Credenciais inválidas.');$tenant=trim((string)($row['tenant']??$s[$env]['tenant']));if($tenant!==''&&!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$tenant))throw new RuleViolation('Informe somente o nome do tenant, em minúsculas, sem URL.');
                if($tenant!==$s[$env]['tenant']&&$s[$env]['tenant']!==''){
                    $used=$db->row('SELECT idintegracao FROM '.$db->table('educbank_fila').' WHERE codcoligada=%d AND ambiente=%s AND tenant=%s LIMIT 1',[$company,$env,$s[$env]['tenant']]);if($used)throw new RuleViolation('Há vínculos neste tenant. A mudança exige migração dos vínculos.');
                }
                $s[$env]['tenant']=$tenant;$token=trim((string)($row['token']??''));if($token!==''){if(strlen($token)>8192||preg_match('/[\r\n]/',$token))throw new RuleViolation('Token inválido.');$s[$env]['token']=self::encrypt($token);}
            }
            $s['item_nome']=Input::text($d['item_nome']??'MENSALIDADE',120);$s['plano']=(string)($d['plano']??'');if($s['plano']!==''&&!in_array($s['plano'],self::PLANS,true))throw new RuleViolation('Plano Educbank inválido.');
            foreach(['multa','juros_diarios'] as $k){$c=Money::cents($d[$k]??'0.00');if($c<0||$c>10000)throw new RuleViolation('Percentual deve estar entre 0 e 100.');$s[$k]=Money::format($c);}
            foreach(['periodos'=>'periodos_letivos','turmas'=>'turmas'] as $k=>$table){$maps=$d[$k]??[];if(!is_array($maps)||count($maps)>3000)throw new RuleViolation('Mapeamento inválido.');$s[$k]=[];foreach($maps as $id=>$v){$id=Input::id((string)$id);$row=$db->get($table,$id);if((int)$row['codcoligada']!==$company)throw new RuleViolation('Mapeamento pertence a outra coligada.');
                    if($k==='turmas'){if(!in_array($v,self::PROGRAMS,true))throw new RuleViolation('Código acadêmico Educbank inválido.');$s[$k][$id]=$v;}
                    else{if(!is_array($v))throw new RuleViolation('Mapeamento de período inválido.');$year=Input::id($v['ano']??null);$term=$v['semestre']??null;if($term==='')$term=null;if($year<1900||$year>2200||($term!==null&&!in_array((string)$term,['1','2'],true)))throw new RuleViolation('Ano ou semestre inválido.');$s[$k][$id]=['ano'=>$year,'semestre'=>$term===null?null:(int)$term];}
                }}
            if($s['ativo']&&(self::scope($s)['tenant']===''||self::scope($s)['token']===''||$s['plano']===''))throw new RuleViolation('Informe tenant, token e plano Educbank antes de ativar.');
            $s['versao']++;Coligadas::within($company,fn()=>update_option(Coligadas::option('ederp_educbank'),$s,false));return self::publicData($company);
        }finally{$db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
}
