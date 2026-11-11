# App do Garçom — Fase 3 (atendimento) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O garçom passa o turno no app: vê avisos (pedido do QR, item pronto), confirma/recusa pedido do QR, registra entrega, marca conta pedida, imprime a pré-conta e troca a mesa — sem push (Fase 4, depende da aprovação do Firebase).

**Architecture:** Servidor cria avisos por usuário (`waiter_notices`) nos mesmos pontos que já existem (`ReceiveMenuOrder`, `TransitionProduction`), agrupados depois do commit como a `PrintQueue` faz. A API v1 só chama Actions existentes (extraindo `RequestBill` do controller web). O app consulta os avisos junto com o salão (polling de 10 s) e ganha ações na comanda. A Fase 4 só acrescenta o envio por FCM aos avisos já gravados.

**Tech Stack:** Laravel 13 / PHP 8.5 / Sanctum / PHPUnit; Kotlin + Compose Material 3, Retrofit, kotlinx.serialization.

**Spec:** `docs/superpowers/specs/2026-11-10-app-garcom-android-design.md`

## Global Constraints

- A API chama as mesmas Actions que a web; nenhuma regra duplicada.
- Rotas novas em `routes/api.php` dentro de `auth:sanctum` + `EnsureApiAccountCanAccess`; permissões: `tables.operate`, `tables.confirm`, `production.deliver`.
- Isolamento por empresa via `BelongsToCompany`; registro de outra empresa → 404.
- Quem recebe aviso (spec): pedido do QR → usuários ativos com `tables.confirm`; item pronto → usuários ativos com `production.deliver`. Modo "só o garçom da mesa": só o responsável (`table_orders.user_id`); sem responsável elegível → todos, como no modo "todos".
- Agrupamento (spec): um aviso por mesa por acontecimento; setores diferentes → avisos separados. Ex.: "Mesa 5: 2 itens prontos — Bar".
- Pré-conta usa `company.service_fee_percent` com a mesma conta do `RegisterSale` (`Decimal::money(subtotal × % / 100)`), sem fechar a mesa. Impressora: `companies.bill_printer_id`, vazia → `order_printer_id`; sem impressora ativa → erro na tela.
- Mensagens ao usuário em pt-BR. Sem dependência nova. Tema light padrão no app.
- Pint em PHP alterado; `php artisan test --compact` verde; app `./gradlew testDebugUnitTest assembleDebug` verde.
- Nova entrada em `config/changelog.php` (id novo) antes do deploy.

## Review Focus

1. Painel da cozinha marca vários itens prontos numa só requisição (`panelReady`) → um aviso por mesa e setor, não um por item (Task 1).
2. Cliente reenvia o mesmo pedido do QR (replay idempotente de `ReceiveMenuOrder`) → nenhum aviso duplicado (Task 1).
3. Modo "só o garçom da mesa" com o responsável inativo, sem permissão ou sem app logado (sem token Sanctum) → aviso para todos (Task 1).
4. Toque duplo em "Entregar" → a segunda chamada não dá erro; item já entregue é ignorado (Task 3).
5. Aviso, pedido do QR ou comanda de outra empresa → 404; aviso de outro usuário nunca aparece nem é marcado como lido (Tasks 2 e 3).

---

### Task 1: Avisos do garçom no servidor

**Files:**
- Create: migration `create_waiter_notices_table`, migration `add_waiter_notices_to_restaurant_operation_settings_table`, `app/Models/WaiterNotice.php` (+ factory), `app/Support/WaiterNotifier.php`
- Modify: `app/Actions/ReceiveMenuOrder.php`, `app/Actions/TransitionProduction.php`, `app/Http/Requests/RestaurantOperationSettingsRequest.php`, `resources/js/components/RestaurantOperationForm.vue`, `app/Providers/AppServiceProvider.php` (scoped), `routes/console.php` (prune)
- Test: `tests/Feature/WaiterNoticeTest.php`

**Interfaces:**
- Produces: tabela `waiter_notices(id, company_id, user_id, kind ['menu_order'|'ready'], dining_table_id, table_order_id, title string(160), body string(255) nullable, read_at nullable, timestamps)`, índice `(user_id, id)`. `WaiterNotice` com `BelongsToCompany`, `MassPrunable` (> 3 dias); `routes/console.php` inclui `WaiterNotice::class` no `model:prune` existente.
- Produces: coluna `restaurant_operation_settings.waiter_notices` string, default `'all'`, valores `all|table_waiter`; regra `['sometimes', Rule::in(['all','table_waiter'])]`; select no formulário "Avisos no app do garçom": "Todos os garçons" / "Só o garçom da mesa".
- Produces: `WaiterNotifier` (scoped) — `menuOrder(MenuOrderReceipt $receipt): void` e `ready(ProductionWork $work): void`; ambos acumulam e gravam em `DB::afterCommit` (gravação desfeita não avisa). `ready` ignora work sem `table_order_item_id`. Destinatários: usuários da empresa com `active = true`, `hasPermission(...)` e ao menos um token em `personal_access_tokens`; aplica o modo da empresa (sem linha de configuração = `all`).
- Textos: QR pendente → title `"Mesa {n}: pedido pelo QR"`, body `"{units} itens · R$ {total} · aguarda confirmação"`; QR confirmado automaticamente → body `"{units} itens · confirmado automaticamente"` (1 item → "1 item"). Pronto → title `"Mesa {n}: {k} itens prontos — {setor}"` (1 → "1 item pronto"), body = `"2× Coca, 1× Suco"` (quantidade sem zeros à direita).
- Hooks: `ReceiveMenuOrder` chama `menuOrder` só para recibo criado agora (não no `return $existing`); `TransitionProduction` chama `ready` quando `$target === 'ready'`.

- [ ] **Step 1: Testes que falham** em `WaiterNoticeTest`:
  - `test_pedido_do_qr_avisa_quem_confirma_e_tem_app` — garçom com `tables.confirm` + token recebe; usuário com permissão sem token não recebe; usuário sem permissão não recebe; title `"Mesa 5: pedido pelo QR"`.
  - `test_reenvio_do_mesmo_pedido_do_qr_nao_duplica_aviso` (Review Focus 2).
  - `test_varios_prontos_na_mesma_requisicao_viram_um_aviso_por_setor` — dois itens da Cozinha e um do Bar pelo `production.panel.ready` → 2 avisos por destinatário; title `"Mesa 5: 2 itens prontos — Cozinha"` (Review Focus 1).
  - `test_item_de_delivery_pronto_nao_avisa_garcom`.
  - `test_modo_garcom_da_mesa_avisa_so_o_responsavel`.
  - `test_modo_garcom_da_mesa_sem_responsavel_com_app_avisa_todos` (Review Focus 3: responsável sem token).
  - `test_configuracao_aceita_so_valores_conhecidos` — PUT `company.operation` com `waiter_notices=x` → erro de validação; `table_waiter` → salvo.
- [ ] **Step 2:** `php artisan test --compact tests/Feature/WaiterNoticeTest.php` → FAIL.
- [ ] **Step 3:** Implementar conforme Interfaces (migrations via `php artisan make:migration`, model via `make:model -f`).
- [ ] **Step 4:** Mesmo comando → PASS; `tests/Feature/ProductionPanelTest.php` e `MenuReceiptTest.php` continuam verdes.
- [ ] **Step 5:** Pint; commit "Avisos do garçom: pedido do QR e item pronto".

### Task 2: API de avisos

**Files:**
- Create: `app/Http/Controllers/Api/V1/NoticeController.php`, `app/Http/Resources/V1/WaiterNoticeResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/NoticeTest.php`

**Interfaces:**
- `GET /api/v1/notices` (`api.v1.notices`) → `{unread: int, data: [{id, kind, title, body, table_id, order_id, read: bool, created_at (ISO 8601)}]}`; só do usuário logado, 50 mais novos, mais novo primeiro.
- `POST /api/v1/notices/read` (`api.v1.notices.read`) body `{up_to_id: int}` → marca como lidos os avisos do usuário com `id <= up_to_id`; responde `{unread: int}`.
- Sem permissão extra (qualquer usuário do app).

- [ ] **Step 1: Testes que falham:** `test_lista_so_os_avisos_do_usuario_com_contagem_de_nao_lidos`; `test_marcar_como_lido_ate_um_id`; `test_nao_marca_aviso_de_outro_usuario` (Review Focus 5); `test_exige_login`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar → PASS.
- [ ] **Step 5:** Pint; commit "API do garçom: avisos".

### Task 3: API de atendimento — conta, troca de mesa, entrega, pedido do QR

**Files:**
- Create: `app/Actions/RequestBill.php`
- Modify: `app/Http/Controllers/TableOrderController.php` (`requestBill` usa a Action), `app/Actions/TransferTable.php`, `app/Http/Controllers/Api/V1/TableOrderController.php`, `app/Http/Resources/V1/TableOrderResource.php`, `routes/api.php`
- Create: `app/Http/Controllers/Api/V1/MenuReceiptController.php`
- Test: `tests/Feature/Api/V1/ServiceActionsTest.php`; ajustar `tests/Feature/TableTest.php` se a troca de responsável mudar alguma asserção

**Interfaces:**
- `RequestBill::handle(TableOrder $order, bool $requested): void` — corpo atual do `requestBill` web (trava, "Esta comanda já foi fechada.").
- `TransferTable`: além do que já faz, grava `user_id = Auth::id()` e `user_name = Auth::user()->name` (spec: o responsável passa a ser quem transferiu).
- `TableOrderResource` ganha: em cada item `deliverable: bool` (produção `ready`, sem `delivered_at`/`cancelled_at`); na comanda `pending_receipts: [{id, total, created_at, items: [{product_name, quantity, options_label, notes}]}]` (recibos `pending` da comanda, ordem por id).
- Rotas (`tables.operate`): `POST orders/{order}/bill` `{requested: bool}` → TableOrderResource; `POST orders/{order}/transfer` `{dining_table_id}` (mesma regra `exists` da web) → TableOrderResource.
- Rota (`production.deliver`): `POST orders/{order}/deliver` `{item_ids: int[] min:1}` → TableOrderResource. Para cada item da comanda (item de outra comanda → 404): já entregue → ignora; `ready` → `TransitionProduction::handle($work, $work->version, 'delivered')`; outro estado → 422 `"{produto} ainda não está pronto."`. Tudo numa transação.
- Rotas (`tables.confirm`): `POST receipts/{receipt}/confirm` e `POST receipts/{receipt}/reject` `{reason: required|string|max:255}` → TableOrderResource da comanda do recibo, via `ConfirmMenuOrder`/`RejectMenuOrder`.

- [ ] **Step 1: Testes que falham:**
  - `test_pedir_conta_marca_e_desmarca`; `test_pedir_conta_em_comanda_fechada_recusa`.
  - `test_trocar_de_mesa_passa_o_responsavel_para_quem_trocou`; `test_trocar_para_mesa_ocupada_recusa` ("A mesa N está ocupada.").
  - `test_entregar_item_pronto`; `test_entregar_duas_vezes_nao_da_erro` (Review Focus 4); `test_entregar_item_que_nao_esta_pronto_recusa`; `test_entregar_exige_permissao`.
  - `test_comanda_mostra_pedido_do_qr_pendente_e_itens_entregaveis`.
  - `test_confirmar_pedido_do_qr`; `test_recusar_pedido_do_qr_exige_motivo`; `test_pedido_do_qr_de_outra_empresa_404` (Review Focus 5).
- [ ] **Step 2:** `php artisan test --compact tests/Feature/Api/V1/ServiceActionsTest.php` → FAIL.
- [ ] **Step 3:** Implementar; web `requestBill` passa a usar `RequestBill`.
- [ ] **Step 4:** Rodar o arquivo + `tests/Feature/TableTest.php` → PASS.
- [ ] **Step 5:** Pint; commit "API do garçom: conta, troca de mesa, entrega e pedido do QR".

### Task 4: Pré-conta impressa

**Files:**
- Create: migration `add_bill_printer_id_to_companies_table` (FK `printers`, nullOnDelete)
- Modify: `app/Support/Printing/Tickets.php`, `app/Support/Printing/PrintQueue.php`, `app/Http/Controllers/PrinterController.php`, `resources/js/pages/printers/Index.vue`, `app/Http/Controllers/Api/V1/TableOrderController.php`, `routes/api.php`
- Test: `tests/Feature/Api/V1/PreBillTest.php`

**Interfaces:**
- `Tickets::preBill(TableOrder $order, string $storeName, ?string $servicePercent, int $columns): string` — nome da loja, "CONTA - Mesa {n}", "Comanda #{number}", data/hora, itens ativos (quantidade × nome, rótulo das opções, total), "Subtotal", "Serviço ({p}%)" quando % > 0, "Total", rodapé "Não é documento fiscal".
- `PrintQueue::queueBill(TableOrder $order): bool` — impressora `bill_printer_id ?? order_printer_id`, ativa; falso sem impressora.
- Impressoras: campo "Conta da mesa (pré-conta)" (`bill_printer_id`, mesma regra do `order_printer_id`), texto de ajuda "Vazio usa a impressora de pedido completo".
- `POST /api/v1/orders/{order}/prebill` (`tables.operate`) → 202 `{queued: true}`; 422: comanda fechada "Esta comanda já foi fechada."; sem itens "Não há itens na conta."; sem impressora "Não há impressora ativa para a conta. Configure em Impressoras."

- [ ] **Step 1: Testes que falham:** `test_pre_conta_vai_para_a_impressora_da_conta_com_servico` (PrintJob criado; payload decodificado contém "Serviço (10%)" e o total com serviço); `test_sem_impressora_da_conta_usa_a_de_pedidos`; `test_sem_impressora_ativa_recusa`; `test_pre_conta_nao_fecha_a_mesa`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar + `tests/Feature/PrintingTest.php` → PASS.
- [ ] **Step 5:** Pint; commit "Pré-conta da mesa impressa pelo app".

### Task 5: App — dados das novas ações

**Files:**
- Create: `data/NoticeRepository.kt`
- Modify: `data/ApiService.kt`, `data/OrderRepository.kt`, `ui/AppNavigation.kt` (AppGraph), `test/.../data/RepositoryFailureTest.kt` (FakeApi)

**Interfaces:**
- `Notice(id, kind: NoticeKind {MENU_ORDER, READY, OTHER}, title, body?, tableId?, orderId?, read, createdAt)`, `Notices(unread: Int, items: List<Notice>)`; `NoticeRepository { suspend fun notices(): ApiResult<Notices>; suspend fun markRead(upToId: Long): ApiResult<Int> }`.
- `OrderItem` ganha `deliverable: Boolean`; `TableOrder` ganha `closing: Boolean` e `pendingReceipts: List<PendingReceipt(id, total, items: List<ReceiptLine(name, quantity, optionsLabel?, notes?)>)>`.
- `OrderRepository` ganha `requestBill(orderId, requested)`, `transfer(orderId, tableId)`, `deliver(orderId, itemIds)`, `confirmReceipt(receiptId)`, `rejectReceipt(receiptId, reason)` → `ApiResult<TableOrder>`; `printBill(orderId)` → `ApiResult<Unit>`.
- `AuthRepository` ganha `suspend fun minVersionCode(): Int?` (nulo se falhar — não bloqueia o app).
- `Waiter` ganha `permissions: Set<String>` lido do `permissions` que `/me` e `/login` já enviam (`WaiterResource`); `WaiterDto` com default `emptyList()`.

- [ ] **Step 1:** Testes que falham em `RepositoryFailureTest`: `avisos_desconhecidos_viram_other`; `comanda_le_pedido_do_qr_e_itens_entregaveis`.
- [ ] **Step 2–4:** Rodar → FAIL; implementar; `./gradlew testDebugUnitTest` → PASS.
- [ ] **Step 5:** Commit "App garçom: dados de avisos e atendimento".

### Task 6: App — ações na comanda

**Files:**
- Modify: `ui/order/OrderViewModel.kt`, `ui/order/OrderScreen.kt`, `ui/AppNavigation.kt`
- Test: `test/.../ui/order/OrderViewModelTest.kt`

**Interfaces / comportamento:**
- `OrderViewModel` ganha `deliver(itemIds)`, `deliverAllReady()`, `toggleBill()`, `printBill()`, `transfer(tableId)`, `confirmReceipt(id)`, `rejectReceipt(id, reason)`; `Showing` ganha `busy: Boolean` e `message: String?` (sucesso curto, ex.: "Conta enviada para a impressora."). Ignora ação enquanto `busy`. Resposta `Ok(order)` substitui a comanda; `Rejected` → `error`; "Esta comanda já foi fechada." → `Closed`.
- Tela: item `deliverable` mostra botão "Entregar" (44 dp); acima da lista, botão "Entregar todos os prontos (N)" quando N ≥ 2; cartão "Pedido do cliente (QR)" por recibo pendente com itens, total, "Confirmar" e "Recusar" (diálogo de motivo, obrigatório, até 255); menu no cabeçalho: "Pedir conta" / "Conta pedida ✓" (desmarca), "Imprimir conta", "Trocar de mesa" (lista de mesas livres do `TableRepository`; navega para a nova mesa ao concluir).
- Botões de entrega/QR só aparecem se `waiter.permissions` (Task 5) tiver `production.deliver`/`tables.confirm`.

- [ ] **Step 1: Testes que falham:** `entregar_atualiza_a_comanda`; `entregar_sem_conexao_mantem_e_avisa`; `pedir_conta_alterna`; `imprimir_conta_sem_impressora_mostra_erro`; `recusar_pedido_do_qr_exige_motivo` (VM ignora motivo vazio); `acao_em_comanda_fechada_volta_ao_salao`.
- [ ] **Step 2–4:** FAIL → implementar → `./gradlew testDebugUnitTest assembleDebug` PASS.
- [ ] **Step 5:** Commit "App garçom: entrega, conta, pré-conta, troca de mesa e pedido do QR".

### Task 7: App — avisos e versão mínima

**Files:**
- Create: `ui/notices/NoticesScreen.kt`, `ui/notices/NoticesViewModel.kt`, `ui/update/UpdateRequiredScreen.kt`, `res/drawable/ic_bell.xml` (já existe — reutilizar)
- Modify: `ui/floor/FloorViewModel.kt`, `ui/floor/FloorScreen.kt`, `ui/AppNavigation.kt`, `app/build.gradle.kts` (`buildFeatures.buildConfig = true` se ainda não estiver)
- Test: `test/.../ui/notices/NoticesViewModelTest.kt`, `test/.../ui/floor/FloorViewModelTest.kt`

**Comportamento:**
- Salão: o refresh de 10 s também busca avisos; sino no cabeçalho com selo do número de não lidos (some em 0; "9+" acima de 9); selo com `contentDescription` "N avisos não lidos".
- Tela Avisos: lista (não lido em negrito com ponto laranja), tempo relativo ("agora", "5 min", "2 h"); ao abrir marca como lidos até o maior id exibido; tocar num aviso com `tableId` abre a comanda daquela mesa.
- Início: antes do `me()`, `minVersionCode()`; se `BuildConfig.VERSION_CODE < min` → tela "Atualize o app" com botão para `https://play.google.com/store/apps/details?id=${BuildConfig.APPLICATION_ID}`; falha na consulta não bloqueia.

- [ ] **Step 1: Testes que falham:** `abrir_avisos_marca_como_lidos_ate_o_maior_id`; `avisos_sem_conexao_mostram_aviso_e_mantem_lista`; `salao_mostra_contagem_de_nao_lidos`.
- [ ] **Step 2–4:** FAIL → implementar → `./gradlew testDebugUnitTest assembleDebug` PASS.
- [ ] **Step 5:** Commit "App garçom: avisos e versão mínima".

### Encerramento da fase

- [ ] `php artisan test --compact` → verde.
- [ ] Entrada nova em `config/changelog.php` (id novo): avisos no app, pré-conta, configuração "Avisos no app do garçom", impressora da conta.
- [ ] Revisão final do branch; deploy (com `php artisan migrate --force`) e push só com pedido do dono.
- [ ] Teste manual (dono): pedido pelo QR → aviso no app → confirmar; item pronto na cozinha → aviso → Entregar; Pedir conta → Imprimir conta sai na impressora; Trocar de mesa.
