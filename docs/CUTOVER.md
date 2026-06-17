# Runbook de puesta en producción (cutover)

Pasaje del **legacy** (Laravel 5.4 en `lacasavolvo.com`, BD `tienda`) al **sistema nuevo** (Laravel 13
API + React SPA). Estrategia: **copiar `tienda` a una BD nueva (`lacasavo_prod`)** y correr el sistema
nuevo ahí. La `tienda` real queda intacta → rollback trivial.

> ⚠️ **Regla de oro:** no se toca nada de producción sin un **backup restaurable de `tienda` verificado**.

## Quién hace qué

| Paso | ¿Quién? | Por qué |
|------|---------|---------|
| Backup de `tienda` (mysqldump) | **René / hosting** | Requiere acceso a la BD real (cPanel/phpMyAdmin) |
| Crear BD `lacasavo_prod` + usuario | **René** (cPanel → MySQL Databases) | Panel de hosting, sin API |
| Restaurar el dump de `tienda` en `lacasavo_prod` | **René / hosting** | BD real, mejor que lo controle el dueño |
| Editar 3 líneas del `.env` de la API + subirlo | **Claude o René** (FTP API disponible) | Automatizable; el switch lo confirma René |
| Correr migraciones (`setup.php`) | **Claude (dispara) / René (decide el momento)** | Corre sobre la BD real → el go lo decide René |
| Build + subir el frontend a `lacasavolvo.com` | **René** (faltan creds FTP de prod) | No hay credenciales FTP del dominio prod aún |
| Smoke test + retirar legacy | **René** | Decisión de negocio / momento |

**Resumen honesto:** el cutover es **mayormente de René** (operaciones de cPanel sobre datos reales +
el momento del switch). Claude **prepara todo** (configs, build, FTP de la API, disparo de migraciones)
y guía paso a paso, pero **crear la BD y el go/no-go son de René**.

## Pre-requisitos
- Ventana de **baja actividad** (René propuso el finde + lunes feriado).
- **Backup de `tienda` hecho y verificado** (que se pueda restaurar).
- Que QA (Tefy) haya cerrado su pase de lo crítico.

## Pasos

### 1. Backup (OBLIGATORIO, primero)
```bash
mysqldump -u <user> -p tienda > tienda_backup_AAAA-MM-DD.sql   # phpMyAdmin → Exportar también sirve
```
Guardar el backup fuera del server. **No seguir sin esto.**

### 2. Crear la BD nueva (cPanel → MySQL Databases)
- Crear base `lacasavo_prod` + usuario `lacasavo_prod` con todos los privilegios.

### 3. Copiar los datos
- Importar `tienda_backup_AAAA-MM-DD.sql` a `lacasavo_prod` (phpMyAdmin → Importar; si pesa, comprimir
  a .zip y subir por File Manager — ver nota de phpMyAdmin en `docs/`).
- **Esta copia debe ser FRESCA, del momento del switch.** Desde que copiás, el legacy y el nuevo
  divergen: lo que se venda en el legacy después de copiar NO estará en el nuevo. Por eso se hace en la
  ventana de baja actividad y se retira el legacy enseguida.

### 4. Apuntar la API a la BD nueva
En el `.env` de la API (`api.lacasavolvo.com`), cambiar **solo 3 líneas**:
```ini
DB_DATABASE=lacasavo_prod
DB_USERNAME=lacasavo_prod
DB_PASSWORD=<la nueva contraseña>
```
El resto ya está prod-ready: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.lacasavolvo.com`,
`CORS_ALLOWED_ORIGINS=https://lacasavolvo.com,...`. Subir el `.env` por FTP.

> ⚠️ **Nota de arquitectura:** hoy la API es **compartida** staging+prod. Al apuntarla a `lacasavo_prod`,
> *staging también* usaría esa base. Si querés mantener staging separado, hay que darle a staging su
> propia API (ej. `api-staging.lacasavolvo.com` con su `.env`→`lacasavo_staging`). Si no, "staging"
> deja de tener sentido una vez que prod está en vivo.

### 5. Migraciones (convierte Shinobi→Spatie sobre la copia)
- Abrir `https://api.lacasavolvo.com/setup.php` → corre `migrate --force` (11 migraciones idempotentes;
  la #3 convierte roles/permisos del legacy a Spatie y conserva los **usuarios reales**), `storage:link`,
  limpia cachés. Se autoelimina.

### 6. Frontend de producción
```bash
cd front && npm run build          # usa front/.env.production (→ api.lacasavolvo.com)
# subir front/dist/ → lacasavolvo.com  (FTP de prod — pendiente de credenciales)
```

### 7. Smoke test (con datos reales)
- Login con un **usuario real** (empleado) → verificar su rol/sucursal.
- Una venta, una compra, apertura/cierre de caja, un envío entre sucursales, estadísticas.
- Revisar que no haya errores 500 ni datos faltantes.

### 8. Retirar el legacy
- `lacasavolvo.com` ya sirve la SPA nueva.
- Dejar el legacy accesible como **`legacy.lacasavolvo.com`** (copia del app legacy + snapshot de
  `tienda`) como botón de pánico unas semanas.

## Rollback
Si algo sale mal:
1. Volver a apuntar `lacasavolvo.com` al legacy (que nunca se tocó).
2. La `tienda` real sigue intacta (el sistema nuevo trabajó sobre `lacasavo_prod`).
3. Si hiciera falta, restaurar `tienda` desde el backup del paso 1.

## Checklist final
- [ ] Backup de `tienda` verificado
- [ ] `lacasavo_prod` creada y cargada (copia fresca)
- [ ] `.env` de la API → `lacasavo_prod` + subido
- [ ] `setup.php` corrido (migraciones OK)
- [ ] Frontend de prod subido a `lacasavolvo.com`
- [ ] Smoke test con usuario real OK
- [ ] `legacy.lacasavolvo.com` como fallback
- [ ] QA (Tefy) cerró lo crítico
