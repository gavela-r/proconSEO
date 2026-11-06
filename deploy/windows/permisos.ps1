<#
.SYNOPSIS
    Permisos de carpetas de proconSEO (SEG-13, docs/despliegue.md, sección 5).

.DESCRIPTION
    - La web (la identidad del grupo de aplicaciones de IIS) y el worker solo pueden LEER el código.
    - Solo pueden escribir en storage\.
    - .env solo lo pueden leer ellos dos y los administradores.
    Ejecutar como administrador después de copiar el código y crear el .env.

.EXAMPLE
    .\permisos.ps1 -Ruta C:\proconseo -GrupoIis proconseo -Usuario proconseo-worker
#>
[CmdletBinding()]
param(
    [string] $Ruta = 'C:\proconseo',
    [string] $GrupoIis = 'proconseo',
    [string] $Usuario = 'proconseo-worker'
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Ejecuta este script como administrador.'
}
if (-not (Test-Path -LiteralPath (Join-Path $Ruta 'public\index.php'))) {
    throw "No parece la carpeta de proconSEO: falta $Ruta\public\index.php."
}

$web = "IIS AppPool\$GrupoIis"
$worker = "$env:COMPUTERNAME\$Usuario"
$storage = Join-Path $Ruta 'storage'
$ficheroEnv = Join-Path $Ruta '.env'

function Ejecutar([string[]] $argumentos) {
    & icacls @argumentos | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls $($argumentos -join ' ') ha fallado." }
}

# Código: sin herencia de permisos amplios; Administradores (S-1-5-32-544) y SYSTEM (S-1-5-18) con control
# total, por su SID para que funcione en Windows en cualquier idioma; web y worker solo lectura.
Ejecutar @($Ruta, '/inheritance:r', '/grant:r', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F', "${web}:(OI)(CI)RX", "${worker}:(OI)(CI)RX")

# storage\: lo único en lo que pueden escribir.
New-Item -ItemType Directory -Force -Path (Join-Path $storage 'capturas') | Out-Null
Ejecutar @($storage, '/grant:r', "${web}:(OI)(CI)M", "${worker}:(OI)(CI)M")

# .env: solo lectura para ellos dos; nadie más.
if (Test-Path -LiteralPath $ficheroEnv) {
    Ejecutar @($ficheroEnv, '/inheritance:r', '/grant:r', '*S-1-5-32-544:F', '*S-1-5-18:F', "${web}:R", "${worker}:R")
} else {
    Write-Warning "Todavía no hay ${ficheroEnv}: crea el .env y vuelve a ejecutar este script."
}

Write-Host 'Permisos aplicados. La web y el worker solo pueden escribir en storage\.'
