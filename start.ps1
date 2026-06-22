# La Casa Volvo — Iniciar servidores locales
# Uso: .\start.ps1

$php = "C:\Users\Rene_\.config\herd\bin\php83\php.exe"
# Rutas relativas al propio script (ubicación-independiente): el repo se mudó
# de D:\Sitios Web\lacasavolvo a D:\softlat-clientes\la-casa-volvo\sistema-erp.
$apiRoot = Join-Path $PSScriptRoot "api"
$frontRoot = Join-Path $PSScriptRoot "front"

Write-Host "=== La Casa Volvo — Local Dev ===" -ForegroundColor Cyan

# Liberar puertos
Write-Host "Liberando puertos 8000 y 3000..." -ForegroundColor DarkGray
@(8000, 3000) | ForEach-Object {
    $p = $_;
    netstat -ano | Select-String ":$p " | ForEach-Object {
        $parts = $_ -replace '\s+', ' ' -split ' ';
        $pid = $parts[-1];
        if ($pid -match '^\d+$') {
            Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue;
            Write-Host "  Puerto $p : PID $pid liberado" -ForegroundColor DarkGray
        }
    }
}
Start-Sleep 1

# Backend — PHP built-in server con router.php
Write-Host "Backend :8000..." -ForegroundColor Green
Start-Process -NoNewWindow $php -ArgumentList "-S 127.0.0.1:8000 -t `"$apiRoot\public`" `"$apiRoot\router.php`""

# Frontend — Vite
Write-Host "Frontend :3000..." -ForegroundColor Green
Set-Location $frontRoot
npm run dev

Write-Host ""
Write-Host "Backend:  http://localhost:8000" -ForegroundColor Yellow
Write-Host "Frontend: http://localhost:3000" -ForegroundColor Yellow
