# Contratos e modelos — 0.19.34

O menu **Contratos** está disponível para administradores e perfis liberados em **Perfis e acessos → Editar acessos → Contratos**. Também há atalhos no Financeiro e na área Financeiro da ficha do aluno. **Bolsas e descontos dos contratos** é uma permissão independente, com uma lista própria para localizar contratos e administrar benefícios. A coligada de trabalho e o período da consulta são respeitados. Veja [o guia das permissões](PERFIS-CONTRATOS.md).

## Consultar e editar

Na aba **Contratos dos alunos**, busque por nome, RA ou número. O filtro de situação permite localizar contratos ativos, cancelados e aguardando parcelas. **Ver contrato** exibe o texto guardado no momento da emissão; **Imprimir contrato** prepara o PDF A4 para impressão no leitor do navegador.

**Editar** permite corrigir número, data e texto, com motivo obrigatório. Ao mudar o texto manualmente, informe uma nova versão do termo. Selecionar um modelo na edição substitui o texto daquele contrato pela versão atual do modelo, preenchida com os dados atuais.

Enquanto nenhuma parcela tiver sido gerada, também é possível corrigir valor original, bolsa total, quantidade e primeiro vencimento. O valor líquido é recalculado pelo servidor. Após a geração, use o Financeiro para os ajustes de lançamentos. Responsáveis e matrícula continuam sendo administrados pelos fluxos próprios do ERP.

Na versão **0.19.26**, o plano de pagamento contém a anuidade sem desconto de pontualidade. Em contratos novos, a edição separa **Anuidade sem o componente de pontualidade** e **Desconto manual total**: o primeiro campo representa a composição interna sem esse componente, e o sistema recompõe o valor bruto e as bolsas. A geração não acrescenta pontualidade novamente ao valor cadastrado no plano. Contratos anteriores mantêm suas regras e a instalação não recalcula parcelas em lote. Para bolsas percentuais sobre a parcela integral, bolsas em reais e alterações por faixa, veja [Bolsas cadastráveis](BOLSAS.md).

## Excluir

1. Abra **Excluir** no contrato desejado.
2. Se houver lançamentos vinculados, abra **Financeiro**, localize o aluno e exclua os lançamentos desse contrato. A exclusão em lote pode ser usada. Lançamentos cancelados ainda disponíveis também devem ser excluídos.
3. Volte ao contrato, informe o motivo e marque a confirmação com o número do contrato.

O contrato sai das consultas operacionais. Seu registro, texto, parcelas e baixas permanecem no banco para auditoria e exportação administrativa. A matrícula é preservada. A ação não cancela nem estorna faturas no Educbank. Se for preciso emitir outro contrato, a ficha do aluno volta a oferecer **Configurar contrato**; o novo contrato recebe outro número, preservando o anterior no histórico.

### Excluir a matrícula depois do financeiro

Na versão 0.19.14, depois de excluir os lançamentos e os contratos, também é possível excluir a matrícula cadastrada incorretamente. Abra **Excluir** na matrícula e informe o motivo. Contratos já excluídos deixam de bloquear essa ação; um contrato cancelado que ainda está disponível precisa ser excluído antes.

A matrícula sai da lista, da ficha e do portal, enquanto seu registro permanece ligado ao histórico financeiro. O vínculo ao período volta a **Aguardando turma**, e a vaga e o curso/período ficam liberados para uma nova matrícula. Pagamentos, textos, parcelas, movimentações financeiras e auditoria são preservados. Transferências, rematrículas e outras movimentações acadêmicas continuam impedindo a exclusão.

As filas Educbank antigas ficam ignoradas e não são recriadas por reprocessamento ou alterações cadastrais. Uma nova matrícula recebe seu próprio identificador e suas próprias filas. A exclusão local não cancela matrícula nem faturas na plataforma Educbank.

## Cadastrar modelos

Em **Modelos de contrato → Novo modelo**, informe nome, versão do texto, conteúdo e situação. O texto inicial é um exemplo: substitua pelo conteúdo completo usado pelo colégio. O editor aceita texto simples ou HTML de formatação, identificado automaticamente. O seletor de formato permite escolher explicitamente HTML ou texto simples. Veja [HTML e impressão](CONTRATOS-HTML-IMPRESSAO.md).

Abra **Shortcodes disponíveis** e clique em um campo para inseri-lo na posição do cursor. **Prévia com dados de um contrato** permite escolher um dos 20 contratos mais recentes da coligada, sem restrição de período, e conferir o preenchimento. A prévia não salva alterações no contrato de referência.

Pode haver um único modelo padrão ativo por coligada. Marcar outro como padrão desmarca o anterior. A secretaria pode escolher qualquer modelo ativo na matrícula ou optar por texto personalizado. O modelo escolhido e sua versão são conferidos antes da emissão. Cada aluno recebe um texto preenchido individualmente, inclusive em matrículas em lote.

O contrato guarda o texto preenchido, nome do modelo, versão do modelo e versão do termo. Editar o modelo atualiza os contratos vinculados ainda não assinados. Textos assinados ficam preservados permanentemente. Excluir o modelo não apaga os textos gerados. A revisão do conteúdo exige uma nova versão do texto e motivo. Os termos explícitos já apresentados e aceitos no fluxo de rematrícula prevalecem sobre o modelo padrão.

## Shortcodes

| Grupo | Campos |
| --- | --- |
| Aluno | `[aluno_nome]`, `[aluno_ra]`, `[aluno_cpf]`, `[aluno_nascimento]`, `[aluno_email]`, `[aluno_endereco]` |
| Responsável financeiro atual (assinante) | `[responsavel_nome]`, `[responsavel_cpf]`, `[responsavel_rg]`, `[responsavel_email]`, `[responsavel_telefone]`, `[responsavel_estado_civil]`, `[responsavel_profissao]` |
| Endereço do assinante | `[responsavel_endereco_completo]`, `[responsavel_rua]`, `[responsavel_numero]`, `[responsavel_complemento]`, `[responsavel_bairro]`, `[responsavel_cidade]`, `[responsavel_estado]`, `[responsavel_cep]`, `[responsavel_pais]`, `[responsavel_endereco]` |
| Pai e mãe com vínculo vigente | `[pai_nome]`, `[pai_cpf]`, `[mae_nome]`, `[mae_cpf]` |
| Colégio | `[colegio_nome]`, `[colegio_razao_social]`, `[colegio_cnpj]`, `[colegio_endereco]`, `[colegio_email]`, `[colegio_telefone]` |
| Contrato | `[contrato_numero]`, `[contrato_data]`, `[contrato_valor_original]`, `[contrato_valor_parcela_original]`, `[contrato_parcelamento_original]`, `[contrato_desconto]`, `[contrato_valor_liquido]`, `[contrato_valor_total]`, `[contrato_parcelas]`, `[contrato_valor_parcela]`, `[contrato_parcelamento]`, `[contrato_dia_vencimento]`, `[contrato_primeiro_vencimento]` |
| Acadêmico | `[matricula_id]`, `[periodo_codigo]`, `[curso_nome]`, `[turma_nome]`, `[turno_nome]`, `[plano_nome]` |

Datas são preenchidas como `DD/MM/AAAA` e valores como `R$ 12.000,00`. Dados opcionais ausentes ficam em branco. A razão social e o CNPJ vêm prioritariamente da coligada proprietária do contrato; os demais dados institucionais usam a identidade configurada nessa coligada.

### Plano de pagamento do aluno

Os valores usam as condições do plano adotado na matrícula daquele aluno, a quantidade escolhida e o primeiro vencimento registrado no contrato. Depois da geração, o valor de cada parcela vem das parcelas contratuais; antes dela, o ERP usa o mesmo cálculo financeiro da geração, com divisão exata dos centavos e bolsas/descontos por faixa. Não são usados saldo em aberto, juros, multas ou valor de baixas.

| Campo | Resultado |
| --- | --- |
| `[contrato_valor_original]` | Total original contratado, antes de qualquer bolsa ou desconto |
| `[contrato_valor_parcela_original]` | Valor original da parcela, antes de qualquer bolsa ou desconto |
| `[contrato_parcelamento_original]` | Quantidade e valores originais, como `12 parcelas de R$ 1.353,00`, antes de qualquer bolsa ou desconto |
| `[contrato_valor_total]` ou `[contrato_valor_liquido]` | Total contratado após bolsas/descontos incondicionais, sem antecipar o desconto condicional de pontualidade |
| `[contrato_parcelas]` | Quantidade de parcelas do aluno, como `12` |
| `[contrato_valor_parcela]` | Valor líquido após bolsas/descontos incondicionais; se variar, informa cada valor e sua faixa de parcelas |
| `[contrato_parcelamento]` | Quantidade e valor líquido após bolsas/descontos incondicionais; discrimina faixas quando necessário |
| `[contrato_dia_vencimento]` | Somente o dia com dois dígitos, como `05`, `10` ou `31` |
| `[contrato_primeiro_vencimento]` | Data completa do primeiro vencimento, como `10/01/2027` |

A cláusula de anuidade já aparece em novos modelos. Em um modelo existente, selecione o trecho que quer substituir ou posicione o cursor no ponto de inserção. Clique em **Inserir cláusula de anuidade**. O botão insere a frase abaixo, até o fim da primeira sentença. Os vencimentos têm campos próprios.

```text
A CONTRATANTE pagará a anuidade escolar de [contrato_valor_original], dividida em [contrato_parcelamento_original].
Vencimento no dia [contrato_dia_vencimento] de cada mês.
O primeiro vencimento será em [contrato_primeiro_vencimento].
```

Um plano original de R$ 16.236,00 em 12 parcelas preenche `[contrato_valor_original]` com R$ 16.236,00, `[contrato_valor_parcela_original]` com R$ 1.353,00 e `[contrato_parcelamento_original]` com 12 parcelas de R$ 1.353,00. Esses valores permanecem iguais mesmo com bolsa de 50%, bolsa integral, desconto fixo ou bolsa somente em algumas parcelas. Nenhuma bolsa nem desconto de pontualidade é abatido desses campos, e a pontualidade não é acrescentada novamente ao valor original do plano. Pequenas diferenças reais de centavos na divisão são discriminadas por faixa, sem usar uma média.

Os campos líquidos anteriores continuam disponíveis com seus nomes e descrições próprios para modelos que precisem apresentar benefícios. Para uma cláusula com os valores originais, use os campos que contêm `original`.

O dia é o do primeiro vencimento contratado; em meses sem esse dia, a cobrança continua ajustada ao último dia do mês. Alterar depois o preço no catálogo de planos não troca o valor já contratado. A edição permitida das condições de um contrato pendente atualiza os campos de seu modelo vinculado, desde que o texto ainda não esteja assinado. Textos personalizados e modelos excluídos são preservados. Para incluir os novos campos em contratos existentes, edite o modelo, informe uma nova versão do texto e salve; somente contratos vinculados não assinados serão atualizados.

Estado civil, profissão e endereço vêm do cadastro em **Pessoas** do responsável financeiro atual do contrato, que será o assinante. O estado civil é preenchido pelo nome cadastrado em **Configurações → Estados civis**, com suporte ao texto legado. Não são usados os dados do aluno ou de um responsável que tenha somente atribuição acadêmica.

`[responsavel_endereco_completo]` reúne rua, número, complemento, bairro, cidade, estado/UF e CEP, acrescentando o país quando há dados de endereço. Campos ausentes são omitidos sem separadores vazios. `[responsavel_endereco]` continua com a composição anterior, sem país. CEP e os campos individuais usam os valores salvos no cadastro; `[responsavel_pais]` retorna o código ISO, como `BR` ou `US`.

Os novos campos são válidos na prévia e nas próximas emissões. Alterar os dados da pessoa ou trocar o responsável financeiro não modifica o texto já salvo nos contratos; use a edição do contrato para aplicar explicitamente um modelo atualizado, com motivo, quando necessário.

### Shortcodes de outros plugins ou do tema

O editor aceita marcadores que não fazem parte do ERP. Para preencher um shortcode informativo que já funciona no WordPress, o administrador abre **Contratos → Modelos de contrato → Shortcodes externos** na coligada desejada. Cole a expressão completa, uma por linha, e salve. Por exemplo, se o tema fornece `[unidade_escolar campus="centro"]`, inclua essa expressão com os mesmos parâmetros usados no modelo.

Ao editar um modelo, clique na expressão em **Shortcodes disponíveis** ou digite-a no corpo do texto. Use **Prévia com dados de um contrato** para conferir o resultado. O ERP usa o texto devolvido pelo provedor na emissão. A formatação vem do modelo. O preenchimento aceita chamadas independentes com parâmetros fixos. Uma expressão sem provedor ativo ou fora da seleção permanece visível com os colchetes. Para exibir uma expressão selecionada literalmente, use colchetes duplos.

Cada coligada tem sua própria seleção. A impressão lê o texto gravado no contrato. O modelo aceita até 40.000 bytes antes do preenchimento, o nome até 191 e a versão até 40.

## Atualização

A versão 0.19.33 acrescenta valor e parcelamento originais, atualiza o exemplo de novos modelos e identifica os campos líquidos no editor. Mantém o schema 48, sem reescrever contratos assinados ou recalcular cobranças.

A versão 0.19.32 acrescenta os campos de valores, parcelamento e dia de vencimento. Mantém o schema 48, sem atualização em lote de textos, recálculo de cobranças ou alteração de assinaturas.

A versão 0.19.13 adiciona a tabela `modelos_contrato` e campos opcionais de origem do texto e exclusão em `contratos` (schema 38). A migração é aditiva e preserva o texto e a versão dos contratos anteriores. A exportação administrativa do grupo Financeiro inclui modelos, contratos excluídos e seus metadados.

A versão 0.19.14 acrescenta os metadados de exclusão em `matriculas` (schema 39). Os registros anteriores começam sem marca de exclusão. O backup administrativo do grupo Matrículas mantém os registros excluídos e seus metadados; as consultas operacionais os omitem.

## API autenticada

Todas as rotas ficam em `/wp-json/erp-educacional/v1`. Mutações usam `POST`, nonce REST e `Idempotency-Key`. As rotas de gestão permitem administradores e perfis liberados para Contratos; a consulta de modelos ativos também permite a equipe com acesso acadêmico autorizado. Registrar a assinatura usa `POST /contratos/{id}/assinar`, com `versao` e `confirmado: true`.

| Rota | Método | Uso |
| --- | --- | --- |
| `/contratos` | GET | Lista paginada; filtros `search`, `codperiodo`, `status`, `idaluno`, `page` |
| `/contratos/{id}` | GET / POST | Consulta ou edição com `versao` e `motivo` |
| `/contratos/{id}/pdf` | GET | PDF privado com nome, MIME e conteúdo em Base64 |
| `/contratos/{id}/excluir` | POST | Exclusão com `versao`, `motivo` e `confirmado: true` |
| `/modelos-contrato` | GET / POST | Consulta de ativos ou cadastro; `todos=1` inclui inativos para o administrador |
| `/modelos-contrato/{id}` | POST | Edição com versão de concorrência e motivo |
| `/modelos-contrato/{id}/excluir` | POST | Exclusão com versão e motivo |
| `/modelos-contrato/previa` | POST | Prévia sem persistência com `idcontrato` e `texto` |
| `/modelos-contrato/shortcodes-externos` | POST | Administrador atualiza a seleção da coligada com as listas `expressoes` e `anteriores`. A consulta de modelos devolve `shortcodes_externos`. |

Na emissão de contrato, envie `idmodelo` e `modelo_versao` para escolher um modelo ou `termo` para fornecer texto personalizado. Quando nenhum dos dois é enviado, o modelo padrão ativo da coligada é usado, se existir. Sem modelo padrão, permanece o texto de cadastro padrão do ERP.

## Contrato assinado

Em **Ver contrato**, marque **Contrato assinado** e use **Registrar contrato assinado** após conferir o texto. A marcação é permanente. Texto, versão e vínculo com o modelo ficam bloqueados inclusive para administradores. Alterações no modelo atualizam os contratos vinculados ainda não assinados. Bolsas, descontos e operações financeiras seguem disponíveis conforme suas permissões. Veja [o guia do texto assinado](CONTRATO-ASSINADO.md).
