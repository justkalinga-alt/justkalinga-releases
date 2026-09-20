$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$profileMarker = 'jk-youtube-community-mcp\.youtube-profile'
$locked = Get-CimInstance Win32_Process -Filter "Name = 'chrome.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine -like "*$profileMarker*" }

foreach ($proc in $locked) {
    try {
        Stop-Process -Id $proc.ProcessId -Force -ErrorAction Stop
    } catch {}
}

Start-Sleep -Seconds 2
npm.cmd start
