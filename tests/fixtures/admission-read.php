<?php
declare(strict_types=1);
function admissionReadFixture(string $suffix):array
{
    $fields=[];foreach(\EducacionalERP\Domain\AdmissionForm::fields() as $f){if($f['chave']==='empresa')continue;$fields[$f['chave']]=match($f['tipo']){'multiplos'=>[],'numero'=>'2','selecao'=>in_array('Não',$f['opcoes'],true)?'Não':($f['opcoes'][0]??''),default=>!empty($f['obrigatorio'])?'INFORMADO':''};}
    $fields['interesse_curso']='Ensino Fundamental II';$fields['interesse_serie']='6º ANO';$fields['saude_orientacoes_medicas']='ORIENTAÇÃO FICTÍCIA';
    return ['pessoas'=>['aluno'=>['nome'=>'Aluno API '.$suffix,'data_nascimento'=>'2015-02-15','sexo'=>'MASCULINO','pais'=>'BR','estado'=>'PR','cidade'=>'Curitiba','rua'=>'Rua de teste','numero'=>'10','bairro'=>'Centro','cep'=>'80010000'],'pai'=>['nome'=>'Pai API '.$suffix,'data_nascimento'=>'1980-03-12','email'=>'pai@example.test','pais'=>'BR','profissao'=>'Professor'],'mae'=>['nome'=>'Mãe API '.$suffix,'data_nascimento'=>'1982-06-20','email'=>'mae@example.test','pais'=>'BR']], 'campos'=>$fields,'responsavel_academico'=>'mae','responsavel_financeiro'=>'pai','retiradas'=>[['pessoa'=>'mae']],'contatos_emergencia'=>[['nome'=>'Contato fictício','telefone'=>'41999999999','parentesco'=>'TIA']],'declaracao'=>true,'declaracao_nome'=>'Mãe API '.$suffix];
}
