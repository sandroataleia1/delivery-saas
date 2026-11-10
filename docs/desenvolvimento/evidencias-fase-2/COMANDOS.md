# Comandos e interpretação — Fase 2

Código verificado: `cdf1927`, branch `phase2/financial-integrity`, base `dfc91ed`. Fechamento documental em 07/11/2026. Lockfiles preservados. Dependências já instaladas pela Fase 1; nenhuma atualização de pacotes.

```powershell
php artisan test --compact
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode All
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/testing/Invoke-DisposableMariaDb.ps1 -Mode Suite
php vendor/bin/pint --test --format=agent
npm run build
composer validate --strict
composer check-platform-reqs
composer audit --locked --format=json
npm audit --omit=dev --json
git -c safe.directory=C:/xampp/htdocs/delivery-saas diff --check
```

`-Mode All` produziu a concorrência final. A última execução `-Mode Suite` ocorreu após a correção das observações no presenter e não substituiu o arquivo de concorrência. O build foi executado após terminar os testes HTTP; depois da correção, ambas as suítes completas passaram novamente. Os comandos de suíte, build, formatação, requisitos e auditorias finalizaram com código zero.

O PHPUnit usa o formato compacto de agente instalado no projeto: cada arquivo final contém o resultado agregado com casos, asserções e duração. `red-green.txt` é saída do sumarizador da Fase 1 sobre logs intermediários preservados em `.testing/`; documenta falhas esperadas durante a implementação, não falhas finais.

O harness cria sua própria instância MariaDB, schema, usuário restrito, chave e marcador temporários. O guard foi executado antes de PHPUnit/migrations/concorrência. Esses segredos/marcadores e bancos não integram as evidências. Versão, engine e isolamento efetivos constam em `mariadb-metadata.json`.

C1–C8 e F1–F5 passaram nas invariantes verificadas. C9 tem integridade aprovada e falha operacional conhecida AUD-020: 60 deadlocks forçados. Não interpretar saída zero do orquestrador como correção de AUD-020.

No Windows, as ferramentas que consultam Git receberam `safe.directory` somente por configuração do processo; a configuração global não foi alterada. Uma primeira chamada do Composer sem essa configuração emitiu erro de ownership do Git; a execução final foi repetida com a configuração local ao processo, com saída zero e arquivos aqui registrados.

As evidências foram normalizadas para UTF-8, LF e remoção de espaços finais, sem alterar os resultados. A representação de stderr pelo PowerShell é preservada: o aviso opcional de `fontaine` no build e a mensagem de verificação da plataforma não mudaram os códigos de saída zero.

`SHA256SUMS.txt` contém hashes dos demais arquivos deste diretório, para verificar integridade das evidências entregues. Não contém o próprio hash.
