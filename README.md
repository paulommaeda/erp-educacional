# ERP Educacional — 0.8.3

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
