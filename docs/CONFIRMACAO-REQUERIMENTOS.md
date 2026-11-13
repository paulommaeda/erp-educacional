# Confirmação de requerimentos por e-mail — 0.19.21

O preenchimento do formulário público registra o requerimento e encaminha um aviso de recebimento. A aprovação posterior cria ou aproveita pessoas, contas WordPress e a ficha do aluno. O convite de criação da senha continua manual em **Usuários e acessos**.

1. Atualize para **0.19.21** em **Plugins → ERP Educacional → Verificar atualizações**. O banco deve estar na versão **44**.
2. No ERP, selecione a coligada de trabalho e abra **Configurações → Requerimento público**.
3. Clique em **Configurar recebimento, e-mails e textos**.
4. Na seção **Confirmação do preenchimento por e-mail**, deixe **Enviar confirmação** ativado.
5. Escolha se recebem o responsável acadêmico, o financeiro ou ambos. São utilizados os e-mails informados no requerimento para essas atribuições.
6. Em **E-mails do comercial — um por linha**, cadastre os destinatários da equipe e salve. São aceitos até trinta endereços; também é possível separá-los por vírgula ou ponto e vírgula.

Os endereços do comercial ficam restritos às configurações do administrador. Cada pessoa recebe uma mensagem individual e endereços repetidos geram uma notificação por requerimento. Se um endereço estiver simultaneamente nos responsáveis e no comercial, ele recebe a confirmação destinada ao responsável.

A mensagem do responsável contém o protocolo, nome do aluno e confirmação de recebimento para análise. O aviso do comercial inclui curso/série/turno de interesse, contatos dos responsáveis selecionados e o atalho para a conferência autenticada. Nenhuma mensagem leva respostas de saúde, documentos, anexos ou links de senha. O recebimento não garante vaga nem efetiva matrícula.

O envio utiliza o serviço de e-mail configurado no WordPress. A fila só é confirmada junto com o requerimento; repetir o envio do mesmo formulário reutiliza o protocolo. Há tentativas automáticas com intervalo. Na conferência aparecem as contagens de enviados, pendentes, falhas e cancelados; o administrador pode usar **Tentar enviar pendentes** depois de conferir o serviço de e-mail. Mensagens já marcadas como enviadas pelo WordPress ficam fora desse reprocessamento. A indicação de enviado representa a confirmação do transporte pelo WordPress, sem comprovar leitura ou entrega na caixa postal.

O WordPress processa os primeiros destinatários após a gravação e continua a fila pelas tarefas agendadas. Requerimentos antigos e importações por API não geram confirmações retroativas. Encerrar o recebimento do formulário e desativar a confirmação são opções independentes. Desativar as confirmações ou remover um endereço comercial impede os respectivos envios ainda pendentes.

A nova versão mantém os destinatários isolados por coligada. A exportação dos requerimentos inclui a fila de notificações, e a exclusão definitiva do requerimento remove suas notificações vinculadas.
