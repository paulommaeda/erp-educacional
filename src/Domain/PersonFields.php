<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class PersonFields
{
    public static function studentType(mixed $v): string { if(is_string($v))$v=CadastroText::upper($v);if(!in_array($v,['REGULAR','AEE'],true))throw new RuleViolation('Tipo de aluno deve ser Regular ou AEE.');return $v; }
    public static function validate(array $d): array
    {
        $out=[];
        foreach(['codpessoa_origem'=>80,'rg'=>40,'rua'=>191,'numero'=>30,'complemento'=>191,'bairro'=>100,'cep'=>20,'cidade'=>100,'estado'=>100,'profissao'=>100,'religiao'=>100,'igreja'=>191] as $k=>$size) {
            $out[$k]=isset($d[$k])&&trim((string)$d[$k])!==''?Input::text($d[$k],$size):null;
        }
        foreach(['sexo'=>['MASCULINO','FEMININO']] as $k=>$allowed) {
            $v=$d[$k]??null;if(is_string($v))$v=CadastroText::upper($v);if($v==='')$v=null;
            if($v!==null&&!in_array($v,$allowed,true))throw new RuleViolation('Valor inválido em '.$k.'.');$out[$k]=$v;
        }
        $out['pais']=strtoupper(trim((string)($d['pais']??'BR')));
        $countries=json_decode(file_get_contents(dirname(__DIR__,2).'/assets/localidades/paises.json'),true);
        if(!isset($countries[$out['pais']]))throw new RuleViolation('Selecione um país válido (código ISO de duas letras na importação).');
        if($out['pais']==='BR') {
            $br=json_decode(file_get_contents(dirname(__DIR__,2).'/assets/localidades/brasil.json'),true);
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
