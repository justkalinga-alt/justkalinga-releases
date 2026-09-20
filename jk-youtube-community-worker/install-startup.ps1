$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$taskName = 'JK YouTube Community Worker'
$startScript = Join-Path $PSScriptRoot 'start.ps1'
$powershell = (Get-Command powershell.exe).Source

$action = New-ScheduledTaskAction -Execute $powershell -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $startScript + '"')
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Description 'Polls JK Social Hub and publishes due YouTube Community posts through the authenticated Playwright profile.' -Force | Out-Null

Write-Host ('Installed Windows logon task: ' + $taskName) -ForegroundColor Green
Write-Host 'The worker will start automatically when this Windows user signs in.'
