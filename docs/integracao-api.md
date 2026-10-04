# APIs de integração — versão 0.10.0

Base: `https://SEU-DOMINIO/wp-json/erp-educacional/v1/integracao`.

Estas APIs permitem que outro sistema leia e grave dados no ERP. GET entrega JSON ao consumidor. POST/PATCH recebe JSON. Esta versão não envia webhooks automaticamente.

## Autenticação e permissões

Use HTTPS e uma **Senha de Aplicativo do WordPress**, gerada no perfil do administrador em Usuários → Perfil → Senhas de aplicativo. Envie `Authorization: Basic BASE64(usuario:senha_de_aplicativo)`. Não use a senha pessoal nem a data de nascimento. Sessões no próprio WordPress também podem usar cookie e `X-WP-Nonce`.

Nesta versão, todas as rotas `/integracao` exigem administrador WordPress, mantendo a regra de edição exclusiva do administrador e protegendo os dados completos de outros alunos. Usuários aluno/responsável/secretaria não têm acesso global às rotas. Senhas, hashes e tokens de documentos privados não são retornados.

POST/PATCH exigem `Content-Type: application/json` e `Idempotency-Key` (16–100 caracteres: letras, números, `_`, `-`). Gere uma chave nova por operação e reutilize a mesma chave e corpo ao repetir após falha de conexão. Alterações com PATCH exigem a `versao` obtida na consulta mais recente. Transferências/resultados/cancelamentos também devem enviar versão. Operações ficam auditadas com usuário, data/hora e mudanças.

## Consultas

| Método / rota | Dados retornados |
|---|---|
| GET `/alunos` | Pessoas dos alunos, dados cadastrais completos, todos os vínculos e pessoas completas do pai, mãe e outros |
| GET `/alunos/{idaluno}` | Mesmo formato para um aluno |
| GET `/matriculas` | Matrícula, aluno e família, período, curso, turma atual, turno, movimentos, contratos, parcelas, lançamentos, baixas, estornos e ajustes |
| GET `/matriculas/{idmatricula}` | Mesmo formato para uma matrícula |
| GET `/financeiro` | Um item por lançamento: valores, situação, baixas/estornos/ajustes, parcela, contrato, devedor atual, aluno e matrícula com turma/curso/turno |
| GET `/financeiro/{idlancamento}` | Mesmo formato para um lançamento |

Coleções: `cursor=0`, `limit=20` (máximo 50). Quando `next_cursor` não for null, faça a próxima consulta com esse cursor e os mesmos filtros. Sem `codperiodo`, a integração consulta todos os períodos; essa regra não altera o período vigente do Portal dos responsáveis.

Filtros: todos aceitam `idaluno` e `codperiodo`; alunos também aceitam `ra` exato; matrículas e financeiro aceitam `idturma`. Financeiro também aceita `status=em_aberto|vencido|baixado|cancelado`, `vencimento_de=AAAA-MM-DD` e `vencimento_ate=AAAA-MM-DD`. A turma no financeiro é a turma atual da matrícula; o lançamento acompanha o aluno após transferência interna.

Resposta de coleção: `{ "schema_version": "1.0", "gerado_em": "...", "items": [...], "next_cursor": "..." ou null, "limit": 20 }`. Detalhe: `{ "schema_version": "1.0", "gerado_em": "...", "item": {...} }`.

`aluno.pessoa` contém os dados da pessoa do aluno. `vinculos` contém vínculos atuais e históricos, com `vigente` e `pessoa` completa. `pai`, `mae` e `outros` são listas somente dos vínculos vigentes, sem pressupor apenas um pai/mãe. Responsabilidades financeira/acadêmica são atribuições independentes do parentesco.

As relações usam IDs internos do ERP: `codpessoa`, `idaluno`, `idmatricula` e `idlancamento`. O código público/importado fica em `pessoa.codigo_pessoa` e o RA em `aluno.ra`; eles não precisam ser iguais. IDs do banco podem aparecer como strings; RA/código da pessoa devem permanecer strings para preservar zeros. Datas de negócio: `AAAA-MM-DD`. Valores monetários: strings decimais, por exemplo `"1500.00"`. Situação financeira é calculada na consulta; liquidação parcial fica em aberto/vencida pelo saldo, e `status_liquidacao` preserva o estado interno. Campos técnicos `criado_em`/`atualizado_em` são UTC no formato `AAAA-MM-DD HH:MM:SS`.

## Recebimento e alterações

| Método / rota | Operação |
|---|---|
| POST `/pessoas` | Cadastra pessoa e usuário WordPress |
| PATCH `/pessoas/{codpessoa}` | Edita pessoa com `versao`; mesmos campos do cadastro |
| POST `/alunos` | Cria aluno e opcionalmente pessoas/vínculos familiares em uma única transação |
| PATCH `/alunos/{idaluno}` | Edita `ra`, `tipo_aluno`, `ativo` com `versao`; não substitui a pessoa |
| POST `/alunos/{idaluno}/vinculos` | Cria/atualiza vínculo com pessoa existente, `parentesco` e atribuições |
| POST `/alunos/{idaluno}/responsavel-financeiro` | Troca devedor e transfere saldos abertos, com `codpessoa_nova` e `motivo` |
| POST `/matriculas` | Matrícula inicial + contrato + parcelas conforme plano da turma |
| POST `/matriculas/{idmatricula}/transferencia` | Transferência interna: `idturma_destino`, `motivo`, `versao` |
| POST `/matriculas/{idmatricula}/cancelamento` | Cancela matrícula e saldos não vencidos; `versao` |
| POST `/matriculas/{idmatricula}/resultado` | `status=aprovado|reprovado`, `versao`; aplica regra da rematrícula por reprovação |
| POST `/financeiro` | Gera lançamentos de contrato pendente existente: `idcontrato` |
| PATCH `/financeiro/{idlancamento}` | `vencimento` e/ou `valor_original`, `motivo`, `versao` |
| POST `/financeiro/editar-lote` | Até 100 lançamentos, cada um com ID/versão, `alteracoes` e `motivo` |
| POST `/financeiro/{idlancamento}/baixas` | Registra `valor_pago`, `data_pagamento`, `forma_pagamento`; demais campos financeiros disponíveis |
| POST `/baixas/{idbaixa}/estorno` | Estorno integral da baixa, com `motivo` |
| POST `/contratos/{idcontrato}/parcelas` | Alternativa para gerar parcelas de contrato pendente |

Financeiro recebe alterações, pagamentos e geração de cobranças conforme contratos. Não insira saldos diretamente nem envie contratos/lançamentos prontos para substituir registros: os serviços calculam valores e preservam as regras do ERP. Nova matrícula nasce reservada; primeira parcela integralmente baixada muda para cursando. Na rematrícula, o fluxo existente do Portal continua gerando as parcelas posteriormente.

### Criar aluno e vínculos completos

```json
{
  "pessoa": {"nome":"ALUNO EXEMPLO", "data_nascimento":"2015-04-12", "rg":"123456", "email":"aluno@example.com", "pais":"BR"},
  "aluno": {"ra":"000123", "tipo_aluno":"REGULAR"},
  "vinculos": [
    {"pessoa":{"nome":"PAI EXEMPLO", "data_nascimento":"1980-01-01", "pais":"BR"}, "parentesco":"pai", "responsavel_financeiro":true, "responsavel_academico":true, "pode_rematricular":true},
    {"codpessoa_responsavel":"42", "parentesco":"mae", "responsavel_academico":true}
  ]
}
```

Para usar pessoa já cadastrada como aluno, substitua `pessoa` por `codpessoa`. Para cada vínculo, use pessoa nova ou `codpessoa_responsavel`. Máximo 20 vínculos. Uma falha reverte todo o cadastro. Se houver homônimo sem login automático disponível, a API retorna `erp_username_required` (409); escolha `user_login` no objeto da pessoa e reenvie com nova chave.

Os campos pessoais também incluem CPF, telefone, sexo, endereço (`rua`, `numero`, `complemento`, `bairro`, `cep`, `cidade`, `estado`, `pais` ISO), `idestado_civil`, profissão, religião, igreja e `foto_attachment_id` por ID de anexo já existente. Códigos de origem importados são informados em `codpessoa_origem`, conforme importação atual. Consulte o OpenAPI para o nome exato do campo da foto e a lista de campos. Nomes e campos cadastrais seguem a normalização do ERP; e-mail permanece com seu formato próprio.

### Matrícula inicial

```json
{"idaluno":"123", "idturma":"8", "quantidade_parcelas":12, "primeiro_vencimento":"2027-01-10", "desconto_incondicional_total":"0.00"}
```

Período, curso, turno e plano são derivados da turma; anuidade é derivada do plano. O aluno precisa de responsável financeiro vigente. Resposta contém `idmatricula` e `idcontrato`.

### Alteração em lote

```json
{"lancamentos":[{"idlancamento":"1", "versao":"3"},{"idlancamento":"2", "versao":"1"}], "alteracoes":{"vencimento":"2027-02-15", "valor_original":"250.00"}, "motivo":"RENEGOCIAÇÃO"}
```

### Consumir a lista

```bash
curl --user "$ERP_USER:$ERP_APP_PASSWORD" \
  'https://SEU-DOMINIO/wp-json/erp-educacional/v1/integracao/alunos?limit=20&cursor=0'
```

Códigos HTTP: 200 sucesso; 401 sem autenticação; 403 sem permissão; 409 escolha de login; 422 dados/regra/versão inválidos; 503 migração pendente; 500 persistência/operação falhou. Não há atualização parcial silenciosa do pacote aluno/família nem do lote financeiro. A resposta não inclui senhas dos usuários criados.

## Bolsas e descontos (0.10.2)

GET/POST `/contratos/{idcontrato}/descontos` consultam ou cadastram múltiplos descontos com `nome`, `valor` fixo por parcela, `parcela_inicio` e `parcela_fim`. POST `/descontos/{iddesconto}/excluir` exige `versao` e desativa o desconto, recalculando apenas parcelas não vencidas sem pagamentos. Descontos aparecem dentro do contrato nas consultas de matrículas e na exportação.

Lançamentos agora incluem `desconto_condicional`, `desconto_incondicional`, `valor_liquido`, `valor_baixa`, `valor_liquido_contratual` e `saldo_a_pagar`. O desconto condicional é previsto até o vencimento e aplicado efetivamente na quitação pontual; use `saldo_a_pagar` na baixa. Baixa registra apenas dinheiro recebido. O valor da pontualidade é configurado pelo administrador em Configurações, válido para contratos existentes e novos.

Correção 0.10.3: `valor_liquido` é original menos descontos incondicionais, sem pontualidade. `valor_com_pontualidade` e `saldo_a_pagar` expõem o benefício separadamente. Número do contrato: RA + código do período letivo.
