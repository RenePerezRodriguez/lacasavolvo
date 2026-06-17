<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Devventa;
use App\Models\Producto;
use App\Models\Tranza;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Property-based testing (técnica A) sobre la CONSERVACIÓN DE DINERO en ventas CREDITO.
 *
 * Un generador pseudo-aleatorio SEMBRADO (determinista, reproducible) encadena
 * operaciones reales por API — agregar ítems → validar → N cobros parciales →
 * N devoluciones — y verifica, tras CADA paso, las invariantes contables que el
 * sistema debe sostener pase lo que pase:
 *
 *   INV-M1  saldo >= 0                 (nunca un saldo negativo almacenado)
 *   INV-M2  acuenta <= total           (no se acredita más de lo vendido)
 *   INV-M3  total - acuenta == saldo   (identidad del libro mayor)
 *   INV-M4  total == Σ(costo·cantidad) de detalles VALIDO
 *
 * Las cantidades y precios se generan ENTEROS a propósito: aísla el bug de signo
 * de `saldo` del ruido de redondeo flotante, para que un rojo sea inequívoco.
 */
class MoneyPropertyTest extends TestCase
{
    /** Semilla fija → la secuencia de "azar" es la misma en cada corrida. */
    private const SEED = 20260615;

    /** Cuántos escenarios aleatorios genera la propiedad. */
    private const SCENARIOS = 60;

    public function test_invariantes_de_dinero_resisten_secuencias_aleatorias(): void
    {
        $this->actingAsUser('ADMIN');
        mt_srand(self::SEED);

        for ($n = 0; $n < self::SCENARIOS; $n++) {
            $this->runScenario($n);
        }
    }

    private function runScenario(int $n): void
    {
        $cuenta = Cuenta::factory()->cliente()->create();
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1,
            'cuenta_id'   => $cuenta->id,
            'tipo'        => 'CREDITO',
            'fecha'       => now()->toDateString(),
            'estado'      => 'PROFORMA',
        ]);

        // 1..3 productos con precio ENTERO y stock holgado (la validación nunca bloquea).
        $items = [];
        $nItems = mt_rand(1, 3);
        for ($i = 0; $i < $nItems; $i++) {
            $precio = mt_rand(5, 200);            // entero
            $prod   = Producto::factory()->create(['p_norm' => $precio, 'stock1' => 1000]);
            $cant   = mt_rand(1, 8);              // entero
            $this->postJson('/api/ventas/agregar-item', [
                'venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => $cant,
            ])->assertStatus(200);
            $items[] = ['prod' => $prod, 'cant' => $cant, 'precio' => $precio];
        }

        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->assertInvariants($venta->fresh(), $items, "escenario {$n} tras validar");

        // Secuencia de cobros parciales (montos enteros, <= saldo).
        $nCobros = mt_rand(0, 3);
        for ($c = 0; $c < $nCobros; $c++) {
            $v = $venta->fresh();
            $saldo = (int) round((float) $v->saldo);
            if ($saldo < 1) break;
            // Sesgo al caso difícil: a veces cobrar el saldo COMPLETO (deja PAGADO).
            $monto = (mt_rand(0, 2) === 0) ? $saldo : mt_rand(1, $saldo);
            $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => $monto])
                ->assertStatus(200);
            $this->assertInvariants($venta->fresh(), $items, "escenario {$n} tras cobrar {$monto}");
        }

        // Secuencia de devoluciones (la trampa: devolver DESPUÉS de pagar todo).
        $nDevs = mt_rand(0, 2);
        for ($d = 0; $d < $nDevs; $d++) {
            $it = $items[array_rand($items)];
            // Devolver 1..cant del ítem (dentro del límite si no se devolvió antes).
            $cant = mt_rand(1, $it['cant']);
            $resp = $this->postJson('/api/ventas/dev-item', [
                'venta_id' => $venta->id, 'producto_id' => $it['prod']->id, 'cantidad' => $cant,
            ]);
            // Puede dar 422 por límite acumulado: solo verificamos invariantes si pasó.
            if ($resp->status() === 200) {
                $this->assertInvariants($venta->fresh(), $items, "escenario {$n} tras devolver {$cant}");
            }
        }
    }

    /**
     * @param  array<int,array{prod:Producto,cant:int,precio:int}>  $items
     */
    private function assertInvariants(Venta $v, array $items, string $ctx): void
    {
        $total   = (float) $v->total;
        $acuenta = (float) $v->acuenta;
        $saldo   = (float) $v->saldo;

        $this->assertGreaterThanOrEqual(-0.005, $saldo, "INV-M1 (saldo>=0) roto en {$ctx}: saldo={$saldo}");
        $this->assertLessThanOrEqual($total + 0.005, $acuenta, "INV-M2 (acuenta<=total) roto en {$ctx}: acuenta={$acuenta} total={$total}");
        $this->assertEqualsWithDelta($total - $acuenta, $saldo, 0.01, "INV-M3 (total-acuenta=saldo) roto en {$ctx}");

        // INV-M4: total == Σ(costo·cantidad) de detalles VALIDO (la devolución NO baja total).
        $sumDetalle = (float) $v->detalles()->where('estado', 'VALIDO')
            ->selectRaw('COALESCE(SUM(costo*cantidad),0) s')->value('s');
        $this->assertEqualsWithDelta($sumDetalle, $total, 0.01, "INV-M4 (total=Σsub) roto en {$ctx}");
    }

    /**
     * Regresión determinista del bug exacto: pagar una venta CREDITO por completo y luego
     * devolver un ítem dejaba saldo NEGATIVO (saldo -= total sin tope) y acuenta > total.
     */
    public function test_devolver_tras_pago_total_no_deja_saldo_negativo(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 25, 'stock1' => 100]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200); // total 100
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 100])->assertStatus(200); // PAGADO, saldo 0

        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(200);

        $v = $venta->fresh();
        $this->assertEquals(0.0, (float) $v->saldo, 'saldo nunca negativo tras devolver una venta pagada');
        $this->assertEquals(100.0, (float) $v->acuenta, 'acuenta no debe superar el total');
        $this->assertEquals('PAGADO', $v->pagado);
        // El reembolso en efectivo (egreso) SÍ debe haber salido: la venta estaba pagada.
        $this->assertEquals(25.0, (float) Tranza::where('registro', $venta->id)->where('clase', 'D-VEN')
            ->where('estado', 'ON')->sum('monto_egreso'), 'egreso de reembolso = valor del ítem');
    }

    /**
     * Decisión de negocio (sobrepago parcial): si el cliente pagó de MÁS respecto a lo que
     * conserva tras devolver, el excedente se le reembolsa en efectivo (egreso) y su deuda
     * queda en 0 — el dinero se conserva exactamente. Pagó 90 de 100, devuelve 1 ítem (25):
     * le vuelven 15 en efectivo, queda PAGADO, y el neto (90-15=75) = 3 ítems que conserva.
     */
    public function test_sobrepago_parcial_reembolsa_excedente_en_efectivo(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 25, 'stock1' => 100]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);   // total 100
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 90])->assertStatus(200); // saldo 10

        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(200);

        $v = $venta->fresh();
        $this->assertEquals(0.0, (float) $v->saldo, 'la deuda queda saldada');
        $this->assertEquals(100.0, (float) $v->acuenta);
        $this->assertEquals('PAGADO', $v->pagado);
        // Reembolso en efectivo del excedente = 15 (no 0 como el legacy, no 25).
        $this->assertEquals(15.0, (float) Tranza::where('registro', $venta->id)->where('clase', 'D-VEN')
            ->where('estado', 'ON')->sum('monto_egreso'), 'se reembolsa solo el excedente pagado de más');
        // Conservación: efectivo neto (cobró 90, devolvió 15) = valor de lo que conserva (3×25).
        $cobrado = (float) Tranza::where('registro', $venta->id)->where('clase', 'COB')->where('estado', 'ON')->sum('monto_ingreso');
        $reembolsado = (float) Tranza::where('registro', $venta->id)->where('clase', 'D-VEN')->where('estado', 'ON')->sum('monto_egreso');
        $this->assertEquals(75.0, $cobrado - $reembolsado, 'el efectivo neto = valor de los ítems conservados');
    }

    /**
     * Simetría: devolver y luego REVERTIR la devolución debe restaurar exactamente
     * acuenta/saldo previos — incluido el caso pagado (donde el delta antiguo corrompía
     * a 75/25 en vez de dejar 100/0).
     */
    public function test_revertir_devolucion_restaura_estado_exacto(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 25, 'stock1' => 100]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 100])->assertStatus(200); // PAGADO, saldo 0

        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(200);
        $dev = Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->latest('id')->first();

        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id])->assertStatus(200);

        $v = $venta->fresh();
        $this->assertEquals(100.0, (float) $v->acuenta, 'revertir restaura acuenta exacta (no 75)');
        $this->assertEquals(0.0, (float) $v->saldo, 'revertir restaura saldo exacto (no 25)');
        $this->assertEquals('PAGADO', $v->pagado);
        // La venta vendió 4 (100→96); devolver 1 (→97) y revertir (→96) deja el neto vendido.
        $this->assertEquals(96, Producto::find($prod->id)->stock1, 'stock vuelve al neto vendido (4 uds)');
    }
}
