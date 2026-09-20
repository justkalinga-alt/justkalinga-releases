$ErrorActionPreference = 'Stop'
$taskName = 'JK YouTube Community Worker'
$task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($task) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host ('Removed Windows task: ' + $taskName) -ForegroundColor Green
} else {
    Write-Host 'Worker startup task is not installed.'
}
