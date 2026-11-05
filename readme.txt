=== ERP Educacional ===
Contributors: paulommaeda
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.19.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ERP acadêmico e financeiro do Colégio Journey em tabelas próprias.

== Description ==
Pessoas, alunos, responsáveis, matrículas, períodos, turmas, planos de pagamento e financeiro. Portal mobile e permissões WordPress.

== Installation ==
Instale o ZIP mantendo a pasta erp-educacional. Faça backup antes de atualizar. Para migrações, abra ERP > Configurações.

== Changelog ==

= 0.19.9 =
* Administrador do WordPress pode excluir lançamentos inclusive pagos; Financeiro exclui somente sem pagamentos.
* Exclusão individual pelo menu de ações, com motivo obrigatório, controle de versão, idempotência e auditoria.
* Títulos excluídos saem das consultas, totais, portal, recibos, bloqueio por inadimplência e APIs operacionais.
* Baixas e vínculos são preservados para auditoria e backup. Não cancela nem estorna faturas no Educbank.
* Filas, consultas e retornos Educbank não reativam títulos excluídos. Migração aditiva para schema 37.

= 0.19.8 =
* Requerimento em uma coluna no celular, inclusive na conferência em modal, até 767 px de largura.
* Campos, botões e opções com tamanho e espaçamento adequados ao toque; desktop mantém duas colunas.
* Preserva dados, validações e consulta por CEP. Schema 36 mantido.

= 0.19.7 =
* Endereço do aluno obrigatório no requerimento e na conferência, com complemento opcional e número aceitando S/N.
* Consulta automática pelo CEP preenche rua, bairro, UF e município e preserva número, complemento e correções manuais.
* Consulta pelo servidor com cache e limites; preenchimento manual disponível em caso de falha ou CEP não encontrado.
* Schema 36 mantido.

= 0.19.6 =
* Reordena o requerimento: curso, série e turno pretendidos; nome e nascimento do aluno; demais campos na sequência existente.
* Preserva validações, campos, mapeamentos e dados. Schema 36 mantido.

= 0.19.5 =
* Novos usuários com senha aleatória e convite por e-mail para criar a senha no portal.
* E-mail obrigatório no cadastro e na edição de pessoas, inclusive importações e requerimentos.
* Convite da conta do aluno também enviado aos responsáveis acadêmicos, com nome, RA e usuário explícitos.
* E-mail HTML com logo, cores e tipografia do colégio/coligada; envio pelo WordPress e SMTP configurado.
* Fila após confirmação do cadastro, retentativas e reenvio por usuários autorizados na edição da conta.
* Senhas existentes preservadas. Schema 36 mantido.

= 0.19.4 =
* Área do portal ocupa a largura disponível no desktop, incluindo contêineres internos e tabelas.
* Botões das parcelas com espaçamento consistente; remove Ver descontos das linhas.
* Número da parcela abre resumo em modal com situação, valores, pontualidade, bolsas, juros, multa e saldo.
* Resumo compartilhado entre ficha do aluno, gestão financeira e portal da família, com fatura e recibo quando disponíveis.
* Valores em reais e tabelas responsivas preservam os dados e as regras financeiras. Schema 36 mantido.

= 0.19.3 =
* Reconhece aluno_nome e os campos de endereço do retorno atual do CRM, incluindo endereco_aluno_dep para CEP.
* Preserva caminhos anteriores do Fluent Forms e mapeamentos já configurados. Schema 36 mantido.

= 0.19.2 =
* Turno pretendido limitado a Matutino e Vespertino.
* Mapeamento preparado para o requerimento Fluent Forms, incluindo aluno, responsáveis, saúde, listas e campos adicionais.
* Requerimentos da API usam a mesma conferência em cinco etapas e aprovação transacional do formulário público.
* Mantém credenciais, dados pendentes, histórico, permissões, isolamento por coligada e conexões genéricas. Schema 36 mantido.
* Corrige quebra de textos de múltipla escolha nos modais mobile.

= 0.19.1 =
* Nacionalidade no início e seleção de UF/cidade de nascimento para brasileiros.
* Curso e ano/série por listas, com opções compatíveis e cursos ativos da coligada.
* Corrige a opção duplicada de responsável no financeiro.
* Autorizações de retirada por Pai, Mãe ou Outro; parentesco e dados dos pais são automáticos.
* Preserva dados de requerimentos anteriores na conferência. Schema 36 mantido.


= 0.19.0 =
* Requerimento público nativo, sem login, com formulário em etapas e conferência antes de criar pessoas e usuários.
* Cadastro de aluno, pai, mãe e outros responsáveis com reaproveitamento explícito de pessoas existentes.
* Ficha médica e aprendizagem estruturadas, 78 campos complementares, contatos de emergência e autorizações de retirada.
* Foto, documentos de guarda/adoção protegidos, PDF do requerimento, histórico e isolamento por coligada.
* Configuração dos textos e do recebimento; página pública e shortcode criados pelo plugin. Migração 36.


= 0.18.0 =
* Descontos com descrição, edição por parcela inicial, suspensão e histórico de regras; preserva parcelas anteriores e pagamentos.
* Tipo Indicação com seleção do RA: ativa apenas com matrícula Cursando do indicado na coligada/período do contrato, desativando e reativando parcelas futuras automaticamente.
* Recálculo após confirmação da operação, fila com retentativa e recuperação por cron, auditoria e controle de versões; falhas não desfazem pagamentos do indicado.
* Detalhamento por nome, descrição e valor no financeiro do ERP, com modais e cartões responsivos; dados do indicado ficam restritos à gestão.
* Educbank mantém desconto fixo acumulado e plano da fatura; nomes enviados em variável extra na criação e justificativa da atualização, conforme recursos da API.
* Schema 35 acrescenta descrições, regras por parcela, snapshots e controle de indicações. Exibição do rótulo na página Educbank depende do modelo do parceiro.

= 0.17.2 =
* Corrige envio de bolsas após integração: atualiza fixedDiscountCents somente nas parcelas afetadas e preserva o EbpayPlan confirmado na fatura Educbank.
* Inclusão, soma e exclusão de bolsas sincronizam faturas existentes, incluindo desconto zero, sem duplicar cobranças ou alterar parcelas pagas.
* O plano das configurações vale para criações; atualizações usam o plano existente. Plano ausente ou divergente exige conferência.
* Normaliza o controle de conteúdo sem reenviar faturas apenas pela troca da configuração de plano; compatibilidade com hashes anteriores. Schema 34 mantido.

= 0.17.1 =
* Corrige conferência de studentExternalId quando o Educbank devolve o identificador externo da matrícula em vez do RA.
* Preserva validação de UUID, contrato, lançamento, CNPJ e responsável financeiro; mantém o RA e os dados recebidos intactos.
* Aplica a mesma compatibilidade na recuperação de resposta perdida, sem criar outra fatura.
* Diagnósticos específicos para divergência de matrícula, contrato e aluno. Schema 34 mantido.

= 0.17.0 =
* Retorno Educbank autenticado e persistente, com confirmação da fatura na API antes da baixa pelo valor efetivamente pago.
* Data, forma, pontualidade concedida, bolsa, juros e multa preservados; recibo e situação cursando passam a refletir a baixa automática.
* Proteção contra avisos repetidos, conciliação com baixas manuais e estorno confirmado de recebimentos da integração, com auditoria.
* Retorno de pagamentos em Configurações > Educbank: ativação por ambiente/coligada, webhook, consulta de recuperação, histórico e reprocessamento em modal.
* Schema 34 cria caixa de retornos e vínculos de recebimento. Retorno começa desativado; ative explicitamente no ambiente escolhido.

= 0.16.5 =
* Meu financeiro inclui número da parcela e vencimento em dd/mm/aaaa.
* Parcelas pagas mostram Recibo, com visualização e download de PDF autenticado, logo da coligada, aluno, responsável, contrato, valores e pagamentos efetivos.
* Recibos também disponíveis na gestão e na ficha do aluno; baixas estornadas são desconsideradas e dados sem comprovação exigem conferência.
* Geração local sob demanda, sem acesso público ao arquivo, com identidade por coligada e biblioteca PDF isolada de outros plugins.

= 0.16.4 =
* Meu financeiro exibe valor original, desconto condicional previsto, desconto incondicional, valor líquido, status colorido e botão Pagar para a fatura Educbank.
* Abrir fatura disponível no menu de ações da gestão, nos detalhes e no financeiro da ficha do aluno.
* Links por responsável, coligada, tenant e ambiente; responsável aguarda confirmação de atualizações e não recebe diagnósticos técnicos da fila.
* Layout em cartões no mobile, sem deduzir pontualidade antecipadamente do líquido ou oferecer novo pagamento para parcelas pagas/canceladas.

= 0.16.3 =
* Falhas de CEP na criação/alteração de matrícula ou fatura identificam o código do responsável financeiro e o campo em Pessoas > Endereço.
* Orientação para conferência nos Correios e revisão da base Educbank no ambiente selecionado, preservando o motivo original e os dados cadastrados.
* Testes cobrem recusa 404, correção de CEP, liberação das faturas e reenvio sem duplicar registros.

= 0.16.2 =
* Corrige filtros booleanos das consultas Educbank: true/false em vez de 1/0, evitando erro de validação antes do envio.
* Testar conexão verifica as consultas de matrículas e faturas com os filtros utilizados na integração; informa que criações são validadas pela fila.
* Erros passam a mostrar operação e validações devolvidas pelo parceiro, com campos e mensagens, sem registrar credenciais ou o corpo bruto da resposta.
* Ao confirmar uma matrícula, retoma as faturas que estavam bloqueadas exclusivamente pela espera dessa matrícula.

= 0.16.1 =
* Indicador Educbank ao lado da situação de cada lançamento na gestão financeira, detalhes e ficha do aluno.
* Integrado em verde somente com UUID e sincronização confirmada; não integrado em vermelho para envio ausente, pendente ou com erro.
* Consulta em lote por coligada, tenant e ambiente, sem chamadas ao parceiro; preservados valores e situações de pagamento.

= 0.16.0 =
* Primeira etapa Educbank: matrícula e mensalidades enviadas em fila persistente, com credenciais por coligada e ambiente.
* Configuração, mapeamento de ano/semestre e série por turma, teste de conexão, histórico, reprocessamento e inclusão dos cadastros existentes em lotes de 20.
* Faturas com matrícula Educbank, aluno/RA, responsável financeiro atual, CNPJ, bolsa e pontualidade separados em centavos.
* Atualiza turma, responsável, valores, descontos e vencimentos; comunica cancelamentos de faturas. Rematrícula só gera faturas após a geração financeira das parcelas.
* Schema 33 acrescenta vínculos/fila com FKs; integração começa desativada. Baixa automática por retorno/webhook ainda não faz parte desta etapa.

= 0.15.4 =
* Documentos e informações pessoais: local e UF de nascimento, raça/etnia, nacionalidade e grau de instrução; profissão mantida na mesma seção.
* Novos campos disponíveis no cadastro, edição, perfil pessoal, importação CSV, conferência da API e ficha do aluno com a proteção de acesso existente.
* Schema 32 acrescenta colunas opcionais, preservando pessoas e campos adicionais já cadastrados.

= 0.15.3 =
* Várias conexões de requerimentos, com nome, URL, autenticação, situação e mapeamento independentes.
* Mapeamento por blocos de Aluno, Pai, Mãe, Responsável acadêmico, Responsável financeiro e Outro responsável; todos importados como pessoas após conferência.
* Conexão de origem identificada na fila e no histórico. Preservadas configuração anterior, credenciais criptografadas e detecção de requerimentos já aceitos.
* Schema 31 acrescenta a identificação da conexão e índice aos requerimentos existentes.

= 0.15.2 =
* Novo login com apresentação institucional e formulário lado a lado; no mobile, apresentação compacta acima do acesso.
* Textos editáveis, nome automático do colégio, logo do sistema e escolha entre cores herdadas ou paleta própria.
* Mantidos fundo sólido, degradê, imagem, largura e arredondamento, com prévia reativa nas configurações.
* Mostrar/ocultar senha acessível, recuperação e redefinição no próprio portal.

= 0.15.1 =
* Sua família na escola: foto, nome, RA, turma atual e período letivo alinhados à esquerda, com layout responsivo.
* Usa o período vigente de cada coligada autorizada, sem antecipar a turma da rematrícula.
* Exibe iniciais quando não há foto e mantém o acesso ao modal de contatos e documentos pelo nome do aluno.

= 0.15.0 =
* Responsável abre os contatos do aluno clicando no nome em Sua família na escola. Edição de e-mail, telefone e endereço em modal, com sincronização da conta e auditoria.
* Documentos solicitados configuráveis por colégio: nome, orientações, obrigatoriedade, ordem e situação.
* Upload privado de PDF/JPG/PNG/WebP, até 5 MB; aprovação com OK ou rejeição com motivo, exclusão do arquivo e liberação para novo envio.
* Secretaria recebe fila com filtros e conferência em modal; documentos também aparecem na ficha do aluno conforme permissões.
* Schema 30 adiciona tipos de documentos e histórico de envios, com FKs por coligada. Metadados disponíveis na exportação seletiva.

= 0.14.17 =
* Foto da pessoa volta a ser aplicada como avatar do usuário WordPress: verificação do banco acompanha a versão atual, inclusive para fotos já cadastradas.
* Salvar ou remover a foto no perfil atualiza o avatar do cabeçalho imediatamente, sem manter o srcset do avatar anterior.
* Remove a setinha ao lado do primeiro nome, mantendo o submenu Meu perfil / Sair por mouse, toque e teclado.

= 0.14.16 =
* E-mail ao responsável preserva nome e e-mail da conta autenticada no FluentSMTP/Brevo, inclusive com remetente forçado na conexão.
* Ajuste limitado à mensagem do ERP, com restauração do provedor após sucesso ou falha.
* Modal de e-mail fecha após confirmação do envio pelo WordPress; resultado aparece no cartão do responsável. Erros e pendências mantêm o modal aberto.

= 0.14.15 =
* Ações de lançamentos financeiros reunidas em menu flutuante, sem alterar a altura da linha.
* Dados pessoais do aluno passam a exigir liberação em Perfis e acessos; a API omite documentos, contatos, endereço e conta quando não autorizados.
* Margens dos botões de vínculo ajustadas e atalho Conversar abre o responsável/aluno no canal do atendente.
* E-mail do responsável abre modal com assunto e corpo; wp_mail utiliza a conta autenticada em From/Reply-To e destinatário conferido no vínculo.
* Envios reservados antes de wp_mail impedem cópias em tentativas concorrentes; resultados são registrados na auditoria.


= 0.14.14 =
* Dados pessoais do aluno apresentados como ficha com identificação, contatos e endereço.
* Áreas Ficha médica, Dificuldades de aprendizagem e Laudos com liberação independente em Perfis e acessos, validada nos dados e definições da API.
* Campos adicionais podem ser destinados às áreas protegidas; campos/grupos existentes com esses nomes mantêm valores e mapeamentos.
* Cartões de pais e responsáveis exibem e-mail/telefone, atribuições espaçadas e ações alinhadas.
* Corrige carregamento de campos adicionais após atualização do banco. Migração 29 disponibiliza as áreas padrão, exclusivas do administrador até sua liberação.


= 0.14.13 =
* Ícone da conta mostra apenas o primeiro nome e abre submenu com Meu perfil e Sair, por mouse, toque ou teclado.

= 0.14.12 =
* Conversas por canal, responsável e aluno, com primeiro nome do responsável, primeiro nome do aluno e RA no cabeçalho e na lista.
* Cabeçalho abre modal com nome completo, foto e turmas do período vigente.
* Nova conversa exige escolha entre os alunos vinculados. Histórico antigo pode ser identificado sem perder mensagens; a identificação é necessária para continuar o envio.
* Migração de banco 28: vínculo opcional de aluno para preservar conversas antigas, chave única por aluno e FK de coligada.


= 0.14.11 =
* Personalização do login: logo do sistema, fundo sólido, degradê ou imagem e cores, largura e arredondamento do formulário, com prévia.

= 0.14.10 =
* Menu de ações das matrículas sobreposto à tabela, sem alterar a altura das linhas.

= 0.14.9 =
* Ações das matrículas na ficha do aluno reunidas em menu hambúrguer, no desktop e mobile.

= 0.14.8 =
* Dados do professor sem nome repetido e sem botões de editar; e-mail, nascimento e usuário.
* Diretório de usuários exibe login e perfis; administrador inclui e remove perfis operacionais no modal.
* Espaçamento entre pesquisa e lista de usuários.


= 0.14.7 =
* Situações financeiras com fundos pastéis: Pago verde, Vencido vermelho e Em aberto amarelo.
* Exibição Pago substitui Baixado nos lançamentos e filtros.


= 0.14.6 =
* Retorno das fichas acima dos seletores alinhados de coligada e período.
* Ficha do professor com cabeçalho, foto e navegação no padrão da ficha do aluno.


= 0.14.5 =
* Período da consulta de professores ao lado da coligada, preservando a ficha aberta ao trocar período.
* Seta discreta e acessível de voltar nas fichas do professor, aluno e matrícula.


= 0.14.4 =
* Ações dos professores alinhadas na mesma linha no desktop.
* Ficha do aluno com menu de áreas excedentes conforme a largura disponível da tela.


= 0.14.3 =
* Planos de pagamento ao lado de Disciplinas e listagem filtrada pelo período da consulta.
* Lista de professores com colunas Pessoa, Situação e ações à direita, adaptada ao mobile.


= 0.14.2 =
* Professores em menu próprio, ficha com disciplinas, turmas e valor da hora por período letivo.
* Reorganização da estrutura acadêmica e alinhamento das ações de copiar e importar turmas.


= 0.14.1 =
* Tipos de disciplina com subtipos opcionais na estrutura acadêmica e histórico escolar anterior.
* Seleção filtrada pelo tipo; vínculos em uso impedem excluir ou mudar o tipo do subtipo.

= 0.14.0 =
* Disciplinas com tipos existentes; grades por curso, composição e vínculo com turmas.
* Professores a partir de pessoas, com perfil WordPress, disciplinas e turmas por período.
* Valores da hora por professor, disciplina e período, sem cálculo de centro de custo.
* CSV de pessoas e alunos permite mapear campos adicionais, inclusive ocultos.
* Vínculos protegidos por FKs, coligada, regras de curso e período e auditoria.

= 0.13.10 =
* CSV de vínculos aluno-responsáveis e matrículas, com modelos e mapeamento de colunas.
* Validação de códigos por coligada, parentescos, atribuições e duplicados; log por linha.
* Importação acadêmica preserva data e situação, sem gerar contratos ou parcelas.

= 0.13.9 =
* CSV continua após linhas inválidas ou duplicadas, com total de sucessos e erros.
* Log em tela e download das linhas puladas com número físico da linha, dados e motivo.

= 0.13.8 =
* Listas aninhadas da API preservam todos os valores, um por linha.
* Ocultar e restaurar requerimentos na conferência, com auditoria e proteção contra reimportação.
* Conversão de pessoa em aluno usa automaticamente seu código como RA; importações legadas preservam RA informado.

= 0.13.7 =
* Listas retornadas pela API são reunidas no mesmo campo, um valor por linha.
* Conferência e edição de campos de texto preservam quebras de linha.

= 0.13.6 =
* Requerimentos pendentes atualizados com o mapeamento vigente, sem duplicação.
* Dados de origem preservados para reaplicar mapeamentos.
* Campos adicionais ocultos continuam na conferência e importação, inclusive ao aproveitar pessoa existente.

= 0.13.5 =
* Grupo opcional nos campos adicionais; destino independente no cadastro da pessoa e na ficha do aluno.
* Campos sem grupo podem usar as áreas existentes da ficha; condições e valores preservados.
* Permissões dos grupos continuam em vigor; campos sem grupo seguem o acesso à área.

= 0.13.4 =
* Regras selecionáveis de exibição dos campos adicionais na ficha do aluno.
* Saudação acima dos seletores de coligada e período, dispostos lado a lado.
* Versão no rodapé; nome e descrição do site nas configurações do colégio.
* Log paginado das importações da API com autor, data, ações, códigos e retrato dos dados aprovados.

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
