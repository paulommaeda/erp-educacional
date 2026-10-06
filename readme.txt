=== ERP Educacional ===
Contributors: paulommaeda
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.13.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ERP acadêmico e financeiro do Colégio Journey em tabelas próprias.

== Description ==
Pessoas, alunos, responsáveis, matrículas, períodos, turmas, planos de pagamento e financeiro. Portal mobile e permissões WordPress.

== Installation ==
Instale o ZIP mantendo a pasta erp-educacional. Faça backup antes de atualizar. Para migrações, abra ERP > Configurações.

== Changelog ==

= 0.13.3 =
* Requerimentos identificados pelo ID de origem ou assinatura, sem chave CPF. Aceitos saem da fila e da API de listagem e não reaparecem na busca.

= 0.13.2 =
* Conferência por CPF sem comparação de datas vazias.
* CPF identifica requerimentos e aproveita pessoas existentes sem sobrescrever dados.
* Configurações em dez seções carregadas sob demanda e mantidas em memória.


= 0.13.1 =
* Estilização completa e responsiva dos modais de integração e mapeamento da API.


= 0.13.0 =
* Conexão HTTPS com autenticação Basic para importar pessoas via API.
* Teste de conexão, descoberta de campos e mapeamento por bloco de pessoa, incluindo campos adicionais.
* Requerimentos pendentes para conferência, aproveitamento de cadastros e criação transacional de pessoas.
* Busca manual por página, senha criptografada e prevenção de importações repetidas.


= 0.12.16 =
* Corrige rotas de exclusão de grupos e campos.
* Alinha tipos de disciplina e seus botões no desktop e mobile.
* Exibe valor do desconto por pontualidade fora do modal.
* Remove botão de acesso ao personalizador do WordPress.


= 0.12.15 =
* Exclusão de campos com confirmação e remoção dos respectivos valores; grupos com campos são protegidos.
* Nomes de campos e grupos preservam capitalização.
* Ordem da ficha: dados, responsáveis, matrículas, financeiro, histórico e grupos adicionais.


= 0.12.14 =
* Grupos de campos adicionais com botões próprios na ficha do aluno.
* Permissões por perfil aplicadas também aos dados retornados pelas APIs.
* Migração dos campos existentes para Dados complementares, preservando valores.


= 0.12.14 =
* Campos adicionais configuráveis e compartilhados: tipos, chave da API, seção, visibilidade, ordem e inativação.
* Valores na ficha do aluno e APIs de pessoas, com edição em modal e normalização.
* Cadastro de pessoas sempre com dois campos por linha.


= 0.12.14 =
* Paginação financeira bloqueia requisições simultâneas e páginas inexistentes; controles ocultos com página única. Inicialização do app protegida contra execução duplicada.

= 0.12.11 =
* Exibe desconto condicional previsto, mantém líquido sem pontualidade e calcula previsão da baixa pela data de pagamento. Espaçamento dos filtros financeiros.

= 0.12.10 =
* Chat com campo de mensagem na largura total e ações lado a lado. Primeiro nome do responsável. Perfil removido da navegação inferior.

= 0.12.9 =
* Chat por canais com responsáveis, Secretaria, Coordenação, Orientação e Supervisão. Histórico, não lidas e anexos privados. Migração 16.

= 0.12.8 =
* Baixa pela parcela abre com aluno, contrato e parcela fixos; apenas valor, data e forma de pagamento editáveis.

= 0.12.7 =
* Resumo financeiro: valor total, valor pago, valor líquido e saldo inadimplente das parcelas vencidas.

= 0.12.6 =
* Operações financeiras com seleção de aluno, contrato, parcela, baixa e responsável, sem digitação de IDs.

= 0.12.5 =
Gestão financeira organizada em consultas, resumo de valores, operações em modais e cartões mobile. Detalhes e baixa no próprio lançamento, com datas e moeda brasileiras.

= 0.12.4 =
Visão familiar sem códigos internos na tabela acadêmica, data da matrícula em dd/mm/aaaa. Cartão inicial da rematrícula identifica o próximo período e as datas de disponibilidade.

= 0.12.3 =
Portal familiar unificado entre coligadas, contratos e lançamentos com nome/CNPJ da coligada. Tema básico e página inicial configurados automaticamente, com fonte Inter.

= 0.12.2 =
Históricos escolares anteriores na ficha do aluno: escola, endereço, anos, séries, cursos, disciplinas, tipos e notas finais. Tipos nas Configurações. Modais, auditoria, exportação e FKs. Migração 15.

= 0.12.1 =
Exportação seletiva por tabela e coligada, incluindo identidade visual, cores e configurações. JSON paginado, acesso exclusivo ao administrador, sem senhas ou tokens.

= 0.12.0 =
* Login, recuperação e redefinição de senha no portal do ERP.
* Lista de usuários simplificada e paginação adaptada ao mobile.
* Código de pessoa preservado na importação e sequência configurável.
* Matrículas Reservado/Cursando integradas à baixa da primeira parcela.
* Transferência externa com solicitante, destino e documento privado.
* Resultado aprovado/reprovado, substituição automática por reprovação e cancelamento de rematrícula.
* Histórico de alterações com data, hora e autor. Migração schema 10.


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
