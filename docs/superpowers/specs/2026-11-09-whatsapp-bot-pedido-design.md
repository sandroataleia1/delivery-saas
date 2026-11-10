# Atendimento e pedido pelo WhatsApp

**Data:** 09/11/2026 · **Base:** catálogo online (blocos A–C) já em produção em `delivery.esis.com.br`.
Marcas: **[usuário]** = decidido pelo usuário; **[proposta]** = decisão minha, que o usuário pode revisar.

## 1. Intenção

Hoje o WhatsApp da loja só **envia** avisos de etapa do pedido (bloco C). Quando um cliente escreve "boa noite, tem pizza hoje?", ninguém responde até alguém da loja pegar o celular. A loja quer que o próprio número:

1. responda sozinho com o link do cardápio digital e a opção de pedir ali mesmo pela conversa **[usuário]**;
2. quando o cliente escolhe pedir pela conversa, conduza o pedido completo com os produtos cadastrados no sistema **[usuário]**;
3. permita que um atendente humano assuma a conversa **pelo celular da loja**, que continua sendo o mesmo número **[usuário]**.

O pedido feito pela conversa entra como **pedido online**: aparece em "Pedidos online", a loja aceita ou recusa, e o cliente recebe cada mudança de status pelo WhatsApp, como já acontece com o catálogo **[usuário]**.

Tudo é construído **dentro do sistema** (Laravel), sem n8n nem ferramenta externa **[usuário]**. Motivo: as regras de cardápio, preço, sabores, adicionais, horário, pedido mínimo e criação do pedido já existem e são reaproveitadas, sem duplicação.

## 2. Objetivos

- **E1. Resposta automática.**
  - Toda conversa nova recebe uma saudação com o nome da loja, o link do cardápio e a linha "Se preferir pedir por aqui, digite 1" **[usuário]**.
- **E2. Bot de pedido completo** pelo texto da conversa.
  - Monta o pedido com o cardápio da loja: categorias, produtos, tamanhos, pizza com sabores e adicionais.
  - Coleta os dados do pedido: entrega ou retirada, endereço com bairro (zona de entrega), forma de pagamento na entrega com troco, e observação.
  - Mostra o resumo, pede confirmação e cria o pedido pelo mesmo caminho do catálogo (`PlaceOnlineOrder`).
- **E3. Passagem para o atendente.**
  - O bot se cala quando a loja fala pelo celular.
  - O cliente pode pedir um humano.
  - O painel avisa quando há cliente esperando.

## 3. Não-objetivos

- **Fora deste projeto:**
  - Pix online / Mercado Pago (bloco D), que fica de fora por enquanto **[usuário]**;
  - inteligência artificial ou entendimento de linguagem livre;
  - cupons e fidelidade;
  - campanhas e mensagens em massa;
  - atendimento por tela dentro do sistema ou pelo whaticket, porque o humano responde pelo celular **[usuário]**.
- Grupos, status/stories, chamadas e mensagens de listas de transmissão são ignorados **[proposta]**.
- Áudio, imagem, figurinha e localização recebem uma resposta curta pedindo para digitar o número da opção. Não são interpretados **[proposta]**.

## 4. Anti-objetivos (seria fracasso mesmo funcionando)

- O bot responder por cima do atendente, ou conversar com fornecedor, amigo ou entregador como se fosse cliente.
- Mandar a mesma saudação a cada mensagem do cliente.
- O bot criar pedido com preço, sabor ou adicional diferente do que o catálogo aceitaria.
- O bot criar pedido duplicado quando o cliente confirma duas vezes ou a mensagem chega repetida.
- Falha da Evolution, do webhook ou do bot travar a loja, o painel ou o catálogo.
- Uma loja ver ou responder conversa de outra loja.

## 5. Restrições

- **Mesma base dos blocos anteriores:**
  - Laravel 13 / PHP 8.5, Actions, FormRequests, migrations aditivas, textos em português;
  - nenhuma dependência nova;
  - fila `database` e worker já em produção.
- A Evolution API v2.3.6 já roda no servidor. Cada loja tem uma instância conectada pela tela "WhatsApp" (bloco C).
- **Mensagens recebidas** chegam por webhook da Evolution: evento de mensagem nova.
  - A rota do webhook é pública, mas identifica a loja e só aceita chamadas com um segredo por instância (na URL ou no cabeçalho).
  - Uma chamada sem segredo válido recebe 404.
- O processamento da mensagem recebida vai para a fila. O webhook responde 200 rápido e nunca devolve erro por falha do bot, para a Evolution não reenviar em loop.
- **Idempotência:** o id da mensagem do WhatsApp é guardado. Mensagem repetida pelo webhook é ignorada.
- **Tenancy:** webhook e fila rodam sem usuário logado. Toda consulta filtra `company_id` explicitamente e todo registro novo recebe `company_id` explícito.
- As mensagens de saída do bot usam o envio que já existe (`EvolutionClient::sendText`). Elas não entram na tabela de avisos de pedido do bloco C, que tem unicidade por pedido e evento.

## 6. Como funciona

### 6.1 Conversa e estado

- **Uma conversa por loja e número do cliente** **[proposta]**.
  - Guarda: o estado atual do roteiro, o carrinho em montagem, a última mensagem do cliente, a última mensagem da loja enviada pelo celular e o "atendente até" (pausa).
- **Conversa nova** = primeira mensagem do número, ou primeira depois de **12 horas** sem nenhuma mensagem **[proposta]**. Só a conversa nova recebe a saudação (E1).
- Comandos que valem em qualquer ponto **[proposta]**:
  - `0` chama o atendente;
  - `9` volta ao menu anterior;
  - "cancelar" desfaz o carrinho e volta à saudação.
- O carrinho da conversa expira depois de **2 horas** sem resposta do cliente **[proposta]**. Quando o cliente volta, o bot recomeça pela saudação.

### 6.2 Saudação (E1)

- O texto tem nome da loja, link do cardápio (`/loja/{slug}`), "Se preferir pedir por aqui, digite 1" e "Para falar com um atendente, digite 0".
- O texto é editável na tela "WhatsApp", com padrão pronto, como os textos do bloco C **[proposta]**.
- Uma chave liga ou desliga a resposta automática na mesma tela. O bot de pedido só funciona com ela ligada **[proposta]**.
- **Com a loja fechada ou pausada:** a saudação informa o próximo horário de abertura e oferece só o link e o atendente, sem a opção 1 **[proposta]**.
- **Com a loja online desativada ou sem endereço público:** não há resposta automática.

### 6.3 Roteiro do pedido (E2)

O cardápio vem em **listas numeradas no texto**, e não nos botões ou listas clicáveis do WhatsApp **[proposta]**. Com a conexão Baileys da Evolution esses botões falham ou nem aparecem, e número digitado funciona em qualquer celular.

1. **Categorias**, só as que têm produto disponível no catálogo online.
2. **Produtos da categoria**, com preço. Produtos esgotados ou fora do catálogo não aparecem.
3. **Composição, quando o produto exige:**
   - tamanho;
   - sabores, respeitando a quantidade permitida;
   - adicionais e opções, respeitando mínimo e máximo de cada grupo.

   As regras são exatamente as do catálogo (`ItemComposer` / regras de composição do `StoreOnlineOrderRequest`). Grupos com escolha múltipla aceitam números separados por vírgula ("1,3"), e "pular" vale onde o mínimo é zero.
4. **Quantidade** e observação do item, opcional ("sem cebola").
5. **Carrinho:**
   - "1 – adicionar mais itens";
   - "2 – remover um item";
   - "3 – finalizar".

   O carrinho mostra o subtotal.
6. **Entrega ou retirada**, conforme o que a loja aceita.
7. **Na entrega:**
   - bairro em lista numerada das zonas de entrega, com a taxa;
   - rua, número, complemento (opcional) e ponto de referência (opcional).

   Para cliente que já pediu antes, o bot oferece "1 – entregar no mesmo endereço da última vez" **[proposta]**.
8. **Nome:** o bot usa o nome do perfil do WhatsApp como sugestão e pede confirmação ou outro nome.
9. **Pagamento na entrega:** dinheiro (pergunta o troco), Pix na entrega ou cartão, conforme as formas que a loja aceita.
10. **Resumo:** itens, taxa, total, endereço e pagamento. "1 – confirmar", "2 – alterar", "0 – atendente".
11. **Confirmação:**
    - O bot chama `PlaceOnlineOrder` com um `client_uuid` fixo por carrinho, para que confirmar duas vezes não crie dois pedidos.
    - Se o catálogo recusar (loja fechou no meio, produto esgotou, abaixo do mínimo), o bot explica o motivo em uma frase e volta ao passo que resolve.
    - Com o pedido aceito no sistema, a mensagem "pedido recebido" do bloco C sai como hoje, com o link de acompanhamento.

O telefone do pedido é o número da conversa. Não é perguntado.

Resposta que o bot não entende recebe "Não entendi. Digite o número da opção." Na **segunda** seguida, o bot oferece o atendente (`0`) **[usuário: interação com humano]**.

### 6.4 Atendente humano (E3) [usuário: pelo celular da loja]

- **Quando a loja manda qualquer mensagem pelo celular** para um cliente (webhook com `fromMe` que não foi enviada pelo bot), a conversa entra em **pausa por 2 horas**.
  - Cada nova mensagem da loja renova a pausa **[proposta]**.
  - Durante a pausa o bot não responde nada, nem a saudação.
  - O sistema distingue as mensagens do bot das do celular guardando o id de cada envio feito pelo bot.
- **Quando o cliente digita `0`:**
  - o bot responde "Certo, um atendente vai falar com você por aqui" e entra em pausa;
  - um aviso aparece em "Pedidos online": "Cliente aguardando atendente no WhatsApp", com nome, número e um link `wa.me` para abrir a conversa no celular;
  - o aviso some quando a loja responde pelo celular ou quando a pausa acaba **[proposta]**.
- **Fim da pausa:** quando ela acaba sem resposta da loja, a próxima mensagem do cliente é tratada como conversa nova e recebe a saudação **[proposta]**.
- **Contatos que não são clientes:** a loja pode marcar um número como "não responder" (fornecedor, entregador, família) na tela "WhatsApp". Como reforço, qualquer número com quem a loja já falou pelo celular nas últimas 12 horas não recebe saudação **[proposta]**.

### 6.5 Tela "WhatsApp" (acréscimos)

- Chave "Responder automaticamente às mensagens", texto da saudação editável e lista de números que o bot não responde.
- Histórico das conversas recentes do bot: número, nome, estado (no roteiro, com atendente, pedido feito) e horário. Apenas leitura **[proposta]**.

### 6.6 Regras complementares [proposta]

- **Carrinho durante a pausa.**
  - O carrinho em montagem é guardado durante a pausa do atendente.
  - Se o cliente voltar a escrever depois da pausa e antes de o carrinho expirar (2 h sem resposta), o bot pergunta "1 – continuar o pedido / 2 – começar de novo".
  - Depois da expiração, a conversa recomeça pela saudação.
- **Identificador do pedido.**
  - O `client_uuid` vale para um conteúdo de carrinho. Se o carrinho mudar depois de uma tentativa recusada, gera-se um novo.
  - Confirmar duas vezes o mesmo conteúdo reaproveita o mesmo identificador e não cria segundo pedido. Isso evita tanto pedido duplicado quanto o 409 de conteúdo diferente.
- **Recusas do `PlaceOnlineOrder`.** Cada motivo tem uma frase própria:
  - loja fechada ou pausada;
  - produto indisponível;
  - abaixo do pedido mínimo;
  - limite de pedidos pendentes por telefone (`online.max_pending_per_phone`);
  - empresa inativa.

  Qualquer outro erro recebe uma frase genérica e a oferta do atendente.
- **Formas de pagamento.**
  - São as do catálogo: `cash` (dinheiro, com pergunta de troco), `pix` (Pix na entrega), `credit` e `debit`.
  - As regras de validação do catálogo não mudam.
- **Contato já atendido.** O reforço "a loja falou com este número pelo celular nas últimas 12 horas, então não há saudação" vale mesmo para cliente que escreve primeiro. Quem já está em conversa com a loja continua com a loja.
- **Webhook das instâncias existentes.**
  - O webhook é registrado em todo "Conectar".
  - Um comando de manutenção registra o webhook em todas as instâncias já conectadas, e roda uma vez na implantação.
- **Primeira tarefa da execução: capturar payloads reais.**
  - Com a loja conectada em produção, registram-se algumas mensagens de verdade: mensagem de cliente, mensagem enviada pelo celular da loja (`fromMe`) e, se aparecer, remetente `@lid`.
  - A chave da conversa e a detecção da pausa são fixadas a partir desses payloads, não de suposições.
  - Se o número não vier resolvido para parte dos clientes, esses recebem só o link e o atendente (6.3).

## 7. Riscos e pontos de atenção

- **Formato do número no webhook.** Versões recentes do WhatsApp podem identificar o remetente por um id "LID" (`...@lid`), e não pelo número (`...@s.whatsapp.net`). A Evolution v2.3.x costuma trazer o número em campo alternativo.
  - O construtor deve verificar o payload real da versão instalada.
  - Sem número de telefone resolvido, o bot não oferece pedido pela conversa (só link e atendente), porque o pedido precisa de telefone válido.
- **Configuração do webhook na Evolution.** Ao conectar ou reconectar a instância na tela "WhatsApp", o sistema registra o webhook dela apontando para o próprio sistema. Instâncias já conectadas recebem o webhook no próximo "Conectar" ou por um comando de manutenção.
- **Ban do número.** Responder só a quem escreveu primeiro mantém o volume baixo e transacional. Não há envio ativo.
- **Pedidos falsos.** Qualquer pessoa pode pedir pela conversa, como já pode pelo catálogo. A loja continua aceitando ou recusando cada pedido no painel.
- **Carga.** A VPS de produção já tem CPU alta. O bot é texto, sem IA, e roda no worker existente.

## 8. Deixado para o construtor

- Modelagem das tabelas de conversa e mensagens recebidas, e formato do estado do roteiro.
- Como reaproveitar a montagem do cardápio e as regras de composição do catálogo sem duplicar validação.
- O texto exato de cada mensagem do roteiro, curto, em português simples, sempre listando as opções numeradas.
- Testes:
  - de ponta a ponta do roteiro com payloads de webhook simulados, incluindo pizza meio a meio, adicionais com mínimo e máximo, loja que fecha no meio, confirmação repetida, mensagem duplicada no webhook, pausa por mensagem da loja e isolamento entre duas lojas.
