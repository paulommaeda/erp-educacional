<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\RuleViolation;
use EducacionalERP\Infrastructure\WordPress\Access;
final class DocumentMailSettings
{
    public const OPTION='ederp_document_mail';
    public const TYPES=['recebido'=>'Documento recebido pela equipe','aprovado'=>'Documento aprovado','rejeitado'=>'Documento rejeitado','pendencias'=>'Lembrete de documentos pendentes'];
    public const TOKENS=['colegio_nome'=>'Colégio','responsavel_nome'=>'Destinatário','aluno_nome'=>'Aluno','aluno_ra'=>'RA','documento_nome'=>'Documento','situacao'=>'Situação','motivo'=>'Motivo da rejeição','documentos_pendentes'=>'Lista de documentos ausentes ou rejeitados','data_envio'=>'Data e hora do envio','link_documentos'=>'Área de documentos da família','link_analise'=>'Fila de análise da secretaria'];
    public static function defaults():array
    {
        return ['recebido_ativo'=>0,'resultado_ativo'=>1,'lembretes_ativos'=>1,'emails_equipe'=>[],'responsaveis'=>'ambos','modelos'=>[
            'recebido'=>['assunto'=>'[colegio_nome] — Documento recebido: [aluno_nome]','corpo'=>"Um responsável enviou um documento para análise.\n\nAluno: [aluno_nome] · RA [aluno_ra]\nDocumento: [documento_nome]\nEnviado em: [data_envio]\n\nConfira o documento na área de análise:\n[link_analise]"],
            'aprovado'=>['assunto'=>'[colegio_nome] — Documento aprovado: [documento_nome]','corpo'=>"Olá, [responsavel_nome].\n\nO documento [documento_nome] de [aluno_nome] foi aprovado pela secretaria.\n\nAcompanhe os documentos do aluno:\n[link_documentos]"],
            'rejeitado'=>['assunto'=>'[colegio_nome] — Reenvio de documento: [documento_nome]','corpo'=>"Olá, [responsavel_nome].\n\nPrecisamos de um novo envio do documento [documento_nome] de [aluno_nome].\n\nMotivo: [motivo]\n\nEnvie o arquivo corrigido na área de documentos:\n[link_documentos]"],
            'pendencias'=>['assunto'=>'[colegio_nome] — Documentos pendentes de [aluno_nome]','corpo'=>"Olá, [responsavel_nome].\n\nAinda existem documentos de [aluno_nome] que precisam ser enviados ou reenviados:\n\n[documentos_pendentes]\n\nAcesse a área de documentos para concluir o envio:\n[link_documentos]\n\nEste lembrete é enviado a cada dois dias enquanto houver pendências. Documentos aguardando análise não entram na cobrança."]
        ]];
    }
    public static function read():array
    {
        $saved=get_option(Coligadas::option(self::OPTION),[]);return array_replace_recursive(self::defaults(),is_array($saved)?$saved:[]);
    }
    public static function payload():array
    {
        Access::requireAdmin();$settings=self::read();return ['configuracao'=>$settings,'versao'=>hash('sha256',wp_json_encode($settings)),'tipos'=>self::TYPES,'shortcodes'=>self::TOKENS,'intervalo_dias'=>2];
    }
    public static function save(array $data):array
    {
        Access::requireAdmin();$current=self::payload();if(!is_string($data['versao']??null)||!hash_equals($current['versao'],$data['versao']))throw new RuleViolation('Configuração alterada. Reabra antes de salvar.');
        if(array_diff(array_keys($data),['versao','recebido_ativo','resultado_ativo','lembretes_ativos','emails_equipe','responsaveis','modelos']))throw new RuleViolation('Campo de notificação inválido.');$out=[];
        foreach(['recebido_ativo','resultado_ativo','lembretes_ativos'] as $field){if(!in_array($data[$field]??null,[0,1,'0','1',true,false],true))throw new RuleViolation('Selecione a situação das notificações.');$out[$field]=(int)$data[$field];}
        $emails=$data['emails_equipe']??[];if(is_string($emails))$emails=preg_split('/[;,\r\n]+/',$emails,-1,PREG_SPLIT_NO_EMPTY);if(!is_array($emails)||count($emails)>30)throw new RuleViolation('Informe até 30 destinatários da equipe.');$valid=[];
        foreach($emails as $email){if(!is_string($email))throw new RuleViolation('E-mail da equipe inválido.');$email=strtolower(trim($email));if(!is_email($email)||strlen($email)>191||preg_match('/[\r\n]/',$email))throw new RuleViolation('Confira os e-mails da equipe.');$valid[$email]=true;}$out['emails_equipe']=array_keys($valid);
        if($out['recebido_ativo']&&!$out['emails_equipe'])throw new RuleViolation('Informe os destinatários da equipe para ativar o aviso de documento recebido.');
        if(!in_array($data['responsaveis']??null,['ambos','academico','financeiro'],true))throw new RuleViolation('Selecione os responsáveis que receberão os avisos.');$out['responsaveis']=$data['responsaveis'];$out['modelos']=[];
        if(!is_array($data['modelos']??null)||array_diff(array_keys($data['modelos']),array_keys(self::TYPES)))throw new RuleViolation('Modelos de e-mail inválidos.');
        foreach(self::TYPES as $type=>$title){$row=$data['modelos'][$type]??[];$out['modelos'][$type]=[];foreach(['assunto'=>250,'corpo'=>12000] as $field=>$max){$text=$row[$field]??null;if(!is_string($text)||trim($text)===''||strlen($text)>$max||($field==='assunto'&&preg_match('/[\r\n]/',$text)))throw new RuleViolation('Preencha o assunto e a mensagem de '.$title.'.');$text=trim($field==='corpo'?sanitize_textarea_field($text):sanitize_text_field($text));if($text==='')throw new RuleViolation('Mensagem vazia.');preg_match_all('/\[([^\]\r\n]+)\]/u',$text,$matches);foreach($matches[1] as $token)if(!isset(self::TOKENS[$token]))throw new RuleViolation('Campo de e-mail desconhecido: ['.$token.'].');$out['modelos'][$type][$field]=$text;}}
        update_option(Coligadas::option(self::OPTION),$out,false);return self::payload();
    }
}
