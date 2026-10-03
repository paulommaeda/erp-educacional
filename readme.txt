=== ERP Educacional ===
Contributors: paulommaeda
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.9.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ERP acadêmico e financeiro do Colégio Journey em tabelas próprias.

== Description ==
Pessoas, alunos, responsáveis, matrículas, períodos, turmas, planos de pagamento e financeiro. Portal mobile e permissões WordPress.

== Installation ==
Instale o ZIP mantendo a pasta erp-educacional. Faça backup antes de atualizar. Para migrações, abra ERP > Configurações.

== Changelog ==

= 0.9.7 =
* Ofertas de rematrícula em lote com seleção de várias turmas.
* Listagem paginada, filtro por período de destino, edição e exclusão em modal.
* Preservação de ofertas utilizadas, auditoria e controle de edição concorrente.


= 0.9.6 =
* Configuração institucional do colégio, endereço, contatos e logo.
* Cores do ERP e identidade integradas ao personalizador WordPress.
* Removida integração Acessar como usuário; edição de e-mail e senha preservada.
* Uma identidade por instalação, sem alteração no schema.

= 0.9.5 =
* Tela Usuários com pesquisa, paginação e edição de e-mail/senha em modal.
* Permissões de consulta, e-mail, senha e acesso assistido independentes.
* Integração pelo shortcode oficial Login as a User PRO (Web357), com configuração requerida no fornecedor.
* Proteção de contas privilegiadas, sincronização de e-mail e auditoria sem senhas.

= 0.9.4 =
* Filtro por curso na listagem de turmas.
* Ordenação alfabética por nome antes da paginação.

= 0.9.3 =
* Importação de turmas por CSV com códigos de período, curso, turno e plano.
* Cópia integral de turmas e planos para novo período ou destino existente, em modal.
* Vínculos financeiros preservados nos novos cadastros; operação atômica e idempotente.

= 0.9.2 =
* Cadastros e edições em modais no admin e no portal.
* Estrutura acadêmica com listagens, filtros e botão de cadastrar.
* Matrículas individuais/em lote, responsáveis, estados civis e perfis com o mesmo padrão.
* Navegação mobile preservada com múltiplos diálogos na página.

= 0.9.1 =
* Próximo período letivo com validação e integração ao destino da rematrícula.
* Migração segura das progressões existentes quando o destino é inequívoco.
* Abas do modal de pessoa alinhadas e responsivas.

= 0.9.0 =
Planos por período letivo e destino fixo de rematrícula configurado na turma.
= 0.8.4 =
Seletores acadêmicos sem campo de busca duplicado. Edição de pessoa por abas com salvamento em qualquer aba.
= 0.8.3 =
Pessoas com filtros por nome e código, cadastro e edição em modal por etapas.
= 0.8.2 =
Atualizador GitHub integrado com Plugin Update Checker 5.7, branch main e slug erp-educacional.
= 0.8.1 =
Correção de nomes de índices na migração de contratos. Schema 7.
