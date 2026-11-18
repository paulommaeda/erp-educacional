# Texto do contrato assinado — 0.19.29

## Registrar

1. Atualize o ERP e conclua a migração em **Configurações**, se solicitada.
2. Abra **Contratos → Ver contrato** e confira o texto apresentado.
3. Marque **Contrato assinado** e clique em **Registrar contrato assinado**.

O ERP registra a confirmação de que o documento foi assinado, com data, usuário que fez o registro e identificação do texto. Esse acesso segue **Perfis e acessos → Editar acessos → Contratos**. A área de bolsas/descontos, sozinha, não autoriza registrar assinaturas.

O check marcado fica desabilitado. A marcação não pode ser desfeita por nenhum perfil, inclusive administrador. Redação, versão do termo, vínculo e origem do modelo e dados do registro ficam protegidos no servidor e na persistência. Um formulário antigo não reabre o texto. Se o contrato tiver mudado antes da confirmação, a tela solicita nova conferência.

O número usado no cabeçalho da impressão é guardado no registro. Alterar depois um número operacional não muda o cabeçalho do texto assinado. O texto pode ser consultado e impresso.

## Antes da assinatura

Editar um modelo atualiza os textos dos contratos vinculados que ainda não estão assinados. O preenchimento usa os dados de cada contrato. O ERP informa quantos textos foram atualizados. Contratos assinados são ignorados nessa atualização.

Texto personalizado sem vínculo com modelo permanece independente. Excluir um modelo não apaga os textos que já foram gerados. A atualização para o schema 46 preserva os textos anteriores e não presume assinaturas. Confira e marque os contratos assinados antes de novas edições dos modelos. Ao salvar um modelo a partir desta versão, os textos vinculados ainda não assinados serão sincronizados.

## Operações financeiras

O bloqueio pedido é **somente do texto**. Bolsas, descontos, valores, geração de parcelas, pagamentos e estornos seguem as permissões e validações existentes. Essas operações não reescrevem o texto assinado. Um texto pode ser marcado como assinado antes da geração das parcelas. As regras de exclusão e os registros financeiros seguem seus fluxos próprios.

## Período da Gestão financeira

A Gestão financeira acompanha **Período da consulta**, no topo do portal. Trocar a seleção atualiza indicadores e opções, limpando competências, turma e aluno do período anterior. A seleção acompanha os links de navegação. Consultar outro período não altera o período vigente configurado para a escola.

## Entrega

Versão 0.19.29, schema 46. Migração aditiva, sem recálculo de cobranças. Confira a atualização em **Plugins → ERP Educacional → Verificar atualizações**. A instalação segue a configuração do WordPress do site. Veja [as verificações realizadas](VALIDACAO.md).
