<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class CadastroText
{
    public const FIELDS=['nome','codigo','descricao','codpessoa_origem','ra','rg','rua','numero','complemento','bairro','cep','cidade','estado','pais','profissao','religiao','igreja','sexo','tipo_aluno'];
    public static function upper(string $text):string
    {
        return function_exists('mb_strtoupper')?mb_strtoupper($text,'UTF-8'):strtoupper(strtr($text,array_combine(preg_split('//u','áàâãäéèêëíìîïóòôõöúùûüçñ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑ',-1,PREG_SPLIT_NO_EMPTY))));
    }
    public static function normalize(array $data):array
    {
        foreach(self::FIELDS as $field)if(isset($data[$field])&&is_string($data[$field]))$data[$field]=self::upper(trim($data[$field]));return $data;
    }
}
