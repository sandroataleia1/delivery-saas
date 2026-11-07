# Execução da Fase 2 — Integridade financeira

**Fechamento:** 07/11/2026. **Parecer:** Fase 2 concluída localmente no escopo autorizado: AUD-005, AUD-006 e AUD-007. Não há falha inexplicada nos testes finais. AUD-020 permanece reproduzido e aberto. A Fase 3 não foi iniciada.

**Base:** `dfc91ed`, conclusão da Fase 1. **Branch:** `phase2/financial-integrity`, preservada localmente. Nenhum remoto configurado, push, merge ou deploy realizado. Nenhum banco operacional ou `.env` real foi utilizado ou alterado. Lockfiles e evidências anteriores permanecem intactos.

## 1. Resumo técnico

**AUD-005:** cada baixa registra o valor efetivo em `bill_settlements`. Principal, recebimentos, descontos e saldo permanecem distintos. R$ 1 em uma dívida de R$ 80 deixa R$ 79 em aberto; baixas de R$ 30 e R$ 49 completam a quitação. A Action relê e trava a conta antes de conferir saldo e gravar baixa, caixa, agregados e auditoria na mesma transação. Valores nulos, negativos, malformados, com precisão indevida ou superiores ao saldo são recusados sem efeitos colaterais.

Desconto exige `finance.discount`, valor explícito e motivo. Recebimento mais desconto deve quitar exatamente o saldo. Não há juros ou multas implícitos. A permissão integra o catálogo existente; perfis personalizados precisam recebê-la expressamente. O histórico conserva autor, data, forma, observações e eventual estorno. O estorno seleciona uma baixa, preserva as outras e registra a reversão do desconto correspondente. O endpoint aceita omissão da seleção somente quando existe uma única baixa ativa, por compatibilidade; a interface sempre envia a seleção.

**AUD-006:** edição e cancelamento direto de contas de venda ou entrada são recusados. O caminho interno `CancelBill::handleFromOrigin` recebe a origem tipada e verifica vínculo e empresa sob lock. Cancelamento legítimo pela origem continua funcionando. Qualquer baixa ativa, mesmo parcial, exige estorno antes do cancelamento; esse bloqueio acontece antes de devolver estoque. O principal de uma conta com histórico não pode ser editado diretamente, mesmo após todos os estornos.

**AUD-007:** cancelamento comum de delivery entregue é recusado com base no estado relido dentro da transação, inclusive quando o objeto recebido pela Action está obsoleto. Cancelamento anterior à entrega continua coberto pelas regressões existentes. Não foi criada operação excepcional de devolução/perda nem reposição presumida de mercadoria.

## 2. Arquivos criados e modificados

Inventário completo em [ARQUIVOS-FASE-2.txt](ARQUIVOS-FASE-2.txt), relativo à base da Fase 1, com indicação de criação ou modificação. Os grupos são:

| Área | Arquivos e finalidade |
|---|---|
| Domínio financeiro | `SettleBill`, `ReopenBill`, `UpdateBillSettlementTotals`, `Bill`, `BillSettlement`, `Customer`: histórico, agregados, saldo e crédito |
| Proteção de origem | `CancelBill`, `CancelSale`, `CancelStockEntry`, `BillController`: bloqueio direto e reversão legítima |
| Delivery | `CancelDeliveryOrder`: proibição transacional de cancelar entregue |
| Dados | Migration aditiva e `BillSettlementFactory` |
| Relatórios | `BillLedger`, `FinanceReport`, `Exports`, `CashController`: baixas parciais, estornos e desconto separado |
| Permissão/auditoria | `config/permissions.php`, `lang/pt_BR/audit.php` |
| Interface | `SettleDialog.vue`, páginas financeiras `Index.vue`/`Show.vue` e relatório `Finance.vue` |
| Testes | `BillSettlementTest`, `BillSettlementReportTest`, `LegacyBillSettlementTest`, `LinkedBillSafetyTest`, `DeliveryCancellationSafetyTest`, atualização justificada de `BillTest`, cenários financeiros e orquestrador de concorrência |
| Documentação | Plano da Fase 2, complemento de `VALIDACAO.md`, este relatório, inventário e evidências novas |

Não foram alterados QR, cozinha, impressão, configurações operacionais ou módulos comerciais.

## 3. Migration criada

`database/migrations/2026_11_07_100004_create_bill_settlements_table.php` acrescenta os campos e o histórico descritos abaixo. Seu timestamp vem depois da última migration existente, inclusive das migrations com datas posteriores à data de início do trabalho.

As migrations completas rodaram do zero nos bancos descartáveis das suítes. A rotina de importação também foi exercitada sobre fixtures legadas coerentes e divergentes nos dois bancos. Isso não equivale a ensaio com dados operacionais; nenhum banco real foi acessado.

Para implantação futura, interromper escritas financeiras durante a mudança, validar backup/restauração e ensaiar a migração em cópia sanitizada do destino. Não executar rollback destrutivo de histórico: `down()` recusa remoção quando há baixas registradas. Após uso, preferir correção aditiva; uma restauração exige procedimento operacional próprio e não foi executada nesta fase.

## 4. Estrutura de dados e legado

| Estrutura | Alteração |
|---|---|
| `bills.amount` | Principal preservado |
| `bills.paid_amount` | Soma dos valores efetivos das baixas ativas |
| `bills.discount_amount` | Descontos ativos, decimal separado |
| `bills.legacy_review_required` | Bloqueio de registros antigos que exigem reconciliação |
| `bill_settlements` | Empresa, conta, valor decimal, desconto/motivo, data, forma, autor/nome, observações e movimento de caixa |
| Estorno da baixa | Data, autor/nome, motivo e movimento contrário; registro original preservado |
| Índices/vínculos | Empresa/data, empresa/data de estorno, conta/data de estorno; FKs de empresa, conta e movimentos com restrição de exclusão |

O saldo é calculado por bcmath no domínio e por expressão decimal comum nas agregações. Conta parcial permanece `open`; só fica `paid` ao zerar. Relatórios consultam os eventos do histórico, e não apenas o estado final da conta.

Conforme decisão expressa do usuário, quitações antigas divergentes não são convertidas em desconto ou juros: valores e status históricos são preservados e alterações ficam bloqueadas. Campos essenciais incompletos e contas abertas com recebimento antigo não explicado também recebem bloqueio. Não foi implementado um procedimento automático de reconciliação.

Quitações coerentes e completas são importadas sem novo movimento de caixa e sem autoria inventada. No dinheiro, a importação confere existência, empresa, valor e direção do movimento; formas não monetárias não podem carregar um vínculo de caixa incoerente. Repetir a importação não duplica registros. Quando só existe nome histórico do responsável, o ID permanece nulo. Relatórios preservam fallback para contas legadas quitadas sem histórico importável, sem duplicar contas já importadas. A flag de revisão continua visível e bloqueia novas operações.

## 5. Testes e verificações

Evidências normalizadas em UTF-8, sem senha ou marcador da instância: [evidencias-fase-2/](evidencias-fase-2/). As saídas finais correspondem ao código do commit `cdf1927`; os commits posteriores de fechamento contêm somente documentação/evidências.

| Verificação | Resultado final | Evidência |
|---|---|---|
| SQLite, `php artisan test --compact` | 476 casos: **474 aprovados**, 2 ignorados; 2.669 asserções; 33,706 s | [sqlite.json](evidencias-fase-2/sqlite.json) |
| MariaDB, harness `-Mode Suite` | **476/476 aprovados**, 2.673 asserções; 33,767 s | [mariadb.json](evidencias-fase-2/mariadb.json) |
| Concorrência, harness `-Mode All` | F1–F5 e C1–C8 aprovados; C9 íntegro com falha operacional conhecida | [concurrency.jsonl](evidencias-fase-2/concurrency.jsonl) |
| Pint completo, `--test --format=agent` | Aprovado | [pint.json](evidencias-fase-2/pint.json) |
| Frontend, `npm run build` | Aprovado; Vite em 1,65 s | [build.txt](evidencias-fase-2/build.txt) |
| `composer validate --strict` | Aprovado, saída 0 | [composer-validate.txt](evidencias-fase-2/composer-validate.txt) |
| `composer check-platform-reqs` | Todos os requisitos atendidos, incluindo bcmath | [composer-platform.txt](evidencias-fase-2/composer-platform.txt) |
| `composer audit --locked` | Nenhum advisory ou pacote abandonado reportado | [composer-audit.json](evidencias-fase-2/composer-audit.json) |
| `npm audit --omit=dev` | Zero vulnerabilidades conhecidas de produção | [npm-audit.json](evidencias-fase-2/npm-audit.json) |
| Diff e preservação | `git diff --check` aprovado; lockfiles e evidências anteriores sem diff | Inventário e commits locais |

Os dois casos ignorados em SQLite verificam triggers de imutabilidade de auditoria, executados efetivamente no MariaDB. Não há skip no MariaDB. Versão local provisória: MariaDB 10.4.32, InnoDB, isolamento REPEATABLE-READ; PHP 8.5.12. A versão de produção segue indefinida. Nenhuma atualização de Composer/npm foi feita nesta fase.

Os testes novos incluem valores inválidos e excessivos sem efeito financeiro/estoque, autorização de desconto, três baixas sucessivas, estorno selecionado, múltiplas datas, fechamento de caixa preservado, migração coerente/divergente, notas independentes, isolamento de contas/baixas/relatórios, cancelamento de origem antes e após estorno e delivery entregue ainda não acertado.

A alteração no teste antigo que aceitava R$ 103,50 para uma dívida de R$ 100 é intencional: a nova regra exige recusa de pagamento superior ao saldo, sem juros implícitos. Asserções passaram a exigir conta aberta e ausência de baixa; não foram enfraquecidas.

Problemas intermediários resolvidos: métodos de pagamento legados foram alinhados aos seis identificadores reais do projeto; limite de data do relatório foi corrigido para fixtures SQLite que armazenavam timestamp; importação de dinheiro passou a conferir coerência do movimento. A revisão independente encontrou observações gravadas mas ausentes na interface: um teste falhou por campo inexistente, a apresentação foi corrigida e o teste passou com 15 asserções. Suítes completas foram executadas novamente após essa correção.

O build emitiu aviso de otimização opcional de fallback de fontes por ausência de `fontaine`, mas terminou com código zero. Nenhuma dependência opcional foi instalada. As evidências preservam esse aviso e a representação de stderr pelo PowerShell; não é uma falha de compilação.

## 6. Concorrência

Os workers são processos independentes liberados por barreira. O harness valida o destino antes de qualquer operação destrutiva, inicia uma instância própria e a encerra ao final. O MariaDB operacional do XAMPP não foi utilizado.

| Cenário | Resultado comprovado |
|---|---|
| F1 — oito pagamentos de R$ 30 em saldo R$ 80 | Duas baixas aceitas, R$ 60 recebidos, saldo R$ 20, dois movimentos e dois registros de auditoria; excessos recusados |
| F2 — seis quitações de R$ 70 + desconto R$ 10 | Uma baixa, recebido R$ 70, desconto R$ 10, saldo zero |
| F3 — seis estornos da mesma baixa | Um estorno, uma saída de R$ 70, desconto revertido, saldo R$ 80 e histórico preservado |
| F4 — baixa R$ 1 × cancelamento de venda | Dez rodadas; cinco com cancelamento vencedor e cinco com baixa vencedora; saldo, caixa, estoque e movimentos coerentes |
| F5 — baixa R$ 1 × cancelamento de entrada | Dez rodadas; cinco com cancelamento vencedor e cinco com baixa vencedora; saldo, caixa, estoque e movimentos coerentes |
| C1–C8 — regressões da Fase 1 | Integridade aprovada nas oito verificações |
| C9 — fechar × transferir comanda | 60 deadlocks 1213 em 30 rodadas forçadas; zero estados inválidos e zero erros inesperados; **AUD-020 continua aberto** |

F4/F5 alternam uma espera controlada após a barreira para exercer os dois desfechos. A operação perdedora recebe validação, sem parcial persistido. Os resultados são locais e sintéticos; não são teste de carga nem promessa de capacidade. Saída zero do harness prova as invariantes, sem classificar o deadlock conhecido como corrigido.

## 7. Reconciliação financeira

| Caso | Relação verificada |
|---|---|
| Dívida R$ 80 | R$ 1 + R$ 30 + R$ 49 = recebido R$ 80; saldos R$ 79, R$ 49, R$ 0 |
| Estorno apenas de R$ 30 | Recebido ativo R$ 50; saldo R$ 30; duas outras baixas preservadas |
| Acordo com desconto | Principal R$ 80 = recebido R$ 70 + desconto R$ 10; dinheiro nunca inclui o desconto |
| Fiado com custo R$ 10 | Venda R$ 80 − custo R$ 10 − desconto de quitação R$ 10 = resultado R$ 60; recebimento de fiado não duplica receita |
| Datas diferentes | Recebimento original continua em sua data; estorno aparece negativo no dia em que ocorreu |
| Caixa anterior fechado | Snapshot original inalterado; estorno de R$ 1 reduz somente caixa atual de R$ 10 para R$ 9 |
| Origem parcialmente baixada | Cancelamento recusado sem alterar dados; após estorno, estoque volta de 9 ou 11 para 10 e caixa retorna a R$ 100 |
| Outra empresa | Relatórios não somam baixas alheias; conta alheia responde 404; seleção de baixa de outra conta é recusada |

Essas verificações comprovam os cenários testados. Não constituem validação contábil de operação real.

## 8. Riscos e limites remanescentes

- AUD-020 e os demais problemas fora de AUD-005/006/007 continuam abertos. Esta fase não torna o produto apto para produção.
- Legado marcado para revisão exige reconciliação explícita futura. Nenhum procedimento de desbloqueio ou ajuste histórico foi inventado.
- Uma migração sobre dados operacionais ainda exige ensaio em cópia sanitizada, janela sem escritas e backup/restauração validado. O rollback bloqueado protege histórico; planejar correção aditiva ou restauração antes de implantar.
- Estorno de Pix/cartão registra ajuste manual; não devolve dinheiro por gateway e não resolve AUD-028. A devolução externa precisa ser conferida pela operação.
- Delivery entregue que precise de correção exige regras próprias de perda, retorno físico e ajuste financeiro. Cancelamento comum permanece proibido.
- CI remoto/Linux, banco/PHP de produção e equivalência de ambiente continuam não verificados. Nenhum envio remoto foi autorizado ou realizado.
- Frontend foi compilado e os contratos Inertia foram testados; não houve nova validação visual em navegador ou operação com usuários reais nesta fase.
- As auditorias de dependências cobrem avisos conhecidos e npm de produção. O isolamento depende dos mecanismos atuais da aplicação; AUD-024 não foi alterado. As limitações monetárias gerais do relatório AUD-035 permanecem fora desta correção.

**Decisão da revisão final:** o revisor independente examinou o código, sem executar testes ou migração em dados operacionais. Os testes foram executados pelo implementador em bancos descartáveis. Mantém-se a entrega local; o custo de eventual incompatibilidade de dados reais é a necessidade de reconciliação e correção antes da implantação. Não há achado menor adiado.

Uma tentativa de salvar evidências foi interrompida pelo revisor automático de permissões por limite de uso; a ação não foi executada e a mensagem não declarou a operação insegura. Após o usuário solicitar continuidade, a mesma gravação autorizada foi concluída pelo mecanismo normal, sem contornar aprovações. O bloqueio não permanece.

## 9. Commits locais

| Commit | Alteração |
|---|---|
| `9be1e37` | Recusa transacional de cancelamento comum de delivery entregue; testes de regressão e plano |
| `bbaf021` | Bloqueio direto de contas vinculadas e cancelamento interno pela origem, com testes |
| `cdf1927` | Histórico de baixas parciais, descontos, estorno selecionado, migração/legado, saldo, relatórios, interface e concorrência financeira |

O commit de fechamento contém este relatório, o inventário, evidências e atualização documental do plano/validação. Seu identificador pode ser consultado com `git log --oneline dfc91ed..phase2/financial-integrity`; o arquivo não incorpora o hash do próprio commit. A branch permanece isolada da Fase 1 e sem integração remota.

## 10. Parecer

**Fase 2 concluída localmente**, com AUD-005/006/007 corrigidos, testes completos aprovados nos dois bancos, concorrência financeira íntegra e alterações commitadas localmente. O deadlock de AUD-020 está explicado e continua aberto, conforme o escopo. Não há regressão conhecida introduzida nem falha final sem explicação.

Este parecer autoriza somente a revisão dos resultados pelo usuário. Não houve implantação e a Fase 3 permanece aguardando autorização expressa.
