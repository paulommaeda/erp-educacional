# Validação

## 0.19.36

- WordPress 7.1.3, PHP 8.4.27 e MariaDB 11.4.8. As suítes nativas de bolsas do aluno, concessões do contrato e texto/impressão passaram, respectivamente, em 38, 37 e 43 verificações. A migração do schema 48 para o 49 foi aplicada duas vezes sobre dados históricos. A API conferiu permissões, coligada, prévia desatualizada e matrícula por operador acadêmico.
- Em SQLite, a suíte de bolsas do aluno passou em 25 verificações, incluindo a baixa de R$ 1.350,00, contratos futuros, redação assinada, pagamentos parciais, desativação e reversão integral em caso de conflito. As suítes de descontos, Educbank, matrícula, contratos, exportação e rematrícula também passaram. Foram exercitadas as transições do 5º para o 6º ano e do 9º ano para o ensino médio com a bolsa ativa.
- Em JSDOM, foram validados o cadastro antes do primeiro contrato, a edição na ficha, a apresentação de título e descrição e as prévias individual e em lote. O teste legado `workflow-ui.cjs` já interrompia na versão 0.19.35 ao buscar o seletor de responsável antes de clicar em Vincular pai. A suíte atual da ficha do aluno cobre o fluxo.

## 0.19.35

- Em WordPress 7.1.3, PHP 8.4.27 e MariaDB 11.4.8 locais, `tests/wordpress-contract-html.php` passou nas 43 verificações. Um shortcode de teste, registrado pela API do WordPress, forneceu texto para um modelo HTML e para um contrato personalizado. A suíte verificou parâmetros fixos, permissões, isolamento por coligada, retirada do provedor, impressão e preservação do contrato assinado.
- A extração do texto do PDF confirmou o nome da unidade e o marcador sem provedor exatamente como apareceram na prévia. A suíte independente de impressão também gerou o PDF de referência.
- As seis suítes PHP/SQLite de contratos, HTML, campos do responsável, plano, permissões e assinatura passaram nas 299 verificações. As cinco suítes de interface de contratos, HTML, permissões, assinatura e matrícula passaram com a API simulada, incluindo a seleção do shortcode pela administração e a inserção no cursor.
- A instalação no site do colégio e a conferência com o plugin externo usado pela escola dependem da atualização local e do provedor escolhido.

## 0.19.34

- Chrome em 1366 e 390 px, com o script do editor e a API simulada. A tela de novo modelo abriu com uma única cláusula de anuidade e os dois campos de vencimento. Em modelo existente, foram testadas a substituição da seleção e a inserção no cursor, a ocultação do botão enquanto a cláusula estiver presente e sua reapresentação após a remoção.
- A prévia com dados fictícios exibiu anuidade de R$ 16.236,00 em 12 parcelas de R$ 1.353,00. A requisição de gravação ocorreu somente ao salvar, com o texto, a versão e o motivo da alteração. Os campos usados foram conferidos no catálogo de shortcodes do servidor.
- Sintaxe de `assets/contracts.js` e `tests/contracts-ui.cjs` conferida com Node. Validação desta entrega limitada ao editor, sem execução em WordPress/MySQL. Schema 48.

## 0.19.33

- `tests/contract-plan-shortcodes.php`: 52 verificações SQLite. Novos campos de parcela e parcelamento originais conferidos na emissão e na prévia, antes/depois da geração e com bolsas de 50%, integral, fixa ou por faixa. Total e parcela originais permanecem em R$ 16.236,00/R$ 1.353,00, sem deduzir benefícios nem acrescentar pontualidade novamente. Conferidos também centavos, legado com descontos, campos líquidos anteriores, pagamentos parciais, HTML, permissões, coligada, assinatura e preservação das cobranças.
- Seis suítes PHP aprovadas: plano/shortcodes, contratos/modelos, assinatura, HTML, dados do assinante e permissões. Sintaxe dos PHP alterados conferida. Três suítes de interface aprovadas: contratos, HTML/impressão e matrícula com modelo. O exemplo inicial usa os campos originais e os botões inserem os novos campos na posição do cursor.
- `tests/wordpress-contract-html.php`: 29 verificações no WordPress 7.1.3/MariaDB 11.4.8. REST autorizada oferece campos originais e líquidos, confere DECIMAL e garante total/parcela originais uniformes com bolsa por faixa. Geração, PDF privado, identidade de duas coligadas e texto assinado preservados. E-mails e HTTP interceptados; nenhuma instalação no site do colégio.
- PDF real teve total e parcelas originais extraídos/conferidos e página A4 renderizada/inspecionada, sem cortes ou sobreposições no cabeçalho, corpo ou rodapé.
- Schema permanece 48. A atualização não muda preços, benefícios, pagamentos ou textos assinados; modelos existentes precisam ser editados/salvos para usar os campos originais.

## 0.19.32

- `tests/contract-plan-shortcodes.php`: 41 verificações SQLite. Total original, bolsas e total líquido usam o parcelamento do aluno, com quatro novos campos: total, valor da parcela, parcelamento e dia de vencimento. Conferidos alunos com quantidades/dias próprios, antes/depois da geração, bolsas de 50%/integral/fixa/por faixa, divisão exata dos centavos, dia 31 em fevereiro, edição posterior do catálogo, condições pendentes, contratos legados com descontos por faixa e pagamentos parciais. Prévia/modelo em HTML, permissões, coligada, assinatura, integridade e preservação das cobranças também validados.
- 13 suítes PHP aprovadas: campos do plano, contratos/modelos, assinatura, HTML, dados do assinante, descontos, bolsas, pontualidade, planos, permissões, gestão financeira, ciclo da matrícula e Educbank/bolsas. Sintaxe conferida nos seis PHP e dois JavaScript envolvidos.
- Quatro suítes de interface aprovadas: contratos, assinatura, matrícula com modelo e HTML/impressão. O editor oferece os novos campos, insere-os na posição do cursor e usa total/parcelamento/dia no texto inicial de novos modelos.
- `tests/wordpress-contract-html.php`: 27 verificações no WordPress 7.1.3/MariaDB 11.4.8. Campos disponíveis pela REST autorizada, valores DECIMAL, dia isolado, bolsa por faixa com pontualidade de R$ 103, correspondência entre plano previsto e parcelas geradas, PDF privado, duas coligadas e proteção dos textos assinados. Regressão nativa de assinatura também aprovada (29 verificações). Transportes externos interceptados, sem envio de e-mail ou alteração no site do colégio.
- PDF gerado pela aplicação real teve os valores, quantidade e dia extraídos/conferidos e a página A4 renderizada/inspecionada, incluindo cabeçalho, tabela e rodapé.
- Schema permanece 48. A instalação não reescreve textos em lote nem recalcula cobranças; o modelo precisa ser editado/salvo para incluir os campos nos contratos não assinados. A edição de condições de um contrato pendente atualiza somente o texto de seu modelo vinculado ainda não assinado.

## 0.19.31

- `tests/document-notifications.php`: 56 verificações SQLite. D-1 recusado, hoje/futuro aceitos, rollback da matrícula, proteção ao editar o primeiro vencimento, envio da secretaria sem vínculo familiar, bloqueio de substituição, aprovação/rejeição e e-mails idempotentes. Conferidos modelos/destinatários, autorização familiar, avisos iniciais, 48 horas, cron atrasado sem acumular ciclos, cancelamento após envio/revogação/desativação, cinco falhas, nova tentativa administrativa, isolamento por coligada e bloqueio durante migração. Uma fila interrompida é reaproveitada ao retomar o cron, sem acumular outra mensagem para o mesmo aluno/destinatário.
- `tests/wordpress-document-notifications.php`: 53 verificações em WordPress 7.1.3/MariaDB 11.4.8. Migração real 47→48 preserva cada registro anterior, adiciona origem familiar aos documentos antigos e não cria avisos retroativos. REST testa funções e vínculos, upload, armazenamento criptografado real, leitura íntegra, origem/operador, idempotência, motivo/reenvio, aprovação, rollback com remoção do arquivo provisório, regra de data local e duas coligadas com equipes/identidades distintas. Falha de transporte, vínculo encerrado, registro/retomada do cron, destino após login, exclusão de notificações/agenda junto com aluno e limpeza das configurações na exclusão da coligada também validados. Todos os e-mails e HTTP externos interceptados.
- 34 suítes PHP de serviço/regressão aprovadas, incluindo documentos/arquivos privados, permissões, planos, bolsas, pontualidade, Educbank, finanças, matrículas, contratos/assinatura/HTML, portal, exportação, família e rotas de exclusão definitiva. Fixtures financeiras históricas agora criam matrículas na data original por um relógio de teste; o código de produção não possui exceção à trava. Corrigida também a contagem antiga das 14 abas no teste de configurações.
- 14 suítes de interface aprovadas. Novos casos cobrem aviso com acesso direto aos documentos, links do e-mail, envio e recuperação de erro mantendo chave de repetição, envio pela secretaria, quatro modelos/destinatários e histórico com nova tentativa. Matrícula em lote testa data mínima do WordPress, D-1 pela validação nativa e também ao contorná-la, além da aceitação de hoje com e sem modelo de contrato.
- Regressões nativas aprovadas: assinatura (29), permissões de contratos (53), recuperação de banco (34) e HTML/PDF (22). Sintaxe aprovada em 473 arquivos PHP e 25 JavaScript.
- Chromium com HTML real do portal e scripts/CSS reais: início familiar, envio da secretaria, configurações e editor de mensagem a 1440/390 px, sem excesso horizontal. Upload real pelo campo do navegador gera multipart e atualiza o estado para análise. Quatro e-mails gerados pelo WordPress de testes foram renderizados e inspecionados a 700/390 px, incluindo links longos com quebra de linha.
- Os três testes legados com falhas reproduzidas na 0.19.29 e documentadas na entrega 0.19.30 não integram essas contagens: `payment-receipts.php`, `payment-ui.cjs` e `management-ui.cjs`. As verificações específicas dos fluxos novos e as regressões relacionadas passaram.
- Schema 48 acrescenta origem, índice por coligada/documento, fila de notificações e agenda de lembretes, com FKs e chaves de repetição. Documentos em análise/aprovados não geram cobrança; vínculos/destinatários atuais são revalidados antes do transporte. Lembretes dependem da execução do cron WordPress/servidor. Não houve instalação no site do colégio nem envio externo.

## 0.19.30

- `tests/contract-html.php`: 30 verificações SQLite com os serviços reais. Tags/estilos, remoção de conteúdo ativo, limites, texto literal, modelos, shortcodes escapados, prévia, atualização somente de não assinados, formato congelado, versão obrigatória e isolamento por coligada. Contratos assinados e cobranças preservados.
- `tests/wordpress-contract-html.php`: 22 verificações em WordPress 7.1.3/MariaDB 11.4.8. Migração real 46→47 preserva todos os campos assinados, hash, modelos antigos e valores. Testadas repetição, REST, quatro perfis sem acesso, operador delegado, PDF privado sem cache compartilhado, logo da biblioteca de mídia, duas coligadas, identidade atualizada sem regravar texto, documento legado literal e ausência de e-mail/HTTP externo.
- Regressões nativas: assinatura (29), permissões de contratos (53) e migração desde backup anterior (34), todas aprovadas no WordPress/MariaDB. Dados fictícios e envio externo interceptado; nenhuma execução no site do colégio.
- 27 suítes PHP e 11 suítes de interface aprovadas, incluindo contrato/modelo, assinatura, permissões, shortcodes do assinante, descontos, bolsas, pontualidade, Educbank, finanças, painel, matrícula/exclusão, exportação e portal. Sintaxe aprovada em 465 arquivos PHP (incluindo a biblioteca isolada) e 24 JavaScript.
- `tests/contract-html-ui.cjs` valida HTML/estilos, remoção de scripts/eventos/recursos, legados literais, envio do formato/versão, abertura do PDF, erro com nova tentativa e alternativa quando a janela é bloqueada. Chromium com HTML real do portal e scripts/CSS reais a 1440/390 px: visualização, edição e prévia sem excesso horizontal. Abertura real da janela com PDF Blob validada.
- PDF A4 de demonstração com cinco páginas renderizado e inspecionado: logo somente na primeira página, tabelas, destaques, acentuação, fonte DejaVu incorporada, assinaturas e rodapé completo em todas as páginas, sem sobreposição. Texto/geometria/paginação conferidos em todas as páginas; PDFs de duas coligadas, configuração alterada e texto antigo literal também verificados. Dados pessoais e cláusulas do PDF fornecido não foram copiados para o repositório.
- Três testes antigos repetem a mesma falha em uma cópia integral do HEAD 0.19.29: `payment-receipts.php` (fixture não resulta em parcela quitada), `payment-ui.cjs` (asserção do valor textual 1500.00) e `management-ui.cjs` (payload esperado de configuração). Eles não integram as contagens aprovadas. A leitura local da logo e a impressão foram validadas diretamente e no WordPress de testes.
- Schema 47 é aditivo: apenas formato do modelo/contrato. Sem reinterpretação dos textos anteriores, recálculo de bolsas/parcelas ou mudança no bloqueio do texto assinado. PDF entregue pela sessão autorizada, sem arquivo de contrato em uploads públicos; recursos remotos, JavaScript e PHP embutidos desabilitados.

## 0.19.29

- `tests/contract-signing.php`: 38 verificações SQLite. Registro explícito, data/autor/hash/número de impressão, repetição segura, texto/modelo/versão protegidos inclusive para administrador e na persistência, bloqueio antes da leitura de operação antiga, isolamento por coligada inclusive em repetição, modelo sincronizando somente contratos não assinados, textos manuais independentes e perfil autorizado. Bolsa, pagamento, estorno e metadados operacionais continuam disponíveis sem mudar texto ou assinatura.
- `tests/wordpress-contract-signing.php`: 29 verificações WordPress 7.1.3/MariaDB 11.4.8. Upgrade 45→46 aditivo preserva texto anterior para conferência e não presume assinaturas. REST testa autorização, confirmação, repetição, bloqueio administrativo, propagação do modelo, persistência protegida e manutenção de operações financeiras. Migração repetida preserva assinatura, texto e cobranças; registro antes da geração de parcelas também validado. Consultas/opções de dois períodos letivos e navegação mantêm o período escolhido sem alterar o vigente. HTTP/e-mail bloqueados.
- Regressões nativas: migração geral 36→46 com índices antigos, conflitos, repetição e instalação nova (34 verificações); perfis de contratos/benefícios (53); indicadores financeiros (43). Migração final de assinatura e migração geral repetidas após a decisão de preservar textos anteriores durante o upgrade.
- 23 suítes PHP aprovadas, incluindo contratos/modelos, assinatura, permissões, indicadores/gestão financeira, ciclo/exclusão de matrícula, coligadas, políticas/rotas/renderização/tema, ficha do aluno, exclusão financeira, contas/convites, exportação, índices, pontualidade e indicação. 13 suítes de interface aprovadas, incluindo assinatura com check permanente, recuperação de erro e repetição com a mesma chave, edição operacional sem campos do texto, mudança/retorno do período e links, além das telas financeiras e acadêmicas relacionadas.
- Chromium com HTML real e scripts/CSS reais a 1440/390 px: lista, texto assinado, texto ainda não assinado e edição dos demais dados. Capturas inspecionadas sem excesso horizontal. Sintaxe PHP/JavaScript e pacote conferidos.
- Schema 46 acrescenta somente campos do registro de assinatura e índice de consulta. A atualização preserva os textos anteriores; salvar o modelo depois da atualização sincroniza os vinculados ainda não assinados. O bloqueio definido pelo usuário é somente do texto, sem novos bloqueios financeiros. Não houve instalação no site do colégio ou envio externo. A falha acadêmica antiga documentada na 0.19.28 não integra as contagens de aprovação.

## 0.19.28

- `tests/financial-dashboard.php`: 47 verificações SQLite. Bases após bolsas/incondicionais, pontualidade guardada e legado com configuração, integral, pagamentos parciais em mais de uma baixa, estorno, cancelamento com recebimento preservado, encargos separados, competência/turma/aluno combinados, taxas sobre base vencida, vencimento de hoje, zeros, filtros inválidos e isolamento por coligada. Permissão própria, ausência de concessão automática, menu e capacidade juntos, revogação e impedimento de operações financeiras.
- `tests/wordpress-financial-dashboard.php`: 43 verificações em WordPress 7.1.3/MariaDB 11.4.8 reais. Conferidos os valores DECIMAL e o agrupamento mensal com a mesma composição, filtros REST, consultas sem cache compartilhado, busca literal, contas sem acesso, perfil Financeiro sem acesso automático, perfil só do painel, controles de coligada/período, separação de operações e administração, ausência de escrita, renderização e revogação. HTTP/e-mail interceptados.
- 19 suítes PHP aprovadas: painel, gestão financeira operacional, ciclo/exclusão de matrícula, contratos, permissões de contratos, políticas/rotas/renderização/tema do portal, recursos administrativos, coligadas, ficha do aluno, exclusão financeira, contas/convites, exportação e visão familiar.
- 12 suítes de interface aprovadas, incluindo o novo painel, identificação/cores de matrícula, ações de matrícula, financeiro/paginação/operações, permissões de contratos, portal, contratos, descontos, bolsas e ficha do aluno. Verificados filtros combinados, buscas, paginação mantendo a seleção, recuperação de erro, conteúdo textual seguro e seletores de coligada/período para operador sem o Financeiro.
- Chromium com HTML real do WordPress e scripts/CSS reais a 1440/390 px: indicadores, matrículas, Financeiro e matriz de perfis. Capturas inspecionadas, sem excesso horizontal. Atalhos financeiros com a mesma posição vertical e altura; códigos e situações com tons pastéis. Sintaxe aprovada em 292 arquivos PHP e 23 JavaScript; pacote conferido.
- A suíte antiga `tests/academic-management.php` usa um cadastro de plano sem o período hoje obrigatório e interrompe antes dos cenários. A mesma falha foi reproduzida em uma cópia integral da versão 0.19.27 publicada, sem as alterações desta entrega. Ela não é contada entre as suítes aprovadas. Consulta de matrículas/códigos e filtros desta entrega foram cobertos pelos novos testes de serviço, REST e interface.
- Schema 45 mantido. O painel usa saldos atuais e a competência da parcela; a projeção líquida não concede descontos em títulos vencidos. Não houve instalação no site do colégio, envio externo ou recálculo de cobranças reais. Contas a pagar, centros de custo, LTV e CAC estão apenas registrados no planejamento.

## 0.19.27

- `tests/contract-permissions.php`: 46 verificações com SQLite. Matriz com duas opções independentes, padrão sem concessão a funcionários, contrato/modelo separado de benefícios, perfil com ambos, capacidades e menus conferidos juntos, serviço protegido após revogação, resumo sem termo/RF, bolsas e desconto comum recalculados e isolamento por coligada.
- `tests/wordpress-contract-permissions.php`: 53 verificações em WordPress 7.1.3/MariaDB 11.4.8 reais. Administrador cria/configura perfis pela REST; contas fictícias sem acesso, só contratos, só benefícios e com ambos exercitam as operações. Conferidos consulta/edição/exclusão validada, modelos/prévia, bolsas/descontos/indicação, aliases, impedimento de autoelevação e de acesso a configurações globais, baixa/exclusão definitiva, resumo sem termo, seleção de coligada e bloqueio após revogação inclusive para chave já confirmada. HTML real do portal gerado para conferência. HTTP/e-mail interceptados.
- `tests/contract-permissions-ui.cjs` carrega os scripts reais de perfis, contratos, modal e benefícios. Conferidos checkboxes independentes e seus envios de concessão/revogação; modelo/edição apenas no perfil correspondente; seleção do contrato correto; desconto comum e bolsa cadastrada pelo editor compartilhado, sem exigir consulta à ficha do aluno.
- 18 suítes PHP de serviço/regressão aprovadas: permissões novas, contratos, descontos, revisões/indicação, preços/pontualidade, políticas/rotas/renderização do portal, tema, ficha do aluno, recursos administrativos, coligadas, contas/convites, Educbank, exportação e visão familiar. Onze suítes de interface aprovadas, incluindo concessão na matrícula, editor de bolsas, perfis, conta, financeiro e gestão de usuários.
- Chromium a 1440/390 px com o HTML real do portal, scripts/CSS reais e API fictícia. Matriz de perfis e área de benefícios sem excesso horizontal; capturas inspecionadas. Rodapé apresenta o crédito solicitado com link HTTPS e versão. Sintaxe PHP/JavaScript e pacote verificados.
- Schema 45 mantido. As novas permissões são opcionais, exigem escolha do administrador e não alteram valores, pagamentos ou dados em lote. Não houve instalação no site do colégio nem envio externo. A instalação segue a configuração do WordPress do site.

## 0.19.26

- `tests/punctuality-plan.php`: 54 verificações, incluindo as 41 de bolsas. Plano anual 16236 em 12 parcelas mantém 1353/1250; 50% usa 1353 e gera 779,50/676,50; bolsa fixa 350 gera 1003/900. Conferidos combinação percentual/fixa, quitação, regra histórica guardada, cotação anterior recusada, pontualidade alterada, contrato legado pendente, limite e rollback.
- `tests/wordpress-scholarships.php`: 37 verificações em WordPress 7.1.3/MariaDB 11.4.8. Conferidos os exemplos acima com DECIMAL/InnoDB, parcela e lançamento, contrato sem acréscimo duplicado, baixa de 900 com 103 de pontualidade, migração 44→45 repetível, manutenção dos dados de backup, permissões, isolamento, transações, idempotência, integral e geração posterior. Todos os transportes HTTP/e-mail foram interceptados.
- `tests/educbank-scholarships.php`: 43 verificações com fornecedor simulado. Mantida a cobertura de registros legados; novas faturas e atualizações usam 1353 como original. Para 50%, 573,50 incondicionais + 103 condicionais produzem 779,50/676,50. Para 60%, o retorno confirma 541,20 recebidos. Bolsa fixa 350 mantém 350 incondicionais, 103 condicionais e confirma 900 recebidos em parcela de 1003. Integral cancela a cobrança sem pagamento e fatura paga bloqueia cancelamento para conciliação.
- 26 suítes PHP e oito de interface aprovadas: preços/cotação, contratos, planos, descontos/indicação, Educbank, matrícula, exclusão financeira, coligadas, shortcodes, exportação, recursos administrativos, índices, K8 e consultas familiares. A interface verifica os dois critérios de bolsa, soma percentual, limite, payload, faixas, versão, repetição, falha recuperável e cotação da rematrícula.
- Chromium a 1440/390 px com scripts/CSS reais e dados fictícios: prévia 1353/1250, bolsa 50% 779,50/676,50 e bolsa fixa 1003/900; telas e capturas inspecionadas sem excesso horizontal. Sintaxe PHP/JavaScript e pacote verificados.
- Schema 45 mantido. Contratos, concessões emitidas e pagamentos não são recalculados em lote. Não houve instalação no site do colégio, alteração de dados reais ou envio de cobrança/e-mail. A conexão real e a instalação seguem a configuração do WordPress do site.

## 0.19.25

- `tests/scholarships.php`: 37 verificações com SQLite. Padrões por coligada, base 1000 + pontualidade 103, bolsa 50% (603/500), soma 60% (503/400), integral zero sem baixa, liberação Cursando, retirada sem duplicação, faixas, regras anteriores, vencimento, pagamentos integrais/parciais, valores fixos, edição de catálogo, versão antiga, rollback acima de 100%, arredondamento e limite monetário. Contratos pendentes usam a base corrigida e aplicam descontos uma vez.
- `tests/wordpress-scholarships.php`: 32 verificações em WordPress 7.1.3/MariaDB 11.4.8 reais. A fixture contém o schema 44 publicado, contrato, duas parcelas, baixa parcial e chat. dbDelta migra para 45 preservando todos os campos anteriores; repetir não duplica os padrões. Concessão via REST recalcula somente a parcela sem baixa; conferidos DECIMAL, permissões, isolamento por coligada, idempotência, rollback InnoDB, integral, duas bolsas e edição/geração posterior de contrato pendente. Todos os transportes HTTP e de e-mail foram interceptados.
- Migração geral WordPress/MariaDB: 34 verificações aprovadas, incluindo restauração 36 → 45, índices antigos, conflitos, diagnóstico, instalação nova e repetição. Exclusão definitiva nativa: 69 verificações aprovadas com o schema novo e suas dependências.
- `tests/educbank-scholarships.php`: 41 verificações com fornecedor simulado. Mantida cobertura de preços legados; bolsas cadastradas atualizam faturas existentes com original 1103, desconto 500/600 e pontualidade 103 separados. Retorno de 60% registra 400 recebidos e 103 de pontualidade. Integral cancela a fatura zerada sem criar outra; pagamento parcial é preservado e fatura paga no fornecedor bloqueia cancelamento para conciliação.
- 26 suítes PHP de regressão aprovadas: bolsas, contratos/modelos, planos, descontos, revisões/indicações, envio/retorno/status/cliente Educbank, ciclo e exclusão de matrícula, exclusão financeira individual/lote/integração, coligadas, shortcodes, exportação, recursos administrativos, índices, envio K8, consultas familiares e domínio. Fixtures de preço anterior mantêm explicitamente o estado legado; as novas suítes exercitam a composição base + pontualidade.
- Oito suítes de interface aprovadas: matrícula com bolsas, gerenciamento de concessões e edição da base, contratos, modelos na matrícula, descontos/indicação, rematrícula, financeiro familiar e exclusão financeira. Conferidos prévia, soma, limite, payload, faixa, suspensão/retirada, versão, repetição protegida, falha recuperável e conteúdo textual seguro.
- Chromium a 1440 e 390 px com scripts e CSS reais, dados fictícios e API interceptada. Cadastro, seleção e gerenciamento de bolsas sem excesso horizontal; capturas de computador e celular inspecionadas. Sintaxe PHP/JavaScript e pacote também verificados.
- Schema 45 acrescenta catálogo, concessões e composição dos preços. Não houve instalação no domínio do colégio, alteração de dados reais, envio de cobrança, e-mail ou chamada autenticada ao Educbank real. A instalação no site e a confirmação no fornecedor dependem do WordPress e da conexão configurada.

## 0.19.24

- `tests/materials-sync.php`: 39 verificações com SQLite e receptor de contrato simulado a partir de API-ALUNOS.md. Exercitados configuração por coligada/período, senha cifrada, URLs REST/subdiretório/rest_route, escopo, seleção das matrículas, todos os responsáveis vigentes, CPF pendente/inválido, RA e telefone com zeros e JSON com apenas os campos aceitos pelo K8.
- O receptor simulado exige modo atualizar e booleano real validar_apenas. Validação não modifica sua lista; atualização conserva o ID por RA e substitui a lista completa de responsáveis. Conferidos lotes de 200 + 3 alunos sem dividir um RA, recusa de conferência desatualizada, chave reutilizada, erro do destino, timeout e ausência de reenvio automático da mesma tentativa incerta.
- `tests/wordpress-materials-sync.php`: 27 verificações com WordPress 7.1.3/MariaDB 11.4.8 reais e todas as chamadas externas interceptadas. REST bloqueia anônimo/secretaria, protege configuração e histórico, envia Basic Auth por HTTPS sem redirecionamento e preserva o aviso de base externa recebido. Conferidos transporte de validação versus gravação, substituição total recusada, escopo por coligada, erro/timeout e bloqueio de migração pendente. Nenhum cadastro ERP foi modificado pelo envio nem houve e-mail externo.
- Cinco suítes de serviço aprovadas: materiais, leitura de requerimentos, conexões de API, recursos administrativos e rotas do portal. Três suítes de interface aprovadas: materiais, leitura de requerimentos e conexões externas. A interface exige validar todos os lotes antes de liberar gravação, informa interrupção parcial e impede alterar campos/fechar durante a operação.
- Chromium a 1440 e 390 px com scripts/CSS reais, dados fictícios e HTTP simulado. Capturas do diálogo inspecionadas; conferência, avisos e controles sem excesso horizontal. Sintaxe e pacote verificados.
- Schema 44 mantido. Integração de envio manual para o contrato do K8 informado pelo usuário, sem uma nova API pública de consulta por CPF no ERP. Não foi realizada conexão autenticada com um K8 real, instalação no site do colégio ou envio externo de alunos. Destino, login e Senha de aplicação devem ser configurados pelo administrador.

## 0.19.23

- `tests/admission-read-api.php`: 51 verificações com SQLite, requerimentos reais do serviço de recebimento e aprovação e arquivos simulados. Conferidos original versus conferido, referências ao aluno, responsáveis/respostas/listas, situação, protocolo, aluno, janela UTC inclusiva, cursor sem repetição e validação de filtros. A leitura não altera requerimentos, anexos, auditoria, fila ou contas.
- `tests/wordpress-admission-read-api.php`: 33 verificações em WordPress 7.1.3/MariaDB 11.4.8 reais. Perfil com coligada explícita, atualização protegida da autorização, criação/autenticação/revogação de Senha de aplicativo nativa e despacho REST. Conferidos 401/403/404/422/503, ausência de escrita nas novas rotas, bloqueio nas APIs de gestão/aprovação/rejeição e anexo cifrado real com integridade. Revogar coligada bloqueia imediatamente; migração pendente mantém a edição do usuário WordPress disponível.
- Novos requerimentos gravam criação/atualização em UTC explicitamente, independentemente do fuso do banco; o teste nativo compara com o relógio UTC. Nenhum registro antigo é recalculado.
- Regressão nativa das confirmações: 46 verificações aprovadas em WordPress/MariaDB, com todos os transportes interceptados. Nenhuma mensagem externa foi enviada.
- Oito suítes de serviço aprovadas: API de leitura, requerimento público, identidade do aluno no requerimento, detalhes de pessoas, conexões de API, conferência de requerimento importado, recursos administrativos e rotas do portal. Cinco suítes de interface aprovadas: instruções/endereço da consulta, conexões externas, conferência Fluent, formulário público e configuração das confirmações.
- Chromium a 1440 e 390 px: tela de integração com scripts/CSS reais, URL selecionável e dados fictícios. Capturas inspecionadas e ausência de excesso horizontal conferida. Sintaxe PHP/JavaScript, referências internas do OpenAPI e pacote verificados.
- Schema 44 mantido. Não houve instalação, tráfego autenticado no domínio do colégio ou conexão ao sistema consumidor. A consulta cobre o formulário nativo do ERP; a fila de importações de outros sistemas permanece no fluxo anterior. Exclusão definitiva exige reconciliação da lista completa no consumidor.

## 0.19.22

- `tests/profile-personal-ui.cjs` abre o perfil com os scripts reais de formulário/modal e verifica a ordem CPF, RG, Nacionalidade, UF, Local e Raça/Etnia, CPF único dentro da seção, Brasil primeiro e preservação da nacionalidade existente.
- Exercitados troca de UF com limpeza/filtragem dos municípios, nacionalidade independente da UF, payload do endpoint próprio, nascimento no exterior por texto, campos sem UF, valores legados e falha no carregamento. Há somente um campo de local ativo no envio; erro nas listas bloqueia o formulário antes de qualquer POST.
- `tests/person-details.php` aprova gravação de cidade válida, rejeita novas combinações incompatíveis ou sem UF e confirma rollback de todos os campos e da versão. Perfil continua aceitando exterior, nacionalidade diferente do país de nascimento e valores legados sem alteração, inclusive normalização de caixa.
- Seis suítes PHP aprovadas: detalhes de pessoas, contas, shortcodes do responsável financeiro, recursos administrativos, rotas do portal e permissões da ficha. Quatro suítes de interface aprovadas: perfil pessoal, campos compartilhados/CSV, modais de perfil/avatar e menu da conta.
- Chromium a 1440, 390 e 320 px com perfil e modal reais, dados fictícios e API simulada. Ordem das seis primeiras posições e ausência de excesso horizontal conferidas. Capturas de desktop e celular inspecionadas; o celular utiliza uma coluna para manter os documentos legíveis.
- Schema 44 mantido. Nenhuma migração ou alteração em lote nos cadastros. Validação de serviço executada com SQLite e fronteiras WordPress simuladas; não houve acesso ou gravação no site do colégio.

## 0.19.21

- `tests/wordpress-admission-notifications.php`: 46 verificações em WordPress 7.1.3/MariaDB 11.4.8 reais. A configuração normaliza/deduplica destinatários, recusa cabeçalhos e opções inválidas, permanece isolada por coligada e não aparece na API anônima. Todos os transportes foram interceptados por `pre_wp_mail`; nenhuma mensagem foi enviada externamente.
- Primeiro envio confirma somente depois do commit, sem criar pessoas ou usuários. Acadêmico/financeiro e comercial recebem mensagens individuais; a fila continua os destinatários restantes. Repetir o envio retorna o mesmo protocolo sem recriar notificações. Conferidos protocolo, identidade da escola, indicação de análise, ausência de contatos de terceiros na mensagem familiar e ausência de saúde, endereço, documentos, anexos ou link de senha nos e-mails.
- Aprovação real cria três pessoas/contas e a ficha do aluno. As três contas permanecem com convite Não enviado, sem data de processamento: nenhum convite de senha é disparado pela aprovação. A matrícula não é efetivada nessa etapa.
- Seleção de destinatário acadêmico/financeiro, endereço coincidente nos responsáveis e no comercial, identidade de outra coligada, falha de transporte com intervalo, limite de cinco tentativas, reprocessamento administrativo e ausência de reenvio dos já confirmados foram exercitados. Secretaria não muda destinatários nem força o reprocessamento.
- Falha por trigger na auditoria posterior à criação da fila reverte requerimento e notificações sem qualquer transporte. Desligar as confirmações mantém o formulário disponível; exclusão definitiva inclui a fila vinculada e impede envio posterior daquele registro.
- `tests/public-admissions.php`: 75 verificações aprovadas; `tests/admission-student-identity.php` também passa, com a expectativa antiga de convite automático atualizada para a política manual vigente desde 0.19.18. O teste continua conferindo homônimos, identidade escolar, cadastro e rollback. Outras seis suítes PHP aprovadas: rotas de requerimentos, página pública, convites, exportação, recursos administrativos e rotas de exclusão.
- WordPress/MariaDB: mais 34 verificações de migração geral (incluindo schema 36 → 44, instalação nova e repetição) e 36 de usuários/acessos aprovadas. A nova tabela não cria notificações retroativas.
- Três suítes de interface aprovadas: configuração das confirmações com erro/salvamento, formulário completo e endereços/CEP. Templates reais dos dois e-mails e editor de configuração renderizados em Chromium a 1440, 390 e 320 px, sem excesso horizontal. Capturas de e-mail e editor em desktop/celular inspecionadas visualmente.
- Schema 44 acrescenta `requerimento_notificacoes`, com vínculo ao requerimento/coligada e unicidade por destinatário. Transporte, SMTP real e entrega na caixa postal do colégio continuam dependendo do WordPress do site; a aceitação pelo transporte não comprova entrega ou leitura. Tarefas agendadas retomam pendências; o WordPress recebe uma solicitação de processamento em segundo plano quando habilitado.

## 0.19.20

- `tests/wordpress-permanent-deletion.php`: 69 verificações em WordPress 7.1.3/MariaDB 11.4.8 reais com dados fictícios. Cadastro de aluno com pessoa/usuário, matrículas, contrato, parcela, pagamento e estorno, trocas de responsável com chaves compostas, histórico escolar, chat/anexo, documentos, registros preservados, fila/retornos/recebimentos Educbank, requerimento importado e resposta de operação. Prévia reúne todas as dependências e identifica alunos afetados por exclusão direta de responsável financeiro.
- Comprovadas exclusão conjunta da pessoa/conta, opção de manter pessoa/conta, histórico de acessos, transferência de posts WordPress e preservação integral do contrato de outro aluno com o mesmo responsável. Pais compartilhados ficam fora da seleção implícita. Todas as FKs declaradas permanecem habilitadas e consistentes após os casos testados.
- Falha por trigger na gravação do comprovante final reverte todos os dados, usuário, autoria WordPress e comprovante, preservando os arquivos físicos. Falha posterior na fila de arquivos mantém a operação concluída e a pendência durável; nova tentativa limpa a fila sem repetir as exclusões. Arquivo compartilhado permanece até a última referência.
- Assinatura falsa, confirmação ausente, prévia de outro administrador, novo vínculo após a prévia, chave inválida e tabela externa ao schema são recusados. Secretaria e visitante não consultam ou executam o recurso; a própria conta exige outro administrador.
- Exclusão de associação com chave composta mantém os cadastros pais. Ciclo opcional entre períodos é resolvido sem desligar FKs. Período atual limpa a seleção correspondente; exclusão de coligada limpa suas configurações, e exclusão da última ativa prepara espaço vazio e atualiza a preferência sem recriar os dados apagados.
- `tests/permanent-deletion-routes.php` verifica as quatro rotas com administrador e responsável. `tests/permanent-deletion-ui.cjs` cobre busca, prévia, mudança de escopo, contagens, escape, confirmação digitada, motivo, nonce, falha/repetição com a mesma chave e interface indisponível para outro perfil. Ações de matrícula e carregamento das Configurações também passam na interface.
- Quinze suítes PHP de regressão aprovadas, incluindo coligadas, planos, ciclo e exclusão de matrícula, contratos, fluxo do aluno, convites, usuários, exportação, rotas e carregamento administrativo. Em WordPress/MariaDB, passaram adicionalmente 34 verificações de migração geral, 36 de usuários/acessos e 29 de recuperação dos históricos do backup, com schema 43.
- Prévia e confirmação inspecionadas visualmente em Chromium a 1440, 390 e 320 px. Campos, fechamento fixo durante rolagem e confirmação acessíveis; nenhum excesso horizontal na página ou no diálogo. Ajustado o botão de fechamento para preservar o título no celular.
- Schema 43 adiciona comprovantes sem FK de propriedade, para registrar inclusive exclusões de coligadas. A atualização apenas instala o recurso. Não houve acesso ao site do colégio, exclusão de dados reais, envio de e-mail ou chamada ao Educbank. Documentos da biblioteca WordPress continuam sob gestão da biblioteca; serviços externos exigem suas próprias operações.

## 0.19.19

- Reproduzido na versão 0.19.18 o erro de FK entre `matricula_movimentacoes(idmatricula)` e `matriculas` informado após a restauração. A correção conclui a migração 36 → 42 em WordPress 7.1.3/MariaDB 11.4.8 reais, com dados fictícios e dbDelta original.
- `tests/wordpress-movement-recovery.php`: 29 verificações. A cópia integral mantém todos os campos e tipos retornados pelo banco, inclusive códigos de matrícula, coligada, turmas e solicitante ausentes. Hash e conteúdo são conferidos antes da retirada do histórico operacional. Documento privado real criptografado continua legível e sua referência permanece no JSON exportado.
- Falha injetada na segunda auditoria reverte todo o lote: originais permanecem, cópias parciais desaparecem e a versão continua 36. Repetição não altera dados ou duplica auditorias; restaurar novamente a mesma linha reutiliza sua cópia, e outro conteúdo com o mesmo código produz uma cópia distinta. A FK final rejeita uma nova movimentação sem matrícula.
- Movimentação válida, alunos, matrículas, contratos, parcelas, pagamentos e demais registros anteriores permanecem iguais. Nenhuma matrícula, turma, pessoa ou coligada é inventada. Atendente e visitante não consultam nem baixam as cópias administrativas.
- Inconsistências independentes em contratos e leituras do chat aparecem juntas no relatório antes da criação das FKs. Elas mantêm a migração pendente e não alteram o financeiro. As cópias e seus downloads permanecem acessíveis ao administrador; corrigir os dados fictícios permite concluir e limpa o relatório anterior. A conferência inclui referências compostas e respeita componentes opcionais nulos.
- `tests/wordpress-migration.php`: 34 verificações de regressão aprovadas, incluindo backup íntegro, índice restaurado, repetição, conflito em chave única, retomada e instalação nova. `tests/wordpress-user-access.php`: 36 verificações aprovadas, preservando a política de convites manuais, confirmação da senha, histórico de acessos e exclusão transacional da pessoa/conta.
- `tests/wordpress-chat-recovery.php`: 105 verificações aprovadas com schema 42, preservando a recuperação anterior dos anexos, arquivos válidos, isolamento entre coligadas, rollback, permissões e repetição sem duplicação.
- Dezesseis suítes PHP de regressão aprovadas, incluindo índices, contratos/shortcodes, exclusão e ciclo de matrícula, fluxo do aluno, exportação, permissões, convites, usuários e portal. Sintaxe PHP dos arquivos alterados e referências do schema conferidas.
- Telas reais de migração pendente e registros preservados renderizadas em Chromium a 1440, 390 e 320 px, sem rolagem horizontal. Downloads individuais com nonce e botão de migração acessíveis; capturas inspecionadas visualmente.
- Schema 42 acrescenta `registros_preservados` sem FK para os códigos de origem, permitindo guardar a cópia mesmo quando o registro relacionado está ausente. As FKs operacionais continuam restritivas. Não houve acesso ao backup ou à instalação do colégio nem envio de e-mails reais.

## 0.19.18

- `tests/wordpress-user-access.php`: 36 verificações em WordPress 7.1.3/MariaDB 11.4.8 reais. Cadastro cria conta sem envio; fila automática antiga é interrompida; lote com versão divergente reverte estado/fila e lote válido é idempotente. Transporte interceptado antes de qualquer envio externo.
- Conferidos Enviado após aceite do transporte, ausência de OK para chave inválida ou senha definida pelo administrador e OK após redefinição real da senha inicial pelo link no portal. Login real com a nova senha registra histórico e último acesso; retorno com sessão ativa, horários em America/Sao_Paulo e permissões por conta/perfil também foram exercitados.
- Confirmação de senha simulada durante o transporte permanece OK após o worker retornar. As gravações do convite comparam o estado anterior para preservar confirmações concorrentes e impedir novas entregas da senha inicial já definida.
- Exclusão usa `wp_delete_user` real na mesma transação da pessoa. Trigger de auditoria com falha injetada restaura pessoa, usuário, metadados e autoria de posts/links; nova tentativa exclui ambos, transfere conteúdos ao administrador, mantém o histórico e é idempotente. Própria conta e pessoa com aluno vinculado permanecem protegidas.
- `tests/account-invitations.php`: 34 verificações com nova política manual, mantendo criptografia, identidade visual, contatos compartilhados, destinatários acadêmicos, chave expirada, revogação/troca de e-mail, falha de transporte, permissões e ausência de senhas/chaves na auditoria.
- Dezesseis suítes PHP de regressão aprovadas e sete testes de interface aprovados, incluindo usuários, convites, autenticação, ficha e ações de matrícula. A interface verifica seleção entre páginas, confirmação prévia, reenvio após erro com a mesma chave de operação, tags e histórico. Lista, confirmação e histórico inspecionados em Chromium a 1440, 768, 390 e 320 px, sem rolagem horizontal.
- Schema 41 acrescenta `usuario_acessos`, sem FK de exclusão para preservar o histórico das contas removidas. Não são gravados senhas, tokens ou conteúdo dos e-mails. O registro de acessos começa nesta versão. Não houve acesso à instalação do colégio ou envio real de convites.
- `tests/wordpress-migration.php`: 34 verificações adicionais com dbDelta real, incluindo schema 36 → 41 com financeiro/chat preservados, repetição e instalação nova. A tabela de acessos é adicionada sem alterar os registros anteriores.

## 0.19.17

- Reproduzido em WordPress/MariaDB o erro exato de FK entre `chat_anexos(codcoligada,idmensagem)` e `chat_mensagens` apresentado após a restauração. A versão 0.19.16 interrompe a migração com esse backup fictício; a correção preserva os arquivos e conclui a atualização para schema 40.
- `tests/wordpress-chat-recovery.php`: 105 verificações em WordPress 7.1.3 e MariaDB 11.4.8 reais. Conferidos mensagem ausente, referência a outra coligada, arquivo válido, dois colégios, conteúdo/nome/MIME/tamanho/coligada/data preservados e ausência de mensagens/conversas inventadas.
- Falha de auditoria injetada por trigger reverte integralmente o lote, incluindo referências, versões e metadados. Nova tentativa conclui; repetição posterior não duplica registros nem auditoria. As FKs finais rejeitam novas referências inexistentes e de outra coligada.
- Conferência e download exigem administrador WordPress; atendente e visitante são bloqueados. Arquivos pendentes não são disponibilizados pelo chat comum. Listagem não carrega conteúdo, nomes são escapados e downloads têm nonce individual, resposta como anexo e cache privado.
- `tests/wordpress-migration.php`: 34 verificações de regressão, agora também com chat/anexo válidos na fixture de schema 36. Conferem preservação dos dados anteriores, inclusive financeiro, repetição, instalação nova e alteração do vínculo opcional com FK já existente.
- Doze suítes PHP de regressão aprovadas, incluindo chat/aluno, índices, exportação, recursos do admin, portal, contratos/shortcodes, exclusões acadêmica/financeira e coligadas/permissões. Dois fluxos de interface do chat aprovados. Conferência dos anexos renderizada em Chromium a 1440, 390 e 320 px, sem rolagem horizontal e com os três downloads de teste acessíveis.
- O ambiente é descartável e usa dados fictícios. Não houve acesso ao backup ou ao site real do colégio. A recuperação trata anexos com vínculos inválidos; outros conflitos de restauração permanecem sujeitos ao diagnóstico.

## 0.19.16

- `tests/wordpress-migration.php`: 34 verificações em WordPress 7.1.3 e MariaDB 11.4.8 reais, com dbDelta original e banco descartável. A fixture usa o schema 36 publicado no commit `400ceb0605a2ae00f74430ca3e2d8da7f2daff32`; contém apenas estrutura, e os registros são fictícios.
- Reproduzida na versão anterior a mensagem genérica de persistência ao remover um índice único antigo ainda necessário à FK de pessoas/alunos. A nova ordem instala o índice substituto antes da remoção e mantém a FK ativa.
- Conferidos backup íntegro 36 → 39, restauração com índice antigo, repetição idempotente e igualdade de todos os registros/valores anteriores, incluindo matrícula, termo do contrato, parcela, saldo e pagamento. Registros não são marcados como excluídos nem recebem modelos inventados.
- Backup com chave única ausente e RA duplicado interrompe a migração sem avançar a versão. O diagnóstico identifica tabela, etapa e erro; valores dos registros e SQL não aparecem. Tentativa manual conclui após corrigir o conflito e preserva os dois alunos.
- Recuperação acessível em Configurações no WordPress e no portal sem carregar a API bloqueada; nonce e escape do diagnóstico conferidos. Erro antigo permite uma tentativa na nova versão; falha dessa tentativa não causa novas migrações em cada acesso.
- Instalação nova, preparação dos cadastros iniciais e repetição sem duplicação também conferidas no banco real. Tela de recuperação renderizada antes de carregar funções exclusivas do wp-admin e inspecionada em Chromium a 1440, 390 e 320 px, sem rolagem horizontal e com o botão de migração acessível.
- Sete suítes de regressão aprovadas: índices, recursos do admin, rotas do portal, shortcodes do assinante, exclusão de matrícula, contratos/modelos e permissões das rotas de integração. Sintaxe dos arquivos PHP alterados conferida.
- O teste local não contém o backup do colégio nem executa operações no site real. Outros impedimentos específicos da restauração passam a ser apresentados no diagnóstico administrativo. Schema 39 mantido.

## 0.19.15

- `tests/contract-payer-shortcodes.php`: 35 verificações de emissão e prévia com estado civil, profissão, endereço completo e campos separados do assinante financeiro. Conferidos nome do estado civil por referência, texto legado, renomeação do cadastro, número zero, campos ausentes, endereço internacional e preservação do shortcode de endereço anterior.
- Os testes distinguem aluno, responsável acadêmico e responsável financeiro, exercitam a troca financeira pelo fluxo próprio e conferem preservação do texto já emitido e aplicação explícita de modelo com os dados do novo assinante.
- Sete suítes PHP aprovadas, incluindo contratos/modelos, estados civis, dados pessoais, bolsas, gestão financeira e exclusão de matrícula. Dez fluxos de interface de contratos e matrícula aprovados. Editor com a lista completa de shortcodes conferido em Chromium em 1440, 390 e 320 px, com inserção dos três novos campos principais e sem rolagem horizontal.
- Schema 39 mantido; os campos já existem no cadastro de pessoas. Os serviços são executados com SQLite e fronteiras WordPress simuladas.

## 0.19.14

- `tests/enrollment-deletion.php` reproduziu na versão 0.19.13 a mensagem "Não é possível excluir: existem contratos financeiros vinculados" mesmo após excluir os lançamentos e o contrato. A correção passa em 46 verificações: exclusão com histórico financeiro preservado, vínculo ao período reaberto, liberação de vaga e nova matrícula, consultas e API, pagamentos antigos, migração automática de situação, restrições acadêmicas, permissões, coligada, versão, idempotência e rollback completo por falha injetada na auditoria final.
- `tests/enrollment-deletion-educbank.php`: 18 verificações adicionais sobre as simulações de retorno Educbank, com integração ativa. Conferidos preservação das baixas e registros remotos, bloqueio de reenfileiramento por período/configurações, reprocessamento manual, resposta tardia, payload de matrícula excluída e novas filas independentes na matrícula substituta. Nenhuma chamada HTTP adicional é feita pela exclusão ou pelos reenvios testados.
- Vinte suítes PHP aprovadas, incluindo contratos, exclusão financeira individual e em lote, integração Educbank, ciclo de matrícula, bolsas, vínculos familiares, rotas, exportação e carregamento do admin. Regressões de ações de matrícula, escolha de modelos na matrícula e ficha do aluno aprovadas na interface; sintaxe dos arquivos alterados conferida.
- A migração adiciona três campos opcionais e um índice em matrículas (schema 39). Os serviços usam transações e chaves estrangeiras SQLite com fronteiras WordPress simuladas; dbDelta e concorrência MySQL real continuam para homologação.

## 0.19.13

- `tests/contracts.php`: 97 verificações de modelos, padrão por coligada, preenchimento, prévia, preservação do texto emitido, permissões no serviço e nas rotas, versões, idempotência, edição pendente, exclusão, pagamentos preservados, reemissão e integridade das chaves estrangeiras.
- `npm --prefix tests run test:contracts`: dez fluxos de interface, incluindo busca e paginação, impressão/visualização segura do texto, edição com modelo, tratamento de erro e repetição, exclusão com confirmação, criação/revisão/exclusão de modelo, prévia e matrícula com modelo padrão ou texto personalizado. A matrícula sem modelos cadastrados também foi verificada.
- Chromium instalado localmente, com API simulada: lista, texto do contrato, lista de modelos e editor em 1440, 768, 390 e 320 px. Dezesseis capturas, sem erros JavaScript ou rolagem horizontal; layout conferido visualmente. Cabeçalho do modal mantém o botão Fechar acessível durante a rolagem.
- Regressões aprovadas de planos de pagamento, bolsas, ciclo de matrícula, coligadas, transições de curso, permissões das rotas de integração, gestão financeira, exclusão financeira em lote e retorno Educbank, exportação seletiva, portal e carregamento dos recursos administrativos. Verificação de sintaxe em 187 arquivos PHP e vinte arquivos JavaScript.
- Na conferência ampliada, cinco testes legados (`academic-management.php`, `people-filter.php`, `integration-api.php`, `payment-receipts.php`, `renewal-manual.php`) apresentaram falhas já reproduzidas na versão 0.19.12, sem as alterações deste desenvolvimento. Os testes antigos de interface `workflow-ui.cjs`, `portal-ui.cjs` e a etapa final de configurações de `management-ui.cjs` também reproduzem as mesmas falhas na base anterior. Os testes específicos novos e os fluxos de matrícula ligados à mudança passam.
- Serviços executados com transações e chaves estrangeiras SQLite e fronteiras WordPress simuladas. A migração aditiva para schema 38 foi conferida no esquema; dbDelta e bloqueios concorrentes ainda exigem homologação em WordPress/MySQL. Não houve acesso à instalação do colégio nem envio de dados ao Educbank.

## 0.15.3

- `tests/api-connections.php`: conexões independentes, criação idempotente, senhas criptografadas sem exposição, mesma URL/ID externo em origens distintas, conferência de pai/mãe/responsável acadêmico/financeiro, reaproveitamento de pessoa entre papéis, remapeamento restrito à origem, restauração de ocultados, campos adicionais ocultos/listas, retirada de blocos, histórico, configuração anterior, versão e permissão de administrador.
- Regressões `external-people-api.php` e `mapped-hidden-import.php`: busca sem criar pessoas, confirmação atômica, idempotência, identificação do requerimento, campos mapeados ocultos, valores em listas e logs preservados.
- `tests/external-api-ui.cjs`: lista de conexões, credenciais em branco ao editar, origem nos testes/buscas, blocos separados de pessoas, salvamento por conexão e conferência dos responsáveis. Configurações continuam carregando sob demanda.
- Chromium em 1440, 390 e 320 px, com API simulada: conexão e cadastro em modal, blocos Pai/Mãe/Responsável, envio do mapeamento, conferência, reaproveitamento da mãe e ausência de rolagem horizontal. Capturas conferidas visualmente.
- Exportação seletiva e planejador de índices aprovados. Sintaxe PHP 8.1 e JavaScript validada. Schema 31 adiciona coluna com padrão `principal` e índice por conexão/situação; credenciais anteriores continuam legíveis sem novo cadastro.
- Serviços executados em PHP-WASM/SQLite com fronteiras WordPress simuladas. Não houve consulta à API real do colégio nem execução de dbDelta em WordPress/MySQL real.

## 0.15.2

- `tests/school-identity.php`: 34 verificações, incluindo textos, quebras de linha, cores herdadas, contraste, paleta própria preservada, compatibilidade das cores anteriores, validação, versão e permissões administrativas.
- `tests/portal-auth.php`, `tests/portal-render.php` e `tests/portal-routing.php`: login, recuperação, redefinição, nonces, origem, limites de tentativas, mensagens, destinos do portal, escape dos textos e carregamento dos recursos do login antes do conteúdo. Os stubs de página e nonce acompanham os contratos reais do WordPress.
- `npm run test:login`: prévia em duas áreas, paleta própria/herdada, fundos, logo, textos, token do nome do colégio, mostrar/ocultar senha sem envio e configurações sob demanda.
- Chromium com HTML renderizado pelo PHP: 1920, 1440, 900, 768, 390 e 320 px. Verificados os painéis, cores, imagem, ausência de overflow e envio nativo dos dados ao portal. Recuperação/redefinição, link expirado e temas alternativos conferidos em desktop e celulares. Requisições interceptadas localmente, sem autenticar ou enviar e-mail real.
- Configuração em modal validada em 1440, 390 e 320 px: logo visível, edição de cores/textos, prévia e payload de salvamento completo.
- Sintaxe PHP 8.1/JavaScript validada. Sem alteração do schema 30 e sem acesso à instalação WordPress do colégio.

## 0.15.1

- `tests/family-overview.php`: 14 verificações em PHP-WASM/SQLite, incluindo foto, ausência de turma, período vigente configurado, rematrícula futura, cancelamento, transferência externa, múltiplas coligadas e revogação do vínculo.
- `tests/family-students-ui.cjs`: apresentação de RA/turma/período, iniciais, falha da imagem, períodos não configurados e manutenção dos modais de contatos/documentos.
- Chromium em 1440, 768, 390 e 320 px, com API simulada: foto, alinhamento à esquerda, ausência de rolagem horizontal e abertura do modal pelo nome.
- O teste legado `tests/portal-ui.cjs` falha também na versão anterior ao esperar três links no menu inferior: ainda conta Perfil, já removido dessa navegação. Essa expectativa não foi alterada neste ajuste.
- Sintaxe PHP 8.1/JavaScript validada. Sem alteração do schema 30; não houve teste na instalação WordPress do colégio.

## 0.15.0

- `tests/family-students.php`: contatos permitidos, normalização, sincronização do e-mail WordPress, versões, idempotência, vínculo vigente, isolamento por coligada, tipos, upload, pendência, aprovação, rejeição e reenvio. Histórico e auditoria preservados. Exclusão com falha de disco permanece registrada para nova tentativa.
- `tests/student-document-files.php`: criptografia AES-GCM real, hash, exclusão física, limite real de 5 MB, extensão/MIME, conteúdo disfarçado, integridade e token seguro. A fronteira de upload HTTP é substituída no teste de arquivos; nenhuma chave de teste é usada em produção.
- `tests/family-students-ui.cjs`: nome abre modal, formulário de contatos, campo de upload por tipo, pendência, motivo de rejeição/reenvio, OK e cadastro de tipos em modal.
- Chromium em 1440, 390 e 320 px: formulários, upload com File/FormData do navegador, conferência, rejeição/reenvio, aprovação e configurações. Verificados layout e ausência de rolagem horizontal; API simulada.
- Regressões de permissões, avatar, contatos, coligadas, exportação, índices, ficha do aluno, modais e carregamento sob demanda das configurações aprovadas.
- Sintaxe PHP 8.1 e JavaScript aprovada. Schema 30 aditivo: duas tabelas com FKs e unicidade do envio ativo. Não houve instalação/migração em WordPress/MySQL real nem envio de documentos ao site do colégio.

## 0.14.17

- PHP-WASM/SQLite: avatar no schema atual para ID, objeto de usuário, e-mail, autor de post e comentário. Fotos existentes, remoção, reaplicação, imagem indisponível, avatar padrão explícito e bloqueio durante erro de migração verificados.
- Testes de contas e edição do próprio perfil aprovados; sincronização e permissões preservadas.
- JSDOM: salvar/remover foto atualiza o cabeçalho com a URL retornada pelo WordPress e elimina o srcset antigo; menu mantém primeiro nome, hover, toque, teclado e saída. Renderização PHP sem a setinha.
- Chromium em 1440, 390 e 320 px: avatar e primeiro nome visíveis, marcador removido, submenu por mouse/toque e teclado, sem rolagem horizontal.
- Sintaxe PHP 8.1 e JavaScript aprovada. Sem alteração do schema 29. Não houve acesso à instalação WordPress do colégio.

## 0.14.16

- `tests/guardian-mail.php`: remetente autenticado, destinatário conferido, idempotência, concorrência, falhas e autorização; execução em PHP-WASM/SQLite com fronteira WordPress simulada.
- `php tests/fluent-brevo.php /caminho/para/fluent-smtp`: integração com as classes originais do FluentSMTP (provedor Brevo, BaseHandler e FluentPHPMailer), substituindo apenas hooks/PHPMailer e HTTP por simuladores. Validado com FluentSMTP 2.4.1: reproduz a sobrescrita original e verifica o payload corrigido, Reply-To, isolamento de outra mensagem e restauração após sucesso, rejeição e exceção. Nenhum pedido é enviado ao Brevo.
- `cd tests && npm run test:communication`: modal fecha somente após sucesso, exibe confirmação na ficha e preserva texto em falhas/pendências; atalho de conversa mantém aluno e responsável.
- Chromium em 1440, 390 e 320 px: fechamento automático, retorno do foco ao e-mail, confirmação visível, mensagem preservada em falha e ausência de rolagem horizontal.
- Sintaxe PHP 8.1/JavaScript aprovada. Sem alteração no schema 29. Não houve envio real pelo Brevo nem acesso à instalação WordPress do colégio nesta revisão.

## 0.8.0

- Sintaxe: 48 arquivos PHP no parser PHP 8.1; JavaScript validado.
- PHP 8.5 WASM + SQLite: suites domain, accounts, avatar, import, student-workflow, portal-permissions, portal-routing, academic-management, payment-plans e civil-status passaram.
- civil-status: 23 verificações, incluindo migração legada, IDs, edição, exclusão bloqueada, permissões, importação, RA, acentos, e-mail e perfil próprio.
- JSDOM: workflow-ui, fields-ui, portal-ui, management-ui e payment-ui passaram.
- O simulador de remove_accents foi ampliado para contemplar acentos em maiúsculas, conforme usados na padronização.

Não executado: integração em WordPress/MySQL real, dbDelta real, concorrência e testes visuais em celular. O teste wordpress-integration depende de EDERP_TEST_BOOTSTRAP apontando para instalação descartável e não foi executado neste ambiente.

## Correção 0.8.1

40 verificações de índices passaram: preservação dos índices anteriores, adição sem colisão de nomes, reexecução, instalação recente, detecção de índice ausente e geração para todas as 28 tabelas. Sintaxe validada em 50 arquivos PHP. Não foi executado dbDelta com MySQL real; o teste reproduz metadados SHOW INDEX e valida o planejador. O erro real do servidor ainda não foi disponibilizado, além da mensagem genérica na captura de tela.

Referência consultada: https://developer.wordpress.org/reference/functions/dbdelta/ — comparação de índices por nome e definição.

## 0.8.3

Teste JSDOM people-ui: lista inicial, filtros combinados, obrigatórios, navegação por etapas, envio de cadastro e edição com versão. Sintaxe PHP/JavaScript validada. Ainda não validado visualmente em navegador real nem com WordPress/MySQL instalado.

## 0.8.4

JSDOM: edição por abas com salvamento antecipado e preservação dos campos; cadastro novo continua por etapas. Gestão acadêmica/matrículas: seletores sem busca extra, mantendo vínculos dependentes, filtros da listagem e envio em lote. Sem teste visual em WordPress real.

## 0.9.0

35 verificações PHP/SQLite de planos/financeiro: período obrigatório, plano incompatível, alteração de período, destino próprio/anterior, oferta somente para turma definida, ausência de destino e adulteração de destino. Contratos, rematrícula sem parcelas, geração posterior e idempotência preservados. JSDOM payment-ui e management-ui passaram com destino somente leitura e seletores novos. Sintaxe PHP/JS conferida. Migração dbDelta/FKs ainda depende de homologação em WordPress/MySQL real.

## 0.9.1

- PHP-WASM com SQLite e stubs WordPress: 42 verificações de planos, períodos, progressão e financeiro aprovadas.
- JSDOM: 7 cenários de gestão, 4 de pessoas e 4 de portal financeiro aprovados, incluindo período de destino automático.
- CSS das abas com seletores específicos para prevalecer sobre os botões genéricos Journey.
- Validação local não substitui a instalação em WordPress/MySQL. Não houve teste visual em navegador real nem execução de dbDelta em MySQL nesta revisão.

## 0.9.2

- JSDOM: 7 cenários de gestão, 4 de pessoas e 4 do portal financeiro passaram.
- Novos testes: abertura/fechamento/criação/edição das cinco áreas acadêmicas; foco de retorno, validação, manutenção de valores após erro e bloqueio de fechamento durante envio.
- Perfil e permissões: formulários em diálogos, versão do cadastro mantida, resumo atualizado e menu mobile independente dos diálogos de cadastro.
- Sintaxe dos quatro scripts de interface e dos PHP alterados validada.
- Sem mudança no schema ou nas regras de negócio. Não houve teste visual em navegador real nem instalação em WordPress nesta revisão.

## 0.9.3

- PHP-WASM/SQLite com stubs WordPress: 22 verificações de importação de turmas, cópia, preservação dos vínculos, idempotência, reversão de transação e autorização.
- As 15 verificações anteriores de importação de pessoas/alunos passaram.
- JSDOM: modal de cópia em período novo/existente, envio do CSV de turmas após prévia e regressão dos modais acadêmicos passaram.
- Sintaxe PHP/JavaScript validada. Não executado em WordPress/MySQL real nem validado visualmente no site.

## 0.9.4

JSDOM: filtro de curso enviado, remoção do filtro e regressões de gestão aprovados. PHP-WASM/SQLite: curso, período e busca combinados e ordenação entre páginas validados. Não houve instalação nem teste visual no WordPress real.

## 0.9.5

- PHP-WASM/SQLite + mocks WordPress: 20 verificações de usuários, alvos protegidos, revogação, concorrência, rollback, sincronização, auditoria e adaptador de shortcode.
- 22 verificações anteriores de perfil e autorização passaram.
- JSDOM: busca, edição em modal, confirmação de senha, conta sem e-mail e link ao acesso assistido; regressões de perfil e permissões passaram.
- Sintaxe de 59 arquivos PHP validada.
- O adaptador foi testado com shortcode simulado. Não houve acesso à instalação nem à licença do Web357 do usuário, teste real de troca/retorno de sessão ou teste visual no WordPress. Homologar com duas contas de teste e barra de retorno habilitada antes de uso operacional.

## 0.9.6

- PHP-WASM com stubs WordPress: 12 verificações de identidade, validação, autorização, versão, logo e CSS; 15 verificações de gestão de usuários.
- JSDOM: 7 cenários de gestão e regressão da tela Usuários sem acesso assistido passaram.
- Sintaxe PHP e JavaScript validada.
- Não houve instalação em WordPress/MySQL real nem teste visual no site. O personalizador e a biblioteca de mídia precisam de homologação na instalação.

## 0.9.7

- PHP-WASM/SQLite: 42 verificações de planos e rematrícula e 14 de gestão de ofertas passaram (lote atômico, incompatibilidade de período, concorrência, idempotência, permissões e proteção de ofertas utilizadas).
- JSDOM: seleção de várias turmas, envio, edição preenchida, exclusão em modal e restrição de ações passaram.
- Sintaxe PHP e JavaScript verificada. Não houve teste em WordPress/MySQL real nem validação visual no site.

## 0.9.8

- PHP-WASM/SQLite: 29 verificações novas de código, sequência, situações, pagamentos, estorno, reprovação atômica, preservação financeira, transferência externa, histórico e cancelamento; 42 verificações de planos/rematrícula, 22 do fluxo de matrícula, 15 de importação, 15 de usuários e 14 de ofertas passaram.
- Migração de numeração: preservação de código importado/zeros, resolução de conflito com IDs e idempotência passaram.
- Autenticação com mocks WordPress: 13 verificações de nonce, origem, erro/sucesso de login, mensagem genérica, link do e-mail no portal, validação/uso da chave e limite de tentativas passaram. Nenhum formulário de acesso usa wp-login.php.
- Documentos: testes com OpenSSL real no runtime PHP validaram descriptografia, metadados, autoria, token, integridade e arquivo ausente. Upload HTTP multipart com APIs WordPress ainda requer homologação.
- JSDOM: modais de transferência externa, resultado, cancelamento e histórico; listagem simplificada de usuários; regressões de pessoas, campos, configurações, gestão, ofertas, cópia, perfil e financeiro passaram.
- A fixture antiga de student-workflow foi atualizada para associar os planos aos respectivos períodos, regra vigente desde o schema 8.
- Sintaxe PHP/JS verificada. Não houve instalação em WordPress/MySQL real, envio real de e-mail nem teste visual no site. Homologar migração MySQL, cookies, e-mail e envio/download da declaração na instalação.
