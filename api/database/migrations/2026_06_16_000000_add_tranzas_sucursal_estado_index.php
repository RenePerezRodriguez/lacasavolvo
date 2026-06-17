<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice compuesto (sucursal_id, estado, fecha) en `tranzas`.
 *
 * `tranzas` solo tenía PRIMARY y `tranzas_fecha_idx` (fecha). Las queries calientes de
 * caja (KPIs, movimientos, cierre, historial) SIEMPRE filtran por `sucursal_id` + `estado`
 * y un rango de `fecha`. Con solo el índice de fecha, MySQL escanea TODO el rango de fecha
 * (que abarca todas las sucursales) y filtra `sucursal_id`/`estado` fila a fila — coste que
 * crece con el nº de tranzas de TODA la red en ese período (medido: ~6.5k filas escaneadas
 * para un rango anual en `tienda` dev, 31k filas totales).
 *
 * El índice compuesto permite saltar directo a (sucursal_id, estado) y luego hacer range
 * scan sobre `fecha` dentro de esa partición — coste acotado a las tranzas de ESA sucursal.
 * El orden de columnas (sucursal_id, estado, fecha) respeta el patrón left-prefix: cubre
 * tanto `WHERE sucursal_id=? AND estado=? AND fecha BETWEEN ...` como `WHERE sucursal_id=?`.
 *
 * Complementa (no reemplaza) a `tranzas_fecha_idx`, que sigue sirviendo a queries que
 * filtran por fecha sin sucursal (estadísticas globales).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('tranzas', 'tranzas_sucursal_estado_fecha_idx')) {
            Schema::table('tranzas', function (Blueprint $t) {
                $t->index(['sucursal_id', 'estado', 'fecha'], 'tranzas_sucursal_estado_fecha_idx');
            });
        }
    }

    public function down(): void
    {
        // Blueprint no tiene dropIndexIfExists; se guarda con Schema::hasIndex + dropIndex.
        if (Schema::hasIndex('tranzas', 'tranzas_sucursal_estado_fecha_idx')) {
            Schema::table('tranzas', function (Blueprint $t) {
                $t->dropIndex('tranzas_sucursal_estado_fecha_idx');
            });
        }
    }
};
