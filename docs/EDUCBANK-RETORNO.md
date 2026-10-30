# Retorno de pagamentos Educbank — 0.17.1

## Ativar no colégio

1. Atualize o plugin e abra o painel WordPress como administrador para aplicar o **schema 34**. A migração acrescenta as tabelas `educbank_retornos` e `educbank_recebimentos`, preservando contratos, parcelas e baixas anteriores.
2. No portal, selecione a coligada e abra **Configurações → Educbank**. Confira se a integração está ativa no ambiente pretendido.
3. Abra **Retorno de pagamentos** e clique em **Ativar retorno e configurar webhook**. O ERP cria uma credencial exclusiva de callback e registra o evento `Invoice.Changed`. Um cadastro anterior para a mesma URL é atualizado, evitando duplicação após timeout.
4. Confira **Webhook confirmado**. Se houver erro de cadastro, a mensagem permanece na tela e a consulta periódica continua habilitada. Corrija o impedimento e use **Reconfigurar webhook**.
5. Use **Consultar pagamentos** para recuperar uma página de faturas pagas já existentes. Quando houver mais, o próximo clique continua do cursor salvo. Use **Processar avisos** para executar imediatamente um lote de notificações recebidas.

O retorno começa **desativado por coligada e ambiente**, mesmo quando o envio já está ativo. Ative separadamente em sandbox e produção. Pagamentos de sandbox são testes e produzem baixas no ERP quando esse retorno é ativado. Esta atualização não ativa o retorno nem simula pagamentos no tenant do colégio.

A URL é derivada da instalação WordPress, sem digitação de IDs. A credencial Basic é independente das contas WordPress e do bearer token Educbank, criptografada no banco e ausente do JSON de configurações/histórico. O proxy/servidor deve encaminhar o cabeçalho `Authorization` à aplicação. Produção exige HTTPS.

A documentação Educbank consultada informa limitação de entrega por HTTP em sandbox. O plugin mantém o protocolo configurado no WordPress e não rebaixa HTTPS para HTTP. Se o parceiro não entregar o aviso nesse ambiente, use a recuperação por consulta e confira a entrega com o Educbank.

## Do pagamento à baixa

1. O callback autentica, verifica os dois identificadores (`InvoiceId` e `ExternalId`) no vínculo da coligada/ambiente/tenant e guarda um aviso durável. Só então responde sucesso. Não faz HTTP nem baixa dentro da requisição do aviso.
2. O worker consulta a fatura conhecida na API autenticada Educbank. Os valores enviados no webhook não autorizam movimentação de dinheiro.
3. A resposta deve corresponder ao CNPJ, matrícula Educbank, contrato e CPF do responsável financeiro atual. O campo `studentExternalId` pode conter o RA com seus zeros ou o identificador externo da própria matrícula, mantendo o UUID da matrícula e o contrato exatos. O valor original e a bolsa também precisam coincidir com o lançamento.
4. Para uma fatura `Paid` consistente, uma única transação registra a baixa e a auditoria, atualiza o valor recebido e zera o saldo. O autor é **sistema (0)**. O usuário que acionou o processamento conserva sua sessão.
5. A primeira parcela quitada promove a matrícula de reservado para cursando pelas regras existentes. As telas passam a mostrar **Pago**, o botão **Pagar** é retirado e **Recibo** passa a usar a baixa efetiva.

| Informação | Tratamento no ERP |
|---|---|
| `paidCents` | Valor efetivamente pago, em centavos inteiros |
| `paidDate` | Data do pagamento, mantendo a data de calendário informada pelo parceiro |
| `paidMethod` | Boleto, Pix, cartão, depósito, recebimento na escola ou múltiplas formas |
| `totalFixedDiscountCents` | Deve coincidir com a bolsa/desconto incondicional local |
| Desconto efetivamente concedido | Registrado separadamente como condicional aplicado |
| `totalFineCents` | Precisa fechar com juros e multa explicitamente detalhados em `fineAndCharges` |
| `netPaidTotalAmountCents` / repasse | Não é usado como dinheiro pago pelo responsável |

O líquido contratual continua sendo **original − desconto incondicional**. A composição da baixa precisa fechar: **pago = líquido − condicional concedido + juros + multa**. O ERP não concede pontualidade novamente nem desconta a bolsa duas vezes. Falta de data, frações de centavo, valores divergentes, forma desconhecida ou encargos sem discriminação seguem para conferência.

A integração não cria outra cobrança ao receber o pagamento. Avisos repetidos, consulta de recuperação e execução simultânea usam o mesmo vínculo e as travas transacionais do financeiro. Uma entrega repetida pode ser revalidada, sem criar outra linha de aviso nem outra baixa para o mesmo recebimento.

## Estornos e intervenções manuais

Uma resposta atual `Pending` com motivo explícito `Refund` ou `Chargeback` pode estornar a baixa criada pela própria integração. O ERP registra estorno e auditoria e restaura a composição anterior da parcela. Um novo pagamento confirmado pode ser baixado novamente, conservando todo o histórico.

A correção confirmada de valor/data/forma de um recebimento da integração também substitui a baixa anterior por estorno e nova baixa, em uma única transação. Se a nova baixa não puder ser aplicada, a transação inteira é revertida.

Quando a baixa tiver sido alterada/estornada manualmente, houver recebimento local conflitante ou o status mudar sem confirmação de estorno, o ERP **preserva o financeiro e pede conferência**. Não desfaz dinheiro apenas porque a fatura foi cancelada no parceiro.

Um retorno `school` exatamente igual a uma quitação local pode ser conciliado sem nova baixa, conferindo valor, data e componentes. Seu estorno continua exigindo intervenção do financeiro. Liquidações `financial_agreement` também exigem conciliação do acordo: o plugin não presume recebimento em dinheiro. Pagamentos parciais locais não são completados automaticamente por diferença.

## Processamento e histórico

O WP-Cron agenda o retorno a cada minuto, condicionado à execução regular do cron do site. Não é um prazo garantido de atualização. O processamento usa lotes pequenos e trava por coligada/ambiente/tenant; há também execução manual pelos botões do modal.

A recuperação consulta até **50 faturas pagas por página**, com cursor no servidor, e revisita um recebimento anterior por execução para recuperar estorno cujo aviso tenha falhado. Faturas não vinculadas a este ERP são ignoradas. Não há loop automático de cliques/paginação no navegador.

O histórico exibe aluno, RA, lançamento, estado Educbank, resultado, valor pago confirmado, horário, tentativas e diagnóstico sem credenciais. As situações são:

- **Pendente:** aguardando confirmação pela API.
- **Processado:** resposta conferida; o resultado indica baixa, conciliação, estorno ou ausência de pagamento.
- **Erro temporário:** retentativa com intervalo crescente, até oito tentativas.
- **Conferir:** divergência financeira/cadastral ou falha que exige intervenção. Depois de corrigir a causa, use **Reprocessar retorno**.

O desligamento pausa novas baixas e consultas. A rota autenticada reconhece avisos como ignorados, sem criar dinheiro; a recuperação busca pagamentos ao reativar. Vínculos e histórico não são apagados.

## Rotas

Base: `/wp-json/erp-educacional/v1/educbank`.

| Método | Rota | Autorização / finalidade |
|---|---|---|
| POST | `/retorno/COLIGADA/sandbox` ou `/retorno/COLIGADA/producao` | Basic exclusivo do callback; persiste aviso, sem baixa direta |
| POST | `/retorno/configuracao` | Administrador; ativa/desativa e configura webhook |
| GET | `/retorno/historico?page=1&status=revisao` | Administrador; histórico paginado da coligada/ambiente atual |
| POST | `/retorno/processar` | Administrador; processa até três avisos |
| POST | `/retorno/consultar` | Administrador; uma página de recuperação |
| POST | `/retorno/ID/reprocessar` | Administrador; recoloca aviso da mesma coligada/ambiente na fila |

O callback não aceita cookie de administrador como substituto da credencial Basic e não usa senha WordPress. A exceção à autenticação de application passwords é restrita ao caminho exato, método POST e Basic previamente validado. As demais rotas REST conservam sua autenticação.

## Validação desta entrega

Testes PHP/SQLite com HTTP simulado cobrem autenticação, ativação explícita, timeout no cadastro do webhook, duplicação, valor real versus valor forjado no aviso/repasse, bolsas e pontualidade, encargos, vínculo de aluno/responsável/coligada, reversão/correção transacional, conflitos manuais, histórico, recibo e cron. SQLite não homologa DDL/travas concorrentes do MySQL nem o processamento real de webhooks pelo WordPress.

A integração existente foi consultada em sandbox apenas em modo leitura; não houve simulação ou baixa real nas faturas do colégio. A entrega real do callback, migração MySQL e o ciclo de pagamento devem ser homologados na instalação após ativação.

Referências oficiais consultadas em 30/10/2026: [Webhook](https://educbank.readme.io/reference/webhook.md), [Webhook de faturas](https://educbank.readme.io/reference/webhook-de-faturas.md), [Cadastro de webhook](https://educbank.readme.io/reference/post_api-ebpay-webhook.md), [Consulta de fatura](https://educbank.readme.io/reference/get_api-ebpay-invoice-id-1.md).

## Correção da referência do aluno — 0.17.1

Uma consulta somente de leitura ao sandbox identificou que a fatura devolvia `studentExternalId` igual a `Enrollment.externalId`, enquanto o RA exibido no ERP era diferente. A versão 0.17.0 comparava esse campo exclusivamente com o RA e rejeitava a baixa com a mensagem genérica “Matrícula, contrato ou RA da fatura não correspondem ao lançamento”.

A conferência aceita as duas representações estritamente: RA exato, preservando zeros, ou ID externo da matrícula local que corresponde ao UUID Educbank já vinculado. O contrato da parcela deve pertencer à mesma matrícula indicada na fila. InvoiceId, ExternalId do lançamento, CNPJ, UUID da matrícula, contrato e responsável financeiro continuam obrigatórios. Não se aceita um código arbitrário de pessoa/aluno ou um identificador aproximado.

A recuperação de resposta perdida após criação usa a mesma compatibilidade: normaliza uma cópia apenas para comparar com a identidade originalmente enviada. Mantém a fatura recebida e o RA local intactos. Não cria uma cobrança substituta.

Após instalar 0.17.1, clique em **Reprocessar retorno** e depois em **Processar avisos**. A consulta automática de recuperação também pode reencontrar a fatura paga. Outras divergências passam a ter mensagens específicas de matrícula, contrato e referência do aluno.

Não exige nova migração: schema 34 mantido. Testes cobrem baixa com referência derivada, RA com zeros, bloqueio de contrato/matrícula/CNPJ/lançamento divergentes, ausência da referência, múltiplos itens e timeout após POST sem duplicação. A consulta ao sandbox não alterou dados nem efetuou baixas na instalação WordPress.
