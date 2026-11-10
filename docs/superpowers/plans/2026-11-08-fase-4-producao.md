# Fase 4 — produção por item e setor

> Execução autorizada pelo usuário; usar superpowers:executing-plans, diretamente nesta sessão.

**Objetivo:** encaminhar e acompanhar cada linha de mesa/QR e delivery por setor, sem duplicar o núcleo financeiro.
**Arquitetura:** Actions transacionais, Company primeiro, origem e trabalho; painel Inertia com polling e eventos persistidos.
**Tecnologias:** dependências instaladas dos lockfiles; Laravel 13.40, Inertia 3, Vue 3, MariaDB descartável e SQLite.
**Especificação:** `docs/superpowers/specs/2026-11-08-fase-4-producao-design.md`, aprovada.

## Restrições globais

Somente branch local `phase4/production-flow`. Sem remoto, push, merge, deploy ou banco operacional. Sem alterar evidências anteriores, dependências ou regras financeiras. Quantidade da linha é indivisível. Não inferir entrega do legado por impressão. Automático exige ativação explícita e destino válido. Permissões no catálogo existente. Cada tarefa observa RED/GREEN e commit local.

## Foco de revisão

- Reenvio após esgotar produto: retorna o envio persistido, sem duplicar trabalho (tarefa 2/6).
- Troca de setor com trabalho ativo: snapshot permanece, inativação recusada (tarefa 1/2).
- Cancelamento do pai após preparo: exige gestão e emite avisos persistentes (tarefa 3).
- Delivery legado já entregue sem venda: exige reconciliação antes do acerto (tarefa 3).
- Preferência automática antiga: não confirma sem novo aceite explícito (tarefa 5).

### Task 1: schema, setores e disponibilidade

**Arquivos:** migrations aditivas; `app/Models/ProductionSector.php`, `ProductionWork.php`, `ProductionEvent.php`; factories; `ProductionSectorController`, request de setor; Category/Product; `config/permissions.php`; `tests/Feature/ProductionSetupTest.php`.
**Interfaces:** setor por empresa; Company.default_production_sector_id; Category.production_sector_id; Product.sold_out; work referencia exatamente um item de origem, snapshots, status, version, delivered_at/cancelled_at, legacy/reconciled_at; evento work_id/from/to/actor/reason/ack. Produz `ProductionDestination::resolve(Product): ProductionSector`.
- [x] Criar testes de destino ausente, setor de outra empresa, atividade e disponibilidade.
- [x] Rodar testes: falham pela ausência do schema/rotas.
- [x] Criar schema, modelos/factories e gestão transacional de setores; sold_out separado de active.
- [x] Testes passam SQLite e MariaDB; commit `feat: add production sectors and operational schema`.

### Task 2: encaminhamento transacional

**Arquivos:** `DispatchProduction`, `ProductionDestination`; AddOrderItems, ConfirmMenuOrder, CreateDeliveryOrder, ReceiveMenuOrder; testes ProductionDispatchTest.
**Interfaces:** `DispatchProduction::handle(TableOrderItem|DeliveryOrderItem): ProductionWork`; unicidade por origem, destino e empresa validados; consumidor recebe snapshot sem preço.
- [x] Testar confirmação QR, lançamento e delivery: um trabalho por linha, sem venda/estoque; destino inválido desfaz tudo; esgotado bloqueia novos pedidos, preserva replay.
- [x] Rodar RED; implementar na transação existente; adaptar fixtures explicitamente com setor.
- [x] Rodar GREEN nos módulos afetados e MariaDB; commit `feat: dispatch restaurant items atomically`.

### Task 3: estados, cancelamento, legado e guardas

**Arquivos:** `TransitionProduction`, `ReconcileProduction`, `ProductionGuard`, `ProductionLock`; cancelamentos de origem; Advance/SettleDeliveryOrder; CloseTableOrder; SendToKitchen; testes ProductionFlowTest e ProductionLegacyTest.
**Interfaces:** transition(work, expectedVersion, target): work; waiting→preparing→ready; entrega somente ready; cancelamento pela origem; evento de cancelamento exige ciência explícita. Reconcile(work, observed, reason) somente legacy. Guard de fechamento exige todos entregues. Company primeiro em todas as escritas.
- [x] Testar bebida pronta sem avançar comida, stale version, salto, entrega, cancelamento sem gestor, cancelamento do pai, aviso até ciência e zero efeitos financeiros.
- [x] Testar fechamento bloqueado, legado restrito, reconciliação auditada e delivery derivado das linhas; endpoint antigo não prepara tudo.
- [x] Rodar RED, implementar Actions/guardas sob locks, GREEN e commit `feat: enforce production transitions and delivery guards`.

### Task 4: painel e interfaces existentes

**Arquivos:** ProductionController, requests/rotas; `resources/js/pages/production/Index.vue`; setores; categorias/produtos; navegação; mesas/delivery; MenuController/Show.vue; testes ProductionEndpointsTest e frontend.
**Interfaces:** painel recebe apenas snapshots operacionais/setores/avisos/legado, sem preço/endereço/telefone; ações com versão; polling 10s, conexão/áudio opt-in, limpeza; QR somente produção das próprias linhas.
- [x] Testar autorização, isolamento, props privadas e endpoints inválidos RED.
- [x] Criar painel responsivo, gestão de setores/destino e esgotado; equipe entrega itens prontos e reconcilia legado; link de preparo por item no delivery.
- [x] Testes HTTP/clientes GREEN, build separado; commit `feat: add production panel and private progress tracking`.

### Task 5: ativação explícita de automático

**Arquivos:** RestaurantOperationSettings/Policy, settings request/action/form, ReceiveMenuOrder/ConfirmMenuOrder; testes ProductionAutomaticTest.
**Interfaces:** ativação com company.manage, aceite de risco remoto, ator/data e setor padrão ativo; preferência anterior sem aceite fica manual; bloqueio administrativo prevalece; confirmação automática interna sem impersonar funcionário.
- [x] Testar manual padrão, preferência antiga, aceite obrigatório, empresa alheia, bloqueio, snapshot e rollback de destino inválido RED.
- [x] Implementar ativação e confirmar/encaminhar novo envio atomicamente; pendentes anteriores intactos.
- [x] GREEN SQLite/MariaDB; commit `feat: activate automatic confirmation with explicit consent`.

### Task 6: concorrência e regressão completa

**Arquivos:** testes existentes com fixtures aderentes ao novo contrato; `tests/Concurrency/production.php`, run/worker; frontend testes.
**Interfaces:** cenários existentes C1–C9/F1–F5/R1–R5 preservam invariantes; P1–P5 cobrem dispatch, transição repetida, entregar/cancelar/fechar, confirmação/esgotado e reconciliação.
- [x] Executar suítes completas SQLite e MariaDB; corrigir somente falhas justificadas por contratos novos e defeitos reais.
- [x] Executar concorrência em processos independentes: sem origem órfã, duplicação, corrupção financeira ou estoque antecipado; C9 sem deadlock.
- [x] Executar quatro testes de recuperação e novos testes cliente, Pint, build, Composer validate/platform/audit e npm audit produção. Resultado esperado: sem falha não explicada.
- [x] Commit `test: verify production concurrency and regression`.

### Task 7: revisão independente e evidências

**Arquivos:** relatório de execução Fase 4, evidências novas e hashes; VALIDACAO.md; este plano/ledger.
- [x] Um revisor novo verifica branch inteira contra especificação. Corrigir achados importantes com RED/GREEN e registrar limitações.
- [x] Registrar arquivos/commits, versões, resultados reais e restrições: CI remoto e ambiente real não executados.
- [x] Conferir auditoria/evidências anteriores intactas, nenhum .env ou segredo; branch local revisável.
- [x] Commit final de relatório. Não iniciar Fase 5.
