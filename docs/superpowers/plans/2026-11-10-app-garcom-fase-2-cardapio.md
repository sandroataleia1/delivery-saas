# App do Garçom — Fase 2 (comanda, cardápio e lançamento de itens) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O garçom toca numa mesa do salão, abre a mesa (se livre), vê a comanda com a situação de cada item e lança itens pelo cardápio (categorias, busca, adicionais, pizza, observação), sem item duplicado e com as mesmas regras da web.

**Architecture:** A API `/api/v1` ganha cardápio, comanda, abrir mesa, cotação e lançamento, chamando as mesmas Actions da web (`OpenTable`, `AddOrderItems`, `ItemComposer`). A validação de lançar itens sai do `TableOrderController` para um FormRequest usado pela web e pela API. No app, duas telas novas (Comanda e Cardápio) seguem o design aprovado (artifact `HQVE3HAQ1tYdNEXhQLFYfJ`, boards "Comanda" e "Adicionar itens").

**Tech Stack:** Laravel 13 / PHP 8.5 / PHPUnit / Sanctum · Kotlin, Jetpack Compose Material 3, Retrofit + kotlinx.serialization (já instalados na Fase 1). Nenhuma dependência nova.

**Spec:** `docs/superpowers/specs/2026-11-10-app-garcom-android-design.md`

**Fora desta fase:** "Entreguei" do item pronto e avisos (Fase 3); conta pedida, pré-conta e transferir (Fase 4). O botão "Conta" do mockup da comanda não entra agora.

## Global Constraints

- Todo texto visível em pt-BR. Tema claro (`GarcomTheme` já fixa claro); cores, fonte Inter e ícones da Fase 1 (`ui/theme/Theme.kt`, `res/drawable/ic_*.xml`). Ícone novo = vetor no traço do Lucide, gerado como os existentes; nunca emoji.
- Toques ≥ 44 dp; botão principal 56 dp, laranja `Brand`; status sempre com cor + texto.
- Branch `phase4/production-flow`; nunca `main`. Push e deploy só com pedido explícito do dono.
- API em `/api/v1`, Eloquent API Resources, grupo `auth:sanctum` + `EnsureApiAccountCanAccess`, tudo sob `can:tables.operate`. Regra de negócio nunca duplicada: preço, adicionais, pizza e esgotado são decididos no servidor.
- Toda consulta respeita `BelongsToCompany`; comanda ou mesa de outra empresa → 404.
- Erros de validação da API: 422 com `message` em pt-BR (o app mostra `message`).
- PHP: `vendor/bin/pint --dirty --format agent` antes de commitar; testes com `php artisan test --compact <arquivo>`.
- Android: `cd garcom-app && ./gradlew testDebugUnitTest assembleDebug`.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; git prefixado com `export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=C:/xampp/htdocs/delivery-saas`.

## Review Focus

1. Toque duplo / rede caindo depois do envio → reenviar com o mesmo `client_uuid` não duplica; o app só gera uuid novo depois de sucesso (Tasks 3 e 6).
2. Produto esgotado entre abrir o cardápio e enviar → servidor recusa com "X está esgotado." e o app mantém o carrinho (Tasks 3 e 6). **Hoje a web também aceita esgotado no POST; a Task 3 corrige para os dois.**
3. Duas pessoas abrem a mesma mesa livre ao mesmo tempo → a segunda recebe "A mesa N já está ocupada." e o app recarrega e mostra a comanda existente (Tasks 2 e 5).
4. Comanda fechada pelo caixa enquanto o garçom monta o pedido → 422 "Esta comanda já foi fechada." e o app volta ao salão (Tasks 3 e 6).
5. Pizza com sabor pausado ou adicional abaixo do mínimo → a cotação devolve 422 com a mensagem do `ItemComposer`, mostrada na folha do produto (Tasks 3 e 6).

---

### Task 1: API do cardápio

**Files:**
- Create: `app/Support/TableMenu.php`, `app/Http/Controllers/Api/V1/MenuController.php`, `app/Http/Resources/V1/MenuProductResource.php`
- Modify: `app/Http/Controllers/TableOrderController.php` (`show` usa `TableMenu::products()`), `routes/api.php`
- Test: `tests/Feature/Api/V1/MenuTest.php`

**Interfaces:**
- Produces: `TableMenu::products(): Collection<Product>` — exatamente a consulta de hoje em `TableOrderController::show` (ativo, vendável, não esgotado, por nome).
- `GET /api/v1/menu` → `{categories: [{id, name}], products: [{id, name, category_id|null, price: "12.50", unit, from_price: "12.50", composition: null | {pizza: {...}|null, groups: [...]}}]}`. `composition` e `from_price` vêm de `ProductComposition::forProducts()` (mesmo formato da web); produto sem composição → `composition: null`, `from_price = price`. Só categorias com algum produto listado.

- [ ] **Step 1: Testes que falham** em `MenuTest` (padrão de `TableTest`: `userWith(['tables.operate'], ...)` + `Sanctum::actingAs`):
  - `test_lista_produtos_vendaveis_com_a_categoria` — produto ativo aparece com `price` e `category_id`; esgotado, inativo e não vendável ficam de fora.
  - `test_pizza_traz_tamanhos_e_sabores` — produto pizza (usar a factory/estado que `ItemComposerTest` usa) → `products.0.composition.pizza.sizes` não vazio.
  - `test_nao_ve_cardapio_de_outra_empresa` — produto de outra empresa ausente.
  - `test_precisa_de_permissao_de_mesas` — sem `tables.operate` → 403.
- [ ] **Step 2:** `php artisan test --compact tests/Feature/Api/V1/MenuTest.php` → FAIL (rota inexistente).
- [ ] **Step 3:** Implementar `TableMenu`, controller e resource; trocar a consulta em `TableOrderController::show` por `TableMenu::products()`.
- [ ] **Step 4:** `php artisan test --compact tests/Feature/Api/V1/MenuTest.php tests/Feature/TableTest.php` → PASS; pint.
- [ ] **Step 5:** Commit "API v1: cardápio do app do garçom".

### Task 2: API da comanda e abrir mesa

**Files:**
- Create: `app/Http/Controllers/Api/V1/TableOrderController.php`, `app/Http/Resources/V1/TableOrderResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/TableOrderTest.php`

**Interfaces:**
- `POST /api/v1/tables/{table}/open` `{people: 1..100}` → 201 `{data: TableOrder}` via `OpenTable::handle($table, $people, $user)`; mesa ocupada/inativa → 422 com a mensagem da Action.
- `GET /api/v1/orders/{order}` → `{data: {id, table: number, people, waiter, minutes, closing: bool, total: subtotal(), items: [...]}}`; comanda fechada → 422 `"Esta comanda já foi fechada."`.
- Item: `{id, product_name, options_label|null, quantity, unit, total, notes|null, status}` com `status` ∈ `waiting | preparing | ready | delivered | cancelled | none` (de `production.status` / `production.delivered_at` / `cancelled_at`; sem produção → `none`). Itens cancelados vêm por último.
- O salão (`TableResource.order.id`, Fase 1) é o elo: mesa ocupada abre `GET orders/{order.id}`; mesa livre chama `open`.

- [ ] **Step 1: Testes que falham:**
  - `test_abre_mesa_livre_e_fica_como_responsavel` — 201; `data.waiter` = nome do garçom; `table_orders.user_id` = garçom.
  - `test_abrir_mesa_ocupada_avisa` — 2ª abertura → 422, `message` = "A mesa 5 já está ocupada.".
  - `test_comanda_mostra_itens_com_a_situacao` — item com produção `waiting`, outro `ready`, outro entregue (`delivered_at`), outro cancelado → statuses `waiting`, `ready`, `delivered`, `cancelled` (cancelado por último).
  - `test_comanda_de_outra_empresa_nao_aparece` — 404.
  - `test_comanda_fechada_avisa` — 422 "Esta comanda já foi fechada.".
- [ ] **Step 2:** Rodar `tests/Feature/Api/V1/TableOrderTest.php` → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Rodar → PASS; pint.
- [ ] **Step 5:** Commit "API v1: comanda e abrir mesa".

### Task 3: API de lançar itens e cotação (com regra de esgotado)

**Files:**
- Create: `app/Http/Requests/AddOrderItemsRequest.php`, `app/Http/Requests/QuoteItemRequest.php`
- Modify: `app/Http/Controllers/TableOrderController.php` (`addItems` recebe `AddOrderItemsRequest`), `app/Http/Controllers/Api/V1/TableOrderController.php`, `routes/api.php`
- Test: `tests/Feature/Api/V1/AddItemsTest.php`, e um caso novo em `tests/Feature/TableTest.php`

**Interfaces:**
- `AddOrderItemsRequest` = as regras de hoje de `TableOrderController::addItems` (mesmos nomes de atributo), usando `ValidatesComposition`, com `exists` também exigindo `sold_out = false`; mensagem do esgotado: `":name está esgotado."` (nome do produto) — via `after()`/closure, não via `exists` genérico.
- `POST /api/v1/orders/{order}/items` (`AddOrderItemsRequest`) → 201 `{data: TableOrder}` (mesmo resource da Task 2) via `AddOrderItems::handle`. Mesmo `client_uuid` de novo → 201 com a comanda, sem duplicar (a Action já ignora).
- `POST /api/v1/orders/{order}/quote` (`QuoteItemRequest`: um item, mesmas regras sem `client_uuid`) → `{unit_price, options_label|null}` via `ItemComposer::composeMany($companyId, [$item])[0]`; nada é gravado. Erro de composição → 422 com a mensagem do `ItemComposer`.

- [ ] **Step 1: Testes que falham** em `AddItemsTest`:
  - `test_lanca_itens_com_o_preco_do_servidor` — envia `quantity: 2` de produto `sale_price 10.00` → `items.0.total` = "20.00"; produção criada.
  - `test_reenvio_com_o_mesmo_uuid_nao_duplica` — dois POST iguais → 1 item.
  - `test_produto_esgotado_e_recusado` — `sold_out = true` → 422, `message` contém "está esgotado".
  - `test_comanda_fechada_recusa` — 422 "Esta comanda já foi fechada.".
  - `test_cotacao_de_pizza_devolve_preco_e_rotulo_sem_gravar` — pizza tamanho + sabor → `unit_price` igual ao que `ItemComposer` calcula; `table_order_items` vazio.
  - `test_cotacao_com_sabor_pausado_avisa` — 422 com a mensagem do composer.
  - Em `tests/Feature/TableTest.php`: `test_web_tambem_recusa_produto_esgotado` — `post(route('orders.items'))` com esgotado → erro de sessão em `items.0.product_id`.
- [ ] **Step 2:** Rodar os dois arquivos → FAIL.
- [ ] **Step 3:** Implementar; a web passa a usar o FormRequest sem mudar a resposta dela.
- [ ] **Step 4:** `php artisan test --compact tests/Feature/Api/V1 tests/Feature/TableTest.php tests/Feature/ItemComposerTest.php` → PASS; pint.
- [ ] **Step 5:** Commit "API v1: lançar itens e cotação; esgotado recusado também na web".

### Task 4: App — dados da comanda, do cardápio e carrinho

**Files:**
- Create em `garcom-app/app/src/main/java/br/com/esis/garcom/`: `data/OrderRepository.kt`, `data/MenuRepository.kt`, `ui/menu/Cart.kt`
- Modify: `data/ApiService.kt`, `ui/AppNavigation.kt` (`AppGraph` ganha `orders` e `menu`), `data/TableRepository.kt` (`FloorTable.orderId: Long?` vindo de `order.id`)
- Test: `app/src/test/java/br/com/esis/garcom/ui/menu/CartTest.kt`, casos novos em `data/RepositoryFailureTest.kt`

**Interfaces:**
- `data class OrderItem(id: Long, name: String, optionsLabel: String?, quantity: String, total: String, notes: String?, status: ItemStatus)`; `enum class ItemStatus { WAITING, PREPARING, READY, DELIVERED, CANCELLED, NONE }` com `fromApi` (desconhecido → `NONE`).
- `data class TableOrder(id: Long, table: Int, people: Int, waiter: String?, minutes: Int, total: String, items: List<OrderItem>)`.
- `sealed interface ApiResult<out T> { Ok(value: T); Rejected(message: String); NotFound; NoConnection; ServerError }` — 422 → `Rejected(message)`, 404 → `NotFound`, 5xx/outros HTTP → `ServerError`, demais exceções → `NoConnection`; `CancellationException` relançada (mesmo padrão de `ApiTableRepository`).
- `interface OrderRepository { suspend fun order(id: Long): ApiResult<TableOrder>; suspend fun open(tableId: Long, people: Int): ApiResult<TableOrder>; suspend fun addItems(orderId: Long, clientUuid: String, items: List<CartLine>): ApiResult<TableOrder>; suspend fun quote(orderId: Long, line: CartLine): ApiResult<Quote> }`; `data class Quote(unitPrice: String, optionsLabel: String?)`.
- `interface MenuRepository { suspend fun menu(): ApiResult<Menu> }`; `Menu(categories: List<MenuCategory>, products: List<MenuProduct>)`; `MenuProduct(id, name, categoryId: Long?, price: String, fromPrice: String, composition: Composition?)` (DTOs de `composition` espelham o JSON da Task 1).
- `data class CartLine(key: String, productId: Long, name: String, quantity: Int, unitPrice: String, optionsLabel: String?, notes: String?, sizeId: Long?, flavorIds: List<Long>, options: List<ChosenOption>)`.
- `class Cart` (imutável): `add(line)`, `increment(key)`, `decrement(key)` (zero remove), `quantityOf(productId): Int`, `count: Int` (soma das quantidades), `total: String` (soma `unitPrice × quantity`, 2 casas, `BigDecimal`), `lines`. Produto simples usa `key = "p<id>"` e soma; produto montado ganha `key` único por montagem.

- [ ] **Step 1: Testes que falham:**
  - `CartTest.produto_simples_soma_na_mesma_linha` — add 2× `p1` → 1 linha, quantidade 2, `count` 2.
  - `CartTest.total_soma_preco_vezes_quantidade` — 2 × "28.00" + 1 × "18.00" → "74.00".
  - `CartTest.diminuir_ate_zero_tira_a_linha`.
  - `CartTest.montagens_diferentes_do_mesmo_produto_ficam_separadas`.
  - `RepositoryFailureTest.rejeicao_422_traz_a_mensagem_do_servidor` — `HttpException` 422 com corpo `{"message":"X está esgotado."}` → `Rejected("X está esgotado.")`.
  - `RepositoryFailureTest.status_de_item_desconhecido_vira_none`.
- [ ] **Step 2:** `./gradlew testDebugUnitTest` → FAIL.
- [ ] **Step 3:** Implementar (Retrofit: `GET menu`, `GET orders/{id}`, `POST tables/{id}/open`, `POST orders/{id}/items`, `POST orders/{id}/quote`).
- [ ] **Step 4:** `./gradlew testDebugUnitTest assembleDebug` → PASS.
- [ ] **Step 5:** Commit "App garçom: dados de comanda, cardápio e carrinho".

### Task 5: App — tela da comanda

**Files:**
- Create: `ui/order/OrderScreen.kt`, `ui/order/OrderViewModel.kt`, `ui/order/ItemStatusStyle.kt`, `res/drawable/ic_chevron_left.xml`, `ic_plus.xml`, `ic_users.xml`
- Modify: `ui/AppNavigation.kt` (`Screen.Order(tableId, tableNumber, orderId: Long?)`, `Screen.Menu(orderId, tableNumber)`; voltar do sistema volta uma tela), `ui/floor/FloorScreen.kt` (toque na mesa abre a comanda, sai o Toast "Em breve")
- Test: `app/src/test/java/br/com/esis/garcom/ui/order/OrderViewModelTest.kt`

**Interfaces:**
- `sealed interface OrderState { Loading; AskPeople; Showing(order: TableOrder, error: String?); Closed(message: String) }`.
- `OrderViewModel(repository: OrderRepository, tableId: Long, orderId: Long?)`: sem `orderId` começa em `AskPeople`; `open(people)`; `refresh()`; `Rejected` na abertura com mesa ocupada → recarrega o salão (`Closed` com a mensagem, a tela volta ao salão e mostra a mensagem); `NotFound`/"já foi fechada" → `Closed`.
- Tela (board "Comanda" do mockup): topo com voltar, "Mesa N", "P pessoas · M min · garçom"; lista de itens com quantidade, nome, rótulo das opções, observação em itálico, valor e selo de status — Na cozinha (`WAITING`/`PREPARING`, âmbar), Pronto para levar (`READY`, verde), Entregue (`DELIVERED`, cinza), Cancelado (riscado, cinza); rodapé com "Total da mesa" e botão "Adicionar itens" (56 dp, largura total). Pedir pessoas: diálogo com − / número / + (1..100, começa em 2) e "Abrir mesa". Recarrega a cada 10 s enquanto visível, como o salão.

- [ ] **Step 1: Testes que falham:**
  - `mesa_livre_pede_pessoas_e_abre` — sem `orderId` → `AskPeople`; `open(3)` → `Showing` com a comanda.
  - `mesa_ja_ocupada_por_outro_volta_ao_salao_com_aviso` — `open` → `Rejected("A mesa 5 já está ocupada.")` → `Closed("A mesa 5 já está ocupada.")`.
  - `comanda_fechada_volta_ao_salao` — `order` → `Rejected("Esta comanda já foi fechada.")` → `Closed`.
  - `sem_conexao_mantem_a_comanda_e_mostra_aviso` — 2ª carga `NoConnection` → mesma comanda + erro "Sem conexão com o servidor".
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** `./gradlew testDebugUnitTest assembleDebug` → PASS.
- [ ] **Step 5:** Commit "App garçom: comanda da mesa".

### Task 6: App — cardápio e envio

**Files:**
- Create: `ui/menu/MenuScreen.kt`, `ui/menu/MenuViewModel.kt`, `ui/menu/ProductSheet.kt`, `res/drawable/ic_search.xml`, `ic_minus.xml`
- Modify: `ui/AppNavigation.kt`
- Test: `app/src/test/java/br/com/esis/garcom/ui/menu/MenuViewModelTest.kt`

**Interfaces:**
- `MenuViewModel(menu: MenuRepository, orders: OrderRepository, orderId: Long, newUuid: () -> String = { UUID.randomUUID().toString() })`.
- Estado: `MenuUi(products: List<MenuProduct>, categories, selectedCategory: Long?, query: String, cart: Cart, sending: Boolean, error: String?, sent: Boolean)`; `visibleProducts` filtra por categoria e por busca (sem acento e sem diferenciar maiúsculas: "pao" acha "Pão").
- `send()`: usa o uuid pendente (gerado na 1ª tentativa e reaproveitado em toda nova tentativa); `Ok` → `sent = true`, carrinho vazio, uuid descartado; `Rejected(msg)` → `error = msg`, carrinho mantido; "Esta comanda já foi fechada." → volta ao salão; `NoConnection` → "Sem conexão. Toque em Enviar de novo — o pedido não será duplicado.", carrinho e uuid mantidos. Ignorar `send()` enquanto `sending`.
- Produto sem `composition`: botão + soma direto no carrinho (`unitPrice = price`). Com `composition`: abre `ProductSheet` (tamanho, sabores até `max_flavors`, grupos com min/max e quantidade quando `allows_quantity`, observação até 120 caracteres, quantidade); "Adicionar" chama `quote` e só entra no carrinho com `Ok` (preço e rótulo do servidor); `Rejected` mostra a mensagem dentro da folha. Opções `paused` aparecem desabilitadas com "Indisponível".
- Tela (board "Adicionar itens"): voltar, "Adicionar itens" + "Mesa N", busca, chips de categoria ("Todas" primeiro), lista com nome, "a partir de" quando `fromPrice ≠ price`, preço e controle − n + (44 dp); botão fixo "Enviar para a cozinha" com a contagem e o total do carrinho, desabilitado com carrinho vazio. Sair com carrinho cheio pergunta "Descartar os itens?".

- [ ] **Step 1: Testes que falham:**
  - `envio_ok_limpa_o_carrinho_e_volta` — `sent = true`, carrinho vazio.
  - `falha_de_rede_mantem_carrinho_e_reenvia_com_o_mesmo_uuid` — 1º `NoConnection`, 2º `Ok`; o repositório falso recebeu o mesmo uuid nas duas chamadas.
  - `depois_de_enviar_o_proximo_pedido_usa_uuid_novo`.
  - `esgotado_mostra_a_mensagem_e_mantem_o_carrinho` — `Rejected("X-Bacon está esgotado.")`.
  - `busca_ignora_acento_e_maiusculas` — "pao" acha "Pão de queijo".
  - `produto_montado_so_entra_com_a_cotacao_do_servidor` — `quote` `Rejected` → carrinho vazio e erro na folha; `Ok("42.00")` → linha com `unitPrice` "42.00".
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar. Depois de `sent`, a navegação volta à comanda, que recarrega.
- [ ] **Step 4:** `./gradlew testDebugUnitTest assembleDebug` → PASS.
- [ ] **Step 5:** Commit "App garçom: cardápio e envio para a cozinha".

### Encerramento da fase

- [ ] Suíte PHP completa: `php artisan test --compact` → verde.
- [ ] Nova entrada em `config/changelog.php` (id novo) citando que produto esgotado não é mais aceito ao lançar na mesa; só vai junto com o deploy.
- [ ] Revisão final do branch (ver skill de execução); deploy e push só com pedido do dono.
- [ ] Teste manual (dono): abrir mesa livre, lançar item simples e pizza, ver na comanda e na tela da cozinha.
