<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\EnvioController;
use App\Http\Controllers\EstadisticaController;
use App\Http\Controllers\IndustriaController;
use App\Http\Controllers\LocalidadController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\MedioController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

// ── Auth pública ──────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ── Info pública para el login (sin token): SOLO el conteo de sucursales activas ──
// Vista pública → expone únicamente el número, nada sensible. Throttle para evitar abuso.
Route::get('/public-info', [SucursalController::class, 'publicInfo'])->middleware('throttle:30,1');

// ── Protegidas (Bearer token Sanctum) ────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user',   [AuthController::class, 'me']);
    Route::post('/logout',[AuthController::class, 'logout']);

    // ── Búsqueda rápida de productos (usada en modals de toda la app) ──────
    Route::get('/productos/quicksearch', [ProductoController::class, 'apiQuickSearch']);

    // ── Ventas ────────────────────────────────────────────────────────────
    // Permiso POR RUTA (no OR de grupo): lectura→index, escritura→create, anular→destroy.
    // Respeta la matriz del seeder: CAJERO/VENDEDOR pueden operar pero NO anular; solo
    // GERENTE/ADMIN anulan. (Antes el OR de grupo dejaba a un rol de solo-lectura escribir/anular.)
    Route::prefix('ventas')->group(function () {
        Route::get('/',                        [VentaController::class, 'api'])->middleware('permission:ventas.index');
        Route::get('/kpis',                    [VentaController::class, 'kpis'])->middleware('permission:ventas.index');
        Route::post('/',                       [VentaController::class, 'store'])->middleware('permission:ventas.create');
        Route::post('/update-encabezado',      [VentaController::class, 'updateEncabezado'])->middleware('permission:ventas.create');
        Route::post('/agregar-item',           [VentaController::class, 'agregarItem'])->middleware('permission:ventas.create');
        Route::post('/update-item',            [VentaController::class, 'updateItem'])->middleware('permission:ventas.create');
        Route::post('/delete-item/{detalle}',  [VentaController::class, 'deleteItem'])->middleware('permission:ventas.create');
        Route::post('/validar/{venta}',        [VentaController::class, 'validar'])->middleware('permission:ventas.create');
        Route::post('/dev-item',               [VentaController::class, 'devItem'])->middleware('permission:ventas.create');
        Route::post('/delete-item-dev',        [VentaController::class, 'deleteItemDev'])->middleware('permission:ventas.create');
        Route::post('/cobrar',                 [VentaController::class, 'cobrarVenta'])->middleware('permission:ventas.create');
        Route::post('/negativos',              [VentaController::class, 'negativos'])->middleware('permission:ventas.index');
        Route::get('/{venta}/detalles',        [VentaController::class, 'apiDetalles'])->middleware('permission:ventas.index');
        Route::get('/{venta}/devoluciones',    [VentaController::class, 'apiDevoluciones'])->middleware('permission:ventas.index');
        Route::get('/{venta}/cobros',          [VentaController::class, 'apiCobros'])->middleware('permission:ventas.index');
        Route::get('/{venta}/pdf',             [VentaController::class, 'pdf'])->middleware('permission:ventas.index');
        Route::delete('/{venta}',              [VentaController::class, 'destroy'])->middleware('permission:ventas.destroy');
    });

    // ── Compras ───────────────────────────────────────────────────────────
    // Permiso POR RUTA: lectura→index, escritura→create, anular→destroy.
    // Seeder: VENDEDOR/CAJERO solo index+show (quedan solo-lectura); OPERADOR/GERENTE crean.
    Route::prefix('compras')->group(function () {
        Route::get('/',                        [CompraController::class, 'api'])->middleware('permission:compras.index');
        Route::get('/kpis',                    [CompraController::class, 'kpis'])->middleware('permission:compras.index');
        Route::post('/',                       [CompraController::class, 'store'])->middleware('permission:compras.create');
        Route::post('/update-encabezado',      [CompraController::class, 'updateEncabezado'])->middleware('permission:compras.create');
        Route::post('/agregar-item',           [CompraController::class, 'agregarItem'])->middleware('permission:compras.create');
        Route::post('/update-item',            [CompraController::class, 'updateItem'])->middleware('permission:compras.create');
        Route::post('/delete-item/{detalle}',  [CompraController::class, 'deleteItem'])->middleware('permission:compras.create');
        Route::post('/validar/{compra}',       [CompraController::class, 'validar'])->middleware('permission:compras.create');
        Route::post('/dev-item',               [CompraController::class, 'devItem'])->middleware('permission:compras.create');
        Route::post('/delete-item-dev',        [CompraController::class, 'deleteItemDev'])->middleware('permission:compras.create');
        Route::post('/pagar',                  [CompraController::class, 'pagarCompra'])->middleware('permission:compras.create');
        Route::get('/{compra}',                 [CompraController::class, 'show'])->middleware('permission:compras.index');
        Route::get('/{compra}/detalles',       [CompraController::class, 'apiDetalles'])->middleware('permission:compras.index');
        Route::get('/{compra}/devoluciones',   [CompraController::class, 'apiDevoluciones'])->middleware('permission:compras.index');
        Route::get('/{compra}/pagos',          [CompraController::class, 'apiPagos'])->middleware('permission:compras.index');
        Route::get('/{compra}/pdf',            [CompraController::class, 'pdf'])->middleware('permission:compras.index');
        Route::delete('/{compra}',             [CompraController::class, 'destroy'])->middleware('permission:compras.destroy');
    });

    // ── Caja ──────────────────────────────────────────────────────────────
    Route::middleware('permission:caja.index|caja.show|caja.cierre|caja.destroy|caja.print')->prefix('caja')->group(function () {
        Route::get('/kpis',                    [CajaController::class, 'kpis']);
        Route::get('/movimientos',             [CajaController::class, 'movimientos']);
        Route::post('/apertura',               [CajaController::class, 'apertura']);
        // Cierre y revertir-cierre exigen caja.cierre específico (fiel al legacy:
        // caja/cierre_caja → permission:caja.cierre). El OR de grupo dejaba cerrar con
        // solo caja.index. Los roles que cierran (VENDEDOR/CAJERO/GERENTE) ya lo tienen.
        Route::post('/cierre',                 [CajaController::class, 'cierre'])->middleware('permission:caja.cierre');
        Route::post('/revertir-cierre',         [CajaController::class, 'revertirCierre'])->middleware('permission:caja.cierre');
        Route::post('/ingreso',                [CajaController::class, 'ingresar']);
        Route::post('/egreso',                 [CajaController::class, 'egresar']);
        Route::post('/update-tranza',          [CajaController::class, 'updateTranza']);
        Route::post('/delete-tranza',          [CajaController::class, 'deleteTranza']);
        Route::get('/report',                  [CajaController::class, 'reportCaja']);
        Route::get('/historial/tranzas',       [CajaController::class, 'apiHistorialTranzas']);
        Route::get('/historial/compras',       [CajaController::class, 'apiHistorialCompras']);
        Route::get('/historial/ventas',        [CajaController::class, 'apiHistorialVentas']);
        Route::get('/historial/efectivos',     [CajaController::class, 'apiHistorialEfectivos']);
        Route::get('/aperturas',               [CajaController::class, 'apiAperturas']);
        // Cierres (réplica del legacy "Lista de Cierres" + ojito): lista, detalle y PDF por cierre.
        // Definidas antes de las rutas con comodín `/{apertura}/…` para que `cierres` no se interprete
        // como un id de apertura (segmento literal gana, pero se ordena explícito por claridad).
        Route::get('/cierres',                  [CajaController::class, 'apiCierres']);
        Route::get('/cierres/{cierre}/detalle', [CajaController::class, 'apiCierreDetalle']);
        Route::get('/cierres/{cierre}/pdf',     [CajaController::class, 'cierrePdf']);
        Route::get('/{apertura}/tranzas',      [CajaController::class, 'apiTranzas']);
        Route::get('/{apertura}/compras',      [CajaController::class, 'apiCompras']);
        Route::get('/{apertura}/ventas',       [CajaController::class, 'apiVentas']);
    });

    // ── Cotizaciones ──────────────────────────────────────────────────────
    // Permiso POR RUTA: lectura→index, escritura→create, anular→destroy.
    // La conversión cotización→venta es escritura (create); el usuario que la opera
    // (VENDEDOR/GERENTE) tiene cotizaciones.create y ventas.create.
    Route::prefix('cotizaciones')->group(function () {
        Route::get('/',                            [CotizacionController::class, 'api'])->middleware('permission:cotizaciones.index');
        Route::get('/kpis',                        [CotizacionController::class, 'kpis'])->middleware('permission:cotizaciones.index');
        Route::post('/',                           [CotizacionController::class, 'store'])->middleware('permission:cotizaciones.create');
        Route::post('/update-encabezado',          [CotizacionController::class, 'updateEncabezado'])->middleware('permission:cotizaciones.create');
        Route::post('/agregar-item',               [CotizacionController::class, 'agregarItem'])->middleware('permission:cotizaciones.create');
        Route::post('/update-item',                [CotizacionController::class, 'updateItem'])->middleware('permission:cotizaciones.create');
        Route::post('/delete-item/{detalle}',      [CotizacionController::class, 'deleteItem'])->middleware('permission:cotizaciones.create');
        Route::post('/{cotizacion}/venta',         [CotizacionController::class, 'ventaCotizacion'])->middleware('permission:cotizaciones.create');
        Route::get('/{cotizacion}',                [CotizacionController::class, 'show'])->middleware('permission:cotizaciones.index');
        Route::get('/{cotizacion}/detalles',       [CotizacionController::class, 'apiDetalles'])->middleware('permission:cotizaciones.index');
        Route::get('/{cotizacion}/pdf',            [CotizacionController::class, 'pdf'])->middleware('permission:cotizaciones.index');
        Route::delete('/{cotizacion}',             [CotizacionController::class, 'destroy'])->middleware('permission:cotizaciones.destroy');
    });

    // ── Pedidos ───────────────────────────────────────────────────────────
    // Permiso POR RUTA: lectura→index, escritura→create, anular→destroy.
    Route::prefix('pedidos')->group(function () {
        Route::get('/',                        [PedidoController::class, 'api'])->middleware('permission:pedidos.index');
        Route::get('/kpis',                    [PedidoController::class, 'kpis'])->middleware('permission:pedidos.index');
        Route::post('/',                       [PedidoController::class, 'store'])->middleware('permission:pedidos.create');
        Route::post('/update-encabezado',      [PedidoController::class, 'updateEncabezado'])->middleware('permission:pedidos.create');
        Route::post('/agregar-item',           [PedidoController::class, 'agregarItem'])->middleware('permission:pedidos.create');
        Route::post('/update-item',            [PedidoController::class, 'updateItem'])->middleware('permission:pedidos.create');
        Route::post('/delete-item/{detalle}',  [PedidoController::class, 'deleteItem'])->middleware('permission:pedidos.create');
        Route::post('/validar/{pedido}',       [PedidoController::class, 'validar'])->middleware('permission:pedidos.create');
        Route::get('/{pedido}',                [PedidoController::class, 'show'])->middleware('permission:pedidos.index');
        Route::get('/{pedido}/detalles',       [PedidoController::class, 'apiDetalles'])->middleware('permission:pedidos.index');
        Route::get('/{pedido}/pdf',            [PedidoController::class, 'pdf'])->middleware('permission:pedidos.index');
        Route::delete('/{pedido}',             [PedidoController::class, 'destroy'])->middleware('permission:pedidos.destroy');
    });

    // ── Envíos ────────────────────────────────────────────────────────────
    // Permiso POR RUTA: lectura→index, escritura→create (incluye enviar/recibir
    // traslados), anular→destroy. Seeder: OPERADOR/GERENTE crean; VENDEDOR solo lee.
    Route::prefix('envios')->group(function () {
        Route::get('/',                        [EnvioController::class, 'api'])->middleware('permission:envios.index');
        Route::get('/kpis',                    [EnvioController::class, 'kpis'])->middleware('permission:envios.index');
        Route::post('/',                       [EnvioController::class, 'store'])->middleware('permission:envios.create');
        Route::post('/update-encabezado',      [EnvioController::class, 'updateEncabezado'])->middleware('permission:envios.create');
        Route::post('/agregar-item',           [EnvioController::class, 'agregarItem'])->middleware('permission:envios.create');
        Route::post('/update-item',            [EnvioController::class, 'updateItem'])->middleware('permission:envios.create');
        Route::post('/delete-item/{detalle}',  [EnvioController::class, 'deleteItem'])->middleware('permission:envios.create');
        Route::post('/enviar/{envio}',         [EnvioController::class, 'enviar'])->middleware('permission:envios.create');
        Route::post('/recibir/{envio}',        [EnvioController::class, 'recibir'])->middleware('permission:envios.create');
        Route::post('/dev-item',               [EnvioController::class, 'devItem'])->middleware('permission:envios.create');
        Route::post('/delete-item-dev',        [EnvioController::class, 'deleteItemDev'])->middleware('permission:envios.create');
        Route::post('/negativos',              [EnvioController::class, 'negativos'])->middleware('permission:envios.index');
        Route::get('/{envio}',                 [EnvioController::class, 'show'])->middleware('permission:envios.index');
        Route::get('/{envio}/detalles',        [EnvioController::class, 'apiDetalles'])->middleware('permission:envios.index');
        Route::get('/{envio}/devoluciones',    [EnvioController::class, 'apiDevoluciones'])->middleware('permission:envios.index');
        Route::get('/{envio}/pdf',             [EnvioController::class, 'pdf'])->middleware('permission:envios.index');
        Route::delete('/{envio}',              [EnvioController::class, 'destroy'])->middleware('permission:envios.destroy');
    });

    // ── Productos ─────────────────────────────────────────────────────────
    Route::middleware('permission:productos.index|productos.show|productos.create|productos.edit|productos.destroy|productos.ajustes')->prefix('productos')->group(function () {
        Route::get('/',                        [ProductoController::class, 'api']);
        Route::get('/kpis',                    [ProductoController::class, 'kpis']);
        Route::post('/',                       [ProductoController::class, 'store']);
        Route::get('/ajustes',                 [ProductoController::class, 'apiAjustes']);
        Route::post('/ajuste-positivo',        [ProductoController::class, 'ajustePositivo']);
        Route::post('/ajuste-negativo',        [ProductoController::class, 'ajusteNegativo']);
        Route::post('/ajuste-destroy',         [ProductoController::class, 'ajusteDestroy']);
        Route::get('/{producto}',              [ProductoController::class, 'show']);
        Route::get('/{producto}/movimientos',  [ProductoController::class, 'movimientos']);
        Route::put('/{producto}',              [ProductoController::class, 'update']);
        Route::delete('/{producto}',           [ProductoController::class, 'destroy']);
    });

    // ── Cuentas ───────────────────────────────────────────────────────────
    Route::middleware('permission:cuentas.index|cuentas.show|cuentas.create|cuentas.edit|cuentas.destroy')->prefix('cuentas')->group(function () {
        Route::get('/',                        [CuentaController::class, 'apiList']);
        Route::get('/kpis',                    [CuentaController::class, 'kpis']);
        Route::post('/',                       [CuentaController::class, 'store']);
        Route::put('/{cuenta}',                [CuentaController::class, 'update']);
        Route::get('/{cuenta}/toggle',         [CuentaController::class, 'toggle']);
        Route::get('/{cuenta}/compras',        [CuentaController::class, 'apiCompras']);
        Route::get('/{cuenta}/ventas',         [CuentaController::class, 'apiVentas']);
        Route::get('/{cuenta}/pagos',          [CuentaController::class, 'apiPagos']);
        Route::get('/{cuenta}/cobros',         [CuentaController::class, 'apiCobros']);
        Route::get('/{cuenta}',                [CuentaController::class, 'apiShow']);
    });

    // ── Dashboard (inicio) — cualquier rol autenticado, acotado a la sucursal activa ──
    // A diferencia de /estadisticas (ADMIN/GERENTE), el inicio lo ve todo el mundo pero
    // solo de SU sucursal. Sin middleware de permiso: basta estar logueado.
    Route::prefix('dashboard')->group(function () {
        Route::get('/ventas-periodo', [EstadisticaController::class, 'dashboardVentasPeriodo']);
        Route::get('/top-productos',  [EstadisticaController::class, 'dashboardTopProductos']);
    });

    // ── Estadísticas ──────────────────────────────────────────────────────
    Route::prefix('estadisticas')->group(function () {
        Route::get('/rotacion',                    [EstadisticaController::class, 'rotacion']);
        Route::get('/rotacion-detalle/{compra}',   [EstadisticaController::class, 'rotacionDetalle']);
        Route::get('/rotacion-sucursal',           [EstadisticaController::class, 'rotacionSucursal']);
        Route::get('/ventas-periodo',              [EstadisticaController::class, 'ventasPeriodo']);
        Route::get('/top-productos',               [EstadisticaController::class, 'topProductos']);
        Route::get('/top-clientes',                [EstadisticaController::class, 'topClientes']);
        Route::get('/exportar-rotacion',           [EstadisticaController::class, 'exportarRotacion']);
        Route::get('/exportar-rotacion-sucursal',  [EstadisticaController::class, 'exportarRotacionSucursal']);
        Route::get('/exportar-ventas-periodo',     [EstadisticaController::class, 'exportarVentasPeriodo']);
        Route::get('/exportar-top-productos',      [EstadisticaController::class, 'exportarTopProductos']);
        Route::get('/exportar-top-clientes',       [EstadisticaController::class, 'exportarTopClientes']);
    });

    // ── Cambio de sucursal activa (cualquier usuario autenticado) ────────
    Route::post('/switch-sucursal', [AuthController::class, 'switchSucursal']);

    // ── Lectura pública (autenticados) — necesaria para selectores de la UI ─
    Route::get('/sucursales',   [SucursalController::class, 'api']);
    Route::get('/roles',        [RoleController::class, 'api']);
    Route::get('/permissions',  [RoleController::class, 'permissions']);

    // ── Administración ────────────────────────────────────────────────────

    // ── Sucursales (write ops) ──────────────────────────────────────────
    Route::middleware('permission:sucursales.create|sucursales.edit|sucursales.destroy')->prefix('sucursales')->group(function () {
        Route::post('/',                   [SucursalController::class, 'store']);
        Route::put('/{sucursal}',          [SucursalController::class, 'update']);
        Route::delete('/{sucursal}',       [SucursalController::class, 'destroy']);
        Route::get('/{sucursal}/toggle',   [SucursalController::class, 'toggle']);
    });

    // ── Usuarios ─────────────────────────────────────────────────────────
    Route::prefix('users')->group(function () {
        Route::middleware('permission:users.index|users.show|users.edit|users.destroy')->group(function () {
            Route::get('/',                                        [UserController::class, 'api']);
            Route::post('/',                                       [UserController::class, 'store']);
            Route::put('/{user}',                                  [UserController::class, 'update']);
            Route::delete('/{user}',                               [UserController::class, 'destroy']);
            Route::get('/{user}/{sucursal}/{acceso}/acces',        [UserController::class, 'acces']);
        });
        // Simulador: ADMIN y GERENTE pueden simular roles (fuera de permission:users.*)
        Route::middleware('role:ADMIN|GERENTE')->group(function () {
            Route::post('/simulate-role',   [UserController::class, 'simulateRole']);
            Route::post('/stop-simulate',   [UserController::class, 'stopSimulate']);
        });
    });

    // ── Roles (write ops) ────────────────────────────────────────────────
    Route::middleware('permission:roles.create|roles.edit|roles.destroy')->prefix('roles')->group(function () {
        Route::post('/',               [RoleController::class, 'store']);
        Route::put('/{role}',          [RoleController::class, 'update']);
        Route::delete('/{role}',       [RoleController::class, 'destroy']);
    });

    // ── Catálogos (Datos Raíz) ───────────────────────────────────────────
    Route::middleware('permission:marcas.index|marcas.show|marcas.create|marcas.edit|marcas.destroy')->prefix('marcas')->group(function () {
        Route::get('/',                    [MarcaController::class, 'api']);
        Route::post('/',                   [MarcaController::class, 'store']);
        Route::put('/{marca}',             [MarcaController::class, 'update']);
        Route::get('/{marca}/toggle',      [MarcaController::class, 'toggle']);
    });

    Route::middleware('permission:industrias.index|industrias.show|industrias.create|industrias.edit|industrias.destroy')->prefix('industrias')->group(function () {
        Route::get('/',                        [IndustriaController::class, 'api']);
        Route::post('/',                       [IndustriaController::class, 'store']);
        Route::put('/{industria}',             [IndustriaController::class, 'update']);
        Route::get('/{industria}/toggle',      [IndustriaController::class, 'toggle']);
    });

    Route::middleware('permission:medios.index|medios.show|medios.create|medios.edit|medios.destroy')->prefix('medios')->group(function () {
        Route::get('/',                [MedioController::class, 'api']);
        Route::post('/',               [MedioController::class, 'store']);
        Route::put('/{medio}',         [MedioController::class, 'update']);
        Route::get('/{medio}/toggle',  [MedioController::class, 'toggle']);
    });

    Route::middleware('permission:empresas.index|empresas.show|empresas.create|empresas.edit|empresas.destroy')->prefix('empresas')->group(function () {
        Route::get('/',                        [EmpresaController::class, 'api']);
        Route::post('/',                       [EmpresaController::class, 'store']);
        Route::put('/{empresa}',               [EmpresaController::class, 'update']);
        Route::delete('/{empresa}',            [EmpresaController::class, 'destroy']);
        Route::get('/{empresa}/toggle',        [EmpresaController::class, 'toggle']);
        Route::get('/{empresa}/cuentas',       [EmpresaController::class, 'cuentasJson']);
    });

    Route::middleware('permission:localidades.index|localidades.show|localidades.create|localidades.edit|localidades.destroy')->prefix('localidades')->group(function () {
        Route::get('/',                    [LocalidadController::class, 'api']);
        Route::post('/',                   [LocalidadController::class, 'store']);
        Route::put('/{localidad}',         [LocalidadController::class, 'update']);
        Route::get('/{localidad}/toggle',  [LocalidadController::class, 'toggle']);
    });

    Route::put('/profile', [UserController::class, 'updateProfile']);
});
