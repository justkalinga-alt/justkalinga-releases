$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
Write-Host 'JK YouTube Community Worker setup' -ForegroundColor Cyan
if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Node.js is not installed or not in PATH.' }
if (-not (Test-Path 'worker-config.json')) { Copy-Item 'worker-config.example.json' 'worker-config.json' }
npm.cmd install
npx.cmd playwright install chromium
Write-Host ''
Write-Host 'Setup complete.' -ForegroundColor Green
Write-Host '1. Edit worker-config.json and paste the Worker Token from JK Social Hub.'
Write-Host '2. Run .\login.ps1 once and sign in to the correct YouTube account.'
Write-Host '3. Run .\start.ps1.'
