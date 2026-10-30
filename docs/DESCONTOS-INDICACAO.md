# Descontos e indicação — 0.18.0

## Atualizar

Abra o painel WordPress como administrador após substituir o plugin. A migração **35** acrescenta campos e tabelas de controle: os registros existentes continuam válidos como descontos comuns. Nenhum contrato ou parcela é recalculado apenas pela instalação.

A operação continua exclusiva de administrador WordPress, como a gestão de bolsas anterior. A família autorizada pode consultar o detalhamento financeiro de suas parcelas, sem editar benefícios.

## Cadastro e edição

Na ficha do aluno → **Financeiro → Bolsas e descontos dos contratos**, selecione o contrato e use **Adicionar desconto**. Informe nome, descrição, tipo, valor em reais por parcela, faixa inicial/final e situação habilitado/suspenso. A descrição é visível no detalhamento financeiro: utilize conteúdo adequado ao responsável. Nomes e descrições seguem a padronização de dados em maiúsculas.

Para mudar um benefício, use **Editar** e informe **Aplicar alterações a partir da parcela**. O sistema guarda regras por faixa para preservar o passado. Por exemplo:

| Alteração | Resultado |
|---|---|
| Suspender a partir da 7 | Parcelas 1–6 mantêm suas regras; parcelas 7 em diante sem baixa perdem o benefício |
| Alterar de R$ 100 para R$ 150 a partir da 5 | As anteriores mantêm R$ 100; da 5 em diante, as elegíveis usam R$ 150 |
| Trocar o aluno indicado a partir da 4 | As parcelas anteriores continuam vinculadas ao indicado antigo; as posteriores seguem o novo |
| Editar apenas a descrição a partir da 8 | Atualiza o detalhamento das parcelas elegíveis a partir da 8 e enfileira a atualização Educbank, mantendo os valores |

A edição manual explícita pode alterar parcelas vencidas sem pagamento a partir do número escolhido. Parcelas com baixa integral, parcial ou baixa efetiva de valor zero não são modificadas. Anteriores ao marco informado conservam valores e descrições aplicadas. Cada mudança exige a versão atual do registro e gera auditoria. Reenviar a mesma operação com a mesma chave não aplica o desconto novamente.

**Excluir** continua retirando o desconto das parcelas não vencidas sem pagamentos. Para retirar desde uma parcela específica, use edição/suspensão com o marco desejado. Exclusão mantém o histórico e impede reativação posterior por evento do indicado.

A soma dos descontos não pode ultrapassar o valor original. Falhas revertem a operação inteira. O líquido continua sendo original menos desconto incondicional; a pontualidade permanece separada e só é concedida na baixa válida.

## Indicação

Selecione **Tipo: Indicação**, busque por **RA ou nome** e escolha outro aluno ativo da mesma coligada. O aluno indicado pode ainda estar sem matrícula; nesse caso o benefício aguarda uma matrícula elegível.

A elegibilidade exige pelo menos uma matrícula do indicado com:

- mesma coligada e mesmo período letivo do contrato beneficiado;
- situação exatamente **Cursando**;
- vínculo ativo da matrícula.

Reservado, cancelada, transferência externa, aprovado, reprovado e outros estados não ativam a indicação. Uma matrícula Cursando em outro período não atende à regra. Não é permitido indicar o próprio aluno nem escolher um aluno de outra coligada.

Quando o indicado passa a Cursando, as parcelas não vencidas sem baixa recebem o benefício conforme a faixa e a regra de cada parcela. Quando deixa de Cursando, as parcelas futuras elegíveis perdem o desconto. Uma reativação válida pode restabelecê-lo uma única vez, sem acumular duplicatas. A situação suspensa definida manualmente prevalece na respectiva faixa.

Descontos já adquiridos em parcelas vencidas são mantidos nas transições automáticas, assim como pagamentos. Se for necessário corrigir vencidas sem baixa, a edição manual com marco permite isso. Uma alteração de valor/indicado feita a partir de uma parcela continua respeitada nos recálculos automáticos posteriores, inclusive quando as parcelas ainda não foram geradas.

## Processamento e auditoria

A mudança da matrícula registra a intenção de recálculo na mesma transação. Depois da confirmação, o sistema tenta um lote pequeno em transações próprias por beneficiário. Assim, um conflito em um desconto não desfaz um pagamento válido do indicado.

Há uma fila durável com geração, situação, tentativas e diagnóstico. O cron do WordPress agenda recuperação a cada minuto, condicionado à execução regular do cron do site. **Reprocessar indicações**, no modal do contrato, recoloca o contrato na fila e executa um lote. Não há consultas em loop no navegador.

Se houver desconto acima do limite, dados incompatíveis ou outra falha, as alterações daquele contrato são revertidas e o diagnóstico aparece no modal. Após corrigir, reprocese. Depois de oito tentativas sem solução, o registro pede conferência. Uma nova mudança da matrícula pode gerar uma nova tentativa independente.

As transações preservam a ordem de bloqueio do aluno beneficiado e contrato. A geração da fila é conferida para que um processamento antigo não descarte uma alteração mais recente. Indicações recíprocas não produzem um ciclo de pagamentos. Retiradas são aplicadas antes das adições em uma troca de elegibilidade, evitando excesso intermediário.

Mudanças de valor, descrição e aplicação são auditadas. Os recálculos automáticos usam autor sistema (0), restaurando a sessão de quem iniciou a operação. As mudanças financeiras resultantes entram na fila Educbank mesmo quando originadas no retorno de um pagamento; o recebimento não gera outra cobrança.

## Detalhamento e Educbank

**Ver descontos**, nas parcelas do portal da família e na gestão/ficha, discrimina nome, descrição e valor efetivamente aplicado. O RA/nome do indicado é restrito à gestão; não é incluído automaticamente nesse detalhamento de outra família.

No Educbank, o desconto fixo continua sendo um valor acumulado por item. Os nomes/descrições são enviados em variável extra na criação e na justificativa da atualização, conforme a API permite. A legenda do bloco do parceiro depende do modelo dele; não há endpoint documentado para uma legenda por benefício. Não se altera o identificador do produto para tentar simular essa legenda.

## Estrutura e API

O schema 35 acrescenta descrição, tipo, habilitação, indicado, regras por parcela e controle de verificação em `contrato_descontos`; snapshots em `desconto_lancamentos`; índice de referências em `desconto_indicados`; e fila em `desconto_recalculos`. As FKs conservam a coligada e os vínculos. O histórico pode impedir a exclusão de um aluno indicado referenciado, preservando integridade financeira.

As rotas abaixo usam autenticação WordPress e autorização exclusiva de administrador. Escritas de desconto exigem `Idempotency-Key`.

| Método | Rota relativa a `/erp-educacional/v1` | Finalidade |
|---|---|---|
| GET / POST | `/contratos/ID/descontos` | Consultar/cadastrar benefícios |
| POST | `/descontos/ID/editar` | Alterar com `versao` e `aplicar_a_partir` |
| POST | `/descontos/ID/excluir` | Excluir, preservando histórico |
| GET | `/contratos/ID/indicados?search=RA&page=1` | Seleção de alunos da mesma coligada |
| POST | `/contratos/ID/recalcular-indicacoes` | Recolocar recálculo na fila e executar lote |

As rotas de integração existentes também oferecem `/integracao/descontos/ID/editar`. A consulta financeira inclui `descontos_detalhados` com nomes, descrições, tipos e valores, sem dados do indicado.

## Validação

Testes PHP/SQLite e HTTP simulado cobrem edição por parcela, suspensão, versões, idempotência, rollback, geração posterior, snapshots anteriores, indicação/reativação/cancelamento/estorno, período/coligada, mudança de indicado, pagamento preservado, falha após commit, retentativa, geração concorrente, excesso intermediário e relações recíprocas. Testes de interface cobrem busca, edição, detalhamento, permissões, ausência de HTML injetado e ausência de consultas em loop. Chromium validou gestão e família em 1440, 390 e 320 px.

SQLite não substitui homologação de migração/travas do MySQL nem a entrega real dos serviços WordPress/Educbank. Esta entrega não efetuou alterações nas faturas reais do colégio.
