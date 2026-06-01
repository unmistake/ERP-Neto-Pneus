param(
    [string]$Hostname = "erp-netorodas.online",
    [string]$ProjectRoot = "D:\xampp\htdocs\ERP",
    [string]$ApacheRoot = "D:\xampp\apache"
)

$ErrorActionPreference = "Stop"

function Write-Step($message) {
    Write-Host ""
    Write-Host "==> $message" -ForegroundColor Cyan
}

$httpdConf = Join-Path $ApacheRoot "conf\httpd.conf"
$vhostsConf = Join-Path $ApacheRoot "conf\extra\httpd-vhosts.conf"

if (-not (Test-Path $httpdConf)) {
    throw "Nao encontrei httpd.conf em $httpdConf"
}

if (-not (Test-Path $vhostsConf)) {
    throw "Nao encontrei httpd-vhosts.conf em $vhostsConf"
}

Write-Step "Habilitando include de VirtualHosts no Apache"
$httpdContent = Get-Content $httpdConf -Raw
$httpdContent = $httpdContent -replace "(?m)^#\s*Include\s+conf/extra/httpd-vhosts\.conf\s*$", "Include conf/extra/httpd-vhosts.conf"
Set-Content -Path $httpdConf -Value $httpdContent -Encoding ASCII

Write-Step "Configurando VirtualHost para $Hostname"
$projectRootApache = $ProjectRoot -replace "\\", "/"
$markerStart = "# BEGIN ERP_NETORODAS_ONLINE"
$markerEnd = "# END ERP_NETORODAS_ONLINE"
$vhostBlock = @"

$markerStart
<VirtualHost *:80>
    ServerName $Hostname
    DocumentRoot "$projectRootApache"

    <Directory "$projectRootApache">
        Options Indexes FollowSymLinks Includes ExecCGI
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
$markerEnd
"@

$vhostsContent = Get-Content $vhostsConf -Raw
$pattern = "(?s)\Q$markerStart\E.*?\Q$markerEnd\E"
if ($vhostsContent -match [regex]::Escape($markerStart)) {
    $vhostsContent = [regex]::Replace($vhostsContent, $pattern, $vhostBlock.Trim())
} else {
    $vhostsContent = $vhostsContent.TrimEnd() + $vhostBlock
}

Set-Content -Path $vhostsConf -Value $vhostsContent -Encoding ASCII

Write-Step "VirtualHost configurado"
Write-Host "Reinicie o Apache no painel do XAMPP."
Write-Host "Depois, com o Cloudflare Tunnel ativo, acesse:"
Write-Host "https://$Hostname" -ForegroundColor Green
