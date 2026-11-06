param(
    [string]$MariaDbBin = 'C:\xampp\mysql\bin',
    [ValidateSet('Suite', 'Concurrency', 'All')][string]$Mode = 'Suite',
    [string[]]$PhpUnitArguments = @()
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
$instanceRoot = Join-Path $tempRoot ('delivery-test-' + [guid]::NewGuid().ToString('N'))
$dataDirectory = Join-Path $instanceRoot 'data'
$markerPath = Join-Path $instanceRoot 'marker.json'
$evidenceRoot = Join-Path $projectRoot '.testing'
$listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
$listener.Start()
$testPort = $listener.LocalEndpoint.Port
$listener.Stop()
$token = [guid]::NewGuid().ToString('N')
$database = 'delivery_test_' + [guid]::NewGuid().ToString('N').Substring(0, 16)
$testEnvironment = @{
    APP_ENV='testing'; APP_DEBUG='false'; APP_KEY=('base64:' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($token)))
    DB_CONNECTION='mariadb'; DB_HOST='127.0.0.1'; DB_PORT=[string]$testPort; DB_DATABASE=$database
    DB_USERNAME='delivery_test'; DB_PASSWORD=$token; DB_URL=''; DB_SOCKET=''; CACHE_STORE='array'; SESSION_DRIVER='array'
    QUEUE_CONNECTION='sync'; MAIL_MAILER='array'; BCRYPT_ROUNDS='4'; DISPOSABLE_DB_MARKER=$markerPath
    DISPOSABLE_DB_TOKEN=$token; DISPOSABLE_ADMIN_PASSWORD=([guid]::NewGuid().ToString('N'))
}
$previousEnvironment = @{}
$serverProcess = $null
$exitStatus = 1
New-Item -ItemType Directory -Path $instanceRoot | Out-Null
New-Item -ItemType Directory -Force -Path $evidenceRoot | Out-Null
Push-Location $projectRoot
try {
    foreach ($name in $testEnvironment.Keys) {
        $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $testEnvironment[$name], 'Process')
    }
    $marker = @{ port=$testPort; database=$database; directory=$dataDirectory; token=$token }
    $marker | ConvertTo-Json | Set-Content -Encoding ASCII -LiteralPath $markerPath
    & (Join-Path $MariaDbBin 'mysql_install_db.exe') "--datadir=$dataDirectory" "--password=$env:DISPOSABLE_ADMIN_PASSWORD" "--port=$testPort" --silent > (Join-Path $evidenceRoot 'mariadb-init.txt') 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Failed to initialize disposable MariaDB' }
    $serverArguments = @('--no-defaults', ('--basedir="' + (Split-Path $MariaDbBin) + '"'), ('--datadir="' + $dataDirectory + '"'), "--port=$testPort", '--bind-address=127.0.0.1', '--skip-name-resolve', '--innodb-flush-log-at-trx-commit=2', '--innodb-lock-wait-timeout=15', '--console')
    $serverProcess = Start-Process -FilePath (Join-Path $MariaDbBin 'mysqld.exe') -ArgumentList $serverArguments -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $evidenceRoot 'mariadb-server.txt') -RedirectStandardError (Join-Path $evidenceRoot 'mariadb-server-error.txt')
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        if ($serverProcess.HasExited) { throw 'Disposable server exited; inspect .testing/mariadb-server-error.txt' }
        $client = New-Object Net.Sockets.TcpClient
        try { $client.Connect('127.0.0.1', $testPort); $ready = $true } catch { Start-Sleep -Milliseconds 500 } finally { $client.Dispose() }
        if ($ready) { break }
    }
    if (-not $ready) { throw 'Disposable server did not become ready' }
    & php scripts/testing/provision-disposable-database.php > (Join-Path $evidenceRoot 'mariadb-metadata.json') 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Provisioning refused; inspect .testing/mariadb-metadata.json' }
    & php scripts/testing/assert-disposable-database.php
    if ($LASTEXITCODE -ne 0) { throw 'Database guard refused target' }
    $exitStatus = 0
    if ($Mode -in @('Suite', 'All')) {
        & php vendor/phpunit/phpunit/phpunit -c phpunit.mariadb.xml @PhpUnitArguments > (Join-Path $evidenceRoot 'mariadb-suite.txt') 2>&1
        $exitStatus = $LASTEXITCODE
        & php scripts/testing/summarize-results.php (Join-Path $evidenceRoot 'mariadb-suite.txt')
    }
    if ($Mode -in @('Concurrency', 'All')) {
        & php tests/Concurrency/run.php > (Join-Path $evidenceRoot 'concurrency.jsonl') 2>&1
        if ($LASTEXITCODE -ne 0) { $exitStatus = $LASTEXITCODE }
        Get-Content (Join-Path $evidenceRoot 'concurrency.jsonl') -Tail 10
    }
} finally {
    if ($serverProcess -and -not $serverProcess.HasExited) {
        Stop-Process -Id $serverProcess.Id -Force
        $serverProcess.WaitForExit()
    }
    foreach ($name in $previousEnvironment.Keys) { [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process') }
    Pop-Location
    $resolvedInstance = [IO.Path]::GetFullPath($instanceRoot)
    $savedMarker = Get-Content -Raw -LiteralPath $markerPath | ConvertFrom-Json
    if (-not $resolvedInstance.StartsWith($tempRoot + '\', [StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolvedInstance -Leaf) -notlike 'delivery-test-*' -or $savedMarker.token -ne $token) {
        throw 'Cleanup refused: unexpected temporary directory or marker'
    }
    Remove-Item -LiteralPath $resolvedInstance -Recurse -Force
}
exit $exitStatus
