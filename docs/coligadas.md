# Coligadas — ERP Educacional 0.12.0

## Atualização
1. Fazer backup do banco antes de instalar o ZIP. Esta versão modifica índices e acrescenta FKs de propriedade.
2. Entrar no painel WordPress como administrador para aplicar a migração 14. Se a migração estiver bloqueada, usar Verificar banco no ERP do WP Admin.
3. Em Configurações, editar a COLIGADA PRINCIPAL, informar nome/razão social/CNPJ. Os registros anteriores pertencem a ela; pessoas e contas não são alteradas.
4. Cadastrar as outras coligadas e selecionar a coligada de trabalho no seletor superior. A escolha é individual por usuário da equipe.
5. Configurar período vigente, identidade e demais parâmetros em cada coligada. Pessoas e usuários permanecem compartilhados.

## Estrutura e segurança relacional
Cada raiz acadêmica recebe CODCOLIGADA. Dependentes herdam a propriedade do aluno, da matrícula ou do contrato. FKs compostas impedem misturar empresas em uma turma, matrícula, contrato, parcela ou pagamento. A propriedade não pode ser alterada em uma edição. Referências explícitas de progressão (próxima turma, matrícula de origem e vínculo da rematrícula) podem atravessar coligadas; isso não transfere os registros históricos.

Pessoas, contas WP, endereços e referências pessoais (estado civil e numeração) são globais. Uma pessoa pode ter duas fichas de aluno, uma por coligada. RA é único por coligada. Os perfis de Secretaria/Financeiro existentes são da equipe do ERP e podem selecionar as coligadas: esta funcionalidade não é isolamento de tenants nem atribuição de funcionários a CNPJs específicos.

## Fundamental 1 → Fundamental 2 / Fundamental 2 → Médio
1. Na origem, configurar o próximo período letivo (ex.: 2027).
2. No destino, criar outro período 2027 com exatamente o mesmo código e datas. Curso, turno, plano e turma precisam pertencer ao destino.
3. Na turma de origem, abrir Rematrícula, escolher Coligada de destino, curso e próxima turma.
4. Selecionar a coligada de destino no cabeçalho e publicar oferta para suas turmas.
5. O responsável inicia pelo aluno da origem e recebe a turma/curso e razão social/CNPJ de destino antes do aceite.
6. A confirmação cria/reutiliza a ficha de aluno no destino, copia vínculos vigentes quando ainda não existirem, registra origem/destino e cria matrícula/contrato no destino. Parcelas são geradas posteriormente pelo perfil Financeiro.

A pessoa, o usuário e o RA são preservados. Se o RA já estiver ligado a outra pessoa no destino, a operação inteira é revertida. Vínculos financeiros divergentes em ficha já existente exigem secretaria; não são sobrescritos. Histórico, débitos e recebimentos anteriores ficam com sua coligada. Reprovação após transição recria matrícula/contrato na coligada original e cancela apenas cobranças futuras no destino, preservando vencidos/baixados.

## APIs
Autenticação e permissões existentes permanecem. Exemplos relativos a `/wp-json/erp-educacional/v1`:

- `GET /coligadas`: lista e seleção atual (equipe).
- `POST /coligadas`: criar `{nome,razao_social,cnpj}`; editar com `{codcoligada,versao,...}` (administrador, Idempotency-Key).
- `POST /coligadas/selecionar`: `{codcoligada}` (equipe).
- `GET /coligadas/destino-periodo?codperiodo=ORIGEM&codcoligada=DESTINO`: período correspondente à progressão configurada.
- `GET /integracao/alunos?codcoligada=2`, `/integracao/matriculas?codcoligada=2`, `/integracao/financeiro?codcoligada=2`: coleções da empresa.
- Operações de cadastro/integração aceitam a coligada selecionada por contexto; usar a query `codcoligada` na integração. Dependentes obrigatoriamente herdam sua coligada dos pais. Não reutilizar chaves de idempotência em coligadas diferentes.

Os IDs internos continuam globais; códigos de negócio (curso, turno, período, RA e contrato) são exclusivos na coligada. Códigos de plano são exclusivos no período da coligada. Importação de pessoas é global; importação de alunos/turmas utiliza a coligada de trabalho. Cópia de período permanece dentro da mesma coligada.

## Validação
Testes automatizados com PHP WASM/SQLite e stubs WordPress: herança e rejeição de propriedade, FKs compostas, transição entre CNPJs, mesmas pessoas/contas, contrato diferido, consulta por coligada, período independente, reprovação entre CNPJs e seletor de destino. Não houve execução em MySQL/WordPress real; validar a migração e os fluxos no ambiente de homologação.
