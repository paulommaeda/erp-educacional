# ERP Educacional — 0.9.4

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
