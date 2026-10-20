<?php
require __DIR__.'/guardian-mail.php';
wp_set_current_user(1);$db->update('aluno_responsaveis',$link,['fim_vigencia'=>null]);
$chat=new EducacionalERP\Application\ChatService($db);
$other=wp_insert_user(['user_login'=>'staff.other','user_email'=>'other@example.test','display_name'=>'Outro atendimento','role'=>'erp_secretaria']);
$myChannel=$chat->saveChannel(['nome'=>'Secretaria','ativo'=>true,'membros'=>[$staff]],keyM())['idcanal'];
$otherChannel=$chat->saveChannel(['nome'=>'Orientação','ativo'=>true,'membros'=>[$other]],keyM())['idcanal'];
wp_set_current_user($staff);$channels=$chat->contactChannels($student,$guardian)['items'];
okM(count($channels)===1&&(int)$channels[0]['idcanal']===$myChannel,'atalho só lista o departamento ao qual o atendente está vinculado');
$c=$chat->start(['idcanal'=>$myChannel,'idaluno'=>$student,'codpessoa'=>$guardian],keyM())['idconversa'];
okM($chat->start(['idcanal'=>$myChannel,'idaluno'=>$student,'codpessoa'=>$guardian],keyM())['idconversa']===$c,'atalho retoma conversa existente do responsável e aluno');
wp_set_current_user(1);$row=$db->get('chat_canais',$otherChannel);$chat->saveChannel(['idcanal'=>$otherChannel,'versao'=>$row['versao'],'nome'=>'Orientação','ativo'=>true,'membros'=>[$other,$staff]],keyM());
wp_set_current_user($staff);okM(count($chat->contactChannels($student,$guardian)['items'])===2,'mais de um canal permite escolha sem trocar família/aluno');
wp_set_current_user($familyUser);denyM(fn()=>$chat->contactChannels($student,$guardian),'responsável não usa atalho interno de atendimento');
