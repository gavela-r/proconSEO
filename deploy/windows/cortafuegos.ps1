<#
.SYNOPSIS
    Cortafuegos de salida de proconSEO con Windows Defender Firewall (SEG-12, docs/despliegue.md, sección 7).

.DESCRIPTION
    Es una segunda barrera por si fallara la validación del código: aunque alguien consiguiera
    colar una dirección interna, la conexión no saldría.

    - php.exe (el worker, que descarga las webs): no puede conectarse a redes privadas ni a
      puertos que no sean 80 y 443.
    - php-cgi.exe (la web, en IIS): no puede conectarse a nada fuera del propio servidor.

    El DNS no se ve afectado: lo resuelve el servicio Cliente DNS de Windows, no PHP. El tráfico
    al propio servidor (MySQL en 127.0.0.1) tampoco, porque Windows no filtra el bucle local.
    Por eso MySQL tiene que estar en el mismo servidor: en Windows Firewall un bloqueo siempre gana
    a un permiso, y una IP de la red local quedaría bloqueada.

.EXAMPLE
    .\cortafuegos.ps1 -PhpDir C:\php
#>
[CmdletBinding()]
param(
    [string] $PhpDir = 'C:\php'
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Ejecuta este script como administrador.'
}
$worker = Join-Path $PhpDir 'php.exe'
$web = Join-Path $PhpDir 'php-cgi.exe'
foreach ($fichero in @($worker, $web)) {
    if (-not (Test-Path -LiteralPath $fichero)) { throw "No existe $fichero. Revisa -PhpDir." }
}

$grupo = 'proconSEO'
$internas = @(
    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '172.16.0.0/12',
    '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/3',
    'fc00::/7', 'fe80::/10', 'ff00::/8'
)

# Se rehacen desde cero cada vez, para no dejar reglas antiguas.
Get-NetFirewallRule -Group $grupo -ErrorAction SilentlyContinue | Remove-NetFirewallRule

New-NetFirewallRule -Group $grupo -DisplayName 'proconSEO worker: sin redes internas' -Direction Outbound `
    -Program $worker -RemoteAddress $internas -Action Block | Out-Null
New-NetFirewallRule -Group $grupo -DisplayName 'proconSEO worker: solo puertos 80 y 443 (TCP)' -Direction Outbound `
    -Program $worker -Protocol TCP -RemotePort '1-79', '81-442', '444-65535' -Action Block | Out-Null
New-NetFirewallRule -Group $grupo -DisplayName 'proconSEO worker: sin UDP' -Direction Outbound `
    -Program $worker -Protocol UDP -Action Block | Out-Null
New-NetFirewallRule -Group $grupo -DisplayName 'proconSEO web: sin salida a la red' -Direction Outbound `
    -Program $web -RemoteAddress 'Internet', 'Intranet', 'LocalSubnet' -Action Block | Out-Null

Write-Host "Reglas creadas (grupo «$grupo»). Para comprobarlo, como la cuenta del worker:"
Write-Host "  & '$worker' -r `"var_dump(@file_get_contents('http://192.168.1.1/'));`"   -> tiene que fallar"
Write-Host "  & '$worker' `"C:\proconseo\bin\comprobar.php`" --red                     -> tiene que dar OK en la conexión https"
