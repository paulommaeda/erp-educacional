# Contatos e documentos dos alunos — 0.19.31

Após atualizar, entre uma vez no WordPress como administrador para aplicar a migração automática do banco (schema 48). A atualização acrescenta a origem dos envios, uma fila de notificações e o controle de lembretes; documentos, cadastros, assinaturas e valores anteriores são preservados.

## Configuração do colégio

Em **Configurações → Documentos dos alunos**, cadastre os documentos solicitados: nome, orientações, obrigatório/opcional, ordem e ativo/inativo. Os tipos pertencem à coligada de trabalho. Um tipo sem envios pode ser excluído; quando já existe histórico, utilize Inativo.

## Portal do responsável

O bloco **Sua família na escola** mostra foto (ou iniciais), nome, RA, turma atual e período letivo, alinhados à esquerda. O período vem da configuração vigente de cada coligada autorizada. Uma rematrícula para o próximo ano não substitui a turma atual. Sem matrícula vigente, aparece **Sem turma no período vigente**; sem configuração, **Período não configurado**. Matrículas canceladas e transferências externas não são apresentadas como turma atual. Os filhos continuam reunidos sem seleção de coligada.

Clique no nome do aluno em **Sua família na escola**. O modal oferece:

- **Dados de contato:** e-mail, telefone, rua, número, complemento, bairro, CEP, país, estado e cidade. Nome, RA, nascimento, documentos pessoais e atribuições não fazem parte dessa edição. O e-mail também segue a sincronização da conta WordPress vinculada ao aluno.
- **Documentos do aluno:** um campo de envio para cada tipo ativo. Aceita PDF, JPG, PNG e WebP, até 5 MB por arquivo. Orientações e obrigatoriedade são exibidas por tipo. A marcação Obrigatório serve à conferência documental; não cria bloqueio de matrícula.

A autorização é conferida no servidor a cada leitura, edição, envio e download: vínculo de responsável vigente com atribuição acadêmica, financeira ou de rematrícula. Pessoas e usuários continuam compartilhados. Documentos dos registros autorizados do mesmo aluno em outros colégios são reunidos automaticamente, sem seletor de coligada.

## Conferência pela secretaria

O menu **Documentos dos alunos** apresenta a fila, inicialmente em Aguardando conferência, com filtros por nome/RA, tipo e situação, além de paginação. O perfil Secretaria recebe esse menu; demais perfis podem ser liberados em **Perfis e acessos**. Administradores têm acesso integral. A ficha do aluno também oferece a aba Documentos para quem possui essa permissão.

A secretaria também pode enviar documentos recebidos por WhatsApp, e-mail ou presencialmente: use **Enviar documento para aluno**, busque por nome ou RA e selecione o arquivo. A aba Documentos da ficha oferece os mesmos campos de envio. PDF, JPG, PNG e WebP são aceitos, até 5 MB. O envio registra o operador e a origem **Secretaria**; o arquivo entra como **Aguardando conferência**. Documentos já aprovados ou em análise mantêm a proteção contra substituição.

Abra o arquivo e clique **Conferir documento**:

| Decisão | Resultado no portal |
| --- | --- |
| Aguardando conferência | Arquivo disponível para consulta; novo envio bloqueado. |
| Aprovar | Exibe **✓ OK · Aprovado** e mantém o arquivo. |
| Rejeitar | Exige motivo, exclui o arquivo e libera o campo para reenvio. |

Cada reenvio cria outro registro. Datas, responsável pelo envio, revisor, decisão e motivo permanecem no histórico e na auditoria; o conteúdo rejeitado é eliminado. Uma falha na exclusão física deixa a limpeza pendente para nova tentativa agendada, com o download já bloqueado pela situação Rejeitado.

## E-mails, lembretes e início do portal

O administrador abre **Configurações → Documentos dos alunos → Configurar e-mails e lembretes**, também disponível no menu Documentos dos alunos. As configurações pertencem à coligada de trabalho.

- **Documento recebido:** defina os e-mails da equipe, um por linha, e ative o aviso. Ele informa os envios feitos pela família. Envios da secretaria não avisam a própria equipe.
- **Aprovação e rejeição:** avisos aos responsáveis ativos com e-mail válido, incluindo o motivo quando o arquivo é rejeitado. Pode-se escolher todos os responsáveis com acesso aos documentos, somente acadêmicos ou somente financeiros. E-mails iguais são deduplicados por aluno e evento.
- **Pendências:** o primeiro lembrete é programado para 48 horas após a identificação de documentos obrigatórios ausentes ou documentos ativos rejeitados. A rejeição reinicia o prazo de 48 horas. Os próximos lembretes ocorrem a cada dois dias enquanto houver pendências. Um cron atrasado não acumula ciclos anteriores. Arquivos aguardando análise e documentos aprovados não são cobrados; tipos inativos e opcionais nunca enviados também não.

Edite os assuntos e mensagens dos quatro modelos usando os campos disponíveis, como [aluno_nome], [aluno_ra], [documento_nome], [motivo], [documentos_pendentes], [link_documentos] e [link_analise]. O conteúdo é texto; a identidade visual do colégio é aplicada automaticamente. Não há anexos ou URLs públicas de arquivos nos e-mails. Mudanças nas configurações e nos vínculos são conferidas novamente antes de cada envio.

O início do portal mostra cada aluno com documentos a enviar ou reenviar e o link **Enviar ou reenviar documentos**, que abre a área correta. O link dos e-mails também abre essa área após login. Envios retiram as respectivas pendências; rejeições voltam a solicitar o reenvio. A secretaria vê a contagem de arquivos aguardando análise no início.

O WordPress executa o processamento em segundo plano. Mantenha o WP-Cron ativo ou um agendamento do servidor que o execute. A rotina verifica lotes a cada cinco minutos e compara o prazo de 48 horas de cada aluno; a hora efetiva depende da execução do cron. A atualização não dispara avisos antigos de recebimento ou análise em massa. Lembretes começam após o primeiro prazo de 48 horas.

O histórico mostra encaminhados pelo WordPress, aguardando envio, falhas e cancelamentos. Aceitação pelo WordPress não confirma entrega na caixa de entrada. Após cinco falhas confirmadas, o administrador pode corrigir o serviço de e-mail e usar **Tentar enviar novamente**. Mensagens já encaminhadas não são repetidas. Um transporte iniciado que tenha sido interrompido sem confirmação fica identificado no histórico e não é repetido automaticamente.

## Primeira parcela

Ao matricular ou criar um novo contrato, o primeiro vencimento deve ser hoje ou uma data futura, conforme o fuso configurado no WordPress. A regra vale para matrícula individual, em lote, rematrícula e API. A data anterior é recusada pelo servidor e toda a operação da matrícula é desfeita. Editar o primeiro vencimento de um contrato também exige uma data atual ou futura. Parcelas e datas históricas de contratos existentes são preservadas quando não se está alterando o primeiro vencimento.

## Armazenamento e API

Os arquivos são criptografados no diretório `erp-documentos-alunos` dentro de uploads, sem anexos ou URLs públicas na biblioteca de mídia. O acesso ocorre pela REST API autenticada. A exportação seletiva oferece os cadastros e metadados de conferência; arquivos são consultados pelos endpoints autorizados.

Prefixo: `/wp-json/erp-educacional/v1`. Operações POST exigem `Idempotency-Key`; a sessão WordPress usa `X-WP-Nonce`.

| Método | Recurso | Uso |
| --- | --- | --- |
| GET / POST | `/familia/alunos/{id}/contato` | Consultar/atualizar contatos autorizados. |
| GET | `/familia/alunos/{id}/documentos` | Campos, situação e documentos da família. |
| POST multipart | `/familia/alunos/{id}/documentos/{tipo}` | Enviar arquivo no campo `documento`. |
| GET | `/familia/documentos/avisos` | Pendências dos alunos do responsável autenticado. |
| GET | `/familia/documentos/{id}/arquivo` | Download autorizado da família. |
| GET / POST | `/configuracoes/documentos-alunos` | Consultar/salvar tipos; administrador. |
| POST | `/configuracoes/documentos-alunos/{id}/excluir` | Excluir tipo sem histórico; administrador. |
| GET | `/documentos-alunos` | Fila: `status`, `search`, `idtipo_documento`, `page`. |
| GET | `/documentos-alunos/alunos/{id}` | Documentos na ficha da secretaria. |
| GET | `/documentos-alunos/buscar-alunos` | Busca por nome/RA e página para envio pela secretaria. |
| POST multipart | `/documentos-alunos/alunos/{id}/{tipo}` | Envio pela secretaria, campo `documento`. |
| GET / POST | `/configuracoes/documentos-emails` | Configuração e modelos por coligada; administrador. |
| GET | `/configuracoes/documentos-emails/historico` | Histórico paginado; administrador. |
| POST | `/configuracoes/documentos-emails/{id}/tentar` | Nova tentativa de envio com falha confirmada; administrador. |
| GET | `/documentos-alunos/{id}/arquivo` | Download autorizado da secretaria. |
| POST | `/documentos-alunos/{id}/conferir` | `versao`, `status` aprovado/rejeitado e `motivo` quando rejeitado. |

Shortcode da fila: `[erp_documentos]`. No portal completo, o menu é disponibilizado automaticamente conforme o perfil.
