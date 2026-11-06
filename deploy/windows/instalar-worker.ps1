<#
.SYNOPSIS
    Deja el worker de proconSEO como tarea programada de Windows: arranca con el servidor,
    se reinicia si falla y funciona aunque nadie haya iniciado sesión (docs/despliegue.md, sección 8).

.DESCRIPTION
    Ejecutar como administrador, una sola vez. Pide la contraseña de la cuenta del worker
    (no se guarda en ningún fichero; la guarda Windows en el Programador de tareas).

.EXAMPLE
    .\instalar-worker.ps1 -Ruta C:\proconseo -Php C:\php\php.exe -Usuario proconseo-worker
#>
[CmdletBinding()]
param(
    [string] $Ruta = 'C:\proconseo',
    [string] $Php = 'C:\php\php.exe',
    [string] $Usuario = 'proconseo-worker',
    [string] $NombreTarea = 'proconSEO worker'
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Ejecuta este script como administrador.'
}
foreach ($fichero in @($Php, (Join-Path $Ruta 'bin\worker.php'))) {
    if (-not (Test-Path -LiteralPath $fichero)) { throw "No existe $fichero. Revisa -Ruta y -Php." }
}

$credencial = Get-Credential -UserName "$env:COMPUTERNAME\$Usuario" -Message "Contraseña de la cuenta $Usuario (la que ejecuta el worker)"

$accion = New-ScheduledTaskAction -Execute $Php -Argument "`"$(Join-Path $Ruta 'bin\worker.php')`"" -WorkingDirectory $Ruta
$disparador = New-ScheduledTaskTrigger -AtStartup
$ajustes = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

Register-ScheduledTask -TaskName $NombreTarea -Action $accion -Trigger $disparador -Settings $ajustes `
    -User $credencial.UserName -Password $credencial.GetNetworkCredential().Password -RunLevel Limited `
    -Description 'Analiza las webs en cola y lanza los análisis programados de proconSEO.' -Force | Out-Null

Start-ScheduledTask -TaskName $NombreTarea
Start-Sleep -Seconds 3
$estado = (Get-ScheduledTask -TaskName $NombreTarea).State
Write-Host "Tarea «$NombreTarea» creada y en marcha (estado: $estado)."
Write-Host "Para comprobarlo: php `"$(Join-Path $Ruta 'bin\comprobar.php')`""
