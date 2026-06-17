<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Tranza;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auditoría adversarial de PERFORMANCE (dimensión nunca atacada en loops previos).
 *
 * NO mide micro-optimizaciones. Caza CLIFFS de escalabilidad — costes que crecen
 * con el nº de filas — con evidencia real (SQL emitido + EXPLAIN contra tienda_test).
 *
 * Hallazgos defendidos por esta suite:
 *  1. whereDate() sobre una columna DATE envuelve `fecha` en `date(fecha)` (CAST), lo
 *     que INUTILIZA el índice `*_fecha_idx`. En `tranzas` (sin índice de sucursal) la
 *     query de caja caía a FULL TABLE SCAN (31k filas en dev), coste lineal en filas.
 *     La columna `fecha` es DATE en todas las tablas → el CAST es semánticamente un
 *     no-op pero rompe el índice. Fix: where() plano (misma semántica, índice usable).
 *  2. Las listas (ventas/compras/...) hacen eager loading de `cuenta`/`sucursal`; el
 *     query-count debe ser CONSTANTE respecto al nº de filas devueltas (no N+1).
 *
 * Determinismo: se asegura el SQL EMITIDO por el endpoint (no depende del optimizador).
 * El EXPLAIN se siembra con suficientes filas para que el índice sea inequívocamente
 * preferible, mostrando la transición scan→range.
 *
 * DB sintética `tienda_test`, factories, DatabaseTransactions (rollback).
 * PROHIBIDO DDL inline: los índices viven en migraciones, no acá.
 */
class PerformanceAuditTest extends TestCase
{
    /**
     * Captura el query log mientras se ejecuta $fn y devuelve las queries registradas.
     *
     * @param callable $fn
     * @return array<int,array{query:string,bindings:array,time:float}>
     */
    private function capturarQueries(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        return $log;
    }

    /** Concatena el SQL de todas las queries de un log, en minúsculas, para inspección. */
    private function sqlPlano(array $log): string
    {
        return strtolower(implode("\n", array_map(fn($q) => $q['query'], $log)));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. CLIFF: whereDate() rompe el índice de `fecha` (full scan en tranzas)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El endpoint de KPIs de caja filtra `tranzas` por rango de fecha. Si emite
     * `date(fecha)` (whereDate sobre una columna DATE), el índice `tranzas_fecha_idx`
     * queda inutilizado y MySQL hace un FULL TABLE SCAN — coste lineal en el nº de
     * tranzas (31k+ en producción). El SQL NO debe envolver `fecha` en `date()`.
     */
    public function test_caja_kpis_no_envuelve_fecha_en_date_rompiendo_el_indice(): void
    {
        $this->actingAsUser('ADMIN');

        $log = $this->capturarQueries(function () {
            $this->getJson('/api/caja/kpis?fecha_desde=2026-06-01&fecha_hasta=2026-06-16')
                ->assertStatus(200);
        });

        $sql = $this->sqlPlano($log);

        // Debe consultar tranzas filtrando por fecha…
        $this->assertStringContainsString('from `tranzas`', $sql, 'caja/kpis debe consultar tranzas');

        // …pero SIN `date(`fecha`)`: ese CAST inutiliza el índice tranzas_fecha_idx
        // y degrada a full scan (coste lineal en filas). La columna es DATE: where() plano basta.
        $this->assertStringNotContainsString('date(`fecha`)', $sql,
            'caja/kpis envuelve `fecha` en date() (whereDate): inutiliza el índice y hace full scan de tranzas. Usar where() plano sobre la columna DATE.');
    }

    /**
     * El listado de movimientos de caja (count + page) tampoco debe envolver `fecha`.
     */
    public function test_caja_movimientos_no_envuelve_fecha_en_date(): void
    {
        $this->actingAsUser('ADMIN');

        $log = $this->capturarQueries(function () {
            $this->getJson('/api/caja/movimientos?fecha_desde=2026-06-01&fecha_hasta=2026-06-16')
                ->assertStatus(200);
        });

        $sql = $this->sqlPlano($log);
        $this->assertStringContainsString('from `tranzas`', $sql);
        $this->assertStringNotContainsString('date(`fecha`)', $sql,
            'caja/movimientos envuelve `fecha` en date(): full scan de tranzas. Usar where() plano.');
    }

    /**
     * El listado de ventas, con filtro de rango de fecha, no debe envolver `fecha`.
     * Ventas SÍ tiene índice (sucursal_id, estado) de respaldo, pero el cast igual
     * impide aprovechar `ventas_fecha_idx` en rangos amplios sin filtro de sucursal.
     */
    public function test_ventas_lista_no_envuelve_fecha_en_date(): void
    {
        $this->actingAsUser('ADMIN');

        $log = $this->capturarQueries(function () {
            $this->getJson('/api/ventas?fecha_desde=2026-06-01&fecha_hasta=2026-06-16&take=30')
                ->assertStatus(200);
        });

        $sql = $this->sqlPlano($log);
        $this->assertStringContainsString('from `ventas`', $sql);
        $this->assertStringNotContainsString('date(`fecha`)', $sql,
            'ventas envuelve `fecha` en date(): impide usar ventas_fecha_idx. Usar where() plano.');
    }

    /**
     * EXPLAIN real contra tienda_test: con suficientes tranzas, la query de caja por
     * rango de fecha NO debe resolverse con un "Table scan". Demuestra que el índice
     * `tranzas_fecha_idx` es usable una vez que `fecha` no va envuelta en date().
     *
     * Se siembran filas para que el optimizador prefiera el índice de forma estable
     * (con tablas diminutas MySQL puede elegir scan aunque haya índice). El método
     * que importa es el SQL emitido (tests anteriores); este es evidencia de respaldo.
     */
    public function test_explain_tranzas_por_fecha_usa_indice_no_full_scan(): void
    {
        // Sembrar ~120 tranzas en distintas fechas (sintéticas, dentro de la transacción).
        $cuenta = Cuenta::factory()->create();
        $filas = [];
        for ($i = 0; $i < 120; $i++) {
            $filas[] = [
                'sucursal_id'   => 1,
                'cuenta_id'     => $cuenta->id,
                'fecha'         => now()->subDays($i % 90)->toDateString(),
                'tipo'          => $i % 2 === 0 ? 'INGRESO' : 'EGRESO',
                'clase'         => 'ENT',
                'registro'      => 0,
                'descripcion'   => 'perf-seed',
                'monto_ingreso' => $i % 2 === 0 ? 10 : 0,
                'monto_egreso'  => $i % 2 === 0 ? 0 : 5,
                'user_id'       => 1,
                'estado'        => 'ON',
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('tranzas')->insert($filas);

        // EXPLAIN de la forma CORREGIDA (where plano). Debe usar índice, no full scan.
        $hoy   = now()->toDateString();
        $desde = now()->subDays(7)->toDateString();
        $rows  = DB::select(
            "EXPLAIN SELECT SUM(monto_ingreso) FROM tranzas
             WHERE sucursal_id = 1 AND estado = 'ON' AND fecha >= ? AND fecha <= ?",
            [$desde, $hoy]
        );

        // MySQL 8 devuelve el plan TREE en la columna EXPLAIN.
        $plan = strtolower(implode(' ', array_map(fn($r) => ((array) $r)['EXPLAIN'] ?? json_encode((array) $r), $rows)));

        $this->assertStringContainsString('tranzas_fecha_idx', $plan,
            "EXPLAIN debería usar tranzas_fecha_idx para el rango de fecha. Plan: {$plan}");
        $this->assertStringNotContainsString('table scan on tranzas', $plan,
            "La query por rango de fecha NO debe degradar a full table scan. Plan: {$plan}");
    }

    /**
     * El índice compuesto `tranzas_sucursal_estado_fecha_idx` (migración nueva) debe existir
     * y ser ELEGIBLE para la query de caja por sucursal+estado+fecha. Sin él, un rango de
     * fecha amplio escanea las tranzas de TODA la red (todas las sucursales) y filtra
     * `sucursal_id` fila a fila. La migración acota el escaneo a la partición de la sucursal.
     *
     * Se siembran tranzas multi-sucursal en un rango amplio para que el filtro de sucursal
     * sea selectivo y el optimizador prefiera el compuesto.
     */
    public function test_explain_tranzas_usa_indice_compuesto_sucursal_estado_fecha(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasIndex('tranzas', 'tranzas_sucursal_estado_fecha_idx'),
            'Falta el índice compuesto tranzas_sucursal_estado_fecha_idx (migración 2026_06_16_000000).'
        );

        $cuenta = Cuenta::factory()->create();
        $filas = [];
        // 300 tranzas repartidas en 5 sucursales y 200 días → filtro de sucursal selectivo.
        for ($i = 0; $i < 300; $i++) {
            $filas[] = [
                'sucursal_id'   => ($i % 5) + 1,
                'cuenta_id'     => $cuenta->id,
                'fecha'         => now()->subDays($i % 200)->toDateString(),
                'tipo'          => 'INGRESO',
                'clase'         => 'ENT',
                'registro'      => 0,
                'descripcion'   => 'perf-seed-multi',
                'monto_ingreso' => 10,
                'monto_egreso'  => 0,
                'user_id'       => 1,
                'estado'        => 'ON',
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }
        DB::table('tranzas')->insert($filas);

        $desde = now()->subDays(190)->toDateString();
        $hasta = now()->toDateString();
        $rows = DB::select(
            "EXPLAIN SELECT SUM(monto_ingreso) FROM tranzas
             WHERE sucursal_id = 1 AND estado = 'ON' AND fecha >= ? AND fecha <= ?",
            [$desde, $hasta]
        );
        $plan = strtolower(implode(' ', array_map(fn($r) => ((array) $r)['EXPLAIN'] ?? json_encode((array) $r), $rows)));

        // Nunca un full table scan; y debe apoyarse en un índice (compuesto preferido).
        $this->assertStringNotContainsString('table scan on tranzas', $plan,
            "La query por sucursal+estado+fecha NO debe ser full scan. Plan: {$plan}");
        $this->assertTrue(
            str_contains($plan, 'tranzas_sucursal_estado_fecha_idx') || str_contains($plan, 'tranzas_fecha_idx'),
            "La query debe usar un índice de tranzas (compuesto o de fecha). Plan: {$plan}"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. N+1: las listas con relaciones deben tener query-count ACOTADO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/ventas mapea `cuenta` y `sucursal` por cada fila. Con eager loading el
     * nº de queries es CONSTANTE respecto al nº de ventas; con N+1 crecería con N.
     * Se siembran 2 lotes (5 y 15 ventas) y se exige que el query-count NO crezca con N.
     */
    public function test_ventas_lista_query_count_independiente_de_N(): void
    {
        $this->actingAsUser('ADMIN');

        $sembrarVentas = function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                $cuenta = Cuenta::factory()->cliente()->create();
                Venta::factory()->create([
                    'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
                    'fecha' => now()->toDateString(), 'estado' => 'VALIDO',
                    'total' => 100, 'pagado' => 'PAGADO',
                ]);
            }
        };

        // Warm-up: la 1ª request resuelve y cachea roles/permisos de Spatie (queries que
        // NO se repiten en la 2ª) y el binding del usuario. Sin esto, la comparación mide
        // el ruido del cache de permisos, no el listado. Tras el warm-up ambas mediciones
        // parten del MISMO estado de cache → la única variable es el nº de filas.
        $sembrarVentas(5);
        $this->getJson('/api/ventas?take=50')->assertStatus(200);

        $logChico = $this->capturarQueries(function () {
            $this->getJson('/api/ventas?take=50')->assertStatus(200);
        });

        $sembrarVentas(10); // ahora 15 en total
        $logGrande = $this->capturarQueries(function () {
            $this->getJson('/api/ventas?take=50')->assertStatus(200);
        });

        $this->assertEquals(
            count($logChico), count($logGrande),
            'El query-count de GET /api/ventas crece con el nº de filas → N+1. Debe ser constante (eager loading de cuenta/sucursal). '
            . 'Chico=' . count($logChico) . ' Grande=' . count($logGrande)
        );
    }

    /**
     * GET /api/productos mapea `marca` e `industria` por fila. Eager loading → query-count constante.
     */
    public function test_productos_lista_query_count_independiente_de_N(): void
    {
        $this->actingAsUser('ADMIN');

        $sembrarProductos = function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                Producto::factory()->create(['estado' => 'ON']);
            }
        };

        // Warm-up del cache de permisos (ver test de ventas).
        $sembrarProductos(5);
        $this->getJson('/api/productos?take=50')->assertStatus(200);

        $logChico = $this->capturarQueries(function () {
            $this->getJson('/api/productos?take=50')->assertStatus(200);
        });

        $sembrarProductos(10);
        $logGrande = $this->capturarQueries(function () {
            $this->getJson('/api/productos?take=50')->assertStatus(200);
        });

        $this->assertEquals(
            count($logChico), count($logGrande),
            'El query-count de GET /api/productos crece con el nº de filas → N+1. Debe ser constante (eager loading de marca/industria). '
            . 'Chico=' . count($logChico) . ' Grande=' . count($logGrande)
        );
    }
}
