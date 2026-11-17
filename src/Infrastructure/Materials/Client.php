<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\Materials;
use EducacionalERP\Domain\RuleViolation;
final class Client
{
    public static function send(array $settings,array $students,bool $validate):array
    {
        $url=Settings::destination($settings['url']);$password=Settings::password($settings);
        $response=wp_safe_remote_post($url,['timeout'=>30,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576,'headers'=>['Authorization'=>'Basic '.base64_encode($settings['usuario'].':'.$password),'Content-Type'=>'application/json','Accept'=>'application/json'],'body'=>wp_json_encode(['modo'=>'atualizar','validar_apenas'=>$validate,'alunos'=>$students])]);
        if(is_wp_error($response))throw new RuleViolation('O K8 não confirmou a resposta. Consulte o relatório em K8 Produtos → Alunos antes de repetir este envio.');
        $status=(int)wp_remote_retrieve_response_code($response);$data=json_decode(wp_remote_retrieve_body($response),true);
        if($status!==200||!is_array($data)||($data['sucesso']??false)!==true||($data['validar_apenas']??null)!==$validate||($data['modo']??null)!=='atualizar'||(int)($data['alunos']??-1)!==count($students)){
            $message=match($status){401,403=>'Confira o login, a Senha de aplicação e a permissão administrativa no K8.',413=>'O K8 recusou o tamanho do lote.',400,415,422=>'O K8 recusou o lote. Confira seu relatório de importação e os cadastros.',default=>'O K8 não confirmou a conclusão. Consulte o relatório de importação antes de repetir.'};
            if(in_array($status,[400,415,422],true)&&is_string($data['message']??null))$message='O K8 recusou o lote: '.self::safeMessage($data['message'],$password);
            throw new RuleViolation($message);
        }
        $out=array_intersect_key($data,array_flip(['sucesso','validar_apenas','modo','alunos','vinculos','registros','novos','atualizados','importado_em','base_consultada']));foreach($out as $key=>$value)if(is_string($value))$out[$key]=self::safeMessage($value,$password);
        $out['avisos']=[];foreach(array_slice(is_array($data['avisos']??null)?$data['avisos']:[],0,500) as $warning){if(!is_array($warning))continue;$clean=array_intersect_key($warning,array_flip(['line','ra','field','message']));if(isset($clean['message']))$clean['message']=self::safeMessage((string)$clean['message'],$password);$out['avisos'][]=$clean;}
        $out['aviso_base']=isset($data['aviso_base'])?self::safeMessage((string)$data['aviso_base'],$password):null;return $out;
    }
    private static function safeMessage(string $message,string $password):string
    {
        $message=str_replace($password,'[credencial omitida]',$message);$message=preg_replace('/\b[0-9]{3}\.?[0-9]{3}\.?[0-9]{3}-?[0-9]{2}\b/','[CPF omitido]',$message);return mb_substr(trim(strip_tags($message)),0,1000);
    }
}
