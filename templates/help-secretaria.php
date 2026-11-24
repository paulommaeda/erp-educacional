<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

return [
    'requerimentos'=>[
        'topic'=>'Admissão e cadastro',
        'title'=>'Conferir e aprovar requerimentos',
        'intro'=>'Confira a solicitação da família, aproveite cadastros existentes e prepare a ficha do aluno.',
        'menus'=>['requerimentos'],
        'screen'=>'requerimentos',
        'keywords'=>'admissão inscrição fluent forms externa protocolo ficha guarda adoção',
        'sections'=>[
            'Localizar o requerimento'=>[
                'Abra Requerimentos. Use Formulário público para os pedidos recebidos pelo formulário do ERP ou Importados por API para os recebidos de uma integração.',
                'No Formulário público, filtre os pendentes e pesquise pelo nome ou pelo protocolo. Clique em Conferir. Use Baixar PDF ou abra os anexos de foto, guarda ou adoção, quando houver.',
                'No fluxo por API, selecione a conexão correspondente ao formulário. Se o requerimento ainda estiver no sistema de origem, use Buscar para conferência e busque a página da conexão.',
            ],
            'Conferir e aprovar'=>[
                'Revise nome, nascimento, documentos, contatos e a série de interesse. Confirme quais familiares foram informados, os responsáveis acadêmico e financeiro e as autorizações de retirada.',
                'Em Destino de cada cadastro, escolha Criar nova pessoa ou Usar pessoa existente. Confira a identidade antes de aproveitar uma pessoa. Para a mesma pessoa indicada em dois blocos, use Mesma pessoa de outro bloco quando disponível.',
                'Clique em Aprovar e criar cadastros. Confira o RA retornado e os vínculos em Alunos. Se a conexão usa a conferência simples de pessoas, confirme os cadastros oferecidos pela tela e complete o aluno e os vínculos na ficha.',
            ],
            'Tratar divergências'=>[
                'Se faltar informação essencial, mantenha o requerimento pendente enquanto confirma os dados com a família. Para rejeitar um requerimento do formulário público, clique em Rejeitar e registre o motivo.',
                'Se o aluno já existir, procure pelo RA antes de criar outro cadastro. Para rever o pedido, consulte o histórico da aba de origem.',
            ],
        ],
        'note'=>'O RA confirma o cadastro do aluno. A matrícula é concluída ao selecionar a turma e confirmar a matrícula no período letivo.',
        'related'=>['cadastro-aluno','responsaveis','matricular'],
    ],
    'cadastro-aluno'=>[
        'topic'=>'Admissão e cadastro',
        'title'=>'Cadastrar pessoa e aluno pela secretaria',
        'intro'=>'Use o cadastro manual quando a escola já tem os dados e precisa abrir a ficha do aluno.',
        'menus'=>['alunos','pessoas'],
        'screen'=>'alunos',
        'sections'=>[
            'Conferir a pessoa'=>[
                'Em Pessoas, pesquise pelo nome ou código antes de usar Cadastrar novo. Confira o nascimento e os dados de contato para distinguir homônimos.',
                'Se a pessoa já existir, aproveite esse cadastro. Para uma pessoa nova, preencha os dados pedidos e confira o e-mail antes de salvar.',
            ],
            'Abrir a ficha do aluno'=>[
                'Em Alunos, pesquise pelo nome ou RA. Se a ficha já existir, clique em Abrir ficha.',
                'Para um aluno novo, clique em Cadastrar aluno, selecione a pessoa que você conferiu em Pessoas e escolha o tipo de aluno. Clique em Salvar e abrir ficha.',
                'Na ficha, confira o RA, os dados cadastrais e a aba Pais e responsáveis. A aba Matrículas e turmas mostra os vínculos acadêmicos do aluno.',
            ],
        ],
        'related'=>['responsaveis','matricular','acesso-familia'],
    ],
    'responsaveis'=>[
        'topic'=>'Admissão e cadastro',
        'title'=>'Vincular pais e responsáveis',
        'intro'=>'Registre o parentesco e as atribuições de cada pessoa na ficha do aluno.',
        'menus'=>['alunos'],
        'screen'=>'alunos',
        'keywords'=>'pai mãe avó tutor financeiro guarda retirada',
        'sections'=>[
            'Criar o vínculo'=>[
                'Em Alunos, clique em Abrir ficha e escolha Pais e responsáveis.',
                'Clique em Vincular pai, Vincular mãe ou Outro responsável. Busque a pessoa cadastrada. Se precisar, use Cadastrar nova pessoa no formulário de vínculo.',
                'Confirme Vínculo com o aluno e marque as atribuições corretas em Responsável acadêmico, Responsável financeiro e Pode fazer rematrícula. Clique em Salvar vínculo.',
            ],
            'Conferir alterações e retirada'=>[
                'Confira as atribuições nos cartões das pessoas. Se já houver um responsável financeiro e for preciso substituí-lo, vincule primeiro a nova pessoa e solicite a troca a quem tem acesso à ação Trocar responsável financeiro.',
                'Quando houver autorização para buscar o aluno, abra Autorizações de retirada e clique em Cadastrar autorização. Essa autorização serve à retirada do aluno. As atribuições e o acesso ao portal são conferidos em Pais e responsáveis.',
            ],
        ],
        'note'=>'Use os vínculos registrados na ficha para conferir quais documentos do pai, da mãe ou de outro responsável financeiro aparecem para a família.',
        'related'=>['requerimentos','documentos-conferir','acesso-familia'],
    ],
    'matricular'=>[
        'topic'=>'Matrículas',
        'title'=>'Matricular alunos individualmente ou em lote',
        'intro'=>'Confirme a alocação no período certo e confira o plano antes de gerar contratos e parcelas.',
        'menus'=>['matriculas'],
        'screen'=>'matriculas',
        'keywords'=>'vincular período plano pagamento boleto RA turma lançamento matrícula lote',
        'sections'=>[
            'Matricular pela ficha'=>[
                'Localize o aluno e confirme o responsável financeiro em Pais e responsáveis antes de iniciar.',
                'Na aba Matrículas e turmas, use Vincular novo período. Escolha o período letivo e clique em Salvar período e escolher turma. Se houver um vínculo aguardando turma, use Selecionar turma no período já iniciado.',
                'Selecione curso, turno e turma. Confira o plano vinculado e os dados de pagamento solicitados, como a quantidade de parcelas e o vencimento inicial. Clique em Confirmar matrícula e contrato.',
            ],
            'Matricular pelo menu Matrículas'=>[
                'Clique em Matricular aluno. Selecione período, curso, turno e turma.',
                'Busque os alunos por nome ou RA e selecione quem pertence a essa mesma turma. Confira a lista e as condições de pagamento antes de clicar em Confirmar matrícula dos selecionados.',
            ],
            'Conferir o resultado'=>[
                'Na listagem de Matrículas, filtre pelo período e pesquise pelo nome ou RA. Confira a turma e a situação na ficha.',
                'Se a turma não aparecer, confira período, curso e turno. Se o pagamento não estiver disponível, peça a conferência do plano vinculado à turma.',
            ],
        ],
        'note'=>'A confirmação gera contrato e parcelas. Confira a turma e os valores antes de confirmar cada aluno ou lote.',
        'related'=>['cadastro-aluno','responsaveis','acesso-familia'],
    ],
    'documentos-conferir'=>[
        'topic'=>'Documentos',
        'title'=>'Receber e conferir documentos dos alunos',
        'intro'=>'Acompanhe a fila, envie um arquivo em nome da família e registre o resultado da conferência.',
        'menus'=>['documentos'],
        'screen'=>'documentos',
        'keywords'=>'upload RG CPF certidão arquivo rejeitado foto reenvio pendência',
        'sections'=>[
            'Encontrar e encaminhar um documento'=>[
                'Abra Documentos dos alunos. Use os filtros de situação, aluno ou RA e tipo de documento. A fila Aguardando conferência reúne os envios que a equipe precisa analisar.',
                'Se a secretaria recebeu o arquivo por outro canal, clique em Enviar documento para aluno. Busque pelo nome ou RA, selecione o aluno, abra seus documentos e envie no tipo correto. Use PDF, JPG, PNG ou WebP de até 5 MB por arquivo.',
                'Para ver as solicitações de uma única pessoa, você também pode abrir a aba Documentos na ficha do aluno, quando disponível.',
            ],
            'Conferir o conteúdo'=>[
                'Na fila, clique em Ver arquivo ou Conferir documento. Confirme se ele está legível, completo e pertence ao aluno ou à pessoa solicitada.',
                'Selecione Aprovar documento quando o arquivo estiver correto. Para pedir correção, selecione Rejeitar e solicitar novo envio e escreva o motivo. Clique em Confirmar conferência.',
                'Use o filtro Aprovados ou Rejeitados para revisar o resultado. A família pode reenviar pela ficha quando o documento for rejeitado e continuar solicitado.',
            ],
        ],
        'note'=>'Se a solicitação esperada não aparecer, confira a coligada, os vínculos ativos do aluno e a condição escolhida em Configurações. Os lembretes seguem as mesmas condições.',
        'related'=>['documentos-configurar','responsaveis'],
    ],
    'documentos-configurar'=>[
        'topic'=>'Documentos',
        'title'=>'Cadastrar tipos de documento e definir quando solicitar',
        'intro'=>'Configure os tipos por coligada e escolha quais alunos devem apresentar cada arquivo.',
        'menus'=>['documentos','configuracoes'],
        'screen'=>'configuracoes',
        'keywords'=>'pai mãe RG CPF outro obrigatoriedade lembretes cadastro',
        'sections'=>[
            'Cadastrar um tipo como administrador'=>[
                'Selecione a coligada de trabalho. Abra Configurações, escolha Documentos dos alunos e clique em Cadastrar documento.',
                'Informe o nome e as orientações. Em Solicitar para, use Todos os alunos para documentos como a certidão. Para documentos individuais, escolha Quando houver pai informado ou Quando houver mãe informada.',
                'Use Quando pai e mãe estiverem informados para um arquivo conjunto. Para RG ou CPF de outra pessoa indicada como responsável financeiro, escolha Quando o responsável financeiro for Outro.',
                'Marque Obrigatório se o documento deve ser cobrado quando a condição se aplicar. Mantenha o tipo Ativo e salve.',
            ],
            'Revisar uma solicitação'=>[
                'Edite o tipo se o nome, a orientação ou a condição mudarem. Para encerrar um tipo que já recebe arquivos, altere a situação para Inativo.',
                'Quando a família não enxergar um documento condicional, confira em Pais e responsáveis se a pessoa está vinculada com o parentesco e a atribuição corretos.',
            ],
        ],
        'related'=>['documentos-conferir','responsaveis'],
    ],
    'acesso-familia'=>[
        'topic'=>'Acesso da família',
        'title'=>'Enviar convites de acesso ao portal',
        'intro'=>'Ajude alunos e responsáveis a criar a senha e acompanhe o retorno dos convites.',
        'menus'=>['usuarios'],
        'screen'=>'usuarios',
        'keywords'=>'login senha email recuperação responsável',
        'sections'=>[
            'Enviar o convite'=>[
                'Abra Usuários e pesquise pelo nome, login ou e-mail. Confirme o destinatário com o cadastro da família.',
                'Em Editar, use Enviar convite de acesso quando disponível. Para selecionar várias contas, marque os nomes, clique em Enviar convites selecionados e confirme.',
            ],
            'Acompanhar o acesso'=>[
                'Confira a situação na listagem. Enviado indica o encaminhamento ao serviço de e-mail. OK indica que a pessoa concluiu a criação da senha pelo convite.',
                'Se houver falha, confira o endereço cadastrado e use Reenviar convite de acesso se disponível. Quem já tem senha pode usar a recuperação de senha no portal.',
            ],
        ],
        'related'=>['cadastro-aluno','responsaveis'],
    ],
];
