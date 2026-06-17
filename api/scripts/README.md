# 🚀 Deploy a Producción — La Casa Volvo API

**Stack:** Laravel 13 · PHP 8.3 · MySQL · cPanel sin SSH  
**URL producción:** `https://api.lacasavolvo.com`  
**Subdominio:** `api.lacasavolvo.com` → DocumentRoot: `public/`

---

## 📁 Estructura de scripts

```text
api/scripts/
├── README.md               ← Este archivo
└── deploy/
    ├── upload-ftp.ps1       ← PowerShell: subir todo el proyecto por FTP
    ├── setup.php            ← PHP: setup post-deploy (migrate + storage:link + cachés)
    └── create-users.php     ← PHP: crear usuarios admin/vendedor iniciales
```

---

## 🔄 Flujo de deploy (paso a paso)

### 1. Preparar `.env` de producción

Crear `api/.env.production.server` con las credenciales del servidor:

```env
APP_NAME="La Casa Volvo"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.lacasavolvo.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=lacasavo_staging
DB_USERNAME=userjavi
DB_PASSWORD=********

CORS_ALLOWED_ORIGINS=https://lacasavolvo.com,https://staging.lacasavolvo.com
```

> ⚠️ Este archivo está en `.gitignore`. El script FTP lo renombra a `.env` al subir.

### 2. Subir archivos al servidor (PowerShell)

```powershell
cd "d:\Sitios Web\lacasavolvo\api"
powershell -ExecutionPolicy Bypass -File scripts/deploy/upload-ftp.ps1
```

**Qué sube:**

- Archivos raíz: `artisan`, `composer.json`, `composer.lock`
- `.env.production.server` → `.env` en el servidor
- Carpetas: `app/`, `bootstrap/`, `config/`, `database/`, `lang/`, `public/`, `resources/`, `routes/`, `scripts/`, `storage/`, `vendor/`

**Tiempo estimado:** ~15 minutos (vendor/ son ~5000 archivos).  
**Tip:** Si vendor/ no cambió, comentá `"vendor"` en `$folders` para ahorrar tiempo.

### 3. Configurar PHP en el servidor

En cPanel → Select PHP Version → Options:

- `max_execution_time = 300`
- `memory_limit = 512M`

O crear `.user.ini` en la raíz del subdominio:

```ini
max_execution_time=300
memory_limit=512M
```

### 4. Ejecutar setup

1. Copiá `scripts/deploy/setup.php` a la carpeta `public/` del servidor
2. Abrilo: **`https://api.lacasavolvo.com/setup.php`**
3. El script ejecuta:
   - ✅ `migrate --force` → 8 migraciones (cache, jobs, RBAC Spatie, Sanctum, índices, DECIMAL, email, simulated_role_id)
   - ✅ `migrate --force` → 8 migraciones (la #3 adapta roles/permisos)
   - ✅ `storage:link --force` → acceso público a PDFs desde storage/
   - ✅ Limpieza de cachés
4. Crear usuarios iniciales:
   ```bash
   php scripts/deploy/create-users.php
   - ✅ `config:clear`, `cache:clear`, `view:clear`, `route:clear`
4. Si todo OK → el script se auto-elimina ✅
5. Si hay error → muestra el error, corregí y recargá

### 5. Verificar API

```powershell
# Login
Invoke-RestMethod -Uri "https://api.lacasavolvo.com/api/login" `
  -Method Post -Body '{"email":"admin@lcv.bo","password":"..."}' `
  -ContentType "application/json"
```

Respuesta esperada: `{ "token": "1|...", "user": { ... } }`

---

## 📋 Migraciones (8 total)

| # | Migración | Descripción |
| --- | --- | --- |
| 1 | `0001_01_01_000001` | Tablas cache y cache_locks |
| 2 | `0001_01_01_000002` | Tablas jobs y failed_jobs |
| 3 | `2026_05_13_201655` | Adapta Shinobi→Spatie: guard_name, pivots, migra role_user |
| 4 | `2026_05_16_000001` | Índices de rendimiento |
| 5 | `2026_05_20_232900` | Sanctum: personal_access_tokens |
| 6 | `2026_05_26_000000` | Columna `email` en cuentas |
| 7 | `2026_05_26_000001` | DECIMAL(9,2)→(12,2) en cierres/aperturas |
| 8 | `2026_05_26_075338` | `simulated_role_id` en users (simulador de roles) |

---

## 🗄️ Base de datos

- **Nombre BD:** `lacasavo_staging` (producción) / `tienda` (local)
- **Dump de referencia:** `api/tienda (1).sql` (71 MB, ~15 MB comprimido)
- **Importar dump grande sin SSH:**
  1. Comprimir: `Compress-Archive -Path "tienda (1).sql" -DestinationPath import.zip`
  2. Subir `import.zip` por FTP a `public/`
  3. Extraer con File Manager de cPanel
  4. Importar con phpMyAdmin (si es muy grande, partirlo o pedir soporte)

---

## 🔑 Permisos y roles

- **91 permisos granulares** (igual al legacy Shinobi)
- **8 roles:** ADMIN · GERENTE · VENDEDOR · CAJERO · OPERADOR · SUSPENDIDO · VENDEDOR DENNIS · VTARIJA
- ADMIN tiene `Gate::before` → acceso total sin permisos explícitos
- Los permisos se asignan en `PermissionsSeeder` vía `syncPermissions()`

---

## 🐛 Problemas comunes

| Problema | Causa | Solución |
| --- | --- | --- |
| 500 en setup | `max_execution_time=30` no alcanza | Subir `.user.ini` o cambiar en cPanel |
| 404 en `/api/*` | `.htaccess` de Laravel no funciona | Revisar que `public/.htaccess` existe y mod_rewrite activo |
| "Nothing to migrate" | Migraciones ya corrieron | Normal, es idempotente |
| "Role already exists" | Seeder ya corrió | Normal, usa `firstOrCreate` |
| BD vacía, migración falla | `ALTER TABLE roles` sin tabla | Importar dump legacy primero |

---

## 📦 Frontend (SPA)

El frontend React se sirve desde `lacasavolvo.com` y `staging.lacasavolvo.com`, NO desde la API.

Ver `front/.env.production` y `front/.env.staging` para `VITE_API_URL`.

---

**Última actualización:** 26 de mayo de 2026  
**Autor:** Rene Arturo Perez Rodriguez
