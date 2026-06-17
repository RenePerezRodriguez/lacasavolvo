# La Casa Volvo — Monorepo

Sistema de gestión empresarial multi-sucursal para La Casa Volvo (Bolivia). Repuestos para camiones Volvo.

**Desarrollador:** Rene Arturo Perez Rodriguez  
**Stack:** Laravel 13 (API JSON) + React + Vite (SPA)  
**Idioma de trabajo:** español

---

## Estructura del monorepo

```
D:\Sitios Web\lacasavolvo\
├── api/        ← Laravel 13 — backend puro JSON API
├── front/      ← React + Vite — SPA frontend
├── .gitignore  ← excluye vendor/, node_modules/, .env, dist/
└── .gitattributes
```

---

## Cómo correr el proyecto

### 🚀 Un solo comando (recomendado)

```powershell
.\start.ps1
```
Limpia puertos 8000 y 3000, inicia backend + frontend.

### Backend (api/)

Se usa el **servidor built-in de PHP** con el router de Laravel. `artisan serve` no funciona en esta máquina (error de socket en Windows).

```bash
C:\Users\Rene_\.config\herd\bin\php83\php.exe -S 127.0.0.1:8000 -t "D:\Sitios Web\lacasavolvo\api\public" "D:\Sitios Web\lacasavolvo\api\router.php"
```
- URL: **`http://localhost:8000`**

> ⚠️ El comando DEBE ejecutarse desde cualquier directorio (rutas absolutas). No usa `cd` previo.

PHP disponible en:
- **PHP 8.3:** `C:\Users\Rene_\.config\herd\bin\php83\php.exe`
- **Composer:** `C:\Users\Rene_\.config\herd\bin\composer.phar`

Migraciones:
```bash
cd "D:\Sitios Web\lacasavolvo\api"
C:\Users\Rene_\.config\herd\bin\php83\php.exe artisan migrate
```
> ⚠️ No se usa db:seed. El sistema legacy ya tiene roles/permisos. La migración #3 (Shinobi→Spatie) los copia automáticamente.

### Frontend (front/)

```bash
cd "D:\Sitios Web\lacasavolvo\front"
npm run dev     # Vite dev server — http://localhost:3000
npm run build   # Build producción → dist/
```

> Puerto fijado en `3000` en `vite.config.js`.

> **Nota:** Este proyecto usa `npm` (no pnpm) — excepción acordada por ser un proyecto legacy migrado.

---

## Backend — api/

### Stack

| Capa | Tecnología |
|------|-----------|
| Framework | Laravel 13, PHP 8.3+ |
| Auth | Laravel Sanctum — Bearer token |
| RBAC | Spatie/Laravel-Permission v7 |
| PDF | barryvdh/laravel-dompdf v3 |
| DB | MySQL, base `tienda` (heredada del legacy) |
| Timezone | `America/La_Paz` |
| Locale | `es` / `es_BO` |

### Base de datos

- `DB_DATABASE=tienda`, `DB_USERNAME=userjavi`
- Las tablas de negocio **ya existen** heredadas del legacy (Laravel 5.4, 2017). Las migraciones usan guardias `Schema::hasTable` para no recrearlas.
- Dump de referencia: `api/tienda (1).sql` (snapshot 2026-05-16)

### 🚀 Deploy a Producción (cPanel / sin SSH)

**Arquitectura**: Subdominio `api.lacasavolvo.com` para la API + dominio principal `lacasavolvo.com` para la SPA (futuro: `sistema.lacasavolvo.com`).

**Staging**: `staging.lacasavolvo.com` (frontend) + `api.lacasavolvo.com` (misma API).

```
cPanel/
├── api/                        ← Subdominio api.lacasavolvo.com
│   ├── public/                 ← DocumentRoot del subdominio
│   │   ├── index.php           ← Laravel entry point
│   │   ├── .htaccess           ← Reglas de Laravel
│   │   └── setup-production.php ← Script de setup (temporal)
│   ├── app/  bootstrap/  config/  ...  (Laravel)
│   └── .env                    ← Credenciales de BD producción
│
├── lacasavolvo.com/            ← Dominio principal (producción)
│   ├── index.html              ← React SPA
│   ├── assets/                 ← JS/CSS del build
│   └── .htaccess               ← SPA fallback
│
└── staging/                    ← Subdominio staging.lacasavolvo.com
    ├── index.html              ← React SPA (staging)
    ├── assets/
    └── .htaccess
```

**Builds del frontend:**

| Entorno | Comando | Archivo .env | VITE_API_URL |
|---|---|---|---|
| Staging | `npm run build -- --mode staging` | `.env.staging` | `https://api.lacasavolvo.com/api` |
| Producción | `npm run build` | `.env.production` | `https://api.lacasavolvo.com/api` |

**Paso a paso:**

```bash
# ═══ 1. EN TU MÁQUINA LOCAL ═══

# Staging
cd front
npm run build -- --mode staging
# → subir front/dist/ a staging/

# Producción
npm run build
# → subir front/dist/ a lacasavolvo.com/

# ═══ 2. SUBIR AL SERVIDOR ═══

# Backend (una sola vez para ambos entornos)
# Subir api/ a la carpeta del subdominio api.lacasavolvo.com

# Frontend staging
# Subir front/dist/ → staging/

# Frontend producción  
# Subir front/dist/ → lacasavolvo.com/

# ═══ 3. CONFIGURAR api/.env ═══

APP_URL=https://api.lacasavolvo.com
APP_ENV=production
APP_DEBUG=false
DB_DATABASE=tienda
DB_USERNAME=userjavi
DB_PASSWORD=********

# CORS (config/cors.php):
'paths' => ['api/*'],
'allowed_origins' => ['https://lacasavolvo.com', 'https://staging.lacasavolvo.com'],

# ═══ 4. SETUP (primera vez) ═══

# Subir api/scripts/deploy/setup.php → api/public/setup.php
# Abrir: https://api.lacasavolvo.com/setup.php
# → Ejecuta migrate, storage:link, limpia cachés, se auto-elimina.
# Los roles/permisos se migran automáticamente (no se necesita seeder).

**Si no tenés acceso al navegador** (solo FTP), pedí al soporte del hosting:
```bash
cd /home/usuario/api
php artisan migrate --force
php artisan storage:link --force
php artisan config:clear && php artisan cache:clear && php artisan route:clear
```

**Migraciones incluidas** (8 total, todas con guard clauses):
| Migración | Qué hace |
|---|---|
| `0001_01_01_000001` | Tablas cache y cache_locks |
| `0001_01_01_000002` | Tablas jobs y failed_jobs |
| `2026_05_13_201655` | **Adapta Shinobi→Spatie**: agrega guard_name, crea pivots, migra role_user, copia permission_role→role_has_permissions, corrige name=slug |
| `2026_05_16_000001` | Índices de rendimiento |
| `2026_05_20_232900` | **Sanctum**: tokens de autenticación API |
| `2026_05_26_000000` | Columna `email` en cuentas |
| `2026_05_26_000001` | DECIMAL(9,2)→(12,2) en cierres y aperturas |
| `2026_05_26_075338` | `simulated_role_id` en users (simulador de roles) |

> **Producción (cPanel):** Si las migraciones fallan por timeout, crear `.user.ini` en `api/public/` con `max_execution_time=300` y `memory_limit=256M`.

### 👤 Credenciales de desarrollo

**Fuente autoritativa:** `api/scripts/deploy/create-users.php`

| Email | Password | Rol | Sucursales |
|-------|----------|-----|------------|
| `rene_perez@safesoft.tech` | _(fuera del repo · pedir a René)_ | ADMIN | 1,2,3,4 |
| `rene_perez@outlook.it` | _(fuera del repo · pedir a René)_ | VENDEDOR | 1,2 |

> ⚠️ **NUNCA inventes credenciales.** Si necesitás loguearte, usá estas o preguntale al humano.

### Auth — Bearer token (Sanctum)

- Token se guarda en `localStorage` como `lcv_token`
- Login: `POST /api/login` → devuelve `{ token, user }`
- Logout: `POST /api/logout`
- Me: `GET /api/user`
- Usuario con rol `SUSPENDIDO` no puede iniciar sesión
- Al login se revocan tokens anteriores del mismo dispositivo (nombre `'spa'`)

### Payload del usuario (GET /api/user)

```json
{
  "id": 1,
  "name": "Nombre Apellido",
  "email": "user@lcv.bo",
  "sucursal_id": 1,
  "sucursal": { "id": 1, "nombre": "Sucursal Principal", "alias": "SP" },
  "role": "ADMIN",
  "roles": ["ADMIN"],
  "permissions": ["ventas.index", "ventas.create", "..."],
  "simulated_role_id": null,
  "simulated_role_name": null,
  "accesos": [{ "sucursal_id": 1, "nombre": "Sucursal Principal" }]
}
```

### Roles disponibles (Spatie) — 7 roles (idénticos al legacy)

`ADMIN` · `GERENTE` · `VENDEDOR` · `OPERADOR` · `SUSPENDIDO` · `VENDEDOR DENNIS` · `VTARIJA`

- `ADMIN` tiene Gate::before → acceso a todo sin necesidad de permisos
- **91 permisos granulares** (legacy): `ventas.index`, `ventas.create`, `ventas.print`, etc.
- **Sin PermissionsSeeder.** La migración #3 copia automáticamente `permission_role` → `role_has_permissions` y corrige `name = slug`. Los permisos vienen de la BD legacy.
  - `GERENTE`: 83 permisos
  - `VENDEDOR`: 52 permisos
  - `OPERADOR`: 83 permisos
  - `VTARIJA`: 9 permisos (solo lectura)
  - `VENDEDOR DENNIS`: 62 permisos
- **Middleware de rutas usa permisos granulares**, NO `role:ADMIN`. Ej: `middleware('permission:ventas.index')`.
  - Rutas admin (sucursales, usuarios, roles): protegidas con `permission:sucursales.*`, `permission:usuarios.*`, `permission:roles.*`
- 5 sucursales (stock1–stock5). `user.sucursal_id` = sucursal activa del usuario.
- **Simulador de roles**: botón "Simular" en cada rol → guarda `simulated_role_id` → recarga la página como ese rol. Botón "Volver a mi rol" para salir.

### CORS

`config/cors.php`:
- `paths: ['api/*']`
- `allowed_origins: ['*']`
- `supports_credentials: false` (token-based, no cookies)

### Modelos y tablas (28 modelos)

| Modelo | Tabla | Notas |
|--------|-------|-------|
| User | users | HasRoles, belongsTo Sucursal, campos: sucursal_id, avatar, simulated_role_id |
| Sucursal | sucursals | 5 sucursales activas |
| Acceso | accesos | Matriz usuario-sucursal (estado ON/OFF) |
| Producto | productos | stock1–stock5 por sucursal |
| Marca | marcas | |
| Industria | industrias | |
| Precio | precios | Historial cambios precio por compra |
| Cuenta | cuentas | Clientes/proveedores/ambos |
| Empresa | empresas | |
| Localidad | localidads | |
| Medio | medios | Medios de pago |
| Compra | compras | Header de compra |
| Compradetalle | compradetalles | |
| Venta | ventas | Header de venta |
| Ventadetalle | ventadetalles | |
| Pedido | pedidos | Orden interna entre sucursales |
| Pedidodetalle | pedidodetalles | |
| Cotizacion | cotizacions | |
| Cotizaciondetalle | cotizaciondetalles | |
| Envio | envios | Remito/despacho |
| Enviodetalle | enviodetalles | |
| Devcompra | devcompras | Devolución a proveedor |
| Devventa | devventas | Devolución de cliente |
| Devenvio | devenvios | Devolución de envío |
| Tranza | tranzas | Movimientos de caja |
| Apertura | aperturas | Apertura de caja |
| Cierre | cierres | Cierre de caja |
| Ajuste | ajustes | Ajustes manuales de stock |

### Convenciones API

- Paginación: `?skip=0&take=30` (offset-based)
- Filtros: query params `?search=X&estado_filtro=VALIDO`
- Estados documentos: `'PROFORMA'` → `'VALIDO'` → `'ANULADO'`
- Estados catálogos: `'ON'` / `'OFF'`
- Moneda: Bolivianos (`Bs.`), `number_format($v, 2)`
- Stock productos: columnas `stock1`–`stock5`, una por sucursal. La API expone solo el `stock` de la sucursal activa del usuario.

### Formatos de respuesta importantes (diferencias vs mock)

**Ventas (GET /api/ventas):**
```json
{
  "data": [...],
  "total": 120
}
```
Cada venta tiene:
- `cuenta` (no `cliente`)
- `total` como string pre-formateado `"Bs. 1,234.56"`
- `pagado` (no `pago`/`saldo`)
- `estado`: `PROFORMA` | `VALIDO` | `ANULADO`

**Productos (GET /api/productos):**
- `stock`: número (solo la sucursal del usuario, no 5 columnas)
- `p_fact`: string `"42.00"` (precio de factura)

**Ventas KPIs (GET /api/ventas/kpis):**
```json
{ "total": 42, "proforma": 10, "valido": 30, "monto": "Bs. 15,000.00" }
```

**Productos KPIs (GET /api/productos/kpis):**
```json
{ "activos": 120, "stock_critico": 5, "sin_stock": 2, "valor_inventario": "Bs. 85,000.00" }
```

### Rutas API completas

```
POST   /api/login                    (pública)
GET    /api/user                     me
POST   /api/logout

GET    /api/productos/quicksearch    búsqueda rápida por código O ID numérico (usada en modals)

# Ventas
GET    /api/ventas                   list (params: skip, estado_filtro, search)
GET    /api/ventas/kpis
POST   /api/ventas                   store
POST   /api/ventas/update-encabezado
POST   /api/ventas/agregar-item
POST   /api/ventas/update-item
POST   /api/ventas/delete-item/{id}
POST   /api/ventas/validar/{id}
POST   /api/ventas/dev-item
POST   /api/ventas/delete-item-dev
POST   /api/ventas/cobrar
POST   /api/ventas/negativos         detecta stock insuficiente antes de validar
POST   /api/ventas/validar-acceso-sucursal   verifica acceso del usuario a sucursal
GET    /api/ventas/{id}/detalles
GET    /api/ventas/{id}/devoluciones
GET    /api/ventas/{id}/cobros
GET    /api/ventas/{id}/pdf
DELETE /api/ventas/{id}

# Compras (misma estructura)
GET    /api/compras  GET /api/compras/kpis
POST   /api/compras/validar/{id}    actualiza stock + historial precios
POST   /api/compras/pagar

# Caja
GET    /api/caja/kpis
GET    /api/caja/movimientos
POST   /api/caja/apertura
POST   /api/caja/cierre
POST   /api/caja/ingreso
POST   /api/caja/egreso
POST   /api/caja/update-tranza
POST   /api/caja/delete-tranza
POST   /api/caja/revertir-cierre       revierte un cierre existente
GET    /api/caja/report
GET    /api/caja/historial/tranzas
GET    /api/caja/historial/compras
GET    /api/caja/historial/ventas
GET    /api/caja/historial/efectivos
GET    /api/caja/{apertura}/tranzas
GET    /api/caja/{apertura}/compras
GET    /api/caja/{apertura}/ventas

# Cotizaciones / Pedidos / Envíos — misma estructura
GET    /api/cotizaciones  /api/pedidos  /api/envios
POST   /api/cotizaciones/{id}/venta   convierte cotización a venta
POST   /api/envios/enviar/{id}  /api/envios/recibir/{id}

# Productos
GET    /api/productos                (params: skip, search, sucursal_id)
GET    /api/productos/kpis
GET    /api/productos/ajustes
POST   /api/productos/ajuste-positivo  ajuste-negativo  ajuste-destroy
GET    /api/productos/{id}/movimientos

# Cuentas, Estadísticas, Admin
# ─── Catálogos con toggle ON/OFF ───
POST   /api/marcas/{id}/toggle        activar/desactivar marca
POST   /api/industrias/{id}/toggle
POST   /api/medios/{id}/toggle
POST   /api/empresas/{id}/toggle
POST   /api/localidades/{id}/toggle
POST   /api/sucursals/{id}/toggle
# ─── Usuarios y Roles ───
POST   /api/users/{id}/suspend        suspender usuario (asigna rol SUSPENDIDO)
POST   /api/users/{id}/activate       reactivar usuario (devuelve rol anterior)
POST   /api/users/simulate-role       simular rol (guarda simulated_role_id)
POST   /api/users/stop-simulate       dejar de simular (vuelve al rol real)
# Ver front/src/services/api.js para el mapa completo
```

### Helper NumerosEnLetras

```php
use App\Helpers\NumerosEnLetras;
NumerosEnLetras::convertir(120.50); // → "CIENTO VEINTE BOLIVIANOS CON 50/100"
```

---

## Frontend — front/

### Stack

| | |
|--|--|
| Framework | React 18 + Vite |
| Estilos | CSS custom (no Tailwind) — design system propio |
| HTTP | Axios — `front/src/services/api.js` |
| Fonts | Plus Jakarta Sans (UI) + Space Grotesk (display) — Google Fonts CDN |
| Icons | Font Awesome 6.5.1 — CDN |

### Estructura de archivos front/

```
front/src/
├── App.jsx               ← Raíz: auth flow, routing, tweaks CSS
├── index.css             ← Design system completo (variables, utilidades)
├── services/
│   └── api.js            ← Axios + interceptor Bearer token
├── lib/
│   ├── components.jsx    ← Barrel: re-exporta los módulos de components/ (ruta de import estable)
│   ├── components/       ← Módulos UI: primitives, feedback, table, search, modals, layout
│   ├── hooks.js          ← useListData — hook para pantallas de lista
│   ├── roles.js          ← ROUTE_PERMISSION — mapa ruta→permiso granular
│   └── tweaks.jsx        ← Panel de prototipo (dark mode, accent, etc.)
└── screens/         ← Una pantalla por archivo (antes agrupadas en ops.jsx/modules.jsx, ya divididas)
    ├── main.jsx          ← LoginScreen + Dashboard
    ├── ventas.jsx        ← barrel → ventas/ (VentasIndex, VentaNueva, VentaDetail)
    ├── productos.jsx     ← Productos, ProductoDetail
    ├── cotizaciones.jsx  ← Cotizaciones, CotizacionDetail
    ├── caja.jsx          ← Caja
    ├── compras.jsx       ← Compras, CompraDetail
    ├── pedidos.jsx       ← Pedidos, PedidoDetail
    ├── envios.jsx        ← Envios, EnvioDetail
    ├── ajustes.jsx       ← Ajustes
    ├── cuentas.jsx       ← Cuentas, CuentaDetail
    ├── historial-caja.jsx← HistorialCaja
    ├── admin.jsx         ← Sucursales, Usuarios, Roles, Perfil, Marcas, Industrias, Medios,
    │                        Empresas, Localidades (patrón SimpleCrudScreen + toggle ON/OFF)
    ├── estadisticas.jsx  ← barrel → estadisticas/ (paneles: VentasPeriodo, TopProductos, TopClientes, Rotacion)
    └── forms.jsx         ← Modals de formularios (RolFormModal con matriz granular 19×11)
```

### Auth flow (App.jsx)

1. Al montar: lee `localStorage.lcv_token` → llama `auth.me()` para restaurar sesión
2. Si `!user`: muestra `<LoginScreen onLogin={handleLogin} />`
3. `handleLogin(email, pass)`: llama API → guarda token → setUser → navega a dashboard
4. `handleLogout()`: llama API logout → limpia token → setUser(null)
5. `user.sucursal_id` se propaga como tweak `sucursalId` a todas las pantallas

### Estilo visual — "Diamante"

El sistema usa un lenguaje visual propio llamado **Diamante**. El motivo central son rombos geométricos (cuadrados rotados 45°) que crean ritmo visual sin ser recargados. **Este estilo es el aprobado y definitivo — no cambiarlo sin instrucción explícita.**

#### Principios del estilo

| Elemento | Regla |
|----------|-------|
| Motivo decorativo | Cuadrados `rotate(45deg)` apilados en clusters, opacidad 6–11% |
| Fondo claro | `#fff` o `var(--page)` — los diamantes van en esquinas con color accent |
| Fondo oscuro | Gradiente `145deg, #0d1b3e → #182642 → #1a4a8a` — diamantes blancos dispersos |
| Tipografía display | `var(--f-display)` (Space Grotesk), peso 700–900, `letter-spacing: -.02em` |
| Status indicator | Punto verde con glow + texto uppercase + `background: rgba(255,255,255,.08)` |

#### Componentes helper (definir dentro del componente que los usa)

```jsx
// Diamante en panel claro (color accent semi-transparente)
const GEO = ({ s, op = .09, r = 8, style = {} }) => (
  <div style={{ width: s, height: s, background: `rgba(11,126,194,${op})`,
    borderRadius: r, transform: "rotate(45deg)", position: "absolute",
    pointerEvents: "none", ...style }} />
);

// Diamante en panel oscuro (blanco semi-transparente)
const GEO_W = ({ s, op = .06, r = 10, style = {} }) => (
  <div style={{ width: s, height: s, background: `rgba(255,255,255,${op})`,
    borderRadius: r, transform: "rotate(45deg)", position: "absolute",
    pointerEvents: "none", ...style }} />
);
```

#### Cluster de esquina (panel claro — patrón típico)

```jsx
{/* Top-left */}
<GEO s={200} op={.06} style={{top:-90, left:-90}}/>
<GEO s={130} op={.09} style={{top:-40, left:-40}}/>
<GEO s={80}  op={.11} style={{top:28,  left:28}}/>
{/* Bottom-right */}
<GEO s={170} op={.06} style={{bottom:-90, right:-90}}/>
<GEO s={110} op={.09} style={{bottom:-35, right:-35}}/>
<GEO s={65}  op={.10} style={{bottom:35,  right:40}}/>
```

#### Panel oscuro navy (gradiente + diamantes blancos dispersos)

```jsx
<div style={{background:"linear-gradient(145deg,#0d1b3e 0%,#182642 45%,#1a4a8a 100%)", position:"relative", overflow:"hidden"}}>
  <GEO_W s={320} op={.04} r={18} style={{top:-140,  right:-140}}/>
  <GEO_W s={200} op={.06} r={12} style={{top:-50,   right:-50}}/>
  <GEO_W s={130} op={.07} r={9}  style={{top:60,    right:40}}/>
  <GEO_W s={90}  op={.08} r={6}  style={{top:170,   right:120}}/>
  <GEO_W s={280} op={.04} r={16} style={{bottom:-130,left:-130}}/>
  <GEO_W s={180} op={.06} r={11} style={{bottom:-40, left:-40}}/>
  <GEO_W s={110} op={.07} r={7}  style={{bottom:60,  left:50}}/>
  <GEO_W s={70}  op={.09} r={5}  style={{bottom:160, left:140}}/>
  <div style={{position:"relative", zIndex:1}}>
    {/* Contenido con color:"#fff" */}
  </div>
</div>
```

#### Dónde se aplica

| Superficie | Uso |
|------------|-----|
| `LoginScreen` (`main.jsx`) | Panel izquierdo (GEO) + panel derecho navy (GEO_W) |
| Welcome overlay (`App.jsx`) | Fondo navy sobre contenido, logo-white.svg |
| Modals de presentación | Cabecera navy con GEO_W (futuro) |
| Pantallas de error / vacío | Panel decorativo lateral (futuro) |

#### Assets disponibles

```
front/public/assets/
├── logo.svg          ← logo color, para fondos claros
└── logo-white.svg    ← logo blanco, para fondos navy/oscuros
```

---

### Design system (CSS variables)

```css
--navy: #182642          /* institucional primario */
--star: #0b7ec2          /* interactivo, accent por defecto */
--accent: var(--star)    /* se puede cambiar desde TweaksPanel */
--accent-hover           /* lighten(accent, 12%) */
--accent-a15             /* accent rgba 15% */
--accent-a30             /* accent rgba 30% */

--bg: #f4f6fa            /* fondo página */
--surface: #ffffff       /* tarjetas */
--ink: #0f172a           /* texto principal */
--soft: #808da7          /* texto secundario */
--line: #e2e8f0          /* bordes */
--r-md: var(--radius)    /* border radius (10px por defecto) */

--success: #22c55e
--danger: #dc2626
--danger-soft: rgba(220,38,38,.08)
--warning: #f59e0b
```

Clases utilitarias principales:
- `card` — tarjeta blanca con sombra sutil
- `btn btn-accent btn-secondary btn-ghost` — botones
- `tbl` — tabla con estilos
- `badge badge-success badge-warning badge-danger badge-soft`
- `input input-group lead-icon` — campos de formulario
- `field label` — label + input wrapper
- `stack` con `--gap` — flex column gap
- `row` — flex row gap-8
- `grid-4 grid-3 grid-2` — CSS grid
- `seg-tabs seg` — tabs segmentados
- `fade-up` — animación entrada
- `page-head` — cabecera de página
- `kpi-card` — tarjeta KPI

### Componentes UI (lib/components.jsx)

```jsx
<Icon name="fa-solid fa-plus" />
<Button variant="accent|secondary|ghost|danger" size="sm|md|lg" icon="fa-plus">
<Badge variant="success|warning|danger|soft">
<StatusBadge status="VALIDO|PROFORMA|ANULADO" />
<Card>  — tarjeta blanca
<KPI label="Ventas" value="42" sub="último mes" trend={+5} />
<Sparkline data={[1,2,3]} />
<Empty message="Sin resultados" />
<PageHead title="Ventas" sub="..." actions={<></>} />
<Pager page={1} pages={10} onPage={fn} />
<AppLayout current="ventas" onNav={fn} user={user} onLogout={fn} ...>
  → contiene Sidebar + Topbar
```

### Navegación (App.jsx renderScreen)

```
dashboard, estadisticas
ventas, venta-nueva, venta-detail (id), cotizaciones, caja
pedidos, compras, envios, productos, ajustes, cuentas
historial-caja, marcas, industrias, medios
sucursales, usuarios, roles, perfil
```

### Estado de pantallas

**Todas las pantallas están cableadas a la API real** (auditado 2026-05-22). No hay pantallas usando mock como fuente principal.

| Pantalla | Archivo |
|----------|--------|
| LoginScreen, Dashboard | `main.jsx` |
| VentasIndex, VentaNueva, VentaDetail | `ventas.jsx` (barrel → `ventas/`) |
| Productos, ProductoDetail | `productos.jsx` |
| Cotizaciones, CotizacionDetail | `cotizaciones.jsx` |
| Caja | `caja.jsx` |
| Compras, CompraDetail | `compras.jsx` |
| Pedidos, PedidoDetail | `pedidos.jsx` |
| Envios, EnvioDetail | `envios.jsx` |
| Ajustes | `ajustes.jsx` |
| Cuentas, CuentaDetail | `cuentas.jsx` |
| HistorialCaja | `historial-caja.jsx` |
| Sucursales, Usuarios, Roles, Perfil, Marcas, Industrias, Medios, Empresas, Localidades | `admin.jsx` |
| Estadisticas | `estadisticas.jsx` (barrel → `estadisticas/`) |
| RolFormModal, UsuarioFormModal, SucursalFormModal, etc. | `forms.jsx` |

### API service (front/src/services/api.js)

Token interceptor: lee `localStorage.lcv_token`, agrega `Authorization: Bearer <token>`.
BASE_URL: `http://localhost:8000/api`

Exports: `auth`, `ventas`, `compras`, `caja`, `cotizaciones`, `pedidos`, `envios`, `productos`, `cuentas`, `estadisticas`, `sucursales`, `users`, `roles`, `marcas`, `industrias`, `medios`

---

## Contexto del negocio

- **La Casa Volvo** vende repuestos para camiones Volvo en Bolivia
- **5 sucursales** — cada una con su propio stock (`stock1`–`stock5`)
- **Caja** es por sucursal — apertura diaria, movimientos, cierre
- **Cotizaciones** son nuevas en v2 (no existían en legacy)
- **Pedidos** son órdenes internas entre sucursales (traslado de mercadería)
- **Envíos** son despachos a clientes (remitos)
- **Cuentas** registra tanto clientes como proveedores (campo `tipo`: cliente/proveedor/ambos)
- Moneda: **Bolivianos (Bs.)**

---

## Mobile responsive

El sistema es completamente responsive con 4 breakpoints CSS (en `front/src/index.css`):

| Breakpoint | Qué cambia |
|---|---|
| **≤900px** | Sidebar se vuelve overlay (position:fixed, z-index 1000). Aparece botón hamburger ☰ en Topbar. Backdrop semitransparente para cerrar. |
| **≤700px** | Tablas → cards (`.tbl` thead hidden, `td` con `data-label` via `DataTable`). Grids (`.grid-4`, `.grid-3`, `.grid-2`) → 1 columna. |
| **≤600px** | Modales → full-screen (100vw × 100vh, border-radius 0). `.page-head` → flex-wrap. |
| **≤450px** | KPIs → 1 columna. `.seg-tabs` → scroll horizontal. |

### Sidebar mobile (implementación)

```jsx
// AppLayout maneja estado mobileOpen
const [mobileOpen, setMobileOpen] = useState(false);

// Topbar recibe onMobileMenu para el botón hamburger
<Topbar onMobileMenu={() => setMobileOpen(true)} ... />

// Sidebar recibe mobileOpen + onClose
<Sidebar mobileOpen={mobileOpen} onClose={() => setMobileOpen(false)} ... />
```

> **⚠️ Bug fix:** El backdrop del sidebar DEBE ser `position:fixed` (no hijo del CSS Grid `.app-shell`) o rompe el layout desktop. Está definido como estilo base (fuera de media queries) con `display:none`, y el media query de ≤900px lo activa con `display:block`.

### DataTable responsive

El componente `DataTable` en `components.jsx` agrega `data-label` a cada `<td>` para que el CSS `@media (max-width:700px)` muestre:
```css
.tbl td::before { content: attr(data-label); font-weight:700; ... }
```

---

## Bugs comunes y soluciones

### 1. `number_format()` rompe `parseFloat()` en el frontend
**Problema:** `number_format(1200, 2)` → `"1,200.00"` → `parseFloat("1,200.00")` → `1` (la coma de miles se interpreta como decimal).
**Solución:** La API retorna valores numéricos `(float)` sin formatear. El frontend formatea con `.toLocaleString('es-BO')` solo para display.

### 2. `Promise.all` — índices corridos al agregar rutas
**Problema:** Al agregar `show()` al array de promesas sin ajustar el destructuring `.then()`, todos los índices se desplazan.
**Solución:** Siempre revisar el orden del array de promesas y su destructuring correspondiente. Agregar nuevas promesas al **inicio** del array para no desplazar índices existentes.

### 3. DECIMAL(9,2) overflow en cierres
**Problema:** `cierres.egresos` de 22M rompía `DECIMAL(9,2)` (máx 9,999,999.99).
**Solución:** Migración `2026_05_26_000001` → `DECIMAL(12,2)` (máx 999,999,999,999.99).

### 4. CSS cascade — media queries pisadas por estilos base
**Problema:** Si los estilos base del sidebar están DESPUÉS del media query en el CSS, pisan los estilos responsive.
**Solución:** Las media queries siempre van al **final** de su sección en `index.css`. Estilos base primero, overrides responsive después.

### 5. phpMyAdmin no importa dumps grandes
**Problema:** Dump SQL de 71MB excede `upload_max_filesize` y `max_execution_time` de phpMyAdmin.
**Solución:** Comprimir a ZIP (~15MB), extraer en cPanel File Manager. Para migraciones: crear `.user.ini` con `max_execution_time=300`.

---

## Deploy — referencias rápidas

| Recurso | Ubicación |
|---|---|
| Credenciales FTP y DB producción | `docs/DEPLOY.md` (gitignored, NO commitear) |
| Script de deploy FTP | `api/scripts/deploy/upload-ftp.ps1` |
| Script de setup post-deploy | `api/scripts/deploy/setup.php` |
| Build staging | `cd front && npm run build -- --mode staging` |
| Build producción | `cd front && npm run build` |

### URLs

| Entorno | URL |
|---|---|
| API (producción + staging) | `https://api.lacasavolvo.com` |
| Frontend staging | `https://staging.lacasavolvo.com` |
| Frontend producción | `https://lacasavolvo.com` |
| Local dev API | `http://localhost:8000` |
| Local dev frontend | `http://localhost:3000` |

---

## Git

Rama principal: `master`  
Primer commit: `cc899ef` — monorepo inicial con api/ + front/

---

## Archivos de referencia fuera del monorepo

- `api/tienda (1).sql` — dump de referencia de la BD (snapshot 2026-05-16)
