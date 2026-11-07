<#
.SYNOPSIS
    Arranca proconSEO en tu ordenador para probarlo: servidor web y worker, y abre el navegador.

.DESCRIPTION
    Solo para desarrollo, en local. En el servidor de Proconsi se usa IIS y el worker como tarea
    programada (docs/despliegue.md).

    Antes de arrancar comprueba que están PHP, MySQL, las dependencias de Composer y el .env, y
    aplica las migraciones pendientes. Después abre dos ventanas (servidor web y worker) y el
    navegador en http://localhost:8090. Al pulsar Enter en esta ventana se cierran las dos.

    No pide ni muestra contraseñas: usa las del .env.

.EXAMPLE
    Doble clic en deploy\windows\iniciar-local.cmd
    o, desde PowerShell:  .\deploy\windows\iniciar-local.ps1
#>
[CmdletBinding()]
param(
    [int] $Puerto = 8090,
    [switch] $SinNavegador
)

$ErrorActionPreference = 'Stop'
$raiz = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Set-Location $raiz

function Paso([string] $texto) { Write-Host "  - $texto" }
function Fallo([string] $texto) {
    Write-Host ''
    Write-Host "ERROR: $texto" -ForegroundColor Red
    Write-Host ''
    Read-Host 'Pulsa Enter para cerrar'
    exit 1
}

Write-Host ''
Write-Host 'proconSEO en local' -ForegroundColor White
Write-Host ''

# 1. PHP
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) { Fallo 'No se encuentra PHP. Instala PHP 8.2 o superior y añádelo al PATH.' }
Paso ("PHP: " + (& php -r 'echo PHP_VERSION;'))

# 2. .env y dependencias
if (-not (Test-Path (Join-Path $raiz '.env'))) {
    Fallo 'Falta el fichero .env. Copia .env.example como .env y rellena los datos de MySQL.'
}
if (-not (Test-Path (Join-Path $raiz 'vendor\autoload.php'))) {
    if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { Fallo 'Faltan las dependencias y no se encuentra Composer.' }
    Paso 'Instalando dependencias (composer install)...'
    & composer install --no-interaction --quiet
    if ($LASTEXITCODE -ne 0) { Fallo 'composer install ha fallado.' }
}
Paso 'Dependencias instaladas'

# 3. MySQL
$mysql = Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'MySQL*' } | Select-Object -First 1
if ($mysql -and $mysql.Status -ne 'Running') {
    Fallo "El servicio $($mysql.Name) está parado. Arráncalo desde Servicios de Windows (o con 'Start-Service $($mysql.Name)' como administrador)."
}
if ($mysql) { Paso "MySQL: servicio $($mysql.Name) en marcha" }

# 4. Base de datos al día
& php bin/migrar.php
if ($LASTEXITCODE -ne 0) { Fallo 'Las migraciones han fallado: revisa los datos de MySQL del .env.' }

# 5. ¿Ya está arrancado?
$url = "http://localhost:$Puerto"
$ocupado = Get-NetTCPConnection -LocalPort $Puerto -State Listen -ErrorAction SilentlyContinue
if ($ocupado) {
    Write-Host ''
    Write-Host "El puerto $Puerto ya está en uso: seguramente proconSEO ya está arrancado." -ForegroundColor Yellow
    if (-not $SinNavegador) { Start-Process $url }
    Read-Host 'Pulsa Enter para cerrar'
    exit 0
}

# 6. Servidor web y worker, cada uno en su ventana
$servidor = Start-Process -FilePath $php.Source -ArgumentList @('-S', "localhost:$Puerto", '-t', 'public') -WorkingDirectory $raiz -PassThru -WindowStyle Minimized
$worker = Start-Process -FilePath $php.Source -ArgumentList @('bin/worker.php') -WorkingDirectory $raiz -PassThru -WindowStyle Minimized

# Espera a que responda (máximo 10 s).
$listo = $false
for ($i = 0; $i -lt 20 -and -not $listo; $i++) {
    Start-Sleep -Milliseconds 500
    try {
        $r = Invoke-WebRequest -Uri "$url/api/salud" -UseBasicParsing -TimeoutSec 2
        $listo = $r.StatusCode -eq 200
    } catch { }
}
if (-not $listo) {
    Stop-Process -Id $servidor.Id, $worker.Id -Force -ErrorAction SilentlyContinue
    Fallo "El servidor no responde en $url. Prueba a ejecutar a mano: php -S localhost:$Puerto -t public"
}

Write-Host ''
Write-Host "Listo: $url" -ForegroundColor Green
Write-Host '  Servidor web y worker en marcha (ventanas minimizadas).'
Write-Host ''
if (-not $SinNavegador) { Start-Process $url }

Read-Host 'Pulsa Enter para parar proconSEO'
Stop-Process -Id $servidor.Id, $worker.Id -Force -ErrorAction SilentlyContinue
Write-Host 'proconSEO parado.'
