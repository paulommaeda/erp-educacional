# Bolsas cadastráveis — 0.19.26

## Preparar e cadastrar

Atualize em **Plugins → ERP Educacional → Verificar atualizações**. Abra o painel como administrador e aguarde a migração para o banco **45**. Se houver uma pendência, use **ERP Educacional → Configurações → Verificar/aplicar migrações**.

Em **Configurações → Bolsas**, cada coligada recebe **Bolsa Integral (100%)** e **Bolsa 50%**. Use **Cadastrar bolsa** para criar outras condições em percentual da mensalidade-base ou em valor fixo por parcela. As bolsas padrão mantêm seus percentuais; podem ser inativadas. Os cadastros e as concessões são administrados pelo administrador do WordPress.

Em **Configurações → Pontualidade**, informe o valor por parcela, por exemplo **R$ 103,00**. O plano de pagamento contém o **valor anual sem desconto de pontualidade**, com esse componente já incluído. Para 12 parcelas de R$ 1.353, cadastre **R$ 16.236** no plano. Sem bolsa, o pagamento em dia será de R$ 1.250 por parcela. O sistema não soma R$ 103 novamente ao valor cadastrado.

## Cálculo

As bolsas percentuais usam o **valor total da parcela cadastrada**, antes da pontualidade. Percentuais são somados nessa mesma base: **50% + 10% = 60%**. O restante após o percentual é o valor a pagar **em dia**; sem pontualidade, a parcela inclui os R$ 103 configurados. Na integral, os dois valores ficam zerados.

Uma bolsa em **valor fixo** reduz diretamente a parcela cadastrada. O pagamento em dia recebe também o desconto de pontualidade. Assim, R$ 1.353 − R$ 350 = R$ 1.003; em dia, R$ 1.003 − R$ 103 = R$ 900.

Com parcela cadastrada de **R$ 1.353** e pontualidade de **R$ 103**:

| Bolsa | Valor da parcela sem desconto de pontualidade | Pagamento em dia |
|---|---:|---:|
| Nenhuma | R$ 1.353,00 | R$ 1.250,00 |
| 50% | R$ 779,50 | R$ 676,50 |
| 50% + 10% | R$ 644,20 | R$ 541,20 |
| Valor fixo de R$ 350 | R$ 1.003,00 | R$ 900,00 |
| Integral | R$ 0,00 | R$ 0,00 |

Por exemplo, 50% de R$ 1.353 é R$ 676,50: a parcela fica em R$ 779,50 e o pagamento em dia em R$ 676,50. No registro financeiro, são R$ 573,50 de redução incondicional e R$ 103 condicionados ao pagamento em dia, totalizando o benefício percentual de R$ 676,50. Para a bolsa fixa, a redução incondicional é R$ 350 e a pontualidade continua sendo R$ 103 à parte.

Percentuais, valores fixos e descontos manuais podem se combinar. O sistema recusa valores negativos, soma percentual acima de 100%, descontos acima do limite e composição que aumentaria o valor original da parcela. Nenhuma operação parcialmente inválida é gravada.

Depois do vencimento, permanece o valor da coluna sem desconto de pontualidade, sujeito aos encargos aplicáveis. A baixa registra o dinheiro recebido e a pontualidade concedida separadamente. Na integral, o acréscimo também é isento: o lançamento fica zerado e quitado sem inventar recebimento ou baixa. A primeira parcela integralmente isenta atende à liberação financeira da situação **Cursando**.

O cálculo distribui centavos entre as parcelas e bolsas, preservando os totais. A pontualidade contratada fica guardada nos novos contratos e parcelas; mudar a configuração vale para novas contratações. O desconto por pontualidade só é concedido no pagamento que atende à regra existente do ERP.

## Aplicar na matrícula

No formulário de matrícula, em **Plano de pagamento e contrato → Bolsas cadastradas**, marque uma ou mais bolsas e informe **Da parcela / Até a parcela** para cada uma. A prévia mostra os valores com e sem desconto de pontualidade. O desconto manual total continua disponível separadamente.

Ao confirmar, o contrato guarda as regras escolhidas e gera parcelas e lançamentos com os mesmos valores. Na rematrícula com geração financeira posterior, a concessão pode ser feita enquanto o contrato aguarda parcelas: o total é projetado e a geração aplica cada desconto uma única vez.

## Alterar um contrato existente

Abra **Alunos → ficha do aluno → Financeiro → Bolsas e descontos dos contratos**, selecione o contrato e use **Bolsas cadastradas**.

- **Aplicar bolsa cadastrada:** escolha a bolsa e a faixa de parcelas.
- **Editar faixa e situação:** habilite ou suspenda e informe **Aplicar alterações a partir da parcela**. As anteriores mantêm suas regras.
- **Retirar bolsa:** informe a partir de qual parcela o benefício será retirado.

O sistema recalcula **parcela, lançamento e totais do contrato** na mesma operação. Mantém os identificadores das cobranças e enfileira a atualização Educbank; não duplica lançamentos. Alterações explícitas também podem alcançar parcelas vencidas sem pagamento. Pagamentos integrais, parciais e baixas efetivas de valor zero são preservados, assim como lançamentos cancelados ou excluídos. A tela informa quantas parcelas foram recalculadas e quantas ficaram preservadas.

Os descontos comuns e por indicação continuam disponíveis no fluxo anterior. Eles se combinam às bolsas cadastradas dentro do limite da parcela. Na edição de contratos novos **antes de gerar parcelas**, o campo **Anuidade sem o componente de pontualidade** representa a composição interna do contrato: o valor anual bruto menos a pontualidade das parcelas. **Desconto manual total** continua separado. O sistema recompõe os totais e as bolsas; depois da geração, o cronograma continua protegido.

Editar nome, descrição ou valor no catálogo vale para **novas concessões**. Bolsas já concedidas guardam a regra original, inclusive seu detalhamento por parcela. Para mudar o percentual de uma concessão existente, retire a anterior a partir da parcela desejada e aplique a nova bolsa nessa faixa. As alterações têm controle de versão, repetição protegida e auditoria.

## Contratos anteriores e backups

O banco permanece no schema **45**. A correção não recalcula parcelas existentes em lote. Novas concessões guardam a regra percentual sobre o valor original; concessões emitidas na versão anterior conservam a regra que já estava gravada, inclusive após editar faixa ou situação. Para usar a regra atual em uma concessão antiga, retire a anterior e conceda novamente na faixa desejada, preservando os pagamentos.

Uma concessão nova em parcela anterior ao schema 45 usa seu valor original como total, sem acrescentar pontualidade novamente. Somente as parcelas elegíveis da faixa são recalculadas. Formulários abertos com a composição anterior precisam ser recarregados antes da confirmação; a API recusa uma cotação antiga.

O termo já emitido ou aceito permanece como foi gravado. Alterar uma concessão financeira não substitui silenciosamente o texto assinado. O administrador pode conferir e revisar o contrato pelo fluxo próprio de contratos, com o motivo e a versão do termo.

## Educbank

Uma bolsa em parcela já integrada atualiza a fatura existente, mantendo o plano confirmado pelo Educbank. O valor original inclui a pontualidade; a bolsa segue como desconto incondicional e a pontualidade fica separada. Integral cancela uma fatura sem pagamento ou impede o envio de uma nova cobrança zerada. A fila mostra falhas e permite reprocessar.

Se a fatura estiver paga no Educbank, a alteração/cancelamento exige conciliação. Retirar uma integral depois de cancelar a fatura externa pode exigir conferência da fatura cancelada; não há recriação automática silenciosa. Encargos já aplicados também podem exigir conciliação antes do envio. A entrega e confirmação dependem da conexão Educbank configurada no WordPress.

## Verificação desta versão

Foram testados os cálculos e os pagamentos com SQLite, a migração de um banco 44 com registros históricos usando WordPress 7.1.3/MariaDB 11.4.8, permissões REST, transações e repetição de operações. A interface foi conferida em computador e celular, e o transporte Educbank foi simulado com faturas e retornos fictícios. Não houve instalação no site do colégio nem envio de cobranças ou e-mails reais. Veja [o registro de validação](VALIDACAO.md).
