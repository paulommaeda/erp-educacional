# Recuperar o ERP após restaurar um backup

O backup pode conter uma versão anterior do banco. O número **36** exibido em Banco de dados é a versão da estrutura; o plugin atual usa a estrutura **40**. Esse número é diferente da versão do plugin, **0.19.17**.

1. Em **Plugins → ERP Educacional → Verificar atualizações**, consulte e instale a versão **0.19.17** ou posterior.
2. Abra o painel WordPress como administrador. A nova versão faz uma tentativa de migração, mesmo se houver um erro de versão anterior salvo.
3. Entre em **ERP Educacional → Configurações**. Se a atualização estiver pendente, a tela apresenta as versões do banco, o diagnóstico da última tentativa e **Verificar/aplicar migrações**. O mesmo recurso está disponível em Configurações no portal.
4. Clique em **Verificar/aplicar migrações**. Ao concluir, o banco passa a **40** e as configurações voltam a carregar.
5. Se a tentativa falhar, copie o texto de **Diagnóstico da última tentativa**. Ele identifica a etapa, a tabela e o erro do banco. Um conflito nos dados ou uma restrição de acesso do servidor precisa ser tratado conforme esse diagnóstico.

A correção cria os índices substitutos antes de remover índices antigos. As chaves estrangeiras continuam ativas; os registros de alunos, matrículas, contratos, parcelas e pagamentos são preservados. Repetir a verificação permite continuar uma migração interrompida. Não altere manualmente o número da versão do banco para ocultar o aviso.

Se o diagnóstico anterior citava o vínculo entre **chat_anexos** e **chat_mensagens**, a versão 0.19.17 preserva os anexos sem mensagem válida na mesma coligada para conferência. O conteúdo, nome, tamanho, data original, coligada e identificador da mensagem original permanecem no banco. As mensagens e os anexos válidos continuam nas suas conversas.

Após a migração, o administrador encontra esses arquivos em **ERP Educacional → Anexos preservados**. Configurações também apresenta um aviso com acesso à conferência. Os downloads são privados e exigem autorização de administrador. Arquivos pendentes não ficam disponíveis pelo chat comum nem são atribuídos automaticamente a outra conversa ou coligada.

A recuperação dos anexos fica registrada na auditoria. Se uma gravação do lote falhar, suas alterações são revertidas; repetir a migração retoma a recuperação sem duplicar arquivos ou auditorias.

Outros conflitos, como RA duplicado ou restrições do servidor, continuam sendo apresentados no diagnóstico. A migração não recria mensagens ou outros registros ausentes no backup.
