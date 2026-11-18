# Gestão financeira — 0.19.28

## Acessos e telas

O menu **Financeiro** mantém a consulta de lançamentos e as operações existentes. A nova **Gestão financeira** é um painel de consulta com acesso independente.

Como administrador, abra **Perfis e acessos → perfil desejado → Editar acessos**, marque **Gestão financeira** e salve. A pessoa deve recarregar o portal. O acesso também pode ser retirado nessa tela; cada consulta confere a autorização novamente. A atualização não concede o painel automaticamente aos funcionários. Liberar o painel não concede baixas, estornos, edição de lançamentos ou administração dos perfis.

## Filtros

Selecione a coligada de trabalho e o período no topo do portal. Trocar o período da consulta atualiza o painel imediatamente e limpa competências, turma e aluno para evitar filtros do período anterior. A seleção acompanha os links de navegação, sem alterar o período vigente da escola. No painel, combine **competência inicial/final**, **turma** e **aluno**. Competências vazias incluem todas as competências do período consultado. Use **Buscar**, **Anterior** e **Próxima** para localizar turmas e alunos; os alunos podem ser pesquisados por nome ou RA. Clique em **Aplicar filtros**. **Limpar filtros** retorna à consulta de todas as turmas/alunos/competências dentro da coligada e do período.

O mês é a competência da parcela. Os recebimentos seguem a competência da parcela à qual pertencem. O painel usa os valores atuais e a turma atual da matrícula. A linha de identificação da consulta mostra os filtros aplicados e a data de referência.

## Indicadores

As bolsas e os descontos incondicionais já são retirados de todas as bases. Não são contabilizados como inadimplência.

| Indicador | Base |
|---|---|
| Previsto bruto | Valor da parcela após benefícios incondicionais, antes da pontualidade. Em títulos cancelados, permanece apenas a parte já liquidada. |
| Previsto | Projeção com pontualidade. O principal efetivamente recebido é preservado quando ultrapassa a projeção, como em um pagamento sem pontualidade. |
| Realizado | Principal recebido em baixas válidas, excluindo baixas estornadas. Juros e multas recebidos aparecem separadamente no total recebido. |
| Inadimplência bruta | Principal vencido em aberto, sem deduzir a pontualidade restante. Pagamentos parciais e descontos já efetivados são abatidos. |
| Inadimplência líquida | Mesmo saldo vencido, considerando a pontualidade restante como projeção de análise. |

Exemplo: parcela de R$ 1.353 com bolsa de 50% gera R$ 779,50 antes da pontualidade. Se vencer sem pagamento, a inadimplência bruta será R$ 779,50 e a líquida R$ 676,50. Com desconto incondicional fixo de R$ 350, a parcela é R$ 1.003: após pagamento parcial de R$ 100 de principal, a bruta será R$ 903 e a líquida R$ 800.

A pontualidade considerada na projeção fica limitada ao saldo de principal e não é deduzida duas vezes se já foi concedida. A consulta não altera as condições de cobrança: a projeção líquida não concede desconto a uma parcela vencida. Juros e multas não são misturados ao principal inadimplente.

As taxas percentuais dividem a inadimplência pela respectiva base das parcelas que já venceram. Parcelas que vencem hoje entram no previsto e no saldo a receber; tornam-se vencidas no dia seguinte, conforme a data configurada no WordPress. Uma consulta sem parcelas vencidas apresenta taxa zero.

Saldos cancelados ou excluídos ficam fora da previsão e do atraso. Valores já recebidos e preservados em títulos cancelados continuam no realizado. Contratos ainda sem lançamentos não entram neste painel. O resumo por competência usa as mesmas bases dos cartões.

## Atualização

Disponível desde 0.19.28; atualização 0.19.29 com schema 46 e acompanhamento do período da consulta. Consulte a atualização em **Plugins → ERP Educacional → Verificar atualizações**. As telas operacionais continuam em `erp_tela=financeiro`; o novo painel usa `erp_tela=gestao_financeira`. O shortcode `[erp_gestao_financeira]` abre o painel e `[erp_operacoes_financeiras]` abre o Financeiro. `[erp_financeiro]` continua sendo a visão financeira familiar.

Veja [as verificações realizadas](VALIDACAO.md) e [as próximas etapas registradas](PLANEJAMENTO-FINANCEIRO.md).
