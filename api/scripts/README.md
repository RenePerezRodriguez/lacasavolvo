# 🚀 Deploy a Producción — La Casa Volvo API

**Stack:** Laravel 13 · PHP 8.3 · MySQL · cPanel **sin SSH**
**URL producción:** `https://api.lacasavolvo.com` (DocumentRoot del subdominio: `public/`)

> ⚠️ **Producción EN VIVO (sistema de dinero): no desplegar sin OK explícito.**
> Runbook canónico con credenciales y detalle fino: **`docs/DEPLOY.md`** (gitignored).

---

## 📁 Scripts (`api/scripts/deploy/`)

```text
deploy/
├── deploy-cpanel-api.sh     ← Orquestador del deploy por la API de cPanel (UAPI). Método ACTUAL.
├── setup.php                ← Setup post-deploy (migrate + storage:link + limpia cachés), se auto-elimina.
├── create-users.php         ← Crea usuarios iniciales (solo en BD nueva/vacía).
├── create-admin-legacy.php  ← Crea/garantiza el admin en la BD legacy.
├── .deploy.env              ← Credenciales (gitignored, NUNCA se commitea).
└── .deploy.env.example      ← Plantilla de credenciales.
```

> El método viejo por **FTP (`upload-ftp.ps1`) fue eliminado** (commit `7da0fa8`). Todo va por UAPI.

---

## 🔄 Deploy (forma rápida)

Desde la raíz del repo, en **Git Bash**:

```bash
bash api/scripts/deploy/deploy-cpanel-api.sh --api <archivos relativos a api/> --spa
# ej: --api app/Http/Controllers/CajaController.php routes/api.php --spa
```

Hace todo: sube los `.php` por UAPI, limpia cachés (route/config/view + opcache), build del SPA + sube `front/dist`, y corre un smoke test.

### Requisitos / gotchas
- Credenciales en `api/scripts/deploy/.deploy.env` (copiá de `.deploy.env.example` y completá `CPANEL_USER` + `CPANEL_API_TOKEN`).
- En Git Bash el script ya exporta `MSYS_NO_PATHCONV=1` (si no, las rutas `/home/...` se mutan a rutas Windows).
- El zip del SPA se hace con **python `zipfile`** (separador `/`), **NUNCA** `Compress-Archive` (mete `\` y rompe el SPA en Linux).
- Tras tocar la API, el **clear-cache es obligatorio** o las rutas nuevas dan 404 (el script ya lo hace).
- Scripts PHP temporales siempre con `?key=<secreto>` + autoeliminación.

---

## 🗄️ Base de datos
- **Prod:** `lacasavo_prod` (copia de `tienda`; `tienda` queda intacta como red de seguridad/rollback). **Local/dev:** `tienda`. **Tests:** `tienda_test`.
- Tablas de negocio heredadas del legacy (2017); migraciones con guard `Schema::hasTable`/`hasColumn`/`hasIndex`.
- **No se corre `db:seed` en prod:** los permisos son los del legacy (ver `.claude/CLAUDE.md` → Roles).

---

## 🔑 Roles
- **7 roles en la BD legacy:** ADMIN · GERENTE · VENDEDOR · OPERADOR · SUSPENDIDO · VENDEDOR DENNIS · VTARIJA.
- **CAJERO no existe en la BD legacy** (solo lo crea `PermissionsSeeder` para `tienda_test`).
- ADMIN tiene `Gate::before` → acceso total sin permisos explícitos.

---

## 🐛 Problemas comunes

| Problema | Causa | Solución |
| --- | --- | --- |
| 404 en ruta nueva tras deploy | Falta limpiar cachés | Re-correr clear-cache (el script ya lo hace) |
| 500 en setup | `max_execution_time=30` corto | Subir `.user.ini` (`max_execution_time=300`, `memory_limit=512M`) |
| Smoke `HTTP 000` | API fría tras clear-cache (timeout transitorio) | Reintentar con timeout mayor |
| SPA con assets rotos en Linux | Se zipeó con `Compress-Archive` | Re-zipear con python `zipfile` |

---

## 📦 Frontend (SPA)
Se sirve desde `lacasavolvo.com` (prod) y `staging.lacasavolvo.com`, NO desde la API. Ver `front/.env.production` / `front/.env.staging` para `VITE_API_URL`.

---

**Método actual:** UAPI de cPanel. Runbook fino: `docs/DEPLOY.md`.
