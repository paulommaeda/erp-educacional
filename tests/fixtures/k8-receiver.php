<?php
declare(strict_types=1);
/** Contract double for API-ALUNOS.md; it never performs a network request. */
function materialsReceiver(string $url,array $args):array|WP_Error
{
    $GLOBALS['materials_calls'][]=['url'=>$url,'args'=>$args];
    if(!empty($GLOBALS['materials_timeout']))return new WP_Error('timeout','Tempo esgotado simulado');
    $body=json_decode($args['body'],true,64,JSON_THROW_ON_ERROR);
    if(array_keys($body)!==['modo','validar_apenas','alunos']||$body['modo']!=='atualizar'||!is_bool($body['validar_apenas'])||!$body['alunos'])throw new RuntimeException('Contrato K8 inválido.');
    if(($args['redirection']??-1)!==0||($args['sslverify']??false)!==true||!str_starts_with($args['headers']['Authorization']??'','Basic '))throw new RuntimeException('Transporte inseguro.');
    $records=0;$links=0;$new=0;$updated=0;
    foreach($body['alunos'] as $student){if(array_keys($student)!==['ra','nome_aluno','cpf_aluno','turma','responsaveis']||!is_string($student['ra'])||trim($student['nome_aluno'])===''||trim($student['turma'])===''||count($student['responsaveis'])>100)throw new RuntimeException('Aluno K8 inválido.');
        foreach($student['responsaveis'] as $guardian)if(array_keys($guardian)!==['nome','cpf','telefone']||!is_string($guardian['cpf'])||!is_string($guardian['telefone']))throw new RuntimeException('Responsável K8 inválido.');
        $records+=max(1,(int)ceil(count($student['responsaveis'])/2));$links+=count($student['responsaveis']);if(isset($GLOBALS['materials_imported'][$student['ra']]))$updated++;else$new++;
    }
    if($records>10000||strlen($args['body'])>10485760)throw new RuntimeException('Lote excede o limite do K8.');
    $status=$GLOBALS['materials_status']??200;
    if($status===200&&!$body['validar_apenas'])foreach($body['alunos'] as $student){$id=$GLOBALS['materials_imported'][$student['ra']]['id']??count($GLOBALS['materials_imported']??[])+1;$GLOBALS['materials_imported'][$student['ra']]=['id'=>$id,'dados'=>$student];}
    $data=$status===200?['sucesso'=>true,'validar_apenas'=>$body['validar_apenas'],'modo'=>'atualizar','alunos'=>count($body['alunos']),'vinculos'=>$links,'registros'=>$records,'novos'=>$body['validar_apenas']?null:$new,'atualizados'=>$body['validar_apenas']?null:$updated,'importado_em'=>$body['validar_apenas']?null:'2026-11-17 10:00:00','avisos'=>$GLOBALS['materials_warnings']??[],'base_consultada'=>'integrada','aviso_base'=>$GLOBALS['materials_base_warning']??null]:['code'=>'k8prod_erro','message'=>$GLOBALS['materials_error']??'Lote recusado.','data'=>['status'=>$status]];
    if(!empty($GLOBALS['materials_bad_response']))$data=['sucesso'=>true,'validar_apenas'=>'false','modo'=>'substituir','alunos'=>0];
    return ['headers'=>[],'body'=>wp_json_encode($data),'response'=>['code'=>$status,'message'=>'Contrato de teste'],'cookies'=>[]];
}
