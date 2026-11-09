# Exclusão definitiva de dados — 0.19.20

O administrador WordPress pode apagar um registro do ERP junto com todos os registros que dependem dele. A tela oferece todos os tipos de dados presentes no ERP, incluindo alunos, pessoas, matrículas, contratos, parcelas, pagamentos, históricos, chat, documentos, cadastros e registros preservados. A exclusão comum continua disponível.

1. Atualize para **0.19.20** em **Plugins → ERP Educacional → Verificar atualizações**. O banco deve estar na versão **43**.
2. Acesse **ERP Educacional → Excluir dados**, no painel WordPress. Também existe um atalho nas Configurações do portal.
3. Escolha **Alunos** e pesquise pelo nome, RA ou código. Confira a coligada e o registro encontrado.
4. Clique em **Conferir exclusão**. A tela mostra os tipos e as quantidades dos registros vinculados, exemplos, alunos afetados, contas e arquivos privados.
5. Se desejar remover também a pessoa e sua conta WordPress, marque **Excluir também o cadastro da pessoa e a conta WordPress**. Confira a nova prévia: uma pessoa compartilhada pode ter vínculos em outras coligadas ou atribuições.
6. Informe o motivo, digite **EXCLUIR** e clique em **Excluir definitivamente**.

O administrador também encontra **Excluir definitivamente com os vínculos** no diálogo de exclusão dos cadastros principais. Para outros tipos, use a tela central e selecione o tipo correspondente.

## O que a seleção inclui

O ERP percorre os registros que apontam para o registro escolhido e suas dependências. Ao excluir o aluno, isso inclui suas matrículas, contratos, parcelas, lançamentos, baixas e estornos, trocas de responsável, históricos escolares, requerimentos aprovados, conversas, documentos e referências preservadas relacionadas. A prévia informa o conteúdo efetivo da seleção daquele cadastro.

Cadastros pais compartilhados, como a pessoa do responsável, turma, período, curso e plano, não entram automaticamente na seleção por serem usados pelo aluno. Eles podem ser escolhidos diretamente na tela central, que mostrará todas as dependências dessa outra seleção. Um registro selecionado por outro vínculo pode alcançar dados de outros alunos; confira a lista de alunos afetados e as contagens. São exibidos até três exemplos por tipo e até cem alunos, com o total completo informado.

Ao excluir uma pessoa ou seu vínculo de usuário, a conta WordPress vinculada também é removida. Os posts e outros conteúdos WordPress são transferidos ao administrador, incluindo os arquivos da biblioteca de mídia. O histórico de acessos daquela conta entra na exclusão definitiva. Para excluir a própria pessoa/conta da sessão, entre com outro administrador.

## Confirmação e recuperação de falhas

A confirmação vale para o administrador e para os dados apresentados. Um novo vínculo ou alteração nos registros impede a execução com uma prévia anterior. Erro em qualquer exclusão, inclusive no comprovante final, reverte o lote inteiro no banco e a exclusão conjunta da conta. Repetir a mesma confirmação após uma falha de resposta retorna o resultado já concluído.

O comprovante registra quem confirmou, motivo, códigos e quantidades, sem guardar uma cópia dos dados pessoais ou financeiros apagados. Ele fica armazenado no ERP e pode ser exportado no grupo de histórico. A tela central também oferece o tipo **Comprovantes de exclusão definitiva** para apagar esses comprovantes.

Os arquivos privados entram em remoção após a confirmação do banco. Um arquivo ainda referenciado por outro cadastro é mantido até a última referência. Falhas deixam uma fila persistente para nova tentativa pela tarefa WordPress; a resposta informa quando há arquivos aguardando remoção. As imagens e outros arquivos da biblioteca de mídia do WordPress são recursos compartilhados e continuam sob gestão da biblioteca.

A exclusão é local ao ERP. Quando a seleção contém dados do Educbank, a tela informa que apagar esses registros não cancela cobranças no serviço externo.

Excluir o período atual limpa a seleção correspondente nas Configurações. Excluir uma coligada remove seus dados e suas configurações; quando for a última coligada ativa, a prévia informa a criação de um espaço vazio para configurar uma nova. As chaves estrangeiras continuam ativas durante todo o procedimento.
