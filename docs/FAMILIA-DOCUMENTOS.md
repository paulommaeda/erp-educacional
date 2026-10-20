# Contatos e documentos dos alunos — 0.15.0

Após atualizar, entre uma vez no WordPress como administrador para aplicar a migração automática do banco (schema 30). A atualização adiciona duas tabelas; cadastros e matrículas anteriores são preservados.

## Configuração do colégio

Em **Configurações → Documentos dos alunos**, cadastre os documentos solicitados: nome, orientações, obrigatório/opcional, ordem e ativo/inativo. Os tipos pertencem à coligada de trabalho. Um tipo sem envios pode ser excluído; quando já existe histórico, utilize Inativo.

## Portal do responsável

Clique no nome do aluno em **Sua família na escola**. O modal oferece:

- **Dados de contato:** e-mail, telefone, rua, número, complemento, bairro, CEP, país, estado e cidade. Nome, RA, nascimento, documentos pessoais e atribuições não fazem parte dessa edição. O e-mail também segue a sincronização da conta WordPress vinculada ao aluno.
- **Documentos do aluno:** um campo de envio para cada tipo ativo. Aceita PDF, JPG, PNG e WebP, até 5 MB por arquivo. Orientações e obrigatoriedade são exibidas por tipo. A marcação Obrigatório serve à conferência documental; não cria bloqueio de matrícula.

A autorização é conferida no servidor a cada leitura, edição, envio e download: vínculo de responsável vigente com atribuição acadêmica, financeira ou de rematrícula. Pessoas e usuários continuam compartilhados. Documentos dos registros autorizados do mesmo aluno em outros colégios são reunidos automaticamente, sem seletor de coligada.

## Conferência pela secretaria

O menu **Documentos dos alunos** apresenta a fila, inicialmente em Aguardando conferência, com filtros por nome/RA, tipo e situação, além de paginação. O perfil Secretaria recebe esse menu; demais perfis podem ser liberados em **Perfis e acessos**. Administradores têm acesso integral. A ficha do aluno também oferece a aba Documentos para quem possui essa permissão.

Abra o arquivo e clique **Conferir documento**:

| Decisão | Resultado no portal |
| --- | --- |
| Aguardando conferência | Arquivo disponível para consulta; novo envio bloqueado. |
| Aprovar | Exibe **✓ OK · Aprovado** e mantém o arquivo. |
| Rejeitar | Exige motivo, exclui o arquivo e libera o campo para reenvio. |

Cada reenvio cria outro registro. Datas, responsável pelo envio, revisor, decisão e motivo permanecem no histórico e na auditoria; o conteúdo rejeitado é eliminado. Uma falha na exclusão física deixa a limpeza pendente para nova tentativa agendada, com o download já bloqueado pela situação Rejeitado.

## Armazenamento e API

Os arquivos são criptografados no diretório `erp-documentos-alunos` dentro de uploads, sem anexos ou URLs públicas na biblioteca de mídia. O acesso ocorre pela REST API autenticada. A exportação seletiva oferece os cadastros e metadados de conferência; arquivos são consultados pelos endpoints autorizados.

Prefixo: `/wp-json/erp-educacional/v1`. Operações POST exigem `Idempotency-Key`; a sessão WordPress usa `X-WP-Nonce`.

| Método | Recurso | Uso |
| --- | --- | --- |
| GET / POST | `/familia/alunos/{id}/contato` | Consultar/atualizar contatos autorizados. |
| GET | `/familia/alunos/{id}/documentos` | Campos, situação e documentos da família. |
| POST multipart | `/familia/alunos/{id}/documentos/{tipo}` | Enviar arquivo no campo `documento`. |
| GET | `/familia/documentos/{id}/arquivo` | Download autorizado da família. |
| GET / POST | `/configuracoes/documentos-alunos` | Consultar/salvar tipos; administrador. |
| POST | `/configuracoes/documentos-alunos/{id}/excluir` | Excluir tipo sem histórico; administrador. |
| GET | `/documentos-alunos` | Fila: `status`, `search`, `idtipo_documento`, `page`. |
| GET | `/documentos-alunos/alunos/{id}` | Documentos na ficha da secretaria. |
| GET | `/documentos-alunos/{id}/arquivo` | Download autorizado da secretaria. |
| POST | `/documentos-alunos/{id}/conferir` | `versao`, `status` aprovado/rejeitado e `motivo` quando rejeitado. |

Shortcode da fila: `[erp_documentos]`. No portal completo, o menu é disponibilizado automaticamente conforme o perfil.
