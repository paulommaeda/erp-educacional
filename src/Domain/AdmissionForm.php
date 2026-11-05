<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
/** The native form uses canonical ERP people fields, not Fluent Forms runtime or smartcodes. */
final class AdmissionForm
{
    public const PEOPLE=['aluno'=>'Aluno','pai'=>'Pai','mae'=>'Mãe','academico'=>'Outro responsável acadêmico','financeiro'=>'Outro responsável financeiro'];
    public static function fields(?Store $db=null):array
    {
        $fields=json_decode(file_get_contents(__DIR__.'/admission-fields.json'),true,512,JSON_THROW_ON_ERROR);
        if($db){$courses=$db->rows('SELECT nome FROM '.$db->table('cursos').' WHERE codcoligada=%d AND ativo=1 ORDER BY nome',[\EducacionalERP\Application\Coligadas::current()]);if($courses)foreach($fields as &$field)if($field['chave']==='interesse_curso')$field['opcoes']=array_values(array_unique(array_column($courses,'nome')));unset($field);}
        return $fields;
    }
    private static function fold(string $value):string {return CadastroText::upper(remove_accents(trim($value)));}
    public static function series(string $course,array $all):array
    {
        $course=self::fold($course);$part=0;
        if(preg_match('/(?:FUNDAMENTAL|FUND|EF)\s*(?:II|2)(?:\b|$)|ANOS FINAIS/',$course))$part=2;
        elseif(preg_match('/(?:FUNDAMENTAL|FUND|EF)\s*(?:I|1)(?:\b|$)|ANOS INICIAIS/',$course))$part=1;
        elseif(str_contains($course,'MEDIO'))$part=3;
        if(!$part)return $all;
        return array_values(array_filter($all,static function($item)use($part){$label=self::fold($item);preg_match('/^(\d+)/',$label,$m);$n=(int)($m[1]??0);return $part===3?str_contains($label,'SERIE'):str_contains($label,'ANO')&&($part===1?$n>=1&&$n<=5:$n>=6&&$n<=9);}));
    }
    private static function choice(string $value,array $options):?string {foreach($options as $option)if(self::fold($value)===self::fold($option))return $option;return null;}
    private static function legacy(?array $original,string $section,string $key,string $value):bool {return $original!==null&&self::fold((string)($original[$section][$key]??''))===self::fold($value);}

    public static function seed(Store $db):void
    {
        $db->atomic(function()use($db){
            $known=array_column($db->rows('SELECT chave FROM '.$db->table('campos_adicionais')), 'chave');
            $defs=self::fields();$defs[]=['chave'=>'contatos_emergencia','nome'=>'Contatos de emergência','area'=>'familia','tipo'=>'texto_longo','opcoes'=>[]];
            foreach($defs as $i=>$f){if(in_array($f['chave'],$known,true))continue;
                $name=$f['nome'];while(strlen($name)>120)$name=preg_replace('/.$/us','',$name);
                $area=match($f['area']){'ficha_medica','dificuldades_aprendizagem'=>$f['area'],default=>'dados_pessoais'};
                $db->insert('campos_adicionais',['chave'=>$f['chave'],'nome'=>$name,'tipo'=>$f['tipo']==='multiplos'?'texto_longo':$f['tipo'],'opcoes'=>implode("\n",array_map([CadastroText::class,'upper'],$f['opcoes'])),'secao'=>$f['area']==='pessoais'?'pessoais':'outros','exibir_pessoa'=>$f['chave']==='empresa'?1:0,'exibir_aluno'=>1,'area_aluno'=>$area,'condicao_exibicao'=>'preenchido','ordem'=>100+$i,'ativo'=>1]);
            }
        });
    }
    public static function visible(array $f,array $values):bool
    {
        $when=$f['quando']??null;return !$when||in_array($values[$when['campo']]??'', $when['valores'],true);
    }
    private static function text(mixed $v,int $limit,bool $required=false):string
    {
        if(!is_scalar($v)&&$v!==null)throw new RuleViolation('Informe um texto válido.');
        $v=trim(strip_tags((string)$v));if(strlen($v)>$limit)throw new RuleViolation('Texto acima do limite de '.$limit.' caracteres.');
        if($required&&$v==='')throw new RuleViolation('Preencha o campo obrigatório.');return $v;
    }
    public static function validate(array $d,bool $review=false,?array $catalog=null,?array $original=null,bool $api=false):array
    {
        $catalog??=self::fields();
        if(array_diff(array_keys($d),['pessoas','campos','contatos_emergencia','retiradas','responsavel_academico','responsavel_financeiro','declaracao','declaracao_nome','declaracao_versao']))throw new RuleViolation('Há campos desconhecidos no requerimento.');
        $people=$d['pessoas']??[];if(!is_array($people)||array_diff(array_keys($people),array_keys($api?self::PEOPLE+['outro'=>'Outro responsável']:self::PEOPLE))||!isset($people['aluno']))throw new RuleViolation('Informe os dados do aluno.');
        $out=['pessoas'=>[],'campos'=>[],'contatos_emergencia'=>[],'retiradas'=>[]];
        foreach($people as $slot=>$p){
            if(!is_array($p)||array_diff(array_keys($p),[...\EducacionalERP\Application\ExternalPeopleApi::FIELDS,'empresa','parentesco',...($api?['campos_adicionais']:[])]))throw new RuleViolation('Dados de pessoa inválidos.');
            try{
                $valid=['nome'=>CadastroText::upper(self::text($p['nome']??'',191,true)),'data_nascimento'=>Input::date($p['data_nascimento']??null)];
                if($valid['data_nascimento']>Input::today())throw new RuleViolation('Nascimento não pode estar no futuro.');
                $cpf=preg_replace('/\D/','',self::text($p['cpf']??'',30));if($cpf!==''&&!Cpf::valid($cpf))throw new RuleViolation('CPF inválido.');$valid['cpf']=$cpf;
                $email=strtolower(self::text($p['email']??'',191));if($email===''||!is_email($email))throw new RuleViolation('Informe um e-mail válido. O e-mail é obrigatório.');$valid['email']=$email;
                $valid['telefone']=self::text($p['telefone']??'',30);
                $valid+=PersonFields::validate($p);unset($valid['codpessoa_origem'],$valid['foto_attachment_id']);
                // Civil status stays a selected system ID; no Fluent single-letter codes.
                $valid['idestado_civil']=empty($p['idestado_civil'])?'':(string)Input::id($p['idestado_civil']);
                $valid['empresa']=CadastroText::upper(self::text($p['empresa']??'',191));if(in_array($slot,['academico','financeiro','outro'],true)){$parentesco=strtolower((string)($p['parentesco']??'outro'));if(!isset(Relationships::LABELS[$parentesco]))throw new RuleViolation('Parentesco inválido.');$valid['parentesco']=$parentesco;}
                $nationality=self::fold((string)$valid['nacionalidade']);$uf=(string)$valid['uf_nascimento'];$city=(string)$valid['local_nascimento'];
                if(preg_match('/^(?:BR|BRA|BRASIL|BRASILEIR[OA](?:\(A\))?)$/',$nationality)&&$uf!==''&&$uf!=='EX'&&$city!==''){
                    $br=json_decode(file_get_contents(dirname(__DIR__,2).'/assets/localidades/brasil.json'),true);$match=null;foreach($br[$uf]['cidades'] as $c)if(self::fold($c['nome'])===self::fold($city)){$match=$c['nome'];break;}
                    if($match===null&&!($review&&isset($original['pessoas'][$slot])&&self::fold((string)($original['pessoas'][$slot]['local_nascimento']??''))===self::fold($city)&&(string)($original['pessoas'][$slot]['uf_nascimento']??'')===$uf))throw new RuleViolation('Cidade de nascimento não pertence à UF selecionada.');if($match!==null)$valid['local_nascimento']=$match;
                }
                $out['pessoas'][$slot]=CadastroText::normalize($valid);if($api&&isset($p['campos_adicionais'])){if(!is_array($p['campos_adicionais'])||count($p['campos_adicionais'])>200)throw new RuleViolation('Campos adicionais inválidos.');$out['pessoas'][$slot]['campos_adicionais']=$p['campos_adicionais'];}if(isset($valid['parentesco']))$out['pessoas'][$slot]['parentesco']=$valid['parentesco'];
            }catch(RuleViolation $e){throw new RuleViolation((self::PEOPLE[$slot]??'Outro responsável').': '.$e->getMessage());}
        }
        foreach(['responsavel_academico','responsavel_financeiro'] as $f){$slot=$d[$f]??'';if(!is_string($slot)||$slot==='aluno'||!isset($out['pessoas'][$slot]))throw new RuleViolation('Selecione o '.str_replace('_',' ',$f).' informado neste requerimento.');$out[$f]=$slot;}
        $values=$d['campos']??[];if(!is_array($values)||array_diff(array_keys($values),array_column($catalog,'chave')))throw new RuleViolation('Campos complementares inválidos.');
        foreach($catalog as $f){if($f['chave']==='empresa'||!self::visible($f,$values))continue;
            try{
                $v=$values[$f['chave']]??($f['tipo']==='multiplos'?[]:'');
                if($f['tipo']==='multiplos'){
                    if(!is_array($v)||!array_is_list($v)||count($v)>50)throw new RuleViolation('Selecione as opções válidas.');
                    foreach($v as $item)if(!is_string($item)||(!in_array($item,$f['opcoes'],true)&&!($review&&in_array($item,is_array($original['campos'][$f['chave']]??null)?$original['campos'][$f['chave']]:explode("\n",(string)($original['campos'][$f['chave']]??'')),true))))throw new RuleViolation('Opção inválida.');$v=implode("\n",array_unique($v));
                }else{$v=self::text($v,$f['tipo']==='texto_longo'?8000:1000,!empty($f['obrigatorio']));if(($f['entrada']??$f['tipo'])==='selecao'&&$v!==''){$options=$f['opcoes'];if($f['chave']==='interesse_serie'){$currentCourse=(string)($out['campos']['interesse_curso']??'');if($currentCourse===''&&!($review&&self::legacy($original,'campos','interesse_curso','')))throw new RuleViolation('Selecione o curso primeiro.');$options=self::series($currentCourse,$options);}$selected=self::choice($v,$options);if($selected===null&&!($review&&self::legacy($original,'campos',$f['chave'],$v)&&($f['chave']!=='interesse_serie'||self::legacy($original,'campos','interesse_curso',(string)($out['campos']['interesse_curso']??'')))))throw new RuleViolation('Opção inválida para o curso selecionado.');if($selected!==null)$v=$selected;}if($f['tipo']==='numero'&&$v!==''&&(!ctype_digit($v)||(int)$v>100))throw new RuleViolation('Número inválido.');}
                $out['campos'][$f['chave']]=$v;
            }catch(RuleViolation $e){throw new RuleViolation($f['nome'].': '.$e->getMessage());}
        }
        foreach(['contatos_emergencia','retiradas'] as $list){$items=$d[$list]??[];if(!is_array($items)||!array_is_list($items)||count($items)>20)throw new RuleViolation('Informe até 20 contatos por lista.');
            foreach($items as $item){if(!is_array($item)||array_diff(array_keys($item),['nome','telefone','parentesco','cpf','rg','pessoa','observacoes']))throw new RuleViolation('Contato inválido.');
                $slot=(string)($item['pessoa']??'');if($slot==='outro')$slot='';if($slot!==''&&($slot==='aluno'||!isset($out['pessoas'][$slot])))throw new RuleViolation('Pessoa da autorização de retirada inválida.');
                $p=$slot!==''?$out['pessoas'][$slot]:$item;
                $parentesco=in_array($slot,['pai','mae'],true)?($slot==='pai'?'PAI':'MÃE'):($item['parentesco']??'OUTRO');$row=['pessoa'=>$slot,'nome'=>CadastroText::upper(self::text($p['nome']??'',191,true)),'telefone'=>self::text($p['telefone']??'',30),'parentesco'=>CadastroText::upper(self::text($parentesco,40,true)),'cpf'=>preg_replace('/\D/','',self::text($p['cpf']??'',30)),'rg'=>self::text($p['rg']??'',40),'observacoes'=>in_array($slot,['pai','mae'],true)?'':self::text($item['observacoes']??'',1000)];
                if($row['cpf']!==''&&!Cpf::valid($row['cpf']))throw new RuleViolation('CPF inválido em '.$row['nome'].'.');$out[$list][]=$row;
            }
        }
        if(!$api&&($d['declaracao']??false)!==true)throw new RuleViolation('Confirme a declaração de veracidade e ciência do tratamento dos dados.');
        $out['declaracao']=!$api;$out['declaracao_nome']=$api?'':CadastroText::upper(self::text($d['declaracao_nome']??'',191,true));$out['declaracao_versao']=$api?'api':'1';
        return $out;
    }
    public static function fromStored(array $d):array
    {
        // Stored multiple selections are newline strings; normalize only our own catalog.
        foreach(self::fields() as $f)if($f['tipo']==='multiplos'&&is_string($d['campos'][$f['chave']]??null))$d['campos'][$f['chave']]=$d['campos'][$f['chave']]===''?[]:explode("\n",$d['campos'][$f['chave']]);return $d;
    }
}
