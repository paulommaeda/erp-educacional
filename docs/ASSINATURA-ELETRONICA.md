# Assinatura eletrônica de contratos

O ERP 0.19.37 envia o PDF de impressão ao plugin Assinatura Eletrônica Educacional 1.1.0. O responsável financeiro recebe um convite no e-mail cadastrado, confirma CPF e data de nascimento, lê o documento e assina com o código recebido por e-mail.

## Conectar a coligada

1. No WordPress de assinaturas, crie um usuário com o papel **Gestor de contratos** e gere uma senha de aplicativo no perfil desse usuário.
2. No ERP, selecione a coligada e abra **Contratos → Configurar assinatura eletrônica** como administrador. Informe a URL HTTPS da API, por exemplo `https://assinaturas.exemplo.com.br/wp-json/edu-sign/v1`, o login e a senha de aplicativo.
3. Para receber a confirmação automática, cadastre o mesmo segredo nos dois sites. Use um valor aleatório com 32 a 256 caracteres ASCII sem espaços. No ERP, copie a **URL de retorno do ERP**. No outro site, cole a URL e o segredo em **Contratos educacionais → Configurações → Retorno ao ERP Educacional**. Quando várias coligadas usam o mesmo site de assinaturas, configure nelas o mesmo segredo de retorno desse site.
4. Salve a configuração. As credenciais preenchidas ficam protegidas no servidor. Deixe os campos de senha e segredo vazios ao editar para conservar o valor cadastrado. A troca de endereço ou usuário exige a senha de aplicativo correspondente.

## Enviar e acompanhar

Em **Contratos → Ver contrato**, confira o texto, as bolsas e o destinatário. Em **Assinatura eletrônica**, clique em **Enviar PDF para assinatura**. Cada envio identifica a coligada, o contrato e a revisão. O original inclui a identidade visual e a redação que o ERP usa na impressão. O limite do original é de 8 MiB. Corrija CPF, data de nascimento ou e-mail no cadastro da pessoa quando a conferência do responsável impedir a emissão.

| Situação | Ação disponível |
| --- | --- |
| Envio a confirmar | Use **Consultar situação** ou **Retomar envio**. O ERP procura a referência antes de repetir a emissão com os mesmos bytes. |
| Convite solicitado ou visualizado | Consulte a situação ou cancele a solicitação. O site de assinaturas controla a entrega do e-mail. |
| Assinado | Baixe o PDF com o certificado em **Baixar PDF assinado**. O ERP confere o hash do arquivo recebido. |
| Cancelado, expirado ou envio recusado | Confira o motivo e o texto antes de abrir uma nova revisão. |

O aviso do assinador e a consulta autenticada podem confirmar uma assinatura. O ERP conserva a data UTC, o protocolo, os hashes original e final e o nome do responsável que assinou. A confirmação bloqueia a redação correspondente. Se o texto ou o responsável financeiro tiver mudado desde o envio, o PDF assinado continua disponível na revisão anterior e a redação atual aguarda uma nova emissão. As bolsas do aluno aparecem no PDF conforme os campos usados no modelo.

O WP-Cron consulta os envios pendentes a cada hora. A consulta manual está disponível mesmo quando o aviso ainda não chegou. Falhas de comunicação conservam a última situação confirmada. Cada revisão continua associada ao endereço do site que a emitiu. Para alterar um envio em andamento, cancele a solicitação no serviço e emita uma nova revisão.

## Trabalhar com vários contratos

1. Abra **Contratos → Contratos dos alunos**. Use o filtro **Assinatura** para listar assinados ou não assinados. O filtro considera a assinatura da redação em vigor, inclusive quando registrada manualmente. Uma assinatura de revisão anterior continua no histórico.
2. Marque os contratos da lista ou use a caixa à esquerda dos botões de ação para selecionar os contratos elegíveis da página. A seleção permanece ao navegar pelas páginas e é limpa ao aplicar filtros. É possível selecionar até 100 contratos por lote.
3. Use **Enviar para assinatura** para os contratos ativos ainda elegíveis. Revise a lista e clique em **Confirmar envio**. O ERP solicita os convites aos responsáveis financeiros. Envios que já aguardam assinatura podem ser conferidos.
4. Use **Conferir assinaturas**, revise a lista e clique em **Confirmar conferência**. O ERP consulta o protocolo de cada revisão selecionada e mostra o resultado por contrato, inclusive quando a consulta falha. Mantenha a tela aberta até a conclusão.

## Rotas no ERP

As rotas usam o prefixo `/wp-json/erp-educacional/v1`. A configuração exige administrador. A consulta do contrato, o envio, o cancelamento e o download exigem o acesso **Contratos** na coligada. A rota de retorno verifica a assinatura HMAC do corpo recebido, o horário, a referência, o protocolo e o hash do original antes de gravar o evento.

| Método | Rota | Uso |
| --- | --- | --- |
| GET, POST | `/assinatura-eletronica/configuracao` | Consultar ou gravar a conexão da coligada |
| GET | `/contratos/{id}/assinatura` | Ver as revisões e os protocolos |
| POST | `/contratos/{id}/assinatura/enviar` | Emitir com a versão atual do contrato e cabeçalho `Idempotency-Key` |
| POST | `/contratos/{id}/assinatura/{envio}/consultar` | Consultar o protocolo |
| POST | `/contratos/{id}/assinatura/{envio}/cancelar` | Cancelar a solicitação no assinador |
| GET | `/contratos/{id}/assinatura/{envio}/pdf` | Receber o PDF assinado com nome, tipo e conteúdo em Base64 |
| POST | `/assinatura-eletronica/retorno` | Receber os eventos de assinatura, cancelamento e expiração |

O protocolo do provedor descreve os campos e as respostas de cada operação. [Integração do assinador com o ERP](https://github.com/paulommaeda/assinatura-eletronica/blob/main/INTEGRACAO-ERP.md)
