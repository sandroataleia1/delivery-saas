# Fundação e evolução operacional — plano de implementação

> Fases 1 e 2 autorizadas expressamente pelo usuário e executadas com `superpowers:executing-plans`. Resultados nos relatórios da [Fase 1](../../desenvolvimento/RELATORIO-FASE-1-2026-11-06.md) e [Fase 2](../../desenvolvimento/RELATORIO-FASE-2-2026-11-07.md). A Fase 3 e as posteriores continuam sem autorização.

**Objetivo:** preparar uma base verificável para corrigir a auditoria, preservando o núcleo de pedidos, vendas, caixa, estoque e isolamento por empresa.

**Arquitetura:** manter Laravel + Inertia + Vue, Actions, FormRequests, banco relacional e polling. Ampliar o domínio existente com recebimentos de pedidos e estados operacionais, sem criar outro núcleo financeiro.

**Tecnologias:** versões dos lockfiles existentes; Laravel 13, PHP ^8.3, Vue 3, Inertia 3, Tailwind 4, Vite 8 e MariaDB. A versão de produção do banco ainda precisa ser definida.

**Especificação:** pedido de continuidade anexado nesta conversa, regras aprovadas das seções 3 e 4, e `docs/auditoria/RELATORIO-AUDITORIA-2026-11-06.md`. Em caso de divergência, prevalecem as novas regras do pedido.

**Data do plano:** 06/11/2026. **Estado em 07/11/2026:** Fases 1 e 2 concluídas localmente; CI remoto e equivalência com produção pendentes. As seções de reconhecimento e execução da fundação abaixo preservam o contexto original. Aguardando revisão do usuário antes de qualquer nova fase.

## 1. Estado encontrado e limites da verificação

- `git status --short --branch` responde que a pasta não é um repositório Git. Não existe histórico local utilizável para identificar alterações em relação a uma revisão anterior. Isso não significa que os arquivos estejam sem alterações.
- PHP CLI 8.5.12, Composer 2.11.0, Node 24.16.0 e npm 11.13.0 disponíveis. A chamada normal `composer -V` confirmou a versão, mas demorou vários minutos; conferir essa lentidão antes de instalar dependências. `php -m` confirmou bcmath, pdo_mysql e pdo_sqlite.
- Não existem `vendor/`, `node_modules/` nem `.env`. Não foram instaladas dependências, executados testes da aplicação, migrations ou acessos ao banco nesta execução.
- O lockfile registra Laravel v13.40.0, Inertia Laravel v3.7.0, Laravel Boost v2.10.4 e PHPUnit 12.5.43. Boost já está declarado, mas não está instalado nesta cópia.
- `AGENTS.md` ainda contém o bootstrap genérico. Antes de mudanças na aplicação, cumprir a instalação do Boost e reler as instruções geradas. Evitar atualizar dependências sem necessidade: partir do lockfile e conferir o efeito do comando exigido pelo bootstrap.
- Foram encontrados 309 métodos `test_*`. Esse número não equivale ao número de casos executados, que pode incluir provedores de dados. Os 317 testes e os resultados MariaDB do relatório são evidência histórica, não uma nova execução.
- Há binário `C:/xampp/mysql/bin/mysqld.exe`; isso não confirma versão, estado ou adequação da futura instância descartável. O serviço local existente não será usado.
- Não há configuração de CI nem scripts de lint JavaScript ou análise de tipos em `package.json`.

Não é possível produzir um diff histórico confiável sem baseline. Na Fase 1, inventariar e calcular hashes antes de qualquer alteração; manter o relatório e as evidências originais intactos.

## 2. Conferência da auditoria no código

As observações abaixo foram confirmadas por leitura, sem reexecutar as explorações:

| Item | Código observado | Consequência para o plano |
|---|---|---|
| AUD-001/002 | `MenuController` expõe itens e subtotal da comanda; `PlaceMenuOrder` chama `AddOrderItems`; mensagem diz que o pedido vai à cozinha | Introduzir recebimento e confirmação; restringir resposta pública; corrigir mensagem |
| AUD-017 | `known_order_id` nulo não detecta troca de atendimento; UUID é consultado dentro da comanda | Contexto de atendimento e idempotência durável por empresa |
| AUD-018 | `SendToKitchen` não valida comanda aberta; fechamento usa todos os itens não cancelados | Estados explícitos e bloqueio transacional de pendências |
| AUD-020 | Fechamento trava comanda antes de alcançar Company em `RegisterSale`; transferência trava Company primeiro | Padronizar ordem de locks e testar concorrência real |
| AUD-005 | `SettleBill` sempre grava `Bill::PAID`, independentemente da diferença de valor | Registro de baixas e saldo remanescente |
| AUD-006 | Atualização verifica somente conta aberta; `CancelBill` não diferencia origem vinculada | Bloquear operação direta, preservando reversões legítimas pela origem |
| AUD-007 | `CancelDeliveryOrder` impede apenas cancelado/acertado | Impedir cancelamento comum de entregue e tratar exceção separadamente |
| AUD-004/021 | Limitador do menu usa só IP; bootstrap não declara proxies confiáveis nem middleware específico de headers | Configuração alinhada à topologia real de implantação |
| AUD-023 | `DatabaseSeeder` tem apenas comentário; `DemoHistorySeeder` também escreve dados sem trava de ambiente | Proteger os dois pontos de entrada de demonstração |
| AUD-032/033 | Filtros usam entradas sem validação em várias listagens; IDs de produto não têm regra `integer` em vários fluxos | Corrigir validação de forma localizada, com regressões |
| AUD-040 | `ext-bcmath` não declarado; teste de isolamento executa DELETE em `audit_logs` | Declarar requisito e corrigir fixture sem desabilitar trigger |

Limitação adicional das provas: P20 aceita encontrar qualquer 500, sem garantir o comportamento de cada URL; P21 usa `assertTrue(true)`. Não copiar essas asserções como testes de regressão. O harness `audit_conc.php` e o XML MariaDB pressupõem execução na raiz da antiga cópia descartável: seus caminhos não funcionam diretamente a partir de `docs/auditoria/evidencias/`.

Preservar as evidências históricas. Criar testes novos com resultado desejado e asserções de dados, status HTTP, estoque, dinheiro e auditoria. Corrigir a premissa de C6: com caixa de origem aberto, cancelamento pode ajustar o esperado sem criar movimento de estorno.

## 3. O que manter, alterar e criar

| Grupo | Já existe e deve ser mantido | Alterar | Criar |
|---|---|---|---|
| Mesas/QR | Token fixo, comanda aberta única, snapshots de preço, UUID, locks | Vínculo de atendimento, exposição pública, limites e confirmação | Registro de cada pedido recebido, contexto de atendimento e acompanhamento restrito ao próprio envio |
| Operação | Lançamento por equipe, lote de cozinha, impressão manual, polling | Transições, fechamento, cancelamento e feedback de conexão | Setores, painel, eventos operacionais e tentativas de impressão |
| Configurações | Perfil da empresa e permissões | Formulários e validação | Operação do Restaurante e resolvedor central de configuração efetiva |
| Financeiro | Venda, caixa, fiado, contas, estoque e auditoria | Baixas, contas vinculadas e reversão de origem | Histórico de baixas e ajustes explícitos |
| Qualidade/operação | PHPUnit, Pint, build, `/up`, logs | Fixture MariaDB, validações, requisitos de ambiente | Git, CI, harness seguro, procedimento de backup/restauração e alertas |

## 4. Decisões de domínio e propostas técnicas

### QR fixo e risco residual

Aplicar a regra aprovada: sem PIN, login ou autenticação de sessão nesta versão. O token da mesa não comprova presença. Confirmação manual reduz cobrança e produção indevidas, mas não elimina pedidos remotos; modo automático aceita um risco maior.

Proposta: cada atendimento tem uma versão opaca, alterada ao encerrar ou cancelar a comanda, e não ao primeiro pedido. A página envia esse contexto junto com o UUID. Dois celulares que abriram a mesa livre podem participar do mesmo atendimento; uma página antiga não pode lançar no atendimento seguinte, mesmo quando abriu antes de existir comanda. Esse contexto é proteção contra obsolescência, não autenticação nem prova de presença.

Resolver leitura do contexto, abertura/adesão e inclusão sob locks consistentes. Não depender de `known_order_id = null` nem somente de checagem fora da transação. Definir e testar o efeito da transferência: contexto da origem é invalidado; envio com contexto da mesa antiga deve ser recusado.

Proposta de idempotência: um registro de recebimento por `(company_id, client_uuid)`, com hash canônico do conteúdo e vínculo ao atendimento. Reenvio idêntico retorna o resultado original, inclusive depois do fechamento; UUID reaproveitado com conteúdo ou mesa diferentes é recusado. Uma chave de acompanhamento aleatória permite consultar apenas aquele envio, sem listar consumo de outros visitantes. Nunca confiar no UUID para acessar dados de outra empresa.

### Estados e configurações

Separar aprovação (`pending`, `confirmed`, `rejected`, `cancelled`), produção (`waiting`, `preparing`, `ready`) e entrega. Encaminhamento e impressão têm registros próprios; nenhuma confirmação de impressão marca entrega ou preparo automaticamente.

O modo e o fluxo efetivos ficam registrados no pedido ao recebê-lo. Mudanças de configuração valem para pedidos futuros. Manual é o padrão; bloqueio administrativo força manual sem apagar a preferência automática e exige autor, motivo e data.

Uma proposta de estrutura é `RestaurantOperationSettings` por empresa, resolvida por `RestaurantOperationPolicy`, com validação no backend e histórico. Nomes são propostos, não classes existentes. Perfis continuam usando o catálogo atual de permissões, sem uma segunda fonte de autorização.

Limites iniciais propostos para revisão na Fase 2: 20 linhas, 10 unidades por linha, 50 unidades no pedido e R$ 500 por pedido público. Aplicar também teto acumulado de pedidos pendentes por mesa, para impedir contorno por fracionamento. Todos serão configuráveis dentro de tetos da plataforma; valores finais e taxas serão validados com o cardápio e o cenário de pico. Preços continuam calculados no servidor com decimal.

### Cozinha, impressão e fechamento

Começar pelo painel digital. Manter impressão manual atual identificada como manual durante a transição. Impressão automática depende de levantamento de sistema operacional, conexão USB/rede, protocolo e agente local; não escolher fornecedor ou protocolo definitivo agora.

Planejar uma fila persistida de encaminhamentos e tentativas de impressão, com reserva, identificador, confirmação, erro e reprocessamento. Timeout após envio é resultado desconhecido: reimprimir pode duplicar papel e exige controle de segunda via. Não prometer impressão física exatamente uma vez.

Sem impressora configurada, painel digital permanece utilizável e a interface apresenta orientação de configuração. Não disponibilizar modo exclusivamente impresso antes de haver um caminho funcional e observável de impressão.

O fechamento bloqueará qualquer pendência no backend, sob a mesma disciplina de locks do recebimento. Apenas itens entregues e confirmados entram no valor; rejeitados/cancelados não entram. Não adicionar atalho administrativo para ignorar pendências.

Para itens anteriores à implantação, não inferir entrega a partir de `kitchen_batch`. Antes da mudança, reconciliar comandas abertas ou executar procedimento assistido de classificação; histórico fechado mantém seus snapshots. Essa migração exige desenho e validação específicos nas fases operacionais.

### Financeiro

Propor histórico de baixas, mantendo principal, pagamentos, ajustes e saldo distintos. R$ 1 recebido em R$ 80 deixa R$ 79 em aberto. Quitação com desconto exige permissão própria, motivo e registro da diferença; pagamento acima do saldo não será convertido implicitamente em juros.

Contas vinculadas não poderão ser editadas/canceladas por operação direta. A reversão pela venda/entrada precisa continuar funcionando: hoje `CancelSale` chama `CancelBill`, portanto um bloqueio genérico nessa Action quebraria cancelamentos legítimos. Projetar caminho interno explícito e testar origem, permissões e transação.

Entrega concluída fica bloqueada para cancelamento comum. A operação excepcional, quando desenhada, deve distinguir perda, devolução física e devolução financeira; não devolver estoque automaticamente por presunção.

## 5. Sequência recomendada

| Ordem | Entrega | Dependências e critério de saída |
|---|---|---|
| 1 — Fundação | Baseline, Boost, harness descartável, seeder, validação, bcmath/Pint, suíte e CI | Plano autorizado; suíte MariaDB e SQLite sem falhas; evidências reproduzíveis |
| 2 — Financeiro urgente | AUD-005/006/007, incluindo reversões legítimas | Fundação; saldo, caixa, estoque e origem reconciliados |
| 3 — Recebimento QR | Confirmação, contexto, idempotência, limites, privacidade, configurações e bloqueio administrativo | Fundação; modo manual completo e alertas; automático só habilitado com encaminhamento funcional |
| 4 — Produção | Setores, painel, transições, entrega, cancelamentos, disponibilidade e alertas | Recebimento; fluxo completo testado |
| 5 — Consistência operacional | Bloqueio de fechamento, ordem de locks, reversões AUD-014 e taxa AUD-015 | Estados operacionais; concorrência comprovada |
| 6 — Operação de produção | Impressão automática, proxies, headers, monitoramento, backup/restauração, carga e E2E | Topologia e impressoras definidas; ambiente isolado equivalente ao destino |

A ordem antecipa o financeiro porque os defeitos existem independentemente da cozinha. As fases 3–5 são entregas técnicas sucessivas, mas não autorizam uso real do novo fluxo antes de sua integração: confirmação automática, estados, cancelamento e fechamento coerente precisam funcionar juntos. Correções pontuais de segurança HTTP podem ser antecipadas quando a hospedagem for conhecida.

Cada fase posterior ganha seu próprio plano detalhado antes de alterar schema. Planos/assinaturas, fiscal, adicionais e filiais permanecem fora desta primeira implementação.

## 6. Fase 1 — tarefas executáveis após autorização

### Tarefa 1 — baseline, dependências e isolamento

**Arquivos:** `.gitignore`, `AGENTS.md`, arquivos gerados pelo Boost; criar `docs/desenvolvimento/VALIDACAO.md`. `composer.json`/`composer.lock` somente quando exigido pela instalação ou requisitos explícitos.

- [ ] Inventariar arquivos e hashes, incluindo lockfiles; excluir segredos e dependências do snapshot destinado ao Git. Não sobrescrever evidências existentes.
- [ ] Conferir a lentidão da chamada do Composer. Usar instalação pelos lockfiles para a baseline, sem executar `composer setup`, que cria `.env` e executa migrations.
- [ ] Cumprir `composer require laravel/boost --dev` e `php artisan boost:install` exigidos pelo bootstrap, conferindo o diff de dependências; reler `AGENTS.md`. Se houver atualização transitiva não necessária, resolver o bootstrap sem misturá-la às correções.
- [ ] Criar baseline Git local e uma branch de trabalho; antes do primeiro commit conferir arquivos ignorados e ausência de segredos. Não configurar remoto, fazer push, merge ou deploy.
- [ ] Preparar cópia de teste e ambiente de teste próprios; não criar nem alterar `.env` real. Conferir extensões PHP e compatibilidade dos lockfiles.

**Saída:** inventário, baseline recuperável e dependências disponíveis em ambiente de desenvolvimento/teste sem acesso a dados reais.

### Tarefa 2 — harness seguro MariaDB e baseline executável

**Arquivos novos propostos:** `phpunit.mariadb.xml`, `scripts/testing/Invoke-DisposableMariaDb.ps1`, `scripts/testing/assert-disposable-database.php`, `tests/Feature/AuditImmutabilityTest.php`. Modificar `tests/Feature/TenantIsolationTest.php`; criar harness de concorrência em `tests/Concurrency/` usando as evidências como referência.

**Interface:** `Invoke-DisposableMariaDb.ps1` inicia instância com diretório temporário próprio, porta exclusiva e credenciais restritas; invoca validação antes do PHPUnit e devolve código de saída dos testes. O guard valida ambiente `testing`, host, porta, schema, marcador da instância criada e configuração efetiva, inclusive URL/cache.

- [ ] Criar teste do guard: rejeitar produção, banco sem marcador, porta local habitual, URL que sobrescreve o destino e configuração cacheada divergente antes de qualquer comando destrutivo. Executar esses testes sem conectar a bancos reais.
- [ ] Implementar o guard e o ciclo de vida da instância. Encerrar apenas o PID iniciado pelo harness; verificar caminho absoluto e marcador antes de remover seu diretório temporário.
- [ ] Registrar versão efetiva, engine, isolamento e comandos usados. Preferir a versão escolhida para produção; enquanto ela não existir, marcar resultado como provisório, sem alegar equivalência.
- [ ] Executar baseline SQLite e MariaDB. Registrar separadamente falhas prévias e falhas introduzidas.
- [ ] Substituir o DELETE da fixture de isolamento por registros identificáveis e filtro/asserções que não dependam de contagem global fixa. Manter triggers ativos.
- [ ] Provar com SQL que UPDATE e DELETE de auditoria são rejeitados e que leitura/inclusão permitidas continuam funcionando.
- [ ] Portar C1–C9 para caminhos reutilizáveis com processos independentes, resultados por cenário e asserções de valores finais. Nesta fase, C9 documenta o defeito conhecido; não mascará-lo como sucesso nem exigir sua correção fora do escopo.

**Saída:** suíte funcional passa nos dois bancos após correção de fixture; concorrência tem relatório separado que distingue integridade de erro operacional conhecido. Nenhum teste é direcionado ao MariaDB existente.

### Tarefa 3 — bloquear seeders de demonstração em produção

**Arquivos:** modificar `database/seeders/DatabaseSeeder.php` e `database/seeders/DemoHistorySeeder.php`; criar `tests/Feature/SeederSafetyTest.php`.

- [ ] Escrever testes que preparam schema descartável em `testing`, então simulam `production` apenas para invocar cada seeder diretamente. Exigir recusa antes de qualquer gravação, inclusive sem dados demo prévios.
- [ ] Verificar a falha dos testes antes da correção.
- [ ] Adicionar guarda no início de `run(): void` dos dois seeders, com erro explícito. Não alterar senhas ou credenciais existentes.
- [ ] Verificar recusa também pelo comando `db:seed --force` contra ambiente descartável e preservação de todas as contagens; `--force` não contorna a guarda.
- [ ] Verificar que o seeder básico continua utilizável em desenvolvimento/teste e preserva perfis e dados esperados.

Não usar `migrate:fresh --seed` para provar essa proteção: ele destruiria o schema antes de o seeder recusar. A guarda protege o seeder, não torna seguro um comando destrutivo em produção.

### Tarefa 4 — validação fundamental e requisitos

**Arquivos de validação a conferir/modificar:** `MenuController.php`, `TableOrderController.php`, `ProductController.php`, `CustomerController.php`, `SupplierController.php`, `UserController.php`, `StockController.php`, `Admin/CompanyController.php`, em `app/Http/Controllers/`; `StoreSaleRequest.php`, `StockEntryRequest.php` e `StoreDeliveryOrderRequest.php`, em `app/Http/Requests/`. Revisar também IDs em receitas e referências relacionadas nos mesmos fluxos.

**Outros arquivos:** `composer.json`, `composer.lock`, `lang/pt_BR/validation.php`; criar `tests/Feature/InputValidationTest.php` e ampliar testes dos respectivos módulos.

- [ ] Escrever casos parametrizados para `search`, `q`, `category`, `status`, `role` e `low`, conforme cada endpoint: arrays, objetos JSON, vazios, valores válidos e valores fora do domínio. Exigir erro de validação sem 500; entradas válidas mantêm busca, paginação e isolamento.
- [ ] Testar IDs como `1abc`, array, decimal, inexistente, válido e pertencente a outra empresa nos fluxos PDV, entrada, delivery e mesas/QR. Exigir erro no campo e nenhuma venda, item ou movimento financeiro/estoque criado.
- [ ] Reproduzir falhas antes da correção e aplicar validação localizada seguindo os padrões existentes, sem mudar contrato válido de frontend.
- [ ] Adicionar `integer` antes das regras de existência onde ausente, mantendo filtro por empresa e por disponibilidade/atividade atual.
- [ ] Declarar `ext-bcmath` como requisito obrigatório e conferir consistência do lockfile. Não executar atualização geral de pacotes.
- [ ] Corrigir somente a formatação apontada pelo Pint em `lang/pt_BR/validation.php`, preservando textos.
- [ ] Rodar regressões dos módulos afetados em SQLite e MariaDB; então suíte completa e build.

**Saída:** P20/P21 convertidos em testes de resultado correto, sem asserções triviais; requisições válidas continuam funcionando; ambiente sem bcmath é recusado pelo Composer.

### Tarefa 5 — CI e evidência final

**Arquivos:** criar `.github/workflows/ci.yml`; atualizar `docs/desenvolvimento/VALIDACAO.md` e relatório de execução da Fase 1.

- [ ] Configurar workflow de testes SQLite e MariaDB descartável, guard de destino, Pint, build e auditoria de dependências. Usar PHP compatível com o destino e uma matriz de suporte apenas quando validada pelo lockfile.
- [ ] Executar `composer validate --strict`, `composer check-platform-reqs`, `php artisan test --compact`, `vendor/bin/phpunit -c phpunit.mariadb.xml`, `vendor/bin/pint --test`, `npm run build`, `composer audit --locked` e `npm audit --omit=dev` no ambiente preparado.
- [ ] Registrar comandos equivalentes para Windows e Linux; validar no Windows o harness local e no runner Linux o serviço descartável. O job de concorrência documenta C9 como problema conhecido até a fase responsável, com resultado próprio visível.
- [ ] Guardar resumo sem segredos: versões, casos executados, falhas, duração e resultados monetários/estoque relevantes.
- [ ] Revisar diff contra baseline: nenhuma migration de negócio, alteração de `.env` real ou mudança funcional de QR/financeiro nesta fase.

O workflow só tem execução remota comprovada após o usuário disponibilizar repositório/runner e autorizar o envio. Nesta fase, entregar arquivo e validação local, marcando essa execução remota como pendente quando necessário.

## 7. Aceite objetivo da Fase 1

1. Baseline Git local recuperável, diff revisável e nenhum segredo versionado.
2. Dependências e Boost preparados; instruções geradas lidas; alterações de lock justificadas.
3. Os dois seeders de demonstração recusam produção antes de gravar; desenvolvimento continua utilizável.
4. IDs malformados e filtros compostos recebem validação, sem erro 500 ou efeitos colaterais, nos endpoints cobertos.
5. Extensão bcmath é requisito explícito; Pint e build passam.
6. Suíte completa SQLite e MariaDB passa; fixture não apaga auditoria nem enfraquece trigger. Número final informado pela execução, não fixado artificialmente em 317.
7. Harness recusa destinos não descartáveis; triggers e índices são verificados no MariaDB; C1–C9 têm relatório de integridade separado e o deadlock conhecido permanece explicitamente aberto.
8. CI entregue e verificações locais documentadas; execução remota e equivalência exata com produção recebem status próprio quando ainda não disponíveis.
9. Código financeiro e fluxo operacional mantêm comportamento nesta fase; AUD-005/006/007 e demais problemas não são apresentados como corrigidos.

## 8. Mapa dos arquivos das fases seguintes

| Área | Existentes provavelmente afetados | Novos componentes propostos |
|---|---|---|
| Recebimento | `MenuController`, `PlaceMenuOrder`, `AddOrderItems`, `OpenTable`, modelos `DiningTable`/`TableOrder`/`TableOrderItem`, `menu/Show.vue` | Modelo de recebimento, migrations aditivas, Actions de confirmar/rejeitar, contexto de atendimento e acompanhamento restrito |
| Configuração | `Company`, `CompanyController`, `CompanyProfileRequest`, admin de empresas, `company/Edit.vue`, `admin/companies/Form.vue` | Settings por empresa, resolvedor central, FormRequests e seção Operação do Restaurante |
| Produção | `SendToKitchen`, `CancelOrderItem`, `TableOrderController`, `tables/Index.vue`, `Order.vue`, `Ticket.vue`, categorias/produtos | Setores, tickets operacionais, painel, transições, eventos e controle de impressão |
| Autorizações | `config/permissions.php`, rotas, perfis e testes | Permissões de confirmação, produção, gestão após preparo e bloqueio administrativo |
| Financeiro | `Bill`, `Customer`, `SettleBill`, `ReopenBill`, `CancelBill`, `CancelSale`, `CancelStockEntry`, `BillController`, relatórios e telas finance | Histórico de baixas/ajustes, testes de saldo, estorno e vínculo |
| Concorrência/origens | `CloseTableOrder`, `TransferTable`, `RegisterSale`, `CancelDeliveryOrder`, `CreateDeliveryOrder`, `CancelSale`, relatórios | Testes simultâneos e reversão explícita da origem |
| Operação técnica | `bootstrap/app.php`, `AppServiceProvider`, configuração de sessão/logs, `resources/js/app.js` e polling | Feedback de conexão, monitoramento, procedimentos de backup e integração local de impressão |

Os nomes novos são propostas a confirmar no plano de cada fase. Reutilizar componentes Vue, layout e padrões de Actions do projeto.

## 9. Testes de aceite posteriores

- Recebimento: dois celulares simultâneos usam uma comanda; UUID repetido não duplica; conteúdo divergente com mesma chave é recusado; página antiga não entra no próximo atendimento; transferência não direciona pedido à mesa errada.
- Aprovação: manual não produz nem cobra pendentes; rejeição exclui cobrança; automático encaminha; bloqueio administrativo prevalece só para novos pedidos e preserva preferência.
- Segurança: empresa A não acessa configurações, pedidos, notificações ou setores de B; visitante não recebe a comanda completa; limites acumulados impedem divisão abusiva de pedidos pendentes.
- Produção: transições inválidas são recusadas; após preparo só gestor autorizado cancela; setor recebe cancelamento; todos os estados e efeitos ficam auditados.
- Impressão: ausência de destino não dispara envio; falha e timeout preservam pedido; reprocessamento é seguro no domínio; segunda via é identificada sem prometer ausência absoluta de papel duplicado.
- Conexão: falha antes de persistir não informa sucesso; perda da resposta após persistência permite reenvio sem duplicidade; reconexão recupera pedidos; tela sem atualização mostra aviso.
- Fechamento: todos os estados pendentes bloqueiam; entregue compõe conta; rejeitado/cancelado não bloqueia nem cobra; novo pedido simultâneo e fechamento não deixam item órfão nem venda inconsistente.
- Financeiro: R$ 1 em R$ 80 deixa R$ 79; duas baixas concorrentes não ultrapassam saldo; ajustes exigem permissão/motivo; estorno preserva trilha e caixa; cancelamento pela origem continua válido.

## 10. Dependências, riscos e decisões ainda necessárias

**Não bloqueiam o planejamento:** hospedagem, versão do banco de produção, impressoras e fornecedor de monitoramento. A Fase 1 pode preparar testes provisórios, mas não afirmar equivalência com um destino ainda indefinido.

**Antes da implantação/piloto:** definir topologia HTTPS/proxy, PHP e MariaDB de destino; retenção, RPO/RTO e local de backup; canal de alertas; hardware de impressão. Restauração precisa ser executada e cronometrada em ambiente limpo.

**Antes de habilitar automático:** validar limites com cardápios reais, encaminhamento funcional, alertas e aceite explícito do risco de pedido remoto. Não há como eliminar esse risco mantendo o acesso anônimo sem prova de presença.

**Riscos de regressão principais:** alterar o cálculo de subtotal ao separar aprovação; quebrar cancelamento de venda ao bloquear contas vinculadas; mudar números de relatórios ao introduzir baixas; inverter locks em novas Actions; efeitos de escopos globais em rotas públicas; classificar histórico legado como entregue sem evidência. Cada um exige teste específico na fase responsável.

Não modificar o escopo global para falhar sem contexto (AUD-024) na fundação: rotas públicas, seeders e comandos dependem hoje desse comportamento. Primeiro mapear consumidores e introduzir contexto explícito em uma mudança própria.

**Próxima ação:** revisão pelo usuário do relatório da Fase 2. Não iniciar a Fase 3 ou posteriores sem autorização expressa; preparar o plano específico antes de suas alterações de schema.
