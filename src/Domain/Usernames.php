<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
final class Usernames
{
    public static function candidates(string $name): array
    {
        $parts=preg_split('/\s+/',trim(strtolower(remove_accents($name))));
        $parts=array_values(array_filter(array_map(fn($p)=>preg_replace('/[^a-z0-9]/','',$p),$parts),fn($p)=>$p!==''&&!in_array($p,['de','da','do','dos','das','e','del'],true)));
        if(count($parts)<2) { return []; }
        $first=array_shift($parts);$out=[];
        foreach(array_reverse($parts) as $last) { $login=$first.'.'.$last;if(strlen($login)<=60&&!in_array($login,$out,true)) { $out[]=$login; } }
        return $out;
    }
    public static function choose(string $name,?string $manual,callable $exists): string
    {
        if($manual!==null && trim($manual)!=='') {
            $manual=trim($manual);
            if(strlen($manual)<3||strlen($manual)>60||!preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D',$manual)) { throw new UsernameRequired('Escolha um nome de usuário com 3 a 60 caracteres: letras minúsculas sem acentos, números, ponto, hífen ou sublinhado.'); }
            if($exists($manual)) { throw new UsernameRequired('Esse nome de usuário já está ocupado. Escolha outro.'); }
            return $manual;
        }
        foreach(self::candidates($name) as $candidate) { if(!$exists($candidate)) { return $candidate; } }
        throw new UsernameRequired('Não há combinação automática disponível para este nome. Escolha o nome de usuário no campo indicado.');
    }
}
