<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class PersonFields
{
    public const RACES=['BRANCA','PRETA','PARDA','AMARELA','INDÍGENA','NÃO DECLARADA'];
    public const EDUCATION=['ENSINO BÁSICO','ENSINO MÉDIO','GRADUAÇÃO','PÓS-GRADUAÇÃO','MESTRADO','DOUTORADO'];
    public static function studentType(mixed $v): string { if(is_string($v))$v=CadastroText::upper($v);if(!in_array($v,['REGULAR','AEE'],true))throw new RuleViolation('Tipo de aluno deve ser Regular ou AEE.');return $v; }
    public static function validate(array $d): array
    {
        $out=[];
        foreach(['codpessoa_origem'=>80,'rg'=>40,'local_nascimento'=>191,'nacionalidade'=>100,'rua'=>191,'numero'=>30,'complemento'=>191,'bairro'=>100,'cep'=>20,'cidade'=>100,'estado'=>100,'profissao'=>100,'religiao'=>100,'igreja'=>191] as $k=>$size) {
            $out[$k]=isset($d[$k])&&trim((string)$d[$k])!==''?Input::text($d[$k],$size):null;
        }
        foreach(['sexo'=>['MASCULINO','FEMININO']] as $k=>$allowed) {
            $v=$d[$k]??null;if(is_string($v))$v=CadastroText::upper($v);if($v==='')$v=null;
            if($v!==null&&!in_array($v,$allowed,true))throw new RuleViolation('Valor inválido em '.$k.'.');$out[$k]=$v;
        }
        foreach(['raca_etnia'=>self::RACES,'grau_instrucao'=>self::EDUCATION] as $field=>$choices) {
            $value=$d[$field]??null;
            if($value===null||$value===''){$out[$field]=null;continue;}
            if(!is_string($value))throw new RuleViolation('Valor inválido em '.$field.'.');
            $value=CadastroText::upper(trim($value));
            if($value===''){$out[$field]=null;continue;}
            $found=false;
            foreach($choices as $choice)if(remove_accents($value)===remove_accents($choice)){$out[$field]=$choice;$found=true;break;}
            if(!$found)throw new RuleViolation('Selecione uma opção válida para '.($field==='raca_etnia'?'raça/etnia':'grau de instrução').'.');
        }
        $birth=$d['uf_nascimento']??null;
        if($birth!==null&&!is_string($birth))throw new RuleViolation('UF de nascimento inválida.');
        $birth=CadastroText::upper(trim($birth??''));
        $br=json_decode(file_get_contents(dirname(__DIR__,2).'/assets/localidades/brasil.json'),true);
        if($birth!==''&&$birth!=='EX'&&!isset($br[$birth]))throw new RuleViolation('UF de nascimento inválida. Use a sigla da UF ou EX para exterior.');
        $out['uf_nascimento']=$birth===''?null:$birth;
        $out['pais']=strtoupper(trim((string)($d['pais']??'BR')));
        $countries=json_decode(file_get_contents(dirname(__DIR__,2).'/assets/localidades/paises.json'),true);
        if(!isset($countries[$out['pais']]))throw new RuleViolation('Selecione um país válido (código ISO de duas letras na importação).');
        if($out['pais']==='BR') {
            if($out['estado']) {$out['estado']=strtoupper($out['estado']);if(!isset($br[$out['estado']]))throw new RuleViolation('Estado brasileiro inválido. Use a sigla da UF.');}
            if($out['cidade']) {
                if(!$out['estado'])throw new RuleViolation('Informe o estado da cidade.');
                $found=false;foreach($br[$out['estado']]['cidades'] as $city){if(strtolower(remove_accents($city['nome']))===strtolower(remove_accents($out['cidade']))){$out['cidade']=$city['nome'];$found=true;break;}}
                if(!$found)throw new RuleViolation('Cidade não pertence ao estado informado.');
            }
            if($out['cep']) {$out['cep']=preg_replace('/[\s.-]/','',$out['cep']);if(!preg_match('/^\d{8}$/D',$out['cep']))throw new RuleViolation('CEP brasileiro deve ter 8 dígitos.');}
        }
        $photo=$d['foto_attachment_id']??null;$out['foto_attachment_id']=null;
        if($photo!==null&&$photo!=='') {
            $photo=Input::id($photo);
            if(!wp_attachment_is_image($photo)||!current_user_can('read_post',$photo))throw new RuleViolation('Selecione uma imagem acessível da biblioteca de mídia.');
            $out['foto_attachment_id']=$photo;
        }
        return $out;
    }
}
