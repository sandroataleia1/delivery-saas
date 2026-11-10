# Bloco A — Opções e pizza em todos os canais: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produtos com grupos de opções (adicionais, bordas) e pizzas com tamanho e até N sabores por tamanho, montados no PDV, na mesa, no QR, no delivery da equipe e, depois, no catálogo, com preço calculado no servidor, snapshot imutável na linha, texto legível na produção e baixa de estoque de tudo.

**Architecture:** Cadastro novo (grupos, opções, tamanhos, preços e fichas por tamanho) isolado por empresa. Uma única classe `App\Support\ItemComposer` valida a escolha, trava as opções e devolve preço unitário, snapshot e rótulo. Todos os pontos de entrada a usam. O snapshot vai numa coluna JSON `options` e num `options_label` em cada tabela de linha. A baixa de estoque continua no `RegisterSale`/`ConsumeProduct`, que agora leem o snapshot via `App\Support\OptionStock`. No front, um módulo puro `resources/js/lib/composition.js` espelha o cálculo para exibição, e o componente `ItemComposer.vue` é reutilizado nas quatro telas.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia 3, Vue 3, Tailwind 4, PHPUnit, Node `node:test`, bcmath via `App\Support\Decimal`.

**Spec:** [docs/superpowers/specs/2026-11-08-catalogo-online-design.md](../specs/2026-11-08-catalogo-online-design.md) §2, §4, §5, §9. Os blocos B, C e D terão planos próprios, escritos depois que este for concluído.

## Global Constraints

- Nenhuma dependência nova, nem PHP nem npm.
- Migrations só aditivas, com data posterior a `2026_11_08_101140`. Não editar migrations entregues.
- Dinheiro sempre em string decimal com bcmath (`Decimal::money`). Nunca usar float no PHP. No JS, usar centavos inteiros (`resources/js/lib/money.js`).
- Do navegador só se aceitam ids e quantidades. Preço, nome e rótulo são sempre calculados no servidor.
- Produto sem grupos e sem pizza: comportamento, payloads e testes atuais ficam **inalterados**.
- Regras de pizza do spec:
  - o preço do sabor por tamanho é o preço da pizza inteira;
  - as regras são `highest` (padrão) ou `average`;
  - não há preço base de tamanho;
  - a "soma" não existe.
- Estoque do spec:
  - pizza baixa a ficha base do tamanho no lugar da ficha do produto;
  - sabor baixa a ficha do tamanho × (1/n) × quantidade da linha;
  - opção baixa a ficha (do tamanho, se existir; senão a geral) × quantidade da opção × quantidade da linha;
  - opção sem ficha só soma no preço;
  - a baixa usa a ficha atual no momento da venda;
  - o negativo continua permitido.
- O momento da baixa não muda: fechamento da mesa, acerto do delivery e venda do PDV.
- Opção pausada ou inativa é recusada em pedido novo. O replay pelo mesmo UUID devolve o resultado gravado, como o esgotado da Fase 4.
- Locks na ordem Company → origem → produtos e insumos (por id) → opções (por id).
- Isolamento: todo model novo usa `BelongsToCompany`. Toda FK de opção, tamanho ou ingrediente é conferida contra a empresa.
- Gestão das opções exige `products.manage`. Models de cadastro usam `Auditable`.
- Textos de interface em português. Testes em PHPUnit (`php artisan test --compact <arquivo>`), front em `node --test tests/Frontend/<arquivo>`.
- Pint: `vendor/bin/pint --dirty --format agent` com `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=C:/xampp/htdocs/delivery-saas`. Usar a mesma env em todo comando git.

## Review Focus

1. **Mesmo produto duas vezes com opções diferentes no mesmo pedido.** Exemplo: duas X-Bacon, uma com +bacon e outra sem. Devem virar duas linhas com preços distintos. Hoje `StoreSaleRequest` usa `distinct` em `product_id` e `ReceiveMenuOrder` indexa por produto. → Testes nas Tasks 4 e 5.
2. **Pizza meio a meio com quantidade 2 e regra média com dízima.** Exemplo: sabores 49,90 e 59,95, média 54,925. O arredondamento acontece só no fim, igual no PHP e no JS, para o PDV não recusar com "preço mudou". → Teste na Task 2 e teste espelho na Task 7.
3. **Opção pausada entre o envio do QR e a confirmação da equipe.** A confirmação recusa com mensagem clara, igual ao esgotado. → Teste na Task 5.
4. **Mesa fechada e delivery acertado com opções.** O estoque baixa sabores e adicionais uma única vez, e o `unit_cost` da venda inclui o custo das opções. → Testes na Task 6.
5. **Opção, tamanho ou ingrediente de outra empresa enviado por id forjado.** Deve ser recusado com 422, sem vazar nada. → Testes nas Tasks 1 e 2.

---

## File Structure

**Novos (backend):**
- `database/migrations/2026_11_09_000001_create_product_options.php`: todo o schema do bloco.
- `app/Models/OptionGroup.php`, `app/Models/Option.php`, `app/Models/OptionPrice.php`, `app/Models/OptionRecipeItem.php`, `app/Models/PizzaSize.php`: cadastro.
- `app/Actions/SaveOptionGroup.php`: cria e atualiza um grupo com opções, preços e fichas.
- `app/Actions/SavePizzaSetup.php`: tamanhos, sabores, regra e fichas de um produto pizza.
- `app/Actions/PauseOption.php`: pausar e retomar uma opção.
- `app/Http/Controllers/OptionGroupController.php`, `app/Http/Controllers/PizzaSetupController.php`.
- `app/Http/Requests/OptionGroupRequest.php`, `app/Http/Requests/PizzaSetupRequest.php`.
- `app/Support/ItemComposer.php`: validação, preço, snapshot e rótulo.
- `app/Support/OptionStock.php`: ingredientes a travar, consumo e custo a partir do snapshot.
- `app/Support/ProductComposition.php`: props de montagem para as telas.
- `app/Http/Requests/Concerns/ValidatesComposition.php`: regras de payload `size_id`, `flavor_ids` e `options` reutilizadas.

**Novos (frontend):**
- `resources/js/lib/composition.js`: cálculo puro de preço e rótulo.
- `resources/js/components/ItemComposer.vue`: modal de montagem.
- `resources/js/pages/products/Options.vue`: cadastro de grupos.
- `resources/js/pages/products/Pizza.vue`: configuração da pizza.

**Testes:**
- `tests/Feature/OptionSetupTest.php`, `tests/Feature/ItemComposerTest.php`, `tests/Feature/OptionChannelsTest.php`, `tests/Feature/OptionStockTest.php`.
- `tests/Frontend/Composition.test.mjs`.

**Modificados:**
- `RegisterSale`, `ConsumeProduct`, `AddOrderItems`, `CreateDeliveryOrder`, `ReceiveMenuOrder`, `ConfirmMenuOrder`, `DispatchProduction`, `CloseTableOrder`, `SettleDeliveryOrder`.
- `StoreSaleRequest`, `StoreDeliveryOrderRequest`, `StoreMenuOrderRequest`, `TableOrderController@addItems`.
- Controllers e páginas que listam linhas: `sales/Pos.vue`, `tables/Order.vue`, `deliveries/Form.vue`, `menu/Show.vue`, `production/Index.vue`, tickets e recibos.
- `routes/web.php`, `Product` (relações e `pizza_pricing`).

---

### Task 1: Schema e cadastro de opções e pizza

**Files:**
- Create: a migration, os 5 models, `SaveOptionGroup`, `SavePizzaSetup`, `PauseOption`, os 2 controllers, os 2 requests e as páginas `products/Options.vue` e `products/Pizza.vue`.
- Modify: `app/Models/Product.php`, `routes/web.php` (dentro do grupo `can:products.manage`) e `resources/js/pages/products/Form.vue` (link "Opções/Pizza" na edição).
- Test: `tests/Feature/OptionSetupTest.php`

**Interfaces:**
- **Produces, schema:**
  - `option_groups` (`id`, `company_id`, `name` 80, `kind` `'extra'|'flavor'`, `min_choices` tinyint, `max_choices` tinyint, `allows_quantity` bool, `active` bool, timestamps).
  - `product_option_group` (`company_id`, `product_id`, `option_group_id`, `position`), único por (`product_id`, `option_group_id`).
  - `options` (`id`, `company_id`, `option_group_id`, `name` 80, `price` decimal 12,2, `active`, `paused`, `position`, timestamps).
  - `pizza_sizes` (`id`, `company_id`, `product_id`, `name` 40, `slices` tinyint nullable, `max_flavors` tinyint 1..4, `position`, `active`, timestamps).
  - `option_prices` (`company_id`, `option_id`, `pizza_size_id`, `price` decimal 12,2), único por (`option_id`, `pizza_size_id`).
  - `option_recipe_items` (`id`, `company_id`, `option_id` nullable, `pizza_size_id` nullable, `ingredient_id` → products, `quantity` decimal 12,3). Semântica:
    - (opção, sem tamanho) = ficha geral da opção;
    - (opção, tamanho) = ficha da opção ou do sabor naquele tamanho;
    - (sem opção, tamanho) = ficha base do tamanho.

    CHECK de que pelo menos um dos dois é não nulo (trigger no SQLite, como na Fase 4).
  - `products.pizza_pricing` string nullable (`null` = não é pizza; `'highest'|'average'`).
  - O `down()` recusa rollback se houver linhas nas tabelas novas.
- **Produces, models:**
  - `Product::optionGroups(): BelongsToMany` (ordenado por `position`), `Product::pizzaSizes(): HasMany` e `Product::isPizza(): bool`.
  - `OptionGroup::options(): HasMany`.
  - `Option::prices(): HasMany` e `Option::recipeItems(): HasMany`.
  - `PizzaSize::recipeItems(): HasMany` (somente `option_id` nulo).
- **Produces, actions:**
  - `SaveOptionGroup::handle(?OptionGroup $group, array $data): OptionGroup`. `$data` = `{name, min_choices, max_choices, allows_quantity, active, product_ids: int[], options: [{id?, name, price, active, recipe: [{ingredient_id, quantity}]}]}`.
  - `SavePizzaSetup::handle(Product $product, array $data): void`. `$data` = `{pizza_pricing: 'highest'|'average', sizes: [{id?, name, slices?, max_flavors, active, recipe: [...]}], flavors: [{id?, name, active, prices: {sizeKey: price}, recipes: {sizeKey: [...]}}], size_prices: {optionId: {sizeKey: price}}}`.
    - `sizeKey` é o id ou o índice de um tamanho novo.
    - Cria ou atualiza o grupo `kind='flavor'` ligado só a este produto.
  - `PauseOption::handle(Option $option, bool $paused): void`, com lock da opção.
- **Rotas:**
  - `GET/POST produtos/opcoes` (`options.index`, `options.store`);
  - `PUT produtos/opcoes/{group}` (`options.update`);
  - `PATCH opcoes/{option}/pausa` (`options.pause`);
  - `GET/PUT produtos/{product}/pizza` (`products.pizza.edit`, `products.pizza.update`).

- [ ] **Step 1: Write the failing tests** in `OptionSetupTest`:
  - `test_admin_creates_extra_group_with_options_recipe_and_products`: POST `options.store` com 2 opções, uma com ficha de 0.030 de um insumo, ligada a 1 produto. Assert: 1 `OptionGroup`, 2 `Option`, 1 `OptionRecipeItem`, pivot com o produto e `AuditLog` criado.
  - `test_group_rejects_min_greater_than_max_and_foreign_ingredient`: POST com `min_choices=3, max_choices=1` e um ingrediente de outra empresa → 422 em `max_choices` e `options.0.recipe.0.ingredient_id`.
  - `test_admin_configures_pizza_sizes_flavors_prices_and_recipes`: PUT `products.pizza.update` com tamanhos Broto (max 1) e Grande (max 2); sabores Calabresa (39,90 / 49,90) e Quatro queijos (44,90 / 59,90); ficha base Grande com 0.300 de farinha; ficha da Calabresa Grande com 0.150 de calabresa. Assert: `pizza_pricing='highest'`, 2 `PizzaSize`, grupo `kind='flavor'` com 2 opções e 4 `OptionPrice`, e as fichas gravadas.
  - `test_flavor_needs_price_for_every_active_size`: falta o preço da Calabresa Broto → 422.
  - `test_pause_option_requires_products_manage`: usuário sem a permissão → 403. Admin → `paused=true`.
  - `test_option_setup_is_isolated_by_company`: admin da empresa B tenta PUT no grupo da empresa A → 404.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OptionSetupTest.php`. Expected: FAIL (rota inexistente).
- [ ] **Step 3: Implement**:
  - migration, models, actions, requests (ingredientes via `Rule::exists('products','id')->where('company_id', …)`) e controllers;
  - as páginas Vue reutilizam `RecipeEditor.vue` para cada ficha;
  - o `Form.vue` do produto ganha os links.
- [ ] **Step 4: Run** the same command. Expected: PASS. Run `php artisan test --compact tests/Feature/ProductTest.php tests/Feature/RecipeTest.php`. Expected: PASS (sem regressão).
- [ ] **Step 5: Commit** `feat: cadastro de opções e pizza`.

### Task 2: ItemComposer: validação, preço, snapshot e rótulo

**Files:**
- Create: `app/Support/ItemComposer.php`, `app/Http/Requests/Concerns/ValidatesComposition.php`.
- Test: `tests/Feature/ItemComposerTest.php`.

**Interfaces:**
- **Consumes:** models da Task 1.
- **Produces:**
  - `ItemComposer::composeMany(int $companyId, array $items): array`.
    - Entrada: `$items` = `[{product_id, size_id?: int, flavor_ids?: int[], options?: [{option_id: int, quantity?: int}]}]`.
    - Retorno: o mesmo índice com `{product: Product, unit_price: string, options: ?array, options_label: ?string, cost: string}`.
    - Trava, numa única query `lockForUpdate` ordenada por id, todas as opções e preços referenciados.
    - Produto sem composição e sem seleção: devolve `unit_price = sale_price`, `options = null`, `options_label = null`. O produto **não** é travado aqui; quem chama já trava.
    - Erros saem como `ValidationException` com chave `items.{i}`.
  - Formato do snapshot (`options`): `{size: {id, name}|null, pricing: 'highest'|'average'|null, flavors: [{id, name, price, fraction: '0.5000'}], extras: [{id, group, name, quantity, price}]}`.
  - Rótulo: partes unidas por ` · `.
    - Partes: tamanho, depois os sabores (`½ A ½ B`; com 3 sabores, `⅓ A ⅓ B ⅓ C`; com 1 sabor, só o nome), depois cada extra como `+{q} {name}` (q omitido quando 1, prefixo `Borda` não inventado: usa `{group}: {name}` para grupos com `max_choices=1` e `+{q} {name}` para os demais).
    - Máximo de 255 caracteres, truncado com `…`.
  - `ValidatesComposition::compositionRules(string $prefix): array`: regras de formato para `size_id`, `flavor_ids.*` e `options.*.option_id|quantity` (inteiros; `quantity` de 1 a 10; até 20 opções e 4 sabores).

Algoritmo de preço, que precisa ser idêntico ao JS da Task 7:

```
flavorValue = highest: max(prices) | average: bcdiv(sum(prices), n, 6)
extrasValue = Σ bcmul(priceForSize(option), quantity, 6)     // priceForSize: OptionPrice do tamanho, senão options.price
unit_price  = Decimal::money(bcadd(pizza ? flavorValue : sale_price, extrasValue, 6))   // arredonda só aqui
```

Validações:
- produto pizza exige `size_id` do produto, ativo, e de 1 a `max_flavors` sabores distintos do grupo `flavor` do produto, cada um ativo, não pausado e com preço no tamanho;
- produto não pizza recusa `size_id` e `flavor_ids`;
- cada opção pertence a um grupo `extra` ligado ao produto, ativo, com a opção ativa e não pausada;
- por grupo: total de escolhas entre `min_choices` e `max_choices`, sem repetir a opção;
- `quantity` > 1 só se `allows_quantity`;
- grupo obrigatório (min ≥ 1) ausente → "Escolha {grupo}.".

`cost` = custo da ficha base do tamanho (ou o `effectiveCost` do produto, se não for pizza) + Σ (custo da ficha do sabor no tamanho × fração) + Σ (custo da ficha da opção × quantidade), com 4 casas. Usa `cost_price` dos ingredientes.

- [ ] **Step 1: Write the failing tests**, com o fixture da Task 1 (Broto max 1, Grande max 2, Calabresa 39,90/49,90, Quatro queijos 44,90/59,90, Borda catupiry 8,00 com Grande 10,00, Bacon 5,00 `allows_quantity`):
  - `test_highest_rule_prices_half_and_half_by_most_expensive_flavor`: Grande + Calabresa + Quatro queijos + borda + 2 bacon → `unit_price === '79.90'` (59,90 + 10,00 + 10,00) e `options_label === 'Grande · ½ Calabresa ½ Quatro queijos · Borda: Catupiry · +2 Bacon'`.
  - `test_average_rule_rounds_only_at_the_end`: `pizza_pricing='average'`, sabores 49,90 e 59,95 → `'54.93'`.
  - `test_snapshot_records_fractions_and_prices`: a fração de cada sabor é `'0.5000'` e os preços do snapshot são os do tamanho.
  - `test_rejects_too_many_flavors_for_size`: Broto com 2 sabores → `ValidationException` em `items.0`.
  - `test_rejects_paused_flavor_and_paused_extra`.
  - `test_rejects_missing_required_group_and_quantity_without_allows_quantity`.
  - `test_rejects_option_from_other_company_or_unlinked_group`.
  - `test_plain_product_without_selection_keeps_sale_price_and_null_snapshot`.
  - `test_cost_includes_size_base_flavor_fraction_and_extras`: com custos conhecidos, confere o `cost` exato.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/ItemComposerTest.php`. Expected: FAIL (classe inexistente).
- [ ] **Step 3: Implement** `ItemComposer` and `ValidatesComposition` per the interface above.
- [ ] **Step 4: Run** the same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat: composição de itens com preço e snapshot no servidor`.

### Task 3: Colunas de snapshot e produção legível

**Files:**
- Create: `database/migrations/2026_11_09_000002_add_options_to_item_lines.php`.
- Modify: `DispatchProduction.php`, `ProductionController.php` (incluir `options_label` em `works` e nos avisos) e `resources/js/pages/production/Index.vue` (mostrar o rótulo abaixo do nome).
- Test: `tests/Feature/OptionChannelsTest.php` (criado aqui).

**Interfaces:**
- **Produces:**
  - colunas `options` JSON nullable e `options_label` string(255) nullable em `sale_items`, `table_order_items`, `delivery_order_items`, `menu_order_receipt_items` e `production_works`;
  - casts `'options' => 'array'` nos models correspondentes;
  - `DispatchProduction` copia `options_label` e `options` da linha para o trabalho.

- [ ] **Step 1: Failing test** `test_dispatch_copies_options_label_to_production_work`: cria um `TableOrderItem` com `options_label='Grande · ½ A ½ B'`, chama `DispatchProduction`. Assert: o `ProductionWork` tem o mesmo `options_label`, e GET `production.index` traz `works.0.options_label`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OptionChannelsTest.php`. Expected: FAIL (coluna inexistente).
- [ ] **Step 3: Implement** the migration, casts, DispatchProduction change, controller and page.
- [ ] **Step 4: Run** the same command plus `tests/Feature/ProductionDispatchTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: snapshot de opções nas linhas e na produção`.

### Task 4: PDV, mesa e delivery da equipe aceitam composição

**Files:**
- Modify:
  - requests e actions: `StoreSaleRequest.php` (remover `distinct` de `items.*.product_id`; incluir `compositionRules('items.*')`), `RegisterSale.php`, `TableOrderController.php@addItems`, `AddOrderItems.php`, `StoreDeliveryOrderRequest.php`, `CreateDeliveryOrder.php`;
  - controllers que alimentam `Pos.vue`, `tables/Order.vue` e `deliveries/Form.vue`: passam `ProductComposition::forProducts(...)` (Task 7 cria o presenter; aqui só o PHP do presenter).
- Create: `app/Support/ProductComposition.php`.
- Test: `tests/Feature/OptionChannelsTest.php`.

**Interfaces:**
- **Consumes:** `ItemComposer::composeMany` e `ValidatesComposition::compositionRules`.
- **Produces:**
  - `RegisterSale::handle` aceita em cada item `size_id`/`flavor_ids`/`options` (seleção, no PDV) **ou** `options_snapshot` (array já gravado, vindo do fechamento e do acerto, junto de `fixed_price`).
    - Com seleção: o preço vem de `composeMany` e é comparado com `expected_price`.
    - `sale_items` grava `options`, `options_label` e `unit_cost = composed cost`.
  - `ProductComposition::forProducts(Collection $products): array<int, array>`, chaveado por `product_id`. Formato: `{pizza: null|{pricing, sizes: [{id, name, slices, max_flavors}], flavors: [{id, name, paused, prices: {sizeId: price}}]}, groups: [{id, name, min, max, allows_quantity, options: [{id, name, price, paused, prices: {sizeId: price}}]}]}`.
    - Só inclui produtos com composição.
    - Opção inativa fica de fora; opção pausada aparece com `paused: true`.

- [ ] **Step 1: Failing tests** em `OptionChannelsTest`:
  - `test_pos_sells_same_product_twice_with_different_extras`: duas linhas do mesmo X-Bacon, uma com +1 Bacon (`expected_price` 33,00) e outra sem (28,00). Assert: 2 `SaleItem` com `options_label` `'+1 Bacon'` e `null`, e total 61,00.
  - `test_pos_rejects_stale_expected_price_with_options`: `expected_price` 28,00 numa linha com bacon → 422 "O preço de X-Bacon mudou".
  - `test_table_launch_stores_composed_price_and_snapshot`: POST `addItems` com a pizza Grande meio a meio. Assert: `unit_price` 59,90, `options.flavors` com 2 itens, e o `ProductionWork` com o rótulo.
  - `test_staff_delivery_stores_composed_lines`: mesmo cenário em `deliveries.store`.
  - `test_replay_after_option_paused_returns_persisted_launch`: lança a mesa com +Bacon, pausa o Bacon e reenvia o mesmo `client_uuid` → 302 sem nova linha.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OptionChannelsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement**:
  - em cada action, trocar o cálculo `product->sale_price` por `composeMany`, chamado **depois** do lock dos produtos e **antes** de gravar;
  - o replay por UUID continua retornando antes da composição.
- [ ] **Step 4: Run** the same command, then `php artisan test --compact tests/Feature/SaleTest.php tests/Feature/TableTest.php tests/Feature/DeliveryTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: PDV, mesa e delivery montam itens com opções`.

### Task 5: QR da mesa: recebimento e confirmação com opções

**Files:**
- Modify: `StoreMenuOrderRequest.php` (incluir `compositionRules('items.*')`), `ReceiveMenuOrder.php`, `ConfirmMenuOrder.php` e `MenuController.php` (props `composition` e `options_label` no `present`).
- Test: `tests/Feature/OptionChannelsTest.php`.

**Interfaces:**
- **Consumes:** `ItemComposer::composeMany` e `ProductComposition::forProducts`.
- **Produces:**
  - `ReceiveMenuOrder`:
    - normaliza a seleção (ids ordenados) e a inclui em `$lines`, portanto no `content_hash`;
    - indexa as linhas por posição, não por produto;
    - grava `options` e `options_label` em `MenuOrderReceiptItem`;
    - o total usa o `unit_price` composto.
  - `ConfirmMenuOrder`:
    - trava as opções do snapshot (ids ordenados, `lockForUpdate`) depois dos produtos;
    - se alguma estiver pausada ou inativa → "Um produto ficou indisponível. Resolva ou rejeite o pedido.";
    - copia `options` e `options_label` para o `TableOrderItem`.

- [ ] **Step 1: Failing tests**:
  - `test_qr_receipt_with_two_compositions_of_same_product`: duas pizzas diferentes no mesmo envio → 2 itens no recebimento, com totais distintos.
  - `test_confirm_refuses_when_option_paused_after_receipt`: recebe com +Bacon, pausa o Bacon e confirma → 422 com a mensagem acima; o recebimento continua `pending`.
  - `test_confirm_copies_snapshot_to_table_item`.
  - `test_qr_replay_with_different_options_is_conflict`: mesmo `client_uuid` com outro `flavor_ids` → 409.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OptionChannelsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** per the interface.
- [ ] **Step 4: Run** the same command plus `tests/Feature/MenuReceiptTest.php tests/Feature/MenuTest.php tests/Feature/ProductionAutomaticTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: QR da mesa com opções e revalidação na confirmação`.

### Task 6: Baixa de estoque das opções

**Files:**
- Create: `app/Support/OptionStock.php`.
- Modify: `ConsumeProduct.php`, `RegisterSale.php` (`lockProducts` e o loop de consumo), `CloseTableOrder.php` e `SettleDeliveryOrder.php` (enviar `options_snapshot` em cada item).
- Test: `tests/Feature/OptionStockTest.php`.

**Interfaces:**
- **Consumes:** o formato do snapshot da Task 2.
- **Produces:**
  - `OptionStock::ingredientIds(array $snapshots): Collection`: ids de ingredientes de todas as fichas referenciadas (base do tamanho, sabor no tamanho, opção no tamanho ou geral).
  - `OptionStock::consumptions(array $snapshot, string $lineQuantity): array<int, array{ingredient_id: int, quantity: string, source: string}>`: quantidade positiva com 3 casas. `source` é o texto da observação do movimento, por exemplo `'Pizza · sabor Calabresa'`.
  - `ConsumeProduct::handle(Product $product, string $quantity, ?string $notes = null, ?Sale $sale = null, ?array $options = null): void`.
    - Com `options` contendo `size`: **não** usa a ficha do produto e consome a base do tamanho.
    - Em seguida, consome sabores e extras via `consumptions` e `MoveStock` (`StockMovement::SALE_INGREDIENT`, `allowNegative: true`), em ordem de `ingredient_id`.
    - Sem `options`: comportamento atual.

- [ ] **Step 1: Failing tests**, com a ficha base Grande de 0.300 de farinha, Calabresa Grande de 0.150 de calabresa, Quatro queijos Grande de 0.200 de queijo e Bacon (geral) de 0.030 de bacon:
  - `test_pos_sale_of_half_and_half_consumes_base_fractions_and_extras`: venda de 2 pizzas Grande ½ Calabresa ½ Quatro queijos + 2 Bacon. Assert, nos saldos:
    - farinha −0.600;
    - calabresa −0.150 (0.150 × 0.5 × 2);
    - queijo −0.200;
    - bacon −0.120.
  - `test_pizza_does_not_consume_product_recipe`: a pizza com ficha própria de 1.000 de X não baixa X.
  - `test_option_without_recipe_only_adds_price`.
  - `test_table_close_consumes_options_once`: lança, entrega (`deliverProduction`) e fecha → saldos como no primeiro teste e um movimento por ingrediente e origem.
  - `test_delivery_settlement_consumes_options_and_sale_cost_includes_options`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/OptionStockTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement**. Em `RegisterSale::lockProducts`, unir `OptionStock::ingredientIds` dos snapshots ao conjunto travado (mesma query ordenada por id).
- [ ] **Step 4: Run** the same command plus `tests/Feature/RecipeTest.php tests/Feature/StockTest.php tests/Feature/SaleTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: baixa de estoque de sabores, bordas e adicionais`.

### Task 7: Montagem no front e exibição em todas as telas

**Files:**
- Create: `resources/js/lib/composition.js`, `resources/js/components/ItemComposer.vue` e `tests/Frontend/Composition.test.mjs`.
- Modify:
  - telas de lançamento: `sales/Pos.vue`, `tables/Order.vue`, `deliveries/Form.vue`, `menu/Show.vue`;
  - telas de exibição: `tables/Ticket.vue`, `deliveries/Show.vue`, `deliveries/Ticket.vue`, `sales/Show.vue`, `sales/Receipt.vue` e os controllers que alimentam essas páginas (incluir `options_label`).

**Interfaces:**
- **Consumes:** o formato `ProductComposition::forProducts` da Task 4 (prop `composition` nas páginas) e o payload de item `{product_id, quantity, size_id?, flavor_ids?, options?: [{option_id, quantity}]}`.
- **Produces:**
  - `composition.js` exporta:
    - `priceCents(product, composition, selection): number`: mesmo algoritmo da Task 2; a média é calculada em centavos × 10⁶ e arredondada só no fim, half-up igual ao `Decimal::money`;
    - `label(composition, selection): string|null`: mesmo texto da Task 2;
    - `missing(composition, selection): string[]`: mensagens de grupos obrigatórios e de limites.
  - `ItemComposer.vue`:
    - props: `product`, `composition`;
    - emite `add` com `{selection, unitCents, label}`;
    - não deixa confirmar enquanto `missing` não estiver vazio;
    - mostra o preço ao vivo, bloqueia opções pausadas e tem campo de observação (120).
  - Cada tela de lançamento abre o modal quando o produto tem composição; senão, adiciona direto como hoje.
  - As linhas do carrinho são chaveadas por produto + seleção normalizada.

- [ ] **Step 1: Failing tests** in `Composition.test.mjs` (`node --test tests/Frontend/Composition.test.mjs`):
  - preço `79.90` e rótulo idênticos ao primeiro teste da Task 2;
  - média de 49,90 e 59,95 = `5493` centavos;
  - `missing` aponta o tamanho ausente e o excesso de sabores no Broto.
- [ ] **Step 2: Run** `node --test tests/Frontend/Composition.test.mjs`. Expected: FAIL (módulo inexistente).
- [ ] **Step 3: Implement** `composition.js`, the component, and wire the four launch screens and the display screens (show `options_label` below the product name).
- [ ] **Step 4: Run** `node --test tests/Frontend/Composition.test.mjs` and `node --test tests/Frontend/MenuRecovery.test.mjs`, then `npm run build`. Expected: PASS, PASS and exit 0.
- [ ] **Step 5: Commit** `feat: montagem de itens no PDV, mesa, delivery e QR`.

### Task 8: Verificação completa do bloco

- [ ] **Step 1: Run** `php artisan test --compact`. Expected: todos aprovados (os 2 ignorados de trigger MariaDB continuam ignorados).
- [ ] **Step 2: Run** `vendor/bin/pint --dirty --format agent` (com a env de git). Expected: sem erro. Se houver ajustes de formatação, commit `style: pint`.
- [ ] **Step 3: Run** the MariaDB harness of Phase 4 (the commands in `docs/desenvolvimento/VALIDACAO.md`, section Fase 4). Expected: suíte completa aprovada, incluindo o CHECK/trigger de `option_recipe_items`.
- [ ] **Step 4: Run** `npm run build` and `composer validate --strict` (pelo PowerShell). Expected: exit 0.
- [ ] **Step 5: Commit** dos ajustes finais, se houver.
