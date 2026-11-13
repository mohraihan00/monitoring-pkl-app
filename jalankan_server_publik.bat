@echo off
title SMART PKL - Server Publik (Laravel + Cloudflare Tunnel)
echo ========================================================
echo   Menjalankan Server Laravel + Cloudflare Tunnel Publik
echo ========================================================
echo.

cd /d "%~dp0\monitoring-pkl-app"
start "Laravel Backend (Port 8000)" cmd /c "php artisan serve --host=0.0.0.0 --port=8000"

timeout /t 3 /nobreak > nul

cd /d "%~dp0"
if exist "cloudflared.log" del "cloudflared.log"
start "Cloudflare Tunnel" cmd /c "cloudflared.exe tunnel --url http://127.0.0.1:8000 --logfile cloudflared.log --no-autoupdate"

echo Menunggu URL publik dari Cloudflare...
set "TUNNEL_URL="
for /l %%i in (1,1,15) do (
    timeout /t 2 /nobreak > nul
    for /f "usebackq delims=" %%u in (`powershell -NoProfile -Command "$m = Select-String -Path 'cloudflared.log' -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches -ErrorAction SilentlyContinue; if ($m) { $m.Matches[-1].Value }"`) do (
        set "TUNNEL_URL=%%u"
    )
    if defined TUNNEL_URL goto :found_url
)

:found_url
if defined TUNNEL_URL (
    echo.
    echo URL Publik Aktif: %TUNNEL_URL%
    curl.exe -s -d "%TUNNEL_URL%/api" "https://ntfy.sh/smart_pkl_smkn2_2800eab1" > nul
    echo URL otomatis dipublikasikan ke APK Android (auto-discovery).
) else (
    echo.
    echo [Peringatan] Gagal mendeteksi URL trycloudflare.com. Cek file cloudflared.log.
)

echo.
echo IP Laptop (untuk Wi-Fi lokal):
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4 Address" /c:"Alamat IPv4"') do (
    echo   -^> %%a
)
echo.
echo Biarkan jendela Laravel Backend dan Cloudflare Tunnel tetap terbuka.
echo ========================================================
pause

