# Backup e restauração do ERP

A partir da versão 0.19.38, um administrador pode guardar várias cópias em **ERP Educacional → Backup e restauração**. A data e a hora aparecem no fuso do WordPress. O histórico também mostra tipo, versão do ERP, versão do WordPress, versão do esquema e tamanho. O nome do ZIP baixado inclui a versão do ERP e a data em UTC.

| Opção | O que você recupera | Quando escolher |
| --- | --- | --- |
| Somente banco de dados | Estrutura, índices e dados das tabelas com o prefixo do site. Inclui alunos, matrículas, contratos, parcelas, baixas, contas do WordPress, configurações e tabelas dos demais plugins com esse prefixo. | Para recuperar dados quando você também dispõe das versões correspondentes dos arquivos. |
| Completo | O mesmo banco mais WordPress, plugins, temas, uploads, documentos locais do ERP e a configuração original da instalação. | Antes de uma atualização ou quando for necessário voltar à versão anterior do sistema com seus documentos. |

## Criar e guardar

1. Escolha o tipo, informe uma senha exclusiva de pelo menos 12 caracteres e clique em **Criar backup**. Prefira uma janela sem lançamentos, uploads, atualizações ou tarefas agendadas. Banco e arquivos são lidos em etapas.
2. Quando a cópia aparecer no histórico, clique em **Baixar ZIP**. Guarde uma cópia fora da hospedagem e a senha em um gerenciador de senhas. Conserve também as chaves de autenticação do WordPress vigentes na data da cópia.
3. Para recolocar um ZIP guardado no histórico, use **Importar backup do ERP** e informe a senha original. O ERP verifica a assinatura do manifesto e a integridade de cada arquivo antes de aceitar o pacote.

O formato aceito é o ZIP gerado por este recurso. Arquivos `.wpress` anteriores continuam dependendo do restaurador compatível com aquele formato. A primeira data disponível para retorno é a da primeira cópia guardada.

## Recuperar uma versão

1. No painel da hospedagem, crie **um banco vazio no mesmo servidor MySQL/MariaDB**. Dê ao usuário de destino permissão sobre as tabelas e chaves desse banco. Se ele tiver credenciais próprias, informe-as na tela de restauração.
2. Escolha a cópia pela data e pela versão. Em **Restaurar uma versão**, informe a senha do ZIP e o nome do banco vazio. Clique em **Restaurar e conferir no destino**.
3. O ERP verifica o pacote, recria as tabelas e índices, carrega os registros e confere as contagens e os vínculos definidos por chaves estrangeiras. Com um backup completo, o resultado também indica a pasta privada com os arquivos recuperados em `site/` e a configuração em `config/wp-config.php`. Em caso de falha a operação informa o motivo. Uma tentativa interrompida pode deixar tabelas no destino. Reserve um novo banco vazio para repetir.
4. Prepare a cópia em ambiente de manutenção. Para um backup completo, use os arquivos recuperados. Para um backup de banco, providencie o WordPress, o ERP, os demais plugins, temas e uploads compatíveis com a data escolhida. Ajuste `DB_NAME`, `DB_USER` e `DB_PASSWORD` no `wp-config.php` da cópia **antes** de iniciar o WordPress recuperado. Mantenha o prefixo indicado no resultado e as chaves de origem. Ao preparar a pasta que será publicada, aplique o proprietário e as permissões de leitura e execução de diretórios recomendados pela hospedagem. A extração usa permissões privadas e não transporta proprietários do sistema operacional. O pacote conserva os endereços do site original. Em outro domínio de testes, configure os endereços desse ambiente separadamente.
5. Para homologar, mantenha a cópia com acesso restrito, e-mails, integrações externas, cron e atualizações automáticas suspensos. Confira um aluno, o contrato dele, as parcelas, as baixas e um documento. Para efetivar o retorno, coloque o site em manutenção, preserve os dados lançados depois da cópia e aponte a hospedagem para os arquivos e o banco conferidos. A publicação do endereço e a reativação das integrações são manuais.

O retorno recupera o estado da escola na data escolhida. Para conciliar pagamentos ou assinaturas recebidos depois dessa data, consulte também o histórico dos serviços externos.

## Requisitos da hospedagem

- WordPress individual, PHP 8.1 ou superior, extensões `mysqli` e `zip` com AES-256 e espaço para o ZIP, os dados temporários, os arquivos recuperados e um segundo banco.
- Por padrão os pacotes ficam em uma pasta privada acima da raiz pública. Para escolher outro local, defina, por exemplo, `define('EDERP_BACKUP_DIR', '/caminho/privado/backups-erp');` no `wp-config.php`. Defina essa constante também para usar o WP-CLI quando o processo não informar a raiz pública. Use um caminho gravável pelo PHP e fora de todos os diretórios publicados pela hospedagem. Proteja essa pasta, inclusive os arquivos temporários e os resultados de recuperação.
- Para dados InnoDB o ERP abre uma transação com leitura consistente. Se houver tabelas de outro mecanismo, ele solicita bloqueio de leitura para o conjunto durante a cópia. O usuário de origem precisa de acesso de leitura e de visibilidade dos gatilhos das tabelas. Views, gatilhos detectados, arquivos vinculados por links simbólicos e tabelas com dependências externas exigem tratamento pela hospedagem.
- O completo cobre os arquivos locais sob a raiz do WordPress e o seu `wp-config.php`. Cache, diretórios de atualização, repositórios Git e pacotes em `wp-content/ai1wm-backups` ficam fora da cópia. Armazenamento externo, regras do servidor web, serviços externos, usuários do MySQL e tarefas configuradas fora do WordPress têm ciclo de backup próprio.
- A assinatura do pacote usa a chave de `wp_salt('auth')` da origem. Para recuperação em outra instalação, prepare o recurso com as chaves originais. Quando a instalação delega as chaves ao ambiente ou ao banco, preserve esses segredos em separado. Sem a senha do ZIP não é possível descriptografar o conteúdo.

## A tela avisa que um diretório está indisponível

Na versão 0.19.38 esse aviso pode aparecer ao abrir a tela, antes de selecionar qualquer ZIP. A partir de 0.19.39, o diagnóstico indica a pasta que o PHP tentou localizar. Quando a hospedagem fornece uma raiz pública alternativa para todo o site, o ERP pode usá-la automaticamente.

Se o aviso mencionar `DOCUMENT_ROOT` ou a criação da pasta de backup, escolha no painel da hospedagem uma pasta privada fora de todas as áreas públicas e acessível ao usuário do PHP. Use o caminho absoluto **da nova conta**, antes da linha final de configuração do `wp-config.php`. Por exemplo, se a hospedagem confirmar que `/home/conta/privado` é uma área privada gravável:

```php
define('EDERP_BACKUP_DIR', '/home/conta/privado/backups-erp');
```

Se essa definição já existir, edite a linha existente. Em uma migração, confira se o endereço ainda aponta para a conta antiga. Quando já houver backups nessa hospedagem, aponte para a pasta que guarda os arquivos de histórico e os ZIPs. Se a mensagem citar `WP_CONTENT_DIR` ou `ABSPATH`, confira o caminho indicado na configuração da instalação. Depois, recarregue **ERP Educacional → Backup e restauração**.

## WP-CLI

Para sites maiores que os limites de upload e de duração de requisições da hospedagem, use o terminal no diretório da instalação. Carregue a senha do ZIP na variável de ambiente `EDERP_BACKUP_PASSWORD`. Para usar outro usuário MySQL no destino, carregue a senha dele em `EDERP_RESTORE_DB_PASSWORD`.

```sh
wp erp backup criar completo
wp erp backup criar banco
wp erp backup listar
wp erp backup importar /caminho/privado/copia.zip
wp erp backup restaurar ID_DO_BACKUP --banco=erp_recuperado --usuario=usuario_destino
```

O resultado de `criar` e `listar` inclui o identificador da cópia. A restauração pelo terminal faz as mesmas verificações que a tela do administrador.
