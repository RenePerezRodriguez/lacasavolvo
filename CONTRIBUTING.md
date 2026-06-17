# Guía de contribución

Repositorio **privado** del sistema de La Casa Volvo. Esta guía es para el equipo de desarrollo
autorizado.

## Flujo de trabajo

1. Ramá desde `master`: `git checkout -b feat/mi-cambio` (o `fix/…`, `perf/…`, `chore/…`).
2. Hacé commits **atómicos** con mensajes en formato [Conventional Commits](https://www.conventionalcommits.org/):
   `feat(ventas): …`, `fix(caja): …`, `perf(estadisticas): …`, `docs: …`.
3. Antes de abrir PR, todo en verde (ver más abajo). No se mergea con la suite roja.
4. PR a `master` con descripción del cambio. No mergees a producción sin revisión.

## Antes de commitear — todo en verde

```bash
cd api   && php artisan test                                    # PHPUnit (debe quedar 100%)
cd api   && php -d memory_limit=1G vendor/bin/phpstan analyse   # PHPStan nivel 5 → 0 errores
cd front && npm run lint                                        # ESLint → 0 errores
cd front && npm run build                                       # build de producción OK
```

Por cada bug corregido: **test que lo reproduzca en rojo primero**, luego el fix (rojo→verde).

## Convenciones

- **Documentación obligatoria** (JSDoc/TSDoc en frontend, PHPDoc en backend) en toda función o
  componente nuevo o modificado.
- **Gestor de paquetes:** `npm` en el frontend (excepción acordada por proyecto legacy migrado).
- **Migraciones** con guard clauses (`Schema::hasTable`, `hasColumn`, `hasIndex`) — la BD hereda 66
  tablas del legacy que no gestionan las migraciones.
- **Nunca** commitear `.env`, credenciales, dumps de BD ni archivos de `.scratch/` (ya excluidos por
  `.gitignore`).

## Estructura

- `api/` — Laravel 13 (API JSON). Controllers en `app/Http/Controllers`, modelos en `app/Models`,
  tests en `tests/Feature`.
- `front/` — React + Vite. Pantallas en `src/screens`, componentes en `src/lib/components`, cliente
  HTTP en `src/services/api.js`.
- `docs/` — deploy y auditoría.
