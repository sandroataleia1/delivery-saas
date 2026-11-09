# Atendimento e pedido pelo WhatsApp — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O número de WhatsApp da loja responde sozinho com o link do cardápio, conduz um pedido completo pela conversa (que entra como pedido online) e se cala quando um atendente assume pelo celular.

**Architecture:** A Evolution chama um webhook público por instância (`/webhooks/whatsapp/{instance}/{secret}`). O webhook só grava a mensagem crua (idempotente pelo id do WhatsApp) e despacha um job. O job interpreta o payload (`InboundMessage`), localiza a conversa da loja com aquele contato e passa a mensagem por um roteador (`WhatsAppBot`) que decide: ignorar, pausar, saudar, ou avançar a máquina de estados do pedido. Ao confirmar, o bot chama a mesma `PlaceOnlineOrder` do catálogo; preços, composição e regras de loja nunca são recalculados no bot.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia 3 + Vue 3, Tailwind 4, PHPUnit (`Http::fake`, `Queue::fake`, `travelTo`), fila `database`, Evolution API v2.3.6 (Baileys).

**Spec:** `docs/superpowers/specs/2026-11-09-whatsapp-bot-pedido-design.md`

## Global Constraints

- Nenhuma dependência nova. Migrations só aditivas. Textos de interface e mensagens em português simples.
- Tudo dentro do Laravel: sem n8n, sem IA, sem botões/listas interativas do WhatsApp — só listas numeradas em texto.
- Webhook: responde **200** rápido e nunca devolve erro por falha do bot; segredo inválido ou instância desconhecida → **404**. Processamento sempre na fila.
- Idempotência: `(company_id, wa_message_id)` único na tabela de mensagens recebidas; repetida é ignorada sem despachar job.
- Tenancy: webhook e job rodam sem usuário. Toda consulta usa `withoutGlobalScopes()` ou filtra `company_id` explicitamente; todo registro novo recebe `company_id` explícito.
- Mensagens do bot saem por `EvolutionClient::sendText` e **não** entram em `whatsapp_messages` (bloco C).
- Tempos fixos: conversa nova após **12 h** sem mensagem; pausa do atendente **2 h** (renovada a cada mensagem da loja); carrinho expira após **2 h** sem resposta do cliente; "loja falou com este número pelo celular nas últimas **12 h**" = sem saudação.
- Comandos globais: `0` atendente, `9` volta um passo, `cancelar` (sem diferenciar maiúsculas/acentos) zera o carrinho e volta à saudação.
- Duas respostas não entendidas seguidas → oferecer o atendente (`0`).
- Formas de pagamento: `config('online.payment_methods')` (`cash`, `pix`, `credit`, `debit`), rótulos de `config('sales.payment_methods')`.
- O pedido é sempre criado por `PlaceOnlineOrder::handle(OnlineStore, array)` com o mesmo payload do catálogo; o telefone é o da conversa.
- Padrões do projeto: Actions, `php artisan make:* --no-interaction`, Pint, auditoria nos ajustes da tela "WhatsApp".
- Prefixo de git em todo comando git e no `pint --dirty`: `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=C:/xampp/htdocs/delivery-saas`. Trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Push e deploy só com autorização do usuário.

## Review Focus

1. **Eco das próprias mensagens do bot.** A Evolution manda um `messages.upsert` com `fromMe: true` para cada `sendText` do bot. Se o id ainda não estiver gravado (corrida), o bot não pode se pausar sozinho. → teste na Task 3 (`test_bot_echo_with_unknown_id_but_same_text_does_not_pause`).
2. **Dois webhooks da mesma conversa processados juntos** (cliente manda "1" e "2" em sequência rápida). O estado não pode ser lido duas vezes antes de gravado. → job com trava por conversa e teste na Task 3.
3. **Carrinho alterado depois de uma recusa e confirmado de novo.** Precisa de `client_uuid` novo (senão 409); mesmo conteúdo reconfirmado reaproveita o uuid e não cria segundo pedido. → testes na Task 5.
4. **Produto/opção pausado ou esgotado entre a escolha e a confirmação.** O bot explica em uma frase e volta ao carrinho, sem pedido criado. → teste na Task 5.
5. **Remetente `@lid` sem número resolvido.** Só link + atendente; nunca entra no roteiro de pedido. → teste na Task 3.

---

## File Structure

- `database/migrations/2026_11_13_000001_create_whatsapp_bot_tables.php` — colunas novas em `whatsapp_settings` e tabelas `whatsapp_inbound_messages`, `whatsapp_conversations`, `whatsapp_bot_messages`.
- `app/Models/WhatsAppInboundMessage.php`, `WhatsAppConversation.php`, `WhatsAppBotMessage.php`.
- `app/Http/Controllers/WhatsAppWebhookController.php` — recebe, valida segredo, grava, despacha.
- `app/Jobs/ProcessWhatsAppInbound.php` — interpreta e chama o bot.
- `app/Support/WhatsAppBot/InboundMessage.php` — leitura do payload da Evolution (testada contra fixtures reais).
- `app/Support/WhatsAppBot/WhatsAppBot.php` — roteador: pausa, bloqueio, saudação, comandos globais, despacho para o roteiro.
- `app/Support/WhatsAppBot/OrderFlow.php` — máquina de estados do pedido (catálogo → item → carrinho).
- `app/Support/WhatsAppBot/CheckoutFlow.php` — entrega/retirada, endereço, nome, pagamento, resumo, confirmação.
- `app/Support/WhatsAppBot/BotCatalog.php` — cardápio disponível da loja para o bot (reusa `ProductComposition`).
- `app/Support/WhatsAppBot/BotReplies.php` — todos os textos fixos do bot (um lugar só).
- `app/Support/WhatsAppBot/BotSender.php` — envia pelo `EvolutionClient` e registra o id enviado.
- `app/Support/EvolutionClient.php` — `sendText` passa a devolver o id; novo `setWebhook`.
- `app/Actions/ConnectWhatsApp.php` — registra o webhook a cada conexão.
- `app/Console/Commands/RegisterWhatsAppWebhooksCommand.php` — `whatsapp:register-webhooks`.
- `app/Http/Controllers/WhatsAppController.php`, `app/Http/Requests/WhatsAppSettingsRequest.php`, `app/Actions/SaveWhatsAppSettings.php`, `resources/js/pages/online/WhatsApp.vue` — chave, saudação, números ignorados, conversas recentes.
- `app/Http/Controllers/OnlineOrderController.php`, `resources/js/pages/online/Orders.vue` — aviso "Cliente aguardando atendente no WhatsApp".
- `tests/Fixtures/whatsapp/*.json` — payloads reais capturados (Task 2).
- Testes: `tests/Feature/WhatsAppWebhookTest.php`, `WhatsAppBotRoutingTest.php`, `WhatsAppBotOrderTest.php`, `WhatsAppBotCheckoutTest.php`, `WhatsAppBotSettingsTest.php`; `tests/Unit/InboundMessageTest.php`.

---

### Task 1: Webhook que só grava, e registro do webhook na Evolution

**Files:**
- Create: migration acima, os três models, `WhatsAppWebhookController`, `ProcessWhatsAppInbound` (corpo vazio por enquanto: marca `processed_at`), `RegisterWhatsAppWebhooksCommand`
- Modify: `app/Support/EvolutionClient.php`, `app/Actions/ConnectWhatsApp.php`, `app/Models/WhatsAppSetting.php`, `routes/web.php`, `bootstrap/app.php` (excluir a rota do CSRF), `routes/console.php`
- Test: `tests/Feature/WhatsAppWebhookTest.php`

**Interfaces:**
- Produces:
  - Tabela `whatsapp_settings` + `webhook_secret` (text, cast `encrypted`), `auto_reply` (bool, default false), `greeting` (text nullable), `ignored_numbers` (json nullable, lista de telefones normalizados).
  - `whatsapp_inbound_messages`: `id, company_id, wa_message_id (string 100), remote_jid (string 100), from_me (bool), payload (json), processed_at (nullable), created_at`; unique `(company_id, wa_message_id)`; model `Prunable` apagando > 7 dias.
  - `whatsapp_conversations`: `id, company_id, remote_jid (string 100), phone (string 20 nullable), contact_name (string 80 nullable), state (string 30, default 'idle'), context (json nullable), cart_uuid (uuid nullable), cart_hash (string 64 nullable), misunderstood (tinyint, default 0), last_customer_at, last_store_at, human_until, waiting_since, cart_touched_at (timestamps nullable), last_online_order_id (nullable FK restrict), timestamps`; unique `(company_id, remote_jid)`; index `(company_id, waiting_since)`.
  - `whatsapp_bot_messages`: `id, company_id, conversation_id (FK cascade), wa_message_id (string 100 nullable), body (text), created_at`; index `(company_id, wa_message_id)`.
  - Rota `POST webhooks/whatsapp/{instance}/{secret}` → `WhatsAppWebhookController`, nome `whatsapp.webhook`, `throttle:240,1`, fora de `auth`.
  - `EvolutionClient::setWebhook(string $name, string $token, string $url): void` → `POST /webhook/set/{name}` com `{"webhook": {"enabled": true, "url": $url, "byEvents": false, "base64": false, "events": ["MESSAGES_UPSERT"]}}` (confirmar o corpo contra a doc v2.3 antes de implementar; o teste fixa o que for usado).
  - `EvolutionClient::sendText(...)`: **retorno muda para `?string`** — `key.id` da resposta, ou null.
  - `WhatsAppSetting::webhookUrl(): string` — gera `webhook_secret` (Str::random(40)) se faltar e devolve `route('whatsapp.webhook', [instance_name, secret])`.
  - Comando `whatsapp:register-webhooks` — chama `setWebhook` em toda configuração com instância; falhas viram uma linha de aviso, não exceção.

- [ ] **Step 1: Write the failing tests** em `WhatsAppWebhookTest`:
  - `test_valid_call_stores_message_once_and_queues_processing`: `Queue::fake()`; POST com payload mínimo `{"event":"messages.upsert","instance":"<nome>","data":{"key":{"id":"ABC","remoteJid":"5511988887777@s.whatsapp.net","fromMe":false},"message":{"conversation":"oi"}}}` duas vezes → ambas 200; 1 linha em `whatsapp_inbound_messages` com o `company_id` da instância; `Queue::assertPushed(ProcessWhatsAppInbound::class, 1)`.
  - `test_wrong_secret_or_unknown_instance_is_404`: segredo errado → 404; instância inexistente → 404; nenhuma linha gravada.
  - `test_other_events_and_group_messages_are_accepted_but_ignored`: `event: "connection.update"` → 200 sem linha; `remoteJid` terminando em `@g.us` ou `status@broadcast` → 200 sem linha.
  - `test_malformed_payload_still_returns_200`: corpo `{}` → 200, nada gravado.
  - `test_connect_registers_webhook_with_secret_url`: `Http::fake` da Evolution; conectar pela tela → requisição a `/webhook/set/{nome}` contendo a URL com o segredo salvo.
  - `test_send_text_returns_message_id`: `Http::fake` devolvendo `{"key":{"id":"OUT1"}}` → `sendText` devolve `'OUT1'`.
  - `test_register_webhooks_command_covers_every_instance`: duas empresas com instância → duas chamadas a `/webhook/set/...`; uma falha 500 não impede a outra (exit code 0).
- [ ] **Step 2:** `php artisan test --compact tests/Feature/WhatsAppWebhookTest.php` → FAIL (rota inexistente).
- [ ] **Step 3:** Implementar. No controller: localizar `WhatsAppSetting::withoutGlobalScopes()->where('instance_name', $instance)`, comparar com `hash_equals`; envolver todo o resto em `try/catch (Throwable)` que só faz `report()` e devolve 200. Gravar com `insertOrIgnore` (ou `firstOrCreate` dentro de try sobre a unique) e despachar o job só quando a linha for nova. Ajustar `SendWhatsAppMessage` para ignorar o retorno novo de `sendText`.
- [ ] **Step 4:** Rodar o arquivo de teste → PASS; rodar `tests/Feature/WhatsApp*` → PASS (bloco C intacto).
- [ ] **Step 5: Commit** `feat: webhook do WhatsApp grava mensagens recebidas`.

### Task 2: Capturar payloads reais em produção (parada obrigatória)

Este passo exige deploy e mensagens de verdade: **pare e peça autorização ao usuário** para o deploy da Task 1 e para rodar `whatsapp:register-webhooks` em produção.

- [ ] **Step 1:** Com autorização, deploy da Task 1 (procedimento padrão do projeto) e `php8.5 artisan whatsapp:register-webhooks` no servidor.
- [ ] **Step 2:** Pedir ao usuário: (a) que um celular de cliente mande "oi" ao número da loja; (b) que a loja responda pelo celular; (c) se possível, um áudio. 
- [ ] **Step 3:** Ler as linhas de `whatsapp_inbound_messages` (consulta só de leitura) e salvar cada `payload` anonimizado (trocar dígitos do telefone por `5511988887777`, nomes por "Cliente Teste") em `tests/Fixtures/whatsapp/customer_text.json`, `store_reply_from_phone.json`, `customer_audio.json` e, se aparecer, `customer_lid.json`.
- [ ] **Step 4:** Registrar no ledger quais campos trazem: id, remoteJid, número alternativo (`remoteJidAlt`/`senderPn`/outro), `fromMe`, `pushName`, texto. Se nenhum payload `@lid` aparecer, criar `customer_lid.json` derivado do de texto, com `remoteJid` `"123456789012345@lid"` e sem número alternativo, e anotar como sintético.
- [ ] **Step 5: Commit** `test: payloads reais da Evolution para o bot`.

### Task 3: Leitura do payload, conversa e roteamento básico (saudação, pausa, ignorados)

**Files:**
- Create: `app/Support/WhatsAppBot/InboundMessage.php`, `WhatsAppBot.php`, `BotReplies.php`, `BotSender.php`
- Modify: `app/Jobs/ProcessWhatsAppInbound.php`
- Test: `tests/Unit/InboundMessageTest.php`, `tests/Feature/WhatsAppBotRoutingTest.php`

**Interfaces:**
- Consumes: tabelas e `sendText(): ?string` da Task 1; fixtures da Task 2.
- Produces:
  - `InboundMessage::fromPayload(array $payload): ?self` com propriedades readonly `string $id, string $remoteJid, bool $fromMe, ?string $phone /* BrazilPhone::normalize do número resolvido */, ?string $pushName, ?string $text /* null para mídia */, string $kind /* 'text'|'media'|'other' */`. Devolve null para grupo, broadcast, status ou evento sem `key`.
  - `BotSender::send(WhatsAppSetting $setting, WhatsAppConversation $conversation, string $text): void` — envia para `55{phone}` (ou o `remoteJid` quando sem phone), grava `whatsapp_bot_messages` com o id devolvido; `EvolutionException` é capturada e reportada, nunca propagada.
  - `WhatsAppBot::handle(WhatsAppSetting $setting, InboundMessage $message): void` — único ponto de entrada; o job chama este método dentro de `Cache::lock("whatsapp-conv:{company}:{jid}", 30)->block(10)`.
  - `BotReplies::greeting(OnlineStore $store, ?string $override, bool $open, ?CarbonImmutable $nextOpening, bool $canOrder): string` — padrão:
    `"Olá! 👋 Aqui é {loja}.\nVeja o cardápio e peça pelo link: {link}\n\nSe preferir pedir por aqui, digite 1.\nPara falar com um atendente, digite 0."` — sem a linha do 1 quando `$canOrder` é false; fechada: troca a linha do 1 por `"Estamos fechados agora. Abrimos {quando}."`. Marcadores aceitos no texto editado: `{loja}`, `{link}`.
- Decisões de roteamento (em ordem, dentro de `handle`):
  1. `fromMe`: se o id está em `whatsapp_bot_messages` **ou** existe mensagem do bot para essa conversa com o mesmo `body` nos últimos 2 min → ignora. Senão: `last_store_at = now`, `human_until = now + 2h`, `waiting_since = null`. Fim.
  2. Telefone em `ignored_numbers` → ignora (sem gravar conversa).
  3. Grava `last_customer_at`. Se `auto_reply` desligado, loja online desativada/sem slug, ou WhatsApp desconectado → fim.
  4. `human_until` no futuro → fim (silêncio total).
  5. Conversa nova (sem mensagem do cliente há 12 h ou recém-criada): se `last_store_at` nas últimas 12 h → fim (contato já atendido). Senão: se há carrinho não expirado (`cart_touched_at` < 2 h) → pergunta "1 – continuar o pedido / 2 – começar de novo"; caso contrário zera estado e envia a saudação.
  6. Mídia → `"Por aqui eu só entendo texto. Digite o número da opção."`.
  7. Comandos globais `0`, `cancelar`, `9`; depois o estado atual (Tasks 4–5). Em `idle`/`greeted`: `1` inicia o roteiro (só se a loja está aberta e `phone` resolvido), `0` atendente.
  - `0`: envia `"Certo, um atendente vai falar com você por aqui."`, `human_until = now + 2h`, `waiting_since = now`.
  - Não entendido: `"Não entendi. Digite o número da opção."`; no segundo seguido acrescenta `"Se preferir, digite 0 para falar com um atendente."`. `misunderstood` zera a cada resposta válida.

- [ ] **Step 1: Write failing unit tests** em `InboundMessageTest`, um por fixture: texto do cliente → `fromMe` false, `phone` `'11988887777'`, `text` `'oi'`; resposta da loja → `fromMe` true; áudio → `kind` `'media'`, `text` null; `@lid` → `phone` conforme o que a fixture real trouxer (null no sintético); payload de grupo → `null`.
- [ ] **Step 2: Write failing feature tests** em `WhatsAppBotRoutingTest` (usar `Http::fake` da Evolution e chamar o webhook com as fixtures, driver de fila `sync`):
  - `test_first_message_gets_greeting_with_store_link_once`: duas mensagens do cliente em sequência → 1 saudação contendo `/loja/{slug}` e "digite 1"; a segunda recebe "Não entendi".
  - `test_greeting_again_only_after_12_hours_of_silence`: `travelTo` +11 h → sem nova saudação; +12 h → saudação.
  - `test_closed_store_greeting_has_no_order_option`: loja fechada → texto sem "digite 1" e com "Abrimos".
  - `test_store_message_from_phone_pauses_bot_for_two_hours_and_renews`: fixture `fromMe` desconhecida → cliente escreve → nenhuma resposta; +1h59 nova `fromMe`; +3 h após a primeira → ainda pausado; +2h01 após a segunda → responde.
  - `test_bot_echo_with_unknown_id_but_same_text_does_not_pause` (Review Focus 1).
  - `test_customer_asking_attendant_pauses_and_marks_waiting`: `0` → resposta do atendente, `waiting_since` preenchido; nova mensagem → silêncio.
  - `test_ignored_number_and_recent_store_contact_get_no_greeting`.
  - `test_auto_reply_off_or_store_disabled_sends_nothing`.
  - `test_lid_sender_without_phone_gets_link_and_attendant_only` (Review Focus 5): saudação sem "digite 1"; `1` → "Não entendi".
  - `test_evolution_failure_never_fails_the_job`: `Http::fake` 500 → job conclui, `processed_at` preenchido.
  - `test_conversations_are_isolated_per_company`: mesmo número escrevendo para duas lojas → duas conversas, cada saudação com o nome da própria loja, envio pela instância certa.
  - `test_concurrent_messages_of_same_conversation_are_serialized` (Review Focus 2): segurar a trava da conversa e processar → job é liberado para nova tentativa (`release`) sem alterar estado.
- [ ] **Step 3:** Rodar → FAIL.
- [ ] **Step 4:** Implementar `InboundMessage`, `BotSender`, `BotReplies::greeting`, `WhatsAppBot::handle` (regras 1–7, estados `idle`, `greeted`, `resume_prompt`) e o job (trava; `processed_at`; qualquer `Throwable` → `report()`).
- [ ] **Step 5:** Rodar os dois arquivos → PASS.
- [ ] **Step 6: Commit** `feat: bot do WhatsApp responde com o cardápio e respeita o atendente`.

### Task 4: Cardápio pela conversa e montagem do item

**Files:**
- Create: `app/Support/WhatsAppBot/BotCatalog.php`, `app/Support/WhatsAppBot/OrderFlow.php`
- Modify: `WhatsAppBot.php` (delegar estados do pedido), `BotReplies.php`
- Test: `tests/Feature/WhatsAppBotOrderTest.php`

**Interfaces:**
- Consumes: `WhatsAppBot::handle`, `BotSender::send`, `ProductComposition::forProducts(Collection): array` (forma documentada em `app/Support/ProductComposition.php`).
- Produces:
  - `BotCatalog::for(int $companyId): self` com `categories(): array<int, array{id:int,name:string}>` (só categorias com produto ativo, vendável e não esgotado), `products(int $categoryId): array<int, array{id:int,name:string,price:string}>` (preço = `sale_price` ou `from_price` da composição, rotulado "a partir de" quando composto), `composition(int $productId): ?array` (entrada de `forProducts`, sem opções pausadas/inativas).
  - `OrderFlow::handle(WhatsAppConversation $c, string $text, BotCatalog $catalog): array` — devolve a lista de textos a enviar e grava o novo `state`/`context`.
  - Estados: `category` → `product` → `size` (pizza) → `flavors` (pizza; até `max_flavors` do tamanho, números separados por vírgula) → `group:{i}` (um por grupo de adicionais, respeitando `min`/`max`; "pular" quando `min` = 0; quantidade repetindo o número quando `allows_quantity`) → `quantity` (1 a `config('online.max_line_quantity')`) → `item_notes` ("pular" aceito; máx. 120 caracteres) → `cart`.
  - `context.cart`: lista de linhas no formato do catálogo `{product_id, quantity, notes?, size_id?, flavor_ids?, options?: [{option_id, quantity}]}` mais `label` e `unit_price` só para exibir (o preço exibido vem de `ItemComposer::composeMany` chamado ao fechar o item, nunca de conta própria).
  - Ao fechar o item: `ItemComposer::composeMany($companyId, [$line])`; `ValidationException` → frase "Essa combinação não está disponível. Vamos escolher de novo." e volta ao primeiro passo do produto.
  - Carrinho: `"1 – adicionar mais itens\n2 – remover um item\n3 – finalizar"` com itens numerados e subtotal; remover pede o número do item. `9` em cada passo volta ao anterior (do `category` volta à saudação). `cart_touched_at` atualizado a cada resposta. Máximo `config('online.max_lines')` linhas.

- [ ] **Step 1: Write failing tests** (cada um conduz a conversa pelo webhook e afirma o texto enviado e o `context.cart` final):
  - `test_lists_only_categories_and_products_available_online`: produto esgotado, inativo, não vendável e de outra empresa não aparecem; categoria só com esgotados some.
  - `test_simple_product_goes_to_cart_with_catalog_price`: categoria 1 → produto 1 → quantidade 2 → "pular" → carrinho com `quantity` 2 e subtotal = 2 × preço.
  - `test_half_and_half_pizza_respects_size_flavor_limit`: tamanho com `max_flavors` 2; "1,2,3" → recusa com o limite; "1,2" → aceita; rótulo igual ao do catálogo (`½ ... ½ ...`).
  - `test_extras_group_enforces_min_and_max`: grupo min 1 max 2 → "pular" recusado; "1,2,3" recusado; "1,3" aceito.
  - `test_paused_option_is_not_offered`.
  - `test_nine_goes_back_one_step_and_cancel_clears_cart`.
  - `test_remove_item_from_cart`.
  - `test_cart_expires_after_two_hours_and_resume_prompt_before_that`: pausa do atendente no meio do carrinho → após a pausa, dentro das 2 h, "1 – continuar"; `1` → carrinho preservado; após 2 h → saudação e carrinho vazio.
- [ ] **Step 2:** Rodar → FAIL. **Step 3:** Implementar. **Step 4:** Rodar → PASS (e `WhatsAppBotRoutingTest`).
- [ ] **Step 5: Commit** `feat: bot monta itens do cardápio pela conversa`.

### Task 5: Fechamento do pedido

**Files:**
- Create: `app/Support/WhatsAppBot/CheckoutFlow.php`
- Modify: `OrderFlow.php` (carrinho "3" → checkout), `BotReplies.php`
- Test: `tests/Feature/WhatsAppBotCheckoutTest.php`

**Interfaces:**
- Consumes: `context.cart` da Task 4; `PlaceOnlineOrder::handle(OnlineStore $store, array $data): array{order: OnlineOrder, token: string}`; regras e chaves de erro de `app/Actions/PlaceOnlineOrder.php`.
- Produces:
  - Estados: `type` (pula se a loja aceita só um tipo) → `zone` (zonas numeradas com taxa) → `address_reuse` (só se a última entrega deste telefone na empresa tiver endereço numa zona ainda existente) → `street` → `number` → `complement` ("pular") → `reference` ("pular") → `name` ("1 – usar {pushName}" ou digitar outro; 2–80 caracteres) → `payment` → `change` (só `cash`; valor > total ou "não preciso") → `summary` ("1 – confirmar / 2 – alterar / 0 – atendente"; "alterar" volta ao carrinho) → `done`.
  - Payload montado exatamente nas chaves de `StoreOnlineOrderRequest::payloadRules()`; `customer.phone` = `phone` da conversa; `client_uuid` = `conversation.cart_uuid`.
  - Identificador: `cart_hash = sha256(json do payload sem client_uuid)`. Se `cart_hash` mudou desde a última tentativa → `cart_uuid = Str::uuid()`; igual → reaproveita.
  - Sucesso: estado `done`, `last_online_order_id`, carrinho limpo, resposta curta "Pedido #{numero} enviado! Você vai receber as atualizações por aqui." (a mensagem "pedido recebido" do bloco C sai sozinha).
  - Recusas (`ValidationException` por chave; `HttpException` 404 = empresa inativa; 409 = conteúdo diferente com o mesmo uuid → gerar uuid novo e tentar uma vez):

    | chave | frase | volta para |
    |---|---|---|
    | `store` | "A loja acabou de fechar. Abrimos {quando}." | saudação |
    | `items.N.product_id` / `items.N` | "O item {nome} ficou indisponível. Tirei do carrinho." | `cart` (linha removida) |
    | `items` (mínimo) | mensagem do erro ("Pedido mínimo de R$ …") | `cart` |
    | `address.delivery_zone_id` | "Não entregamos mais nesse bairro. Escolha outro." | `zone` |
    | `change_for` | "O troco precisa ser maior que o total." | `change` |
    | `customer.phone` | "Você já tem pedidos aguardando a loja. Assim que ela responder, você pode pedir de novo." | `done` |
    | `type` | "A loja não aceita mais esse tipo de pedido agora." | `type` |
    | 404 | "A loja não está recebendo pedidos agora." | saudação |
    | outro | "Não consegui enviar seu pedido. Digite 0 para falar com um atendente." | `summary` |

- [ ] **Step 1: Write failing tests:**
  - `test_delivery_order_is_created_as_online_order_with_catalog_totals`: fluxo completo → 1 `OnlineOrder` `pending`, telefone da conversa, zona/taxa e total iguais aos do catálogo, `whatsapp_messages` com `received` enfileirada.
  - `test_pickup_skips_address_and_reuse_offers_last_address`.
  - `test_cash_asks_change_and_rejects_change_below_total`.
  - `test_confirming_twice_creates_one_order` (Review Focus 3, parte 1): "1" duas vezes (e a mesma mensagem repetida no webhook) → 1 pedido.
  - `test_changed_cart_after_refusal_gets_new_identifier` (Review Focus 3, parte 2): recusa por mínimo → adiciona item → confirma → pedido criado, sem 409.
  - `test_product_sold_out_before_confirm_returns_to_cart` (Review Focus 4).
  - `test_store_closing_mid_conversation_explains_and_creates_nothing`.
  - `test_pending_limit_per_phone_is_explained`.
- [ ] **Step 2:** Rodar → FAIL. **Step 3:** Implementar. **Step 4:** Rodar os arquivos do bot → PASS.
- [ ] **Step 5: Commit** `feat: bot fecha o pedido pela conversa como pedido online`.

### Task 6: Telas — ajustes do bot e aviso de cliente aguardando

**Files:**
- Modify: `app/Http/Controllers/WhatsAppController.php`, `app/Http/Requests/WhatsAppSettingsRequest.php`, `app/Actions/SaveWhatsAppSettings.php`, `resources/js/pages/online/WhatsApp.vue`, `app/Http/Controllers/OnlineOrderController.php`, `resources/js/pages/online/Orders.vue`
- Test: `tests/Feature/WhatsAppBotSettingsTest.php`

**Interfaces:**
- Consumes: colunas da Task 1; `WhatsAppConversation.waiting_since`, `human_until`, `last_store_at`.
- Produces:
  - Request: `auto_reply` boolean; `greeting` nullable string max 1000 (vazio = padrão); `ignored_numbers` array max 100, cada item normalizado por `BrazilPhone::normalize` (inválido → erro "Número inválido: {valor}").
  - Prop `bot` na tela WhatsApp: `{auto_reply, greeting, default_greeting, ignored_numbers, conversations: últimas 30 por updated_at → [{phone, name, status_label ('No roteiro' | 'Com atendente' | 'Pedido feito' | 'Aguardando atendente'), updated_at}]}`.
  - Prop `waitingCustomers` em "Pedidos online" (incluída no `usePoll`): conversas com `waiting_since` não nulo, `human_until` no futuro e `last_store_at` anterior a `waiting_since` → `[{name, phone, since, wa_link: 'https://wa.me/55'+phone}]`. Aviso: "Cliente aguardando atendente no WhatsApp" com nome, número e link.

- [ ] **Step 1: Write failing tests:** salvar chave/saudação/números (com auditoria); número inválido recusado; tela traz conversas só da própria empresa; aviso aparece após `0`, some após `fromMe` da loja e após a pausa expirar; outra empresa não vê.
- [ ] **Step 2:** FAIL. **Step 3:** Implementar backend e telas (seguir o visual existente de `WhatsApp.vue` e `Orders.vue`). **Step 4:** PASS + `npm run build` sem erro.
- [ ] **Step 5: Commit** `feat: ajustes do bot na tela WhatsApp e aviso de cliente aguardando`.

### Task 7: Fechamento

- [ ] **Step 1:** `vendor/bin/pint --dirty --format agent` (com o prefixo), suíte completa `php artisan test --compact`, `npm run build` → tudo verde.
- [ ] **Step 2:** Revisão final do branch (code-reviewer) com o Review Focus acima.
- [ ] **Step 3:** Perguntar ao usuário sobre push e deploy. No deploy, depois do `migrate`: `php8.5 artisan whatsapp:register-webhooks`. A chave "Responder automaticamente" nasce desligada; a loja liga na tela WhatsApp.
