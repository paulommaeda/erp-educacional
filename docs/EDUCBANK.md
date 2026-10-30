# Integração Educbank — 0.16.4, primeira etapa

## Configurar

No portal, como administrador WordPress, abra **Configurações → Educbank**. Selecione antes a coligada de trabalho.

1. Em **Configurar conexão**, informe o tenant e o token fornecidos pelo Educbank. Há credenciais independentes para sandbox e produção. Informe só o tenant, sem protocolo ou domínio.
2. Selecione o plano contratado (`ZeroDefault`, `Gateway`, `PreEnrollment` ou `EducbankGateway`) e o identificador do produto mensalidade cadastrado no parceiro. O identificador não é o nome do plano de pagamento do ERP.
3. Configure multa e **juros diários** em percentual conforme o acordo. Os padrões são zero; o plugin não presume taxas. O desconto por pontualidade continua na configuração existente do ERP.
4. Em **Mapear período e séries**, associe o período ao ano acadêmico e ciclo anual/semestral. Associe cada turma a `EI:1`–`EI:5`, `EF:1`–`EF:9`, `EM:1`–`EM:3` ou `Extras`. A série não é deduzida do nome ou do curso: turmas do mesmo curso podem ter séries diferentes.
5. Use **Testar conexão**. Essa ação consulta matrículas e faturas, sem criar registros. A autorização para criar/alterar e as regras dos cadastros são conferidas no processamento da fila.
6. Ative a integração em sandbox e confira os envios. Para cadastros anteriores à ativação, selecione o período e use **Enfileirar até 20 matrículas**; novos lotes dependem de clique explícito. A ativação não faz exportação integral do histórico.

O CNPJ vem da coligada proprietária da matrícula. O nome, CPF válido, CEP brasileiro e número do endereço do responsável financeiro precisam estar cadastrados. Falhas de cadastro e mapeamento aparecem na fila; não são preenchidas com dados inventados.

## O que é enviado

| ERP | Educbank |
|---|---|
| `idmatricula` | `Enrollment.externalId`, como string |
| Pessoa do aluno | `student.name`, `birthDate`, `gender` |
| RF vigente do aluno | `guardians`, com documento, endereço, contatos, parentesco e principal |
| CNPJ da coligada | `companyDocument` / `companyCnpj` |
| Período e turma mapeados | `academicYear`, `term`, `academicProgram`, `academicClassName` |
| `idlancamento` de cada parcela | `Invoice.externalId`, como string |
| UUID da matrícula retornado pelo parceiro | `items[].enrollmentId` |
| RA | `items[].studentExternalId`, preservando zeros |
| `idcontrato` | `items[].externalOrderId` |
| Valor original | `items[].valueCents` inteiro |
| Bolsa / desconto incondicional | `items[].fixedDiscountCents` inteiro |
| Pontualidade configurada | `earlyPaymentDiscounts`, `days: 0`, valor em centavos |
| Vencimento atual | `dueDate` |
| Mês do vencimento original da parcela | `referenceDate`, YYYY-MM; competência preservada ao prorrogar vencimento |

O líquido continua sendo original menos desconto incondicional. A pontualidade é um benefício até o vencimento e não é deduzida antecipadamente do líquido. Uma mensalidade corresponde a uma fatura, com um item, quantidade 1 e a matrícula Educbank do aluno.

## Fluxo e proteção contra duplicação

- Inserções/alterações do banco registram a intenção de sincronização em `educbank_fila`, na mesma transação do ERP. Não há HTTP segurando a transação acadêmica/financeira.
- O processamento cria primeiro a matrícula. Faturas aguardam a confirmação do UUID dela.
- O worker consulta `externalId` antes de qualquer POST. Respostas perdidas depois da criação são reconciliadas com matrícula/aluno/CNPJ/contrato, sem aceitar silenciosamente outro registro com o mesmo identificador.
- Vínculos únicos por coligada, ambiente, tenant, tipo e ID local. Sandbox e produção não compartilham UUIDs. Uma mudança de tenant com vínculos existentes exige migração, em vez de reenviar o histórico para outro destino.
- Alterações de turma e RF atualizam a matrícula e as faturas abertas. Valor original, bolsa, pontualidade e vencimento atualizam a mesma fatura por PUT.
- Cancelamentos locais de faturas futuras são comunicados pelo endpoint de cancelamento. Não se cancela automaticamente uma fatura informada como paga pelo parceiro.
- Uma edição durante HTTP mantém a intenção mais recente pendente; a resposta antiga não elimina a alteração nova.
- O processamento tem trava por coligada/ambiente/tenant, lotes pequenos e retentativa com intervalo crescente. Após oito falhas, exige conferência. **Reprocessar** recoloca o registro na fila.
- Credenciais são criptografadas com AES-256-GCM e chave derivada do salt WordPress. Tokens não aparecem nas respostas ou exportações. Trocar os salts exige cadastrar os tokens novamente.

Rematrícula continua criando contrato sem parcelas. A matrícula pode ser enviada ao Educbank imediatamente; as faturas só entram na fila depois que o financeiro gera as parcelas definitivas.

## Limites desta etapa

Esta versão implementa envio/criação e atualização, não baixa automática. O status exibido na fila corresponde à última resposta de sincronização. Pagamentos/estornos recebidos no Educbank e pagamentos feitos na secretaria precisam de conciliação: ainda não há webhook nem chamada automática de recebimento na escola. Lançamentos já pagos antes da integração não geram outra cobrança; lançamentos parcialmente pagos ou com encargos/baixa aplicados exigem conferência antes de envio.

Encerrar a matrícula no ERP não exclui automaticamente a matrícula no Educbank. O endpoint de exclusão acadêmica possui efeitos próprios; a fila pede conferência desse vínculo e comunica separadamente os cancelamentos financeiros já definidos no ERP, preservando os débitos anteriores.

CNPJ, ano e semestre de matrícula já criada são conferidos antes de atualizar. Divergências não são marcadas como sincronizadas por um PUT que não suporta alterar esses campos.

WP-Cron executa pequenos lotes a cada cinco minutos, conforme a execução do cron do site. **Processar fila** executa até três registros. A fila só funciona após a migração 33; a atualização começa desativada, em sandbox. Para uso operacional contínuo, configure a execução regular do cron no servidor.

## Rotas do ERP

Base: `/wp-json/erp-educacional/v1/educbank`. Todas autenticadas e exclusivas de administrador. POST usa JSON e nonce REST; o enfileiramento também exige `Idempotency-Key`.

| Método | Rota | Finalidade |
|---|---|---|
| GET / POST | `/configuracao` | Ler/salvar configuração sem devolver tokens |
| GET | `/mapeamentos?codperiodo=ID` | Períodos e turmas da coligada |
| GET | `/fila?page=1&status=erro` | Histórico paginado e filtros |
| POST | `/testar` | Consultar conexão sem emissão |
| POST | `/enfileirar` | Período, cursor opcional, até 20 matrículas |
| POST | `/processar` | Processar um lote |
| POST | `/fila/ID/reprocessar` | Tentar novamente na mesma coligada/ambiente |

## Referências e validação

Lidas em 30/10/2026; DTOs completos obtidos nas versões Markdown/OpenAPI oficiais:

- https://educbank.readme.io/reference/o-educbank
- https://educbank.readme.io/reference/autenticação
- https://educbank.readme.io/reference/ambientes
- https://educbank.readme.io/reference/post_api-ebpay-enrollment
- https://educbank.readme.io/reference/get_api-ebpay-enrollment
- https://educbank.readme.io/reference/put_api-ebpay-enrollment-id
- https://educbank.readme.io/reference/post_api-ebpay-invoice-1
- https://educbank.readme.io/reference/get_api-ebpay-invoice-1
- https://educbank.readme.io/reference/put_api-ebpay-invoice-id-1
- https://educbank.readme.io/reference/post_api-ebpay-invoice-id-cancel
- https://educbank.readme.io/reference/emissão-de-faturas

Testes PHP-WASM/SQLite com HTTP simulado: ordem de envio; valores e descontos; RF; CNPJ; rematrícula diferida; timeouts de matrícula/fatura; concorrência; conflito de externalId; rollback; autorização; isolamento de ambiente/coligada; lotes idempotentes; ausência de mapeamento. Regressões de planos, bolsas, perfis, dados de pessoas, exportação e índices. JSDOM e Chromium em 1440/390/320 px: configuração, mapeamento, senhas vazias/ocultas, fila, paginação, reprocessamento e ausência de loops.

Não foram usadas credenciais reais nem emitidas cobranças no Educbank. Migração dbDelta/InnoDB, concorrência entre processos MySQL e homologação com o tenant de sandbox precisam ser verificados na instalação. Durante a regressão foi atualizado um teste antigo que esperava RA informado na criação manual; o sistema vigente gera RA a partir do código da pessoa. A verificação de preservação dos zeros do RA importado continua coberta.

## Indicador nas parcelas — 0.16.1

Na Gestão financeira e na aba Financeiro da ficha do aluno, a situação de pagamento permanece acompanhada de um indicador separado:

- **Integrado (verde):** existe um UUID da fatura e a última sincronização foi confirmada para a coligada, tenant e ambiente selecionados.
- **Não integrado (vermelho):** não há envio neste ambiente, há erro/conferência, atualização pendente ou falta confirmação do UUID.

O aviso também aparece nos detalhes da parcela. A descrição acessível/tooltip informa Sandbox ou Produção e o motivo resumido. Uma sincronização de sandbox não confirma integração em produção. Pausar os envios preserva as confirmações já concluídas; novas atualizações pendentes voltam ao vermelho até serem confirmadas.

Consultar parcelas não envia cobranças nem consulta o Educbank: os indicadores vêm da fila local, em lote. Eles não alteram valores ou situações Em aberto/Vencido/Pago/Cancelado. Os dados técnicos de integração são omitidos da consulta do responsável sem perfil operacional financeiro.

Novas matrículas e parcelas são enfileiradas automaticamente quando a integração está ativa. O processamento agendado ocorre em lotes a cada cinco minutos, conforme a execução do cron do site; não é envio simultâneo à gravação. Cadastros anteriores à ativação precisam ser enfileirados pela tela Educbank. Na rematrícula, as faturas continuam aguardando a geração financeira definitiva das parcelas.

Validação adicional: testes PHP/SQLite dos estados, isolamento de ambiente/tenant, proteção do portal familiar e preservação financeira; JSDOM da gestão e detalhes, incluindo integrado e não integrado para parcelas pagas e em aberto; conferência visual em desktop e mobile. Sem alteração do schema 33.

## Erro 400 apesar de consulta confirmada — 0.16.2

O teste original consultava somente matrículas, com `maxResultCount=1`. O processamento usava também `showCanceledEnrollments` e `showCanceledInvoices`: são booleanos nas definições oficiais do Educbank. O `http_build_query` do PHP convertia esses valores para `1`/`0`; agora eles são serializados como `true`/`false`. Valores numéricos e identificadores, inclusive zeros à esquerda, continuam preservados. O JSON do corpo mantém seus tipos originais.

Essa falha de serialização pode produzir erro 400 na consulta de prevenção de duplicação, antes mesmo do POST de criação. Sem o corpo da resposta original do colégio, não se presume que seja a única causa. O teste agora exercita as duas consultas e seus filtros; não cria registros nem promete validar antecipadamente a emissão.

A fila informa HTTP, operação e motivos de validação devolvidos pelo parceiro nos formatos ABP (`error.validationErrors`, `members`, `message`, `details`) e ValidationProblemDetails (`errors`, `title`). Exemplos: `ao consultar matrículas`, `guardians[0].address.zipCode: CEP obrigatório`. Credenciais, documentos e e-mails são ocultados; dados extras, stack trace e respostas HTML não são copiados. A mensagem é limitada ao tamanho da coluna, preservando UTF-8.

Após atualizar, use **Reprocessar** no registro com erro e depois **Processar fila**. Preserve os cadastros e IDs existentes; não é necessário recriar matrícula ou parcelas. Ao confirmar a matrícula, faturas que estavam exclusivamente aguardando essa confirmação são liberadas automaticamente, inclusive se chegaram a Conferir por excesso de tentativas de espera. Outras falhas de fatura mantêm sua própria conferência. A fila usa lotes, portanto o restante pode seguir nos próximos processamentos.

Validação: servidor simulado rejeita `1`/`0` para os filtros booleanos; testes cobrem consulta e envio com `true`/`false`, mensagens ABP/ProblemDetails, proteção de credenciais, truncamento com acentos, retorno seguro de HTML, retomada das dependências e preservação do controle de duplicação. Sem acesso ao tenant ou credenciais reais do colégio nesta alteração. Não muda o schema 33.


## CEP não encontrado ao criar matrícula — 0.16.3

Uma resposta como **HTTP 404 ao criar matrícula: CEP não encontrado** informa uma recusa do endereço pelo Educbank. O CEP enviado nessa operação é o do **responsável financeiro vigente**, em `guardians[0].address.zipCode`; na fatura, é `payer.address.zipCode`. A mensagem não indica falha de autenticação nem uma rota inexistente: o detalhe aponta para a consulta do CEP. O teste de conexão só realiza consultas, por isso pode passar mesmo com um endereço recusado no cadastro.

1. Em **Pessoas**, localize o responsável financeiro indicado no erro, abra **Editar → Endereço** e confira o CEP do endereço real nos Correios.
2. Se houver erro cadastral, corrija e salve. A edição reenfileira os registros vinculados enquanto a integração estiver ativa. Depois use **Processar fila**; se necessário, **Reprocessar** no registro.
3. Se o CEP estiver correto e a recusa persistir, peça ao Educbank a conferência da base de CEPs no ambiente indicado (sandbox ou produção). Não substitua o CEP por outro apenas para passar na validação.

O erro agora inclui o código da pessoa responsável e o caminho do campo, mantendo a resposta do parceiro, o ambiente e o limite seguro de 480 bytes com UTF-8 válido. Em cadastros sem código, identifica o ID interno da pessoa. O plugin continua enviando apenas os oito dígitos, sem máscara. Ele não presume a existência de um CEP só pelo formato, não troca o endereço automaticamente e não adiciona dependência de um serviço de CEP externo.

Após a correção e confirmação da matrícula, as faturas aguardando esse vínculo podem seguir nos próximos lotes. Matrícula, contrato, parcelas e IDs existentes são preservados; não é necessário recriá-los. A atualização melhora o diagnóstico e não altera a base postal do Educbank.

Validação com HTTP simulado: recusa 404 no POST de matrícula, espera das faturas, correção do cadastro, criação sem duplicação e recusa no PUT de fatura com mensagem longa e acentuada. Sem credenciais reais ou alterações em cadastros do colégio; schema 33 mantido.


## Link de pagamento e visão da família — 0.16.4

**Meu financeiro** usa uma lista própria com somente seis colunas: Valor original, Desconto condicional, Desconto incondicional, Valor líquido, Status e Pagamento. O desconto condicional exibido é o previsto nas configurações da coligada (`desconto_condicional_previsto`), com fallback para o campo legado. Ele não é descontado antecipadamente do valor líquido contratual. Os estados mantêm as cores: Em aberto amarelo pastel, Vencido vermelho pastel, Pago verde pastel e Cancelado cinza. No mobile, cada lançamento vira um cartão com os mesmos campos.

O botão **Pagar** abre em nova aba a URL da fatura salva na integração, para parcelas em aberto ou vencidas com vínculo e última sincronização confirmados. Faturas sem URL válida ou com atualização pendente exibem Aguardando fatura. Parcelas pagas/canceladas e faturas que o parceiro já informou como Paid/Canceled não oferecem novo pagamento ao responsável.

Na **Gestão financeira**, o menu de ações de cada linha inclui **Abrir fatura**. O link também aparece nos detalhes do lançamento e na aba Financeiro da ficha do aluno. A gestão pode consultar o link já conhecido inclusive quando há uma atualização pendente ou o lançamento está encerrado, como na checagem da integração.

O endpoint financeiro acrescenta `url_fatura` (HTTPS válido ou null) somente aos lançamentos que a autorização e o filtro do responsável já permitem consultar. O vínculo é buscado em lote, pela coligada proprietária, tenant e ambiente selecionados. O portal familiar continua sem o objeto técnico `educbank`, erros da fila ou UUID remoto. As leituras usam o banco local, sem requisições ao parceiro, criação de cobrança ou envio de credenciais ao navegador.

Sem alteração do schema 33. Validação PHP/SQLite e HTTP simulado: autorização por pagador, escopo de coligada/tenant/ambiente, estados e URLs inválidas; JSDOM: colunas, valores, links, gestão e troca de aluno; Chromium em 1440, 390 e 320 px: status coloridos, alvo de toque, menu sem alteração da altura da linha e ausência de overflow. Não foram realizados pagamentos nem emissões reais para testar esta mudança.
