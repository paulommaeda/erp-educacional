<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;

final class ScholarshipRequestForm
{
    public static function steps(): array
    {
        return json_decode(file_get_contents(__DIR__.'/scholarship-request-fields.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function validate(array $input): array
    {
        $data=[];
        foreach(self::steps() as $step){
            foreach($step['fields'] as $field){
                $key=$field['key'];
                $condition=$field['when']??null;
                if($condition&&!in_array($data[$condition['key']]??null,$condition['options'],true))continue;
                $value=$input[$key]??'';
                if(!$field['required']&&is_string($value)&&trim($value)==='')continue;
                try{
                    $text=Input::text($value,$key==='justificativa'?8000:191);
                    $data[$key]=match($key){
                        'nascimento'=>self::birth($text),
                        'email'=>self::email($text),
                        'percentual'=>self::percentage($text),
                        'numero_filhos'=>self::children($text),
                        'telefone'=>self::phone($text),
                        default=>$text,
                    };
                    if(isset($field['options'])&&!in_array($data[$key],$field['options'],true))throw new RuleViolation('Opção inválida.');
                }catch(RuleViolation $e){
                    throw new RuleViolation($field['label'].': '.$e->getMessage(),0,$e);
                }
            }
        }
        return $data;
    }

    public static function percentage(mixed $value): string
    {
        if(!is_string($value)||!preg_match('/^([0-9]{1,3})(?:[,.]([0-9]{1,2}))?\s*%?$/D',trim($value),$parts)){
            throw new RuleViolation('Informe um percentual entre 0,01% e 100%.');
        }
        $cents=(int)$parts[1]*100+(int)str_pad($parts[2]??'',2,'0');
        if($cents<1||$cents>10000)throw new RuleViolation('Informe um percentual entre 0,01% e 100%.');
        return Money::format($cents);
    }

    private static function birth(string $date): string
    {
        $date=Input::date($date);
        if($date>Input::today())throw new RuleViolation('A data de nascimento não pode estar no futuro.');
        return $date;
    }

    private static function email(string $email): string
    {
        if(!is_email($email))throw new RuleViolation('Informe um e-mail válido.');
        return strtolower($email);
    }

    private static function children(string $value): string
    {
        if(!ctype_digit($value)||strlen($value)>3)throw new RuleViolation('Informe um número inteiro de filhos.');
        return $value;
    }

    private static function phone(string $value): string
    {
        $digits=preg_replace('/\D/','',$value);
        if(strlen($digits)<10||strlen($digits)>15)throw new RuleViolation('Informe o celular com DDD.');
        return $value;
    }
}
