# Validação 0.8.0

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
