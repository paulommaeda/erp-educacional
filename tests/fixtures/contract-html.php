<?php
/** Fictitious layout content. No personal data or clauses copied from the user's reference. */
$html='<h1>CONTRATO DE DEMONSTRAÇÃO</h1><p style="text-align:center"><strong>Documento fictício para conferência da impressão</strong></p><p>Aluno: <strong>ESTUDANTE DE DEMONSTRAÇÃO</strong> · RA DEMO-2027<br>Responsável: PESSOA FICTÍCIA · Contrato DEMO-2027</p>';
$paragraph='Este é um conteúdo fictício usado exclusivamente para conferir a apresentação do documento. Os dados exibidos são de demonstração. O texto deve permanecer legível, com acentuação correta e alinhamento justificado, respeitando a separação entre os parágrafos e os limites da área de impressão. A logo e os contatos pertencem à configuração usada neste exemplo, e o rodapé precisa acompanhar todas as páginas sem cobrir o conteúdo. ';
for($i=1;$i<=34;$i++){
    $html.='<p><strong>SEÇÃO '.$i.' — Demonstração de formatação.</strong> '.$paragraph.$paragraph.'</p>';
    if($i===2)$html.='<ul><li>Negrito: <strong>informação em destaque</strong>.</li><li>Itálico: <em>observação de demonstração</em>.</li><li>Sublinhado: <u>ponto para conferência</u>.</li></ul>';
    if($i===16)$html.='<table border="1"><thead><tr><th>Descrição</th><th>Valor demonstrativo</th></tr></thead><tbody><tr><td>Mensalidade fictícia</td><td>R$ 1.353,00</td></tr><tr><td>Pagamento em dia</td><td>R$ 1.250,00</td></tr></tbody></table>';
}
$html.='<div style="page-break-inside:avoid"><p style="text-align:center">Cidade de demonstração, 18 de novembro de 2026.</p><table><tr><td style="text-align:center;padding-top:20pt">_________________________________<br>RESPONSÁVEL FICTÍCIO</td><td style="text-align:center;padding-top:20pt">_________________________________<br>COLÉGIO DE DEMONSTRAÇÃO</td></tr></table></div>';
return $html;
