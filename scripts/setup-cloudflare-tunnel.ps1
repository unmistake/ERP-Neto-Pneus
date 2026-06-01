param(
    [string]$TunnelName = "erp-netorodas",
    [string]$Hostname = "erp-netorodas.online",
    [string]$OriginUrl = "http://localhost:80"
)

$ErrorActionPreference = "Stop"

function Write-Step($message) {
    Write-Host ""
    Write-Host "==> $message" -ForegroundColor Cyan
}

function Invoke-CloudflaredCapture {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        return & cloudflared @Arguments 2>&1
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
}

Write-Step "Verificando cloudflared"
cloudflared --version

$cloudflaredDir = Join-Path $env:USERPROFILE ".cloudflared"
$certPath = Join-Path $cloudflaredDir "cert.pem"

if (-not (Test-Path $certPath)) {
    Write-Step "Login necessario"
    Write-Host "O navegador sera aberto para autorizar a Cloudflare."
    Write-Host "Selecione o dominio $Hostname e conclua a autorizacao."
    cloudflared tunnel login
}

Write-Step "Criando ou reutilizando tunnel $TunnelName"
$listOutput = Invoke-CloudflaredCapture tunnel list
$listText = ($listOutput | Out-String)
$existingLine = $listText -split "`r?`n" | Select-String -Pattern "\s$TunnelName\s" | Select-Object -First 1

if (-not $existingLine) {
    cloudflared tunnel create $TunnelName
}

$listOutput = Invoke-CloudflaredCapture tunnel list
$listText = ($listOutput | Out-String)
$tunnelLine = $listText -split "`r?`n" | Select-String -Pattern "\s$TunnelName\s" | Select-Object -First 1
if (-not $tunnelLine) {
    throw "Nao foi possivel localizar o tunnel $TunnelName apos a criacao."
}

$tunnelId = ($tunnelLine.ToString().Trim() -split "\s+")[0]
$credentialsPath = Join-Path $cloudflaredDir "$tunnelId.json"

if (-not (Test-Path $credentialsPath)) {
    throw "Arquivo de credenciais nao encontrado: $credentialsPath"
}

Write-Step "Criando rota DNS $Hostname"
cloudflared tunnel route dns $TunnelName $Hostname

Write-Step "Gerando config.yml"
$configPath = Join-Path $cloudflaredDir "config.yml"
$configContent = @"
tunnel: $TunnelName
credentials-file: $credentialsPath

ingress:
  - hostname: $Hostname
    service: $OriginUrl
  - service: http_status:404
"@

New-Item -ItemType Directory -Path $cloudflaredDir -Force | Out-Null
Set-Content -Path $configPath -Value $configContent -Encoding ASCII

Write-Step "Configuracao concluida"
Write-Host "Config: $configPath"
Write-Host "Para iniciar o tunnel, rode:"
Write-Host "cloudflared tunnel run $TunnelName" -ForegroundColor Green
Write-Host ""
Write-Host "Se o Apache ainda estiver servindo o ERP em /ERP, acesse inicialmente:"
Write-Host "https://$Hostname/ERP" -ForegroundColor Yellow
