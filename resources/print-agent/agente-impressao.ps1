# Agente de impressao - Delivery
#
# Busca no sistema os papeis a imprimir (comandas da cozinha, pedidos, testes)
# e envia para as impressoras termicas da loja:
#   - impressora de rede: direto no IP, porta 9100;
#   - impressora do Windows: pelo nome ou pelo caminho \\COMPUTADOR\Compartilhamento.
#
# Como usar: clique com o botao direito neste arquivo e escolha "Executar com o PowerShell".
# Ele se instala nesta conta do Windows, passa a abrir sozinho ao ligar o computador
# e fica rodando escondido. Para reinstalar ou trocar a chave, baixe o agente de novo no sistema.

param([switch]$Run)

$ErrorActionPreference = 'Stop'
$Server = '__SERVER__'
$Token = '__TOKEN__'
$AppName = 'AgenteImpressaoDelivery'
$InstallDir = Join-Path $env:LOCALAPPDATA $AppName
$InstalledScript = Join-Path $InstallDir 'agente-impressao.ps1'
$LogFile = Join-Path $InstallDir 'agente.log'
$StartupFile = Join-Path ([Environment]::GetFolderPath('Startup')) "$AppName.cmd"

function Stop-OldAgents {
    Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
        Where-Object { $_.CommandLine -like "*$AppName*" -and $_.ProcessId -ne $PID } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

function Install-Agent {
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
    Stop-OldAgents
    Copy-Item -LiteralPath $PSCommandPath -Destination $InstalledScript -Force
    $launcher = "@echo off`r`nstart `"`" powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$InstalledScript`" -Run`r`n"
    [IO.File]::WriteAllText($StartupFile, $launcher, [Text.Encoding]::ASCII)
    Start-Process powershell.exe -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$InstalledScript`"", '-Run')
    Write-Host ''
    Write-Host 'Agente de impressao instalado e ligado.' -ForegroundColor Green
    Write-Host 'Ele abre sozinho quando o Windows iniciar. Confira o status na tela Impressoras do sistema.'
    Write-Host "Registro de atividade: $LogFile"
    Write-Host ''
    Read-Host 'Pressione Enter para fechar'
}

function Write-Log([string]$message) {
    try {
        if ((Test-Path $LogFile) -and (Get-Item $LogFile).Length -gt 1MB) {
            Move-Item -LiteralPath $LogFile -Destination "$LogFile.old" -Force
        }
        Add-Content -LiteralPath $LogFile -Value ("{0:yyyy-MM-dd HH:mm:ss}  {1}" -f (Get-Date), $message)
    } catch {
    }
}

$RawPrinterSource = @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;

public static class DeliveryRawPrinter
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public class DocInfo
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string DocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string OutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string DataType;
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);

    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool ClosePrinter(IntPtr handle);

    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool StartDocPrinter(IntPtr handle, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DocInfo info);

    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool EndDocPrinter(IntPtr handle);

    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool StartPagePrinter(IntPtr handle);

    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool EndPagePrinter(IntPtr handle);

    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool WritePrinter(IntPtr handle, byte[] bytes, int count, out int written);

    public static void Send(string printer, byte[] data, string title)
    {
        IntPtr handle;
        if (!OpenPrinter(printer, out handle, IntPtr.Zero)) throw new Win32Exception(Marshal.GetLastWin32Error(), "Impressora nao encontrada: " + printer);
        try
        {
            DocInfo info = new DocInfo();
            info.DocName = title;
            info.DataType = "RAW";
            if (!StartDocPrinter(handle, 1, info)) throw new Win32Exception(Marshal.GetLastWin32Error());
            try
            {
                StartPagePrinter(handle);
                int written;
                if (!WritePrinter(handle, data, data.Length, out written)) throw new Win32Exception(Marshal.GetLastWin32Error());
                EndPagePrinter(handle);
            }
            finally { EndDocPrinter(handle); }
        }
        finally { ClosePrinter(handle); }
    }
}
'@

function Send-ToNetwork([string]$address, [int]$port, [byte[]]$bytes) {
    $client = New-Object Net.Sockets.TcpClient
    try {
        $attempt = $client.BeginConnect($address, $port, $null, $null)
        if (-not $attempt.AsyncWaitHandle.WaitOne(5000)) { throw "Impressora $($address):$port nao respondeu." }
        $client.EndConnect($attempt)
        $stream = $client.GetStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Flush()
    } finally {
        $client.Close()
    }
}

function Invoke-Api([string]$method, [string]$path, $body = $null) {
    $params = @{
        Method = $method
        Uri = "$Server/print-agent/$path"
        Headers = @{ Authorization = "Bearer $Token"; Accept = 'application/json' }
        TimeoutSec = 20
    }
    if ($null -ne $body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($body | ConvertTo-Json -Compress))
    }
    Invoke-RestMethod @params
}

function Start-Agent {
    $created = $false
    $mutex = New-Object Threading.Mutex($true, "Local\$AppName", [ref]$created)
    if (-not $created) { return }

    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Add-Type -TypeDefinition $RawPrinterSource
    Write-Log "Agente iniciado. Servidor: $Server"
    $waitSeconds = 3

    while ($true) {
        try {
            $response = Invoke-Api 'GET' 'jobs'
            $waitSeconds = 3
            foreach ($job in $response.jobs) {
                try {
                    $bytes = [Convert]::FromBase64String($job.payload)
                    if ($job.printer.connection -eq 'network') {
                        Send-ToNetwork $job.printer.address ([int]$job.printer.port) $bytes
                    } else {
                        [DeliveryRawPrinter]::Send($job.printer.address, $bytes, $job.title)
                    }
                    Invoke-Api 'POST' "jobs/$($job.id)/printed" | Out-Null
                    Write-Log "Impresso: $($job.title) em $($job.printer.name)"
                } catch {
                    $message = $_.Exception.Message
                    Write-Log "Falha: $($job.title) em $($job.printer.name): $message"
                    try { Invoke-Api 'POST' "jobs/$($job.id)/failed" @{ error = $message } | Out-Null } catch { }
                }
            }
        } catch {
            $status = $null
            if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
            if ($status -eq 401) {
                Write-Log 'Chave recusada: um agente mais novo foi baixado no sistema. Este agente vai parar.'
                return
            }
            Write-Log "Sem contato com o servidor: $($_.Exception.Message)"
            $waitSeconds = [Math]::Min($waitSeconds * 2, 60)
        }
        Start-Sleep -Seconds $waitSeconds
    }
}

if ($Run) {
    Start-Agent
} else {
    Install-Agent
}
