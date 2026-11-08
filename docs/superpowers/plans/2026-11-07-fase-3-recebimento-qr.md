# Fase 3 — plano de implementação do recebimento QR

> Executar tarefa por tarefa com `superpowers:executing-plans`, conforme aprovação expressa do desenho. Ledger neste documento. Nenhuma nova confirmação é necessária para as tarefas autorizadas.

**Objetivo:** recebimentos pendentes, acompanhamento privado, idempotência durável e confirmação manual sem efeitos financeiros indevidos.
**Arquitetura:** Actions e snapshots separados dos itens cobrados; configurações centralizadas; locks de Company antes de mesa/comanda; polling existente.
**Tecnologias:** lockfiles atuais, Laravel 13/PHP 8.5, Vue/Inertia, MariaDB descartável e SQLite em memória. Sem novas dependências.
**Spec:** [desenho aprovado](../specs/2026-11-07-fase-3-recebimento-qr-design.md).

## Restrições e foco de revisão

- Branch `phase3/qr-receiving`; sem push, merge, deploy, `.env` real ou banco operacional. Preservar evidências anteriores.
- Automático indisponível sem produção funcional. Não implementar cozinha, impressão ou demais fases.
- Pendente/rejeitado não entra na cobrança; nenhum estado de produção é inferido.
- Riscos a testar: perda da resposta com produto/preço alterado; transferência e página antiga; decisões concorrentes; fracionamento de limites; sessão de funcionário de outra empresa no QR.
- Baseline antes de alterações: SQLite 474 aprovados + 2 skips MariaDB, 2.669 asserções. Base de preparação `f922bca`, entrega financeira `d622c79`.

## Tarefa 1 — configurações e infraestrutura de recebimentos

Arquivos: migrations aditivas; modelos/factories `RestaurantOperationSettings`, `MenuOrderReceipt`, `MenuOrderReceiptItem`; `RestaurantOperationPolicy`; config `restaurant.php`; permissão `tables.confirm`; testes `RestaurantOperationSettingsTest`.
Interface: `RestaurantOperationPolicy::resolve(Company): array`, preferência e modo efetivo distintos; snapshot imutável por recebimento. Settings por empresa, defaults e limites do desenho. Campos de bloqueio protegidos por endpoint exclusivo de super administrador, com motivo/auditoria.

- [x] RED: defaults manuais, limites validados, preferência automática indisponível, isolamento e bloqueio não alterável pela empresa.
- [x] GREEN: schema aditivo após última migration, snapshots monetários decimais, índices/uniqueness, modelos auditáveis sem chave de acompanhamento no log.
- [x] Testar atualização e bloqueio/desbloqueio com responsável/motivo, concessão de `tables.confirm` por capacidade existente.
- [x] Rodar testes SQLite/MariaDB e commit local.

## Tarefa 2 — contexto e disciplina de locks

Arquivos: `DiningTable`, `OpenTable`, `AddOrderItems`, `CloseTableOrder`, `CancelTableOrder`, `TransferTable`, `SendToKitchen`, `CancelOrderItem`; helper `TableOperationLock`; testes `TableContextTest`.
Interface: lock de Company primeiro; mesas ordenadas por ID quando necessário; então comanda. Contexto não muda na primeira abertura; rotações no fim de atendimento e transferência. Encerrar/cancelar com pendentes é recusado.

- [x] RED: contexto persistente na abertura e invalidado no encerramento; pendências bloqueiam fechamento/cancelamento; transferência mantém comanda/recebimentos.
- [x] GREEN: transações coerentes e rotação; preços/financeiro existentes preservados. Reconciliar retornos idempotentes do fechamento.
- [x] Regressões de mesas, caixa/vendas e testes de contexto; commit.

## Tarefa 3 — recebimento, privacidade e decisões

Arquivos: `ReceiveMenuOrder`, `ConfirmMenuOrder`, `RejectMenuOrder`; adaptação de `PlaceMenuOrder`; `MenuController`, `MenuOrderReceiptController`, `StoreMenuOrderRequest`; rotas e rate limit; testes `MenuReceiptTest` e atualização justificada de `MenuTest`.
Interfaces: receber com mesa/contexto/UUID/chave de 256 bits/linhas; somente hash da chave no banco; consultar com header `X-Menu-Receipt-Key`. Payload canônico e replay antes da validação comercial atual. Confirmar copia snapshots para itens existentes com referência única por linha. Rejeitar exige motivo. Decisões exigem `tables.confirm`.

- [x] RED: um recebimento pendente e subtotal zero; replay após fechamento/preço/inativação; conflito de conteúdo; acompanhamento sem chave negado; confirmação/rejeição e isolamento.
- [x] GREEN: transação com limites acumulados, snapshot/preço do servidor, retorno idempotente e decisões auditadas sem segredos.
- [x] Atualizar contratos antigos que cobravam imediatamente, preservando isolamento e validação. Testar nenhum movimento de caixa/estoque até fechamento.
- [x] Limites por mesa+IP/mesa/empresa e acompanhamento separado; 429 preserva identificadores no cliente. Testes e commit.

## Tarefa 4 — configurações, acompanhamento e alertas Vue

Arquivos: `company/Edit.vue`, `admin/companies/Form.vue`, `menu/Show.vue`, `tables/Index.vue`, `tables/Order.vue`; componentes/composables focados; presenters de mesas/comanda.
Interface: snapshot de recebimento próprio, rascunho com payload/chave estáveis, consulta privada sem segredo na URL. Operação mostra pendências e atraso; som após gesto e uma vez por recebimento/aba; aviso após 30 s sem resposta.

- [x] Testes de props e permissões antes dos presenters; sem N+1.
- [x] UI manual completa, editar configuração dentro de tetos, habilitar som explicitamente, mensagens honestas de rede/produção.
- [x] Preservar payload em resultado ambíguo; corrigir validação só cria novo envio quando confirmado que o anterior não persistiu. Atualizações antigas não sobrescrevem estado recente.
- [x] Build separado de testes HTTP, regressões e commit.

## Tarefa 5 — concorrência, revisão e relatório

Arquivos: harness `tests/Concurrency/receipts.php`, orquestrador/C3, novos testes de concorrência, evidências/relatório da Fase 3.

- [ ] Barreira/processos: mesma chave, duas chaves na mesa livre, limite acumulado, confirmar × rejeitar, receber × encerrar/transferir. Conferir dinheiro/estoque/auditoria e ausência de órfãos.
- [ ] C1–C9 e F1–F5; C3 agora exige recebimentos pendentes e zero itens cobrados. AUD-020 só muda de status com evidência específica.
- [ ] Suítes completas SQLite/MariaDB, Pint, build e auditorias sem atualizar dependências. Revisão independente única da branch e correções importantes RED/GREEN.
- [ ] Relatório com arquivos/schema, commits, resultados reais, riscos e limitações. Branch limpa e local. Fase 4 aguardando autorização.

## Ledger

- Preparação: desenho `f922bca` aprovado pelo usuário; branch limpa. Isolamento mantém o método autorizado das fases anteriores: branch própria no diretório compartilhado, dados somente descartáveis. Nenhum worktree/dependência adicional necessário.
- Pré-verificação: tarefa 2 antecipa somente os locks/bloqueio mínimo necessários à validade do contexto. Produção e bloqueio completo de estados continuam nas fases futuras, conforme desenho aprovado.

- Tarefas 1–4 implementadas e verificadas: RED inicial configurações (3 falhas), contexto (2 falhas), recebimentos (7 falhas), presenter (1 falha); GREEN com contratos novos. Regressões do menu/validação: 111/111. Rodada completa: SQLite 498 aprovados + 2 skips de trigger; MariaDB 500/500, 2.911 asserções. Build aprovado.
- Ruling: commit conjunto das tarefas 1–4 — schema, relações, locks, contrato HTTP e consumidores Vue precisam permanecer compatíveis na mesma revisão — custo: revisão inicial maior, mitigada por testes focados e revisão independente única.
- Tarefa 5: C1–C8, F1–F5 e R1–R5 passaram. C9: 30 rodadas/120 workers, zero deadlocks/timeout/estado inválido; a verificação passa a exigir zero deadlocks no CI. O resultado descreve esse cenário reproduzível, sem promessa de ausência universal de deadlock.
- Defeito encontrado e reproduzido: contexto de autoria anônima sobrevivia ao recebimento em processo reutilizado e atribuía a confirmação ao visitante. Corrigido com Context::scope; teste RED acusou user_id nulo, GREEN exige funcionário correto, mantendo criação anônima.
- Limitação de validação: sem Dusk/browser instalado, não foram instalados pacotes nem ferramentas novas; Vue compilado e contratos HTTP exercitados, validação real de áudio/celulares ainda pendente. Nenhuma equivalência com produção ou execução remota do CI é afirmada.

- Revisão independente única da branch `01511dd`: dois achados importantes no cliente, sem achado crítico backend. Resposta antiga do acompanhamento apagava rascunho/regredia estado; abas substituíam o journal compartilhado e podiam perder UUID/chave de envio ambíguo. Ambos reproduzidos com o script real do componente e Vue instalado, sem navegador/pacotes novos.
- Correção única da revisão (08/11): geração de resposta e vínculo do carrinho ao próprio UUID; registro local independente por UUID; sincronização antes de novo envio e evento `storage`; recuperação explícita de envio descoberto em outra aba; estado confirmado/rejeitado não regride para pendente. Testes Node RED (duas falhas demonstradas) → GREEN 3/3 e build aprovado; incluídos no CI.
- Ruling: adicionar testes Node nativos em `tests/Frontend` — o comportamento de respostas fora de ordem e múltiplas abas não é provado pelo PHPUnit/build — nenhum pacote ou lockfile novo; limites: simulação de transporte/storage, não execução em celular/navegador real.
