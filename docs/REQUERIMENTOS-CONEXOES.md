# Requerimentos: pessoas e conexões — 0.15.3

Em **Configurações → Integração API**, é possível cadastrar várias conexões com **Adicionar conexão**. Cada uma tem nome, URL HTTPS, usuário, senha, caminho da lista, parâmetro de paginação, situação e mapeamento próprios. A configuração anterior aparece como **Conexão principal**, preservando sua senha e seus campos.

Na conexão desejada, clique em **Mapear pessoas**. O mapeamento fica organizado em blocos:

- Aluno;
- Pai e Mãe;
- Responsável acadêmico e Responsável financeiro;
- Outro responsável.

Escolha o bloco em **Adicionar pessoa do requerimento** e clique em **Adicionar bloco de pessoa**. Selecione, para cada campo do ERP, o caminho correspondente na resposta da API. O nome identifica o bloco recebido; para criar a pessoa, o nome e o nascimento são obrigatórios. Dados ausentes podem ser completados durante a conferência. Campos adicionais, traduções e valores fixos continuam disponíveis. Os campos mapeados são importados independentemente da exibição no cadastro ou na ficha.

Quando uma resposta já contém aluno, pai, mãe e outros responsáveis, coloque todos os blocos na mesma conexão: eles aparecerão juntos no requerimento. Conexões diferentes possuem filas de origem independentes, mesmo que um ID externo coincida; não há junção automática entre respostas de URLs ou conexões diferentes.

**Buscar para conferência** consulta somente a conexão selecionada e prepara os registros. Na tela **Requerimentos**, a secretaria confere cada pessoa, corrige dados e escolhe criar, aproveitar um cadastro existente, usar a mesma pessoa de um bloco anterior ou ignorar o bloco. Por exemplo, o bloco Responsável acadêmico pode usar o cadastro já conferido no bloco Mãe.

Confirmar cria os cadastros de **Pessoas** e as contas WordPress correspondentes. Transformar a pessoa em aluno, definir atribuições, vincular responsáveis e matricular continuam nas etapas próprias do ERP. Nenhuma pessoa é criada apenas por testar ou buscar a API.

A fila e o log exibem o nome da conexão. Requerimentos aceitos deixam a fila pendente e não reaparecem em novas buscas da mesma origem. Ocultados podem ser restaurados com o mapeamento de sua própria conexão. Desativar uma conexão impede novas consultas e preserva os dados já conferidos e o histórico.

As senhas permanecem criptografadas e não são retornadas ao navegador. Somente administradores configuram conexões. A secretaria utiliza sua permissão de Requerimentos para conferir os dados.

## Compatibilidade da API do ERP

Os endpoints existentes em `/wp-json/erp-educacional/v1/api-pessoas/` são mantidos:

| Operação | Alteração |
| --- | --- |
| GET `configuracao` | Retorna `conexoes`, `pessoas` e `campos`, preservando os campos da configuração principal no nível superior. |
| POST `configuracao` | Usa `idconexao` para editar ou `nova_conexao: true` para adicionar. A UI envia `versao` ao editar e uma chave de operação estável ao criar. |
| POST `testar` | Recebe `idconexao`; sem esse campo, consulta a principal. |
| POST `buscar` | Recebe `idconexao` e `pagina`; sem conexão, usa a principal. |
| Fila, conferência e logs | Preservam o `idconexao` e apresentam `conexao_nome`. |

Schema 31: coluna de identificação da conexão e índice por conexão/situação na tabela de requerimentos. Registros anteriores pertencem à principal. Configurações permanecem nas opções do WordPress; nenhum dado de aluno ou financeiro é renumerado.
