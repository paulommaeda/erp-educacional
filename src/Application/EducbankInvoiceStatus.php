<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input};
use EducacionalERP\Infrastructure\Educbank\Settings;
/** Read-only, batched invoice links and optional management diagnostics. Never sends an invoice. */
final class EducbankInvoiceStatus
{
    public function __construct(private Store $db){}
    public function decorate(array $titles,bool $management=true):array
    {
        $groups=[];foreach($titles as $t)$groups[(int)$t['codcoligada']][Input::id($t['idlancamento'])]=true;
        $scopes=[];$jobs=[];
        foreach($groups as $company=>$ids){
            $s=Settings::read($company);$scope=Settings::scope($s);$scopes[$company]=$s;
            $args=[$company,$s['ambiente'],$scope['tenant'],...array_keys($ids)];
            $rows=$this->db->rows('SELECT idlancamento,idremoto,status,status_remoto,url_fatura FROM '.$this->db->table('educbank_fila')." WHERE codcoligada=%d AND ambiente=%s AND tenant=%s AND tipo='fatura' AND idlancamento IN (".implode(',',array_fill(0,count($ids),'%d')).')',$args);
            foreach($rows as $r)$jobs[$company][(int)$r['idlancamento']]=$r;
        }
        foreach($titles as &$t){
            $company=(int)$t['codcoligada'];$s=$scopes[$company];$job=$jobs[$company][(int)$t['idlancamento']]??null;$state=$job['status']??'nao_enfileirado';
            $integrated=$state==='sincronizado'&&!empty($job['idremoto']);
            $url=$job['url_fatura']??null;
            $valid=is_string($url)&&preg_match('~^https://[^\s<>]+$~D',$url)&&filter_var($url,FILTER_VALIDATE_URL)!==false;
            $parts=$valid?parse_url($url):[];
            $t['url_fatura']=$valid&&!empty($job['idremoto'])&&!isset($parts['user'])&&!isset($parts['pass'])?$url:null;
            if(!$management){
                // A payer change or financial edit must synchronize before exposing a payment link.
                if(!$integrated||in_array($t['status'],['baixado','quitado','cancelado'],true)||in_array($job['status_remoto']??'',['Paid','Canceled'],true))$t['url_fatura']=null;
                unset($t['educbank']);continue;
            }
            $message=$integrated?'Fatura confirmada no Educbank.':match($state){
                'pendente'=>'Aguardando envio ou atualização no Educbank.',
                'erro'=>'O envio ou a atualização no Educbank apresentou erro.',
                'revisao'=>'A integração desta fatura precisa de conferência.',
                'ignorado'=>'Esta fatura não foi enviada ao Educbank.',
                'sincronizado'=>'A confirmação da fatura no Educbank está incompleta.',
                default=>Settings::scope($s)['tenant']===''?'Educbank não configurado nesta coligada.':'Fatura ainda não enfileirada para este ambiente.',
            };
            if(!$s['ativo'])$message.=' Envios automáticos desativados.';
            $t['educbank']=['integrado'=>$integrated,'situacao'=>$state,'ambiente'=>$s['ambiente'],'mensagem'=>$message];
        }unset($t);return $titles;
    }
}
