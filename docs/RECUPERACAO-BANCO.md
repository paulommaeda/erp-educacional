# Recuperar o ERP após restaurar um backup

O backup pode conter uma versão anterior do banco. O número **36** exibido em Banco de dados é a versão da estrutura; o plugin atual usa a estrutura **43**. Esse número é diferente da versão do plugin, **0.19.20**.

1. Em **Plugins → ERP Educacional → Verificar atualizações**, consulte e instale a versão **0.19.20** ou posterior.
2. Abra o painel WordPress como administrador. A nova versão faz uma tentativa de migração, mesmo se houver um erro de versão anterior salvo.
3. Entre em **ERP Educacional → Configurações**. Se a atualização estiver pendente, a tela apresenta as versões do banco, o diagnóstico da última tentativa e **Verificar/aplicar migrações**. O mesmo recurso está disponível em Configurações no portal.
4. Clique em **Verificar/aplicar migrações**. Ao concluir, o banco passa a **43** e as configurações voltam a carregar.
5. Se a tentativa falhar, confira **Diagnóstico da última tentativa** e **Referências pendentes**. A lista reúne as tabelas e os vínculos que ainda precisam de conferência, a quantidade de registros e o primeiro código encontrado. Um conflito nos dados ou uma restrição de acesso do servidor precisa ser tratado conforme esse diagnóstico.

A correção cria os índices substitutos antes de remover índices antigos. As chaves estrangeiras continuam ativas; os registros de alunos, matrículas, contratos, parcelas e pagamentos são preservados. Repetir a verificação permite continuar uma migração interrompida. Não altere manualmente o número da versão do banco para ocultar o aviso.

Se o diagnóstico anterior citava o vínculo entre **chat_anexos** e **chat_mensagens**, a versão 0.19.17 preserva os anexos sem mensagem válida na mesma coligada para conferência. O conteúdo, nome, tamanho, data original, coligada e identificador da mensagem original permanecem no banco. As mensagens e os anexos válidos continuam nas suas conversas.

Após a migração, o administrador encontra esses arquivos em **ERP Educacional → Anexos preservados**. Configurações também apresenta um aviso com acesso à conferência. Os downloads são privados e exigem autorização de administrador. Arquivos pendentes não ficam disponíveis pelo chat comum nem são atribuídos automaticamente a outra conversa ou coligada.

A recuperação dos anexos fica registrada na auditoria. Se uma gravação do lote falhar, suas alterações são revertidas; repetir a migração retoma a recuperação sem duplicar arquivos ou auditorias.

Se o diagnóstico cita **matricula_movimentacoes (idmatricula) com matriculas**, há históricos que apontam para uma matrícula ausente no banco restaurado. A versão 0.19.19 guarda uma cópia integral de cada histórico antes de retirá-lo da tabela operacional, permitindo concluir o vínculo obrigatório das movimentações válidas.

O administrador encontra essas cópias em **ERP Educacional → Registros preservados**, inclusive enquanto outra etapa da migração estiver pendente. É possível baixar o registro completo em JSON, com todos os campos e códigos originais. O documento privado original também pode ser baixado quando estiver disponível no backup; sua referência permanece guardada mesmo se o arquivo estiver ausente. Nenhuma matrícula, turma, pessoa ou obrigação financeira é criada para preencher a lacuna.

Cada cópia tem verificação de integridade e auditoria. Uma falha reverte todo o lote de preservação. Repetir a migração reutiliza uma cópia idêntica; outro conteúdo com o mesmo código de origem gera uma cópia separada. As movimentações válidas, alunos, matrículas, contratos, parcelas e pagamentos permanecem intactos.

O ERP confere todas as referências declaradas antes de adicionar as chaves estrangeiras. Outros vínculos inconsistentes, como um contrato sem matrícula, aparecem juntos em Configurações e impedem a confirmação da nova versão até a conferência dos dados correspondentes. Outros conflitos, como RA duplicado ou restrições do servidor, continuam sendo apresentados no diagnóstico. A migração não recria mensagens ou outros registros ausentes no backup.
