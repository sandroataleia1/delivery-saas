# Execução da Fase 1 — Fundação

**Data:** 06/11/2026. **Parecer:** Fase 1 concluída no âmbito local previsto no plano, com CI remoto e equivalência com produção ainda não verificados. Não há falha de teste sem explicação na entrega. O defeito operacional AUD-020 permanece reproduzido e explicitamente aberto. **A Fase 2 não foi iniciada.**

## 1. Arquivos criados e modificados

O inventário completo, incluindo os arquivos gerados pelo Boost, está em [ARQUIVOS-FASE-1.txt](ARQUIVOS-FASE-1.txt). Os grupos principais são:

| Área | Arquivos e finalidade |
|---|---|
| Baseline e ferramentas | `.gitignore`, `AGENTS.md`, `CLAUDE.md`, `.agents/skills/`: instruções Boost e exclusão de segredos/dependências/dados locais |
| Banco descartável | `phpunit.xml`, `phpunit.mariadb.xml`, `tests/TestCase.php`, `scripts/testing/`: chave somente de teste, guard, provisionamento, ciclo de vida e resumo de resultados |
| Integridade e concorrência | `tests/Support/`, `tests/Unit/`, `tests/Concurrency/run.php`, `AuditImmutabilityTest`, fixture de `TenantIsolationTest`, asserções adicionais de `DeliveryTest` |
| Seeders | `DatabaseSeeder.php`, `DemoHistorySeeder.php`, `SeederSafetyTest.php`: recusa em produção antes de qualquer gravação |
| Validação | Controllers de produtos, clientes, fornecedores, usuários, estoque, administração de empresas, mesas e menu; Requests de venda, entrada, delivery e receita; `InputValidationTest.php` |
| Requisitos e formatação | `composer.json`, `composer.lock`, `lang/pt_BR/validation.php`, `pint.json`: bcmath obrigatório e formatação sem alterar textos |
| Entrega | `.github/workflows/ci.yml`, documentação de validação, progresso, relatório, evidências e atualização do estado do plano |

Nenhuma alteração em Actions, modelos, migrations de negócio, rotas, configurações da aplicação, frontend ou `package-lock.json`. Os cinco arquivos originais de `docs/auditoria` mantêm seus hashes SHA-256. Não foi criado `.env` real.

## 2. Git e commits locais

| Commit | Alteração |
|---|---|
| `1fe21fc` | Baseline recuperável com os 368 arquivos originais, antes das modificações |
| `47ed6d3` | Preparação, `.gitignore` e instruções/skills Boost |
| `e0bce72` | Proteção dos dois seeders e testes RED→GREEN |
| `2530366` | MariaDB descartável, guard, imutabilidade e harness C1–C9 |
| `7b4df50` | Validação de filtros/referências de produtos, bcmath e formatação |
| `378e6b6` | Ajustes da revisão: socket, barreira e reconciliação de efeitos concorrentes |

O commit final de CI/documentação é identificável por `git log -1` e pela mensagem `ci: deliver phase 1 checks and verified execution evidence`. A branch permanece `phase1/foundation`. Nenhum remoto, push, merge ou deploy foi realizado.

O inventário SHA-256 anterior à alteração ficou no diretório temporário `delivery-phase1-baseline-6f3b3f2bccb448bbb95578df95f8edf1`. A recuperação durável é o commit baseline. A execução usou esta branch no diretório aberto pelo usuário e isolamento dos dados, sem criar outro checkout de código; essa adaptação está registrada no progresso.

## 3. SQLite

**435 casos: 433 aprovados, 2 ignorados, 2.276 asserções, 25,069 s; exit 0.** Os dois ignorados são testes de triggers de auditoria, executados efetivamente no MariaDB. Comando: `php artisan test --compact`. Evidência: [sqlite.json](evidencias-fase-1/sqlite.json).

Os testes novos exercitam requisições inválidas e válidas, permissões e isolamento. A suíte de validação tem 83 casos e 346 asserções, incluindo comparação de dados financeiros e estoque antes/depois de entradas recusadas. Os seeders têm cinco testes e 13 asserções; produção é simulada somente depois da criação segura do schema descartável.

## 4. MariaDB

**435 aprovados, 2.280 asserções, 33,539 s; exit 0.** Ambiente efetivo: MariaDB **10.4.32**, InnoDB, REPEATABLE-READ, PHP 8.5.12. Schema, usuário restrito, senha, porta e dados criados exclusivamente para cada execução. O serviço operacional do XAMPP não foi usado. Evidências: [mariadb.json](evidencias-fase-1/mariadb.json), [metadados](evidencias-fase-1/mariadb-metadata.json).

Executado pelo `Invoke-DisposableMariaDb.ps1 -Mode Suite`, que chama `vendor/phpunit/phpunit/phpunit -c phpunit.mariadb.xml`. Leitura/inclusão da auditoria continuam permitidas; SQL UPDATE e DELETE são rejeitados pelos triggers ativos. A fixture de isolamento não apaga auditoria nem depende de contagem global fixa.

## 5. Concorrência

Processos independentes, com barreira de prontidão e execução em MariaDB descartável. Cada grupo mostrou todos os workers prontos antes da liberação. Evidência completa: [concorrencia.jsonl](evidencias-fase-1/concorrencia.jsonl).

| Cenário | Resultado observado |
|---|---|
| C1 | 12 reenvios da mesma chave: 1 venda, uma baixa de estoque |
| C2 | 20 vendas sobre saldo 5: saldo -15 e soma de movimentos -20; numeração única |
| C3 | 20 pedidos QR: 1 comanda, 20 itens |
| C4 | 6 fechamentos: 1 venda e 5 recusas válidas |
| C5 | 6 baixas: 1 movimento de caixa |
| C6 | 6 cancelamentos: uma reversão de estoque, saldo restaurado, nenhum movimento extra no caixa de origem aberto |
| C7 | 8 aberturas: 1 sessão |
| C8 | Fechamento com 15 vendas concorrentes: nesta rodada o fechamento venceu e as 15 vendas foram recusadas; esperado congelado R$ 0 = pagamentos R$ 0 |
| C9 | 30 rodadas/120 workers: 60 deadlocks 1213, zero timeout 1205, zero estado inválido e zero erro inesperado |

O C9 verifica ambas as chaves de tentativa de fechamento, venda vinculada, vendas órfãs/duplicadas, pagamentos, itens, movimentos e saldo por rodada. A integridade passou; a operação segue com **FALHA_CONHECIDA_AUD_020**. A saída zero do harness indica invariantes preservadas e erros classificados; não apresenta o deadlock como corrigido. O saldo negativo em C2 é a política preexistente, também não alterada.

## 6. Build e formatação

`npm run build`: **sucesso em 1,82 s**. [Saída](evidencias-fase-1/build.txt). Nenhum código frontend mudou.

O plugin de fontes avisa que fallbacks otimizados precisam do pacote opcional `fontaine`. O build concluiu com exit 0; não foram alteradas dependências para suprimir o aviso. PowerShell 5 apresenta mensagens de stderr como `NativeCommandError` nos logs redirecionados, inclusive a mensagem informativa de `check-platform-reqs`; os códigos de saída e resultados efetivos foram conferidos.

`vendor/bin/pint --test`: **passou**. [Saída](evidencias-fase-1/pint.json). Excluídos somente os scripts históricos imutáveis em `docs/auditoria/evidencias`; o PHP ativo continua coberto. A alteração no arquivo de tradução foi somente de formatação.

## 7. Segurança, dependências e CI

| Verificação | Resultado |
|---|---|
| `composer validate --strict` | Válido |
| `composer check-platform-reqs` | Todos os requisitos atendidos |
| `composer audit --locked` | Nenhum aviso de vulnerabilidade conhecido |
| `npm audit --omit=dev` | Zero vulnerabilidades nas dependências de produção |
| Comparação dos locks | Todos os 118 pacotes PHP, versões e referências idênticos ao baseline; lock npm byte a byte idêntico |
| bcmath ausente | Composer recusou instalação dry-run com plataforma simulada, exit 2 |
| Guard de destino | 13 testes sem conexão, cobrindo produção, porta, host, marcador/token, datadir, URL, socket, cache e SQLite em disco |
| CI | YAML válido com dois jobs; PowerShell do harness sem erro de sintaxe |

As saídas estão em [evidencias-fase-1](evidencias-fase-1/). As auditorias não substituem avaliação externa de segurança; o npm foi auditado com `--omit=dev`, conforme escopo.

Boost 2.10.4 já constava no lock. Foram instalados 118 pacotes PHP e 109 npm sem atualização geral. A única mudança de lock PHP é o requisito de plataforma `ext-bcmath` e seu `content-hash`; nenhuma resolução de pacote mudou. O comando offline `composer update --lock` não possuía todos os metadados em cache; a sincronização desses dois campos usou as próprias classes Composer do PHAR e foi validada pelo Composer. O teste de ausência de bcmath usa uma cópia de diagnóstico: a extensão local é compilada estaticamente e não foi removida.

O CI inclui SQLite, MariaDB descartável, guard, Pint, build, auditorias e concorrência. **Não houve execução de runner Linux/remoto**, conforme a restrição de não enviar o código. As credenciais literais do serviço CI são exclusivas do container descartável. Nenhuma credencial operacional, banco ou arquivo `.env` foi versionado; `.env.example` permanece como exemplo.

## 8. Problemas encontrados e tratamento

1. Ausência de APP_KEY na baseline: gerava erros de preparação. Adicionada chave pública somente nos XMLs de teste; nenhum ambiente real alterado.
2. Fixture de auditoria usava DELETE, recusado pelo trigger MariaDB. Substituída por registros identificáveis e filtro/asserções por empresa; trigger preservado.
3. Provas antigas P20/P21 tinham cobertura fraca. Criadas regressões com status/campo e ausência de efeitos reais; `/vendas` usa seus filtros reais `number/status`, sem inventar busca nova.
4. Deadlock era encapsulado em exceção PDO anterior. Classificação corrigida e testada sem depender apenas do texto da mensagem.
5. Revisão independente confirmou riscos no próprio harness: socket poderia contornar host/porta; liberação por horário poderia não garantir concorrência; C9 precisava detectar vendas fora do vínculo. Os três pontos foram corrigidos e tiveram testes RED→GREEN antes da verificação final.
6. Uma rodada intermediária MariaDB falhou na página de bairros. O log mostrou que o build paralelo havia substituído o CSS de fontes durante a renderização. Sem build simultâneo, o teste isolado e a suíte completa passaram. Asserções de persistência foram adicionadas à fixture. [Registro da causa](evidencias-fase-1/interferencia-build.txt).
7. Git recusou proprietário diferente do sandbox: usado `safe.directory` somente por comando/processo, sem configuração global. O executor isolado passou a falhar após criar `.git`; chamadas autorizadas exigiram escalonamento. Um limite temporário da revisão automática interrompeu uma chamada sem executar ação; o trabalho foi retomado após o usuário pedir continuidade.
8. O wrapper Composer removeu temporariamente o circunflexo de `^2.10`; restaurado por chamada direta ao PHAR. O resultado final preserva a restrição e os pacotes existentes. Boost instalado com ambiente local e SQLite em memória, sem `.env`.

Os ciclos RED precederam as correções: seeders (4 falhas), validação (48 no SQLite/54 no MariaDB na etapa inicial), barreira (2), socket (1) e reconciliação (10). A fixture MariaDB também reproduziu sua falha antes da correção. Os números de execuções intermediárias não são o aceite final.

## 9. Riscos e limitações

- MariaDB 10.4.32 foi usado por disponibilidade local e reprodutibilidade; não representa recomendação para produção. A versão de destino e sua equivalência continuam indefinidas.
- CI remoto/Linux ainda não executado. Precisa de repositório/runner e autorização para envio em outro momento.
- C9 conserva o deadlock AUD-020. As falhas QR, financeiras e de operação das fases seguintes continuam abertas. A Fase 1 não torna o sistema apto para produção.
- Não foram executados carga, backup/restauração, impressora, celular físico, e-mail real ou teste de intrusão.
- Os guards protegem as entradas de teste entregues; não interceptam comandos Artisan arbitrários de um operador. Seguir [VALIDACAO.md](VALIDACAO.md).
- Nenhum novo lint JavaScript, análise estática ou tipo foi introduzido. Pint cobre PHP; o build verifica a compilação frontend.

## 10. Parecer de conclusão

As cinco tarefas foram entregues com validação local efetiva. Baseline e commits recuperáveis, Boost preparado sem atualização de pacotes, guard testado, MariaDB real descartável, seeders protegidos, validação com regressões, requisito bcmath, Pint, build, CI e evidências estão disponíveis. Os problemas surgidos na execução têm causa e tratamento documentados.

O aceite local da Fase 1 está atendido. Execução remota do CI e equivalência com produção possuem status pendente próprio, como permitido no plano. Não há autorização implícita para implantação nem para a Fase 2. Aguardar a revisão e nova autorização do usuário.
