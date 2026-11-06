# Contratos e modelos — 0.19.15

O menu **Contratos** está disponível para administradores do WordPress no portal e no wp-admin. Também há atalhos na Gestão financeira e na área Financeiro da ficha do aluno. A coligada de trabalho e o período da consulta são respeitados.

## Consultar e editar

Na aba **Contratos dos alunos**, busque por nome, RA ou número. O filtro de situação permite localizar contratos ativos, cancelados e aguardando parcelas. **Ver contrato** exibe o texto guardado no momento da emissão; **Imprimir contrato** abre a impressão do navegador, que também permite salvar em PDF.

**Editar** permite corrigir número, data e texto, com motivo obrigatório. Ao mudar o texto manualmente, informe uma nova versão do termo. Selecionar um modelo na edição substitui o texto daquele contrato pela versão atual do modelo, preenchida com os dados atuais.

Enquanto nenhuma parcela tiver sido gerada, também é possível corrigir valor original, bolsa total, quantidade e primeiro vencimento. O valor líquido é recalculado pelo servidor. Após a geração, use a Gestão financeira para os ajustes de lançamentos. Responsáveis e matrícula continuam sendo administrados pelos fluxos próprios do ERP.

## Excluir

1. Abra **Excluir** no contrato desejado.
2. Se houver lançamentos vinculados, abra **Gestão financeira**, localize o aluno e exclua os lançamentos desse contrato. A exclusão em lote pode ser usada. Lançamentos cancelados ainda disponíveis também devem ser excluídos.
3. Volte ao contrato, informe o motivo e marque a confirmação com o número do contrato.

O contrato sai das consultas operacionais. Seu registro, texto, parcelas e baixas permanecem no banco para auditoria e exportação administrativa. A matrícula é preservada. A ação não cancela nem estorna faturas no Educbank. Se for preciso emitir outro contrato, a ficha do aluno volta a oferecer **Configurar contrato**; o novo contrato recebe outro número, preservando o anterior no histórico.

### Excluir a matrícula depois do financeiro

Na versão 0.19.14, depois de excluir os lançamentos e os contratos, também é possível excluir a matrícula cadastrada incorretamente. Abra **Excluir** na matrícula e informe o motivo. Contratos já excluídos deixam de bloquear essa ação; um contrato cancelado que ainda está disponível precisa ser excluído antes.

A matrícula sai da lista, da ficha e do portal, enquanto seu registro permanece ligado ao histórico financeiro. O vínculo ao período volta a **Aguardando turma**, e a vaga e o curso/período ficam liberados para uma nova matrícula. Pagamentos, textos, parcelas, movimentações financeiras e auditoria são preservados. Transferências, rematrículas e outras movimentações acadêmicas continuam impedindo a exclusão.

As filas Educbank antigas ficam ignoradas e não são recriadas por reprocessamento ou alterações cadastrais. Uma nova matrícula recebe seu próprio identificador e suas próprias filas. A exclusão local não cancela matrícula nem faturas na plataforma Educbank.

## Cadastrar modelos

Em **Modelos de contrato → Novo modelo**, informe nome, versão do texto, conteúdo e situação. O texto inicial é um exemplo: substitua pelo conteúdo completo usado pelo colégio. O editor usa texto simples com quebras de linha.

Abra **Shortcodes disponíveis** e clique em um campo para inseri-lo na posição do cursor. **Prévia com dados de um contrato** permite escolher um dos 20 contratos mais recentes da coligada, sem restrição de período, e conferir o preenchimento. A prévia não salva alterações no contrato de referência.

Pode haver um único modelo padrão ativo por coligada. Marcar outro como padrão desmarca o anterior. A secretaria pode escolher qualquer modelo ativo na matrícula ou optar por texto personalizado. O modelo escolhido e sua versão são conferidos antes da emissão. Cada aluno recebe um texto preenchido individualmente, inclusive em matrículas em lote.

O contrato guarda o texto preenchido, nome do modelo, versão do modelo e versão do termo. Editar, inativar ou excluir o modelo não modifica contratos emitidos. A revisão do conteúdo exige uma nova versão do texto e motivo. Os termos explícitos já apresentados e aceitos no fluxo de rematrícula prevalecem sobre o modelo padrão.

## Shortcodes

| Grupo | Campos |
| --- | --- |
| Aluno | `[aluno_nome]`, `[aluno_ra]`, `[aluno_cpf]`, `[aluno_nascimento]`, `[aluno_email]`, `[aluno_endereco]` |
| Responsável financeiro atual (assinante) | `[responsavel_nome]`, `[responsavel_cpf]`, `[responsavel_rg]`, `[responsavel_email]`, `[responsavel_telefone]`, `[responsavel_estado_civil]`, `[responsavel_profissao]` |
| Endereço do assinante | `[responsavel_endereco_completo]`, `[responsavel_rua]`, `[responsavel_numero]`, `[responsavel_complemento]`, `[responsavel_bairro]`, `[responsavel_cidade]`, `[responsavel_estado]`, `[responsavel_cep]`, `[responsavel_pais]`, `[responsavel_endereco]` |
| Pai e mãe com vínculo vigente | `[pai_nome]`, `[pai_cpf]`, `[mae_nome]`, `[mae_cpf]` |
| Colégio | `[colegio_nome]`, `[colegio_razao_social]`, `[colegio_cnpj]`, `[colegio_endereco]`, `[colegio_email]`, `[colegio_telefone]` |
| Contrato | `[contrato_numero]`, `[contrato_data]`, `[contrato_valor_original]`, `[contrato_desconto]`, `[contrato_valor_liquido]`, `[contrato_parcelas]`, `[contrato_primeiro_vencimento]` |
| Acadêmico | `[matricula_id]`, `[periodo_codigo]`, `[curso_nome]`, `[turma_nome]`, `[turno_nome]`, `[plano_nome]` |

Datas são preenchidas como `DD/MM/AAAA` e valores como `R$ 12.000,00`. Dados opcionais ausentes ficam em branco. A razão social e o CNPJ vêm prioritariamente da coligada proprietária do contrato; os demais dados institucionais usam a identidade configurada nessa coligada.

Estado civil, profissão e endereço vêm do cadastro em **Pessoas** do responsável financeiro atual do contrato, que será o assinante. O estado civil é preenchido pelo nome cadastrado em **Configurações → Estados civis**, com suporte ao texto legado. Não são usados os dados do aluno ou de um responsável que tenha somente atribuição acadêmica.

`[responsavel_endereco_completo]` reúne rua, número, complemento, bairro, cidade, estado/UF e CEP, acrescentando o país quando há dados de endereço. Campos ausentes são omitidos sem separadores vazios. `[responsavel_endereco]` continua com a composição anterior, sem país. CEP e os campos individuais usam os valores salvos no cadastro; `[responsavel_pais]` retorna o código ISO, como `BR` ou `US`.

Os novos campos são válidos na prévia e nas próximas emissões. Alterar os dados da pessoa ou trocar o responsável financeiro não modifica o texto já salvo nos contratos; use a edição do contrato para aplicar explicitamente um modelo atualizado, com motivo, quando necessário.

Os shortcodes são substituições de texto de uma lista fixa. Não executam shortcodes de plugins, HTML ou código WordPress. O conteúdo tem limite de 40.000 bytes antes do preenchimento; nome e versão têm limites de 191 e 40 bytes, respectivamente.

## Atualização

A versão 0.19.13 adiciona a tabela `modelos_contrato` e campos opcionais de origem do texto e exclusão em `contratos` (schema 38). A migração é aditiva e preserva o texto e a versão dos contratos anteriores. A exportação administrativa do grupo Financeiro inclui modelos, contratos excluídos e seus metadados.

A versão 0.19.14 acrescenta os metadados de exclusão em `matriculas` (schema 39). Os registros anteriores começam sem marca de exclusão. O backup administrativo do grupo Matrículas mantém os registros excluídos e seus metadados; as consultas operacionais os omitem.

## API autenticada

Todas as rotas ficam em `/wp-json/erp-educacional/v1`. Mutações usam `POST`, nonce REST e `Idempotency-Key`. As rotas de gestão são exclusivas do administrador; a consulta de modelos ativos também permite a equipe com acesso acadêmico autorizado.

| Rota | Método | Uso |
| --- | --- | --- |
| `/contratos` | GET | Lista paginada; filtros `search`, `codperiodo`, `status`, `idaluno`, `page` |
| `/contratos/{id}` | GET / POST | Consulta ou edição com `versao` e `motivo` |
| `/contratos/{id}/excluir` | POST | Exclusão com `versao`, `motivo` e `confirmado: true` |
| `/modelos-contrato` | GET / POST | Consulta de ativos ou cadastro; `todos=1` inclui inativos para o administrador |
| `/modelos-contrato/{id}` | POST | Edição com versão de concorrência e motivo |
| `/modelos-contrato/{id}/excluir` | POST | Exclusão com versão e motivo |
| `/modelos-contrato/previa` | POST | Prévia sem persistência com `idcontrato` e `texto` |

Na emissão de contrato, envie `idmodelo` e `modelo_versao` para escolher um modelo ou `termo` para fornecer texto personalizado. Quando nenhum dos dois é enviado, o modelo padrão ativo da coligada é usado, se existir. Sem modelo padrão, permanece o texto de cadastro padrão do ERP.
