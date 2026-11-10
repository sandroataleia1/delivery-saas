# Catálogo online, opções/pizza, WhatsApp e Pix

**Data:** 08/11/2026. **Estado:** aprovado expressamente pelo usuário em 08/11/2026, incluindo as propostas marcadas. Nada implementado; implantação não autorizada.
**Base:** `phase4/production-flow` (Fase 4 concluída, não implantada). Regras das Fases 3 e 4 continuam válidas.
**Convenção:** o que o usuário decidiu está marcado **[usuário]**; o que é proposta do autor do desenho está marcado **[proposta]** e pode ser revisto na aprovação.

## 1. Intenção

A loja quer um **catálogo online público** em que o cliente monta o pedido de delivery ou retirada sozinho, paga na entrega ou por Pix online, e acompanha cada etapa pela página e pelo WhatsApp. A referência de fluxo é o Anota AI **[usuário]**. Hoje só existe o QR de mesa (Fase 3) e o delivery lançado pela equipe. Pizza com sabores, adicionais e observação não existem em canal nenhum.

Sucesso: um cliente pede pelo link, a loja aceita com um toque, a cozinha recebe os itens já montados, o cliente é avisado a cada etapa sem ninguém digitar mensagem, e o estoque e o caixa fecham certo, inclusive com sabores e adicionais.

## 2. Decisões do usuário

1. Catálogo público para **delivery e retirada**, com acompanhamento pela página e pelo WhatsApp **[usuário]**.
2. **Pizza com até N sabores por tamanho**, configurado pela empresa. O máximo citado é 2, mas o número vem da configuração **[usuário]**.
3. **Adicionais e observação** por item **[usuário]**.
4. Sabores e adicionais valem para **todos os canais**: PDV, mesa, QR da mesa, delivery da equipe e catálogo **[usuário]**.
5. **Tudo baixa estoque**: produto, sabores, bordas e adicionais **[usuário]**.
6. O cliente **recebe atualização pelo WhatsApp a cada etapa**, via **Evolution API**, já instalada no servidor do usuário **[usuário]**.
7. **A loja precisa aceitar** o pedido online antes de ele ir para a produção **[usuário]**.
8. **Pix online pelo Mercado Pago**, além do pagamento na entrega **[usuário]**.
9. **O estorno é manual**: a loja devolve pelo Mercado Pago e só registra no sistema **[usuário]**.
10. **Dias e horários de funcionamento** são cadastrados pela loja no sistema **[usuário]**.
11. O cliente se identifica **só com nome e WhatsApp**, sem conta nem senha **[usuário]**.

## 3. Objetivos, não objetivos e antiobjetivos

**Objetivos:** os itens 1–11 acima, com isolamento por empresa, idempotência, auditoria e locks no padrão das Fases 3 e 4.

**Não objetivos desta entrega:**
- estorno automático, cartão online, pedido agendado e feriados (a pausa manual cobre os feriados);
- código de verificação por WhatsApp, cupons, fidelidade e chatbot de atendimento;
- mensagens de marketing, cálculo de taxa por distância ou mapa, e integração com iFood ou marketplaces;
- multiloja e domínio próprio por loja;
- WebSocket (continua o polling) e implantação.

**Antiobjetivos** (falhas mesmo que "funcione"):
- pedido online chegar à cozinha sem aceite;
- cliente pagar e o pedido não chegar à loja, ou chegar sem a loja saber que já está pago;
- preço, taxa ou total aceitos do navegador;
- falha do WhatsApp ou do Mercado Pago impedir o pedido ou travar a loja;
- página pública expor dados de outro cliente, como o endereço por telefone;
- estoque de sabor ou adicional baixar errado ou em dobro;
- cozinha recebendo item montado de forma ilegível.

## 4. Ordem de entrega [proposta]

Quatro blocos dependentes, cada um testável e entregável sozinho, nesta ordem:
- **A.** Opções e pizza em todos os canais, com estoque.
- **B.** Catálogo online, horários, aceite e acompanhamento.
- **C.** WhatsApp pela Evolution API.
- **D.** Pix pelo Mercado Pago.

B funciona sem C e D, com pagamento na entrega e acompanhamento pela página.

## 5. Bloco A — opções e pizza

### 5.1 Modelo [proposta]

- **Grupo de opções:** pertence à empresa e pode ser reutilizado em vários produtos, como "Adicionais de lanche" ou "Bordas".
  - Campos: nome, mínimo, máximo, se permite quantidade por opção (por exemplo "+2 bacon") e ordem.
  - Obrigatório significa mínimo ≥ 1.
- **Opção:** nome, preço adicional, ativo e pausado.
  - Pausado é o equivalente ao esgotado, separado de ativo.
  - Cada opção pode ter ficha de ingredientes (ingrediente = `Product`, quantidade), igual a `RecipeItem`.
- **Produto ↔ grupos:** ligação ordenada. Um produto sem grupos funciona exatamente como hoje.
- **Pizza:** um produto marcado como pizza tem:
  - **Tamanhos:** nome, fatias (informativo), **máximo de sabores do tamanho** **[usuário: configurável]** e **ficha base por tamanho** **[proposta]**, com massa, caixa e o que vale para qualquer sabor.
  - **Sabores:** um grupo próprio de cada produto pizza, sem compartilhar entre produtos nesta versão **[proposta]**. Cada sabor tem **preço por tamanho**, que é o **preço da pizza inteira daquele sabor naquele tamanho**, como no Anota, e **ficha de ingredientes por tamanho** **[proposta]**.
  - **Regra de preço** escolhida pela empresa por produto pizza **[proposta]**:
    - **maior valor**: o preço do sabor mais caro;
    - **média**: a média dos preços dos sabores, que é o mesmo que somar o preço de cada sabor × 1/n.

    O padrão é **maior valor**. A "soma" citada na conversa foi descartada porque, com o preço da pizza inteira por sabor, ela cobraria a pizza duas vezes. O tamanho não tem preço base somado.
  - **Outros grupos, como borda:** podem ter **preço por tamanho** opcional **[proposta]**. Sem esse preço, vale o preço único da opção.

### 5.2 Montagem e preço

- O servidor sempre recalcula o preço a partir do banco. Do navegador só se aceitam ids e quantidades de opções.
  - O PDV continua conferindo o preço esperado da linha (`expected_price`), que agora inclui as opções. Se o preço divergir, a resposta é a mesma de hoje: "o preço mudou, confira o carrinho".
- **Validação:**
  - mínimos e máximos de cada grupo;
  - tamanho obrigatório na pizza, e de 1 até o máximo de sabores do tamanho;
  - opção pausada ou inativa é recusada em pedido novo, com a mesma regra de replay do esgotado da Fase 4;
  - toda opção precisa pertencer à empresa e ao produto.
- **Preço unitário da linha:**
  - produto comum = preço do produto + soma de (preço da opção × quantidade da opção);
  - pizza = valor dos sabores pela regra + soma de (preço da opção no tamanho × quantidade da opção).

  No cardápio, "a partir de" é o menor preço possível sem opcionais.
  - O arredondamento segue o `Decimal` existente: dinheiro com 2 casas, sem float.
  - Na regra média, a divisão é feita com precisão alta e o arredondamento acontece só no fim.
- A **observação** livre continua no campo `notes` existente.

### 5.3 Snapshot nas linhas [proposta]

Cada tabela de linha recebe uma coluna `options` em JSON, imutável, gravada pelo servidor:
- tabelas: `sale_items`, `table_order_items`, `delivery_order_items`, `menu_order_receipt_items`, a linha do pedido online e `production_works`;
- conteúdo: lista de {grupo, opção id, nome, quantidade, preço unitário, fração do sabor, tamanho id e nome}.

O nome e o preço exibidos e cobrados vêm do snapshot. Mudar o cardápio depois não altera pedido antigo. A linha da produção recebe também um **texto legível** derivado do snapshot. Exemplo: "Grande · ½ Calabresa ½ Quatro queijos · Borda catupiry · +1 Bacon".

Uma linha com opções é sempre uma linha própria. Dois itens iguais com opções diferentes nunca se fundem.

### 5.4 Estoque **[usuário: tudo baixa]**

O momento da baixa **não muda**: fechamento da comanda, acerto do delivery e venda do PDV, todos pelo `RegisterSale`/`ConsumeProduct`. Na baixa:
- o produto comum baixa como hoje, pela ficha técnica ou pelo próprio saldo;
- a pizza baixa a **ficha base do tamanho** × quantidade da linha, no lugar da ficha única do produto;
- **cada opção** baixa a ficha dela × quantidade da opção × quantidade da linha;
- **cada sabor** baixa a ficha do tamanho escolhido × fração (1/número de sabores) × quantidade da linha;
- opção sem ficha não baixa nada e só soma no preço.

Os insumos das opções entram no lock ordenado por id de `RegisterSale::lockProducts`, para manter a disciplina contra deadlock. A ficha usada é a **atual no momento da baixa**, igual ao comportamento de hoje para produtos, e não um snapshot. O estoque negativo continua permitido com alerta. A movimentação registra a origem (produto/opção/sabor) na observação.

O acerto de mesa e de delivery hoje passa ao `RegisterSale` só `product_id`, `quantity` e `fixed_price`. Ele passa a enviar também o snapshot `options`, e o `fixed_price` continua sendo a fonte do preço.

### 5.5 Telas

- **Cadastro:** grupos, opções, fichas, pausar opção e configuração de pizza (tamanhos, sabores, preço e ficha por tamanho, regra de preço).
- **Montagem:** o mesmo componente de montagem é usado no PDV, no lançamento de mesa, no delivery da equipe, no QR e no catálogo.
  - Não deixa avançar sem o obrigatório e mostra o preço calculado.
  - O servidor é quem decide.
- **Exibição:** painel de produção, comanda impressa, detalhe do pedido e cupom mostram o texto legível.

## 6. Bloco B — catálogo online

### 6.1 Loja e horários

- **Configuração da loja online** [proposta], por empresa:
  - ligar/desligar e endereço público curto (slug único);
  - aceita entrega e/ou retirada, pedido mínimo e texto de tempo estimado;
  - WhatsApp da loja (para o botão "falar com a loja");
  - minutos para aceite (padrão **15** [proposta], como no Anota);
  - logo e foto de produto opcionais [proposta: upload simples, sem dependência nova].
- **Horários [usuário]:** grade semanal por dia.
  - Cada dia pode ter **vários turnos** e um turno pode **passar da meia-noite** [proposta].
  - Fuso America/Sao_Paulo.
- **Pausa manual** [proposta]: fecha a loja por cima da grade até reabrir. Cobre feriados e cozinha lotada.
- Fora do horário ou pausada, o catálogo mostra "Fechado · abre hoje às 18h" e o servidor recusa o envio. A conferência vale no momento de gravar, não só na tela.

### 6.2 Página pública

Rota pública pelo slug, sob `public.page` e throttle, com o padrão das Fases 3 e 4. Telas de referência (rascunho visual mostrado ao usuário):
1. **Cardápio:** cabeçalho com aberto/fechado, tempo, taxa por bairro e mínimo; abas de categoria; produtos com foto, descrição e preço "a partir de"; esgotado visível e desabilitado; sacola.
2. **Montagem:** a do bloco A.
3. **Finalizar:**
   - entrega ou retirada, nome e WhatsApp;
   - bairro escolhido entre as `DeliveryZone` da loja, com taxa calculada pelo servidor, e endereço;
   - subtotal, taxa e total;
   - pagamento: na entrega (dinheiro com troco, Pix ou cartão) ou Pix agora (bloco D).
4. **Acompanhar:** número do pedido e etapas Recebido → Aceito → Em preparo → Pronto/Saiu → Entregue/Retirado, com horários; aviso de que o cliente recebe as etapas no WhatsApp; botão "Falar com a loja no WhatsApp".

A página não expõe SKU, código de barras, custo, nem dados de outros pedidos. Bairro fora das zonas cadastradas não aceita entrega [proposta]; a loja cadastra os bairros que atende.

### 6.3 Cliente [usuário: nome e WhatsApp]

- O telefone é normalizado (DDD + número, formato brasileiro) e procurado na empresa. Se existir, o pedido é ligado ao `Customer`; se não, é criado.
- O endereço entra em `CustomerAddress` como o `CreateDeliveryOrder` já faz.
- O próprio navegador guarda nome, telefone e último endereço [proposta]. O servidor **nunca** devolve endereços salvos a partir de um telefone digitado.
- Acompanhamento por **token secreto do pedido**, longo e aleatório, no link da tela e do WhatsApp.
- Rate limit por telefone, IP e empresa [proposta: valores conservadores no padrão da Fase 3], e limite de pedidos pendentes por telefone.

### 6.4 Pedido online e aceite [usuário: a loja precisa aceitar]

O pedido online é um **recebimento separado** do delivery, no padrão do `MenuOrderReceipt` da Fase 3. Estados [proposta]:
- `awaiting_payment`: só para o Pix online;
- `pending`: aguardando aceite;
- `accepted`: estado terminal do recebimento;
- `rejected`;
- `expired`.

**Número do pedido [proposta]:** o pedido online recebe na hora do envio um número sequencial próprio por empresa, que é o número que o cliente vê na página e no WhatsApp do começo ao fim. O delivery criado no aceite mantém a numeração dele, sem furos, e mostra "Online #N" ao lado. A equipe busca por qualquer um dos dois.

O envio é idempotente por `client_uuid` e grava o snapshot de itens, opções, preços, taxa e total calculados pelo servidor. **Antes do aceite**, nada vira delivery, produção, venda, caixa ou estoque.

**Painel da loja ("Pedidos online"):**
- lista de pendentes com contador de tempo e som (som só após ativação, como na Fase 3);
- botões **Aceitar** e **Recusar com motivo**;
- permissão nova `online_orders.accept`, concedida aos perfis que hoje confirmam QR [proposta].

**Aceitar**, na mesma transação, com locks na ordem Company → recebimento → produtos e opções:
1. revalida produto esgotado e opção pausada;
2. cria o `DeliveryOrder` pelo mesmo caminho do `CreateDeliveryOrder`, com o snapshot do recebimento, o responsável pelo aceite e a ligação ao recebimento;
3. encaminha à produção pelo `DispatchProduction`.

Se algo ficou indisponível, o aceite é recusado com orientação para recusar o pedido ou falar com o cliente. A loja não altera o pedido do cliente nesta versão [proposta].

**Recusar** exige motivo. **Expirar:** um comando agendado a cada minuto marca `expired` o pendente sem aceite depois do prazo e o `awaiting_payment` vencido.

Depois do aceite, o pedido segue **exatamente** o fluxo de delivery da Fase 4: produção por item, saída, entrega e acerto. A tela de delivery mostra a origem "Online".

### 6.5 Acompanhamento

A página do token consulta por polling e deriva a etapa do recebimento, depois do `DeliveryOrder` e do status sincronizado pela Fase 4. Para retirada, a etapa "Pronto para retirar" substitui "Saiu para entrega". Cancelamento e recusa mostram o motivo.

## 7. Bloco C — WhatsApp (Evolution API)

- **Conexão** [proposta]:
  - A URL e a chave global da Evolution do usuário ficam no `.env` da plataforma.
  - Cada empresa conecta **o próprio número**: nas configurações, o sistema cria a instância da empresa na Evolution e mostra o QR. O estado conectado/desconectado aparece na tela.
  - O token da instância é guardado cifrado.
- **Eventos** que geram mensagem, só de pedidos do catálogo online nesta versão [proposta]:
  - recebido (aguardando aceite);
  - pagamento confirmado;
  - aceito;
  - em preparo;
  - pronto para retirar ou saiu para entrega;
  - entregue;
  - recusado (com motivo e, se pago, "seu pagamento será devolvido pela loja");
  - cancelado;
  - expirado.
- **Textos editáveis por empresa** [proposta], com padrões prontos e marcadores {nome}, {loja}, {numero}, {link}, {motivo}, {total}. Toda mensagem leva o link de acompanhamento. Só mensagens transacionais.
- **Envio garantido sem travar o pedido:**
  - Cada evento grava uma linha de saída na **mesma transação** da mudança de estado, com unicidade por (pedido, evento) para nunca mandar duplicado.
  - Um job na fila envia **depois do commit**, com novas tentativas e espera crescente.
  - Falha ou número desconectado nunca desfaz nem bloqueia o pedido.
  - A loja vê o histórico de envios e um alerta de "WhatsApp desconectado".
- O risco de bloqueio do número por uso não oficial (Evolution) é do usuário, que escolheu essa API. Volume baixo e só transacional reduz o risco.

## 8. Bloco D — Pix pelo Mercado Pago

- **Conta da loja** [proposta]: cada empresa informa as próprias credenciais do Mercado Pago (access token e segredo do webhook), guardadas cifradas. O dinheiro cai na conta da loja e a plataforma não intermedeia. A conexão por OAuth/marketplace fica para depois.
- **E-mail só no Pix [proposta]:** o Mercado Pago exige e-mail do pagador. O campo de e-mail aparece **somente** quando o cliente escolhe "Pix agora", e o navegador o lembra. Não se usa e-mail fictício. Pagamento na entrega continua só com nome e WhatsApp.
- **No checkout**, o cliente escolhe "Pix agora":
  1. O recebimento é gravado como `awaiting_payment`.
  2. O servidor cria o pagamento Pix na API com chave de idempotência, referência externa ligada ao recebimento e expiração [proposta: 15 min].
  3. A tela mostra o QR e o copia-e-cola.
  4. Polling na tela do pedido.
- **Confirmação:** só vale a confirmação vinda do Mercado Pago, **nunca** o navegador.
  - O webhook tem assinatura verificada e, em seguida, o servidor **consulta o pagamento na API** e confere status aprovado, valor igual ao total e referência do próprio recebimento.
  - Se o webhook não chegar, o polling da tela dispara uma consulta ao Mercado Pago pelo servidor, com limite de frequência.
  - Confirmado, o recebimento passa a `pending` e aparece para a loja aceitar, marcado **PAGO**.
  - O pagamento é idempotente: confirmar duas vezes não cria efeito duplo.
- **Pago fora de hora:** se a aprovação chegar depois de o recebimento expirar, ele não volta à fila. Fica com **estorno pendente** e alerta à loja [proposta].
- **No acerto:** o delivery guarda o valor pago online. O acerto pré-preenche a forma nova **"Pix online"** com esse valor, sem alteração, e o entregador e a comanda mostram "JÁ PAGO, não cobrar". A venda entra no caixa da sessão que fizer o acerto, como forma não dinheiro [proposta].
- **Estorno [usuário: manual]:**
  - Recusa, expiração após pagamento ou cancelamento de pedido pago geram **estorno pendente** com o valor.
  - A loja devolve no Mercado Pago e marca "estornado". Ficam registrados o responsável, a data e uma observação opcional, e há uma lista de estornos pendentes.
  - O valor nunca entra no caixa como venda.

## 9. Restrições

- **Pilha:** Laravel 13, PHP 8.5, Inertia 3/Vue 3, Tailwind 4, PHPUnit e Pint.
- **Dependências:** nenhuma nova. Evolution e Mercado Pago entram pelo cliente HTTP do Laravel; a fila usa o driver `database` que já existe.
- **Implantação:** passa a exigir **worker de fila** e **agendador** (cron), além de **URL pública HTTPS** para o webhook e para os links. Hoje não existe job nem comando agendado no projeto.
- **Padrões do código existente:** Actions, FormRequests, locks Company-first, snapshots, idempotência por `client_uuid`, migrations aditivas, valores decimais em string/`bcmath` e auditoria.
- **Testes:** feature tests por bloco, e Evolution e Mercado Pago simulados com `Http::fake`. Os cenários de concorrência no harness MariaDB das Fases 3 e 4 cobrem: aceitar × expirar, aceitar × esgotar, webhook duplicado, webhook × expiração e envio repetido.
- **Fora desta entrega:** nada de implantação.

## 10. Deixado para quem implementa

- Nomes de tabelas, colunas, classes e rotas.
- Divisão em tarefas, desde que respeite a ordem A→B→C→D.
- Formato visual fino das telas, seguindo o padrão atual e o rascunho mostrado.
- Os valores iniciais de rate limit e de limites de pedido, conservadores e configuráveis.
- O detalhe das chamadas da Evolution e do Mercado Pago (endpoints, campos obrigatórios como o e-mail do pagador no Pix e o formato da assinatura do webhook), **conferidos na documentação oficial no momento da implementação**.
