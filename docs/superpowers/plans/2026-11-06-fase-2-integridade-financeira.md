# Fase 2 — integridade financeira

> Execução nativa com `superpowers:executing-plans`, autorizada expressamente no pedido anexado da Fase 2. Implementação limitada a AUD-005/006/007. Decisões financeiras ainda não definidas aguardam resposta antes de schema ou comportamento dependente.

**Objetivo:** registrar baixas efetivas e saldo remanescente, impedir alteração direta de contas vinculadas e cancelamento comum de delivery entregue.

**Arquitetura:** manter Actions, transações, locks, decimal/bcmath, escopo de empresa e auditoria. Histórico próprio de baixas/ajustes, sem novo núcleo de vendas, caixa ou estoque.

**Tecnologias:** dependências existentes nos lockfiles; sem atualização de pacotes. Testes somente em SQLite em memória e MariaDB descartável pelo harness da Fase 1.

**Especificação:** pedido anexado “DELIVERY SAAS — FASE 2: INTEGRIDADE FINANCEIRA”; AUD-005/006/007 do relatório original; decisões aprovadas do plano geral. Base `dfc91ed`, branch `phase2/financial-integrity`.

## Restrições globais

- Não acessar banco operacional, criar `.env` real, fazer push, merge ou deploy.
- Não alterar QR, cozinha, impressão ou configurações operacionais.
- Não implementar juros, multa ou devolução presumida de mercadorias.
- Desconto exige permissão própria, motivo e valor explícito; pagamento positivo limitado ao saldo sob lock.
- AUD-020 permanece conhecido e aberto. Evidências originais da auditoria e da Fase 1 permanecem intactas.

## Foco da revisão

Recebimentos simultâneos acima do saldo; cancelamento da origem concorrente com baixa; estorno de várias formas de pagamento; migração de quitações antigas divergentes sem inventar ajustes; leitura de histórico e relatórios de outra empresa. Cada tarefa abaixo deve cobrir seu risco com asserções de dinheiro, estoque e auditoria.

## Tarefa 1 — delivery entregue

Arquivos: `app/Actions/CancelDeliveryOrder.php`, `tests/Feature/DeliveryCancellationSafetyTest.php`; regressões existentes em `DeliveryTest.php`.

- [x] RED: duas falhas reproduziram cancelamento indevido por HTTP e por objeto obsoleto; isolamento já protegido.
- [x] GREEN: validar `DELIVERED` no objeto relido sob lock, preservando recusa para pedidos acertados.
- [x] Testar cancelamento antes de entregar (regressões existentes), estado persistido diferente do objeto recebido e isolamento entre empresas.
- [x] SQLite: 31 testes/188 asserções; MariaDB descartável com filtro Delivery: 37 testes/212 asserções, ambos passaram.

## Tarefa 2 — histórico de baixas e saldo

Arquivos previstos: migration aditiva, modelo de baixa e factory; `Bill`, `Customer`, `SettleBill`, `ReopenBill`, permissão financeira, `BillController`, formulário de baixa/detalhe e relatórios `FinanceReport`/fluxo de caixa.

Interfaces a fechar antes de alterar schema: representação do legado divergente e granularidade do estorno, perguntadas ao usuário. O principal original não será reescrito; valor recebido e desconto serão distintos. Leituras de saldo devem usar a mesma expressão decimal.

- [ ] Registrar as decisões pendentes e o schema exato antes de migrations.
- [ ] RED: R$ 80 → R$ 1 → saldo R$ 79 → R$ 30 → R$ 49 → R$ 49 → quitada; valores inválidos e excessivos não gravam nada.
- [ ] GREEN: histórico, saldo, desconto autorizado e auditoria na mesma transação; caixa movimenta somente dinheiro efetivo.
- [ ] Testar autorização, multiempresa, estornos, legado e relatórios/limite de crédito, incluindo baixa parcial em datas diferentes.
- [ ] Adequar interface financeira e testes anteriores somente onde a nova regra muda o contrato.
- [ ] Rodar SQLite/MariaDB e registrar commit local.

## Tarefa 3 — contas vinculadas e reversão pela origem

Arquivos: `BillController`, Action de edição se necessária, `CancelBill`, `CancelSale`, `CancelStockEntry`, testes financeiros e de origem.

Interface: operação direta recusa `sale_id`/`stock_entry_id`; caminho interno explícito pela origem mantém transação e validação de saldo/baixas sob lock. Sem bypass enviado pela requisição.

- [ ] RED: edição/cancelamento direto recusados; dinheiro/estoque/origem inalterados.
- [ ] GREEN: bloquear diretamente e preservar cancelamento legítimo pela venda/entrada.
- [ ] Testar origem com baixa parcial, concorrência baixa × cancelamento e necessidade de estorno antes da reversão.
- [ ] Rodar regressões SQLite/MariaDB e registrar commit local.

## Tarefa 4 — concorrência, revisão e entrega

Arquivos: harness financeiro em `tests/Concurrency`, documentação e evidências novas da Fase 2.

- [ ] Processos independentes sincronizados disputam uma conta: soma dos pagamentos nunca excede saldo; conferir ledger, caixa e auditoria.
- [ ] Executar suíte SQLite/MariaDB, concorrência financeira e C1–C9; registrar AUD-020 separadamente.
- [ ] Pint, build sem sobreposição com testes HTTP, diff e revisão independente da branch.
- [ ] Relatório com arquivos, migrations/schema, testes, reconciliação, riscos e commits; deixar branch limpa e local.

## Progresso e decisões

- Preparação: Git limpo em `dfc91ed`; sete commits da Fase 1 presentes. `git fsck --full` não encontrou corrupção; três blobs sem referência são resíduos Git, sem perda de commits.
- Adaptação de isolamento: repetir a execução em branch própria no diretório atual e usar somente dados descartáveis, conforme o método da Fase 1. Nenhum checkout adicional necessário.
- Pré-verificação de interfaces: tarefa 2 determina saldo/histórico consumidos pela tarefa 3 e relatórios; tarefa 1 é independente e pode avançar enquanto as decisões financeiras aguardam resposta.
- Pendente: legado quitado com diferença e estorno selecionado versus integral. Não implementar migração ou estorno dependente antes da resposta.
