<div align="center">

# La Casa Volvo — Sistema de Gestión

**ERP multi-sucursal para La Casa Volvo — casa de repuestos para camiones Volvo, La Paz · Bolivia.**

Ventas · Compras · Cotizaciones · Pedidos · Envíos entre sucursales · Caja · Inventario · Estadísticas · RBAC

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![React](https://img.shields.io/badge/React-18-61DAFB?logo=react&logoColor=black)
![Vite](https://img.shields.io/badge/Vite-7-646CFF?logo=vite&logoColor=white)
![Tests](https://img.shields.io/badge/tests-522%20passing-22c55e)
![Version](https://img.shields.io/badge/version-2.0.0-0b7ec2)
![License](https://img.shields.io/badge/license-Proprietary-lightgrey)

</div>

---

## 📦 Sobre el proyecto

Sistema de gestión empresarial **multi-sucursal** de **La Casa Volvo**, casa de repuestos para
camiones Volvo con sede en **La Paz, Bolivia** y sucursales en el país. Cubre el ciclo completo del
negocio: catálogo e inventario por sucursal, ventas y cotizaciones (POS), compras a proveedores,
**traslados de stock entre sucursales**, caja (apertura/movimientos/cierre/arqueo), devoluciones,
estadísticas (rotación FIFO, top productos/clientes) y control de accesos granular (RBAC por rol y
por sucursal).

> **Contexto:** es el **rediseño completo** del ERP legacy de la empresa (Laravel 5.4, 2017),
> modernizado a una arquitectura desacoplada **Laravel 13 (API) + React (SPA)**, conservando la base
> de datos y la lógica de negocio heredada. Es un **sistema en evolución**: se seguirán agregando
> funcionalidades y mejoras.

- **Idioma de trabajo:** español · **Moneda:** Bolivianos (Bs.) · **Zona horaria:** `America/La_Paz`
- **Multi-sucursal:** stock independiente por sucursal (`stock1`–`stock5`).

## 🏗️ Arquitectura

Monorepo con backend y frontend desacoplados:

```
lacasavolvo/
├── api/      ← Laravel 13 — API JSON pura (PHP 8.3, MySQL, Sanctum, Spatie Permission)
├── front/    ← React 18 + Vite — SPA (CSS propio "Diamante", Axios)
└── docs/     ← Deploy, runbook de cutover y auditoría (AUDIT-LEDGER, AUDIT-MATRIX)
```

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel 13 · PHP 8.3 · MySQL |
| Auth | Laravel Sanctum (Bearer token) |
| RBAC | Spatie Laravel-Permission v7 (8 roles, 91 permisos granulares) |
| PDF | barryvdh/laravel-dompdf |
| Frontend | React 18 · Vite · Axios |
| Estilos | Design system propio ("Diamante") — sin framework UI |

## 🚀 Cómo correr (desarrollo)

```bash
# Un solo comando (limpia puertos 8000/3000 y levanta backend + frontend)
./start.ps1
```

O por separado:

```bash
# Backend (servidor built-in de PHP con router de Laravel)
php -S 127.0.0.1:8000 -t api/public api/router.php        # → http://localhost:8000

# Frontend
cd front && npm run dev                                    # → http://localhost:3000
```

> Requiere PHP 8.3, MySQL y Node 18+. El frontend usa **npm** (excepción acordada por ser proyecto
> legacy migrado). Ver `api/.env.example` para la configuración de base de datos.

## 🧪 Calidad

| Gate | Estado |
|------|--------|
| PHPUnit (feature tests) | **522 / 522** ✅ |
| PHPStan / Larastan | nivel 5, **0 errores** ✅ |
| ESLint | **0 errores** ✅ |
| Accesibilidad (axe-core, E2E Playwright) | 0 violaciones serias/críticas ✅ |

```bash
cd api && php artisan test                                          # tests
cd api && php -d memory_limit=1G vendor/bin/phpstan analyse         # análisis estático
cd front && npm run lint && npm run build                          # lint + build
cd front && npx playwright test                                    # E2E + a11y (app corriendo)
```

El sistema pasó por **24 loops de auditoría adversarial** (correctitud e invariantes de dinero/stock,
seguridad/autorización, contrato de API, E2E, accesibilidad, responsive y performance). Detalle por
módulo en [`docs/AUDIT-LEDGER.md`](docs/AUDIT-LEDGER.md) y [`docs/AUDIT-MATRIX.md`](docs/AUDIT-MATRIX.md).

## 🚢 Deploy

cPanel (sin SSH): subdominio `api.lacasavolvo.com` para la API + dominio principal para la SPA, con
`staging.lacasavolvo.com` para pruebas. El paso a paso de la puesta en producción (copia de base de
datos, migraciones, backup y rollback) está en [`docs/CUTOVER.md`](docs/CUTOVER.md).

## 🌿 Metodología de ramas

- **`main`** — estable / producción. Cada release sale de acá.
- **`dev`** — integración. Las features se mergean acá primero.
- **`feature/…` · `fix/…` · `perf/…`** — ramas de trabajo (salen de `dev`, vuelven por PR).
- Versionado semántico con **Releases** (`vX.Y.Z`). Ver [CONTRIBUTING.md](CONTRIBUTING.md).

## 👥 Equipo

Desarrollado por **Rene Arturo Perez Rodriguez**.

---

<div align="center">
<sub>© 2026 La Casa Volvo · La Paz, Bolivia — Software propietario, todos los derechos reservados.</sub>
</div>
