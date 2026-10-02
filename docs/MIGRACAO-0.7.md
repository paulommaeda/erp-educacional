# Migração 0.7.0 — schema 4 → 5

Migração aditiva executada pelo administrador. Nenhum registro é excluído ou renumerado.

- Nova tabela `{$prefix}erp_planos_pagamento`: PK `idplano`, código único, nome, valor_anuidade DECIMAL(15,2), ativo, versão e timestamps.
- `turmas.idplano`: nullable para cadastros anteriores; FK para planos_pagamento.idplano.
- `contratos.idplano`: nullable para contratos anteriores; FK para planos_pagamento.idplano.
- Contratos recebem `plano_nome_snapshot`, `plano_versao_snapshot`, `primeiro_vencimento`, `parcelas_geradas` e `parcelas_geradas_em`.
- `parcelas_geradas` tem default 1 para preservar o tratamento de contratos anteriores. Novos contratos são inseridos explicitamente com 0 e só passam a 1 após geração bem-sucedida. Contratos antigos não são colocados na fila nem cobrados novamente.
- O schema agora tem 27 tabelas e 53 FKs. As FKs são adicionadas após dbDelta, com RESTRICT e nomes estáveis; dbDelta não é usado para instalar FKs diretamente.
- Plano, valor e quantidade são registrados no contrato antes da geração. Não existe recálculo retroativo por alteração de plano ou transferência de turma.
- A migração instala `erp_financeiro` e `erp_gerar_parcelas`. Atribuição de perfil operacional é exclusiva do administrador. Responsável financeiro do aluno continua sendo `erp_responsavel_financeiro`, sem direito de gerar cobranças.

Após migrar, cadastre os planos e vincule-os às turmas que receberão novas matrículas. Matrículas anteriores sem contrato exigem vincular um plano antes de configurar o contrato. Rematrículas anteriores que já geraram parcelas mantêm essas parcelas; a atualização não as estorna.

Homologação pendente: dbDelta em MySQL/MariaDB e corrida entre duas solicitações de geração. A implementação usa transação, lock do aluno/contrato, idempotência e flag de geração; os testes disponíveis usam SQLite com FKs, sem validar a concorrência MySQL.
