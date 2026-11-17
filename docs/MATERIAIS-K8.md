# Enviar alunos do ERP para o K8 Produtos — 0.19.24

O ERP envia os cadastros para a API de importação que o sistema de materiais já oferece. O responsável digita o CPF no K8, que utiliza a lista importada para localizar os alunos. A integração segue o contrato do documento API-ALUNOS.md fornecido para este desenvolvimento.

## Configurar no ERP

1. Atualize o ERP em **Plugins → ERP Educacional → Verificar atualizações**.
2. No portal, selecione a coligada correta e abra **Configurações → Integração API → Enviar alunos ao sistema de materiais (K8) → Abrir envio ao K8**.
3. No sistema de materiais, copie a URL exibida em **K8 Produtos → Alunos → Importar pela API**. Ela deve ser HTTPS e terminar em /wp-json/k8-produtos/v1/alunos/importar; também são aceitos subdiretórios e o endereço com rest_route descrito pelo K8.
4. No WordPress do K8, gere uma **Senha de aplicação** para uma conta administradora com manage_options. Informe no ERP a URL, o login dessa conta e a Senha de aplicação. Não use o nome dado à senha como login.
5. Escolha o período letivo e salve. A conexão fica isolada por coligada. Ao alterar destino ou login, informe a senha correspondente.

A senha é cifrada no banco do ERP e não é devolvida ao navegador. O transporte usa Basic Auth, verificação TLS e nenhum redirecionamento. Somente administradores do ERP configuram ou executam o envio.

## Conferir, validar e enviar

1. Clique em **Conferir alunos**. Revise os RAs, nomes, turmas, quantidade de responsáveis e avisos.
2. Clique em **Validar no K8 sem gravar**. O ERP envia validar_apenas como booleano true. O K8 confere cada lote sem alterar sua lista.
3. Após a validação de todos os lotes, clique em **Enviar alunos ao K8**. O ERP envia validar_apenas como booleano false, sempre no modo atualizar.

A interface mostra a confirmação por lote e o total concluído. Se um lote falhar, o processamento para e informa os lotes anteriores confirmados. Uma falha não significa que os lotes anteriores foram desfeitos. Em caso de timeout ou resposta não confirmada, consulte **K8 Produtos → Alunos** antes de iniciar outra tentativa.

Se os cadastros, os vínculos ou a configuração mudarem entre a conferência e o envio, o ERP exige nova conferência. As últimas 40 tentativas ficam registradas por coligada: repetir uma chave confirmada nesse histórico não repete o transporte; uma chave sem confirmação também não é repetida automaticamente.

O envio é manual por esses botões. Novas matrículas ou alterações de responsáveis exigem nova conferência e envio.

## Alunos e responsáveis incluídos

- Alunos e pessoas ativos, com matrícula atual não excluída e turma no período selecionado, pertencentes à coligada de trabalho.
- A turma enviada é o nome da turma atual da matrícula. Cadastros ativos sem matrícula/turma apta no período são contados na conferência e não entram no lote.
- Todos os responsáveis ativos com vínculo em vigor, incluindo pai, mãe, acadêmico, financeiro e outros. Vínculos duplicados não repetem a pessoa; vínculos encerrados ou futuros ficam fora.
- Cada RA viaja com a lista completa de responsáveis em um único lote, com até 100 responsáveis por aluno. A lista não é dividida entre chamadas.
- RA, CPF e telefone são enviados como texto, preservando zeros. CPF ausente é enviado vazio. Telefone segue somente com dígitos.

CPF de aluno ou responsável informado e inválido impede preparar o envio. Responsável sem CPF gera aviso; se nenhum responsável do aluno tiver CPF, esse aluno não será localizado pela compra por CPF até o cadastro ser completado e reenviado. Mais de uma turma atual diferente para o mesmo aluno no período exige conferência da matrícula.

## Formato enviado ao receptor

| Destino K8 | Origem no ERP |
|---|---|
| ra | RA do aluno |
| nome_aluno | Nome da pessoa do aluno |
| cpf_aluno | CPF do aluno, quando preenchido |
| turma | Nome da turma atual no período escolhido |
| responsaveis[].nome | Nome da pessoa responsável |
| responsaveis[].cpf | CPF da pessoa responsável |
| responsaveis[].telefone | Telefone com dígitos |

Exemplo fictício do formato; a integração monta os cadastros no servidor:

~~~json
{
  "modo": "atualizar",
  "validar_apenas": false,
  "alunos": [
    {
      "ra": "000123",
      "nome_aluno": "Aluno de exemplo",
      "cpf_aluno": "",
      "turma": "5º Ano A",
      "responsaveis": [
        {"nome": "Responsável de exemplo", "cpf": "52998224725", "telefone": "11987654321"}
      ]
    }
  ]
}
~~~

O modo atualizar preserva os IDs de RAs existentes e substitui todos os vínculos dos RAs enviados, conforme o contrato do K8. RAs ausentes no lote permanecem no sistema de materiais; inativar/excluir um aluno no ERP não o remove automaticamente do K8. A integração não utiliza substituição total da lista.

O K8 identifica alunos por RA. Se diferentes coligadas compartilham o mesmo destino, seus RAs precisam ser distintos nessa lista; caso contrário, use destinos/listas separados para evitar que um colégio atualize o aluno do outro.

Os lotes têm até 200 alunos, até 2 MiB e até 8.000 registros expandidos pelo formato do receptor. Os limites ficam abaixo dos máximos documentados de 10 MiB e 10.000 registros. Um RA e seus responsáveis sempre permanecem juntos. Em listas grandes, a gravação é confirmada por lote, e cada lote é uma transação independente no receptor.

## Depois da importação

No K8, confira a lista e faça uma nova busca pelo CPF do responsável. A importação bem-sucedida invalida consultas temporárias de compra anteriores. Confira também se as turmas importadas estão liberadas nos produtos: o contrato fornecido informa que turmas novas não são habilitadas automaticamente.

Se o K8 informar que a compra usa uma base externa, o ERP exibe o aviso recebido. Ajuste o mapeamento em **K8 Produtos → Configurações** para que a compra consulte a lista integrada, conforme a orientação do sistema de materiais.

Pedidos, preços, estoque, cobranças e entregas continuam sendo tratados pelo sistema de materiais. Este envio atualiza somente os cadastros de alunos e seus responsáveis.

## Verificação

O formato, a autenticação, o isolamento por coligada/período, a validação, os lotes e as falhas foram exercitados com SQLite e WordPress/MariaDB locais. As chamadas externas foram interceptadas por um receptor simulado que verifica o contrato do documento fornecido. Nenhum cadastro ou e-mail foi enviado a um site externo. O destino real ainda precisa ser configurado e validado pelo administrador. Schema 44 mantido.
