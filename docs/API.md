> As regras da seção 0.7.0 ao final substituem os exemplos anteriores de valor manual, matrícula somente acadêmica e geração automática na rematrícula.

# API — versão 0.5.0

Base: `https://SEU-SITE/wp-json/erp-educacional/v1`

Todas as rotas são autenticadas e verificam capabilities ou vínculos. Não há endpoint público de consulta por CPF. A exportação completa é exclusiva da capability `erp_exportar_dados`.

## Novo fluxo da Secretaria

Os endpoints abaixo também exigem autenticação, permissão e Idempotency-Key em POST. Os endpoints anteriores continuam disponíveis.

| Método | Rota | Uso |
|---|---|---|
| GET | `/secretaria/alunos?search=Sofia&page=1` | Lista com nome e RA |
| POST | `/secretaria/alunos` | Cria pessoa+aluno atomicamente, ou reutiliza codpessoa |
| GET | `/secretaria/alunos/{id}` | Ficha com pessoa, responsáveis, matrículas e períodos pendentes |
| POST | `/pessoas/{id}` | Edita nome, CPF, nascimento, e-mail e telefone |
| GET | `/opcoes/pessoas?search=Carlos&page=1` | Busca de pessoas para vínculo |
| GET | `/opcoes/periodos_letivos`, `/opcoes/cursos`, `/opcoes/turnos` | Seletores pesquisáveis |
| GET | `/opcoes/turmas?codperiodo=7&page=1` | Turmas ativas do período; filtro opcional idcurso |
| POST | `/alunos/{id}/periodos` | Salva vínculo Aguardando turma |
| POST | `/alunos/{id}/periodos/{vinculo}/turma` | Conclui matrícula acadêmica pela turma |
| POST | `/matriculas/{id}/contrato` | Cria contrato e parcelas posteriormente |

As rotas de pessoa/ficha exigem `erp_gerenciar_pessoas`; as de período/turma/contrato exigem `erp_gerenciar_academico`. A ficha só inclui os dados acadêmicos se o usuário também tiver essa capability.

Novo aluno:

```json
{"nome":"Sofia Silva","ra":"000045","data_nascimento":"2015-04-12"}
```

Reutilizar pessoa já cadastrada:

```json
{"codpessoa":"10","ra":"000045"}
```

Vincular período (`POST /alunos/1/periodos`):

```json
{"codperiodo":"7"}
```

Resposta:

```json
{"idvinculoperiodo":"5","status":"aguardando_turma"}
```

Confirmar turma (`POST /alunos/1/periodos/5/turma`):

```json
{"idturma":"9"}
```

Resposta:

```json
{"idmatricula":"3","idturma":"9"}
```

A operação não exige valores financeiros nem gera cobranças. O contrato posterior recebe o mesmo corpo financeiro do endpoint de matrícula original, sem idaluno/idturma. Requer responsável financeiro e rejeita nova geração se a matrícula já tiver contrato.

O POST de responsáveis agora permite editar atribuições de uma pessoa já vinculada, preservando vigências. Troca de devedor deve continuar usando a operação financeira específica.

A exportação acrescenta o campo `periodos_pendentes`, filtrado pelo período solicitado. São vínculos preparatórios, não matrículas concluídas.

## Autenticação

No portal, o plugin usa cookie WordPress e cabeçalho `X-WP-Nonce`.

Para integrações, use um usuário dedicado com Application Password por HTTPS e apenas as capabilities necessárias. Não reutilize a senha principal do administrador.

Exemplo de exportação com curl (a senha de aplicativo será solicitada):

```bash
curl --user USUARIO_INTEGRACAO \
  'https://SEU-SITE/wp-json/erp-educacional/v1/exportacao/alunos/123?codperiodo=1'
```

`codperiodo` é a PK do período, não necessariamente o ano/código textual `2026`.

## Rotas implementadas

| Método | Rota | Permissão |
|---|---|---|
| GET | `/me` e `/me/alunos` | Conta autenticada; registros vinculados |
| GET/POST | `/cadastros/pessoas` e `/cadastros/alunos` | `erp_gerenciar_pessoas` |
| GET/POST | `/cadastros/periodos_letivos`, `/cursos`, `/turnos`, `/turmas` (todos sob `/cadastros/`) | `erp_gerenciar_academico` |
| POST | `/pessoa-usuarios` | `erp_configurar` |
| GET/POST | `/alunos/{id}/responsaveis` | `erp_gerenciar_pessoas` |
| POST | `/matriculas` | `erp_gerenciar_academico` |
| POST | `/matriculas/{id}/transferencias` | `erp_gerenciar_academico` |
| POST | `/alunos/{id}/trocas-responsavel-financeiro` | `erp_trocar_responsavel_financeiro` |
| POST | `/lancamentos/{id}/baixas` | `erp_baixar_lancamentos` |
| POST | `/lancamentos/{id}/ajustes` | `erp_ajustar_lancamentos` |
| POST | `/baixas/{id}/estornos` | `erp_estornar_baixas` |
| POST | `/ofertas-rematricula` | `erp_gerenciar_academico` |
| GET | `/alunos/{id}/ofertas-rematricula` | Vínculo autorizado a rematricular ou gestão acadêmica |
| POST | `/rematriculas` | Pessoa vinculada e autorização para o aluno verificada no caso de uso |
| GET | `/alunos/{id}/matriculas` | Aluno, responsável acadêmico ou gestão acadêmica |
| GET | `/alunos/{id}/financeiro` | Responsável financeiro ou consulta financeira administrativa |
| GET | `/exportacao/alunos/{id}?codperiodo=1` | `erp_exportar_dados` |
| GET | `/exportacao/alunos?codperiodo=1&cursor=0&limit=10` | `erp_exportar_dados` |

Listas de cadastros usam `?page=1&search=texto`, com 20 registros por página. A busca de pessoas é pelo nome; a busca do cadastro de alunos é pelo RA. Exportação em lote usa cursor de ID de aluno e limite máximo de 20. O lote é uma leitura consistente; páginas distintas não constituem uma fotografia global de toda a base.

## POST: formato comum

```
Content-Type: application/json
Idempotency-Key: operacao-uuid-unico-12345678
```

Uma chave identifica uma única operação e usuário. A mesma chave não pode ser reaproveitada em endpoints ou corpos diferentes. O recibo é persistido na mesma transação da operação. Em falha, ambos são revertidos.

### Criar pessoa

`POST /cadastros/pessoas`

```json
{"nome":"Pessoa de Exemplo","cpf":null,"email":"exemplo@example.com"}
```

### Criar aluno

`POST /cadastros/alunos`

```json
{"codpessoa":"1","ra":"000123"}
```

### Vincular responsável

`POST /alunos/1/responsaveis`

```json
{
  "codpessoa_responsavel":"2",
  "parentesco":"mae",
  "responsavel_academico":1,
  "responsavel_financeiro":1,
  "pode_rematricular":1
}
```

### Matrícula inicial

`POST /matriculas`

```json
{
  "idaluno":"1",
  "idturma":"1",
  "valor_original_total":"12000.00",
  "desconto_incondicional_total":"1200.00",
  "quantidade_parcelas":12,
  "primeiro_vencimento":"2027-01-10",
  "versao_termo":"1",
  "termo":"Texto integral do contrato."
}
```

Curso, período e turno derivam da turma. O responsável financeiro deriva do vínculo vigente. Esses dados não são aceitos livremente do navegador.

### Transferência

`POST /matriculas/1/transferencias`

```json
{"idturma_destino":"2","motivo":"Solicitação do responsável."}
```

### Troca de responsável

`POST /alunos/1/trocas-responsavel-financeiro`

```json
{"codpessoa_nova":"3","motivo":"Alteração formal de responsabilidade."}
```

A pessoa 3 deve estar previamente vinculada ao aluno. Esta operação transfere automaticamente os saldos aplicáveis dos contratos do aluno, não apenas de uma turma ou período.

### Baixa parcial

`POST /lancamentos/1/baixas`

```json
{
  "valor_pago":"400.00",
  "data_pagamento":"2026-09-28",
  "forma_pagamento":"pix",
  "codpessoa_pagador":"2",
  "referencia_externa":"RECIBO-001"
}
```

Formas aceitas: `pix`, `boleto`, `cartao`, `dinheiro`, `transferencia`, `outro`.
Não existe processamento de pagamento ou gateway nesta versão. A baixa é um registro administrativo de valor já recebido.

### Ajuste

`POST /lancamentos/1/ajustes`

```json
{"componente":"juros_aplicados","valor_delta":"10.00","motivo":"Encargos conferidos pela tesouraria."}
```

Componentes: `desconto_incondicional`, `desconto_condicional_aplicado`, `juros_aplicados`, `multa_aplicada`. Reduções podem usar valor negativo, respeitando os valores já liquidados e a não negatividade do saldo/componentes.

### Estorno integral

`POST /baixas/1/estornos`

```json
{"motivo":"Recebimento registrado indevidamente."}
```

### Oferta e rematrícula

`POST /ofertas-rematricula`

```json
{
  "codperiodo_destino":"2",
  "idcurso_origem":"1",
  "idcurso_destino":"1",
  "data_abertura":"2026-10-01",
  "data_encerramento":"2026-12-20",
  "valor_total":"12000.00",
  "numero_parcelas":12,
  "primeiro_vencimento":"2027-01-10",
  "versao_termo":"1",
  "texto_termo":"Termo de renovação integral.",
  "turmas":["3","4"]
}
```

`POST /rematriculas` (logado como responsável autorizado):

```json
{
  "idoferta":"1",
  "idmatricula_origem":"1",
  "idturma":"3",
  "versao_termo":"1",
  "aceite":true
}
```

Preço e número de parcelas são lidos da oferta no servidor.

## Exportação

`exportacao-exemplo.json` foi gerado pelo próprio `ExportService` com dados fictícios dos testes. Datas UTC são ISO 8601; datas civis/vencimentos são `YYYY-MM-DD`.

Registros de responsáveis incluem vigências históricas, não apenas os atuais. Matrículas são filtradas pelo período explícito. Contratos contêm parcelas; cada parcela contém seu lançamento, baixas, estornos, ajustes e trocas.

## Erros

- 401: autenticação ausente.
- 403: usuário sem acesso.
- 422: regra de negócio ou entrada inválida, inclusive idempotência divergente.
- 500: falha de persistência/duplicidade não classificada; a operação é revertida.
- 503: schema não instalado, migração incompleta ou falha de migração.

Não são retornados SQL, credenciais ou detalhes internos de exceções. Requisições repetidas após falha de rede devem reutilizar a chave e corpo originais.

## Referências técnicas

- https://developer.wordpress.org/reference/functions/dbdelta/
- https://developer.wordpress.org/plugins/creating-tables-with-plugins/
- https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
- https://developer.wordpress.org/reference/functions/register_rest_route/


## Cadastro de pessoas e contas — 0.5.0

POST `/cadastros/pessoas` exige `nome` e `data_nascimento` (AAAA-MM-DD). Aceita `user_login` opcional para escolha manual. Retorna `id`, `wp_user_id`, `user_login`; nunca retorna senha. Sem combinação disponível, retorna HTTP 409, código `erp_username_required`, com `field=user_login`; mantenha o formulário e solicite o nome de usuário. Ao alterar o payload, use nova chave de idempotência.

POST `/cadastros/{tabela}/{id}` edita `pessoas`, `alunos`, `periodos_letivos`, `cursos`, `turnos`, `turmas`. Somente administrador WordPress; aceita `versao` para detectar edição concorrente. POST `/pessoas/{id}` segue a mesma restrição. Alterar vínculo existente exige administrador no serviço. Transferência de turma exige administrador. Criação permanece disponível à Secretaria conforme capabilities.

GET `/cadastros/pessoas` inclui `conta` com login, perfis e situação e `idaluno` quando existente. GET `/secretaria/alunos/{id}` também inclui `conta`. POST `/contas/sincronizar` (administrador) recebe `{ "cursor": 0 }`, processa até 20 pessoas e retorna `items` e `next_cursor`. Repita enquanto houver próximo cursor. Pessoas sem dados suficientes ficam pendentes para correção.

O fluxo da tela de aluno envia `{ "codpessoa": "1", "ra": "000123" }` a POST `/secretaria/alunos`. O formato integrado anterior continua aceito por compatibilidade, também exigindo nascimento para uma nova pessoa.

## Importação e campos — 0.5.0

POST `/importacao/pessoas` e POST `/importacao/alunos`: administrador WordPress, nonce ou autenticação REST e Idempotency-Key. Um objeto por requisição, uma transação por registro. Pessoas exigem codpessoa_origem, nome e data_nascimento; alunos exigem codpessoa_origem e ra. Repetição é reconhecida pelo código de origem e combinação pessoa/RA, sem sobrescrever dados existentes. Resposta contém status criado/existente e id; falhas preservam a linha anterior e não interrompem outras requisições.

`pessoas` aceita codpessoa_origem, rg, rua, numero, complemento, bairro, cep, cidade, estado, pais (ISO alpha-2), estado_civil, profissao, religiao, igreja, sexo, foto_attachment_id. Cidades brasileiras são nomes oficiais e estados são UF. `alunos` aceita tipo_aluno Regular/AEE. Os dados estão nas entidades retornadas pela exportação autenticada. Foto guarda referência à mídia WordPress; nenhuma chamada é feita ao serviço Gravatar.

## Portal e acesso — 0.5.0

GET `/painel`: indicadores das áreas autorizadas e alunos vinculados. GET `/me/perfil`: pessoa da conta autenticada e dados básicos de acesso. POST `/me/perfil`: somente campos pessoais autorizados + versao, com chave de idempotência; não recebe codpessoa/RA/roles. POST `/me/foto`: multipart no campo foto; autenticação REST, conta vinculada ativa, raster JPG/PNG/WebP até 5 MB e 4096 pixels por dimensão.

GET `/perfis`, POST `/perfis` (nome), POST `/perfis/menus` (role, menus), GET `/perfis/pessoa/{id}` e POST `/perfis/atribuicao` (codpessoa, role, acao adicionar/remover): somente administrador. Atribuição manual limitada a Secretaria e erp_custom_*; papéis acadêmicos/financeiros são automáticos. Alterações exigem Idempotency-Key para correlação da auditoria.

GET `/financeiro/alunos`: diretório paginado por nome/RA para usuários autorizados à gestão financeira. Rotas existentes verificam a política de menus antes das permissões de operação e de vínculo. Esta política não interfere no acesso explícito de integrações autorizadas às rotas de exportação.


## 0.6.0 — gestão acadêmica

- GET `/configuracoes`: `codperiodo` configurado e lista de períodos; autenticado.
- POST `/configuracoes`: `{ "codperiodo": "1" }`, administrador e Idempotency-Key para correlação da auditoria. Não é operação financeira.
- GET `/matriculas`: filtros `codperiodo`, `idturma`, `idcurso`, `idturno`, `search`, `status`, `tipo_aluno`, `origem`, `data_inicio`, `data_fim`, `page`. Retorna `items`, `total`, `page`, `codperiodo`; 20 registros por página. Sem período, usa a configuração; `codperiodo=todos` consulta todos.
- GET `/matriculas/filtros`: projeção de turmas, cursos e turnos para seletores. Exige autorização acadêmica.
- POST `/matriculas/lote`: `{ "codperiodo": "1", "idturma": "2", "alunos": ["3", "4"] }`. Até 100 alunos. Exige capability acadêmica e menu Matrículas. Transação única e Idempotency-Key; reutilização idêntica não duplica; erro reverte tudo. Não gera contrato.
- POST `/exclusoes/{tipo}/{id}`: `{ "versao": "1", "motivo": "Correção cadastral" }`. Administrador, Idempotency-Key e versão obrigatórios. Tipos: pessoas, alunos, periodos_letivos, cursos, turnos, turmas, matriculas, aluno_periodos, aluno_responsaveis. Valida referências do schema e preserva auditoria; não permite excluir financeiro por este endpoint.
- Consultas de financeiro/matrículas do aluno, dashboard e turmas usam período configurado quando omitido. `todos` remove esse filtro sem alterar autorização. Ficha da secretaria retorna histórico completo para a interface selecionar o período. Rematrícula filtra o período da matrícula de origem. Exportação aceita período explícito ou padrão configurado; requer um período válido.


## 0.7.0 — planos, contratos e geração de parcelas

- Cadastro `planos_pagamento`: código, nome, valor_anuidade (string decimal), ativo. Criação/edição/exclusão: administrador. Consultas: usuários com acesso acadêmico institucional. `turmas.idplano` vincula a turma ao plano.
- GET `/turmas/{id}/plano`: retorna o plano ativo da turma, valor e versão; exige acesso acadêmico institucional.
- POST `/matriculas/lote` e POST `/alunos/{id}/periodos/{link}/turma`: agora exigem `quantidade_parcelas` e `primeiro_vencimento`. Aceitam `desconto_incondicional_total`, `termo`, `idplano`, `plano_versao`. Os dois últimos identificam o plano exibido e detectam mudança concorrente. O valor da anuidade sempre vem do banco, independentemente de `valor_original_total` enviado.
- A confirmação inicial gera contrato + parcelas + lançamentos na mesma transação da matrícula. Lote suporta até 100 alunos com condições comuns; erro reverte todos.
- POST `/ofertas-rematricula`: turmas precisam ter plano ativo. `numero_parcelas` é a sugestão e `primeiro_vencimento` é a data contratual proposta. O antigo valor_total da oferta não é fonte de preço; ofertas novas armazenam 0 nesse campo legado.
- GET `/alunos/{id}/ofertas-rematricula`: cada turma traz `plano`, com id, versão e anuidade. A consulta de responsáveis usa a matrícula de origem do período vigente.
- POST `/rematriculas`: aceita `quantidade_parcelas` (ou sugestão da oferta), `idplano` e `plano_versao`, além dos campos de oferta/origem/turma/aceite. Gera contrato com `parcelas_geradas=false`, sem parcelas e sem lançamentos.
- GET `/financeiro/pendentes`: lista contratos pendentes de geração, paginados, com filtros codperiodo (`todos` permitido para funcionário), search e page. Exige administrador ou papel interno Financeiro com menu financeiro e capability `erp_gerar_parcelas`.
- POST `/contratos/{id}/parcelas`: mesma autorização, Idempotency-Key obrigatório. Gera as condições já salvas no contrato. A mesma chave retorna a resposta anterior; nova chave em contrato já gerado retorna `ja_geradas=true`, sem duplicação.
- Financeiro pessoal e vida acadêmica de usuários sem autorização institucional ignoram tentativas de escolher outros períodos; exigem período vigente configurado. GET configuracoes só retorna o período vigente para essas contas.
- Exportação mantém os dados anteriores e inclui os campos de plano/snapshot/geração do contrato. `parcelas_geradas` é booleano; contrato pendente traz `parcelas: []`.
