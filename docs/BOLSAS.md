# Bolsas cadastráveis — 0.19.25

## Preparar e cadastrar

Atualize em **Plugins → ERP Educacional → Verificar atualizações**. Abra o painel como administrador e aguarde a migração para o banco **45**. Se houver uma pendência, use **ERP Educacional → Configurações → Verificar/aplicar migrações**.

Em **Configurações → Bolsas**, cada coligada recebe **Bolsa Integral (100%)** e **Bolsa 50%**. Use **Cadastrar bolsa** para criar outras condições em percentual da mensalidade-base ou em valor fixo por parcela. As bolsas padrão mantêm seus percentuais; podem ser inativadas. Os cadastros e as concessões são administrados pelo administrador do WordPress.

Em **Configurações → Pontualidade**, informe o valor por parcela, por exemplo **R$ 103,00**. Esse valor é configurável. Para os novos contratos, o plano de pagamento contém a **anuidade-base**, sem esse acréscimo. Uma mensalidade-base de R$ 1.000 corresponde a uma anuidade-base de R$ 12.000 se forem 12 parcelas.

## Cálculo

Os percentuais das bolsas incidem sobre a mesma mensalidade-base e são somados: **50% + 10% = 60%**. Não há desconto percentual sobre o saldo já reduzido. Valores fixos e descontos manuais são somados à redução; o conjunto não pode superar a base, nem os percentuais podem superar 100%.

Com base de R$ 1.000 e pontualidade configurada em R$ 103:

| Bolsa | Valor da parcela sem desconto de pontualidade | Pagamento em dia |
|---|---:|---:|
| Nenhuma | R$ 1.103,00 | R$ 1.000,00 |
| 50% | R$ 603,00 | R$ 500,00 |
| 50% + 10% | R$ 503,00 | R$ 400,00 |
| Integral | R$ 0,00 | R$ 0,00 |

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

Os descontos comuns e por indicação continuam disponíveis no fluxo anterior. Eles se combinam às bolsas cadastradas dentro do limite da base. Na edição de contratos novos **antes de gerar parcelas**, use **Anuidade-base** e **Desconto manual total**; o sistema calcula os totais com as bolsas. Depois da geração, o cronograma continua protegido.

Editar nome, descrição ou valor no catálogo vale para **novas concessões**. Bolsas já concedidas guardam a regra original, inclusive seu detalhamento por parcela. Para mudar o percentual de uma concessão existente, retire a anterior a partir da parcela desejada e aplique a nova bolsa nessa faixa. As alterações têm controle de versão, repetição protegida e auditoria.

## Contratos anteriores e backups

A instalação acrescenta os campos, tabelas e as duas bolsas padrão. **Não recalcula parcelas existentes nem acrescenta pontualidade em lote.** Contratos anteriores continuam com a regra de valores e pontualidade anterior. Se receberem uma bolsa cadastrada, somente as parcelas elegíveis alcançadas pela concessão explícita passam à nova composição, usando o valor original como base e acrescentando a pontualidade configurada. Confira essa base antes de conceder a bolsa a um contrato antigo.

O termo já emitido ou aceito permanece como foi gravado. Alterar uma concessão financeira não substitui silenciosamente o texto assinado. O administrador pode conferir e revisar o contrato pelo fluxo próprio de contratos, com o motivo e a versão do termo.

## Educbank

Uma bolsa em parcela já integrada atualiza a fatura existente, mantendo o plano confirmado pelo Educbank. O valor original inclui a pontualidade; a bolsa segue como desconto incondicional e a pontualidade fica separada. Integral cancela uma fatura sem pagamento ou impede o envio de uma nova cobrança zerada. A fila mostra falhas e permite reprocessar.

Se a fatura estiver paga no Educbank, a alteração/cancelamento exige conciliação. Retirar uma integral depois de cancelar a fatura externa pode exigir conferência da fatura cancelada; não há recriação automática silenciosa. Encargos já aplicados também podem exigir conciliação antes do envio. A entrega e confirmação dependem da conexão Educbank configurada no WordPress.

## Verificação desta versão

Foram testados os cálculos e os pagamentos com SQLite, a migração de um banco 44 com registros históricos usando WordPress 7.1.3/MariaDB 11.4.8, permissões REST, transações e repetição de operações. A interface foi conferida em computador e celular, e o transporte Educbank foi simulado com faturas e retornos fictícios. Não houve instalação no site do colégio nem envio de cobranças ou e-mails reais. Veja [o registro de validação](VALIDACAO.md).
