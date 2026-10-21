# Documentos e informações pessoais — 0.15.4

Em Pessoas → Cadastrar novo / Editar → Informações pessoais, os campos ficam na seção Documentos e informações pessoais. O perfil pessoal usa o mesmo cadastro. Todos são opcionais e são gravados em maiúsculas; o e-mail continua com sua escrita original.

| Campo | Chave no CSV / API | Definição em pessoas | Preenchimento |
|---|---|---|---|
| Local de Nascimento | `local_nascimento` | varchar(191), NULL | Texto livre; permite nascimento no exterior |
| UF Nascimento | `uf_nascimento` | varchar(2), NULL | 27 UFs brasileiras e EX para exterior |
| Raça/Etnia | `raca_etnia` | varchar(20), NULL | Branca, Preta, Parda, Amarela, Indígena, Não declarada |
| Nacionalidade | `nacionalidade` | varchar(100), NULL | Texto livre |
| Grau de Instrução | `grau_instrucao` | varchar(30), NULL | Ensino Básico, Ensino Médio, Graduação, Pós-graduação, Mestrado, Doutorado |
| Profissão | `profissao` | varchar(100), NULL | Campo existente; permanece uma única vez na seção |

UF de nascimento é independente do país, estado e cidade do endereço atual. Não é necessário mudar o endereço para informar a naturalidade. Nenhuma nacionalidade ou raça é preenchida automaticamente.

As opções de raça/etnia e instrução são validadas no servidor. Texto com diferenças de caixa ou sem acentos é convertido para a opção canônica: `Indigena` → `INDÍGENA`; `Pos-graduacao` → `PÓS-GRADUAÇÃO`. Outras opções devem ser corrigidas na conferência antes da importação. Valores desconhecidos recebidos pela API continuam visíveis para revisão, sem serem descartados silenciosamente.

O modelo CSV de pessoas e o mapeamento de cada conexão de requerimentos incluem as mesmas chaves. A conferência mostra os valores antes de criar pessoas; a importação continua sujeita à aprovação. Os novos campos também fazem parte da exportação e dos dados de pessoa nas integrações.

Na ficha do aluno, os valores aparecem em Dados do aluno → Dados pessoais, respeitando a autorização configurada em Perfis e acessos. A edição de contatos pelo responsável mantém sua lista própria de campos permitidos.

Schema 32 adiciona cinco colunas opcionais por dbDelta. Não remove cadastros, vínculos ou valores de campos adicionais. Um campo adicional com chave parecida continua em `campos_adicionais`, separado do campo nativo; revise seu mapeamento se desejar passar a usar o campo nativo. Cadastros anteriores começam com os novos valores vazios.

# Verificação desta versão

- PHP 8.1: sintaxe dos arquivos alterados validada. PHP-WASM/SQLite e stubs WordPress: cadastro, edição parcial, perfil próprio, limpeza de opcionais, opções válidas/inválidas, exportação, CSV, conferência/importação de requerimentos e permissões.
- JSDOM: opções completas, preenchimento para edição, profissão única, UF independente do endereço, modelo CSV e novos campos na conferência da API e ficha do aluno.
- Chromium em 1440, 390 e 320 px: cadastro em passos e edição por abas, envio dos valores e duas colunas sem rolagem horizontal. Conferência visual dos modais em desktop e mobile.
- Não foi feita instalação no WordPress do colégio nem execução real de dbDelta/MySQL nesta alteração.
