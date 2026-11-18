# Acesso a contratos, bolsas e descontos — 0.19.27

## Liberar um perfil

1. Atualize o plugin em **Plugins → ERP Educacional → Verificar atualizações** e recarregue o portal.
2. Como administrador, abra **Perfis e acessos**.
3. Expanda o perfil desejado e use **Editar acessos**.
4. Marque **Contratos** e/ou **Bolsas e descontos dos contratos** e salve.
5. A pessoa que recebeu a permissão deve recarregar o portal para atualizar o menu e os atalhos.

Os dois acessos são independentes e podem ser concedidos aos perfis existentes ou personalizados. Pessoas com mais de um perfil recebem a soma dos acessos. Nenhum perfil de funcionário recebe essas permissões automaticamente pela atualização; o administrador escolhe quem terá acesso.

| Acesso | Operações disponíveis |
|---|---|
| Contratos | Consultar e imprimir o termo, editar contratos com motivo e versão, excluir contratos permitidos pelo fluxo e gerenciar modelos de contrato. |
| Bolsas e descontos dos contratos | Localizar contratos por aluno, RA ou número; aplicar, alterar, suspender e retirar bolsas/descontos; consultar indicados e reprocessar indicações. |

## Usar os acessos

**Contratos** abre a lista de contratos e os modelos. A exclusão continua exigindo a retirada dos lançamentos vinculados, e um cronograma já gerado continua protegido.

**Bolsas e descontos dos contratos** abre uma lista própria com busca por aluno, RA ou contrato. Clique em **Bolsas e descontos** no contrato desejado para usar o mesmo editor disponível na ficha do aluno. Esse caminho permite trabalhar com benefícios mesmo sem acesso à ficha completa ou à gestão financeira.

Quando as duas áreas estiverem liberadas, a lista de contratos também oferece o atalho dos benefícios. Na ficha do aluno e no Financeiro, os atalhos acompanham as permissões concedidas.

A pessoa autorizada a administrar apenas benefícios recebe o resumo necessário do contrato, sem consultar o termo completo por esse acesso. Para consultar e editar o termo, libere também **Contratos**.

O cadastro global de bolsas em Configurações e o valor global de pontualidade continuam sob administração. A permissão de benefícios permite usar as bolsas disponibilizadas e criar descontos do contrato. Baixas, estornos, alterações de pessoas, exclusão definitiva e administração dos perfis seguem suas permissões próprias.

## Revogar e conferir

Volte a **Editar acessos**, desmarque a área e salve. A autorização é conferida em cada operação. Um formulário que tenha ficado aberto não mantém uma permissão revogada. Se a pessoa ainda tiver outro perfil que libera a área, continuará com esse acesso pela soma dos perfis.

Os contratos e benefícios permanecem limitados à coligada selecionada. Alterações continuam registrando o autor na auditoria e preservando as validações de pagamentos, versões, faixas e valores.

## Rodapé

O portal apresenta **Desenvolvido por Paulo Maeda**, com link para [paulomaeda.com](https://paulomaeda.com), junto à versão do sistema.

## Entrega e verificação

Permissões introduzidas no schema 45 e mantidas no schema 46, sem alteração em lote dos perfis. O acesso Contratos também permite registrar a assinatura do texto; veja [o guia do texto assinado](CONTRATO-ASSINADO.md). Testes de permissões, telas e operações realizados em ambiente local, incluindo WordPress/MariaDB e transporte externo bloqueado. A instalação no site segue a configuração do WordPress. Veja [o registro de validação](VALIDACAO.md).
