# =============================================================================
# FTP Upload Script — La Casa Volvo API
# =============================================================================
# Sube TODO el proyecto Laravel (api/) al servidor cPanel por FTP.
# Usa .NET FtpWebRequest nativo de PowerShell (sin dependencias externas).
#
# USO:
#   1. Copiar scripts/deploy/.deploy.env.example -> .deploy.env y completar las
#      credenciales reales (el archivo .deploy.env esta gitignored).
#   2. Ejecutar: powershell -ExecutionPolicy Bypass -File scripts/deploy/upload-ftp.ps1
#
# NOTAS:
#   - vendor/ se sube también (~5000 archivos, ~15 min)
#   - Si vendor/ ya está en el servidor, comentá la línea en $folders
#   - .env NO se sube (se maneja aparte por seguridad)
#   - .gitignore del servidor debe excluir .env, storage/logs, etc.
# =============================================================================

# ── Credenciales: se leen de scripts/deploy/.deploy.env (gitignored) ─────────
$envFile = Join-Path $PSScriptRoot ".deploy.env"
if (Test-Path $envFile) {
    Get-Content $envFile | ForEach-Object {
        if ($_ -match '^\s*([^#=][^=]*)=(.*)$') {
            Set-Item -Path ("env:" + $matches[1].Trim()) -Value $matches[2].Trim()
        }
    }
}
$ftpHost     = $env:LCV_FTP_HOST
$ftpUser     = $env:LCV_FTP_USER
$ftpPass     = $env:LCV_FTP_PASS
# Ruta local de api/: por defecto el padre de scripts/deploy/, o LCV_API_LOCAL
$localBase   = if ($env:LCV_API_LOCAL) { $env:LCV_API_LOCAL } else { Split-Path (Split-Path $PSScriptRoot) }
$remoteBase  = "/"

if (-not $ftpHost -or -not $ftpUser -or -not $ftpPass) {
    Write-Error "Faltan credenciales FTP. Copiá scripts/deploy/.deploy.env.example a .deploy.env y completá LCV_FTP_HOST/USER/PASS."
    exit 1
}

# ═══ Carpetas a subir ════════════════════════════════════════════════════════
# Comentar las que NO quieras subir (ej: vendor si ya está en el server)
$folders = @(
    "app",
    "bootstrap",
    "config",
    "database",
    "lang",
    "public",
    "resources",
    "routes",
    "scripts",
    "storage"
    # "vendor"       # ← ~5000 archivos, comentalo si ya está subido
)

# ═══ Archivos sueltos de la raíz ═════════════════════════════════════════════
$files = @(
    "artisan",
    "composer.json",
    "composer.lock",
    ".env.production.server"   # ← Se renombra a .env en el servidor
)

Write-Host "╔══════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║  FTP Upload — La Casa Volvo API     ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""
Write-Host " Servidor : $ftpHost" -ForegroundColor Gray
Write-Host " Usuario  : $ftpUser" -ForegroundColor Gray
Write-Host " Origen   : $localBase" -ForegroundColor Gray
Write-Host ""

# ══════════════════════════════════════════════════════════════════════════════
# Función recursiva para subir una carpeta
# ══════════════════════════════════════════════════════════════════════════════
function Invoke-FolderUpload($localFolder, $remoteFolder) {
    $script:totalFiles++
    $localPath = Join-Path $script:localBase $localFolder
    $remotePath = "$script:remoteBase$remoteFolder".Replace("\","/")
    
    # Crear carpeta remota (ignorar error si ya existe)
    $ftpUrl = "ftp://$script:ftpHost$remotePath/"
    try {
        $request = [System.Net.FtpWebRequest]::Create($ftpUrl)
        $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $request.Credentials = New-Object System.Net.NetworkCredential($script:ftpUser, $script:ftpPass)
        $request.GetResponse() | Out-Null
    } catch { }

    # Subir archivos de esta carpeta
    Get-ChildItem $localPath -File -ErrorAction SilentlyContinue | ForEach-Object {
        $fileName = $_.Name
        $fileUrl = "ftp://$script:ftpHost$remotePath/$fileName"
        
        try {
            $request = [System.Net.FtpWebRequest]::Create($fileUrl)
            $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
            $request.Credentials = New-Object System.Net.NetworkCredential($script:ftpUser, $script:ftpPass)
            $content = [System.IO.File]::ReadAllBytes($_.FullName)
            $request.ContentLength = $content.Length
            $stream = $request.GetRequestStream()
            $stream.Write($content, 0, $content.Length)
            $stream.Close()
            $script:uploadedFiles++
        } catch {
            Write-Host "   ✗ ERROR: $remoteFolder/$fileName — $_" -ForegroundColor Red
            $script:errorFiles++
        }
    }

    # Recursión: subcarpetas
    Get-ChildItem $localPath -Directory -ErrorAction SilentlyContinue | ForEach-Object {
        Invoke-FolderUpload "$localFolder\$($_.Name)" "$remoteFolder/$($_.Name)"
    }
}

# ══════════════════════════════════════════════════════════════════════════════
# 1. SUBIR ARCHIVOS SUELTOS (raíz)
# ══════════════════════════════════════════════════════════════════════════════
Write-Host "── Archivos raíz ──" -ForegroundColor Yellow

foreach ($f in $files) {
    $localFile = Join-Path $localBase $f
    if (!(Test-Path $localFile)) {
        Write-Host "   ⊘ $f (no existe, saltando)" -ForegroundColor DarkGray
        continue
    }

    # .env.production.server → .env en el servidor
    $remoteName = if ($f -eq ".env.production.server") { ".env" } else { $f }
    $fileUrl = "ftp://$ftpHost/$remoteName"

    try {
        Write-Host "   ↑ $f → $remoteName" -ForegroundColor Gray
        $request = [System.Net.FtpWebRequest]::Create($fileUrl)
        $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
        $request.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
        $content = [System.IO.File]::ReadAllBytes($localFile)
        $request.ContentLength = $content.Length
        $stream = $request.GetRequestStream()
        $stream.Write($content, 0, $content.Length)
        $stream.Close()
        Write-Host "     ✓ OK" -ForegroundColor Green
        $script:uploadedFiles++
    } catch {
        Write-Host "     ✗ ERROR: $_" -ForegroundColor Red
        $script:errorFiles++
    }
}

# ══════════════════════════════════════════════════════════════════════════════
# 2. SUBIR CARPETAS
# ══════════════════════════════════════════════════════════════════════════════
$script:totalFiles = 0
$script:uploadedFiles = 0
$script:errorFiles = 0

foreach ($folder in $folders) {
    Write-Host "`n── $folder/ ──" -ForegroundColor Yellow
    Invoke-FolderUpload $folder $folder
}

# ══════════════════════════════════════════════════════════════════════════════
# RESULTADO
# ══════════════════════════════════════════════════════════════════════════════
Write-Host ""
Write-Host "══════════════════════════════════════" -ForegroundColor Cyan
Write-Host "  SUBIDA COMPLETADA" -ForegroundColor Green
Write-Host "  Procesados : $totalFiles" -ForegroundColor Gray
Write-Host "  Subidos    : $uploadedFiles" -ForegroundColor Green
if ($errorFiles -gt 0) {
    Write-Host "  Errores    : $errorFiles" -ForegroundColor Red
}
Write-Host "══════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "Siguiente paso:" -ForegroundColor Yellow
Write-Host "  1. Copiá scripts/deploy/setup.php → public/setup.php en el server"
Write-Host "  2. Abrilo: https://api.lacasavolvo.com/setup.php" -ForegroundColor Cyan
