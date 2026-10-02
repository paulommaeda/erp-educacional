# Schema implementado — versão 2

26 tabelas / 51 chaves estrangeiras. Prefixo: `$wpdb->prefix + "erp_"`. InnoDB. FKs RESTRICT.

A nova tabela `aluno_periodos` permite salvar o período antes de selecionar a turma, sem alterar registros existentes.

## pessoas

PK: `codpessoa`

| Campo | Definição SQL |
|---|---|
| `codpessoa` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `nome` | `varchar(191) NOT NULL` |
| `nome_social` | `varchar(191) DEFAULT NULL` |
| `cpf` | `varchar(11) DEFAULT NULL` |
| `data_nascimento` | `date DEFAULT NULL` |
| `email` | `varchar(191) DEFAULT NULL` |
| `telefone` | `varchar(30) DEFAULT NULL` |
| `ativo` | `tinyint(1) NOT NULL DEFAULT 1` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `cpf`

## pessoa_enderecos

PK: `idendereco`

| Campo | Definição SQL |
|---|---|
| `idendereco` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `codpessoa` | `bigint(20) unsigned NOT NULL` |
| `tipo` | `varchar(30) NOT NULL` |
| `cep` | `varchar(10) NOT NULL` |
| `logradouro` | `varchar(191) NOT NULL` |
| `numero` | `varchar(30) NOT NULL` |
| `complemento` | `varchar(191) DEFAULT NULL` |
| `bairro` | `varchar(100) NOT NULL` |
| `cidade` | `varchar(100) NOT NULL` |
| `uf` | `varchar(2) NOT NULL` |
| `pais` | `varchar(2) NOT NULL DEFAULT 'BR'` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- FK: `codpessoa` → `pessoas(codpessoa)`

## pessoa_usuarios

PK: `codpessoa`

| Campo | Definição SQL |
|---|---|
| `codpessoa` | `bigint(20) unsigned NOT NULL` |
| `wp_user_id` | `bigint(20) unsigned NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `wp_user_id`
- FK: `codpessoa` → `pessoas(codpessoa)`

## alunos

PK: `idaluno`

| Campo | Definição SQL |
|---|---|
| `idaluno` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `codpessoa` | `bigint(20) unsigned NOT NULL` |
| `ra` | `varchar(40) NOT NULL` |
| `ativo` | `tinyint(1) NOT NULL DEFAULT 1` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `codpessoa`
- UNIQUE: `ra`
- FK: `codpessoa` → `pessoas(codpessoa)`

## aluno_responsaveis

PK: `idvinculo`

| Campo | Definição SQL |
|---|---|
| `idvinculo` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idaluno` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_responsavel` | `bigint(20) unsigned NOT NULL` |
| `parentesco` | `varchar(30) NOT NULL` |
| `responsavel_academico` | `tinyint(1) NOT NULL DEFAULT 0` |
| `responsavel_financeiro` | `tinyint(1) NOT NULL DEFAULT 0` |
| `pode_rematricular` | `tinyint(1) NOT NULL DEFAULT 0` |
| `inicio_vigencia` | `datetime NOT NULL` |
| `fim_vigencia` | `datetime DEFAULT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- FK: `idaluno` → `alunos(idaluno)`
- FK: `codpessoa_responsavel` → `pessoas(codpessoa)`

## periodos_letivos

PK: `codperiodo`

| Campo | Definição SQL |
|---|---|
| `codperiodo` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `codigo` | `varchar(30) NOT NULL` |
| `descricao` | `varchar(191) NOT NULL` |
| `data_inicio` | `date NOT NULL` |
| `data_fim` | `date NOT NULL` |
| `status` | `varchar(20) NOT NULL DEFAULT 'planejado'` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `codigo`

## cursos

PK: `idcurso`

| Campo | Definição SQL |
|---|---|
| `idcurso` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `codigo` | `varchar(30) NOT NULL` |
| `nome` | `varchar(191) NOT NULL` |
| `descricao` | `text DEFAULT NULL` |
| `ativo` | `tinyint(1) NOT NULL DEFAULT 1` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `codigo`

## turnos

PK: `idturno`

| Campo | Definição SQL |
|---|---|
| `idturno` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `codigo` | `varchar(30) NOT NULL` |
| `nome` | `varchar(100) NOT NULL` |
| `hora_inicio` | `time DEFAULT NULL` |
| `hora_fim` | `time DEFAULT NULL` |
| `ativo` | `tinyint(1) NOT NULL DEFAULT 1` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `codigo`

## turmas

PK: `idturma`

| Campo | Definição SQL |
|---|---|
| `idturma` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `codperiodo` | `bigint(20) unsigned NOT NULL` |
| `idcurso` | `bigint(20) unsigned NOT NULL` |
| `idturno` | `bigint(20) unsigned NOT NULL` |
| `codigo` | `varchar(30) NOT NULL` |
| `nome` | `varchar(100) NOT NULL` |
| `capacidade` | `int unsigned NOT NULL` |
| `status` | `varchar(20) NOT NULL DEFAULT 'ativa'` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `codperiodo, codigo`
- UNIQUE: `idturma, codperiodo, idcurso`
- FK: `codperiodo` → `periodos_letivos(codperiodo)`
- FK: `idcurso` → `cursos(idcurso)`
- FK: `idturno` → `turnos(idturno)`

## matriculas

PK: `idmatricula`

| Campo | Definição SQL |
|---|---|
| `idmatricula` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idaluno` | `bigint(20) unsigned NOT NULL` |
| `codperiodo` | `bigint(20) unsigned NOT NULL` |
| `idcurso` | `bigint(20) unsigned NOT NULL` |
| `idturma_atual` | `bigint(20) unsigned NOT NULL` |
| `idmatricula_origem` | `bigint(20) unsigned DEFAULT NULL` |
| `data_matricula` | `date NOT NULL` |
| `status` | `varchar(20) NOT NULL DEFAULT 'ativa'` |
| `origem` | `varchar(30) NOT NULL` |
| `encerrado_em` | `datetime DEFAULT NULL` |
| `motivo_encerramento` | `text DEFAULT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idaluno, codperiodo, idcurso`
- FK: `idaluno` → `alunos(idaluno)`
- FK: `codperiodo` → `periodos_letivos(codperiodo)`
- FK: `idcurso` → `cursos(idcurso)`
- FK: `idturma_atual, codperiodo, idcurso` → `turmas(idturma, codperiodo, idcurso)`
- FK: `idmatricula_origem` → `matriculas(idmatricula)`

## matricula_movimentacoes

PK: `idmovimentacao`

| Campo | Definição SQL |
|---|---|
| `idmovimentacao` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idmatricula` | `bigint(20) unsigned NOT NULL` |
| `tipo` | `varchar(30) NOT NULL` |
| `idturma_origem` | `bigint(20) unsigned DEFAULT NULL` |
| `idturma_destino` | `bigint(20) unsigned DEFAULT NULL` |
| `status_anterior` | `varchar(20) DEFAULT NULL` |
| `status_novo` | `varchar(20) NOT NULL` |
| `efetivado_em` | `datetime NOT NULL` |
| `registrado_em` | `datetime NOT NULL` |
| `motivo` | `text NOT NULL` |
| `ator_wp_user_id` | `bigint(20) unsigned NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- FK: `idmatricula` → `matriculas(idmatricula)`
- FK: `idturma_origem` → `turmas(idturma)`
- FK: `idturma_destino` → `turmas(idturma)`

## ofertas_rematricula

PK: `idoferta`

| Campo | Definição SQL |
|---|---|
| `idoferta` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `codperiodo_destino` | `bigint(20) unsigned NOT NULL` |
| `idcurso_origem` | `bigint(20) unsigned NOT NULL` |
| `idcurso_destino` | `bigint(20) unsigned NOT NULL` |
| `abertura_em` | `datetime NOT NULL` |
| `encerramento_em` | `datetime NOT NULL` |
| `versao_termo` | `varchar(40) NOT NULL` |
| `texto_termo` | `longtext NOT NULL` |
| `valor_total` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `numero_parcelas` | `int unsigned NOT NULL` |
| `primeiro_vencimento` | `date NOT NULL` |
| `dia_vencimento` | `tinyint unsigned NOT NULL` |
| `ativo` | `tinyint(1) NOT NULL DEFAULT 1` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- FK: `codperiodo_destino` → `periodos_letivos(codperiodo)`
- FK: `idcurso_origem` → `cursos(idcurso)`
- FK: `idcurso_destino` → `cursos(idcurso)`

## oferta_turmas

PK: `idoferta, idturma`

| Campo | Definição SQL |
|---|---|
| `idoferta` | `bigint(20) unsigned NOT NULL` |
| `idturma` | `bigint(20) unsigned NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- FK: `idoferta` → `ofertas_rematricula(idoferta)`
- FK: `idturma` → `turmas(idturma)`

## rematriculas

PK: `idrematricula`

| Campo | Definição SQL |
|---|---|
| `idrematricula` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idoferta` | `bigint(20) unsigned NOT NULL` |
| `idmatricula_origem` | `bigint(20) unsigned NOT NULL` |
| `idmatricula_destino` | `bigint(20) unsigned DEFAULT NULL` |
| `codpessoa_solicitante` | `bigint(20) unsigned NOT NULL` |
| `idempotencia` | `varchar(100) NOT NULL` |
| `status` | `varchar(20) NOT NULL` |
| `termo_versao` | `varchar(40) NOT NULL` |
| `termo_hash` | `varchar(64) NOT NULL` |
| `aceito_em` | `datetime NOT NULL` |
| `concluido_em` | `datetime DEFAULT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idempotencia`
- UNIQUE: `idoferta, idmatricula_origem`
- FK: `idoferta` → `ofertas_rematricula(idoferta)`
- FK: `idmatricula_origem` → `matriculas(idmatricula)`
- FK: `idmatricula_destino` → `matriculas(idmatricula)`
- FK: `codpessoa_solicitante` → `pessoas(codpessoa)`

## contratos

PK: `idcontrato`

| Campo | Definição SQL |
|---|---|
| `idcontrato` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `numero` | `varchar(50) NOT NULL` |
| `idmatricula` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_rf_original` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_rf_atual` | `bigint(20) unsigned NOT NULL` |
| `data_contrato` | `date NOT NULL` |
| `valor_original_total` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `desconto_incondicional_total` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `valor_liquido_total` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `quantidade_parcelas` | `int unsigned NOT NULL` |
| `moeda` | `varchar(3) NOT NULL DEFAULT 'BRL'` |
| `status` | `varchar(20) NOT NULL DEFAULT 'ativo'` |
| `versao_termo` | `varchar(40) NOT NULL` |
| `termo_snapshot` | `longtext NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `numero`
- FK: `idmatricula` → `matriculas(idmatricula)`
- FK: `codpessoa_rf_original` → `pessoas(codpessoa)`
- FK: `codpessoa_rf_atual` → `pessoas(codpessoa)`

## parcelas

PK: `idparcela`

| Campo | Definição SQL |
|---|---|
| `idparcela` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idcontrato` | `bigint(20) unsigned NOT NULL` |
| `numero` | `int unsigned NOT NULL` |
| `competencia` | `date NOT NULL` |
| `vencimento_original` | `date NOT NULL` |
| `valor_original` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `desconto_incondicional` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `valor_liquido` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `desconto_condicional_previsto` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `limite_desconto_condicional` | `date DEFAULT NULL` |
| `regra_desconto_condicional` | `longtext NOT NULL` |
| `taxa_juros_mensal` | `decimal(9,6) NOT NULL DEFAULT 0` |
| `percentual_multa` | `decimal(9,6) NOT NULL DEFAULT 0` |
| `regra_encargos` | `longtext NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idcontrato, numero`
- FK: `idcontrato` → `contratos(idcontrato)`

## lancamentos

PK: `idlancamento`

| Campo | Definição SQL |
|---|---|
| `idlancamento` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idparcela` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_rf_original` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_rf_atual` | `bigint(20) unsigned NOT NULL` |
| `descricao` | `varchar(191) NOT NULL` |
| `emissao` | `date NOT NULL` |
| `vencimento` | `date NOT NULL` |
| `valor_original` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `desconto_incondicional` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `valor_liquido` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `desconto_condicional_aplicado` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `juros_aplicados` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `multa_aplicada` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `valor_baixa` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `saldo_aberto` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `status` | `varchar(20) NOT NULL DEFAULT 'aberto'` |
| `cancelado_em` | `datetime DEFAULT NULL` |
| `motivo_cancelamento` | `text DEFAULT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idparcela`
- FK: `idparcela` → `parcelas(idparcela)`
- FK: `codpessoa_rf_original` → `pessoas(codpessoa)`
- FK: `codpessoa_rf_atual` → `pessoas(codpessoa)`

## baixas

PK: `idbaixa`

| Campo | Definição SQL |
|---|---|
| `idbaixa` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idlancamento` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_devedor` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_pagador` | `bigint(20) unsigned DEFAULT NULL` |
| `data_pagamento` | `date NOT NULL` |
| `registrado_em` | `datetime NOT NULL` |
| `valor_pago` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `principal_liquidado` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `juros_pagos` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `multa_paga` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `desconto_condicional_concedido` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `forma_pagamento` | `varchar(30) NOT NULL` |
| `referencia_externa` | `varchar(100) DEFAULT NULL` |
| `idempotencia` | `varchar(100) NOT NULL` |
| `ator_wp_user_id` | `bigint(20) unsigned NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idempotencia`
- FK: `idlancamento` → `lancamentos(idlancamento)`
- FK: `codpessoa_devedor` → `pessoas(codpessoa)`
- FK: `codpessoa_pagador` → `pessoas(codpessoa)`

## baixa_estornos

PK: `idestorno`

| Campo | Definição SQL |
|---|---|
| `idestorno` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idbaixa` | `bigint(20) unsigned NOT NULL` |
| `valor` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `motivo` | `text NOT NULL` |
| `efetivado_em` | `datetime NOT NULL` |
| `ator_wp_user_id` | `bigint(20) unsigned NOT NULL` |
| `idempotencia` | `varchar(100) NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idempotencia`
- FK: `idbaixa` → `baixas(idbaixa)`

## lancamento_ajustes

PK: `idajuste`

| Campo | Definição SQL |
|---|---|
| `idajuste` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idlancamento` | `bigint(20) unsigned NOT NULL` |
| `componente` | `varchar(40) NOT NULL` |
| `valor_delta` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `motivo` | `text NOT NULL` |
| `efetivado_em` | `datetime NOT NULL` |
| `ator_wp_user_id` | `bigint(20) unsigned NOT NULL` |
| `idempotencia` | `varchar(100) NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idempotencia`
- FK: `idlancamento` → `lancamentos(idlancamento)`

## trocas_responsavel

PK: `idtroca`

| Campo | Definição SQL |
|---|---|
| `idtroca` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idaluno` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_anterior` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_nova` | `bigint(20) unsigned NOT NULL` |
| `efetivado_em` | `datetime NOT NULL` |
| `motivo` | `text NOT NULL` |
| `ator_wp_user_id` | `bigint(20) unsigned NOT NULL` |
| `idempotencia` | `varchar(100) NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idempotencia`
- FK: `idaluno` → `alunos(idaluno)`
- FK: `codpessoa_anterior` → `pessoas(codpessoa)`
- FK: `codpessoa_nova` → `pessoas(codpessoa)`

## troca_contratos

PK: `idtroca, idcontrato`

| Campo | Definição SQL |
|---|---|
| `idtroca` | `bigint(20) unsigned NOT NULL` |
| `idcontrato` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_anterior` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_nova` | `bigint(20) unsigned NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- FK: `idtroca` → `trocas_responsavel(idtroca)`
- FK: `idcontrato` → `contratos(idcontrato)`
- FK: `codpessoa_anterior` → `pessoas(codpessoa)`
- FK: `codpessoa_nova` → `pessoas(codpessoa)`

## troca_lancamentos

PK: `idtroca, idlancamento`

| Campo | Definição SQL |
|---|---|
| `idtroca` | `bigint(20) unsigned NOT NULL` |
| `idlancamento` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_anterior` | `bigint(20) unsigned NOT NULL` |
| `codpessoa_nova` | `bigint(20) unsigned NOT NULL` |
| `saldo_transferido` | `decimal(15,2) NOT NULL DEFAULT 0.00` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- FK: `idtroca` → `trocas_responsavel(idtroca)`
- FK: `idlancamento` → `lancamentos(idlancamento)`
- FK: `codpessoa_anterior` → `pessoas(codpessoa)`
- FK: `codpessoa_nova` → `pessoas(codpessoa)`

## auditoria

PK: `idauditoria`

| Campo | Definição SQL |
|---|---|
| `idauditoria` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `entidade` | `varchar(50) NOT NULL` |
| `entidade_id` | `bigint(20) unsigned NOT NULL` |
| `acao` | `varchar(50) NOT NULL` |
| `antes_json` | `longtext DEFAULT NULL` |
| `depois_json` | `longtext DEFAULT NULL` |
| `ator_wp_user_id` | `bigint(20) unsigned NOT NULL` |
| `ocorrido_em` | `datetime NOT NULL` |
| `correlacao_id` | `varchar(100) NOT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |


## operacoes

PK: `idoperacao`

| Campo | Definição SQL |
|---|---|
| `idoperacao` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `chave` | `varchar(100) NOT NULL` |
| `tipo` | `varchar(50) NOT NULL` |
| `request_hash` | `varchar(64) NOT NULL` |
| `resposta_json` | `longtext DEFAULT NULL` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `chave`

## aluno_periodos

PK: `idvinculoperiodo`

| Campo | Definição SQL |
|---|---|
| `idvinculoperiodo` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `idaluno` | `bigint(20) unsigned NOT NULL` |
| `codperiodo` | `bigint(20) unsigned NOT NULL` |
| `idmatricula` | `bigint(20) unsigned DEFAULT NULL` |
| `status` | `varchar(30) NOT NULL DEFAULT 'aguardando_turma'` |
| `criado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `atualizado_em` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `versao` | `bigint(20) unsigned NOT NULL DEFAULT 1` |

- UNIQUE: `idaluno, codperiodo`
- UNIQUE: `idmatricula`
- FK: `idaluno` → `alunos(idaluno)`
- FK: `codperiodo` → `periodos_letivos(codperiodo)`
- FK: `idmatricula` → `matriculas(idmatricula)`
