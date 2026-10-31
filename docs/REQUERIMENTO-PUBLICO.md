# Requerimento público de matrícula

Versão 0.19.0 • Migração 36.

O formulário nativo foi construído a partir do JSON do Fluent Forms e do modelo HTML do PDF fornecidos. Não depende desses plugins para funcionar. Não importa submissões antigas automaticamente; a integração de requerimentos por API permanece em uma aba separada.

## Como começar

1. Atualize o plugin e abra uma vez o painel WordPress como administrador para executar a migração 36 e criar a página publicada **Requerimento de matrícula**.
2. No ERP, selecione a coligada de trabalho e abra **Configurações → Requerimento público**. Copie o endereço exibido. Esse endereço já fixa o colégio do requerimento.
3. Personalize apresentação, informação sobre uso dos dados e declaração do solicitante. A configuração **Recebimento** permite abrir ou encerrar os envios daquela coligada.
4. Compartilhe o endereço com as famílias. O preenchimento é livre, sem usuário ou senha. Em outras páginas pode ser utilizado `[erp_requerimento coligada="1"]`.
5. A equipe confere os envios em **Requerimentos → Formulário público**. As abas de pendentes, aprovados e rejeitados têm pesquisa e paginação. A importação existente está em **Importados por API**.

A página pública recebe as cores e a logo configuradas no colégio. Não carrega o conjunto de scripts das telas administrativas nem exige login do portal.

## Etapas e destino dos dados

| Área do formulário | Destino após aprovação |
| --- | --- |
| Identificação, nascimento, documentos, contato e endereço do aluno | Pessoa vinculada ao aluno |
| Pai, mãe, outro responsável acadêmico e outro financeiro | Pessoas e contas WordPress; vínculos explícitos com o aluno |
| Parentesco do outro responsável | Vínculo com o aluno, separado das atribuições acadêmica e financeira |
| Curso, série e turno pretendidos, indicação e escola de origem | Campos adicionais do aluno; informações para a secretaria |
| Núcleo familiar, guarda, adoção e irmãos | Campos adicionais protegidos nos dados pessoais do aluno |
| Necessidades de aprendizagem e acompanhamentos | Área protegida de dificuldades de aprendizagem |
| Saúde, medicamentos, alergias, atendimentos, convênio, restrições e orientações | Área protegida de ficha médica |
| Contatos de emergência | Campo adicional do aluno com um contato por bloco de linhas |
| Autorizações de retirada | Cadastro próprio em **Ficha do aluno → Autorizações de retirada** |
| Foto | Envio privado pendente; pode virar foto da pessoa e avatar WordPress após aprovação |
| Documentos de guarda e adoção | Arquivos privados associados ao requerimento e acessíveis na ficha do aluno |

São criados **78 campos adicionais** (77 definições do catálogo, incluindo empresa, mais contatos de emergência), preservando definições e valores já existentes com as mesmas chaves. Os novos campos do aluno ficam inicialmente fora do formulário genérico de Pessoas para manter o cadastro simples; podem ser reorganizados nas configurações de campos adicionais. Empresa aparece em Informações pessoais.

Cada opção múltipla é gravada no mesmo campo, uma resposta por linha. Respostas dependentes de uma condição desativada não são importadas. Labels mantêm capitalização normal; os valores seguem a normalização do ERP, preservando e-mails em minúsculas.

## Conferência

O envio cria apenas `requerimentos_publicos` e, quando necessário, `requerimento_arquivos`. Nenhuma pessoa, conta WordPress ou matrícula é criada nessa etapa. O protocolo aleatório identifica o envio; não existe consulta pública que liste requerimentos ou revele os dados de uma família.

Na conferência em modal, a equipe percorre as etapas livremente e pode corrigir os dados. Na última etapa, escolhe para cada pessoa:

- **Criar nova pessoa:** aplica a numeração do ERP e o provisionamento de conta já existente. Havendo homônimo de usuário, informa um login manual. Um nome e nascimento já cadastrados exigem seleção do cadastro existente ou confirmação explícita de que se trata de outra pessoa.
- **Usar pessoa existente:** seleciona uma sugestão ou informa o código de outra pessoa. Seus dados pessoais existentes são preservados. As informações complementares do aluno conferidas no requerimento são gravadas na ficha.
- **Mesma pessoa de outro bloco:** permite reutilizar um responsável já conferido no próprio envio.

Sugestões usam CPF ou nome completo e nascimento. Não há fusão automática por CPF, nome ou e-mail. As restrições de unicidade dos cadastros existentes continuam válidas.

A aprovação cria ou aproveita o aluno na coligada do requerimento. Para aluno novo, RA é o código da pessoa; um aluno já existente mantém o RA atual. Cria vínculos de pai/mãe/outros com as atribuições selecionadas e concede a rematrícula ao responsável acadêmico. Caso já existam atribuições incompatíveis ou outro responsável financeiro, a operação é interrompida para ajuste consciente na ficha.

A aprovação é transacional e idempotente: falhas desfazem os cadastros e contas da operação, e repetir o mesmo envio/aprovação não os duplica. São preservados os dados originais, os dados conferidos, o resultado, o usuário e a data da conferência, além da auditoria do ERP.

**Aprovar requerimento não gera matrícula, contrato, parcelas nem integração Educbank.** Essa etapa continua sendo realizada no fluxo de matrícula da secretaria.

## Permissões

Para conferir, o perfil precisa de acesso ao menu **Requerimentos** e à capacidade de gerenciar pessoas. A conferência completa, seus anexos e seu PDF exigem também **Dados pessoais**, **Ficha médica** e **Dificuldades de aprendizagem**, em **Perfis e acessos**. Grupos de campos reconfigurados continuam exigindo a autorização do grupo. O administrador tem acesso completo.

Os campos canônicos com prefixo `saude_` permanecem protegidos como ficha médica, `aprendizagem_` como dificuldades de aprendizagem e `familia_`/`contatos_emergencia` como dados pessoais, mesmo se forem movidos para outra área visual.

A gestão de autorizações de retirada exige acesso a Alunos, gerenciamento de pessoas e Dados pessoais. Nenhuma autorização de retirada concede perfil de responsável, acesso ao portal ou direito de rematrícula. Uma autorização pode ser editada ou revogada, preservando o registro e a auditoria.

Os requerimentos e autorizações pertencem a uma coligada. Pessoas e usuários continuam compartilhados.

## Arquivos e PDF

Aceita JPG, PNG e WebP para foto; PDF, JPG, PNG e WebP para documentos. Limite de 5 MB por arquivo e 15 MB somados. Conteúdo, extensão e MIME são verificados no servidor. Documentos de guarda/adoção só são aceitos quando aplicáveis às respostas.

Enquanto pendentes, os arquivos são armazenados criptografados, sem URL pública de mídia. O download autenticado verifica o hash do arquivo. Apenas a foto explicitamente aprovada é publicada na biblioteca de mídia para atender ao comportamento já existente de foto/avatar. Falhas na aprovação removem os arquivos de mídia e miniaturas criados naquela tentativa.

**Baixar PDF** gera um documento com a logo do colégio, protocolo, estado da conferência, pessoas, atribuições, respostas completas, contatos, autorizações, foto e relação dos anexos. Guarda a declaração e o texto de privacidade vigentes no momento do envio. Após aprovação, o documento utiliza os dados conferidos e informa que o original foi preservado. O PDF é gerado para download autenticado e não é salvo como arquivo público.

Documentos de guarda/adoção são preservados como anexos do requerimento; não ganham automaticamente o status de documento aprovado em outro checklist. Podem ser consultados na ficha pelo painel de requerimentos de origem, conforme as permissões.

## Endpoints

Base: `/wp-json/erp-educacional/v1`.

| Método e caminho | Acesso / finalidade |
| --- | --- |
| `GET /publico/requerimentos/{colegio}` | Público: catálogo, textos, estados civis e desafio de envio; sem pessoas cadastradas |
| `POST /publico/requerimentos/{colegio}` | Público: multipart com `dados` JSON, `token`, `website` vazio e anexos opcionais `foto`, `guarda`, `adocao`; cabeçalho `Idempotency-Key` |
| `GET /requerimentos-publicos` | Equipe: fila filtrada por `status`, `search`, `page` |
| `GET /requerimentos-publicos/formulario` | Equipe: catálogo para conferência |
| `GET /requerimentos-publicos/{id}` | Equipe autorizada: conferência completa |
| `POST /requerimentos-publicos/{id}/aprovar` | Equipe autorizada: versão, dados conferidos e decisões de cada pessoa |
| `POST /requerimentos-publicos/{id}/rejeitar` | Equipe: versão e motivo |
| `GET /requerimentos-publicos/{id}/pdf` | Equipe autorizada: PDF em base64 |
| `GET /requerimentos-publicos/arquivos/{id}` | Equipe autorizada: arquivo privado em base64 |
| `GET/POST /retiradas-alunos/{idaluno}` | Gestão de autorizações de retirada |
| `GET /retiradas-alunos/{idaluno}/requerimentos` | Origem documental na ficha do aluno |
| `GET/POST /configuracoes/requerimento-publico` | Administrador: recebimento e textos por coligada |

A resposta pública de recebimento contém somente protocolo e confirmação, com HTTP 202. O desafio tem validade de duas horas, tempo mínimo de preenchimento, vínculo com a coligada e endereço do visitante, campo de proteção contra robôs e limite horário. Respostas têm `no-store`. O endpoint público não aceita IDs de usuário, foto de outra pessoa, campos internos arbitrários ou atribuições administrativas.

## Validação realizada

Testes de domínio, transações e permissões usam SQLite com adaptador WP de teste; verificações de rotas, proteção de entrada, arquivos criptografados, fotos e interface usam ambientes simulados. Fluxos de envio e aprovação foram exercitados em Chromium a 320, 390 e 1440 px. O PDF foi gerado e inspecionado visualmente.

A migração de produção em MySQL e os hooks reais da biblioteca de mídia do site devem ser homologados no WordPress. Nenhum requerimento ou usuário real foi criado para executar esses testes.
