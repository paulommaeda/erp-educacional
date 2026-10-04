# ERP Educacional — 0.10.4

Portal Journey para gestão acadêmica e financeira em tabelas customizadas WordPress. PHP 8.1+, 64 bits; WordPress 6.4+; MySQL 5.7+ / MariaDB 10.3+, tabelas InnoDB. Versão para homologação.

## Atualizar e preparar

1. Substitua o plugin anterior pelo ZIP `erp-educacional-0.8.2.zip` em uma cópia de homologação com backup.
2. Abra o wp-admin como administrador. A migração adiciona o **schema 7**, sem excluir ou renumerar cadastros. O perfil interno **Financeiro** é criado nessa atualização. Se a migração não concluir, use ERP → Configurações → Verificar/aplicar migrações.
3. No Portal Journey → Configurações, confira o **Período letivo atual**.
4. Em Estrutura acadêmica → **Planos de pagamento**, cadastre código, nome e **valor da anuidade**. Somente administradores criam/editam/excluem planos.
5. Em Estrutura acadêmica → **Turmas**, edite as turmas existentes e selecione o plano. Para cadastrar uma turma nova, selecione período, curso, turno e plano. Uma turma sem plano ativo não pode gerar nova matrícula com contrato.
6. Em **Perfis e acessos → Atribuir perfil**, selecione a pessoa que opera o financeiro e adicione **Financeiro**. Esse é um perfil de funcionário, separado de **Responsável financeiro**, que representa o responsável pelo aluno.

As turmas antigas ficam inicialmente sem plano, para não inventar preços. Contratos e parcelas já existentes continuam com seus valores; a atualização não desfaz cobranças de rematrículas antigas. Não é preciso recriar matrículas ou contratos existentes.

## Fluxo da matrícula inicial

**Pessoa → Aluno e responsáveis → Matrícula / período → Curso → Turno → Turma → Plano da turma → Número de parcelas → Contrato.**

- Cadastre as pessoas, transforme a pessoa em aluno e vincule os responsáveis na ficha. Deve haver exatamente um responsável financeiro vigente.
- Em Matrículas, clique **Matricular aluno**, no alto à esquerda. Selecione período, curso, turno e turma. O plano e a anuidade aparecem automaticamente; o valor é consultado novamente e validado pelo servidor.
- Informe quantidade de parcelas, primeiro vencimento, eventual bolsa total e texto do contrato. A tela mostra uma estimativa do parcelamento. O limite técnico é 120 parcelas.
- Selecione um ou mais alunos. Até 100 por lote, com a mesma turma e condições; a seleção se mantém entre páginas de busca.
- Ao confirmar, o sistema cria matrícula, contrato, parcelas e lançamentos financeiros em uma única transação. Se um aluno tiver impedimento, o lote inteiro é revertido e a mensagem indica o RA.
- No caminho pela ficha, o vínculo inicial ao período continua podendo ser salvo antes. A confirmação da turma agora exige o parcelamento e gera também o contrato e as parcelas.

O contrato guarda plano, nome e versão do plano, anuidade, descontos, total líquido, quantidade de parcelas, primeiro vencimento, termo e responsável. Alterar um plano ou transferir o aluno de turma não recalcula contratos existentes. Os centavos são distribuídos nas primeiras parcelas e o vencimento é ajustado ao último dia dos meses menores. Descontos, juros, multas e baixas continuam separados.

## Rematrícula: geração financeira posterior

1. A Secretaria publica a oferta para o próximo período, com curso de origem/destino, turmas, janela de renovação, termo, quantidade sugerida de parcelas e primeiro vencimento. O valor vem do plano de cada turma; não é digitado na oferta.
2. O responsável acessa a oferta a partir da matrícula do período vigente, escolhe a turma, confere plano/anuidade, define a quantidade de parcelas e aceita o termo.
3. A confirmação cria a matrícula e o contrato, com **parcelas pendentes de geração**. Não cria parcelas nem lançamentos a receber.
4. O usuário com perfil interno **Financeiro**, ou o administrador, abre **Gestão financeira → Contratos aguardando parcelas**. Se necessário, seleciona o período de destino da rematrícula no filtro da consulta.
5. Confere aluno, turma, contrato, valor e quantidade, clica **Gerar parcelas** e confirma. O sistema gera as parcelas e os lançamentos uma única vez.

A geração usa as condições gravadas no contrato, mesmo que o plano tenha mudado depois. Se houver troca de responsável financeiro antes da geração, os lançamentos pertencem ao responsável atual. A Secretaria e o Responsável financeiro do aluno não podem executar essa geração posterior. O perfil Financeiro recebe consulta e geração; as demais operações financeiras conservam suas permissões específicas.

## Período vigente no portal pessoal

Aluno e responsáveis não recebem o seletor **Período da consulta**. Vida acadêmica e financeiro pessoal mostram o período vigente, e a API não permite que usuários sem autorização institucional contornem isso enviando outro período ou `todos`. Se não houver período vigente configurado, a consulta é bloqueada com uma mensagem para procurar a escola.

Na rematrícula, o vínculo de origem pertence ao período vigente; a oferta mostra a turma e o plano do próximo período. Usuários institucionais autorizados continuam consultando outros períodos nas telas de gestão. Um funcionário com papel de responsável também não vê o seletor em suas telas pessoais.

Pessoas, alunos, cursos e turnos são cadastros gerais, necessários inclusive antes da matrícula. O período filtra os dados acadêmicos/financeiros que pertencem a um período, sem substituir a autorização por vínculo.

## Consultas e exclusões

A tela Matrículas inicia com período atual e situação Ativa. Filtros: período, curso, turno, turma, nome/RA, situação, tipo Regular/AEE, origem e intervalo de datas. Resultado: RA, aluno, turma, turno, curso, período, situação, tipo e data. Há paginação e acesso à ficha.

Somente administradores excluem cadastros, com motivo, confirmação, verificação de versão e auditoria. Cursos com turmas, planos vinculados, alunos com dependências, turmas com histórico e matrículas com contratos não podem ser excluídos. O período vigente não pode ser excluído. Matrículas acadêmicas antigas sem contrato ou movimentações posteriores podem ser excluídas, reabrindo o vínculo ao período; o vínculo pendente pode ser excluído separadamente. Históricos de responsáveis são preservados.

Excluir aluno mantém a pessoa e sincroniza seus perfis. Excluir uma pessoa sem dependências mantém sua conta WordPress, desvincula-a e remove os perfis ERP; outros perfis e conteúdos WordPress são preservados. Exclusões são definitivas e não possuem lixeira.

## Pessoas, contas e importação

Pessoas centralizam nome, nascimento, CPF, RG, contato, endereço brasileiro/estrangeiro, estado civil, profissão, religião, igreja, sexo e foto. Alunos recebem RA e tipo Regular/AEE. Parentesco e atribuições acadêmica/financeira são independentes e cumulativos.

As contas WordPress são criadas com login `nome.sobrenome`, tentando sobrenomes anteriores em colisões; se necessário, a tela solicita um login manual. A senha inicial continua sendo nascimento DDMMAAAA. Alterar nome/nascimento não altera login ou senha. Meu perfil permite editar somente os próprios dados e a foto; RA, vínculos e permissões ficam fora dessa edição.

Importação continua disponível ao administrador: CSV UTF-8 de pessoas primeiro, alunos depois. `codpessoa_origem` e RA são independentes e mantêm zeros à esquerda. Reimportação idêntica não duplica registros. Modelos estão em `modelos/` e na tela. Foto é avatar local do WordPress, sem alterar o serviço externo Gravatar.

## Acesso e páginas

Somente administradores acessam o wp-admin. Os demais usam o portal, com navegação mobile e permissões também na REST API. A página **Portal Journey**, com `[erp_app]`, é criada automaticamente ao abrir o painel como administrador. Perfis e acessos permite atribuir Secretaria, Financeiro ou perfis personalizados e configurar os menus. Aluno e responsáveis são definidos pelos vínculos.

| Página | Shortcode |
|---|---|
| Aplicativo completo | `[erp_app]` ou `[erp_portal]` |
| Pessoas | `[erp_pessoas]` |
| Alunos e ficha | `[erp_alunos]` |
| Estrutura acadêmica e planos | `[erp_academico]` |
| Matrículas | `[erp_matriculas]` |
| Gestão financeira / parcelas pendentes | `[erp_gestao_financeira]` |
| Ofertas de rematrícula | `[erp_rematriculas]` |
| Importação | `[erp_importacao]` |
| Meu perfil | `[erp_perfil]` |
| Perfis e acessos | `[erp_perfis]` |
| Configurações | `[erp_configuracoes]` |
| Vida acadêmica pessoal | `[erp_dados_academicos]` |
| Financeiro pessoal | `[erp_financeiro]` |
| Rematrícula do responsável | `[erp_rematricula]` |
| Navegação conforme permissões | `[erp_menu]` |

Um shortcode de página por página. A página principal tem template próprio; em outras páginas, os shortcodes convivem com o tema. O design utiliza a logo Journey fornecida, verde e amarelo, com tipografia do sistema. A interface é mobile first, mas ainda não registra service worker nem funciona offline. Exclua o portal e a API autenticada de caches externos.

## Validação e documentos

Testes locais de domínio, contas, importação, matrículas, permissões, gestão acadêmica, financeiro e formulários executados em PHP/SQLite e DOM simulado. Inclui 23 verificações específicas de estados civis e normalização. Sintaxe verificada para PHP 8.1 e JavaScript. Não houve homologação em WordPress/MySQL real nem teste visual em celular físico. Migração `dbDelta`, concorrência real, mídia, login e integração com plugins do site devem ser homologados antes da produção.

Consulte `docs/VALIDACAO.md`, `docs/API.md` e `docs/MIGRACAO-0.8.md`.

## Estados civis e padronização — 0.8

Em **Configurações → Estados civis**, o administrador cadastra um código numérico e um nome. Códigos iniciais: 1 SOLTEIRO; 2 CASADO; 3 VIÚVO; 4 DIVORCIADO; 5 SEPARADO. O código é imutável; o nome pode ser editado. Registros utilizados não podem ser excluídos.

No CSV de pessoas, mapeie a coluna `idestado_civil` e informe o código (ex.: `2`). O formulário apresenta `2 — CASADO`, armazena a referência e a ficha exibe o nome. A coluna antiga `estado_civil` também aceita nomes compatíveis ou códigos; se as duas forem enviadas, prevalece `idestado_civil`. Código desconhecido gera erro na linha. Confira o significado dos códigos de seu sistema anterior antes de importar.

Nomes, endereços, profissão, religião, igreja, RG, códigos alfanuméricos e demais textos cadastrais são normalizados em maiúsculas no servidor, tanto na importação quanto ao salvar formulários. E-mail é preservado; login, senha, URLs e chaves técnicas mantêm suas regras próprias. Rótulos das telas permanecem normais. Cadastros antigos serão padronizados ao serem editados e salvos; não há conversão indiscriminada de todo o histórico.

## Correção 0.8.2 — migração de contratos

Correção dos índices que anteriormente recebiam nomes posicionais (ix_0, ix_1…). A atualização preserva nomes de índices equivalentes e cria nomes estáveis para os novos. Não remove tabelas, dados ou FKs. Verifica os índices antes de concluir e inclui o erro do banco no diagnóstico de dbDelta.

Se aparecer “dbDelta falhou em contratos”: faça backup, substitua o plugin pelo ZIP 0.8.2 e abra ERP → Configurações → Verificar/aplicar migrações como administrador. Ao concluir, a versão do banco deve ser 7. Não desinstale nem apague tabelas. Se continuar falhando, envie a mensagem detalhada exibida.

## GitHub e atualizações

Repositório oficial: https://github.com/paulommaeda/erp-educacional. A versão 0.8.2 inclui o atualizador. Instale-a manualmente uma vez; versões posteriores publicadas em main serão consultadas pelo WordPress. Veja docs/GITHUB.md. O schema continua 7.

## Pessoas — 0.8.3

A página abre na listagem paginada, com filtros combinados por nome e código exato (interno ou de origem). Cadastrar novo abre um modal em quatro etapas; Editar reutiliza o modal com os dados preenchidos. Validação mantém o usuário na etapa com erro. Permissões de edição existentes preservadas; nenhuma migração adicional de banco.

## Ajustes 0.8.4

Seletores acadêmicos (período, curso, turno, turma e plano) sem busca duplicada. Mais resultados permanece disponível para listas paginadas. Edição de pessoas usa abas clicáveis, navegação por teclado e Salvar em qualquer aba; cadastro novo mantém etapas. Validação abre a aba que contém o campo inválido. Sem migração de banco.

## Planos por período e progressão — 0.9.0

Após atualizar, abra o wp-admin como administrador e confira ERP → Configurações → Verificar/aplicar migrações (schema 8).

1. Cadastre o próximo período e seu plano de pagamento, escolhendo o período no plano.
2. Cadastre as turmas do próximo período com os respectivos cursos, turnos e planos. O seletor de plano é filtrado pelo período da turma; a API também rejeita incompatibilidades.
3. Edite cada turma atual e defina Destino da rematrícula: próximo período, próximo curso e próxima turma. O curso efetivo é o curso da turma de destino.
4. Mantenha a oferta de rematrícula com janela, termo e turma de destino incluída. O portal mostra exclusivamente o destino configurado, sem seletor. Sem destino configurado ou oferta compatível, a renovação não fica disponível.

Planos antigos usados em apenas um período recebem esse período automaticamente. Planos sem uso ou utilizados em vários períodos ficam “Não definido — editar”. Crie planos separados para os períodos necessários e ajuste as turmas antes de matricular/rematricular. Contratos e parcelas existentes não são recalculados. O cadastro de turma permite deixar o destino vazio até o planejamento do próximo período.

A rematrícula continua criando contrato pendente de parcelas, geradas posteriormente pelo Financeiro. Alterar o destino afeta novas rematrículas, não rematrículas já concluídas. O backend bloqueia destino adulterado, turma de período anterior e uso de plano de outro período.

## Próximo período e abas de pessoa — 0.9.1

Em Estrutura acadêmica → Períodos letivos, cadastre primeiro o período futuro. Edite o período atual e selecione **Próximo período letivo** (por exemplo, 2026 → 2027). No cadastro da turma, o período de destino é apresentado automaticamente; escolha o próximo curso e a próxima turma desse período. O portal mantém o destino fixo para o responsável.

O campo é opcional durante a preparação, mas obrigatório para disponibilizar a rematrícula. A API rejeita autorreferências e destinos que não iniciem depois da origem. Não é possível remover ou trocar o próximo período enquanto houver próximas turmas incompatíveis: remova esses destinos, altere o período e configure as turmas novamente. As referências impedem a exclusão do período utilizado como próximo.

A atualização executa o schema 9 e mantém os dados. Se todas as próximas turmas já configuradas de um período apontarem para um único período futuro, esse vínculo será preenchido automaticamente. Quando houver destinos divergentes, configure o vínculo manualmente; as rematrículas ficam indisponíveis até corrigir a configuração. Matrículas, contratos e parcelas existentes não são recalculados.

As abas de edição de pessoa passam a ter larguras iguais, destaque somente no botão ativo, foco visível e alvos de toque maiores: duas colunas no celular e quatro no desktop. A navegação por clique/teclado e o botão de salvar a qualquer momento foram mantidos.

## Cadastros em modais — 0.9.2

A estrutura acadêmica mantém listagens e filtros na página. Os botões Cadastrar e Editar abrem um diálogo com o formulário. O padrão também é aplicado aos cadastros de aluno, vínculos de responsáveis, matrícula individual/em lote, contrato, transferência, ofertas de rematrícula, estados civis, configurações, perfil pessoal e gestão de perfis. A edição de pessoa mantém as abas existentes.

Os diálogos usam o elemento nativo dialog para restringir o foco ao formulário aberto, oferecem Fechar/Escape e devolvem o foco ao botão de origem quando ele continua presente. Durante um envio ou upload de perfil, o fechamento fica bloqueado. Uma falha do servidor mantém o diálogo e os valores para correção. O salvamento fecha o diálogo e executa a atualização da tela correspondente. Fechar sem salvar não envia alterações ao servidor; formulários reutilizáveis preservam o rascunho enquanto a página permanece aberta.

O componente compartilhado está em assets/modals.js e é carregado como dependência das interfaces do ERP. Não há migração de banco nesta versão (schema 9 mantido). Atualize pelo verificador do WordPress ou pelo ZIP.

## Importação e cópia de estrutura — 0.9.3

Disponível somente ao administrador WordPress.

**Importar turmas:** em Importação, selecione Turmas e baixe o modelo CSV UTF-8. As colunas são `codigo_periodo;codigo;nome;codigo_curso;codigo_turno;capacidade;codigo_plano`. Use os códigos dos cadastros, não seus IDs internos. Período, curso, turno e plano devem existir. O plano é opcional, mas a turma precisa de um plano para gerar contrato na matrícula. A importação valida o período do plano, mantém registros idênticos e relata conflitos sem sobrescrever turmas existentes. Cada linha é uma transação independente. Há mapeamento, prévia e relatório de resultados.

**Copiar para próximo período:** em Estrutura acadêmica, abra o botão correspondente. Escolha a origem e informe um novo período (código, descrição e datas) ou selecione um destino existente. Todos os planos e turmas ativos serão copiados, preservando valores, capacidades, cursos e turnos. Turmas que compartilham um plano continuam compartilhando um único novo plano no destino. Os códigos dos planos recebem o sufixo `-P<ID do destino>` para respeitar a unicidade global; códigos e nomes das turmas são preservados.

O novo período fica planejado e é vinculado como próximo período da origem. O período vigente das configurações não muda. A operação é atômica e idempotente: uma falha reverte todas as inserções, inclusive um novo período; reenvios da mesma solicitação não duplicam cadastros. Novas solicitações que encontrem códigos em conflito são bloqueadas. Limite: 1.000 planos e turmas por cópia.

Matrículas, contratos, parcelas, ofertas e vínculos de pessoas não são copiados. A próxima turma não é preenchida automaticamente: copiar a turma do 1º ano para outro período não significa que o aluno deva repetir o 1º ano. Configure a progressão depois da cópia, antes de publicar ofertas de rematrícula. Não há migração de banco nesta versão.

## Listagem de turmas — 0.9.4

Em Estrutura acadêmica → Turmas, selecione um curso para filtrar. O botão Todos os cursos remove esse filtro. A busca por nome e o período da consulta continuam combinados. Os resultados são ordenados pelo nome da turma (A–Z), com código e ID como desempate, antes da paginação. Não há migração de banco.

## Gestão de usuários e acesso assistido — 0.9.5 (histórico)

**A integração de acesso assistido descrita abaixo foi removida na versão 0.9.6.**

A nova área **Usuários** fica no portal e também pode ser usada pelo shortcode `[erp_usuarios]`. Possui busca por nome, login ou e-mail, paginação, perfis e pessoa vinculada. A edição em modal permite alterar e-mail e definir uma nova senha (mínimo 12 caracteres). Deixe a senha vazia para manter a atual. Não é possível consultar a senha atual. O login e os papéis não são alterados por esta tela.

**Configuração pelo administrador:**
1. Entre em Perfis e acessos e edite o perfil Secretaria. Marque o menu Usuários e as permissões Alterar e-mail e Redefinir senha. Se a política do perfil nunca foi personalizada, esses acessos já são padrão. Em instalações com a matriz salva anteriormente, habilite o novo menu explicitamente.
2. Para Coordenação, utilize o perfil existente ou crie um perfil personalizado e atribua-o às pessoas responsáveis. Marque Usuários e Acessar como usuário; não é necessário conceder edição de senha/e-mail.
3. No WordPress, configure **Login as a User PRO (Web357)**. O shortcode de front-end e a matriz de permissões por papel são recursos PRO segundo a documentação do fornecedor. Habilite o perfil de Coordenação em Edit Users Capability Assignment e autorize somente Pessoa/Aluno/Responsáveis na matriz de destinos. Mantenha a barra de retorno no front-end habilitada. A configuração do Web357 é feita pelo administrador; o ERP não altera a licença nem concede edit_users automaticamente.
4. Na tela Usuários, clique em Acessar como usuário e depois no botão oficial do Web357. O shortcode recebe o destino do portal e o retorno à tela Usuários. Para sair da sessão assistida, use a barra de retorno do plugin.

O adaptador utiliza `[login_as_user user_id="..." redirect_to="..." logout_redirect_url="..." button_name="..."]`, renderizado no servidor em uma página normal para permitir os recursos nativos do fornecedor. Plugin ausente, shortcode indisponível ou falta da permissão edit_users são mostrados na tela. As autorizações finais e a sessão de troca/retorno pertencem ao Web357. Esta integração não cria uma alternativa de autenticação própria.

A Secretaria pode consultar contas, mas só edita contas comuns do portal; contas administrativas, operacionais e com privilégios adicionais são protegidas. O acesso assistido pelo ERP também é restrito a contas comuns, inclusive para administradores. Ele é uma sessão real com as ações disponíveis ao usuário, não uma visualização somente leitura.

E-mail alterado é sincronizado com a Pessoa vinculada. `wp_update_user` realiza a atualização e aplica os mecanismos e notificações nativos WordPress. A auditoria guarda autor, alvo, mudança de e-mail e indicação de senha redefinida, nunca a senha. Um token de versão opaco bloqueia gravações sobre uma consulta desatualizada. Nenhum campo de senha é retornado na API. As novas permissões não permitem editar perfis nem atribuir privilégios. Não há migração de banco.

Referências do fornecedor (consultadas em 03/10/2026):
- https://docs.web357.com/login-as-a-user-wordpress-plugin/intro/
- https://docs.web357.com/login-as-a-user-wordpress-plugin/configuration/
- https://docs.web357.com/login-as-a-user-wordpress-plugin/guides/shortcode/

## Identidade do colégio — 0.9.6

Em **Configurações → Colégio e identidade visual → Personalizar colégio**, o administrador configura nome, razão social, CNPJ, telefone, e-mail, site e endereço completo. O formulário em modal permite selecionar/enviar o logo pela biblioteca de mídia e escolher as cores dos botões, destaques, cor secundária, texto, fundo e cartões/menus. Ao salvar, a página recarrega com a identidade atualizada.

O botão **Abrir personalizador do WordPress** abre a seção **ERP — Identidade do colégio**, com nome, logo e as mesmas seis cores. As duas interfaces compartilham a configuração. A identidade é usada no portal, login do portal e menus; o tema do site fora do ERP não é alterado. Sem configuração, a identidade Journey é preservada. Ao salvar sem logo, aparece o nome do colégio.

O botão Acessar como usuário e seu adaptador foram retirados do ERP. A edição de e-mail/senha permanece. O plugin Web357 instalado separadamente continua independente; suas permissões são administradas no próprio WordPress.

Esta etapa oferece uma identidade por instalação WordPress. Ainda não implementa isolamento de múltiplas escolas na mesma base, assinaturas ou provisionamento de um SaaS. Schema 9 mantido, sem migração de tabelas.

## Ofertas de rematrícula — 0.9.7

Na área Ofertas de rematrícula, a página principal exibe as ofertas cadastradas, turmas, cursos, período de destino, janela, situação e quantidade de rematrículas, com paginação e filtro por período de destino (inicialmente todos, incluindo os futuros).

**Nova oferta em lote** abre um modal. Selecione período de destino, curso atual e curso de destino; marque as turmas individualmente ou use Selecionar todas. Até 100 turmas do mesmo curso/período podem integrar uma oferta. As opções são carregadas também além da primeira página. A publicação é atômica, valida planos e turmas ativas e não duplica a oferta ao reenviar a mesma solicitação. Para outro par de cursos, publique outra oferta.

O administrador pode **Editar** ou **Excluir**, sempre em modal. A exclusão exige motivo e é bloqueada quando existem rematrículas vinculadas. Uma oferta utilizada permite somente alteração da janela e ativação/desativação; destinos e condições aceitas são preservados. A alteração do texto de um termo ainda não utilizado exige nova versão. Alterações concorrentes são detectadas e as ações são auditadas.

A seleção de turmas pertence à gestão. O responsável continua direcionado à próxima turma previamente configurada; a rematrícula continua criando contrato sem gerar parcelas. Schema 9 mantido.

## Login, códigos e ciclo de matrícula — 0.10.0

**Atualização:** esta versão usa o schema 10. Depois de instalar, entre no painel WordPress como administrador para executar a migração automática. Se houver diagnóstico de migração, use ERP Educacional → Configurações → Verificar banco. A migração preserva as chaves relacionais e os cadastros existentes.

**Acesso:** login, erro de senha, solicitação de recuperação e formulário de nova senha ficam no portal. A autenticação, a chave de recuperação e o salvamento da senha utilizam as APIs WordPress. O link do e-mail aponta ao ERP. Há nonce, validação de origem, mensagem genérica de recuperação e limite de tentativas. O formulário de nova senha exige 12 caracteres e confirmação. O envio do e-mail depende da configuração de correio da instalação. O administrador continua tendo seu painel WordPress.

**Usuários e mobile:** a listagem mostra nome, e-mail e Editar, alinhados no desktop e reorganizados no celular. Login, perfis e código não aparecem na linha. A paginação das matrículas tem uma linha de informação e dois botões alinhados no celular.

**Código da pessoa:** em Pessoas aparece apenas Código. Na importação, use `codigo_pessoa`; as colunas antigas `codpessoa` e `codpessoa_origem` continuam sendo reconhecidas no mapeamento. O código importado é mantido, inclusive zeros iniciais. RA continua separado do código da pessoa. As chaves internas não são renumeradas, preservando matrículas, usuários e referências anteriores.

Em Configurações → Numeração de pessoas, informe o último número utilizado. 600 gera 601 e o contador passa a 601 após o cadastro concluído. Falhas não consomem a sequência. A configuração não retrocede; códigos numéricos importados também elevam o contador quando maiores. Códigos externos alfanuméricos continuam aceitos. A sequência automática aceita até 12 dígitos. Na migração, códigos de origem têm prioridade; pessoas criadas manualmente mantêm o código interno antigo como código visível quando livre, ou recebem um novo código se houver conflito. O campo separado de código de origem foi retirado do cadastro/edição.

**Situações:** matrícula inicial e rematrícula começam em Reservado. A primeira parcela precisa ser integralmente quitada com pagamento para mudar para Cursando; pagamento parcial não confirma a matrícula. Estorno que reabre essa parcela retorna a Reservado. Situações encerradas não são reabertas por pagamentos. As vagas contam matrículas reservadas e cursando. Matrículas antigas ativas são migradas conforme a quitação da primeira parcela, com registro da alteração.

**Ficha do aluno → Matrículas:** o administrador dispõe de Trocar turma, Transferência externa, Resultado do período e Cancelar rematrícula, conforme a situação da matrícula. Todas as ações abrem modal. O histórico mostra situações, motivo, data/hora no fuso WordPress e autor, inclusive nas mudanças automáticas. A consulta do histórico exige acesso acadêmico.

**Transferência externa:** selecione um responsável vigente ou Outra pessoa e digite o nome, sem criar pessoa. Informe data da solicitação dentro do período letivo e colégio de destino. A declaração é opcional (PDF/JPG/PNG/WebP, 5 MB). O arquivo é criptografado e baixado mediante autorização do administrador no Histórico, sem URL pública legível. O servidor precisa de OpenSSL e Fileinfo. Backups devem preservar uploads e os salts WordPress usados na criptografia. A situação passa a Transferência externa; o financeiro existente permanece preservado.

**Resultado:** selecione Aprovado ou Reprovado. Se o reprovado já tiver uma rematrícula ativa vinculada à matrícula atual, ela será cancelada e substituída automaticamente pela turma de mesmo código, curso e turno no próximo período configurado. Essa turma deve existir (pode ser copiada do período atual), ter vagas e plano válido. Ausência/incompatibilidade bloqueia toda a operação, sem alteração parcial. A matrícula e a solicitação anteriores ficam no histórico; o contrato existente é reassociado à substituta, preservando valores, descontos, parcelas e pagamentos, sem novas cobranças. O destino regular de progressão da turma não é alterado para os demais alunos. Se corrigir posteriormente Reprovado para Aprovado, a correção da situação não reposiciona a matrícula substituta; ajuste sua turma pela ação Trocar turma.

**Cancelar rematrícula:** confirmação simples, sem excluir registros. Contratos ainda sem parcelas são cancelados e não podem gerar parcelas posteriormente. Cobranças e pagamentos já existentes não são apagados nem estornados automaticamente: devem ser tratados pelo financeiro. O banco permite uma nova matrícula vigente no mesmo curso/período mantendo as matrículas canceladas como histórico. Edição, transferência, resultados e cancelamento permanecem exclusivos do administrador.

## Versão 0.9.9 — financeiro

A migração 11 concilia índices existentes fora do dbDelta, sem recriar nomes legados. Se o ERP estava bloqueado por erro de banco, instale esta versão e execute **Verificar banco** como administrador. O log recebido contém erros de índices de 02/10 e avisos do Portal de 03/10. O menu foi corrigido para inicializar seu HTML e não usar variáveis da tela de login; a autenticação própria é chamada pela renderização do Portal. Valide o resultado com um log novo.

Na transferência externa e no cancelamento manual de matrícula/rematrícula, saldos não vencidos (vencimento hoje ou futuro, no fuso do WordPress) são cancelados na mesma transação. Valores pagos ficam preservados; débitos anteriores a hoje continuam exigíveis. O valor cancelado, data, motivo e auditoria são mantidos. Contratos ainda sem parcelas ficam cancelados. A substituição automática por reprovação transfere o contrato para a matrícula substituta e preserva suas cobranças.

Financeiro → selecione o aluno → filtre a situação. **Editar** abre modal individual; marque lançamentos e use **Editar selecionados** para alterar em lote (máximo 100). No lote, campos vazios são preservados, e o valor preenchido é aplicado a cada título. Vencimento e valor original atuais podem ser renegociados; líquido/saldo são recalculados com descontos, encargos e baixas existentes. Contrato e cronograma original permanecem como histórico. Títulos baixados/cancelados são bloqueados para edição. Permissão: `erp_ajustar_lancamentos`, além do menu Financeiro.

A situação pública dos títulos é `em_aberto`, `vencido`, `baixado` ou `cancelado`, calculada a cada consulta; o estado interno de liquidação continua em `status_liquidacao`. Baixas parciais permanecem em aberto ou vencidas pelo saldo restante. Não depende de cron. API: `POST /lancamentos/{id}/editar` e `POST /lancamentos/editar-lote`, com nonce, Idempotency-Key, versões e motivo. Exportação também apresenta essas situações.

Validação local: serviços transacionais em SQLite/PHP-WASM e modais com JSDOM. dbDelta, índices e bloqueios devem ser homologados em WordPress/MySQL antes de produção.

## Versão 0.10.0

Estrutura acadêmica → Turmas → **Rematrícula** abre modal para alterar somente o destino, mesmo em turma ocupada. Próximo período/turma continuam validados; matrículas e contratos atuais não são modificados.

APIs bidirecionais em `/wp-json/erp-educacional/v1/integracao`: consultas paginadas completas e operações de cadastro/edição de alunos, pessoas e vínculos, matrículas, geração/edição de cobranças e baixas/estornos. Autenticação WordPress por Senha de Aplicativo (HTTPS); acesso global restrito ao administrador nesta versão. Documentação e exemplos: [docs/integracao-api.md](docs/integracao-api.md); contrato OpenAPI: [docs/openapi.json](docs/openapi.json).

## Versão 0.10.1 — rematrícula entre cursos

Ofertas são elegíveis pelo vínculo turma atual → próxima turma ofertada, inclusive entre cursos diferentes. O curso de origem/referência da oferta permanece por compatibilidade, mas não restringe a elegibilidade: quem define o destino é a próxima turma cadastrada, cujo curso/período precisam corresponder à oferta. Isso permite 5º ano do Fundamental I → 6º ano do Fundamental II e 9º ano do Fundamental II → 1ª série do Médio, sem regras presas a nomes/códigos de curso.

Ofertas existentes passam a atender esses vínculos sem recriação. Continuam obrigatórios período vigente/próximo período, turma de destino ativa com plano, janela da oferta, autorização do responsável e ausência de matrícula ativa no curso/período de destino. O responsável não pode escolher outra turma. Aceite e exibição seguem o mesmo destino fixo; a rematrícula cria contrato e deixa parcelas para geração posterior pelo financeiro.

## Versão 0.10.2 — bolsas e descontos

Atualize e execute **Verificar banco** como administrador para a migração 12 (tabelas `contrato_descontos` e `desconto_lancamentos`, com FKs).

**Configurações → Desconto por pontualidade**: valor fixo em R$ por parcela, aplicado a todos os contratos nas consultas e novas quitações, incluindo contratos existentes. Zero desativa; baixas anteriores não são recalculadas. O benefício é limitado ao principal restante e concedido ao quitar até o vencimento, considerando a data do pagamento. Em pagamentos parciais, permanece previsto e só é efetivado na quitação. Pagamento acima do saldo com pontualidade é rejeitado para evitar sobrepagamento. Estorno integral restaura saldo e desconto concedido.

**Ficha do aluno → Financeiro → Bolsas e descontos dos contratos**: escolha o contrato e adicione descontos com nome, valor em R$ por parcela, parcela inicial e final. Vários descontos podem se acumular e somam à bolsa base já registrada na matrícula. Valores que geram líquido negativo são rejeitados. Cadastro/exclusão sempre em modal. A concessão modifica parcelas sem pagamentos na faixa, inclusive vencidas; parcelas pagas ou com baixa parcial são preservadas. Contratos ainda sem parcelas recebem os descontos na geração posterior.

Excluir desativa o desconto, mantém histórico e retira somente sua aplicação em parcelas com vencimento hoje ou futuro que ainda não tiveram pagamentos. Parcelas vencidas, canceladas ou com baixas são preservadas. Outros descontos continuam válidos. Uma concessão posterior não desfaz a preservação histórica de descontos excluídos.

Nas consultas: valor original, desconto condicional (previsto enquanto elegível ou efetivamente aplicado após quitação), desconto incondicional, valor líquido e valor pago. O líquido público é `original - incondicional - condicional`, com encargos separados. `valor_liquido_contratual` preserva o líquido antes da pontualidade; `saldo_aberto` preserva a cobrança contábil antes da condição, e `saldo_a_pagar` mostra o valor exigível com o benefício atual. Envie `saldo_a_pagar` ao quitar em dia. Após o vencimento, o benefício previsto desaparece; pagamentos pontuais registrados depois usam sua data comprovada.

Contratos/parcelas originais ficam como snapshot; renegociações aparecem nos lançamentos e na auditoria. APIs e exportação incluem a lista de descontos do contrato e os novos campos calculados. GET/POST `/integracao/contratos/{id}/descontos`; POST `/integracao/descontos/{id}/excluir` com `versao`. Cadastro requer `nome`, `valor`, `parcela_inicio`, `parcela_fim`; autenticação e Idempotency-Key seguem a documentação de integração.

Testes locais cobrem acumulação/faixas, exclusão prospectiva, preservação de pagamentos, excesso de descontos, pontualidade, estorno e contratos pendentes. Homologar migração em WordPress/MySQL.

## Versão 0.10.3

Valor líquido é sempre original menos descontos incondicionais. Pontualidade permanece separada como benefício previsto/aplicado e altera apenas o valor de quitação (`saldo_a_pagar`); `valor_com_pontualidade` mostra a simulação. Mesmo após pagamento, o líquido não é reduzido pelo desconto condicional.

Modal de bolsas possui escopo CSS próprio, layout responsivo, controles estilizados e título dimensionado. Contratos novos são numerados `RA-CODIGO_DO_PERIODO`, preservando zeros do RA. Havendo mais de um contrato para o mesmo RA/período, um sufixo numérico preserva unicidade. Migração 13 renomeia contratos antigos com padrão CT-, sem alterar parcelas/pagamentos. Execute Verificar banco como administrador.

## Versão 0.10.4

Modal de destino da rematrícula com escopo ERP próprio, formulário em coluna única, controles estilizados, título dimensionado e botões sem esticar na altura do formulário. Layout responsivo para celular e desktop. Sem alteração de banco.

### 0.10.5
Rematrícula no portal em três etapas: apresentação, destino fixo e aceite. Texto da apresentação em Configurações, junto ao período vigente. Qualquer saldo positivo não cancelado do aluno (inclusive parcelas futuras e pagamentos parciais, em qualquer período) bloqueia o início e a confirmação pela API. Após regularização, atualizar o portal. Mensagem de bloqueio em estilo danger. Não há migração de banco.

### 0.10.6
Configurações permite personalizar a mensagem de bloqueio da rematrícula por parcelas em aberto. A mesma mensagem é usada no portal e na validação da API. Alteração exclusiva do administrador, com auditoria e preservação do texto padrão nas instalações existentes.
