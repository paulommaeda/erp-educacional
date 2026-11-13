# API de leitura de requerimentos — 0.19.23

Esta API permite consultar em outro sistema os requerimentos recebidos pelo formulário público nativo do ERP. Inclui os pendentes, aprovados e rejeitados. As conexões que importam formulários de outros sistemas continuam no fluxo existente de Integração API; a consulta descrita aqui lê os requerimentos do próprio ERP.

## Configurar o acesso

1. Atualize o ERP para 0.19.23 ou posterior. Em **Configurações → Integração API → Consultar requerimentos em outro sistema**, copie o endereço exibido para a coligada atual.
2. No WordPress, em **Usuários → Adicionar novo**, crie uma conta exclusiva para o sistema consumidor e escolha **Integração: leitura de requerimentos**.
3. Edite essa conta. Em **API de leitura de requerimentos → Coligada autorizada**, escolha a escola e salve. A opção **Sem acesso** bloqueia a integração.
4. Na edição da mesma conta, em **Senhas de aplicativo**, informe o nome do sistema e gere uma senha. Cadastre no consumidor o nome de usuário e essa senha de aplicativo. O WordPress precisa disponibilizar esse recurso sobre HTTPS.

O perfil de leitura tem apenas as capacidades read e erp_ler_requerimentos. A autorização por coligada inclui dados pessoais, respostas médicas e educacionais, contatos, autorizações e anexos do requerimento. Esse perfil não aprova, altera ou exclui requerimentos nem grava nas demais APIs de gestão. O administrador também pode consultar; para o consumidor, use a conta de leitura dedicada. A escolha de coligada do portal não amplia a autorização desta conta.

Autenticação: **HTTP Basic sobre HTTPS**, no cabeçalho Authorization com o usuário e sua Senha de aplicativo. Não envie senha na URL. Sessões administrativas do próprio WordPress podem utilizar cookie com X-WP-Nonce. Se o WordPress não reconhecer a autenticação, confira se a hospedagem encaminha o cabeçalho Authorization.

Para revogar o acesso, selecione **Sem acesso** e salve a conta, retire o perfil de integração ou revogue a senha em **Senhas de aplicativo**. Não é necessário alterar os requerimentos.

## Endereços

Base:

~~~text
https://SEU-DOMINIO/wp-json/erp-educacional/v1/integracao
~~~

| Método | Caminho | Retorno |
|---|---|---|
| GET | /requerimentos | Lista paginada com dados completos |
| GET | /requerimentos/{idrequerimento} | Um requerimento |
| GET | /requerimentos/campos | Chaves, rótulos, opções, grupos de pessoas e estados civis |
| GET | /requerimentos/{idrequerimento}/arquivos/{idarquivo} | Anexo autorizado em JSON, com conteúdo base64 |

Todas as consultas exigem autenticação. Não há consulta anônima por CPF. Requerimentos de outra coligada não são retornados nem pelo ID direto. As respostas possuem Cache-Control: private, no-store, max-age=0.

## Filtros e paginação

| Parâmetro | Exemplo | Regra |
|---|---|---|
| cursor | 0 | ID inicial exclusivo; use o next_cursor da resposta para continuar |
| limit | 20 | De 1 a 50; padrão 20 |
| status | aprovado | todos (padrão), pendente, aprovado ou rejeitado |
| protocolo | REQ-0123456789ABCDEF0123 | Correspondência exata |
| idaluno | 123 | Somente requerimentos vinculados ao aluno aprovado |
| atualizado_desde | 2026-11-13T00:00:00Z | Limite inferior inclusivo, em UTC |
| atualizado_ate | 2026-11-13T23:59:59Z | Limite superior inclusivo, em UTC |
| codcoligada | 1 | Administrador escolhe a coligada; leitor só aceita a mesma coligada autorizada |

Os itens vêm em ordem crescente de ID. Enquanto next_cursor não for null, continue com o valor recebido e os mesmos filtros. Nunca use o cursor de uma coligada em outra.

Para sincronizar, faça primeiro uma leitura completa. Nas próximas rodadas, reinicie cursor em 0, filtre pelo intervalo de atualização e percorra todas as páginas. Fixe atualizado_ate no início da rodada e guarde esse horário como início da rodada seguinte, com pequena sobreposição. Requerimentos antigos podem ser aprovados ou rejeitados depois; continuar somente com o último ID da rodada anterior perderia essas alterações. Atualize no consumidor pela combinação codcoligada + idrequerimento e compare versao.

O endpoint não fornece avisos de exclusão definitiva. Se precisar espelhar exclusões, reconcilie periodicamente a lista completa. A leitura não muda a situação, não confirma recebimento no consumidor e não envia e-mails.

## Conteúdo de cada item

- **idrequerimento, codcoligada, protocolo, status, versao:** identificação e situação.
- **nome_aluno, idaluno:** nome do requerimento e referência ao aluno após a aprovação.
- **criado_em, atualizado_em, conferido_em:** horários técnicos no formato UTC com Z; conferido_em pode ser null. A versão 0.19.23 passa a gravar explicitamente em UTC a criação dos novos requerimentos. Horários históricos são preservados conforme gravados no banco, sem conversão retroativa de fuso.
- **fonte_dados:** recebidos ou conferidos.
- **dados_recebidos:** conteúdo original do envio, preservado.
- **dados:** conteúdo conferido quando aprovado; nos demais casos, conteúdo recebido.
- **dados.pessoas:** aluno, pai, mae e, quando preenchidos, academico e financeiro. Inclui documentos, nascimento, contatos, profissão, estado civil e endereço.
- **dados.responsavel_academico / responsavel_financeiro:** indicam qual bloco de pessoa exerce a atribuição.
- **dados.campos:** respostas com suas chaves. Use /requerimentos/campos para interpretar rótulos e opções; respostas múltiplas são listas.
- **dados.retiradas / contatos_emergencia:** autorizações e contatos informados.
- **dados.declaracao, declaracao_nome, declaracao_versao:** ciência registrada no envio. Os textos apresentados ficam em declaracao_texto e privacidade_texto no item.
- **resultado:** referência ao aluno, RA e códigos das pessoas cadastradas após aprovação; null antes da aprovação.
- **motivo:** motivo de rejeição, quando houver.
- **arquivos:** ID, campo, nome, MIME, tamanho em bytes, disponibilidade e rota de leitura. As rotas são relativas à base REST do ERP, antes de /integracao.

IDs do item e versao são strings; preserve zeros do RA e dos documentos. Campos opcionais podem ser null ou vazios. Dados de nascimento usam AAAA-MM-DD. Nenhuma consulta retorna senha, hash de conta, chave de envio, token privado, sugestões de outros cadastros ou endereço direto de armazenamento.

## Exemplos para o integrador

O curl solicita a senha de aplicativo sem colocá-la no comando:

~~~bash
curl --user USUARIO_DA_API \
  --header 'Accept: application/json' \
  'https://SEU-DOMINIO/wp-json/erp-educacional/v1/integracao/requerimentos?limit=20&status=pendente&codcoligada=1'
~~~

Estrutura de uma coleção vazia:

~~~json
{
  "schema_version": "1.0",
  "gerado_em": "2026-11-13T13:00:00Z",
  "codcoligada": "1",
  "items": [],
  "next_cursor": null,
  "limit": 20
}
~~~

Consulta individual:

~~~bash
curl --user USUARIO_DA_API \
  'https://SEU-DOMINIO/wp-json/erp-educacional/v1/integracao/requerimentos/123?codcoligada=1'
~~~

O detalhe possui schema_version, gerado_em e item. O catálogo possui schema_version, codcoligada, pessoas, campos_pessoa, campos, estados_civis e situacoes.

Use a rota de arquivo retornada em arquivos, acrescentando a mesma autenticação e codcoligada. A resposta contém nome, mime, tamanho, codificacao: base64 e conteudo. Decodifique conteudo em bytes antes de gravar. Tokens e caminhos do armazenamento privado não fazem parte do contrato.

No Postman, escolha **Authorization → Basic Auth**, preencha usuário e Senha de aplicativo e faça GET para o endereço copiado. A especificação importável está em [openapi.json](openapi.json).

## Respostas de erro

| HTTP | Significado |
|---|---|
| 401 | Autenticação ausente ou inválida |
| 403 | Perfil ou coligada sem autorização |
| 404 | Requerimento/anexo ausente ou pertencente a outro escopo; método sem rota |
| 422 | Filtro inválido ou arquivo privado indisponível |
| 500 | Falha de consulta, dados inválidos no armazenamento ou integridade do arquivo |
| 503 | Atualização do banco pendente ou falha registrada no ERP |

## Verificações

Testes de serviço com SQLite e testes nativos de WordPress 7.1.3/MariaDB 11.4.8 com usuários, Senhas de aplicativo, REST e arquivo cifrado reais. Conferidos bloqueios de leitura, escrita e coligada; original versus aprovado; filtros, paginação, integridade e revogação. Nenhum e-mail externo foi enviado. Não houve instalação nem teste de conexão no site do colégio ou no sistema consumidor. Schema 44 mantido.
