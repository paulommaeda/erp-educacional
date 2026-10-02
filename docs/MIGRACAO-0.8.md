# Atualização 0.8.0

1. Faça backup de arquivos e banco e atualize primeiro em homologação.
2. Substitua o plugin usando o ZIP 0.8.0 e abra o wp-admin como administrador para aplicar o schema 6.
3. Confira Configurações → Estados civis e ajuste os nomes para os códigos da sua fonte de importação antes de importar.
4. Use o modelo CSV atualizado: `idestado_civil` contém o código numérico. Confira algumas fichas após importar.

A migração cria `estados_civis` e a FK nullable `pessoas.idestado_civil`, preservando o campo textual legado por compatibilidade. Nomes conhecidos são associados aos códigos iniciais; nomes diferentes recebem novos códigos e são preservados. RA, CODPESSOA e vínculos existentes não são renumerados. Excluir estado civil em uso é bloqueado por dependência relacional.

Editar o nome atualiza sua apresentação nas fichas e exportações. Dados textuais de novos cadastros/importações e edições são salvos em maiúsculas; o e-mail não é convertido. Registros preexistentes não são todos regravados automaticamente.

A validação local utiliza SQLite e funções WordPress simuladas; dbDelta, FKs e migração devem ser conferidos em WordPress/MySQL real antes de produção.
