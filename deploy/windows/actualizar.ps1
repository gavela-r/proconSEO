<#
.SYNOPSIS
    Actualiza proconSEO a la última versión de main (docs/despliegue.md, sección 10).

.DESCRIPTION
    Descarga el código, instala dependencias, aplica las migraciones, comprueba la instalación y
    reinicia el worker. Si la comprobación da errores, para y no reinicia nada.
    Las migraciones necesitan el usuario de MySQL que puede cambiar tablas (proconseo_admin):
    el script pide su contraseña y la pasa solo a ese proceso, sin guardarla.

.EXAMPLE
    .\actualizar.ps1 -Ruta C:\proconseo -Php C:\php\php.exe
#>
[CmdletBinding()]
param(
    [string] $Ruta = 'C:\proconseo',
    [string] $Php = 'C:\php\php.exe',
    [string] $UsuarioAdmin = 'proconseo_admin',
    [string] $NombreTarea = 'proconSEO worker'
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $Ruta

function Paso([string] $texto, [scriptblock] $orden) {
    Write-Host "`n== $texto"
    & $orden
    if ($LASTEXITCODE -ne 0) { throw "Ha fallado: $texto. No se ha reiniciado nada." }
}

Paso 'Descargar el código' { git pull --ff-only }
Paso 'Instalar dependencias' { composer install --no-dev --optimize-autoloader --no-interaction }

$credencial = Get-Credential -UserName $UsuarioAdmin -Message 'Usuario de MySQL que puede cambiar tablas (para las migraciones)'
$env:DB_USER = $credencial.UserName
$env:DB_PASS = $credencial.GetNetworkCredential().Password
try {
    Paso 'Aplicar migraciones' { & $Php bin\migrar.php }
} finally {
    Remove-Item Env:DB_USER, Env:DB_PASS -ErrorAction SilentlyContinue
}

Paso 'Comprobar la instalación' { & $Php bin\comprobar.php --produccion }

Write-Host "`n== Reiniciar el worker"
Stop-ScheduledTask -TaskName $NombreTarea
Start-ScheduledTask -TaskName $NombreTarea
Write-Host 'Actualizado. El worker ya usa el código nuevo.'
