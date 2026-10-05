<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
/** Legal owner from the record, never from the staff's selected work company. */
final class CompanyData
{
    private array $cache=[];
    public function __construct(private Store $db){}
    public function decorate(array $row):array
    {
        $id=(int)$row['codcoligada'];if(!isset($this->cache[$id])){$company=$this->db->get('coligadas',$id);$this->cache[$id]=array_intersect_key($company,array_flip(['codcoligada','nome','razao_social','cnpj']));}
        $row['coligada']=$this->cache[$id];$row['coligada_nome']=$this->cache[$id]['nome'];$row['coligada_cnpj']=$this->cache[$id]['cnpj'];return $row;
    }
}
