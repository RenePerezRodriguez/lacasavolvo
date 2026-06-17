# La Casa Volvo — API

Backend JSON API para el sistema de gestión multi-sucursal de La Casa Volvo (Bolivia). Repuestos para camiones Volvo.

**Stack:** Laravel 13 · PHP 8.3 · MySQL · Sanctum · Spatie Permissions

---

## 🚀 Iniciar en local

```powershell
# Desde la raíz del monorepo
.\start.ps1
```

Ese script levanta backend (`:8000`) + frontend (`:3000`) y libera los puertos si están ocupados.

### Solo backend

```powershell
C:\Users\Rene_\.config\herd\bin\php83\php.exe -S 127.0.0.1:8000 -t "D:\Sitios Web\lacasavolvo\api\public" "D:\Sitios Web\lacasavolvo\api\router.php"
```

- URL: **`http://localhost:8000`**
- ⚠️ `artisan serve` no funciona en esta máquina (error de socket en Windows).

### Migraciones

```bash
cd "D:\Sitios Web\lacasavolvo\api"
C:\Users\Rene_\.config\herd\bin\php83\php.exe artisan migrate
```

> No se usa `db:seed`. Roles y permisos vienen del legacy y la migración #3 los copia automáticamente.

---

## 📦 Stack

| Capa | Tecnología |
|------|-----------|
| Framework | Laravel 13 |
| PHP | 8.3 (Herd: `C:\Users\Rene_\.config\herd\bin\php83\php.exe`) |
| Auth | Laravel Sanctum — Bearer token |
| RBAC | Spatie/Laravel-Permission v7 |
| PDF | barryvdh/laravel-dompdf v3 |
| DB | MySQL, base `tienda` |
| Timezone | `America/La_Paz` |
| Locale | `es` / `es_BO` |

---

## 🔐 Auth

- `POST /api/login` → `{ token, user }`
- `GET /api/user` → datos del usuario autenticado
- `POST /api/logout`
- Token Bearer en header `Authorization`
- 7 roles: ADMIN · GERENTE · VENDEDOR · OPERADOR · SUSPENDIDO · VENDEDOR DENNIS · VTARIJA

---

## 🗄️ Base de datos

- `DB_DATABASE=tienda`
- Tablas de negocio heredadas del legacy (Laravel 5.4, 2017)
- Migraciones usan `Schema::hasTable` para no recrear tablas existentes
- Dump de referencia: `tienda (1).sql`

---

## 🚢 Deploy

Ver `.github/copilot-instructions.md` para el procedimiento completo de deploy a cPanel.

- API producción: `https://api.lacasavolvo.com`
- Staging frontend: `https://staging.lacasavolvo.com`
- Producción frontend: `https://lacasavolvo.com`
