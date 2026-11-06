# Entrega de versões do ERP Educacional

O usuário recebe as novas versões pela tela de atualizações do WordPress. O canal de distribuição é a branch `main` do repositório `paulommaeda/erp-educacional`, configurada em `src/Infrastructure/WordPress/Updates.php`.

O usuário autorizou expressamente a liberação da versão 0.19.14 e das próximas correções deste ERP que forem concluídas e testadas nesse canal. Essa autorização de publicação vale para as próximas entregas, até instrução contrária. A instalação de cada versão segue as configurações do WordPress do usuário.

- Desenvolva em uma branch separada e execute as verificações apropriadas à alteração.
- Para entregar uma versão concluída, publique os arquivos testados na `main`, respeitando as proteções e verificações do repositório. Uma branch de desenvolvimento ou uma proposta em rascunho, por si só, não disponibiliza uma atualização no WordPress. Se o usuário pedir expressamente apenas revisão, mantenha a entrega em revisão.
- Incremente `Version` e `EDERP_VERSION` em `erp-educacional.php`, `Stable tag` em `readme.txt`, o changelog e a versão apresentada no README. A versão precisa ser maior que a publicada anteriormente para ser oferecida como atualização.
- Preserve o atualizador, a branch `main` e a pasta/slug `erp-educacional` nos pacotes.
- Após publicar, confirme a versão lida remotamente na `main` e o resultado da publicação. Informe como disparar a consulta imediata em **Plugins → ERP Educacional → Verificar atualizações**.
- Registre as verificações realizadas e qualquer validação pendente no WordPress/MySQL. Publicar uma versão para consulta pelo atualizador e instalar essa versão no site são ações distintas.
