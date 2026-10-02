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
