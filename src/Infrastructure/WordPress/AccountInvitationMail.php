<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;

/** Email-safe markup; clients without web-font support use the portal's fallback stack. */
final class AccountInvitationMail
{
    public static function render(array $person,\WP_User $user,string $url,?array $student=null,?array $guardian=null):string
    {
        $school=SchoolIdentity::read();$logo=SchoolIdentity::logo();$e=static fn($v)=>esc_html((string)$v);
        $font="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif";
        $title=$student?'Crie a senha do aluno':'Crie sua senha de acesso';
        $name=$guardian['nome']??$person['nome'];
        $copy=$student?'Este convite é para criar a senha da conta do aluno <strong>'.$e($person['nome']).'</strong>, RA <strong>'.$e($student['ra']).'</strong>.':'Seu cadastro no portal do '.$e($school['nome']).' foi concluído. Defina sua senha para começar a usar sua conta.';
        if($guardian)$copy.=' Você está recebendo este e-mail por ser responsável acadêmico pelo aluno. <strong>O botão abaixo define a senha do aluno; a senha da sua conta de responsável permanece a mesma.</strong>';
        $details='<p style="margin:20px 0 6px"><strong>'.($student?'Usuário do aluno':'Seu usuário').':</strong> '.$e($user->user_login).'</p>';
        if($student)$details.='<p style="margin:0 0 20px"><strong>Aluno:</strong> '.$e($person['nome']).'<br><strong>RA:</strong> '.$e($student['ra']).'</p>';
        $button=$student?'Criar senha do aluno':'Criar minha senha';$color=$school['cor_primaria'];$onColor=SchoolIdentity::contrast($color);
        return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$e($title).'</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet"></head><body style="margin:0;padding:0;background:'.$school['cor_fundo'].';color:'.$school['cor_texto'].';font-family:'.$e($font).';line-height:1.6">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:'.$school['cor_superficie'].';border-radius:16px"><tr><td style="padding:28px 24px;border-top:6px solid '.$color.'">'
            .($logo?'<img src="'.esc_url($logo).'" alt="'.$e($school['nome']).'" width="220" style="display:block;width:220px;max-width:100%;height:auto;margin-bottom:24px">':'<p style="font-size:22px;font-weight:700">'.$e($school['nome']).'</p>')
            .'<h1 style="font-size:24px;line-height:1.3;margin:0 0 20px">'.$e($title).'</h1><p>Olá, '.$e($name).'.</p><p>'.$copy.'</p>'.$details
            .'<p style="margin:28px 0"><a href="'.esc_url($url).'" style="display:inline-block;padding:14px 22px;border-radius:10px;background:'.$color.';color:'.$onColor.';text-decoration:none;font-weight:700">'.$e($button).'</a></p>'
            .'<p>O link é pessoal, funciona uma única vez e expira em até 24 horas. Escolha uma senha longa e exclusiva. Nenhuma senha é enviada por e-mail.</p><p>Se o botão não abrir, copie este endereço para o navegador:</p><p style="font-size:12px;word-break:break-all;overflow-wrap:anywhere"><a href="'.esc_url($url).'" style="color:'.$color.'">'.$e($url).'</a></p><p>Se precisar de outro convite ou não reconhecer este cadastro, procure a secretaria.</p>'
            .'<p style="margin-top:28px;padding-top:16px;border-top:1px solid '.$school['cor_destaque'].';font-size:13px">'.$e($school['nome']).'<br>Portal educacional</p></td></tr></table></td></tr></table></body></html>';
    }
}
