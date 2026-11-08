# Validação da Fase 1

Os comandos abaixo validam a aplicação sem `.env` e sem acessar o banco operacional. Dependências são instaladas pelos lockfiles. A branch local é `phase1/foundation`; a revisão original está no commit `1fe21fc`.

## Windows

Pré-requisitos verificados: PHP 8.5 com bcmath, pdo_mysql e pdo_sqlite; Composer 2; Node 24; npm 11. O harness usa os binários MariaDB em `C:\xampp\mysql\bin`, mas inicia **outro processo**, com dados temporários e porta própria. Não inicia nem usa o serviço do XAMPP.

```powershell
composer install --no-interaction --prefer-dist --no-progress
npm ci --ignore-scripts --no-audit --no-fund
composer validate --strict
composer check-platform-reqs
php artisan test --compact
php vendor/bin/pint --test
npm run build
composer audit --locked
npm audit --omit=dev
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode All
```

`-Mode Suite` executa apenas PHPUnit; `-Mode Concurrency` executa apenas C1–C9. `-MariaDbBin` permite apontar para outro diretório de binários, sem reutilizar instância existente. O script devolve o código de saída dos testes e encerra somente o PID que criou. A remoção do diretório verifica caminho absoluto, prefixo temporário e token do marcador.

Se o Git recusar o proprietário da pasta, usar `git -c safe.directory=C:/xampp/htdocs/delivery-saas ...` por comando. Para subprocessos que invocam Git (como Pint), esta execução usou as variáveis de processo `GIT_CONFIG_COUNT=1`, `GIT_CONFIG_KEY_0=safe.directory`, `GIT_CONFIG_VALUE_0=C:/xampp/htdocs/delivery-saas`; nenhuma configuração global foi alterada.

Saídas locais ficam em `.testing/`, ignorado pelo Git. PowerShell 5 grava redirecionamentos em UTF-16LE; `php scripts/testing/summarize-results.php .testing/mariadb-suite.txt` trata essa codificação. As evidências publicadas desta execução foram normalizadas para UTF-8 e não incluem credenciais nem marcadores.

## Proteção do destino

`Tests\TestCase` verifica a configuração efetiva antes de executar os traits de preparação de banco. SQLite deve ser exclusivamente `:memory:`. Para MariaDB/MySQL, exige ambiente `testing`, host `127.0.0.1`, porta diferente de 3306, nome `delivery_test_<hex>`, marcador e token correspondentes. Recusa URL de conexão, socket Unix e configuração cacheada. Antes de migrations, confere `@@datadir` com o marcador. Os testes unitários do guard não se conectam a banco algum.

O provisionador valida o destino antes da primeira conexão administrativa e antes de criar o schema; cria usuário restrito ao schema de teste. O harness de concorrência também chama o guard antes de `migrate:fresh`. O marcador identifica a instância criada pelo harness; não é uma autorização para testar contra servidores arbitrários.

Essas proteções cobrem os pontos de entrada de teste entregues. Comandos Artisan arbitrários executados manualmente não passam automaticamente pelo guard. Não executar `migrate:fresh`, `migrate:refresh` ou outros comandos destrutivos fora do harness. A trava dos seeders protege apenas a execução do seeder, inclusive com `--force`; não torna segura uma migration destrutiva em produção.

Os XMLs incluem chave pública exclusiva de teste. O harness gera sua própria chave temporária. Nenhuma delas é chave de produção.

## Linux e CI

`.github/workflows/ci.yml` contém dois jobs: SQLite/build/verificações e MariaDB/concorrência. No Linux, os comandos Composer, npm, PHPUnit e Pint são os mesmos. O MariaDB é um serviço Docker descartável do runner, publicado em 3307; o job gera schema, senha e marcador, então executa:

```sh
php scripts/testing/provision-disposable-database.php
php scripts/testing/assert-disposable-database.php
vendor/bin/phpunit -c phpunit.mariadb.xml
php tests/Concurrency/run.php
```

Esses comandos dependem das variáveis e do serviço definidos no workflow; não devem ser copiados para um terminal conectado a banco real. A senha administrativa literal do YAML pertence somente ao serviço descartável do job. Nenhum segredo operacional está no arquivo.

O YAML foi validado localmente; **nenhum runner Linux/remoto foi executado** nesta fase. Não foi configurado remoto nem realizado push. MariaDB 10.4.32 reproduz o binário local testado, sem recomendar essa versão para produção. PHP e MariaDB de produção ainda precisam ser definidos e validados.

## Interpretação dos resultados

SQLite verifica regras e HTTP, mas não prova locks nem triggers. Os dois testes de imutabilidade de auditoria são explicitamente ignorados nesse banco e executados no MariaDB. Não desabilitar triggers para obter resultado verde.

C1–C9 usam processos independentes e barreira: todos precisam informar prontidão antes da liberação. Falha de inicialização ou timeout é erro do teste. C9 força sobreposição dos locks, reconcilia as duas chaves de fechamento, pagamentos, itens, movimentos e saldo por rodada. Seu JSON distingue integridade de erro operacional: `FALHA_CONHECIDA_AUD_020` significa que o deadlock permanece. Código de saída zero comprova as invariantes testadas, não ausência desse defeito.

O Pint exclui apenas as evidências históricas em `docs/auditoria/evidencias`, preservadas byte a byte. Todo o PHP ativo, inclusive os novos testes e scripts, continua sujeito à formatação.

Na entrega da Fase 1, não estavam cobertos: produção, carga, backup/restauração, impressoras, celulares físicos, e-mail real ou correção dos defeitos financeiros e operacionais reservados às fases posteriores.

## Complemento da Fase 2 — integridade financeira

O mesmo harness agora executa F1–F5 em `tests/Concurrency/financial.php`, além de C1–C9: pagamentos parciais concorrentes, desconto autorizado único, estorno de baixa selecionada e baixa versus cancelamento de venda/entrada. F4/F5 alternam precedência após a barreira e exigem os dois desfechos sem inconsistência. Não executar o arquivo financeiro isoladamente: ele é incluído pelo orquestrador depois do guard e da preparação descartável.

No Windows, os comandos continuam `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode Suite` e `-Mode All`. No Linux, o job existente chama `php tests/Concurrency/run.php` após provisionamento e guard; a inclusão dos novos cenários é automática. Nenhum runner remoto foi executado.

A suíte aplica a migration aditiva do histórico de baixas somente nos bancos de teste. Os testes de legado exercitam a rotina de importação sobre fixtures; não executar essa rotina manualmente contra banco real. Conta legada incoerente permanece bloqueada para reconciliação. Rollback com histórico populado é recusado. A implantação futura exige ensaio sanitizado, backup/restauração e interrupção de escritas financeiras.

Resultados e limitações da Fase 2 estão no [relatório de execução](RELATORIO-FASE-2-2026-11-07.md). As evidências da Fase 1 continuam históricas e intactas; os novos resultados estão em `evidencias-fase-2/`.


## Complemento da Fase 3 — recebimento seguro do QR

O harness inclui `tests/Concurrency/receipts.php` (R1–R5) após o guard: replay da mesma chave, confirmar versus rejeitar, limite acumulado, receber versus fechar e receber versus transferir. C3 exige agora uma comanda, 20 recebimentos pendentes e nenhum item cobrado; configura explicitamente teto de 20 pendentes para esse cenário. Os limites padrão da aplicação continuam 5 pedidos pendentes.

A disciplina de locks passou a ser Company → mesas ordenadas → comanda → recebimentos/itens. C9 conserva 30 rodadas e 120 workers com sobreposição forçada; nesta fase seu código de saída exige também **zero deadlocks 1213 e zero timeouts 1205**. A descrição histórica das fases anteriores acima não representa esse novo critério. O CI chama o mesmo orquestrador; execução remota continua não realizada.

A migration `2026_11_07_100005` é aditiva, preenche contexto nas mesas existentes e concede `tables.confirm` a perfis que já operavam mesas, independentemente do nome. O contexto é controle de obsolescência, não autenticação. Não marca itens antigos como preparados/entregues. Rollback com recebimentos é recusado. Antes de implantação: ensaiar atualização com cópia sanitizada, interromper escritas, testar restauração e validar comandas abertas. Não executar migration manualmente contra dados operacionais nesta entrega.

O browser precisa de contexto, UUID e chave privada por envio. Requisições antigas sem esses campos recebem validação e precisam recarregar o cardápio. A chave fica no aparelho e só seu hash vai ao servidor; não é parâmetro de URL. Perder armazenamento local perde o acompanhamento privado; a equipe continua tendo o registro. Resposta incerta preserva payload/identificadores para reenvio. Não apagar esse registro para tentar novamente sem conferir com a equipe.

A preferência automática é apenas registrada: modo efetivo permanece manual até existir encaminhamento funcional em fase futura. Aprovação não comprova preparo, impressão ou entrega. O QR continua anônimo; confirmação manual e limites reduzem, mas não eliminam, pedidos remotos. O proxy/HTTPS de produção ainda não foi configurado.

As evidências da entrega ficam em `evidencias-fase-3/`; relatório próprio registra revisões, resultados, limitações e commits. As evidências das fases anteriores permanecem intactas.


Regressões do cliente: `node --test tests/Frontend/*.test.mjs` (Windows: caminho explícito `tests/Frontend/MenuRecovery.test.mjs`). Usa o script real do componente, Vue instalado e transporte/storage controlados. Exercita reenvio, recarga, respostas atrasadas e abas; não exige pacotes adicionais nem equivale a E2E em navegador. O build deve rodar separado dos testes HTTP para não invalidar o manifest durante a suíte.

O journal do cliente guarda cada UUID em uma chave independente e sincroniza abas antes de permitir um novo envio. Apenas os 20 recebimentos conhecidos mais recentes são apresentados, além de todos os envios incertos. Os registros locais de recuperação são conservados; perda/limpeza/quota do armazenamento exige atendimento da equipe. Não são backup do pedido: o registro do servidor permanece sendo a fonte da verdade. Áudio depende de gesto e suporte do navegador; validar em hardware real antes do piloto.

## Complemento da Fase 4 — produção por item e setor

O harness inclui `tests/Concurrency/production.php` (P1–P5) após o guard: lançamentos iguais, transições com a mesma versão, entregar × cancelar × fechar, confirmar × esgotar e reconciliações do mesmo legado. Todos os cenários anteriores (C1–C9, F1–F5, R1–R5) continuam no mesmo orquestrador, e C9 mantém o critério de zero deadlocks 1213 e zero timeouts 1205.

Execução completa no Windows: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode All` (suíte + concorrência numa instância MariaDB temporária, removida ao final). SQLite: `php artisan test --compact`. Cliente: `node --test tests/Frontend/MenuRecovery.test.mjs`. Rodar o build separado dos testes HTTP.

As migrations `2026_11_08_095904` e `2026_11_08_101140` são aditivas e recusam rollback com histórico. Itens ativos anteriores ficam como legado aguardando reconciliação; fechamento, acerto e produção dessas origens ficam bloqueados até a classificação. Antes de implantar: ensaiar com cópia sanitizada, interromper escritas, testar restauração, configurar setor padrão e perfil da cozinha (`production.operate`/`production.manage`) e reconciliar as comandas e entregas abertas. Não executar as migrations manualmente contra dados operacionais.

O automático só vale após ativação explícita com aceite de risco e setor padrão ativo; a preferência da Fase 3 permanece manual. Evidências em `evidencias-fase-4/`; as evidências das fases anteriores permanecem intactas.

## Complemento do Bloco B — catálogo online

O harness inclui `tests/Concurrency/online.php` (O1–O4) após o guard, com os tipos de job `placeonline`, `acceptonline` e `expireonline`: oito envios do mesmo `client_uuid` (um pedido, um número, uma auditoria `created` e o mesmo link para todos), aceitar × expirar no instante do prazo em 30 rodadas (ou aceito com 1 delivery, ou expirado sem delivery; nunca os dois), aceitar × esgotar o produto nas duas ordens e oito aceites do mesmo pedido (1 delivery, 1 `ProductionWork` por linha). Todos exigem zero deadlocks 1213 e zero timeouts 1205. Os cenários anteriores (C1–C9, F1–F5, R1–R5, P1–P5) continuam no mesmo orquestrador. O envio roda sem usuário logado e com o ator de auditoria do cliente, como na rota pública.

Execução completa no Windows: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode All` (ou `-Mode Concurrency` só para o harness). SQLite: `php artisan test --compact` (os 2 testes de trigger MariaDB ficam ignorados). Cliente: `node --test tests/Frontend/Composition.test.mjs`, `node --test tests/Frontend/MenuRecovery.test.mjs` e `node --test tests/Frontend/ShopCart.test.mjs`. Build: `npm run build`, separado dos testes HTTP. No PowerShell: `composer validate --strict`.

As migrations `2026_11_10_000001` e `2026_11_10_000002` são aditivas e recusam rollback com dados. A implantação precisa de `php artisan storage:link` para as imagens dos produtos e do agendamento da expiração dos pedidos online.

## Complemento do Bloco C — WhatsApp

As notificações dos pedidos do catálogo online saem pela Evolution API. A implantação precisa das variáveis `EVOLUTION_URL` e `EVOLUTION_API_KEY` (lidas em `config('services.evolution.url')` e `config('services.evolution.key')`); sem elas o recurso fica indisponível e os pedidos seguem normalmente. O envio usa a fila: é preciso o worker (`php artisan queue:work`) e o agendador do Laravel, que executa `online-orders:expire` e `whatsapp:sweep`. O número da loja é conectado pelo administrador na tela "WhatsApp" (leitura do QR code); o token da instância fica cifrado.

Falha, timeout, Evolution não configurada ou número desconectado nunca desfazem nem atrasam o pedido. O painel de pedidos online mostra um alerta quando o WhatsApp está desconectado e quando há mensagens não enviadas nas últimas 24 horas; a página de acompanhamento só menciona o WhatsApp com o número conectado.

Verificação: `php artisan test --compact` (os 2 testes de trigger MariaDB ficam ignorados), `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode All`, `vendor/bin/pint --dirty --format agent`, `node --test tests/Frontend/Composition.test.mjs`, `node --test tests/Frontend/MenuRecovery.test.mjs`, `node --test tests/Frontend/ShopCart.test.mjs`, `npm run build` (separado dos testes HTTP) e, no PowerShell, `composer validate --strict`.
