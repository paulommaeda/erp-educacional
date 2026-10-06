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

### 0.10.7
Correção: rematrícula bloqueada somente por saldo positivo em lançamento não cancelado com vencimento anterior à data atual do WordPress. Parcelas futuras e que vencem hoje não bloqueiam. Regra aplicada ao portal e à confirmação pela API. Texto padrão atualizado para parcelas vencidas; textos personalizados preservados. Sem migração de banco.

### 0.10.8
Resumo financeiro sem nome do plano e vencimento em DD/MM/AAAA. Parcelamento exclusivamente definido pela oferta, inclusive na API. Manual PDF selecionável/enviável pela mídia em Configurações: quando configurado, surge antes do termo, com visualizador do navegador, tela cheia, download e aceite obrigatório. Versão SHA-256 validada pela API e registrada em auditoria com responsável/data. Sem manual configurado, mantém o fluxo anterior. Não usa serviço externo Issuu; navegação e zoom dependem do leitor PDF do navegador, com download para dispositivos sem suporte. Modais com controles e tipografia uniformizados.

### 0.10.9
Rematrícula no mobile até 700px em um único nível: removidos bordas, sombras, fundos e paddings dos cartões aninhados, mantendo apenas o espaçamento externo da página. Termo sem caixa interna, confirmação em largura total. Desktop e aviso danger preservados.

### 0.11.0
Correção do login: política Referrer-Policy same-origin evita origem opaca/null em POST local. Validação normaliza esquema, host e porta e aceita somente origens dos endereços home/site configurados no WordPress. Nonce e limite de tentativas preservados. Após atualizar, limpar cache e recarregar completamente a página de login.

### 0.12.0 — Coligadas
Coligadas/CNPJs em Configurações, com edição em modal. Pessoas, endereços pessoais, usuários e cadastros de apoio da pessoa permanecem globais. Alunos, vínculos acadêmicos/familiares da ficha, períodos, cursos, turnos, planos, turmas, matrículas e todo financeiro têm CODCOLIGADA. FKs compostas bloqueiam referências incompatíveis. RA e pessoa são únicos dentro de cada coligada. Seleção de trabalho por usuário da equipe; sem segregação de permissões por CNPJ nesta versão (os perfis existentes continuam autorizados a gerir as coligadas).

Migração 14 cria coligada 1 e atribui a ela os dados atuais. Configurações antigas permanecem na coligada 1. Após atualizar, entrar no WP Admin para migração automática, ou executar Verificar banco. A DDL MySQL não é transacional: fazer backup antes da atualização. Preencher o CNPJ da coligada inicial em Configurações.

Rematrícula entre coligadas: configurar o próximo período na origem, cadastrar período equivalente no destino (mesmo código e datas), escolher a coligada de destino na turma de origem e selecionar a próxima turma. Criar a oferta na coligada de destino. O portal mostra a razão social/CNPJ. A confirmação cria ou reutiliza a ficha local da mesma pessoa, preserva RA (conflito com outra pessoa bloqueia), replica vínculos vigentes se a ficha de destino não tiver vínculos e exige conferência administrativa se responsáveis financeiros existentes divergirem. Histórico/contratos/débitos de origem ficam na origem; novo contrato aguarda o Financeiro do destino. Pessoas e usuários nunca são duplicados. Parcelas vencidas continuam bloqueando. Na reprovação após troca de CNPJ, cancela cobranças futuras do destino e gera contrato pendente próprio na origem; não transfere recebimentos entre empresas.

Período vigente, identidade do colégio, pontualidade, apresentação, mensagem de bloqueio e manual são configurados por coligada. Numeração de pessoas é global. APIs de integração aceitam `codcoligada` na query e devolvem esse campo nos registros. Coleções usam a coligada selecionada por padrão. Detalhes por ID continuam disponíveis ao administrador para todas as coligadas. Documentação: [coligadas](docs/coligadas.md).

## Exportação seletiva — 0.12.1
No Portal, entre em **Exportação** (administrador) ou use `[erp_exportacao]`. Disponível também no WP Admin, ERP → Exportação, e nas Configurações. Selecione coligadas, categorias ou tabelas individuais; baixe um JSON com registros, códigos, referências, contagens e intervalo da coleta. Inclui alunos, pessoas, responsáveis, estrutura, matrículas, financeiro, auditoria e configurações de identidade visual/cores. Pessoas e contas vinculadas são globais. Contas exportadas não incluem senhas, hashes, chaves ou tokens; operações de idempotência não são exportadas.

Logo, foto, manual e documentos permanecem referências/IDs; os arquivos binários devem ser copiados separadamente. A exportação não é um backup nem uma restauração automática: relações com tabelas desmarcadas podem apontar para registros ausentes. Os lotes usam cursor pela chave primária (incluindo chaves compostas), 200 registros por requisição. Não existe snapshot entre requisições; evite mudanças enquanto exporta. Exportações grandes consomem memória do navegador. Não cria arquivo público no servidor.

REST autenticado e restrito a admin: `GET /exportacao/catalogo`, `POST /exportacao/dados` (`tabela`, `coligadas`, `cursor`), `POST /exportacao/configuracoes` (`coligadas`, `categorias`).

### 0.12.2 — Histórico escolar anterior
Na ficha do aluno, a aba **Histórico escolar anterior** permite consultar escolas anteriores. Administradores podem cadastrar/editar em modal uma escola com nome, CNPJ opcional, endereço completo e observações, vários anos letivos (período, ano, série, curso e resultado opcional), disciplinas, nota final ou conceito e tipo de disciplina. Os tipos ficam em Configurações e pertencem à coligada selecionada, com edição, ativação/inativação e exclusão bloqueada quando utilizados.

Migração 15 cria quatro tabelas próprias: tipos_disciplina, historicos_anteriores, historico_anos e historico_disciplinas, com FKs de propriedade e índices. O histórico pertence à ficha local do aluno e à sua coligada, sem criar matrícula ou financeiro. Após atualizar, entrar no WP Admin para aplicar a migração; fazer backup antes. Não homologado em MySQL/WordPress real.

Salvamento transacional com idempotência, versão do histórico para evitar sobreposição, IDs de filhos preservados e auditoria do conjunto antes/depois. Remover anos/disciplinas no modal e salvar exclui os registros removidos; excluir escola exclui seu histórico inteiro após confirmação. Histórico e tipos estão na exportação seletiva; as APIs de alunos incluem historicos_anteriores. Secretaria consulta na ficha, alterações somente por admin. Não gera ainda documento oficial do histórico escolar.

### 0.12.3 — Portal familiar, empresa financeira e tema
Pessoas e contas continuam globais. O portal familiar mostra uma entrada por pessoa/aluno, com todas as fichas autorizadas em coligadas diferentes; a troca é somente entre alunos, sem seletor ou rótulo de coligada. Acadêmico, financeiro e ofertas consultam o período vigente em cada coligada, independentemente de parâmetros enviados pelo navegador. Cada ficha é autorizada por escopo: vínculo financeiro não libera acadêmico e vice-versa. Equipe mantém seleção de trabalho. Não altera os vínculos de responsáveis nem transfere financeiro entre empresas.

Contratos e lançamentos das APIs de integração e exportação completa/seletiva recebem `coligada` (codcoligada, nome, razao_social, cnpj), `coligada_nome` e `coligada_cnpj`. Proprietário vem do registro, nunca da seleção do usuário.

Tema básico `ERP Educacional Portal` distribuído em theme/erp-educacional-portal, copiado para a pasta de temas do WordPress e ativado na ativação do plugin. Página do portal criada/reutilizada e definida como página inicial estática. Atualizações de uma instalação existente executam o primeiro preparo automaticamente ao entrar no WP Admin, após o banco estar pronto. Reativar o plugin reaplica tema/página inicial. O preparo concluído não é imposto em todos os acessos: alterações posteriores de tema feitas pelo admin permanecem. Configuração anterior fica guardada em ederp_portal_previous_site; não há restauração automática ao desativar.

A instalação requer escrita na pasta de temas. Falha fica explícita em aviso administrativo e será tentada novamente no próximo acesso. Não substitui diretório de tema não marcado como pertencente ao ERP. Fonte Inter carregada pelo Google Fonts com display=swap e alternativas locais; fonte não é embutida no ZIP. Tema evita dependência PHP fatal se o plugin for desativado. Sem nova migração de banco além da versão 15 já existente. Testado com SQLite/WP stubs e JSDOM; ativação real de tema, autenticação e MySQL precisam de homologação.

### 0.12.4 — Visão dos responsáveis
Tabela acadêmica no portal familiar apresenta Situação, Data da matrícula (dd/mm/aaaa), Contratos, Período (nome/código legível), Curso (nome), Turma e Turno. Oculta IDs internos de matrícula, período, curso e turma. APIs e telas de gestão mantêm os campos originais. Cartão inicial de rematrícula identifica o período de destino e exibe Disponível de dd/mm/aaaa até dd/mm/aaaa; a turma continua na etapa própria. Oferta inclui periodo_destino (codperiodo, codigo, descricao). Sem migração de banco.

### 0.12.5 — Gestão financeira mobile
Consultas separadas em Lançamentos e Contratos aguardando parcelas. Filtros em seção própria, resumo dos valores filtrados e operações em botões que abrem modais, sem formulários escondidos dentro de acordeões. Celular até 700px usa cartões com valores essenciais; Detalhes mostra descontos e valor líquido. Datas dd/mm/aaaa e moeda brasileira. Seleção individual/em lote, Selecionar todos disponível no mobile, limite de 100 preservado. Registrar baixa no cartão abre a operação com lançamento preenchido. Operações atualizam consultas ao concluir, preservando endpoints, permissões e regras financeiras.

Validado em Chromium com tela de 390px, sem transbordamento horizontal; detalhes e baixa em modal, envio da baixa, testes de edição individual/lote e sintaxe. Sem migração de banco.


## Chat por canais — 0.12.9

Após atualizar, entre no WP Admin com o administrador para aplicar a migração 16 e instalar os novos perfis. No Portal, abra Conversas → Gerenciar canais → Novo canal. Escolha nome, coligada e usuários de Secretaria, Coordenação ou Orientação. Perfis personalizados com estes nomes também podem ser incluídos. Atribua os perfis na tela Perfis e acessos; o perfil Supervisão vê e responde em todos os canais, mas não administra seus cadastros. O administrador do WordPress vê tudo e configura os canais.

Menu Conversas e shortcode `[erp_chat]`. Responsáveis com vínculo vigente acadêmico, financeiro ou de rematrícula só iniciam conversa com canais das coligadas dos filhos. Não há conversa direta com usuários. Cada conversa pertence ao canal e à pessoa responsável. A equipe do canal inicia contato em Nova conversa, escolhendo canal e responsável com conta vinculada. Histórico permanece ao desativar o canal; novos envios ficam bloqueados. Remover um atendente retira seu acesso ao canal. Supervisão não concede administração WordPress nem acesso automático às demais áreas do ERP.

API autenticada `/wp-json/erp-educacional/v1/chat`: canais GET/POST; atendentes GET (admin); canais/{id}/responsaveis GET (equipe); conversas GET/POST; conversas/{id} GET; conversas/{id}/mensagens GET/POST; conversas/{id}/leitura POST; anexos/{id} GET. Escritas de canais, início e mensagens exigem Idempotency-Key e possuem transações. A leitura atualiza somente o cursor da própria conta. Paginação de conversas por before, mensagens por before/after. Respostas privadas/no-store. Anexos enviados no JSON com nome/conteudo base64, validados por Fileinfo e tipo real. Limite: 3 arquivos e 5 MB no total; PDF, JPG, PNG, WEBP e TXT. Conteúdo salvo em tabela privada, sem URLs públicas do WordPress. Download autorizado por conversa. Requer PHP Fileinfo; limite post_max_size e pacote MySQL precisam acomodar uma requisição de aproximadamente 7 MB.

Atualização das mensagens a cada 7 segundos com o portal aberto; sem integração com WhatsApp, push fora do portal, presença, chamadas ou áudios gravados nesta versão. Nenhum envio offline é confirmado; o texto e anexos ficam disponíveis para tentar novamente. Horários armazenados em UTC, exibidos no fuso do dispositivo. As tabelas chat_canais/chat_membros/chat_conversas/chat_mensagens/chat_anexos/chat_leituras usam FKs e propriedade de coligada. Contas/pessoas compartilhadas permanecem sem coligada.

Validação: SQLite com stubs WordPress para autorização entre famílias, canais e coligadas, supervisão, histórico, idempotência e anexos privados; JSDOM para fluxo; Chromium 390px/desktop para layout. Migração dbDelta/MySQL, autenticação e limites do servidor devem ser homologados na instalação real.

Exportação seletiva inclui categoria Chat com canais, membros, conversas, mensagens, anexos privados em base64 e leituras. A exportação de anexos pode produzir arquivos grandes e é exclusiva do administrador.


### Campos adicionais (0.12.13)
Em Configurações → Campos adicionais, cadastrar nome, chave imutável, tipo, opções, ordem e visibilidade. Os campos são globais, pois Pessoas são compartilhadas entre coligadas. Não exibidos no cadastro permanecem na aba Campos adicionais da ficha do aluno. Apenas administradores configuram campos e editam valores na ficha. O perfil pessoal não permite editar os campos adicionais nesta versão. Inativação preserva os valores armazenados.

As rotas existentes POST /integracao/pessoas e PATCH /integracao/pessoas/{id} aceitam `campos_adicionais` como objeto de chave/valor, por exemplo `{"campos_adicionais":{"nacionalidade":"BRASILEIRA"}}`. Datas usam AAAA-MM-DD, números ponto decimal e listas valores exatos cadastrados. Omitir uma chave preserva seu valor; string vazia limpa o valor. Os payloads de pessoas/alunos incluem esse objeto. A conexão e conferência dos requerimentos do Fluent Forms será uma etapa posterior.

Validado com PHP-WASM/SQLite e JSDOM; migração dbDelta/foreign keys não executada em um MySQL de produção.


### Grupos de campos (0.12.14)
Configurações → Grupos de campos permite criar/editar nome, ordem e situação. Cada campo adicional deve pertencer a um grupo. Na ficha do aluno, cada grupo ativo com campos ativos aparece como aba usando seu nome. Edição dos valores continua exclusiva do administrador.

Em Perfis e acessos, marque os grupos autorizados na seção Grupos na ficha do aluno. Autorizações de múltiplos perfis se somam. O acesso ao grupo não libera sozinho a tela geral de alunos; a área Alunos precisa estar liberada também. Novos grupos ficam exclusivos do administrador até concessão explícita. Dados retornados em pessoas/alunos e definições de campos respeitam as permissões, evitando acesso por chamada direta de API. Grupos inativos não exibem dados e preservam seus valores. Não são novos itens no menu principal: são botões dentro da ficha.

Migração 18 cria o grupo inicial Dados complementares para os campos existentes, sem alterar suas chaves ou valores. Definições e grupos são compartilhados entre coligadas. Exportações incluem grupos e permissões. As rotas /grupos-campos (GET/POST) consultam/configuram grupos; /campos-adicionais passa a receber idgrupo.

Validação: PHP-WASM/SQLite para migração lógica e autorização; JSDOM para abas por grupo e modais de perfis. dbDelta em MySQL e aparência no WordPress precisam de validação no ambiente da escola.


### Exclusão e labels (0.12.15)
Os botões Excluir em Configurações removem campos e grupos, exclusivamente para administradores. A exclusão de campo exige confirmação em modal e remove seus valores nas pessoas na mesma transação, com auditoria e idempotência. Grupos com campos vinculados não podem ser excluídos: transfira ou exclua seus campos primeiro. Nomes de campos e grupos preservam a capitalização digitada; valores pessoais continuam seguindo sua normalização. Não há conversão automática de nomes antigos, que podem ser corrigidos via Editar.

A ficha mostra Dados do aluno → Pais e responsáveis → Matrículas e turmas → Financeiro → Histórico escolar anterior → grupos adicionais (por ordem configurada), respeitando permissões e mantendo os nomes próprios dos grupos.


### Ajustes de configurações (0.12.16)
Corrigida a definição do identificador antes do registro das rotas de exclusão. Teste de registro verifica correspondência de URLs numéricas e permissões. Tipos de disciplina recebem linhas com espaçamento, nome à esquerda e ações alinhadas; no mobile as ações ficam abaixo do nome. O desconto por pontualidade mostra o valor cadastrado por parcela fora do modal e atualiza após salvar. Removido o link do personalizador WordPress da tela do colégio; personalização interna permanece.


### Pessoas por API (0.13.0)
Em Configurações → Integração de pessoas por API, o administrador informa URL HTTPS pública, usuário e senha HTTP Basic, caminho da lista (data por padrão; vazio para raiz) e parâmetro de página (page). O Testar conexão retorna campos descobertos e até três registros de exemplo. Depois Mapear campos associa caminhos JSON com ponto a campos nativos ou adicionais, por bloco Aluno/Pai/Mãe/Outro/Responsável financeiro. Cada destino aparece uma vez por bloco. Pode configurar valor padrão e tradução JSON, por exemplo {"C":"2","S":"1"} para IDs de estados civis. Nascimento DD/MM/AAAA e sexo M/F são convertidos. País e estado requerem códigos ISO/UF; demais códigos podem ser traduzidos. Número e complemento combinados precisam de conferência, sem divisão automática.

É obrigatório escolher um ID externo estável do requerimento antes de buscar. O JSON Fluent Forms analisado anteriormente não incluía entry_id: ajustar endpoint na origem. A identidade combina host, caminho do endpoint e esse ID; mudanças em per_page não duplicam registros. Mudanças de ID, host ou caminho definem outra fonte. Buscar para conferência lê uma página por acionamento (sem polling ou laço automático). Recebidos ficam congelados; nova busca não sobrescreve requerimentos existentes. A configuração aceita uma conexão nesta versão.

Tela Requerimentos / shortcode [erp_requerimentos]: selecione criar pessoa, aproveitar pessoa existente por sugestão de CPF ou nome+nascimento, repetir pessoa já selecionada em outro bloco, ou ignorar. Confira e corrija os dados antes da confirmação. Sugestões não mesclam nem sobrescrevem pessoas. Confirmar cria somente pessoas e contas WordPress; atribuição de Aluno/RA, vínculos familiares e matrícula continuam na ficha do aluno, em etapa posterior. O cadastro usa a numeração normal do ERP; o ID do requerimento não vira código da pessoa. Repetições de confirmação são idempotentes e erros revertem toda a confirmação. Homônimos podem exigir preencher o nome de usuário manualmente no modal.

O administrador configura/testa/busca; perfis com Requerimentos liberado e capability de gestão de pessoas conferem e confirmam. Libere o menu em Perfis e acessos para a Secretaria já configurada. Campos adicionais mapeados exigem acesso ao grupo ativo: não é permitido confirmar campos que o perfil não pode conferir. Pais/mães/financeiro não ganham automaticamente atribuições sem aluno.

A senha é criptografada AES-GCM com chave derivada do salt WordPress e jamais devolvida nas respostas; deixar o campo vazio mantém a senha. Alteração do salt exige recadastrar senha. HTTP seguro do WordPress bloqueia URLs não públicas, usa HTTPS e valida TLS, não segue redirecionamentos (evita encaminhar credenciais) e limita resposta a 2 MB / 20s. Bloqueios de Cloudflare/autenticação aparecem como HTTP de origem. Anexos, fotos, arrays de contatos e dados não mapeados não são importados automaticamente.

REST: /api-pessoas/configuracao GET/POST, /testar POST, /buscar POST (pagina), /requerimentos GET, /requerimentos/{id} GET, /requerimentos/{id}/confirmar POST. Escritas de configuração, busca e confirmação usam Idempotency-Key; permissões e nonce seguem o ERP. A fila é global porque os cadastros de pessoas são compartilhados entre coligadas. Exportação de Pessoas inclui a fila; credenciais não são exportadas.

Validado com resposta HTTP simulada, PHP-WASM/SQLite e JSDOM: criptografia/autenticação, descoberta, conversões, fila sem criar pessoas, duplicidade, confirmação idempotente e rollback, além de registro de rotas existente. Não foi validada conexão real com o CRM nem migração dbDelta em MySQL.


### CPF e configurações sob demanda (0.13.2)
Esta versão substitui o ID externo pelo CPF da pessoa principal para identificar requerimentos. Mapear CPF no bloco Aluno (ou na pessoa principal quando não houver bloco Aluno) é obrigatório para buscar; documento normalizado em 11 dígitos. campo_id é mantido apenas para compatibilidade e deixa de ser necessário. A tabela da fila ganha cpf_origem com unicidade. Migração associa CPF aos registros anteriores quando disponível; registros antigos sem CPF continuam conferíveis. Não converte CODPESSOA em CPF: a PK interna permanece intacta.

Conferência sugere somente cadastros pelo CPF, evitando consultas por nascimento ausente. Na criação, um CPF já cadastrado reaproveita a pessoa sem atualizar dados. Novas pessoas continuam submetidas à validação existente de CPF e demais campos.

Configurações agora possuem dez abas: período/rematrícula, colégio/cores, coligadas, pontualidade, numeração, estados civis, tipos de disciplina, grupos, campos e API. Somente a aba inicial busca dados no carregamento; as demais carregam ao abrir. Conteúdos são mantidos enquanto a página está aberta e atualizados após salvar. Testes comprovam uma consulta inicial e ausência de novas chamadas ao revisitar abas já carregadas.
