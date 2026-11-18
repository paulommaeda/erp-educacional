<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
final class DocumentMail
{
    public static function render(string $subject,string $body,string $link,bool $staff):string
    {
        $school=SchoolIdentity::read();$logo=SchoolIdentity::logo();$color=$school['cor_primaria'];$title=esc_html($subject);$name=esc_html($school['nome']);
        return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title></head><body style="margin:0;background:'.$school['cor_fundo'].';color:'.$school['cor_texto'].';font-family:Arial,sans-serif;line-height:1.6;overflow-wrap:anywhere;word-break:break-word"><table role="presentation" width="100%" style="table-layout:fixed;width:100%"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="600" style="width:100%;max-width:600px;table-layout:fixed;background:'.$school['cor_superficie'].';border-radius:16px"><tr><td style="padding:28px 24px;overflow-wrap:anywhere;word-break:break-word;border-top:6px solid '.$color.'">'.($logo?'<img src="'.esc_url($logo).'" width="220" alt="'.$name.'" style="display:block;max-width:100%;height:auto;margin-bottom:24px">':'<p>'.$name.'</p>').'<h1 style="font-size:22px;line-height:1.3">'.$title.'</h1><p>'.nl2br(esc_html($body)).'</p><p><a href="'.esc_url($link).'" style="display:inline-block;padding:12px 20px;background:'.$color.';color:'.SchoolIdentity::contrast($color).';text-decoration:none;border-radius:8px">'.($staff?'Analisar documentos':'Abrir documentos do aluno').'</a></p><p style="margin-top:28px;border-top:1px solid '.$school['cor_destaque'].';padding-top:16px">'.$name.'<br>Secretaria escolar</p></td></tr></table></td></tr></table></body></html>';
    }
}
