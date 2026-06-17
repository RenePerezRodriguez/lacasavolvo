<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Cotizacion;
use App\Models\Producto;
use App\Models\Compra;
use App\Models\Compradetalle;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Residuales de la auditoría adversarial (frentes diferidos retomados a pedido del humano,
 * 2026-06-16). Cada bug: test rojo→verde.
 *
 * #cotizacions.observacion overflow — GEMELO del bug de Pedidos (loop 14): la columna
 * `observacion` es varchar(191) pero store/updateEncabezado NO la validaban → un texto de
 * 192+ chars reventaba el INSERT/UPDATE con PDOException 1406 → 500 (en vez de un 422 limpio).
 */
class ResidualesAuditTest extends TestCase
{
    /**
     * store: una observación más larga que la columna debe dar 422 (validación), no 500.
     */
    public function test_cotizacion_store_observacion_larga_da_422_no_500(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->create();

        $res = $this->postJson('/api/cotizaciones', [
            'fecha'       => now()->format('Y-m-d'),
            'cuenta_id'   => $cuenta->id,
            'observacion' => str_repeat('A', 300),
        ]);

        $res->assertStatus(422);
    }

    /**
     * updateEncabezado: mismo guard al editar el encabezado de una cotización VALIDO.
     */
    public function test_cotizacion_update_observacion_larga_da_422_no_500(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->create();
        $cotizacion = Cotizacion::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO',
        ]);

        $res = $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cotizacion->id,
            'cuenta_id'     => $cuenta->id,
            'fecha'         => now()->format('Y-m-d'),
            'descuento'     => 0,
            'observacion'   => str_repeat('B', 300),
        ]);

        $res->assertStatus(422);
    }

    /**
     * Contraprueba: una observación dentro del límite (<=191) se acepta normal.
     */
    public function test_cotizacion_observacion_corta_se_acepta(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->create();

        $this->postJson('/api/cotizaciones', [
            'fecha'       => now()->format('Y-m-d'),
            'cuenta_id'   => $cuenta->id,
            'observacion' => 'Entrega en obra, coordinar con el encargado.',
        ])->assertStatus(200);
    }

    // ──────────── #Performance · Estadísticas (rotación FIFO) — residual ────────────

    /**
     * Crea un producto con una compra y una venta validadas en la sucursal 1 (alimenta la
     * rotación FIFO). Devuelve el id del producto.
     *
     * @return int
     */
    private function productoConRotacion(): int
    {
        $prod   = Producto::factory()->create(['estado' => 'ON', 'stock1' => 100]);
        $cuenta = Cuenta::factory()->create();

        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO', 'fecha' => now()->subDays(10)->toDateString()]);
        Compradetalle::create([
            'compra_id' => $compra->id, 'producto_id' => $prod->id,
            'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion, 'marca' => '',
            'costo' => 100, 'p_comp' => 100, 'p_norm' => 0, 'p_fact' => 0,
            'cantidad' => 10, 'monto' => 1000, 'descuento' => 0, 'subtotal' => 1000, 'user_id' => 1, 'estado' => 'VALIDO',
        ]);

        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO', 'fecha' => now()->subDays(5)->toDateString()]);
        Ventadetalle::factory()->create([
            'venta_id' => $venta->id, 'producto_id' => $prod->id,
            'costo' => 150, 'p_comp' => 0, 'cantidad' => 4, 'monto' => 600, 'descuento' => 0, 'subtotal' => 600, 'estado' => 'VALIDO',
        ]);

        return $prod->id;
    }

    /** Cuenta las queries que dispara $fn. */
    private function contarQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        return $n;
    }

    /**
     * La rotación FIFO NO debe disparar más queries al crecer la cantidad de productos
     * (sin N+1): el tracking usa `whereIn($pids)` (4 queries fijas) y el conteo ahora es
     * una subquery COUNT en SQL (no materializa los grupos). El nº de queries debe ser
     * CONSTANTE entre un dataset chico y uno mayor, y acotado.
     */
    public function test_rotacion_query_count_no_crece_con_n_productos(): void
    {
        $this->actingAsUser('ADMIN');

        for ($i = 0; $i < 2; $i++) $this->productoConRotacion();
        $this->getJson('/api/estadisticas/rotacion')->assertStatus(200); // warm-up (cachea permisos/roles)
        $qChico = $this->contarQueries(fn() => $this->getJson('/api/estadisticas/rotacion')->assertStatus(200));

        for ($i = 0; $i < 6; $i++) $this->productoConRotacion();
        $qGrande = $this->contarQueries(fn() => $this->getJson('/api/estadisticas/rotacion')->assertStatus(200));

        $this->assertEquals($qChico, $qGrande, "rotación no debe hacer más queries al crecer N (no N+1): {$qChico} vs {$qGrande}");
        $this->assertLessThan(15, $qGrande, "rotación debe estar acotada en pocas queries (fue {$qGrande})");
    }
}
