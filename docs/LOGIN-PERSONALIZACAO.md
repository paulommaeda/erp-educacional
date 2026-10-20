# Login do portal — 0.15.2

A tela de acesso apresenta a identidade do colégio à esquerda e o formulário à direita. Em celulares e tablets menores, a apresentação é compacta e aparece acima do formulário. A logo segue o cadastro do colégio.

Em **Configurações → Colégio e cores → Personalizar colégio → Tela de login**, configure:

- **Cores do login:** acompanhe a identidade do colégio ou mantenha uma paleta exclusiva para o acesso. A paleta própria fica guardada ao alternar entre as opções. Cores de login já personalizadas em versões anteriores são preservadas automaticamente.
- **Fundo da apresentação:** cor sólida, degradê ou imagem da biblioteca de mídia. Imagens recebem uma camada da cor de fundo para favorecer a leitura.
- **Formulário:** largura, arredondamento dos campos e botões, cores de texto, campos e botão.
- **Textos da apresentação e do formulário:** etiqueta da logo, chamada, título, descrição, frase institucional, boas-vindas, título e descrição do formulário, texto do botão e rodapé. Use `{colegio}` para inserir o nome do cadastro automaticamente. Quebras de linha no título da apresentação são preservadas.

A prévia acompanha as alterações antes de salvar. Textos usam a grafia informada, sem conversão para maiúsculas; as chamadas curtas recebem o estilo visual da tela.

A recuperação e a redefinição de senha usam o mesmo layout, com instruções adequadas a cada etapa. Autenticação, preenchimento automático, manter conectado, nonces, limites de tentativas e envio do e-mail de recuperação continuam usando as APIs do WordPress e os destinos do portal. O botão **Mostrar/Ocultar** altera apenas a visibilidade da senha no navegador.

As configurações continuam por coligada e fazem parte da exportação de personalização. Esta atualização mantém o schema 30.
