<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,AdmissionForm,CadastroText,RuleViolation};
/** Explicit template from the supplied Fluent Forms export and flattened ffapi response. */
final class ExternalAdmissionTemplate
{
    public const VERSION=1;
    public const MODE='requerimento';
    public static function known(string $url):bool {return strtolower((string)parse_url($url,PHP_URL_HOST))==='crm.colegiojourney.com.br'&&rtrim((string)parse_url($url,PHP_URL_PATH),'/')==='/wp-json/ffapi/v1/requerimentos';}
    public const FILE_FIELDS=['requerimento_arquivo_foto'=>'Foto (endereço de origem)','requerimento_arquivo_guarda'=>'Documento de guarda (endereço de origem)','requerimento_arquivo_adocao'=>'Documento de adoção (endereço de origem)'];
    public static function ensureFields(Store $db):void
    {
        $db->atomic(function()use($db){foreach(self::FILE_FIELDS as $key=>$name)if(!$db->row('SELECT idcampo FROM '.$db->table('campos_adicionais').' WHERE chave=%s',[$key]))$db->insert('campos_adicionais',['chave'=>$key,'nome'=>$name,'tipo'=>'texto_longo','secao'=>'outros','exibir_pessoa'=>0,'exibir_aluno'=>1,'area_aluno'=>'dados_pessoais','opcoes'=>'','condicao_exibicao'=>'preenchido','ordem'=>190,'ativo'=>1]);});
    }
    public static function mappings():array
    {
        $out=[];$add=static function(string $slot,string $dest,array $sources,string $default='')use(&$out){$out[]=['pessoa'=>$slot,'destino'=>$dest,'origem'=>array_shift($sources),'alternativas'=>$sources,'padrao'=>$default];};
        $sets=[
            'aluno'=>['nome'=>'Nome_Completo_do_Aluno','data_nascimento'=>'data_de_nascimento_aluno','cpf'=>'cpf_aluno','rg'=>'rg_aluno','email'=>'email_aluno','telefone'=>'telefone_aluno','sexo'=>'sexo_aluno','local_nascimento'=>'local_de_nascimento_aluno','uf_nascimento'=>'uf_nascimento_aluno','raca_etnia'=>'raca_etinia_aluno','nacionalidade'=>'nacionalidade_aluno','religiao'=>'religiao','igreja'=>'igreja','endereco'=>'endereco_aluno','bairro'=>'bairro_aluno'],
        ];
        foreach(['pai'=>['nome_do_pai','pai'],'mae'=>['nome_da_mae','mae'],'academico'=>['nome_da_reposnavel_ausencia','reposnavel_ausencia'],'financeiro'=>['nome_da_reponsavel_financeiro','reponsavel_financeiro']] as $slot=>[$name,$suffix]){
            $sets[$slot]=['nome'=>$name,'data_nascimento'=>'data_nascimento_'.$suffix,'cpf'=>'cpf_'.$suffix,'rg'=>'rg_'.$suffix,'email'=>'email_'.$suffix,'telefone'=>'celualr_'.$suffix,'local_nascimento'=>'cidade_natal_'.$suffix,'uf_nascimento'=>'uf_natal_'.$suffix,'nacionalidade'=>'naturalidade_'.$suffix,'grau_instrucao'=>'grau_de_instrucao_'.$suffix,'profissao'=>'profissao_'.$suffix,'idestado_civil'=>'estado_civil_'.$suffix,'religiao'=>'religiao_'.$suffix,'igreja'=>'igreja_'.$suffix,'raca_etnia'=>$slot==='mae'?'input_radio_9':'raca_'.$suffix,'endereco'=>'endereco_'.$suffix,'bairro'=>$slot==='academico'?'responsavel_ausencia_bairro':'bairro_'.$suffix];
        }
        foreach($sets as $slot=>$fields){foreach($fields as $dest=>$source){if(in_array($dest,['endereco','bairro'],true))continue;
                $sources=$dest==='nome'?[$source.'_first_name',$source,$source.'.first_name']:[$source];$sources[]=$slot.'.'.$dest;
                if($dest==='telefone')$sources[]='celular_'.($slot==='aluno'?'aluno':($slot==='academico'?'responsavel_ausencia':($slot==='financeiro'?'responsavel_financeiro':$slot)));
                $add($slot,$dest,$sources);
            }
            $address=$fields['endereco'];foreach(['rua'=>'address_line_1','complemento'=>'address_line_2','cidade'=>'city','estado'=>'state','cep'=>'zip','pais'=>'country','numero'=>'numero'] as $dest=>$source)$add($slot,$dest,[$address.'_'.$source,$address.'.'.$source,$slot.'.'.$dest],$dest==='pais'?'BR':'');
            $add($slot,'bairro',[$fields['bairro'],$address.'_bairro',$address.'.bairro',$slot.'.bairro']);
            if($slot!=='aluno')$add($slot,'extra:empresa',['empresa_'.($slot==='academico'?'reposnavel_ausencia':($slot==='financeiro'?'reponsavel_financeiro':$slot)),$slot.'.empresa']);
        }
        foreach(AdmissionForm::fields() as $f){$key=$f['chave'];if($key==='empresa')continue;
            $source=preg_replace('/^(saude_|aprendizagem_|familia_)/','',$key);$sources=match($key){'interesse_curso'=>['nivel_de_ensino','curso_pretendido'],'interesse_serie'=>['turmas_fund_1','turmas_fund','turmas_medio','ano_serie'],'interesse_turno'=>['turno','turno_1'],'indicado_por'=>['names_first_name','names.first_name','names'],'familia_input_radio'=>['documentacao_guarda','input_radio'],default=>[$source]};
            $sources[]=$key;$add('aluno','extra:'.$key,$sources);
        }
        foreach(['foto'=>['image-upload','foto_aluno'],'guarda'=>['arquivo_guarda'],'adocao'=>['arquivo_adocao']] as $key=>$sources)$add('aluno','extra:requerimento_arquivo_'.$key,$sources);
        $add('aluno','extra:contatos_emergencia',['contatos_emergencia']);
        foreach(['responsavel_academico'=>['responsavel_academico'],'responsavel_financeiro'=>['input_radio_8','responsavel_financeiro'],'retiradas'=>['input_radio_7','autorizados_retirada'],'contatos_emergencia'=>['contatos_emergencia'],'endereco_pai'=>['input_radio_4'],'endereco_mae'=>['input_radio_5'],'parentesco_outro'=>['grau_de_parentesco_responsavel_ausencia']] as $key=>$sources)$add('requerimento','req:'.$key,$sources);
        return $out;
    }
    public static function value(array $row,array $m):mixed
    {
        $paths=[(string)($m['origem']??''),...($m['alternativas']??[])];
        foreach($paths as $path){if($path==='')continue;foreach(['','response.','fields.','dados.'] as $prefix){$v=ExternalPeopleApi::value($row,$prefix.$path);if($v===null||$v===''||$v===[])continue;
                if(is_array($v)&&!array_is_list($v)&&($m['destino']??'')==='nome')$v=trim(implode(' ',array_filter([$v['first_name']??'',$v['middle_name']??'',$v['last_name']??''])));
                if(($m['destino']??'')==='nome'&&str_ends_with($path,'_first_name')){$last=ExternalPeopleApi::value($row,$prefix.substr($path,0,-11).'_last_name');if(is_string($last)&&trim($last)!=='')$v=trim((string)$v.' '.$last);}
                return $v;
        }}return $m['padrao']??'';
    }
    public static function text(mixed $v):string {if(is_array($v)){$parts=[];array_walk_recursive($v,static function($item)use(&$parts){if(is_scalar($item))$parts[]=(string)$item;});return implode("\n",$parts);}return is_scalar($v)?trim((string)$v):'';}
    public static function normalize(Store $db,string $dest,mixed $value):string
    {
        $v=self::text($value);if($v==='')return '';
        if($dest==='sexo')return ['M'=>'MASCULINO','F'=>'FEMININO'][strtoupper($v)]??CadastroText::upper($v);
        if($dest==='data_nascimento'){if(preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D',$v,$m))return "$m[3]-$m[2]-$m[1]";if(preg_match('/^\d{4}-\d{2}-\d{2}[T ]/',$v))return substr($v,0,10);}
        if(in_array($dest,['estado','uf_nascimento'],true)){static $br=null;$br??=json_decode(file_get_contents(dirname(__DIR__,2).'/assets/localidades/brasil.json'),true);foreach($br as $uf=>$item)if(CadastroText::upper(remove_accents($v))===CadastroText::upper(remove_accents($item['nome']))||strtoupper($v)===$uf||preg_match('/\('.$uf.'\)$/i',$v))return $uf;}
        if($dest==='idestado_civil'){$name=['S'=>'SOLTEIRO','C'=>'CASADO','D'=>'DIVORCIADO','V'=>'VIÚVO','SE'=>'SEPARADO'][strtoupper($v)]??$v;try{return (string)(new CivilStatus($db))->resolve($name)['idestado_civil'];}catch(RuleViolation $e){return $v;}}
        if($dest==='extra:saude_brupo_sanguineo_1'&&strtolower($v)==='positvo')return 'Positivo';
        return $v;
    }
    public static function request(Store $db,array $raw,array $people,array $mapping):array
    {
        $d=['pessoas'=>[],'campos'=>[],'retiradas'=>[],'contatos_emergencia'=>[],'responsavel_academico'=>'','responsavel_financeiro'=>'','declaracao'=>false,'declaracao_nome'=>'','declaracao_versao'=>'api'];$form=[];
        foreach($mapping as $m)if($m['pessoa']==='requerimento'&&str_starts_with($m['destino'],'req:'))$form[substr($m['destino'],4)]=self::value($raw,$m);
        $native=array_column(AdmissionForm::fields($db),null,'chave');
        foreach($people as $slot=>$p){if($slot==='requerimento')continue;unset($p['sugestoes']);$extra=$p['campos_adicionais']??[];unset($p['campos_adicionais']);$p['empresa']=$extra['empresa']??'';unset($extra['empresa']);
            if($slot==='aluno'){foreach($extra as $k=>$v){if($k==='contatos_emergencia')continue;$d['campos'][$k]=isset($native[$k])&&$native[$k]['tipo']==='multiplos'?($v===''?[]:explode("\n",$v)):$v;}}else $p['campos_adicionais']=$extra;
            $d['pessoas'][$slot]=$p;
        }
        foreach(['pai','mae'] as $slot)if(isset($d['pessoas'][$slot])&&str_contains(CadastroText::upper(self::text($form['endereco_'.$slot]??'')),'MESMO'))foreach(['rua','numero','complemento','bairro','cep','cidade','estado','pais'] as $field)$d['pessoas'][$slot][$field]=$d['pessoas']['aluno'][$field]??'';
        foreach(['responsavel_academico','responsavel_financeiro'] as $key){$v=CadastroText::upper(remove_accents(self::text($form[$key]??'')));$slot=match($v){'PAI'=>'pai','MAE'=>'mae','OUTRO','OUTRORES'=>$key==='responsavel_financeiro'?'financeiro':'academico',default=>strtolower($v)};if(isset($d['pessoas'][$slot])&&$slot!=='aluno')$d[$key]=$slot;}
        if(isset($d['pessoas']['academico'])){$label=CadastroText::upper(self::text($form['parentesco_outro']??''));$d['pessoas']['academico']['parentesco']=match($label){'AVÓ'=>'avo_feminina','AVÔ'=>'avo_masculino','TIA'=>'tia','TIO'=>'tio','PADRASTO'=>'padrasto','MADRASTA'=>'madrasta',default=>'outro'};}
        $withdraw=CadastroText::upper(remove_accents(self::text($form['retiradas']??'')));foreach(['pai'=>'PAI','mae'=>'MAE'] as $slot=>$word)if(isset($d['pessoas'][$slot])&&str_contains($withdraw,$word))$d['retiradas'][]=['pessoa'=>$slot];
        $contacts=$form['contatos_emergencia']??[];if(is_string($contacts)){try{$decoded=json_decode($contacts,true,32,JSON_THROW_ON_ERROR);if(is_array($decoded))$contacts=$decoded;}catch(\Throwable $ignored){$lines=array_values(array_filter(preg_split('/\r?\n/',$contacts),'strlen'));$contacts=array_chunk($lines,3);}}
        if(is_array($contacts))foreach($contacts as $r)if(is_array($r)){$name=$r['nome']??$r['name']??$r[0]??'';$phone=$r['telefone']??$r['phone']??$r[1]??'';$relation=$r['parentesco']??$r['relationship']??$r[2]??'';if(self::text($name)!=='')$d['contatos_emergencia'][]=['nome'=>self::text($name),'telefone'=>self::text($phone),'parentesco'=>self::text($relation)];}
        return $d;
    }
}
