# App do Garçom — Fase 1 (fundação: base, login e salão) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar a pasta `garcom-app/` com o projeto Android base e a primeira fatia ponta a ponta: o garçom faz login (fica logado), vê o nome do restaurante e o salão com as mesas.

**Architecture:** Laravel ganha uma API versionada `/api/v1` autenticada por token Sanctum, reaproveitando as regras que a web já usa (login, permissões, montagem do salão). O app Android (Kotlin + Compose) consome essa API com Retrofit e guarda o token criptografado no aparelho.

**Tech Stack:** Laravel 13 / PHP 8.5 / PHPUnit · Laravel Sanctum · Kotlin, Jetpack Compose (Material 3), Retrofit + OkHttp + kotlinx.serialization, DataStore, Coil · Gradle 9.3.1, Android Gradle Plugin 9.1.0, compileSdk 36, minSdk 26, JDK 17.

**Spec:** `docs/superpowers/specs/2026-11-10-app-garcom-android-design.md`

**Fases seguintes (planos próprios, fora deste):** 2 · cardápio e lançamento de itens; 3 · avisos e push (Firebase); 4 · conta pedida, pré-conta impressa e transferência.

## Global Constraints

- Todo texto visível ao usuário em pt-BR.
- Branch `phase4/production-flow` (ou derivada); nunca `main`.
- Não instalar Sanctum antes da aprovação explícita do dono (Task 2, Step 1). Nenhum outro pacote PHP novo.
- API em `/api/v1`, respostas com Eloquent API Resources; regra de negócio nunca duplicada — chamar o que a web já usa.
- Toda consulta respeita o escopo da empresa (`BelongsToCompany`); o app nunca vê dados de outra empresa.
- Só usuários com `tables.operate` usam o app.
- PHP: `vendor/bin/pint --dirty --format agent` antes de commitar; testes com `php artisan test --compact <arquivo>`.
- Android: package/applicationId `br.com.esis.garcom`; nome do app "Garçom"; URL da API `https://delivery.esis.com.br/api/v1/` em `BuildConfig.API_BASE_URL`.
- Android: build e testes com `garcom-app\gradlew.bat` (PowerShell), nunca Gradle global.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; prefixar git com `export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=C:/xampp/htdocs/delivery-saas`.

## Review Focus

1. Usuário inativado ou empresa inativada com o app logado → próxima chamada da API responde 401 e o app volta ao login (Task 2).
2. Admin troca a senha do garçom → todos os tokens dele são revogados (Task 2).
3. Garçom de outra empresa ou sem `tables.operate` → não lista mesas de ninguém (Task 3).
4. Força bruta pelo app → o login da API usa o mesmo limite de 5 tentativas e a mesma auditoria da web (Task 2).
5. Sem internet / servidor fora → o app mostra "Sem conexão com o servidor" e um botão "Tentar de novo", sem fechar (Tasks 4 e 5).

---

### Task 1: Projeto Android base em `garcom-app/`

**Files:**
- Create: `garcom-app/settings.gradle.kts`, `garcom-app/build.gradle.kts`, `garcom-app/gradle.properties`, `garcom-app/gradle/libs.versions.toml`, `garcom-app/gradlew`, `garcom-app/gradlew.bat`, `garcom-app/gradle/wrapper/*`
- Create: `garcom-app/app/build.gradle.kts`, `garcom-app/app/src/main/AndroidManifest.xml`, `garcom-app/app/src/main/java/br/com/esis/garcom/MainActivity.kt`, `.../ui/theme/Theme.kt`, `res/values/strings.xml`
- Create: `garcom-app/.gitignore` (ignora `.gradle/`, `build/`, `local.properties`, `.idea/`, `*.iml`)
- Test: `garcom-app/app/src/test/java/br/com/esis/garcom/ApiConfigTest.kt`

**Interfaces:**
- Produces: `BuildConfig.API_BASE_URL: String`; `object ApiConfig { fun baseUrl(): String }` (garante a barra final); tema `GarcomTheme { }`.

- [ ] **Step 1:** Gerar o wrapper com o Gradle já baixado na máquina: `~/.gradle/wrapper/dists/gradle-9.3.1-all/*/gradle-9.3.1/bin/gradle.bat wrapper --gradle-version 9.3.1` dentro de `garcom-app/`. AGP 9.1.0 (já tem Kotlin embutido — não aplicar `org.jetbrains.kotlin.android`), plugins `com.android.application`, `org.jetbrains.kotlin.plugin.compose` 2.3.20 e `org.jetbrains.kotlin.plugin.serialization` 2.3.20. `local.properties` com `sdk.dir` apontando para `%LOCALAPPDATA%\Android\Sdk` (não versionado). `buildFeatures { compose = true; buildConfig = true }`.
- [ ] **Step 2: Teste que falha**

```kotlin
class ApiConfigTest {
    @Test fun base_url_aponta_para_a_api_v1_com_barra_final() {
        assertEquals("https://delivery.esis.com.br/api/v1/", ApiConfig.baseUrl())
    }
}
```
- [ ] **Step 3:** `garcom-app\gradlew.bat -p garcom-app testDebugUnitTest` → FAIL (ApiConfig não existe).
- [ ] **Step 4:** Implementar `ApiConfig` e a `MainActivity` com `GarcomTheme` mostrando "Garçom" centralizado.
- [ ] **Step 5:** `garcom-app\gradlew.bat -p garcom-app testDebugUnitTest assembleDebug` → BUILD SUCCESSFUL e `app/build/outputs/apk/debug/app-debug.apk` existe.
- [ ] **Step 6:** Commit "App garçom: projeto Android base".

### Task 2: API de login com token (Sanctum)

**Files:**
- Create: `routes/api.php` (via `php artisan install:api`), `app/Http/Controllers/Api/V1/AuthController.php`, `app/Http/Resources/V1/WaiterResource.php`, `app/Http/Middleware/EnsureApiAccountCanAccess.php`, `config/garcom.php`
- Modify: `app/Models/User.php` (trait `HasApiTokens`), `app/Http/Requests/LoginRequest.php` (extrair a autenticação para ser usada pela API), `app/Http/Controllers/UserController.php` (revogar tokens), `bootstrap/app.php` (registrar rotas/middleware da API)
- Test: `tests/Feature/Api/V1/AuthTest.php`

**Interfaces:**
- Produces (HTTP):
  - `POST /api/v1/login` `{email, password, device_name}` → 200 `{token, user}`; 422 `errors.email` em credencial errada, conta inativa ou excesso de tentativas; 403 `{message: "Seu perfil não atende mesas."}` sem `tables.operate`.
  - `GET /api/v1/me` → 200 `{data: {id, name, permissions: string[], company: {id, name, logo_url|null}}}`.
  - `POST /api/v1/logout` → 204, revoga só o token atual.
  - `GET /api/v1/app` (público) → `{min_version_code: int}` de `config('garcom.min_version_code')` (padrão 1).
- Grupo autenticado: middleware `auth:sanctum` + `EnsureApiAccountCanAccess` (se `! canSignIn()` revoga o token atual e responde 401 `{message: "Seu acesso está desativado."}`).
- `company.name` = `trade_name`; `logo_url` = `OnlineStore::logoUrl()` da empresa, `null` se não houver loja.

- [ ] **Step 1: PARAR e pedir aprovação** ao dono para `composer require laravel/sanctum` (via PowerShell) e `php artisan install:api --no-interaction`. Sem aprovação, a fase para aqui.
- [ ] **Step 2: Testes que falham** em `AuthTest`:
  - `test_garcom_entra_e_recebe_token_com_a_empresa` — usuário `userWith(['tables.operate'])`; login 200; `GET /me` com o token traz `company.name` e `permissions` contendo `tables.operate`; `audit_logs` tem `event = login`.
  - `test_senha_errada_nao_gera_token_e_e_auditada` — 422 `errors.email`, `personal_access_tokens` vazio, `audit_logs` tem `login_failed`.
  - `test_perfil_sem_mesas_nao_usa_o_app` — `userWith(['production.view'])` → 403, nenhum token criado.
  - `test_login_bloqueia_apos_cinco_tentativas` — 6ª tentativa com a senha certa → 422.
  - `test_usuario_inativado_cai_na_proxima_chamada` — com token válido, `active = false` → `GET /me` 401 e o token some.
  - `test_admin_trocar_a_senha_revoga_os_tokens` — `PUT users.update` com senha nova → `tokens()->count() === 0`.
  - `test_sair_revoga_so_o_token_do_aparelho` — dois tokens; logout com um → o outro continua válido.
  - `test_versao_minima_do_app_e_publica` — `GET /api/v1/app` → `min_version_code` = 1.
- [ ] **Step 3:** `php artisan test --compact tests/Feature/Api/V1/AuthTest.php` → FAIL (rotas inexistentes).
- [ ] **Step 4:** Implementar. A autenticação (rate limit, `canSignIn`, auditoria `login`/`login_failed`) sai de `LoginRequest::authenticate()` para um método reutilizável que a web e a API chamam; a API emite `createToken($device_name)` em vez de sessão. `UserController::update` chama `$user->tokens()->delete()` quando a senha muda ou o usuário é inativado (`update` e `toggleStatus`, rota `users.status`).
- [ ] **Step 5:** Rodar `AuthTest` e `tests/Feature/AuthTest.php` → PASS; `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 6:** Commit "API v1: login do app do garçom com token".

### Task 3: API do salão (mesas)

**Files:**
- Create: `app/Support/TableFloor.php`, `app/Http/Controllers/Api/V1/TableController.php`, `app/Http/Resources/V1/TableResource.php`
- Modify: `app/Http/Controllers/DiningTableController.php` (`index` passa a usar `TableFloor`), `routes/api.php`
- Test: `tests/Feature/Api/V1/TableTest.php`

**Interfaces:**
- Produces: `TableFloor::rows(): Collection` — exatamente a montagem que hoje está em `DiningTableController::index` (mesas ativas por número, com `order` ou `null`: `id, people, pending_count, waiter, total, minutes, closing`).
- `GET /api/v1/tables` (`can:tables.operate`) → `{data: [{id, number, area, seats, status, order|null}]}` com `status` ∈ `free | open | pending | closing`; prioridade: `pending` (pedido do QR esperando) > `closing` (conta pedida) > `open` > `free`.

- [ ] **Step 1: Testes que falham** em `TableTest`:
  - `test_lista_as_mesas_da_empresa_com_o_status` — mesa livre → `free`; mesa com comanda → `open`; com `bill_requested_at` → `closing`; com recebimento pendente → `pending`.
  - `test_nao_ve_mesas_de_outra_empresa` — mesas de outra empresa ausentes da lista.
  - `test_precisa_de_permissao_de_mesas` — token de usuário sem `tables.operate` → 403.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Extrair `TableFloor`, usar na web e na API; `TableResource` calcula `status`.
- [ ] **Step 4:** `php artisan test --compact tests/Feature/Api/V1/TableTest.php tests/Feature/TableTest.php` → PASS; pint.
- [ ] **Step 5:** Commit "API v1: salão de mesas".

### Task 4: App — login e sessão salva

**Files:**
- Create em `garcom-app/app/src/main/java/br/com/esis/garcom/`: `data/ApiService.kt`, `data/ApiClient.kt`, `data/AuthRepository.kt`, `data/TokenStore.kt`, `data/Models.kt`, `ui/login/LoginScreen.kt`, `ui/login/LoginViewModel.kt`, `ui/AppNavigation.kt`
- Modify: `MainActivity.kt`, `app/build.gradle.kts`, `AndroidManifest.xml` (permissão `INTERNET`)
- Test: `garcom-app/app/src/test/java/br/com/esis/garcom/ui/login/LoginViewModelTest.kt`

**Interfaces:**
- Consumes: endpoints da Task 2.
- Produces: `interface AuthRepository { suspend fun login(email: String, password: String): LoginResult; suspend fun me(): Waiter?; suspend fun logout() }`; `sealed interface LoginResult { Success(waiter: Waiter); InvalidCredentials(message: String); NotAWaiter(message: String); NoConnection }`; `data class Waiter(id: Long, name: String, companyName: String, logoUrl: String?)`; `TokenStore { suspend fun read(): String?; suspend fun save(token: String); suspend fun clear() }`.
- Token: criptografado com chave AES/GCM do Android Keystore e guardado em DataStore. `device_name` = `"${Build.MANUFACTURER} ${Build.MODEL}"`. OkHttp interceptor injeta `Authorization: Bearer`; resposta 401 limpa o token e volta para o login.

- [ ] **Step 1: Testes que falham** (`LoginViewModelTest`, repositório falso, `kotlinx-coroutines-test`):
  - `login_ok_vai_para_o_salao` — estado final `LoggedIn(waiter)`.
  - `credencial_errada_mostra_a_mensagem_do_servidor` — erro = mensagem recebida.
  - `sem_conexao_mostra_aviso_e_permite_tentar_de_novo` — erro = "Sem conexão com o servidor".
  - `campos_vazios_nao_chamam_o_servidor` — repositório não é chamado.
- [ ] **Step 2:** `gradlew.bat -p garcom-app testDebugUnitTest` → FAIL.
- [ ] **Step 3:** Implementar. Ao abrir o app: se `TokenStore.read()` existe, chama `me()`; sucesso → salão, 401 → login.
- [ ] **Step 4:** `gradlew.bat -p garcom-app testDebugUnitTest assembleDebug` → PASS.
- [ ] **Step 5:** Commit "App garçom: login com sessão salva".

### Task 5: App — salão e trocar garçom

**Files:**
- Create: `ui/floor/FloorScreen.kt`, `ui/floor/FloorViewModel.kt`, `data/TableRepository.kt`, `ui/floor/TableStatusStyle.kt`
- Modify: `ui/AppNavigation.kt`, `data/ApiService.kt`
- Test: `garcom-app/app/src/test/java/br/com/esis/garcom/ui/floor/FloorViewModelTest.kt`

**Interfaces:**
- Consumes: `GET /api/v1/tables` (Task 3), `AuthRepository.logout()` (Task 4).
- Produces: `enum class TableStatus { FREE, OPEN, PENDING, CLOSING }` mapeado do campo `status`; `data class FloorTable(id, number, area, status, waiter, total, minutes)`.
- Tela: topo com logo (Coil) + nome do restaurante + nome do garçom + botão "Trocar garçom"; grade de mesas (número grande, cor/ícone e texto por status: Livre / Ocupada / Pedido da mesa / Conta pedida). Atualiza a cada 10 s enquanto a tela está visível (parar em `ON_STOP`). Toque na mesa: por enquanto mostra "Em breve" (fase 2).

- [ ] **Step 1: Testes que falham** em `FloorViewModelTest`:
  - `status_desconhecido_vira_ocupada` — `"xyz"` → `OPEN` (servidor novo não derruba app antigo).
  - `erro_de_rede_mantem_a_ultima_lista_e_mostra_aviso` — 2ª carga falha → lista anterior preservada + aviso "Sem conexão com o servidor".
  - `trocar_garcom_limpa_a_sessao` — chama `logout()` e estado vira `LoggedOut`.
- [ ] **Step 2:** Rodar → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** `gradlew.bat -p garcom-app testDebugUnitTest assembleDebug` → PASS.
- [ ] **Step 5:** Commit "App garçom: salão de mesas e trocar garçom".

### Encerramento da fase

- [ ] Suíte PHP completa: `php artisan test --compact` → tudo verde.
- [ ] Novidade em `config/changelog.php` (id novo) só quando a API for para produção; deploy só com pedido explícito do dono.
- [ ] Teste manual (dono): instalar `app-debug.apk` num celular após o deploy da API, entrar com um usuário Garçom, ver o salão.
