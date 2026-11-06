# Fase 2 — integridade financeira

> Execução nativa com `superpowers:executing-plans`, autorizada expressamente no pedido anexado da Fase 2. Implementação limitada a AUD-005/006/007. Decisões financeiras respondidas pelo usuário: preservar e bloquear legado divergente para reconciliação; estornar uma baixa selecionada.

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

- [x] Registrar as decisões pendentes e o schema exato antes de migrations.
- [x] RED: R$ 80 → R$ 1 → saldo R$ 79 → R$ 30 → R$ 49 → R$ 49 → quitada; valores inválidos e excessivos não gravam nada.
- [x] GREEN: histórico, saldo, desconto autorizado e auditoria na mesma transação; caixa movimenta somente dinheiro efetivo.
- [x] Testar autorização, multiempresa, estornos, legado e relatórios/limite de crédito, incluindo baixa parcial em datas diferentes.
- [x] Adequar interface financeira e testes anteriores somente onde a nova regra muda o contrato.
- [ ] Rodar SQLite/MariaDB e registrar commit local.

## Tarefa 3 — contas vinculadas e reversão pela origem

Arquivos: `BillController`, Action de edição se necessária, `CancelBill`, `CancelSale`, `CancelStockEntry`, testes financeiros e de origem.

Interface: operação direta recusa `sale_id`/`stock_entry_id`; caminho interno explícito pela origem mantém transação e validação de saldo/baixas sob lock. Sem bypass enviado pela requisição.

- [x] RED: edição/cancelamento direto recusados; dinheiro/estoque/origem inalterados.
- [x] GREEN: bloquear diretamente e preservar cancelamento legítimo pela venda/entrada.
- [x] Testar origem com baixa parcial, concorrência baixa × cancelamento e necessidade de estorno antes da reversão.
- [ ] Rodar regressões SQLite/MariaDB e registrar commit local.

## Tarefa 4 — concorrência, revisão e entrega

Arquivos: harness financeiro em `tests/Concurrency`, documentação e evidências novas da Fase 2.

- [x] Processos independentes sincronizados disputam uma conta: soma dos pagamentos nunca excede saldo; conferir ledger, caixa e auditoria.
- [ ] Executar suíte SQLite/MariaDB, concorrência financeira e C1–C9; registrar AUD-020 separadamente.
- [ ] Pint, build sem sobreposição com testes HTTP, diff e revisão independente da branch.
- [ ] Relatório com arquivos, migrations/schema, testes, reconciliação, riscos e commits; deixar branch limpa e local.

## Progresso e decisões

- Preparação: Git limpo em `dfc91ed`; sete commits da Fase 1 presentes. `git fsck --full` não encontrou corrupção; três blobs sem referência são resíduos Git, sem perda de commits.
- Adaptação de isolamento: repetir a execução em branch própria no diretório atual e usar somente dados descartáveis, conforme o método da Fase 1. Nenhum checkout adicional necessário.
- Pré-verificação de interfaces: tarefa 2 determina saldo/histórico consumidos pela tarefa 3 e relatórios; tarefa 1 é independente e pode avançar enquanto as decisões financeiras aguardam resposta.
- Decisões expressas do usuário: preservar e bloquear legado divergente para reconciliação; estornar uma baixa selecionada.
- AUD-007 entregue no commit `9be1e37`; sem operação excepcional de devolução/perda e sem reposição presumida de estoque.
- Execução antecipada da parte independente da tarefa 3: bloquear edição/cancelamento direto e criar caminho interno com origem tipada e vínculo/empresa conferidos. RED: quatro falhas reais; regressões SQLite: 51 testes/286 asserções passaram. A proteção de contas com baixa parcial depende da tarefa 2 e permanece pendente.

## Desenho detalhado da tarefa 2

Decisão técnica: acrescentar `bill_settlements` com empresa, conta, valor efetivo, desconto separado, data, forma, responsável/nome, motivo do desconto, observação e movimento de caixa. Campos de estorno ficam na mesma baixa (data, responsável, motivo, movimento contrário), sem apagar histórico. Usar `Auditable` e `BelongsToCompany`, sem dependências novas. Cada operação trava a conta antes de conferir saldo e gravar efeitos.

`bills.amount` continua como principal. Acrescentar `discount_amount` agregado e `legacy_review_required`; `paid_amount` passa a representar soma das baixas ativas. Saldo = principal menos recebido menos descontos, com decimal/bcmath; conta só fica quitada ao zerar. Uma baixa de valor positivo pode conceder desconto explícito apenas para quitar o saldo integral; exige `finance.discount` e motivo. Acima do saldo é recusado; nenhum juro/multa implícito. Principal não pode ser editado após histórico de baixas; contas vinculadas já têm bloqueio completo direto.

Migration aditiva deve rodar **depois** da última migration existente (há timestamps posteriores à data atual no projeto). Importar somente quitações antigas coerentes e completas como uma baixa histórica, sem novo caixa nem auditoria inventada. Preservar exatamente valores/datas/autoria disponíveis; ator ID pode ser nulo e nome é snapshot. Quitadas divergentes ou com campos essenciais ausentes, e contas abertas com recebimento legado não explicado, recebem flag de revisão e ficam bloqueadas para operações financeiras/edição/cancelamento. Não inferir desconto/juros nem reabrir automaticamente dívidas históricas. Relatórios preservam fallback dos registros legados sem ledger. O rollback deve recusar remoção de histórico populado; documentar correção por migration futura e restauração de backup, sem executar nada em produção.

`SettleBill::handle(Bill, array): Bill`: valida valor/data/forma também na Action, relê sob lock, recusa revisão legada e saldo insuficiente, verifica permissão/motivo de desconto, cria movimento apenas do dinheiro efetivo e baixa histórica na mesma transação; atualiza os agregados da conta. `Bill::balance()` e expressão SQL centralizada alimentam limite de cliente, listas, resumo e devedores. Manter status `open` para parcial, sem acrescentar estado conflitante.

`ReopenBill::handle(Bill, string reason, ?int settlementId = null): void`: estorna somente uma baixa ativa da conta/empresa sob o mesmo lock, com lançamento de caixa contrário e auditoria. Omissão de ID aceita apenas quando há uma única baixa ativa (compatibilidade do endpoint existente); várias baixas exigem seleção. O desconto daquela baixa também é revertido explicitamente. Não presumir devolução automática por adquirente/Pix: operação registra o ajuste manual já existente. Se caixa usado, exigir caixa aberto e saldo para saída, preservando fechamentos anteriores.

Relatórios devem somar recebimentos na data de pagamento e negativos na data de estorno; não apagar recebimento de períodos anteriores. Criar consulta comum em `app/Reports/BillLedger.php` com união de baixas, estornos e fallback legado sem ledger, sempre com escopo de empresa. Desconto nunca compõe dinheiro. `CashController::flow`, resumo de contas e `FinanceReport` usam essa consulta; abertos/devedores usam saldo. Em vendas a prazo, desconto de quitação reduz resultado em campo separado, pois a receita original já foi reconhecida pela venda; não contar recebimento de fiado como nova receita. Demais despesas/receitas manuais continuam pelos valores efetivos, evitando dupla dedução.

Tela financeira: diálogo usa saldo, apresenta desconto somente com permissão e exige motivo; detalhe mostra principal/recebido/desconto/saldo, histórico e estorno por baixa. Não oferecer edição/cancelamento de vinculada nem operação de conta bloqueada para reconciliação. Seguir componentes Vue existentes e habilidades Inertia/Tailwind quando aplicáveis.

Origem com baixa ativa, mesmo parcial, exige estorno selecionado antes de cancelamento. `CancelBill::handleFromOrigin` precisa conferir essa condição sob lock para não perder corrida com uma baixa. `CancelSale` e `CancelStockEntry` mantêm suas reversões existentes, sem alterar estoque por presunção. Acrescentar concorrência baixa × origem e soma de pagamentos acima do saldo, com processos/barreira do harness existente.

## Evidências da implementação e revisão final

- Tarefa 2: RED de baixas parciais e descontos demonstrou nove falhas reais; GREEN inicial de 15 testes/90 asserções. A regra anterior de aceitar pagamento acima do principal foi substituída por recusa explícita, conforme o pedido; não houve redução de cobertura para obter resultado verde.
- Relatórios: cinco casos RED provaram falta de saldo/baixas/datas/estornos; GREEN inclui parcial, despesas, fiado com desconto separado e multiempresa. Uma falha intermediária de data em SQLite veio de fixtures antigas com timestamp em coluna de data: a consulta usa limites `whereDate`, sem retirar a asserção.
- Legado: importação das cinco formas não monetárias existentes e dinheiro coerente; repetição não duplica. RED adicional encontrou cinco baixas importadas indevidamente para um único movimento coerente; GREEN passou a conferir empresa, valor, direção e ausência de vínculo de caixa no não monetário. Não foram fabricados juros/descontos ou novos movimentos.
- Origem: duas regressões adicionais provam que R$ 1 recebido bloqueia cancelamento sem efeitos colaterais; estornar a baixa selecionada libera o cancelamento legítimo, volta estoque a 10 e mantém caixa em R$ 100.
- Estorno de caixa após fechamento: snapshot anterior permanece idêntico; saída de R$ 1 é registrada no caixa atual. Históricos preservados, inclusive a baixa estornada.
- F1–F5 passaram no MariaDB com processos independentes: limite concorrente de saldo, desconto único, estorno selecionado único, baixa versus venda e baixa versus entrada. F4/F5 alternam precedência e provaram ambos os desfechos em dez rodadas cada; zero estados inconsistentes e zero erros inesperados.
- C1–C8 passaram; C9 preserva integridade, reproduz 60 deadlocks AUD-020 e não é apresentado como corrigido.
- Revisão independente da branch pelo agente `phase2_final_review`: achado importante de observações salvas mas invisíveis no histórico. Final: fixed observações por baixa invisíveis — `test_each_receipt_keeps_its_own_notes_visible_in_history` RED (campo ausente) → GREEN (15 asserções); suíte final documentada no relatório.
- Final: Ruling: a revisão independente não executou testes nem migração em dados operacionais — resultados de testes são comprovados pelo executor em ambientes descartáveis; aplicação a dados reais permanece fora da autorização — custo se errado: incompatibilidades de dados operacionais exigirão ensaio em cópia sanitizada e reconciliação antes da implantação.
- Nenhum achado menor foi adiado. Encerramento por `superpowers:finishing-a-development-branch` mantém a branch local, opção já expressamente determinada pelo usuário; não apresentar menu de push/merge contrário à autorização.