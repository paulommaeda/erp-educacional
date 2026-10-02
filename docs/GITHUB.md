# Atualizações pelo GitHub

Repositório: https://github.com/paulommaeda/erp-educacional
Branch de distribuição: main. Slug/pasta: erp-educacional.

O plugin inclui Plugin Update Checker v5.7, de YahnisElsts, sob licença MIT (plugin-update-checker/license.txt). Inicializa em plugins_loaded e integra a verificação à tela Plugins do WordPress. O repositório é público e não exige token.

## Primeira instalação
Instale manualmente o ZIP 0.8.2 substituindo a versão instalada, sem desinstalar. A partir daí, consulte Plugins > ERP Educacional > Verificar atualizações. A versão disponível só será oferecida quando superior à instalada. Checagem periódica não significa instalação automática; o administrador controla a atualização no WordPress.

## Próximas versões
1. Altere Version e EDERP_VERSION em erp-educacional.php, Stable tag em readme.txt e changelog.
2. Teste antes de publicar. Use branches separadas para desenvolvimento; main deve conter apenas versões prontas.
3. Publique os arquivos completos na raiz de main, incluindo plugin-update-checker, assets e src.
4. Confira a atualização em homologação antes de aplicar em produção.

A integração usa main diretamente; não depende de GitHub Releases. O pacote GitHub é reconhecido pelo PUC para manter o diretório erp-educacional.

Se o repositório se tornar privado, configure uma credencial de leitura na constante EDERP_GITHUB_TOKEN do wp-config.php do site. Nunca inclua tokens nos arquivos do repositório ou no ZIP.
