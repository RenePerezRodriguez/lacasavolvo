<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds missing secondary indexes.
 *
 * Already indexed in production (via legacy migrations):
 *   ventas(sucursal_id, cuenta_id), ventadetalles(venta_id, producto_id),
 *   compras(sucursal_id, cuenta_id), compradetalles(compra_id, producto_id),
 *   tranzas(sucursal_id, estado), aperturas(sucursal_id),
 *   cierres(apertura_id, sucursal_id), envios(sucursal_id, cuenta_id),
 *   enviodetalles(envio_id, producto_id), pedidos(sucursal_id)
 *
 * This migration fills the gaps: fecha filters, estado filters on ventas/compras,
 *   cotizaciondetalles, pedidodetalles, ajustes, devventas, devcompras, productos.
 */
return new class extends Migration
{
    private function addIndex(string $table, array|string $cols, string $name): void
    {
        if (!Schema::hasIndex($table, $name)) {
            Schema::table($table, function (Blueprint $t) use ($cols, $name) {
                $t->index((array) $cols, $name);
            });
        }
    }

    public function up(): void
    {
        // ── ventas ────────────────────────────────────────────────────────
        // Historial / estadísticas filtran por estado dentro de una sucursal
        $this->addIndex('ventas', ['sucursal_id', 'estado'],      'ventas_sucursal_estado_idx');
        // Historial filtra por rango de fecha
        $this->addIndex('ventas', ['fecha'],                       'ventas_fecha_idx');

        // ── compras ───────────────────────────────────────────────────────
        $this->addIndex('compras', ['sucursal_id', 'estado'],     'compras_sucursal_estado_idx');
        $this->addIndex('compras', ['fecha'],                      'compras_fecha_idx');

        // ── tranzas ───────────────────────────────────────────────────────
        // Historial de caja: WHERE sucursal_id AND fecha BETWEEN
        $this->addIndex('tranzas', ['fecha'],                      'tranzas_fecha_idx');

        // ── aperturas ─────────────────────────────────────────────────────
        // Buscar caja abierta: WHERE sucursal_id = ? AND cerrado = 'NO'
        $this->addIndex('aperturas', ['sucursal_id', 'cerrado'],   'aperturas_sucursal_cerrado_idx');
        $this->addIndex('aperturas', ['fecha'],                    'aperturas_fecha_idx');

        // ── cotizaciondetalles ─────────────────────────────────────────────
        // Sin ningún índice: show de cotización carga sus ítems por cotizacion_id
        $this->addIndex('cotizaciondetalles', ['cotizacion_id'],   'cotd_cotizacion_id_idx');
        $this->addIndex('cotizaciondetalles', ['producto_id'],     'cotd_producto_id_idx');

        // ── pedidodetalles ────────────────────────────────────────────────
        // 28k filas sin índice: show de pedido por pedido_id
        $this->addIndex('pedidodetalles', ['pedido_id'],           'pdd_pedido_id_idx');
        $this->addIndex('pedidodetalles', ['producto_id'],         'pdd_producto_id_idx');

        // ── productos ─────────────────────────────────────────────────────
        // api/kpis: WHERE estado IN ('ON','DES') — 6836 productos
        $this->addIndex('productos', ['estado'],                   'productos_estado_idx');
        // Filtros de catálogo
        $this->addIndex('productos', ['marca_id'],                 'productos_marca_id_idx');
        $this->addIndex('productos', ['industria_id'],             'productos_industria_id_idx');
        // Búsqueda rápida / quicksearch por código
        $this->addIndex('productos', ['codigo'],                   'productos_codigo_idx');

        // ── ajustes ───────────────────────────────────────────────────────
        // Movimientos de producto: ajustes por producto en una sucursal (3292 filas)
        $this->addIndex('ajustes', ['producto_id', 'sucursal_id'], 'ajustes_producto_sucursal_idx');
        $this->addIndex('ajustes', ['estado'],                     'ajustes_estado_idx');

        // ── devventas ─────────────────────────────────────────────────────
        $this->addIndex('devventas', ['venta_id'],                 'devv_venta_id_idx');
        $this->addIndex('devventas', ['producto_id'],              'devv_producto_id_idx');

        // ── devcompras ────────────────────────────────────────────────────
        $this->addIndex('devcompras', ['compra_id'],               'devc_compra_id_idx');
        $this->addIndex('devcompras', ['producto_id'],             'devc_producto_id_idx');

        // ── cuentas ───────────────────────────────────────────────────────
        // EmpresaController.cuentasJson() filtra por empresa_id
        $this->addIndex('cuentas', ['empresa_id'],                 'cuentas_empresa_id_idx');

        // ── devenvios ─────────────────────────────────────────────────────
        // Estadísticas FIFO filtra por producto_id + sucursal_id
        $this->addIndex('devenvios', ['producto_id'],              'devenvios_producto_id_idx');
        $this->addIndex('devenvios', ['sucursal_id'],              'devenvios_sucursal_id_idx');
    }

    public function down(): void
    {
        $indexes = [
            'ventas'             => ['ventas_sucursal_estado_idx', 'ventas_fecha_idx'],
            'compras'            => ['compras_sucursal_estado_idx', 'compras_fecha_idx'],
            'tranzas'            => ['tranzas_fecha_idx'],
            'aperturas'          => ['aperturas_sucursal_cerrado_idx', 'aperturas_fecha_idx'],
            'cotizaciondetalles' => ['cotd_cotizacion_id_idx', 'cotd_producto_id_idx'],
            'pedidodetalles'     => ['pdd_pedido_id_idx', 'pdd_producto_id_idx'],
            'productos'          => ['productos_estado_idx', 'productos_marca_id_idx', 'productos_industria_id_idx', 'productos_codigo_idx'],
            'ajustes'            => ['ajustes_producto_sucursal_idx', 'ajustes_estado_idx'],
            'devventas'          => ['devv_venta_id_idx', 'devv_producto_id_idx'],
            'devcompras'         => ['devc_compra_id_idx', 'devc_producto_id_idx'],
            'cuentas'            => ['cuentas_empresa_id_idx'],
            'devenvios'          => ['devenvios_producto_id_idx', 'devenvios_sucursal_id_idx'],
        ];

        foreach ($indexes as $table => $names) {
            Schema::table($table, function (Blueprint $t) use ($names) {
                foreach ($names as $name) {
                    $t->dropIndexIfExists($name);
                }
            });
        }
    }
};
