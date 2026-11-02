<#
.SYNOPSIS
  Registers the bakery-ops print bridge with the Windows Task Scheduler (phase-5 plan 5.3b, docs/runbook/print-bridge.md).

.DESCRIPTION
  No third-party service wrapper: a scheduled task starts "node src\main.js --config <file>" when the computer starts, runs it without
  a time limit and starts it again a minute after it stops on an error. Before registering, the script checks Node (22 or later), the
  config and the token (print-bridge --check), and narrows the token file to SYSTEM and Administrators (and the chosen user).
  Run it from an elevated PowerShell. Running it again replaces the task (to change -Serve or the config path).

.PARAMETER ConfigPath
  bridge.config.json (default: the one beside this script).

.PARAMETER Serve
  Also run the local mode for the till page on this computer, for example 127.0.0.1:9180 (plan 10.2 D11).

.PARAMETER RunAs
  System (default): the task runs as SYSTEM from start-up, nobody needs to be logged on. CurrentUser: the task runs when this user logs
  on, which a Windows shared printer connected only for that user may need.

.PARAMETER SkipCheck
  Register without asking the API whether it takes the token (for a computer that is offline while it is set up).

.PARAMETER Uninstall
  Stop and remove the task. The files stay.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\install-service.ps1

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\install-service.ps1 -Serve 127.0.0.1:9180

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\install-service.ps1 -Uninstall
#>
[CmdletBinding()]
param(
    [string]$TaskName = 'BakeryOpsPrintBridge',
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'bridge.config.json'),
    [string]$NodePath = '',
    [string]$Serve = '',
    [ValidateSet('System', 'CurrentUser')]
    [string]$RunAs = 'System',
    [switch]$SkipCheck,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0

function Test-Admin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Admin)) {
    throw 'Run this script from an elevated PowerShell (Run as administrator).'
}

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if ($Uninstall) {
    if ($null -eq $existing) {
        Write-Host "No task named $TaskName."
        exit 0
    }
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "Removed the task $TaskName. The files in $PSScriptRoot stay."
    exit 0
}

# Node 22 or later
if ($NodePath -eq '') {
    $command = Get-Command node -ErrorAction SilentlyContinue
    if ($null -eq $command) {
        throw 'node was not found. Install Node.js 22 LTS or later (nodejs.org), or pass -NodePath.'
    }
    $NodePath = $command.Source
}
if (-not (Test-Path -LiteralPath $NodePath -PathType Leaf)) {
    throw "No node at $NodePath."
}
$nodeVersion = (& $NodePath --version).Trim()
if ($nodeVersion -notmatch '^v(\d+)\.' -or [int]$Matches[1] -lt 22) {
    throw "The bridge needs Node 22 or later; $NodePath is $nodeVersion."
}

$main = Join-Path $PSScriptRoot 'src\main.js'
if (-not (Test-Path -LiteralPath $main -PathType Leaf)) {
    throw "No $main next to this script: run it from the print-bridge folder."
}
if (-not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
    throw "No config at $ConfigPath. Copy bridge.config.example.json to bridge.config.json and fill it in."
}
$ConfigPath = (Resolve-Path -LiteralPath $ConfigPath).Path

# The token file: only SYSTEM and Administrators (and the user the task runs as) may read it
$config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
if (-not ($config.PSObject.Properties.Name -contains 'tokenFile')) {
    throw "$ConfigPath names no tokenFile."
}
$tokenFile = [string]$config.tokenFile
if (-not [IO.Path]::IsPathRooted($tokenFile)) {
    $tokenFile = Join-Path (Split-Path -Parent $ConfigPath) $tokenFile
}
if (-not (Test-Path -LiteralPath $tokenFile -PathType Leaf)) {
    throw "No token file at $tokenFile. Paste the bridge token (shown once when the bridge was enrolled) into it."
}
$grants = @('*S-1-5-18:(R)', '*S-1-5-32-544:(F)')
if ($RunAs -eq 'CurrentUser') {
    $grants += "*$([Security.Principal.WindowsIdentity]::GetCurrent().User.Value):(R)"
}
& icacls.exe $tokenFile /inheritance:r /grant:r @grants | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "icacls could not narrow the access to $tokenFile."
}

if (-not $SkipCheck) {
    & $NodePath $main --config $ConfigPath --check
    if ($LASTEXITCODE -ne 0) {
        throw 'The API did not take the token (see the line above). Fix the token or the config, or pass -SkipCheck to register anyway.'
    }
}

$arguments = "`"$main`" --config `"$ConfigPath`""
if ($Serve -ne '') {
    $arguments += " --serve $Serve"
}
$action = New-ScheduledTaskAction -Execute $NodePath -Argument $arguments -WorkingDirectory $PSScriptRoot

if ($RunAs -eq 'System') {
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
} else {
    $user = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $user
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
}

# no time limit; start again a minute after a failure; one copy at a time
$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

if ($null -ne $existing) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
}
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
    -Description 'bakery-ops print bridge: pulls receipt jobs from the API and prints them (docs/runbook/print-bridge.md).' -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

Start-Sleep -Seconds 3
$info = Get-ScheduledTaskInfo -TaskName $TaskName
$state = (Get-ScheduledTask -TaskName $TaskName).State
Write-Host "Registered $TaskName ($RunAs, Node $nodeVersion): state $state, last result $($info.LastTaskResult)."
Write-Host "The log is the file named under log.file in $ConfigPath (default: logs\bridge.log beside it)."
