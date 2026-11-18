<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\Store;
use EducacionalERP\Infrastructure\WordPress\SchoolIdentity;
use EducacionalERP\Infrastructure\Pdf\ContractPdf;

/** Authenticated, company-scoped printing. Contract PDFs are never saved to public uploads. */
final class ContractPrint
{
    public function __construct(private Store $db,private Contracts $contracts) {}
    public function download(int $id):array
    {
        $contract=$this->contracts->detail($id);$company=$this->db->get('coligadas',(int)$contract['codcoligada']);
        $school=Coligadas::within((int)$contract['codcoligada'],static function()use($company){
            $saved=get_option(SchoolIdentity::option(),false);$identity=SchoolIdentity::read();
            if($saved===false)$identity['nome']=$company['nome'];
            $identity['logo_padrao']=$saved===false&&(int)$company['codcoligada']===1;
            return $identity;
        });
        $number=$contract['assinatura_numero_snapshot']?:$contract['numero'];
        $safe=preg_replace('/[^\pL\pN._-]+/u','-',(string)$number)?:'contrato';
        return ['nome'=>'contrato-'.$safe.'.pdf','mime'=>'application/pdf','conteudo'=>base64_encode(ContractPdf::render($contract,$school))];
    }
}
