# Fase 3 — recebimento seguro do QR: execução e evidências

**Data de conclusão:** 08/11/2026. **Branch:** `phase3/qr-receiving`. **Base financeira:** `d622c79`.
**Parecer:** concluída localmente no escopo aprovado. Não é autorização de implantação nem aprovação para produção. Fase 4 não iniciada.

## 1. Entrega

Pedido público vira recebimento pendente, com snapshots de produtos/preços e sem item cobrado, venda ou movimento de estoque/caixa. Equipe autorizada confirma ou rejeita; confirmação copia os snapshots uma única vez para os itens existentes. Rejeição exige motivo. Preço é calculado no servidor; produto inativo não pode ser confirmado.

O cardápio não expõe a comanda completa, código de barras ou SKU. O próprio envio é consultado por UUID e chave privada de 256 bits, enviada em header; apenas SHA-256 da chave é gravado no banco. A chave não vai à URL, props administrativas ou auditoria. UUID é único por empresa; replay idêntico retorna o recebimento original, inclusive após fechamento e alteração/inativação do produto. Conteúdo divergente retorna 409; chave incorreta, 404.

Cada mesa possui contexto opaco de atendimento: estável na primeira abertura e rotacionado no fechamento/cancelamento e nas duas mesas da transferência. Página antiga não abre conta seguinte. Pendentes acompanham a comanda transferida. Fechamento e cancelamento recusam pendências no backend.

Configuração central por empresa registra preferência, limites e alertas. **Modo efetivo sempre manual** nesta fase; preferência automática fica preservada, mas depende de produção funcional futura. Super administrador pode bloquear/desbloquear com motivo, autor e data. Perfis existentes que operam mesas recebem `tables.confirm` por capacidade; novos perfis padrão equivalentes também.

Lista de mesas e comanda mostram pendentes, atraso e aviso de atualização ausente; polling de 10 s. Som exige gesto do usuário e é deduplicado na tela. Cliente acompanha somente seus envios, com polling de 20 s, timeout e reenvio com payload/UUID/chave preservados. Registros locais por UUID e sincronização entre abas protegem recuperação; resposta antiga não apaga novo rascunho.

## 2. Schema e compatibilidade

Migration aditiva `2026_11_07_100005_create_menu_order_receiving_tables.php`: novas `restaurant_operation_settings`, `menu_order_receipts`, `menu_order_receipt_items`; contexto em `dining_tables`; vínculo único e nullable do item cobrado ao snapshot recebido. Valores em decimal; FKs restritivas e unicidade por empresa/UUID. Contextos das mesas existentes recebem backfill. Rollback com recebimentos é recusado.

Não houve classificação automática de histórico como preparado/entregue, nova regra financeira, cozinha digital ou impressão automática. Clientes com página antiga precisam recarregar para obter o novo contrato. Implantação futura exige ensaio sanitizado, backup/restauração e interrupção coordenada de escritas. Teste estrutural do backfill usa banco SQLite separado em memória; MariaDB executou as migrations e suíte completas em instância nova. Atualização com volume real legado não foi ensaiada.

Locks agora seguem Company → mesas ordenadas → comanda → recebimentos/itens, antecipação localizada prevista no desenho para impedir disputas de contexto e fechamento. O núcleo financeiro da Fase 2 foi preservado.

## 3. Limites e permissões

| Limite público | Padrão | Teto configurável da plataforma |
|---|---:|---:|
| Linhas por pedido | 20 | 30 |
| Unidades por linha | 10 | 50 |
| Unidades por pedido | 50 | 200 |
| Valor por pedido | R$ 500 | R$ 5.000 |
| Pedidos pendentes por mesa | 5 | 20 |
| Unidades pendentes por mesa | 100 | 500 |
| Valor pendente por mesa | R$ 1.000 | R$ 10.000 |
| Atraso | 10 min | 120 min |

Rate limit: 20/min por mesa+IP, 60/min por mesa e 600/min por empresa. Acompanhamento separado. IP compartilhado entre empresas não consome um único orçamento global. `trustProxies` e topologia HTTPS continuam dependentes da implantação; AUD-004 não é apresentado como integralmente resolvido em produção.

## 4. Verificações finais

| Verificação | Resultado observado |
|---|---|
| SQLite completo | 498/500 aprovados; 2 ignorados de trigger MariaDB; 2908 asserções; 23.49 s |
| MariaDB completo | 500/500 aprovados; 2912 asserções; 40.14 s |
| Cliente Node | 4/4: resposta atrasada, abas, recarga/reenvio e recuperação por evento de storage |
| Concorrência | C1–C9, F1–F5 e R1–R5: 19 cenários aprovados |
| C9 | 30 rodadas/120 workers, 0 deadlocks 1213, 0 timeouts 1205, 0 estados inválidos |
| Build frontend | `npm run build`, exit 0 |
| Pint | `php vendor/bin/pint --format agent`, aprovado após formatação |
| Composer | `validate --strict` e `check-platform-reqs`, exit 0 |
| Dependências | `composer audit --locked` e `npm audit --omit=dev`: zero vulnerabilidades conhecidas; sem atualização de pacotes |
| Git | Commits locais, sem remoto/push/merge/deploy; evidências anteriores e lockfiles intactos |

MariaDB 10.4.32, InnoDB, REPEATABLE-READ, instância própria temporária do harness. Nenhum teste usou a porta operacional 3306. PHP CLI 8.5.12. Não é equivalência com um destino de produção ainda indefinido.

R1 prova oito reenvios com um recebimento e uma auditoria de criação; R2 prova decisão única entre confirmar/rejeitar e subtotal coerente; R3 recusa fracionamento concorrente; R4 reconcilia receber versus fechar com venda/estoque; R5 verifica transferência sem comanda órfã. C3 configura explicitamente 20 pendentes para aceitar os 20 processos, mantendo padrão da aplicação em 5: uma comanda, 20 recebimentos, zero itens cobrados. F1–F5 preservam as provas financeiras anteriores.

Comandos reproduzíveis e precauções estão em [VALIDACAO.md](VALIDACAO.md). Saídas normalizadas em UTF-8, sem credenciais operacionais: [evidencias-fase-3](evidencias-fase-3/), com [SHA256SUMS.txt](evidencias-fase-3/SHA256SUMS.txt). Build foi executado separado dos testes HTTP.

## 5. Problemas encontrados e correções

- Contexto de autoria anônima podia sobreviver em processo reutilizado e atribuir confirmação ao visitante. Teste RED confirmou `user_id` nulo; `Context::scope` restringe a autoria anônima ao recebimento. GREEN exige funcionário real na decisão e criação anônima preservada.
- Revisão independente única de `01511dd` identificou dois achados importantes: resposta atrasada regredia estado/apagava novo carrinho; journal compartilhado entre abas podia perder credenciais de recuperação. Reproduzidos com script real e Vue instalado; corrigidos com gerações de resposta, vínculo do rascunho ao UUID e registros independentes sincronizados. Testes RED/GREEN no runner nativo do Node; nenhuma dependência nova.
- Compatibilidade de testes antigos: contratos que cobravam imediatamente passaram a exigir recebimento pendente e subtotal zero; preço do servidor, isolamento, estoque, permissões e efeitos financeiros continuam assertados. Não foram removidas barreiras de segurança para obter aprovação.
- Pint exigiu normalização adicional de imports/traits. Houve correção de BOM em um teste gerado via PowerShell e de serialização de proxy no transporte simulado do teste Node; resultados finais acima não contêm essas falhas.
- Build emite aviso de pacote opcional `fontaine` ausente para otimização de fontes. Compilação aprovada; nenhuma instalação foi feita para suprimir aviso.

## 6. Riscos e limitações restantes

O QR continua fixo e anônimo, sem PIN/login/prova de presença. Contexto detecta página obsoleta; confirmação manual e limites reduzem, mas **não eliminam pedidos remotos**. Não habilitar automático como se esse risco estivesse eliminado.

Confirmação não comprova impressão, preparo ou entrega. Encaminhamento continua manual; painel de cozinha, impressão automática, transições completas de produção e bloqueio de fechamento por esses estados pertencem às próximas fases. AUD-001/002 são tratados no alcance do desenho aprovado, sem afirmar eliminação do risco residual ou entrega automática à cozinha. AUD-017 ganhou contexto e replay durável; AUD-020 teve correção localizada e zero deadlocks no cenário reproduzível, sem promessa universal.

Testes Node simulam transporte/storage e executam o script do componente; não validam DOM, cookies/CSRF em navegador, áudio real ou políticas de abas em segundo plano. Não houve celular físico, impressora, carga, backup/restauração, runner remoto ou produção. Áudio depende de ativação e suporte do navegador; polling/sinalização dependem de conexão e agendamento do browser. Esses itens precisam de validação operacional antes de piloto.

O aparelho conserva as chaves dos próprios envios. Limpar/perder/quota do armazenamento exige conferência com a equipe; registros do servidor permanecem. Os 20 recebimentos conhecidos mais recentes são mostrados, além dos incertos; o armazenamento independente é conservado para recuperação. Não substitui política de retenção/LGPD nem backup.

CI foi atualizado com regressões Node e C9 exigindo zero deadlocks, mas não executado remotamente. Hospedagem/versões finais/proxy/HTTPS e ensaio de implantação permanecem pendentes. Nenhuma dessas limitações foi substituída por acesso inseguro a dados reais.

## 7. Commits locais

| Commit | Alteração |
|---|---|
| `f922bca` | Desenho da Fase 3, posteriormente aprovado |
| `01511dd` | Schema, configurações, recebimento privado, confirmação/rejeição, contexto/locks, alertas e testes |
| `24606a5` | Correções da revisão: respostas atrasadas e recuperação entre abas; regressões Node no CI |
| `72594f6` | Asserção adicional de recuperação e formatação final de modelos |
| `9f8f0d0` | Liberação do rascunho recuperado na sincronização entre abas, com quarta regressão |

O commit de documentação da entrega inclui este relatório, evidências, manifesto e atualização do ledger/guia; sua identificação é consultável em `git log`. A branch permanece local, sem integração com a fase anterior.

## 8. Arquivos criados e modificados

Legenda: **A** criado; **M** modificado, contra `d622c79`. Relação do código/desenho já commitado:

- **M** `.github/workflows/ci.yml`
- **M** `app/Actions/AddOrderItems.php`
- **M** `app/Actions/CancelOrderItem.php`
- **M** `app/Actions/CancelTableOrder.php`
- **M** `app/Actions/CloseTableOrder.php`
- **A** `app/Actions/ConfirmMenuOrder.php`
- **M** `app/Actions/OpenTable.php`
- **M** `app/Actions/PlaceMenuOrder.php`
- **A** `app/Actions/ReceiveMenuOrder.php`
- **A** `app/Actions/RejectMenuOrder.php`
- **A** `app/Actions/SaveRestaurantOperationSettings.php`
- **M** `app/Actions/SendToKitchen.php`
- **M** `app/Actions/TransferTable.php`
- **M** `app/Http/Controllers/Admin/CompanyController.php`
- **M** `app/Http/Controllers/CompanyController.php`
- **M** `app/Http/Controllers/DiningTableController.php`
- **M** `app/Http/Controllers/MenuController.php`
- **A** `app/Http/Controllers/MenuOrderReceiptController.php`
- **A** `app/Http/Controllers/RestaurantOperationSettingsController.php`
- **M** `app/Http/Controllers/TableOrderController.php`
- **A** `app/Http/Requests/RestaurantOperationSettingsRequest.php`
- **A** `app/Http/Requests/StoreMenuOrderRequest.php`
- **M** `app/Models/DiningTable.php`
- **A** `app/Models/MenuOrderReceipt.php`
- **A** `app/Models/MenuOrderReceiptItem.php`
- **A** `app/Models/RestaurantOperationSettings.php`
- **M** `app/Models/TableOrder.php`
- **M** `app/Providers/AppServiceProvider.php`
- **A** `app/Support/RestaurantOperationPolicy.php`
- **A** `app/Support/TableOperationLock.php`
- **M** `config/permissions.php`
- **A** `config/restaurant.php`
- **A** `database/factories/MenuOrderReceiptFactory.php`
- **A** `database/factories/MenuOrderReceiptItemFactory.php`
- **A** `database/factories/RestaurantOperationSettingsFactory.php`
- **A** `database/migrations/2026_11_07_100005_create_menu_order_receiving_tables.php`
- **M** `docs/superpowers/plans/2026-11-06-fundacao-e-evolucao-operacional.md`
- **A** `docs/superpowers/plans/2026-11-07-fase-3-recebimento-qr.md`
- **A** `docs/superpowers/specs/2026-11-07-fase-3-recebimento-qr-design.md`
- **M** `lang/pt_BR/audit.php`
- **A** `resources/js/components/MenuReceiptAlerts.vue`
- **A** `resources/js/components/MenuReceiptDecisions.vue`
- **A** `resources/js/components/RestaurantOperationForm.vue`
- **M** `resources/js/pages/admin/companies/Form.vue`
- **M** `resources/js/pages/company/Edit.vue`
- **M** `resources/js/pages/menu/Show.vue`
- **M** `resources/js/pages/tables/Index.vue`
- **M** `resources/js/pages/tables/Order.vue`
- **M** `routes/web.php`
- **A** `tests/Concurrency/receipts.php`
- **M** `tests/Concurrency/run.php`
- **M** `tests/Feature/InputValidationTest.php`
- **A** `tests/Feature/MenuReceiptTest.php`
- **A** `tests/Feature/MenuReceivingMigrationTest.php`
- **M** `tests/Feature/MenuTest.php`
- **A** `tests/Feature/RestaurantOperationSettingsTest.php`
- **A** `tests/Feature/TableContextTest.php`
- **A** `tests/Frontend/MenuRecovery.test.mjs`

Documentação final adicional: este relatório; `docs/desenvolvimento/VALIDACAO.md`; arquivos de `docs/desenvolvimento/evidencias-fase-3/`; atualização do ledger do plano da Fase 3. Evidências originais da auditoria e das fases anteriores permanecem sem diff.

## 9. Aceite

Escopo aprovado implementado e verificado localmente; nenhuma falha de teste final inexplicada. Dois skips SQLite explicados e cobertos no MariaDB. Achados importantes da revisão corrigidos e exercitados por regressões. Limitações de ambiente e validação operacional estão discriminadas acima. Fase 4 aguarda autorização explícita após revisão desta entrega.
