# Bloco B — Catálogo online, horários, aceite e acompanhamento: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Página pública por loja, em que o cliente monta um pedido de delivery ou retirada com nome e WhatsApp, a loja aceita ou recusa num painel, o pedido aceito vira delivery pelo fluxo da Fase 4, e o cliente acompanha as etapas por um link secreto.

**Architecture:**
- Uma configuração `OnlineStore` por empresa guarda o slug, os horários em turnos e a pausa manual. `App\Support\StoreSchedule` decide se a loja está aberta.
- O envio do cliente grava um recebimento próprio, `OnlineOrder` com itens, no padrão do `MenuOrderReceipt`: idempotente por `client_uuid` e com o preço composto pelo `ItemComposer` do Bloco A. Nada vira delivery, produção, venda ou estoque antes do aceite.
- O aceite trava Company → recebimento → produtos → opções e cria o `DeliveryOrder` pelo mesmo núcleo do `CreateDeliveryOrder`, que é extraído para isso. Em seguida encaminha à produção.
- Um comando agendado expira os pendentes.
- O acompanhamento deriva as etapas do recebimento e do delivery.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia 3, Vue 3, Tailwind 4, PHPUnit, Node `node:test`, bcmath via `App\Support\Decimal`, disco `public` do Laravel para imagens.

**Spec:** [docs/superpowers/specs/2026-11-08-catalogo-online-design.md](../specs/2026-11-08-catalogo-online-design.md) §2, §3, §6 e §9. O Bloco A (§5) já está entregue em `phase4/production-flow` (`ec6cec6`). Os blocos C (WhatsApp) e D (Pix) terão planos próprios.

## Global Constraints

- **Dependências:** nenhuma nova, nem PHP nem npm.
- **Migrations:**
  - só aditivas, com data posterior a `2026_11_09_000002`;
  - não editar migrations entregues;
  - o `down()` recusa o rollback quando já houver dados, no padrão das Fases 3 e 4.
- **Dinheiro:**
  - sempre em string decimal com bcmath (`Decimal::money`), nunca float no PHP;
  - no JS, centavos inteiros.
- **Do navegador** só se aceitam ids, quantidades, textos de identificação e endereço. Preço, taxa, subtotal, total, nome do produto e rótulo são calculados no servidor.
- **Fuso:** `America/Sao_Paulo` para horários da loja.
- **Rotas públicas:**
  - ficam sob o middleware `public.page` (descarta o usuário da sessão) e throttle;
  - nunca usam o escopo de tenant: filtram por `company_id` explicitamente (`withoutGlobalScopes()`), como o `MenuController`.
- **A página pública não expõe:**
  - SKU, código de barras, custo ou ficha técnica;
  - dados de outros pedidos;
  - endereço salvo a partir de um telefone digitado.
- **Antes do aceite,** nada cria `DeliveryOrder`, `ProductionWork`, `Sale`, movimento de caixa ou de estoque.
- **Ordem de lock em toda escrita:**
  - envio: Company → produtos (por id) → opções (pelo `ItemComposer`);
  - aceite: Company → recebimento → produtos (por id) → opções (por id).
- **Padrões existentes:** Actions, FormRequests, `forceFill` para colunas protegidas, `Auditable`/`BelongsToCompany` nos modelos novos, mensagens em português.
- **Pagamento na entrega:** só `cash` (com troco opcional), `pix`, `credit` e `debit`, de `config('sales.payment_methods')`. "Pix agora" é do Bloco D e não aparece neste bloco.
- **Rodar testes:**
  - PHP: `php artisan test --compact <arquivo>`;
  - JS: `node --test <arquivo>`, com caminho explícito.
- **Ambiente:**
  - comandos git com `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=C:/xampp/htdocs/delivery-saas`;
  - `composer` só pelo PowerShell.

### Decisões deste plano (o spec deixou para quem implementa, §10)

- **Tabelas:**
  - `online_stores` (1 por empresa);
  - `online_store_hours` (turnos);
  - `online_orders` e `online_order_items`;
  - coluna `products.image_path`;
  - coluna `delivery_orders.online_order_id` (nullable, única).
- **Rotas públicas:**
  - `GET /loja/{slug}` (`shop.show`);
  - `POST /loja/{slug}/pedidos` (`shop.store`);
  - `GET /loja/{slug}/pedido/{token}` (`shop.track`);
  - `GET /loja/{slug}/pedido/{token}/status` (`shop.status`).
- **Permissões:**
  - configurar a loja online reutiliza `company.manage`;
  - aceitar e recusar usam a permissão nova `online_orders.accept`, no grupo "Loja online", concedida a todo perfil que tem `tables.confirm` (spec §6.4).
- **Token de acompanhamento:**
  - gerado pelo servidor com `Str::random(48)`;
  - guardado como `sha256` em `tracking_token_hash`, para busca, e cifrado (cast `encrypted`) em `tracking_token`, para o Bloco C montar o link do WhatsApp;
  - o reenvio idempotente com o mesmo `client_uuid` e o mesmo conteúdo devolve o mesmo link; conteúdo diferente devolve 409.
- **Telefone:**
  - normalizado para só dígitos, com DDD, 10 ou 11 dígitos;
  - o prefixo `55` é removido quando sobram 12 ou 13 dígitos.
  - Clientes antigos têm o telefone no formato digitado pela equipe. A busca filtra candidatos por `phone like %<últimos 8 dígitos>` e compara a normalização em PHP; vence o menor id.
- **Pedido mínimo:** vale sobre o subtotal, sem a taxa, para entrega e retirada.
- **Limites, conservadores e em `config/online.php`:**
  - até 30 linhas;
  - quantidade inteira de 1 a 20 por linha;
  - até 3 pedidos `pending` por telefone na empresa;
  - throttle `online`: 10/min por IP e slug, 5 por 10 min por telefone e slug, 300/min por empresa;
  - throttle `online-status`: 120/min por token e IP.
- **Prazo do aceite:**
  - `expires_at = created_at + accept_minutes` (padrão 15);
  - aceitar depois de `expires_at` é recusado e marca `expired` na mesma transação.
- **Imagens:**
  - disco `public` em `online/{company_id}/`;
  - jpg, png ou webp, até 1024 KB;
  - a implantação precisa de `php artisan storage:link` (anotar no relatório final).
- **Exibição e busca:**
  - o delivery criado no aceite mostra "Online #N" ao lado do próprio número;
  - o histórico de entregas busca pelos dois números.

## Review Focus

1. **Turno que passa da meia-noite e loja fechando entre abrir o cardápio e enviar.**
   - Exemplo: sábado de 18:00 às 02:00. A loja está aberta no domingo à 01:30 e fechada no domingo às 02:00.
   - O envio feito depois do fechamento ou durante a pausa é recusado com "A loja está fechada agora.", mesmo que a página tenha mostrado aberta.
   - → Testes nas Tasks 2 e 4.
2. **O mesmo WhatsApp digitado de jeitos diferentes** (`(11) 98888-7777`, `+55 11 98888-7777`, `11988887777`) cai no mesmo `Customer`, e nenhuma resposta pública devolve endereço salvo.
   - → Testes na Task 4.
3. **Aceitar no limite do prazo, ou aceitar e expirar ao mesmo tempo.** Há um único resultado, e nenhum delivery nasce de pedido expirado.
   - → Testes nas Tasks 5 e 6 e cenário O2 na Task 10.
4. **Produto esgotado ou opção pausada entre o envio e o aceite.**
   - O aceite é recusado com "Um item ficou indisponível. Recuse o pedido ou fale com o cliente.".
   - Nada é criado e o pedido continua `pending`.
   - → Teste na Task 5 e cenário O3 na Task 10.
5. **Valores forjados no envio:**
   - `delivery_fee`, `unit_price` ou `total` extras no payload são ignorados;
   - bairro de outra empresa gera 422;
   - opção de outra empresa gera 422.
   - O total gravado é o do servidor.
   - → Testes na Task 4.

---

### Task 1: Esquema, modelos, permissão e configuração da loja online

**Files:**
- Create:
  - `database/migrations/2026_11_10_000001_create_online_store_tables.php`;
  - `database/migrations/2026_11_10_000002_grant_online_order_permissions.php`;
  - `config/online.php`;
  - os modelos `app/Models/OnlineStore.php`, `OnlineStoreHour.php`, `OnlineOrder.php` e `OnlineOrderItem.php`;
  - `app/Http/Controllers/OnlineStoreController.php`;
  - `app/Http/Requests/OnlineStoreRequest.php`;
  - `app/Actions/SaveOnlineStore.php`;
  - `resources/js/pages/online/Settings.vue`.
- Modify:
  - `config/permissions.php`: grupo `online` com `online_orders.accept => 'Aceitar e recusar pedidos online'`, também nos perfis padrão que têm `tables.confirm`;
  - `routes/web.php`;
  - `app/Models/DeliveryOrder.php`: relação `onlineOrder()`;
  - o menu lateral em `resources/js/layouts/AppLayout.vue`.
- Test: `tests/Feature/OnlineStoreSettingsTest.php`.

**Interfaces:**
- **Produces, esquema:**
  - **`online_stores`:**
    - `company_id` único;
    - `enabled` bool (padrão false);
    - `slug` string(60), único global, `[a-z0-9-]`, de 3 a 60 caracteres;
    - `accepts_delivery` e `accepts_pickup` bool;
    - `min_order` decimal(10,2), padrão 0;
    - `eta_text` string(60), nullable;
    - `whatsapp` string(20), nullable, só dígitos;
    - `accept_minutes` unsigned smallint, padrão 15, de 5 a 120;
    - `logo_path`, nullable;
    - `paused_at` e `paused_by_name`, nullable;
    - `paused_reason` string(120), nullable;
    - timestamps.
  - **`online_store_hours`:**
    - `company_id`;
    - `weekday` tinyint de 0 a 6, com 0 = domingo;
    - `opens_at` e `closes_at`, `time`;
    - `closes_at <= opens_at` significa que o turno passa da meia-noite;
    - índice (`company_id`, `weekday`).
  - **`online_orders`:**
    - `company_id`;
    - `number` unsigned int, único por (`company_id`, `number`);
    - `client_uuid`, único por (`company_id`, `client_uuid`);
    - `content_hash` char(64);
    - `tracking_token_hash` char(64), único global;
    - `tracking_token` text (cast `encrypted`);
    - `status` string(20): `awaiting_payment` | `pending` | `accepted` | `rejected` | `expired`;
    - `type` (`delivery` | `pickup`);
    - `customer_id`, `customer_name` e `customer_phone`;
    - endereço: `street`, `number_address`, `complement`, `district` e `reference`;
    - `delivery_zone_id` (nullable) e `zone_name`;
    - `subtotal`, `delivery_fee` e `total`, decimal(10,2);
    - `payment_method` e `change_for` (nullable);
    - `notes` string(255), nullable;
    - `expires_at`;
    - `decided_at`, `decided_by_id`, `decided_by_name` e `decision_reason` string(255), todos nullable;
    - timestamps;
    - índice (`company_id`, `status`, `expires_at`).
  - **`online_order_items`:**
    - `company_id` e `online_order_id`;
    - `product_id`, `product_name` e `unit`;
    - `quantity` unsigned int;
    - `unit_price` e `total`;
    - `options` JSON e `options_label` string(255), nullable;
    - `notes` string(120), nullable.
  - **`delivery_orders.online_order_id`:** nullable, único, FK.
  - **`products.image_path`:** nullable.
- **Produces, modelos e rotas:**
  - Modelos:
    - `OnlineStore` (`hours()`, `isPaused(): bool`);
    - `OnlineOrder` (constantes de status, `items()`, `deliveryOrder()` hasOne via `delivery_orders.online_order_id`, `auditExclude` com os dois campos de token e `content_hash`).
  - Rotas autenticadas:
    - `GET /loja-online/configuracao` → `online.settings`;
    - `PUT /loja-online/configuracao` → `online.settings.update`;
    - as duas com `can:company.manage`.
  - `SaveOnlineStore::handle(array $data): OnlineStore`:
    - trava a Company;
    - cria ou atualiza a configuração;
    - substitui os turnos pelo conjunto enviado;
    - audita.
  - `OnlineStoreRequest` valida:
    - slug, regex e unicidade, ignorando a própria loja;
    - pelo menos um entre `accepts_delivery` e `accepts_pickup` quando `enabled`;
    - `hours.*.weekday` de 0 a 6;
    - `opens_at` e `closes_at` no formato `H:i`, diferentes entre si;
    - turnos do mesmo dia sem sobreposição. Um turno que passa da meia-noite ocupa o dia seguinte até `closes_at`.
- **Produces, configuração:**
  - `config/online.php`: `max_lines` 30, `max_line_quantity` 20, `max_pending_per_phone` 3, `payment_methods` `['cash','pix','credit','debit']`.

- [ ] **Step 1: Failing tests:**
  - `test_admin_saves_store_with_overnight_shift`: o admin salva o slug `pizzaria-exemplo`, entrega e retirada, mínimo `30,00`, e o sábado de 18:00 às 02:00. Ao recarregar, a página mostra o mesmo turno, e `accept_minutes` = 15.
  - `test_slug_must_be_unique_and_well_formed`: um slug de outra empresa e um slug `Pizza Exemplo` dão 422 em `slug`.
  - `test_overlapping_shifts_rejected`: sexta 18:00–23:00 e sexta 22:00–23:30 dão 422.
  - `test_enabled_store_requires_a_service_type`.
  - `test_settings_require_company_manage`: Caixa recebe 403.
  - `test_grant_migration_gives_accept_permission_to_roles_with_tables_confirm`: rodar o `up()` da migration de concessão sobre um perfil existente "Garçom".
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OnlineStoreSettingsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the interface and the settings page:
  - campos da loja;
  - grade semanal com "adicionar turno" por dia;
  - link público exibido: `route('shop.show', slug)` só depois da Task 8; até lá, mostrar o texto `/loja/{slug}`.
- [ ] **Step 4: Run** the same command plus `tests/Feature/RoleManagementTest.php tests/Feature/SeederSafetyTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: configuração da loja online e horários`.

### Task 2: Horário de funcionamento e pausa manual

**Files:**
- Create:
  - `app/Support/StoreSchedule.php`;
  - `app/Actions/PauseOnlineStore.php`.
- Modify:
  - `OnlineStoreController.php`: rotas `POST /loja-online/pausa` (`online.pause`, com motivo opcional) e `DELETE /loja-online/pausa` (`online.resume`), com `can:online_orders.accept`;
  - `online/Settings.vue`.
- Test: `tests/Feature/StoreScheduleTest.php`.

**Interfaces:**
- **Consumes:** `OnlineStore` e `hours` da Task 1.
- **Produces:**
  - `StoreSchedule::isOpen(OnlineStore $store, CarbonImmutable $at): bool`:
    - falso se `! enabled` ou se a loja está pausada;
    - verdadeiro se algum turno do dia de `$at` contém a hora, ou se um turno do dia anterior que passa da meia-noite ainda não fechou;
    - o intervalo é fechado na abertura e aberto no fechamento.
  - `StoreSchedule::nextOpening(OnlineStore $store, CarbonImmutable $at): ?CarbonImmutable`:
    - a próxima abertura em até 7 dias, ignorando a pausa;
    - devolve null sem turnos.
  - `StoreSchedule::label(OnlineStore $store, CarbonImmutable $at): string`. Valores possíveis:
    - `"Aberto"`;
    - `"Fechado · abre hoje às 18h"`;
    - `"Fechado · abre amanhã às 18h"`;
    - `"Fechado · abre sexta às 18h"`;
    - `"Fechado no momento"`, quando está pausada ou sem próxima abertura.
    - O minuto aparece quando não é zero: `"18h30"`.
  - `$at` sempre é convertido para `America/Sao_Paulo`.
  - `PauseOnlineStore::pause(?string $reason)` grava `paused_at`, `paused_by_name` e `paused_reason`; `resume()` limpa os três. As duas operações travam a Company e auditam.

- [ ] **Step 1: Failing tests:**
  - `test_overnight_shift_spans_midnight`: com o turno de sábado 18:00–02:00, sábado 17:59 dá fechado; sábado 18:00 aberto; domingo 01:30 aberto; domingo 02:00 fechado.
  - `test_label_next_opening`:
    - sexta 23:00, com turnos só de sexta 18–22 e sábado 18:30–23 → `"Fechado · abre amanhã às 18h30"`;
    - terça 10:00 com turno de terça 18–23 → `"Fechado · abre hoje às 18h"`.
  - `test_pause_closes_over_schedule_until_resumed`: com a loja aberta pela grade, pausar dá `isOpen` false e label `"Fechado no momento"`; retomar dá aberto.
  - `test_disabled_store_is_closed`.
  - `test_pause_requires_accept_permission`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/StoreScheduleTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** No Settings.vue, o botão "Pausar loja", com motivo opcional, ou "Reabrir" mostra o `label` atual.
- [ ] **Step 4: Run** the same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat: horário de funcionamento com turnos e pausa manual`.

### Task 3: Logo da loja e foto de produto

**Files:**
- Modify:
  - `OnlineStoreRequest.php` e `SaveOnlineStore.php`: `logo` file opcional e `remove_logo` bool;
  - `app/Http/Requests/ProductRequest.php`;
  - `app/Http/Controllers/ProductController.php`: `image` file opcional e `remove_image`;
  - `Product` model: accessor `image_url`;
  - as telas `online/Settings.vue` e o formulário de produto em `resources/js/pages/products/`.
- Test: `tests/Feature/OnlineImagesTest.php`.

**Interfaces:**
- **Produces:**
  - Regras do upload: `['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000']`.
  - Armazenamento:
    - `Storage::disk('public')->putFile("online/{company_id}", $file)`;
    - o arquivo antigo é apagado ao substituir ou remover, só depois do commit (`DB::afterCommit`).
  - `Product::imageUrl(): ?string` e `OnlineStore::logoUrl(): ?string`, via `Storage::disk('public')->url()`.
  - O formulário Inertia envia com `forceFormData: true`. Para PUT com arquivo, usar `_method: 'put'`.

- [ ] **Step 1: Failing tests**, com `Storage::fake('public')`:
  - `test_product_image_upload_and_replace_deletes_old_file`;
  - `test_rejects_non_image_and_oversized_file` (pdf → 422; 1.5 MB → 422);
  - `test_logo_removed_with_flag`;
  - `test_other_company_cannot_touch_product_image` (404).
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OnlineImagesTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command plus `tests/Feature/ProductTest.php`, then `npm run build`. Expected: PASS and exit 0.
- [ ] **Step 5: Commit** `feat: logo da loja e foto de produto`.

### Task 4: Envio do pedido online

**Files:**
- Create:
  - `app/Support/BrazilPhone.php`;
  - `app/Actions/PlaceOnlineOrder.php`;
  - `app/Http/Requests/StoreOnlineOrderRequest.php`;
  - `app/Http/Controllers/ShopController.php`, só com `store` nesta task.
- Modify:
  - `routes/web.php`: grupo `public.page` com `POST loja/{slug}/pedidos` e `throttle:online`;
  - `app/Providers/AppServiceProvider.php`: limiters `online` e `online-status`.
- Test: `tests/Feature/OnlineOrderPlaceTest.php`.

**Interfaces:**
- **Consumes:**
  - `ItemComposer::composeMany(int $companyId, array $items): array` (Bloco A), que lança `items.{i}`;
  - `ValidatesComposition::compositionRules('items.*')`;
  - `StoreSchedule::isOpen` (Task 2);
  - `DeliveryZone`, que é escopado por empresa e precisa de `withoutGlobalScopes()`.
- **Produces:**
  - `BrazilPhone::normalize(string $raw): ?string` devolve 10 ou 11 dígitos, ou null.
  - `PlaceOnlineOrder::handle(OnlineStore $store, array $data): array{order: OnlineOrder, token: string}`.
  - **Payload:**
    - `client_uuid` (uuid);
    - `type`;
    - `customer.name` (de 2 a 80 caracteres);
    - `customer.phone`;
    - `address`, só para entrega: `street`, `number`, `complement`, `reference` e `delivery_zone_id`;
    - `items[]` (`product_id`, `quantity` de 1 a 20, `notes` com até 120, mais as regras de composição);
    - `payment_method`;
    - `change_for`;
    - `notes`.
  - **Na transação:**
    1. Trava a Company e confere `active`, `store.enabled` e `isOpen(now)`. Fechada → 422 `store` "A loja está fechada agora.".
    2. Replay pelo `client_uuid`: mesmo `content_hash` → devolve o mesmo pedido e o token decifrado; diferente → 409.
    3. Valida o tipo aceito pela loja. A zona precisa ser da empresa → 422 `address.delivery_zone_id` "Não entregamos neste bairro.".
    4. Trava os produtos ativos, vendáveis e não esgotados, por id. Ausente → `items.{i}.product_id` "Produto indisponível.".
    5. Chama `composeMany`.
    6. Calcula subtotal, taxa (`zone.fee`, ou 0 na retirada) e total.
    7. Confere o mínimo → 422 `items` "Pedido mínimo de R$ 30,00.".
    8. Confere o limite de pendentes por telefone → 422 `customer.phone` "Você já tem pedidos aguardando a loja. Aguarde a resposta.".
    9. Resolve ou cria o `Customer`, normalizando o telefone.
    10. Lembra o endereço como em `CreateDeliveryOrder::rememberAddress`, com `district` = nome da zona.
    11. Numera com `max(number)+1` da empresa.
    12. Gera o token, grava `expires_at` e salva `pending` com os itens.
  - **Auditoria:** o ator é a constante `PlaceOnlineOrder::GUEST = 'Cliente (loja online)'`, via `Context::scope`, como em `MenuController::store`.
  - **Resposta:**
    - JSON `{number, tracking_url}`;
    - status 201 quando cria, 200 no replay.
    - A resposta não ecoa endereço nem dados do cliente.
  - **Hash:** usa a seleção normalizada no padrão de `ReceiveMenuOrder::normalizedSelection`. Os itens são compostos a partir da ordem enviada pelo cliente.

- [ ] **Step 1: Failing tests.** A loja do teste fica aberta com `Carbon::setTestNow` dentro do turno.
  - `test_places_delivery_order_with_server_prices`:
    - 2 × X-Bacon (28,00) + 1 × Coca (10,00), bairro Centro com taxa 6,00;
    - o payload traz `delivery_fee: 0`, `total: 1` e `items.0.unit_price: 1`;
    - assert: subtotal 66,00, taxa 6,00, total 72,00, status `pending`, `number` 1, `expires_at` = agora + 15 min;
    - assert: nenhum `DeliveryOrder`, `ProductionWork`, `Sale` ou `StockMovement` é criado.
  - `test_phone_formats_resolve_same_customer`:
    - três envios com `(11) 98888-7777`, `+55 11 98888-7777` e `11988887777` dão um só `Customer`, com o telefone `11988887777`;
    - um cliente antigo cadastrado com `(11) 98888-7777` é reutilizado.
  - `test_closed_store_refuses_even_if_page_was_open`: setTestNow após o fechamento dá 422 `store`.
  - `test_overnight_shift_accepts_after_midnight`.
  - `test_zone_from_other_company_rejected`.
  - `test_option_from_other_company_rejected`: dá 422 em `items.0`.
  - `test_minimum_order_enforced_on_subtotal`.
  - `test_replay_same_uuid_returns_same_link_and_different_content_is_409`.
  - `test_pending_limit_per_phone`.
  - `test_response_never_contains_address`:
    - o cliente já tem um endereço salvo de outro pedido;
    - um novo envio com o mesmo telefone e retirada devolve JSON sem `street` e sem `addresses`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OnlineOrderPlaceTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command plus `tests/Feature/ItemComposerTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: envio de pedido pela loja online`.

### Task 5: Aceite e recusa, com o delivery criado pelo mesmo núcleo

**Files:**
- Create:
  - `app/Actions/AcceptOnlineOrder.php`;
  - `app/Actions/RejectOnlineOrder.php`;
  - `app/Http/Controllers/OnlineOrderController.php`, só com `accept` e `reject` nesta task.
- Modify:
  - `app/Actions/CreateDeliveryOrder.php`: extrair o núcleo;
  - `routes/web.php`: `POST loja-online/pedidos/{onlineOrder}/aceitar` e `.../recusar`, com `can:online_orders.accept`;
  - `DeliveryController.php` e as páginas `deliveries/Show.vue`, `Board.vue`, `History.vue` e `Ticket.vue`: mostrar "Online #N" e buscar no histórico pelos dois números.
- Test: `tests/Feature/OnlineOrderAcceptTest.php`.

**Interfaces:**
- **Consumes:**
  - `DispatchProduction::handle(DeliveryOrderItem $item)`;
  - `OnlineOrder` (Task 1).
- **Produces:**
  - `CreateDeliveryOrder::persist(User $user, Customer $customer, array $header, array $lines, ?int $onlineOrderId = null): DeliveryOrder`:
    - grava o pedido com `number = max+1` e as linhas já compostas;
    - chama `DispatchProduction` em cada linha;
    - não trava nada, porque quem chama já travou.
    - `handle()` passa a usar `persist`, com comportamento e testes inalterados.
  - `AcceptOnlineOrder::handle(OnlineOrder $order): DeliveryOrder`, sob o Gate `online_orders.accept`. Na transação, com Company → recebimento:
    1. `accepted` → devolve o delivery existente (idempotente).
    2. Outro status que não `pending` → 422 `online_order` "Este pedido não está mais aguardando aceite.".
    3. `now() >= expires_at` → marca `expired`, faz commit dessa mudança e responde 422 "O prazo de aceite deste pedido venceu.". Lançar a exceção depois de gravar exige fazer a gravação fora do rollback: grave o `expired` e devolva o erro sem exceção dentro da transação.
    4. Trava os produtos das linhas por id e revalida `active`, `sellable` e `! sold_out`. Trava as opções do snapshot (sabores e extras) por id e revalida `active` e `! paused`. Falha → 422 "Um item ficou indisponível. Recuse o pedido ou fale com o cliente.", e o pedido continua `pending`.
    5. Chama `persist`, passando como header o tipo, endereço, `district = zone_name`, taxa, pagamento, troco, observação e `online_order_id`, e como linhas as do snapshot.
    6. Marca `accepted`, com `decided_at` e `decided_by_*`.
  - `RejectOnlineOrder::handle(OnlineOrder $order, string $reason): OnlineOrder`:
    - Gate `online_orders.accept`;
    - motivo obrigatório, de 3 a 255 caracteres;
    - só a partir de `pending`.
  - Na tela de delivery, o pedido mostra a origem "Online #N" (`online_order.number`).

- [ ] **Step 1: Failing tests:**
  - `test_accept_creates_delivery_with_snapshot_and_dispatches_production`:
    - delivery com `online_order_id`, mesmas linhas, preços, `options_label` e taxa;
    - um `ProductionWork` por linha;
    - status `accepted`;
    - o número do delivery segue a sequência própria e não é o número online.
  - `test_accept_is_idempotent`: dois aceites dão um delivery.
  - `test_accept_refused_when_product_sold_out_after_order`: 422 com a mensagem acima; o pedido segue `pending` e nenhum delivery é criado.
  - `test_accept_refused_when_option_paused_after_order`.
  - `test_accept_after_deadline_marks_expired`: 422 e status `expired`.
  - `test_reject_requires_reason_and_only_from_pending`.
  - `test_cross_company_accept_404`.
  - `test_create_delivery_order_behaviour_unchanged`: rodar `tests/Feature/DeliveryTest.php`.
  - `test_history_search_finds_by_online_number`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OnlineOrderAcceptTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command plus `tests/Feature/DeliveryTest.php tests/Feature/ProductionDispatchTest.php tests/Feature/OptionChannelsTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: aceite e recusa de pedidos online`.

### Task 6: Expiração agendada

**Files:**
- Create:
  - `app/Actions/ExpireOnlineOrders.php`;
  - `app/Console/Commands/ExpireOnlineOrdersCommand.php`, com a assinatura `online-orders:expire`.
- Modify:
  - `routes/console.php`: `Schedule::command('online-orders:expire')->everyMinute()->withoutOverlapping()`.
- Test: `tests/Feature/OnlineOrderExpireTest.php`.

**Interfaces:**
- **Produces:**
  - `ExpireOnlineOrders::handle(CarbonImmutable $now): int`:
    - busca os ids de `pending` e `awaiting_payment` com `expires_at <= $now`, sem escopo de tenant;
    - para cada um, numa transação própria, trava Company → recebimento;
    - reconfere o status e o prazo e marca `expired`, com `decided_at` e `decision_reason = 'Prazo de aceite encerrado.'`;
    - devolve quantos expirou.
  - A auditoria registra o ator `'Sistema (expiração)'`.

- [ ] **Step 1: Failing tests:**
  - `test_command_expires_only_overdue_pending`: um pendente vencido, um no prazo e um aceito; só o primeiro vira `expired`.
  - `test_expire_skips_order_accepted_meanwhile`: o pedido é aceito antes de o loop chegar nele, simulado aceitando entre a coleta de ids e o processamento, com um `$beforeEach` injetável ou fazendo o aceite primeiro com o prazo vencido. Assert: continua `accepted`.
  - `test_schedule_registers_command`: `Schedule` contém `online-orders:expire` a cada minuto.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OnlineOrderExpireTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat: expiração agendada de pedidos online`.

### Task 7: Painel "Pedidos online" da loja

**Files:**
- Modify:
  - `OnlineOrderController.php`: `index`, com `GET /loja-online/pedidos` (`online.orders`) e `can:online_orders.accept`.
- Create:
  - `resources/js/pages/online/Orders.vue`;
  - `resources/js/components/OnlineOrderCard.vue`.
- Modify:
  - `AppLayout.vue`: item "Pedidos online", com um contador de pendentes via prop compartilhada em `HandleInertiaRequests`, só para quem tem a permissão.
- Test: `tests/Feature/OnlineOrderPanelTest.php`.

**Interfaces:**
- **Consumes:** `MenuReceiptAlerts.vue`, com as props `ids`, `operation` (com `sound_alerts`) e `lastUpdated`, e `RestaurantOperationPolicy`/`RestaurantOperationSettings` para `sound_alerts`.
- **Produces:**
  - Props:
    - `pending`: os pendentes mais antigos primeiro;
    - `recent`: até 30 decididos nas últimas 12 h;
    - `store`: `label` do `StoreSchedule`, `paused` e o link público;
    - `operation`.
  - Cada pedido:
    - número, tipo, cliente (nome e telefone), endereço e zona;
    - itens com `options_label` e `notes`;
    - subtotal, taxa e total;
    - pagamento e troco;
    - `created_at` e `expires_at`;
    - nos aceitos, o `delivery_id` com link para o delivery.
  - Polling `usePoll(10000, { only: ['pending','recent','store'] }, { keepAlive: true })`.
  - O cartão mostra a contagem regressiva até `expires_at`, os botões Aceitar e Recusar (motivo obrigatório num diálogo) e os erros da resposta.
  - O som toca só depois da ativação, pelo `MenuReceiptAlerts`.

- [ ] **Step 1: Failing tests:**
  - `test_panel_lists_pending_of_own_company_only`: Inertia `pending` com 1 item da própria empresa e nenhum de outra.
  - `test_panel_props_have_no_cost_or_token`: os props não contêm `tracking_token`, `cost` nem `content_hash`.
  - `test_panel_requires_permission`: Entregador recebe 403.
  - `test_shared_pending_count_only_for_permitted_users`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OnlineOrderPanelTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command and `npm run build`. Expected: PASS and exit 0.
- [ ] **Step 5: Commit** `feat: painel de pedidos online com aceite e alerta`.

### Task 8: Página pública do cardápio e finalização

**Files:**
- Modify:
  - `ShopController.php`: `show`, com `GET loja/{slug}` (`shop.show`) sob `public.page`.
- Create:
  - `resources/js/pages/shop/Show.vue`;
  - `resources/js/lib/shopCart.js`;
  - `tests/Frontend/ShopCart.test.mjs`.
- Modify:
  - `online/Settings.vue`: link público real.
- Test: `tests/Feature/ShopPageTest.php`.

**Interfaces:**
- **Consumes:**
  - `ProductComposition::forProducts($products)`, que inclui `from_price`;
  - `ItemComposer.vue`, com as props `product` e `composition`, que emite `add`;
  - `composition.js` (`priceCents`, `label`, `missing`, `selectionKey`, `payloadFields`, `lineError`);
  - `StoreSchedule::label` e `isOpen`.
- **Produces:**
  - Props públicos:
    - `store`: nome fantasia, `logo_url`, `slug`, `open`, `label`, `eta_text`, `min_order`, `accepts_delivery`, `accepts_pickup` e `whatsapp`;
    - `categories`;
    - `products`: `id`, `name`, `description`, `sale_price`, `sold_out`, `category_id` e `image_url`;
    - `composition`;
    - `zones` (`id`, `name`, `fee`);
    - `payment_methods` (rótulos de `config('online.payment_methods')`).
  - Um slug inexistente ou desabilitado, ou uma empresa inativa, dá 404.
  - `shopCart.js` exporta funções puras:
    - `addLine(cart, line): cart`, com uid próprio por linha e fusão só com a mesma `selectionKey` e as mesmas `notes`;
    - `subtotalCents(cart)`;
    - `totalCents(cart, zoneFeeCents)`;
    - `toPayload(cart)`;
    - `rememberCustomer(storage, data)` e `recallCustomer(storage)`, para nome, telefone e último endereço no `localStorage`, por slug, protegidos por try/catch.
  - **Telas** (rascunho aprovado):
    - **Cardápio:**
      - cabeçalho com aberto ou fechado (`label`), tempo, "taxa por bairro" e mínimo;
      - abas de categoria;
      - produtos com foto, descrição e preço ("a partir de" quando estão em `composition`);
      - esgotado visível e desabilitado;
      - barra "Ver sacola · N itens · R$".
    - **Montagem:** `ItemComposer.vue`.
    - **Finalizar:**
      - Entrega ou Retirar, conforme a loja;
      - nome e WhatsApp;
      - bairro (select das zonas, mostrando a taxa), rua, número, complemento e referência;
      - subtotal, taxa e total;
      - pagamento na entrega, com troco quando é dinheiro;
      - observação;
      - "Enviar pedido".
  - **Envio:**
    - `fetch` POST JSON com `client_uuid` estável enquanto o envio não se confirma, como no QR;
    - em sucesso, guarda o link no `localStorage` (lista "meus pedidos" por slug) e navega para `tracking_url`.
  - **Erros:** `items.{i}` sob a linha, via `lineError`, e os demais no topo.
  - **Loja fechada:** a página inteira mostra o `label`, desabilita "Enviar" e mantém a sacola.

- [ ] **Step 1: Failing tests:**
  - **PHP:**
    - `test_shop_page_exposes_no_sku_cost_or_barcode`: os produtos e a `composition` não contêm `sku`, `barcode`, `cost_price` nem `recipe`.
    - `test_disabled_or_unknown_slug_404`.
    - `test_shop_page_ignores_logged_in_staff_of_other_company`: um funcionário de outra empresa logado vê o cardápio desta loja.
    - `test_closed_label_shown`.
  - **Node:**
    - `addLine` mantém linhas separadas para a mesma composição com notas diferentes;
    - `subtotalCents` de 2 × 2800 + 1000 dá 6600;
    - `toPayload` não contém preço.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/ShopPageTest.php` and `node --test tests/Frontend/ShopCart.test.mjs`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** both commands and `npm run build`. Expected: PASS, PASS and exit 0.
- [ ] **Step 5: Commit** `feat: página pública do cardápio online e finalização`.

### Task 9: Acompanhamento pelo link secreto

**Files:**
- Modify:
  - `ShopController.php`: `track`, com `GET loja/{slug}/pedido/{token}` (`shop.track`), e `status`, com `GET .../status` (`shop.status`, `throttle:online-status`).
- Create:
  - `app/Support/OnlineOrderProgress.php`;
  - `resources/js/pages/shop/Track.vue`.
- Test: `tests/Feature/OnlineOrderTrackTest.php`.

**Interfaces:**
- **Consumes:**
  - `OnlineOrder` → `deliveryOrder`, com `status`, `dispatched_at`, `delivered_at`, `cancelled_at` e o motivo de cancelamento existente;
  - `ProductionGuard::syncDeliveryStatus` já mantém o status do delivery.
- **Produces:**
  - `OnlineOrderProgress::for(OnlineOrder $order): array{number:int, type:string, state:string, steps: list<array{key:string, label:string, at:?string, done:bool, current:bool}>, reason:?string, total:string, items:list<array{product_name:string, options_label:?string, quantity:int, total:string}>}`.
  - Etapas: `received` "Recebido" → `accepted` "Aceito pela loja" → `preparing` "Em preparo" → `ready` "Saiu para entrega", ou "Pronto para retirar" na retirada → `delivered` "Entregue", ou "Retirado".
  - `state` terminal é um de `rejected`, `expired` ou `cancelled`, com `reason`:
    - recusa: o `decision_reason`;
    - expiração: "A loja não confirmou a tempo.";
    - cancelamento: o motivo do delivery.
  - Para delivery, `ready` é marcado quando o status é `out_for_delivery` ou `delivered`. Para retirada, é marcado em `ready` ou `delivered`.
  - O horário (`at`) aparece quando existe: `created_at`, `decided_at`, `dispatched_at` ou `delivered_at`.
  - **Busca:** por `slug` e `sha256(token)`, com `hash_equals`. Token com formato inválido ou desconhecido → 404. Pedido de outra loja → 404.
  - **Resposta:**
    - `status` responde JSON com `Cache-Control: no-store, private`;
    - não inclui telefone, endereço nem dados do cliente além do primeiro nome.
  - **Track.vue:**
    - etapas com os horários;
    - itens e total;
    - aviso "Você também recebe cada etapa no WhatsApp." (texto fixo; o envio é do Bloco C);
    - botão "Falar com a loja no WhatsApp" (`https://wa.me/55{whatsapp}`) quando a loja tem número;
    - polling a cada 10 s, parando em estado terminal.

- [ ] **Step 1: Failing tests:**
  - `test_progress_pending_then_accepted_preparing_out_delivered`: percorre o fluxo real com aceite, `TransitionProduction` e as ações de saída e entrega existentes, conferindo `steps` a cada passo.
  - `test_pickup_shows_ready_for_pickup_label`.
  - `test_rejected_and_expired_show_reason`.
  - `test_wrong_token_or_other_store_404`.
  - `test_status_payload_has_no_phone_or_address`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OnlineOrderTrackTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command and `npm run build`. Expected: PASS and exit 0.
- [ ] **Step 5: Commit** `feat: acompanhamento do pedido online pelo link`.

### Task 10: Concorrência no MariaDB e verificação completa

**Files:**
- Create: `tests/Concurrency/online.php`, com os cenários O1–O4.
- Modify:
  - `tests/Concurrency/run.php`: novos tipos de job `placeonline`, `acceptonline` e `expireonline`, incluindo o arquivo;
  - `docs/desenvolvimento/VALIDACAO.md`: seção "Bloco B" com os comandos.

**Interfaces:**
- **Consumes:** `PlaceOnlineOrder`, `AcceptOnlineOrder`, `ExpireOnlineOrders`, `MarkProductSoldOut` e o padrão `run_all`/`report`/`inv`/`tally` do harness.
- **Produces:**
  - **O1:** oito envios com o mesmo `client_uuid` → 1 `OnlineOrder`, 1 número, 1 evento de auditoria `created`.
  - **O2:** aceitar × expirar com o prazo vencendo, 30 rodadas → em cada rodada, ou `accepted` com 1 delivery, ou `expired` com 0 deliveries; nunca os dois.
  - **O3:** aceitar × esgotar o produto, nas duas ordens → ou aceito com o produto marcado esgotado depois, ou recusado com o pedido `pending` e 0 deliveries.
  - **O4:** oito aceites do mesmo pedido → 1 delivery, 1 `ProductionWork` por linha.
  - Todos com 0 deadlocks 1213 e 0 timeouts 1205.

- [ ] **Step 1: Write** the scenarios.
- [ ] **Step 2: Run** `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode All`. Expected: suíte completa aprovada, com os cenários O1–O4 e os anteriores, e exit 0.
- [ ] **Step 3: Run:**
  - `php artisan test --compact`: todos aprovados, os 2 de trigger MariaDB ignorados;
  - `vendor/bin/pint --dirty --format agent`;
  - `node --test tests/Frontend/Composition.test.mjs`, `node --test tests/Frontend/MenuRecovery.test.mjs` e `node --test tests/Frontend/ShopCart.test.mjs`;
  - `npm run build`;
  - no PowerShell, `composer validate --strict`.
  - Expected: tudo exit 0.
- [ ] **Step 4: Commit** `test: concorrência do catálogo online e verificação do bloco B`.
