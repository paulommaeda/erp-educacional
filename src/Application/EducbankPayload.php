<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\{Store,Input,Money,RuleViolation};
use EducacionalERP\Infrastructure\Educbank\Settings;
/** Exact Educbank DTOs; amounts are integer cents, never decimal floats. */
final class EducbankPayload
{
    public function __construct(private Store $db){}
    public function context(int $id,array $settings):array
    {
        $m=$this->db->get('matriculas',$id);$student=$this->db->get('alunos',(int)$m['idaluno']);$p=$this->db->get('pessoas',(int)$student['codpessoa']);$class=$this->db->get('turmas',(int)$m['idturma_atual']);$company=$this->db->get('coligadas',(int)$m['codcoligada']);
        $year=$settings['periodos'][$m['codperiodo']]??null;$program=$settings['turmas'][$class['idturma']]??null;
        if(!$year)throw new RuleViolation('Mapeie o ano/semestre do período letivo nas configurações Educbank.');
        if(!in_array($program,Settings::PROGRAMS,true))throw new RuleViolation('Mapeie a série Educbank da turma nas configurações.');
        $cnpj=preg_replace('/\D/','',(string)$company['cnpj']);if(!Coligadas::validCnpj($cnpj))throw new RuleViolation('Cadastre um CNPJ válido na coligada.');
        $links=$this->db->rows('SELECT * FROM '.$this->db->table('aluno_responsaveis').' WHERE idaluno=%d AND responsavel_financeiro=1 AND fim_vigencia IS NULL AND inicio_vigencia<=UTC_TIMESTAMP()',[(int)$student['idaluno']]);
        if(count($links)!==1)throw new RuleViolation('O aluno precisa de exatamente um responsável financeiro vigente.');$link=$links[0];$rf=$this->db->get('pessoas',(int)$link['codpessoa_responsavel']);
        return compact('m','student','p','class','company','cnpj','year','program','link','rf');
    }
    private function address(array $p):array
    {
        $cep=preg_replace('/\D/','',(string)$p['cep']);if(($p['pais']??'BR')!=='BR'||strlen($cep)!==8||empty($p['numero']))throw new RuleViolation('Complete CEP brasileiro e número do endereço do responsável financeiro.');
        return ['zipCode'=>$cep,'street'=>$p['rua'],'number'=>$p['numero'],'district'=>$p['bairro'],'city'=>$p['cidade'],'state'=>$p['estado'],'country'=>$p['pais'],'complement'=>$p['complemento']];
    }
    private function document(array $p):string
    {
        $cpf=preg_replace('/\D/','',(string)$p['cpf']);if(!\EducacionalERP\Domain\Cpf::valid($cpf))throw new RuleViolation('Cadastre um CPF válido no responsável financeiro.');return $cpf;
    }
    private function gender(array $p):string{return match($p['sexo']??''){'MASCULINO'=>'Male','FEMININO'=>'Female',default=>'PreferNotToSay'};}
    public function enrollment(array $c):array
    {
        $p=$c['p'];$rf=$c['rf'];$kinship=['mae'=>'Mother','pai'=>'Father','tio'=>'Uncle','tia'=>'Aunt','padrasto'=>'Stepfather','madrasta'=>'Stepmother','avo_masculino'=>'Grandfather','avo_feminina'=>'Grandmother','irmao'=>'Brother','irma'=>'Sister'];
        return ['externalId'=>(string)$c['m']['idmatricula'],'student'=>['name'=>Input::text($p['nome']),'birthDate'=>Input::date($p['data_nascimento']),'gender'=>$this->gender($p)],'companyDocument'=>$c['cnpj'],'academicYear'=>(int)$c['year']['ano'],'term'=>$c['year']['semestre'],'academicProgram'=>$c['program'],'academicClassName'=>$c['class']['nome'],'guardians'=>[['name'=>Input::text($rf['nome']),'document'=>$this->document($rf),'address'=>$this->address($rf),'phone'=>$rf['telefone'],'email'=>$rf['email'],'gender'=>$this->gender($rf),'kinship'=>$kinship[$c['link']['parentesco']]??'Other','isDefault'=>true]]];
    }
    public function invoice(array $c,array $title,string $enrollment,array $settings):array
    {
        $rf=$this->db->get('pessoas',(int)$title['codpessoa_rf_atual']);if((int)$rf['codpessoa']!==(int)$c['rf']['codpessoa'])throw new RuleViolation('Responsável do lançamento difere do vínculo financeiro vigente. Confira a troca de responsável.');
        $parcel=$this->db->get('parcelas',(int)$title['idparcela']);$contract=$this->db->get('contratos',(int)$parcel['idcontrato']);
        $original=Money::cents($title['valor_original']);$discount=Money::cents($title['desconto_incondicional']);$net=Money::cents($title['valor_liquido']);
        if($original-$discount!==$net||$discount<0||$net<=0)throw new RuleViolation('Valores do lançamento inconsistentes ou sem valor a cobrar.');
        if(Money::cents($title['valor_baixa'])>0||Money::cents($title['desconto_condicional_aplicado'])>0||Money::cents($title['juros_aplicados'])>0||Money::cents($title['multa_aplicada'])>0)throw new RuleViolation('Lançamento com baixa ou encargos aplicados exige conciliação antes do envio ao Educbank.');
        $conditional=min($net,Money::cents(Coligadas::within((int)$c['m']['codcoligada'],fn()=>get_option(Coligadas::option('ederp_pontualidade'),'0.00'))));
        return ['externalId'=>(string)$title['idlancamento'],'companyCnpj'=>$c['cnpj'],'payerDocument'=>$this->document($rf),'payer'=>['name'=>$rf['nome'],'cpfCnpj'=>$this->document($rf),'address'=>$this->address($rf),'phone'=>$rf['telefone'],'email'=>$rf['email']],
            'dueDate'=>Input::date($title['vencimento']),'referenceDate'=>substr($parcel['vencimento_original'],0,7),'closeInvoice'=>true,
            'items'=>[['itemName'=>$settings['item_nome'],'quantity'=>1,'valueCents'=>$original,'fixedDiscountCents'=>$discount,'installmentNumber'=>(int)$parcel['numero'],'totalInstallments'=>(int)$contract['quantidade_parcelas'],'enrollmentId'=>$enrollment,'externalOrderId'=>(string)$contract['idcontrato'],'studentExternalId'=>$c['student']['ra'],'studentName'=>$c['p']['nome'],'academicClassName'=>$c['class']['nome'],'academicClassYear'=>(int)$c['year']['ano'],'academicClassLevel'=>$c['program'],'ebpayPlan'=>$settings['plano']]],
            'earlyPaymentDiscounts'=>$conditional>0?[['days'=>0,'valueCents'=>$conditional]]:[],
            'paymentFine'=>['overdueFine'=>Money::cents($settings['multa'])/10000,'dailyInterest'=>Money::cents($settings['juros_diarios'])/10000]];
    }
    /** Ownership proof used only when recovering a lost POST response. */
    public static function identity(string $type,array $d):string
    {
        if($type==='matricula')$parts=[preg_replace('/\D/','',(string)($d['companyDocument']??'')),(string)($d['externalId']??''),(int)($d['academicYear']??0),(int)($d['term']??1),$d['student']['name']??$d['studentName']??'',substr($d['student']['birthDate']??$d['studentBirthDate']??'',0,10)];
        else{$i=$d['items'][0]??[];$parts=[preg_replace('/\D/','',(string)($d['companyCnpj']??'')),(string)($d['externalId']??''),$i['enrollmentId']??'',$i['externalOrderId']??'',$i['studentExternalId']??''];}
        return hash('sha256',wp_json_encode($parts));
    }
}
