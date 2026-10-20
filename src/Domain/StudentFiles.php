<?php
declare(strict_types=1);
namespace EducacionalERP\Domain;
interface StudentFiles
{
    public function put(array $file):array;
    public function read(string $token):string;
    public function delete(string $token):bool;
}
