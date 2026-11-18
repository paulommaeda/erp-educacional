# Contratos em HTML e impressão — 0.19.30

Em **Contratos → Modelos de contrato → Novo modelo/Editar modelo**, cadastre o conteúdo completo no campo **Texto com shortcodes**. Você pode colar HTML de formatação. O formato é identificado automaticamente; para preservar tags como texto literal, escolha **Texto simples**. A edição individual do contrato oferece as mesmas opções.

São aceitos parágrafos e quebras de linha, títulos, negrito, itálico, sublinhado, listas, tabelas, links e estilos de fonte, alinhamento, espaçamento e borda. Use **Prévia com dados de um contrato** para conferir o preenchimento antes de salvar. Scripts, formulários, folhas de estilo e recursos externos são retirados. Os shortcodes continuam sendo os campos da lista do ERP; dados preenchidos são tratados como texto, inclusive quando contêm sinais de HTML.

```html
<h1>CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS</h1>
<p style="text-align:justify">
  <strong>[responsavel_nome]</strong>, CPF [responsavel_cpf],
  responsável pelo(a) aluno(a) <strong>[aluno_nome]</strong>, RA [aluno_ra].
</p>
<p><strong>Período letivo:</strong> [periodo_codigo].</p>
```

O trecho é apenas um exemplo de formatação. O colégio deve cadastrar seu próprio texto completo.

Ao revisar o texto ou seu formato, informe uma nova versão. Salvar um modelo atualiza os textos dos contratos vinculados ainda não assinados. O texto, formato, versão, origem e registro de assinatura dos contratos marcados como assinados permanecem bloqueados para todos os perfis. Bolsas e operações financeiras mantêm as regras existentes.

## Imprimir

1. Selecione a coligada de trabalho e abra **Contratos → Ver contrato → Imprimir contrato**.
2. O ERP prepara um PDF A4 e abre o leitor de PDF do navegador. Use o botão de impressão do leitor. Se o navegador bloquear a janela, use o link do PDF mostrado no próprio contrato.
3. A logo aparece no alto da primeira página, seguida de uma linha. Todas as páginas recebem o rodapé com endereço, telefone, site, e-mail e **Página: X de Y**, como no modelo visual fornecido.

Configure esses dados em **Configurações → Colégio e cores**, na coligada correspondente: logo, rua, número, complemento, bairro, cidade, UF, CEP, telefone, site e e-mail. Campos vazios são omitidos e textos longos do rodapé quebram em mais linhas, reservando espaço no documento. A impressão lê a configuração atual sem reescrever o texto do contrato assinado. Se não houver logo configurada, o cabeçalho usa o nome do colégio; a identidade padrão já utilizada pelo ERP continua disponível quando ainda não existe configuração salva.

O corpo do contrato segue o conteúdo cadastrado; títulos, cláusulas e assinaturas devem estar nesse texto. O ERP acrescenta apenas a identidade visual e a paginação da impressão. Fontes padrão seguem as equivalências do PDF; para fonte incorporada com mais caracteres Unicode, pode-se usar `font-family:DejaVu Sans` ou `DejaVu Serif`. Não é necessário colar a logo ou os dados de rodapé no modelo.

## Acesso e atualização

A impressão exige a permissão **Contratos**, confere a coligada atual e retorna o arquivo pela sessão autenticada, sem gravar PDFs em uploads públicos. A rota `GET /wp-json/erp-educacional/v1/contratos/{id}/pdf` retorna `nome`, `mime: application/pdf` e `conteudo` em Base64, com cache privado desabilitado.

A versão 0.19.30 usa schema 47. A migração acrescenta somente `formato_texto` aos modelos e contratos, com padrão `texto`, preservando conteúdo anterior, assinaturas, hashes, pagamentos e saldos. Documentos antigos não são reinterpretados como HTML. Para imprimir HTML, a hospedagem precisa das extensões PHP DOM e MBString; logos PNG precisam de GD, e o WordPress precisa de uma pasta temporária gravável. Logos JPEG/PNG são lidas localmente; GIF/WebP usam o editor de imagens do WordPress para conversão.

Consulte a atualização em **Plugins → ERP Educacional → Verificar atualizações**. A instalação segue as configurações do WordPress do site. Veja [as verificações realizadas](VALIDACAO.md).
