# Recuperar o ERP após restaurar um backup

O backup pode conter uma versão anterior do banco. O número **36** exibido em Banco de dados é a versão da estrutura; o plugin atual usa a estrutura **39**. Esse número é diferente da versão do plugin, **0.19.16**.

1. Em **Plugins → ERP Educacional → Verificar atualizações**, consulte e instale a versão **0.19.16** ou posterior.
2. Abra o painel WordPress como administrador. A nova versão faz uma tentativa de migração, mesmo se houver um erro de versão anterior salvo.
3. Entre em **ERP Educacional → Configurações**. Se a atualização estiver pendente, a tela apresenta as versões do banco, o diagnóstico da última tentativa e **Verificar/aplicar migrações**. O mesmo recurso está disponível em Configurações no portal.
4. Clique em **Verificar/aplicar migrações**. Ao concluir, o banco passa a **39** e as configurações voltam a carregar.
5. Se a tentativa falhar, copie o texto de **Diagnóstico da última tentativa**. Ele identifica a etapa, a tabela e o erro do banco. Um conflito nos dados ou uma restrição de acesso do servidor precisa ser tratado conforme esse diagnóstico.

A correção cria os índices substitutos antes de remover índices antigos. As chaves estrangeiras continuam ativas; os registros de alunos, matrículas, contratos, parcelas e pagamentos são preservados. Repetir a verificação permite continuar uma migração interrompida. Não altere manualmente o número da versão do banco para ocultar o aviso.

A migração não resolve automaticamente dados conflitantes nem recria registros ausentes em um backup incompleto. Ela para e informa o impedimento, mantendo a versão anterior até concluir.
