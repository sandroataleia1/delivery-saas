# Bloco C — WhatsApp (Evolution API) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O cliente do catálogo online recebe pelo WhatsApp da própria loja uma mensagem a cada etapa do pedido, sem que falha do WhatsApp afete o pedido.

**Architecture:** Cada empresa conecta o próprio número criando uma instância na Evolution API do usuário (URL e chave global no `.env`). Cada mudança de estado de um pedido online grava, na mesma transação, uma linha de saída (`whatsapp_messages`, única por pedido+evento) com o texto já montado. Um job na fila `database` envia depois do commit, com reivindicação atômica da linha, novas tentativas com espera crescente e uma varredura agendada para jobs perdidos. A loja conecta pelo QR, edita os textos, vê o histórico e recebe alerta de "WhatsApp desconectado".

**Tech Stack:** Laravel 13, PHP 8.5, Inertia 3 + Vue 3, Tailwind 4, PHPUnit, Pint, cliente HTTP do Laravel (`Http::fake` nos testes), fila `database`.

**Spec:** `docs/superpowers/specs/2026-11-08-catalogo-online-design.md` (§7 Bloco C, §3 antiobjetivos, §9 restrições).

## Global Constraints

- Nenhuma dependência nova. A Evolution entra pelo cliente HTTP do Laravel e a fila usa o driver `database`, que já existe (spec §9).
- Implantação não autorizada. Não tocar na `main` nem fazer push.
- A URL e a chave global da Evolution ficam no `.env`: `EVOLUTION_URL` e `EVOLUTION_API_KEY`, lidos em `config('services.evolution.url')` e `config('services.evolution.key')`.
- O token da instância é guardado cifrado (cast `encrypted`).
- Só pedidos do catálogo online geram mensagem, e só mensagens transacionais.
- Os 9 eventos do bloco C são `received`, `accepted`, `preparing`, `ready_for_pickup`, `out_for_delivery`, `delivered`, `rejected`, `cancelled` e `expired`. "Pagamento confirmado" e a frase de devolução do valor pago entram no bloco D.
- Marcadores aceitos: `{nome}`, `{loja}`, `{numero}`, `{link}`, `{motivo}`, `{total}`. Toda mensagem leva o link de acompanhamento: se o texto não tiver `{link}`, ele é acrescentado ao final numa linha própria.
- Falha, timeout, Evolution não configurada ou número desconectado nunca desfazem, bloqueiam nem atrasam o pedido. Nenhuma exceção do envio pode chegar à requisição que mudou o estado, nem com o driver `sync` dos testes.
- Nunca mandar duplicado:
  - há unicidade por (pedido, evento);
  - o envio só acontece depois de reivindicar a linha (`pending` → `sending` atômico);
  - uma linha presa em `sending` nunca é reenviada, vira `failed`.
- Tenancy: o envio roda sem usuário logado (fila e agendador) e a gravação pode rodar na rota pública. Toda consulta filtra `company_id` explicitamente ou usa `withoutGlobalScopes()`, e toda linha criada recebe `company_id` explícito.
- Ordem de locks: o bloco C não cria lock novo. A gravação da linha de saída roda dentro das transações existentes, depois dos locks delas.
- Padrões do código: Actions, FormRequests, migrations aditivas, auditoria (`Auditable`), textos de interface em português, Pint e `php artisan make:*` com `--no-interaction`.
- Prefixo de git em todo comando git e no `pint --dirty`: `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=C:/xampp/htdocs/delivery-saas`. Trailer dos commits: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Evolution fora do ar, com timeout, HTTP 500 ou sem URL configurada no momento do aceite.**
   - O aceite responde normalmente e o delivery é criado.
   - A mensagem termina `failed` com o motivo, depois das tentativas, e a requisição nunca vê exceção.
   - → Testes nas Tasks 2 e 3.
2. **O mesmo evento disparado duas vezes:**
   - o status do delivery vai e volta (`preparing` → `received` → `preparing` pela sincronização da produção);
   - o aceite é repetido;
   - dois jobs ou o job e a varredura pegam a mesma linha.
   - Sai uma mensagem só.
   - → Testes nas Tasks 2 e 3.
3. **Duas empresas com WhatsApp conectado.** O pedido da empresa B nunca usa a instância nem o token da empresa A, inclusive pela rota pública e pelo agendador, que rodam sem usuário.
   - → Testes nas Tasks 2 e 3.
4. **Texto editado pela loja sem `{link}`, com marcador desconhecido, ou campo apagado.**
   - O link é acrescentado.
   - O marcador desconhecido fica como está.
   - O campo vazio volta ao texto padrão.
   - → Testes nas Tasks 2 e 4.
5. **WhatsApp desligado ou instância removida com mensagens já na fila.** As mensagens viram `failed` com "WhatsApp desligado", sem novas tentativas. Ninguém do outro tenant vê o histórico.
   - → Testes nas Tasks 3 e 4.

---

## File Structure

- `config/services.php`: chave `evolution` (`url`, `key`, `timeout` = 10).
- `database/migrations/2026_11_11_000001_create_whatsapp_tables.php`: tabelas `whatsapp_settings` e `whatsapp_messages`.
- `app/Models/WhatsAppSetting.php`, `app/Models/WhatsAppMessage.php`.
- `app/Support/EvolutionClient.php` e `app/Support/EvolutionException.php`: única fronteira HTTP com a Evolution.
- `app/Support/WhatsAppTemplates.php`: textos padrão e montagem.
- `app/Support/WhatsAppOutbox.php`: decide o evento, grava a linha e despacha o job; registrado no `AppServiceProvider`.
- `app/Jobs/SendWhatsAppMessage.php`: reivindica, envia, tenta de novo e marca falha.
- `app/Console/Commands/SweepWhatsAppMessagesCommand.php`: comando `whatsapp:sweep`.
- `app/Actions/ConnectWhatsApp.php`, `app/Actions/DisconnectWhatsApp.php`, `app/Actions/SaveWhatsAppSettings.php`.
- `app/Http/Controllers/WhatsAppController.php`, `app/Http/Requests/WhatsAppSettingsRequest.php`.
- `resources/js/pages/online/WhatsApp.vue`, além de alterações em `AppLayout.vue`, `online/Orders.vue` e `shop/Track.vue`.
- Testes:
  - `tests/Feature/EvolutionClientTest.php`
  - `tests/Feature/WhatsAppOutboxTest.php`
  - `tests/Feature/SendWhatsAppMessageTest.php`
  - `tests/Feature/WhatsAppSettingsTest.php`
  - `tests/Feature/WhatsAppAlertsTest.php`
- Factories: `WhatsAppSettingFactory`, `WhatsAppMessageFactory`.

`app/Jobs/` é pasta nova, criada por `php artisan make:job`. É a pasta padrão do framework para o job que a spec exige (§7: "Um job na fila envia depois do commit").

---

### Task 1: Esquema, modelos e cliente da Evolution

**Files:**
- Create: a migration `2026_11_11_000001_create_whatsapp_tables.php`, os modelos `WhatsAppSetting` e `WhatsAppMessage` com suas factories, e `app/Support/EvolutionClient.php` e `app/Support/EvolutionException.php`.
- Modify: `config/services.php`, `.env.example` (`EVOLUTION_URL=`, `EVOLUTION_API_KEY=`) e `app/Models/OnlineOrder.php` (relação `whatsappMessages()`).
- Test: `tests/Feature/EvolutionClientTest.php`.

**Interfaces:**
- Produces:
  - **Tabela `whatsapp_settings`:**
    - `id`;
    - `company_id`, único, FK com restrictOnDelete;
    - `enabled`, bool, default false;
    - `instance_name` string(80), nullable e único;
    - `instance_token` text, nullable;
    - `state` string(20), nullable;
    - `state_checked_at`, timestamp nullable;
    - `templates` json, nullable;
    - timestamps.
  - **Tabela `whatsapp_messages`:**
    - `id`;
    - `company_id`, FK;
    - `online_order_id`, FK com restrictOnDelete;
    - `event` string(30);
    - `phone` string(20);
    - `body` text;
    - `status` string(12), default `pending`;
    - `attempts`, unsignedTinyInteger, default 0;
    - `last_error` string(255), nullable;
    - `sent_at`, nullable;
    - timestamps;
    - unique `(online_order_id, event)`, index `(company_id, created_at)` e index `(status, updated_at)`.
  - **`WhatsAppSetting`:**
    - usa `BelongsToCompany` e `Auditable`;
    - casts: `instance_token` → `encrypted`, `templates` → `array`, `enabled` → `boolean`, `state_checked_at` → `datetime`;
    - `auditRedact()` = `['instance_token']` e `auditExclude()` = `['state', 'state_checked_at']`;
    - `isConnected(): bool`, que é `state === 'open'`;
    - `auditLabel()` = `'WhatsApp da loja'`.
  - **`WhatsAppMessage`:**
    - usa `BelongsToCompany`, sem auditoria, porque o histórico já é o registro;
    - constantes `PENDING = 'pending'`, `SENDING = 'sending'`, `SENT = 'sent'`, `FAILED = 'failed'` e `MAX_ATTEMPTS = 5`;
    - casts `sent_at` → `datetime`.
  - **`EvolutionClient`**, resolvido pelo container. Todos os métodos de rede lançam `EvolutionException` (mensagem em português, até 255 caracteres) em falha de conexão, timeout ou resposta não 2xx. Usam timeout de `config('services.evolution.timeout')` segundos e `connectTimeout(5)`.
    - `configured(): bool`: URL e chave preenchidas.
    - `createInstance(string $name): array{token: string, qr: ?string}`:
      - `POST {url}/instance/create` com header `apikey` = chave global e corpo `{instanceName, qrcode: true, integration: 'WHATSAPP-BAILEYS'}`;
      - o token vem do `hash` da resposta, que pode ser string ou `{apikey: string}` conforme a versão;
      - o QR vem de `qrcode.base64`.
    - `connect(string $name, string $token): ?string`:
      - `GET {url}/instance/connect/{name}` com `apikey` = token da instância;
      - devolve `base64` da resposta ou null, quando já está conectado.
    - `state(string $name, string $token): string`: `GET {url}/instance/connectionState/{name}` e devolve `instance.state`, ou `'close'` quando ausente.
    - `logout(string $name, string $token): void`: `DELETE {url}/instance/logout/{name}`.
    - `sendText(string $name, string $token, string $number, string $text): void`: `POST {url}/message/sendText/{name}` com corpo `{number, text}`, em que o número leva o país e só dígitos.
    - A URL base é usada sem a barra final.
    - Endpoints conferidos no código-fonte oficial (`EvolutionAPI/evolution-api`, `main`, em 08/11/2026): o guard aceita o header `apikey` com a chave global ou o token da própria instância nas rotas com `:instanceName`, e `SendTextDto` tem `number` e `text` no topo.

- [ ] **Step 1: Write the failing tests** in `EvolutionClientTest` (todos com `Http::preventStrayRequests()`, `config(['services.evolution.url' => 'https://evo.test/', 'services.evolution.key' => 'GLOBAL'])`):
  - `test_create_instance_sends_global_key_and_returns_token_and_qr`: fake `https://evo.test/instance/create` → `['instance' => ['instanceName' => 'x'], 'hash' => 'TOKEN1', 'qrcode' => ['base64' => 'data:image/png;base64,AAA']]`; asserts result `['token' => 'TOKEN1', 'qr' => 'data:image/png;base64,AAA']` and `Http::assertSent` with header `apikey` = `GLOBAL`, body `instanceName` = `x`, `qrcode` = true, `integration` = `WHATSAPP-BAILEYS`.
  - `test_create_instance_accepts_hash_object`: `'hash' => ['apikey' => 'TOKEN2']`, sem `qrcode` → `['token' => 'TOKEN2', 'qr' => null]`.
  - `test_send_text_uses_instance_token_and_number_with_country`: `sendText('x', 'TOKEN1', '5511988887777', 'Oi')` → assertSent `POST https://evo.test/message/sendText/x`, header `apikey` = `TOKEN1`, body `['number' => '5511988887777', 'text' => 'Oi']`.
  - `test_state_reads_instance_state_and_defaults_to_close`: `['instance' => ['instanceName' => 'x', 'state' => 'open']]` → `'open'`; `[]` → `'close'`.
  - `test_failures_become_evolution_exception`: HTTP 500 em sendText → `EvolutionException`; `Http::fake(fn () => throw new ConnectionException('timeout'))` → `EvolutionException`.
  - `test_not_configured`: url vazia → `configured()` false.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/EvolutionClientTest.php` — Expected: FAIL (class not found).

- [ ] **Step 3: Implement** a migration, os modelos, as factories, o config, o `.env.example`, `EvolutionClient` e `EvolutionException`. Crie com `php artisan make:migration`, `make:model -f` e `make:class`.

- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/EvolutionClientTest.php` — Expected: PASS, 6 tests.

- [ ] **Step 5: Run** `vendor/bin/pint --dirty --format agent` and commit `feat: esquema do WhatsApp e cliente da Evolution`.

---

### Task 2: Textos e linha de saída gravada com a mudança de estado

**Files:**
- Create: `app/Support/WhatsAppTemplates.php`, `app/Support/WhatsAppOutbox.php`.
- Modify: `app/Providers/AppServiceProvider.php` (chamar `WhatsAppOutbox::register()` no `boot`).
- Test: `tests/Feature/WhatsAppOutboxTest.php`.

**Interfaces:**
- Consumes: os modelos da Task 1, `OnlineOrder`, `DeliveryOrder`, `OnlineStore`, `Company::trade_name`, `Decimal::currency()`, `OnlineOrderProgress::EXPIRED_REASON` e a rota `shop.track` (`[$store->slug, $order->tracking_token]`).
- Produces:
  - **`WhatsAppTemplates::EVENTS`**: lista ordenada dos 9 eventos com o rótulo em português, para a tela.
    ```
    ['received' => 'Pedido recebido', 'accepted' => 'Pedido aceito', 'preparing' => 'Em preparo',
     'ready_for_pickup' => 'Pronto para retirar', 'out_for_delivery' => 'Saiu para entrega', 'delivered' => 'Entregue',
     'rejected' => 'Recusado', 'cancelled' => 'Cancelado', 'expired' => 'Expirado']
    ```
  - **`WhatsAppTemplates::DEFAULTS`**: textos padrão, exatos.
    ```
    'received' => "Olá, {nome}! Recebemos seu pedido #{numero} na {loja}, no total de {total}. Avisamos por aqui assim que a loja confirmar.\nAcompanhe: {link}",
    'accepted' => "{nome}, a {loja} aceitou seu pedido #{numero}.\nAcompanhe: {link}",
    'preparing' => "Seu pedido #{numero} está em preparo na {loja}.\nAcompanhe: {link}",
    'ready_for_pickup' => "Seu pedido #{numero} está pronto para retirar na {loja}.\nAcompanhe: {link}",
    'out_for_delivery' => "Seu pedido #{numero} saiu para entrega.\nAcompanhe: {link}",
    'delivered' => "Pedido #{numero} concluído. Obrigado por pedir na {loja}!\n{link}",
    'rejected' => "A {loja} não pôde aceitar seu pedido #{numero}. Motivo: {motivo}\n{link}",
    'cancelled' => "Seu pedido #{numero} na {loja} foi cancelado. Motivo: {motivo}\n{link}",
    'expired' => "A {loja} não confirmou seu pedido #{numero} a tempo, e ele não será preparado.\n{link}",
    ```
  - **`WhatsAppTemplates::render(?array $overrides, string $event, array $values): string`**:
    - usa o override se for string não vazia depois de `trim`; senão, o padrão;
    - substitui só os 6 marcadores, recebidos em `$values` com as chaves `nome`, `loja`, `numero`, `link`, `motivo` e `total`;
    - se o texto não continha `{link}`, acrescenta `"\n".$values['link']`.
  - **`WhatsAppOutbox::register(): void`**: registra listeners de modelo em três situações.
    - `OnlineOrder::created` com status `pending` → `received`.
    - `OnlineOrder::updated`, quando `status` mudou para `accepted`, `rejected` ou `expired` → evento de mesmo nome.
    - `DeliveryOrder::updated`, quando `status` mudou e `online_order_id` não é nulo:
      - `preparing` → `preparing`;
      - `ready`, se o tipo for `pickup` → `ready_for_pickup`;
      - `out_for_delivery` → `out_for_delivery`;
      - `delivered` → `delivered`;
      - `cancelled` → `cancelled`.
  - **`WhatsAppOutbox::record(OnlineOrder $order, string $event, ?string $reason = null): ?int`**:
    - devolve null sem gravar quando a empresa do pedido não tem `WhatsAppSetting`, quando `enabled` é false ou quando `instance_name` é nulo;
    - busca por `company_id` do pedido com `withoutGlobalScopes`, e a loja online também por `company_id` explícito;
    - monta o texto com `nome` = `customer_name`, `loja` = `trade_name` da empresa, `numero` = `number`, `link` = URL absoluta de `shop.track`, `total` = `Decimal::currency($order->total)` e `motivo`:
      - `rejected` → `decision_reason`;
      - `expired` → `OnlineOrderProgress::EXPIRED_REASON`;
      - `cancelled` → `cancel_reason` do delivery;
      - nos demais → string vazia.
    - grava com `insertOrIgnore`, `company_id` explícito, `phone` = `'55'.customer_phone` e `status` `pending`;
    - se inseriu, lê o id pela chave (pedido, evento), despacha `SendWhatsAppMessage::dispatch($id)->afterCommit()` e devolve o id;
    - se a linha já existia, devolve null e não despacha.
  - Nada aqui lança para quem chamou: qualquer `Throwable` da montagem vira `report($e)` e null. O pedido nunca falha por causa do WhatsApp.

O job `SendWhatsAppMessage` é da Task 3. Nesta task, crie só a classe com `handle()` vazio (via `php artisan make:job SendWhatsAppMessage --no-interaction`) para o dispatch existir, e use `Queue::fake()` nos testes.

- [ ] **Step 1: Write the failing tests** em `WhatsAppOutboxTest`, com `Queue::fake()`, `Http::preventStrayRequests()` e uma empresa com `WhatsAppSetting` `enabled` e `instance_name` `inst-a`. Monte os pedidos pelas Actions reais: `PlaceOnlineOrder` com o ator de auditoria no `Context`, como em `ShopController`, e depois `AcceptOnlineOrder`, `RejectOnlineOrder`, `ExpireOnlineOrders`, `TransitionProduction`, `AdvanceDeliveryOrder` e `CancelDeliveryOrder`, seguindo os testes existentes de `OnlineOrderAcceptTest` e `OnlineOrderExpireTest`.
  - `test_placing_order_records_received_with_link_and_total`: a linha `received` tem `phone` `'5511988887777'`, o corpo contém `'#'.$order->number`, a URL de `shop.track` com o token do pedido e `'R$'`. Um único `SendWhatsAppMessage` é empurrado.
  - `test_each_stage_records_one_message`:
    - para pickup, a sequência aceite → item em preparo → pronto → retirado grava `received`, `accepted`, `preparing`, `ready_for_pickup` e `delivered`;
    - para delivery, a sequência até `out_for_delivery` grava `out_for_delivery` e nunca `ready_for_pickup`.
  - `test_rejected_expired_and_cancelled_carry_reason`:
    - a recusa com motivo `'Sem entregador'` gera um corpo que contém `'Motivo: Sem entregador'`;
    - a expiração pelo `ExpireOnlineOrders` grava uma linha `expired` cujo corpo contém o link de acompanhamento (o texto padrão de `expired` não usa `{motivo}`);
    - o cancelamento do delivery com motivo `'Cliente desistiu'` gera um corpo que contém `'Cliente desistiu'`.
  - `test_same_event_twice_records_once`: forçar `preparing` → `received` → `preparing` no delivery via `forceFill()->save()` resulta em uma linha `preparing` e um job.
  - `test_nothing_recorded_without_enabled_settings`: sem settings, com `enabled` false e com `instance_name` nulo, o pedido aceito tem 0 linhas e 0 jobs.
  - `test_other_company_settings_never_used`: a empresa A conectada e a empresa B sem settings; o pedido de B, feito pela rota pública `POST /loja/{slug}/pedidos` sem login, tem 0 linhas.
  - `test_order_flow_survives_outbox_failure`: settings com `templates` `['received' => ['x']]` (override não string, que faz a montagem lançar); `POST /loja/{slug}/pedidos` sem login responde como em `OnlineOrderPlaceTest` (sucesso), o pedido existe e há 0 linhas de saída.
  - `test_render_appends_link_keeps_unknown_markers_and_falls_back_to_default` (unitário de `WhatsAppTemplates::render`):
    - um override sem `{link}` termina em `"\nhttps://l"`;
    - `{foo}` permanece;
    - um override `'   '` usa `DEFAULTS`.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/WhatsAppOutboxTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement** `WhatsAppTemplates`, `WhatsAppOutbox` e o registro no provider.

- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/WhatsAppOutboxTest.php` e depois a suíte toda, `php artisan test --compact`. Expected: tudo PASS. Os testes antigos não criam `WhatsAppSetting`, então nada é gravado neles.

- [ ] **Step 5: Run** `vendor/bin/pint --dirty --format agent` and commit `feat: mensagens de WhatsApp gravadas com cada etapa do pedido online`.

---

### Task 3: Envio pela fila, novas tentativas e varredura

**Files:**
- Modify: `app/Jobs/SendWhatsAppMessage.php`, `routes/console.php`.
- Create: `app/Console/Commands/SweepWhatsAppMessagesCommand.php` (`whatsapp:sweep`).
- Test: `tests/Feature/SendWhatsAppMessageTest.php`.

**Interfaces:**
- Consumes: `EvolutionClient` e `EvolutionException` (Task 1), e `WhatsAppMessage` e `WhatsAppSetting` (Task 1).
- Produces:
  - **`SendWhatsAppMessage implements ShouldQueue`**: construtor `public function __construct(public int $messageId)`, `$tries = 6` e `handle(EvolutionClient $client): void`. O `handle` nunca lança. Passos:
    1. **Reivindica:** `WhatsAppMessage::withoutGlobalScopes()->whereKey($id)->where('status', PENDING)->where('attempts', '<', MAX_ATTEMPTS)->update(['status' => SENDING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()])`. Se o resultado não for 1, retorna sem fazer nada.
    2. **Confere a configuração:** carrega a mensagem e o `WhatsAppSetting` da mesma `company_id`, com `withoutGlobalScopes`. Se não houver settings, se estiver desligado, se não houver instância ou se `! $client->configured()`, marca `failed` com `last_error` `'WhatsApp desligado'` e retorna.
    3. **Envia:** chama `sendText(instance_name, instance_token, phone, body)`. Se der certo, grava `sent`, `sent_at = now()` e `last_error = null`, e atualiza `state = 'open'` e `state_checked_at` nos settings.
    4. **Trata a falha:** em `EvolutionException`, grava `last_error`. Se `attempts >= MAX_ATTEMPTS`, marca `failed`. Senão, volta a `pending` e chama `$this->release(BACKOFF[$attempts - 1])`, com `BACKOFF = [30, 120, 600, 1800]` segundos.
    5. **Atualiza o estado da conexão:** depois da falha, consulta `state()` e grava em `state` e `state_checked_at`. É um passo opcional: qualquer exceção dele é engolida.
    6. Qualquer outro `Throwable` depois da reivindicação vira `report($e)` e é tratado como a falha do passo 4, com `last_error` `'Erro interno no envio'`.
  - **`whatsapp:sweep`**: um comando a cada 5 minutos, com `withoutOverlapping()` em `routes/console.php`.
    - Linhas `pending` com `updated_at` mais antigo que 35 minutos e `attempts < MAX_ATTEMPTS` recebem um novo `SendWhatsAppMessage::dispatch($id)`.
    - Linhas `sending` com `updated_at` mais antigo que 15 minutos viram `failed`, com `last_error` `'Envio interrompido; não reenviado para evitar mensagem duplicada.'`.
    - Devolve a contagem de cada caso na saída do comando.

- [ ] **Step 1: Write the failing tests** em `SendWhatsAppMessageTest` (`Http::preventStrayRequests()`, settings com `instance_name` `inst-a`, `instance_token` `TOKEN-A`, config da Evolution preenchida; mensagens via factory; job executado com `app()->call([new SendWhatsAppMessage($id), 'handle'])` ou `(new SendWhatsAppMessage($id))->handle(app(EvolutionClient::class))`):
  - `test_sends_once_and_marks_sent`:
    - o fake de `*/message/sendText/inst-a` responde 201;
    - a linha termina `sent`, com `attempts` 1, `sent_at` preenchido e settings `state` `'open'`;
    - `Http::assertSentCount(1)`;
    - rodar o mesmo job de novo não envia (`assertSentCount(1)`).
  - `test_failure_releases_with_backoff_then_fails_after_max_attempts`:
    - sendText responde 500 e connectionState responde `close`;
    - depois de 1 execução, a linha está `pending`, com `attempts` 1, `last_error` preenchido e settings `state` `'close'`;
    - depois de executar até `attempts` 5, a linha está `failed`;
    - uma execução extra não envia.
    - Use `Queue::fake()` e confira o `release` pelo `FakeJob`/`withFakeQueueInteractions()->assertReleased(30)` na primeira execução.
  - `test_connection_timeout_never_throws`: o fake lança `ConnectionException`; `handle` retorna sem exceção e a linha volta a `pending`.
  - `test_disabled_or_missing_instance_fails_without_calling_evolution`: com settings `enabled` false, a linha fica `failed` com `'WhatsApp desligado'` e `Http::assertNothingSent()`.
  - `test_uses_the_message_company_instance`:
    - a empresa A tem `inst-a`/`TOKEN-A` e a empresa B tem `inst-b`/`TOKEN-B`;
    - a mensagem de B vai para `.../sendText/inst-b` com `apikey` `TOKEN-B`;
    - o job roda sem usuário (`Auth::guest()`).
  - `test_accept_survives_evolution_outage` (integração com fila `sync`, sem `Queue::fake`):
    - a Evolution responde 500;
    - `postJson(route('online.orders.accept', $order))` responde como em `OnlineOrderAcceptTest`, sem 500, e o delivery existe;
    - a linha `accepted` fica `pending` ou `failed`, nunca `sent`.
  - `test_sweep_redispatches_stale_pending_and_fails_stuck_sending`:
    - com `Queue::fake()`, uma linha `pending` de 40 minutos atrás é despachada e uma de 10 minutos não;
    - uma linha `sending` de 20 minutos vira `failed` com o texto exato;
    - uma linha `sending` de 5 minutos fica como está.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/SendWhatsAppMessageTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement** o job, o comando (`php artisan make:command`) e o agendamento.

- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/SendWhatsAppMessageTest.php tests/Feature/WhatsAppOutboxTest.php` — Expected: PASS.

- [ ] **Step 5: Run** `vendor/bin/pint --dirty --format agent` and commit `feat: envio das mensagens de WhatsApp pela fila com novas tentativas`.

---

### Task 4: Tela do WhatsApp da loja — conectar, textos e histórico

**Files:**
- Create:
  - `app/Actions/ConnectWhatsApp.php`, `app/Actions/DisconnectWhatsApp.php`, `app/Actions/SaveWhatsAppSettings.php`;
  - `app/Http/Controllers/WhatsAppController.php`, `app/Http/Requests/WhatsAppSettingsRequest.php`;
  - `resources/js/pages/online/WhatsApp.vue`.
- Modify: `routes/web.php` (no grupo `can:company.manage`, junto de `online.settings`) e `resources/js/layouts/AppLayout.vue` (item "WhatsApp" logo depois de "Loja online", `can('company.manage')`, ícone `MessageCircle` de `@lucide/vue`).
- Test: `tests/Feature/WhatsAppSettingsTest.php`.

**Interfaces:**
- Consumes: `EvolutionClient`, `WhatsAppSetting`, `WhatsAppMessage`, `WhatsAppTemplates::EVENTS` e `DEFAULTS`.
- Produces as rotas:
  - **`GET loja-online/whatsapp`** → `online.whatsapp`. Renderiza `online/WhatsApp` com estas props:
    - `available`: `EvolutionClient::configured()`;
    - `settings`: `{enabled, connected, state, has_instance, templates}`;
      - `templates` traz os 9 eventos, cada um como `{key, label, default, value}`, em que `value` é o override ou null;
    - `qr`: `Cache::get("whatsapp-qr:{company_id}")` enquanto não estiver conectado; senão, null;
    - `messages`: as últimas 50 da empresa, cada uma com `{id, number, event_label, status, attempts, last_error, created_at, sent_at}`, em que `number` é o número do pedido online.
    - Ao abrir, se houver instância e a Evolution estiver configurada, atualiza `state` e `state_checked_at` com `state()`. Um `EvolutionException` aqui vira `state` `'close'`, sem erro na tela.
  - **`POST loja-online/whatsapp/conectar`** → `online.whatsapp.connect`. Chama `ConnectWhatsApp::handle(Company $company): void`:
    - sem instância, cria com o nome `'delivery-'.$company->id.'-'.Str::lower(Str::random(6))` e grava `instance_name` e `instance_token`;
    - com instância, chama `connect()`;
    - guarda o QR devolvido em `Cache::put("whatsapp-qr:{id}", $qr, 60)`;
    - um `EvolutionException` vira `ValidationException` em `whatsapp`, com a mensagem `'Não foi possível falar com o servidor do WhatsApp. Tente de novo em instantes.'`;
    - com a Evolution não configurada, vira 422 com `'O WhatsApp ainda não foi configurado no servidor.'`.
  - **`DELETE loja-online/whatsapp/conexao`** → `online.whatsapp.disconnect`. Chama `DisconnectWhatsApp::handle(Company $company): void`: faz `logout()`, grava `state` `'close'` e limpa o cache do QR. As falhas são tratadas como no `connect`.
  - **`PUT loja-online/whatsapp`** → `online.whatsapp.update`. Chama `SaveWhatsAppSettings::handle(Company $company, array $data): WhatsAppSetting`:
    - grava `enabled` e `templates`, guardando só os overrides não vazios, depois de `trim`;
    - a validação em `WhatsAppSettingsRequest` exige `enabled` booleano; `templates` é um array opcional com chaves só entre os 9 eventos, cada valor `nullable|string|max:1000`;
    - a chave de evento desconhecida gera 422 em `templates`.
- **Tela (`WhatsApp.vue`):**
  - Quando `!available`, um aviso.
  - O estado aparece como "Conectado" ou "Desconectado".
  - O botão "Conectar WhatsApp" ou "Gerar novo QR" mostra a imagem do `qr` com o texto "Abra o WhatsApp no celular da loja > Aparelhos conectados > Conectar aparelho e leia o código.".
  - `usePoll(5000, { only: ['settings', 'qr'] })` roda só enquanto existe `qr` e não está conectado.
  - O botão "Desconectar" usa `ConfirmDialog`.
  - O formulário tem o interruptor "Enviar mensagens aos clientes", um textarea por evento com o padrão como placeholder e a legenda dos marcadores, e o botão "Salvar".
  - A tabela de histórico mostra pedido, etapa, situação (Enviada, Na fila, Enviando, Falhou), tentativas, erro e horário.
  - Siga o padrão visual de `online/Settings.vue`.
- O controller usa a empresa do usuário logado. Todas as consultas passam pelo escopo de tenant e o `company_id` é explícito ao criar.

- [ ] **Step 1: Write the failing tests** in `WhatsAppSettingsTest` (`Http::preventStrayRequests()`, Evolution configurada):
  - `test_admin_connects_and_sees_qr`:
    - o fake de `instance/create` responde `hash` `TOK` e `qrcode.base64` `QR1`;
    - o POST connect redireciona e grava `instance_name`, que começa com `'delivery-'.$companyId.'-'`;
    - `instance_token` é `'TOK'` lido pelo modelo e diferente de `'TOK'` no banco cru, pela cifra;
    - o fake de `connectionState` responde `connecting`;
    - o GET mostra `qr` `QR1` e `settings.connected` false.
  - `test_reconnect_uses_existing_instance`: com instância existente, o POST chama `instance/connect/{name}` e nunca `instance/create`.
  - `test_evolution_error_is_friendly`: o create responde 500 e o POST devolve erro de validação `whatsapp` com o texto exato.
  - `test_not_configured_server`: a URL vazia faz a página mostrar `available` false e o POST devolver 422 com `'O WhatsApp ainda não foi configurado no servidor.'`.
  - `test_saves_enabled_and_only_non_empty_overrides`:
    - o PUT `{enabled: true, templates: {accepted: 'Oi {nome}', preparing: '  '}}` grava `templates` `['accepted' => 'Oi {nome}']`;
    - o evento `foo` responde 422.
  - `test_history_is_scoped_to_company`: as mensagens da empresa B não aparecem para o admin da A.
  - `test_requires_company_manage`: o Garçom (`online_orders.accept` sem `company.manage`) recebe 403 em todas as 4 rotas.
  - `test_disconnect_logs_out`: o DELETE chama `instance/logout/{name}` e o `state` fica `'close'`.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/WhatsAppSettingsTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement** as Actions, o request, o controller, as rotas, a página Vue e o item do menu.

- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/WhatsAppSettingsTest.php` e `npm run build`. Expected: PASS, com o build exit 0.

- [ ] **Step 5: Run** `vendor/bin/pint --dirty --format agent` and commit `feat: tela para conectar o WhatsApp da loja e editar as mensagens`.

---

### Task 5: Alerta no painel, aviso na página do cliente e verificação completa

**Files:**
- Modify:
  - `app/Http/Controllers/OnlineOrderController.php` (prop `whatsapp` no `index`);
  - `resources/js/pages/online/Orders.vue`;
  - `app/Http/Controllers/ShopController.php` (prop `whatsapp_updates` em `track`);
  - `resources/js/pages/shop/Track.vue`;
  - `docs/desenvolvimento/VALIDACAO.md`.
- Test: `tests/Feature/WhatsAppAlertsTest.php`.

**Interfaces:**
- Consumes: `WhatsAppSetting`, `WhatsAppMessage`.
- Produces:
  - **`online.orders`**:
    - A prop `whatsapp` é `null` quando não há settings `enabled` com instância.
    - Quando há, ela é `{connected: bool, failed_recent: int}`, em que `failed_recent` conta as mensagens `failed` da empresa nas últimas 24 h.
    - Ela entra no `usePoll` existente (`only` ganha `'whatsapp'`).
    - Em `Orders.vue`, com `whatsapp && !whatsapp.connected`, aparece o alerta `role="alert"` "WhatsApp desconectado: os clientes não estão recebendo as mensagens dos pedidos.".
    - Com `failed_recent > 0`, aparece "{n} mensagem(ns) de WhatsApp não enviada(s) nas últimas 24 horas."
    - Ambos levam a `online.whatsapp` quando `can('company.manage')`.
  - **`shop.track`**:
    - A prop `whatsapp_updates` é true só quando a empresa tem settings `enabled`, instância e `state === 'open'`. A consulta é por `company_id` explícito, porque a rota é pública.
    - Em `Track.vue` volta a frase `<p class="text-muted mt-4 text-sm">Você também recebe cada etapa no WhatsApp.</p>` com `v-if="whatsapp_updates"`.
    - O endpoint `shop.status` não muda (teste existente fixa as chaves de `store`).

- [ ] **Step 1: Write the failing tests** em `WhatsAppAlertsTest`:
  - `test_orders_page_flags_disconnected_whatsapp`:
    - settings `enabled` com `state` `'close'` → `whatsapp.connected` false;
    - `state` `'open'` → true;
    - sem settings → `whatsapp` null.
  - `test_orders_page_counts_recent_failures`: 2 `failed` de hoje, 1 `failed` de 2 dias atrás e 1 `failed` de outra empresa resultam em `failed_recent` 2.
  - `test_track_page_mentions_whatsapp_only_when_connected`: com `state` `'open'` a página mostra `whatsapp_updates` true; com `'close'` ou sem settings, false. A página é acessada sem login.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/WhatsAppAlertsTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement** as props e as telas.

- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/WhatsAppAlertsTest.php` — Expected: PASS.

- [ ] **Step 5: Add the "Complemento do Bloco C — WhatsApp" section** to `docs/desenvolvimento/VALIDACAO.md`, no estilo das seções existentes. Ela deve dizer:
  - quais variáveis a implantação precisa: `EVOLUTION_URL` e `EVOLUTION_API_KEY`;
  - que é preciso o worker da fila (`php artisan queue:work`) e o agendador, para `online-orders:expire` e `whatsapp:sweep`;
  - que o número da loja é conectado na tela "WhatsApp";
  - quais comandos de verificação rodar.

- [ ] **Step 6: Full verification** (record each result in the report):
  - `php artisan test --compact`: tudo PASS, menos os 2 ignorados de trigger MariaDB.
  - `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode All`: exit 0, com a suíte toda PASS, os cenários de concorrência existentes PASS, 0 deadlocks 1213 e 0 timeouts 1205.
  - `vendor/bin/pint --dirty --format agent`.
  - `node --test tests/Frontend/Composition.test.mjs`, `node --test tests/Frontend/MenuRecovery.test.mjs` e `node --test tests/Frontend/ShopCart.test.mjs`.
  - `npm run build`.
  - No PowerShell: `composer validate --strict`.

- [ ] **Step 7: Commit** `feat: alerta de WhatsApp desconectado e verificação do bloco C`.
