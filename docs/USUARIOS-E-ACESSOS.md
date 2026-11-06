# Usuários, convites e acessos — 0.19.18

Criar ou importar uma pessoa cria também a conta WordPress, com senha aleatória. O convite fica **Não enviado**. O cadastro, novos vínculos e a atualização do plugin não autorizam o envio automático de convites.

## Enviar os convites

1. Abra **Usuários** no portal ou **ERP Educacional → Usuários e acessos** no WordPress.
2. Pesquise pelo nome, login ou e-mail. Marque as contas desejadas ou **Selecionar esta página**. A seleção é mantida ao trocar de página, até 100 contas por operação.
3. Clique em **Enviar convites selecionados**, confira os nomes e confirme o envio. Só essa confirmação encaminha a seleção para a fila de e-mail.
4. Consulte novamente a lista para acompanhar a situação. O processamento depende do agendador do WordPress e do serviço de e-mail configurado no site.

Para contas de alunos, o mesmo convite também pode ser encaminhado aos responsáveis acadêmicos vigentes, conforme os vínculos cadastrados. O convite identifica a conta do aluno; a senha da conta do responsável é independente.

| Tag | Significado |
|---|---|
| Não enviado | Conta criada, aguardando solicitação de envio. |
| Na fila | Envio solicitado pela equipe e aguardando processamento. |
| Enviado | O sistema encaminhou o convite ao serviço de e-mail. Há data e hora quando disponíveis. |
| OK | A pessoa concluiu a criação da senha inicial usando o link válido do convite. |
| Senha definida | A conta já tem senha própria ou foi alterada por outro caminho. Não equivale a confirmar o convite inicial. |
| Falha no envio | Confira o e-mail e o serviço configurado e solicite um novo envio. Um envio parcial pode mostrar também Enviado. |

Abrir o link sem concluir a senha não marca OK. Link inválido ou expirado também não marca OK. Senhas definidas por um administrador não simulam essa confirmação. Contas com senha já definida usam a recuperação normal de senha do portal.

O envio individual continua disponível em **Editar**. A permissão **Redefinir senha e enviar convites** controla os envios; contas protegidas mantêm as restrições de edição.

## Último acesso e histórico

A lista mostra a data e hora do último acesso registrado. Em **Histórico de acessos**, consulte as entradas com login e os retornos após um período sem atividade, mesmo quando a sessão permanece aberta. Navegação contínua atualiza a data do último acesso sem criar uma entrada de histórico a cada página.

Os horários seguem o fuso configurado no WordPress, indicado no histórico. O registro começa nesta versão; não é possível reconstruir acessos anteriores que não foram registrados. O histórico não grava senhas, chaves, URLs de convite ou conteúdo dos e-mails.

A consulta exige a permissão **Consultar histórico de acessos**. Administradores têm acesso; a Secretaria recebe essa permissão na configuração padrão, respeitando as restrições de contas protegidas. Perfis com permissões personalizadas devem ser conferidos em **Perfis e acessos**.

## Excluir pessoa e conta

Ao excluir uma pessoa sem dependências, o ERP exclui também a conta WordPress vinculada e seus convites/metadados. Posts e links do WordPress são transferidos ao administrador que confirmou a exclusão. Históricos do ERP e registros de acessos permanecem preservados.

Uma falha na gravação reverte pessoa, conta e alterações de autoria na mesma operação. A própria conta da sessão não pode ser excluída por esse caminho. Pessoas vinculadas a alunos, contratos ou outros registros protegidos continuam exigindo a resolução dessas dependências. Contas de uma rede WordPress exigem atuação do administrador da rede.
