<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Cuenta;
use App\Models\Devcompra;
use App\Models\Producto;
use App\Models\Tranza;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo COMPRAS — foco en la CONSERVACIÓN DE DINERO
 * (saldo/acuenta) en compras CREDITO, el gemelo no arreglado del bug que la
 * auditoría de Ventas (Loop 1) ya cerró con `recalcularSaldoCredito()`.
 *
 * Compras todavía muta `acuenta`/`saldo` con DELTAS sin tope en `devItem`,
 * `pagarCompra` y `deleteItemDev`. Las invariantes contables del libro de
 * proveedor que el sistema DEBE sostener pase lo que pase:
 *
 *   INV-M1  saldo >= 0                 (nunca un saldo negativo almacenado)
 *   INV-M2  acuenta <= total           (no se "paga"/acredita más de lo comprado)
 *   INV-M3  total - acuenta == saldo   (identidad del libro mayor)
 *
 * Semántica de la devolución a proveedor en CREDITO (confirmada con el espejo de
 * Ventas y la convención del propio controller): devolver mercadería reduce la
 * deuda con el proveedor; si ya se le pagó de MÁS respecto a lo que queda, el
 * excedente vuelve como ingreso de efectivo (tranza D-COM con monto_ingreso).
 *
 * Cantidades y costos ENTEROS a propósito: aísla el bug de signo/tope del ruido
 * de redondeo flotante, para que un rojo sea inequívoco.
 */
class ComprasAuditTest extends TestCase
{
    /** Semilla fija → la secuencia de "azar" es la misma en cada corrida. */
    private const SEED = 20260616;

    /** Cuántos escenarios aleatorios genera la propiedad. */
    private const SCENARIOS = 60;

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers de construcción (flujo REAL por API, igual que ComprasTest)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea una compra CREDITO PROFORMA, le agrega ítems por API y la valida.
     * Devuelve [compra, items] donde items = [['prod'=>Producto,'cant'=>int,'costo'=>int], ...].
     *
     * @param  array<int,array{costo:int,cant:int}>  $specs
     * @return array{0:Compra,1:array<int,array{prod:Producto,cant:int,costo:int}>}
     */
    private function compraCreditoValidada(array $specs): array
    {
        $cuenta = Cuenta::factory()->proveedor()->create();
        $compra = Compra::factory()->create([
            'sucursal_id' => 1,
            'cuenta_id'   => $cuenta->id,
            'tipo'        => 'CREDITO',
            'fecha'       => now()->toDateString(),
            'estado'      => 'PROFORMA',
            'pagado'      => 'POR PAGAR',
        ]);

        $items = [];
        foreach ($specs as $spec) {
            $prod = Producto::factory()->create(['p_comp' => $spec['costo'], 'stock1' => 1000]);
            $this->postJson('/api/compras/agregar-item', [
                'compra_id'   => $compra->id,
                'producto_id' => $prod->id,
                'cantidad'    => $spec['cant'],
                'costo'       => $spec['costo'],
            ])->assertStatus(200);
            $items[] = ['prod' => $prod, 'cant' => $spec['cant'], 'costo' => $spec['costo']];
        }

        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);

        return [$compra->fresh(), $items];
    }

    /**
     * Verifica las invariantes contables del libro de proveedor sobre una compra.
     */
    private function assertInvariants(Compra $c, string $ctx): void
    {
        $total   = (float) $c->total;
        $acuenta = (float) $c->acuenta;
        $saldo   = (float) $c->saldo;

        $this->assertGreaterThanOrEqual(-0.005, $saldo, "INV-M1 (saldo>=0) roto en {$ctx}: saldo={$saldo}");
        $this->assertLessThanOrEqual($total + 0.005, $acuenta, "INV-M2 (acuenta<=total) roto en {$ctx}: acuenta={$acuenta} total={$total}");
        $this->assertEqualsWithDelta($total - $acuenta, $saldo, 0.01, "INV-M3 (total-acuenta=saldo) roto en {$ctx}: total={$total} acuenta={$acuenta} saldo={$saldo}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. REPRO DETERMINISTA MÍNIMO (caso difícil primero) — el bug del brief
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compra CREDITO total 100, se paga 90 (saldo 10), se devuelve un ítem de 30.
     * Con los deltas sin tope: acuenta = 90+30 = 120 > total 100 (INV-M2 roto),
     * saldo clampa a 0 → acuenta+saldo = 120 != 100 (INV-M3 roto).
     */
    public function test_devolver_mas_que_el_saldo_no_infla_acuenta_sobre_total(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        // total 100 = 10 uds × costo 10
        [$compra, $items] = $this->compraCreditoValidada([['costo' => 10, 'cant' => 10]]);

        $this->postJson('/api/compras/pagar', ['compra_id' => $compra->id, 'monto' => 90])->assertStatus(200);
        $c = $compra->fresh();
        $this->assertEquals(90.0, (float) $c->acuenta, 'precondición: pagado 90');
        $this->assertEquals(10.0, (float) $c->saldo, 'precondición: saldo 10');

        // Devolver 3 uds (valor 30) — más que el saldo pendiente (10).
        $this->postJson('/api/compras/dev-item', [
            'compra_id'   => $compra->id,
            'producto_id' => $items[0]['prod']->id,
            'cantidad'    => 3,
        ])->assertStatus(200);

        $c = $compra->fresh();
        $this->assertInvariants($c, 'compra pagada 90, devuelto ítem de 30');
        $this->assertLessThanOrEqual(100.0, (float) $c->acuenta, 'acuenta no debe superar el total 100');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. STATEFUL PBT — cadena sembrada de pagar / devolver / revertir-dev
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generador pseudo-aleatorio SEMBRADO que encadena, sobre una compra CREDITO
     * validada, operaciones reales por API (pagar parcial, devolver ítem, revertir
     * devolución) verificando las 3 invariantes contables tras CADA paso. La trampa
     * deliberada: pagar (casi) todo y luego devolver de más.
     */
    public function test_pbt_invariantes_de_dinero_compras_credito(): void
    {
        $this->actingAsUser('ADMIN');
        mt_srand(self::SEED);

        for ($n = 0; $n < self::SCENARIOS; $n++) {
            $this->runScenario($n);
        }
    }

    private function runScenario(int $n): void
    {
        // 1..3 ítems, costo y cantidad ENTEROS.
        $specs = [];
        $nItems = mt_rand(1, 3);
        for ($i = 0; $i < $nItems; $i++) {
            $specs[] = ['costo' => mt_rand(5, 200), 'cant' => mt_rand(1, 8)];
        }
        [$compra, $items] = $this->compraCreditoValidada($specs);
        $this->assertInvariants($compra->fresh(), "escenario {$n} tras validar");

        // Secuencia de pagos parciales (montos enteros, <= saldo).
        $nPagos = mt_rand(0, 3);
        for ($p = 0; $p < $nPagos; $p++) {
            $c = $compra->fresh();
            $saldo = (int) round((float) $c->saldo);
            if ($saldo < 1) break;
            // Sesgo al caso difícil: a veces pagar el saldo COMPLETO (deja PAGADO).
            $monto = (mt_rand(0, 2) === 0) ? $saldo : mt_rand(1, $saldo);
            $this->postJson('/api/compras/pagar', ['compra_id' => $compra->id, 'monto' => $monto])
                ->assertStatus(200);
            $this->assertInvariants($compra->fresh(), "escenario {$n} tras pagar {$monto}");
        }

        // Secuencia de devoluciones (la trampa: devolver DESPUÉS de pagar todo).
        $nDevs = mt_rand(0, 3);
        for ($d = 0; $d < $nDevs; $d++) {
            $it = $items[array_rand($items)];
            $cant = mt_rand(1, $it['cant']);
            $resp = $this->postJson('/api/compras/dev-item', [
                'compra_id'   => $compra->id,
                'producto_id' => $it['prod']->id,
                'cantidad'    => $cant,
            ]);
            // Puede dar 422 por límite acumulado: solo verificamos si pasó.
            if ($resp->status() === 200) {
                $this->assertInvariants($compra->fresh(), "escenario {$n} tras devolver {$cant}");
            }
        }

        // Revertir alguna devolución viva (simetría del libro).
        $devs = Devcompra::where('compra_id', $compra->id)->where('estado', 'ON')->get();
        foreach ($devs as $dev) {
            if (mt_rand(0, 1) === 0) {
                $this->postJson('/api/compras/delete-item-dev', ['registro' => $dev->id])
                    ->assertStatus(200);
                $this->assertInvariants($compra->fresh(), "escenario {$n} tras revertir dev {$dev->id}");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. SIMETRÍA — devolver y revertir restaura el estado exacto (caso pagado)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Pagar TODO (PAGADO, saldo 0), devolver un ítem, luego revertir la devolución
     * debe restaurar acuenta/saldo/pagado EXACTOS. Con los deltas viejos, devItem
     * deja acuenta inflada y revertir no la restaura simétricamente.
     */
    public function test_revertir_devolucion_restaura_estado_exacto(): void
    {
        $this->actingAsUser('ADMIN');
        // total 100 = 10 uds × 10
        [$compra, $items] = $this->compraCreditoValidada([['costo' => 10, 'cant' => 10]]);

        $this->postJson('/api/compras/pagar', ['compra_id' => $compra->id, 'monto' => 100])->assertStatus(200);
        $c = $compra->fresh();
        $this->assertEquals('PAGADO', $c->pagado);
        $this->assertEquals(0.0, (float) $c->saldo);

        // Devolver 2 uds (valor 20).
        $this->postJson('/api/compras/dev-item', [
            'compra_id'   => $compra->id,
            'producto_id' => $items[0]['prod']->id,
            'cantidad'    => 2,
        ])->assertStatus(200);
        $this->assertInvariants($compra->fresh(), 'tras devolver sobre compra pagada');

        $dev = Devcompra::where('compra_id', $compra->id)->where('estado', 'ON')->latest('id')->first();
        $this->postJson('/api/compras/delete-item-dev', ['registro' => $dev->id])->assertStatus(200);

        $c = $compra->fresh();
        $this->assertEquals(100.0, (float) $c->acuenta, 'revertir restaura acuenta exacta (no inflada)');
        $this->assertEquals(0.0, (float) $c->saldo, 'revertir restaura saldo exacto');
        $this->assertEquals('PAGADO', $c->pagado, 'revertir restaura pagado');
        $this->assertInvariants($c, 'tras revertir devolución');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. CONSERVACIÓN DE CAJA — el efectivo neto que sale = lo realmente debido
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Caso sobrepago: pagar 90 de 100, devolver un ítem de 30. La deuda pendiente
     * era 10; las 3 uds devueltas (30) reducen la deuda a 0 y dejan 20 pagados de
     * más → el proveedor debe devolver 20 en efectivo (ingreso D-COM). El efectivo
     * neto que sale de la tienda (pagos PAG − ingresos D-COM) debe quedar = al
     * valor de lo que la tienda CONSERVA (7 uds × 10 = 70).
     */
    public function test_conservacion_de_caja_sobrepago_devolucion(): void
    {
        $this->actingAsUser('ADMIN');
        [$compra, $items] = $this->compraCreditoValidada([['costo' => 10, 'cant' => 10]]);

        $this->postJson('/api/compras/pagar', ['compra_id' => $compra->id, 'monto' => 90])->assertStatus(200);
        $this->postJson('/api/compras/dev-item', [
            'compra_id'   => $compra->id,
            'producto_id' => $items[0]['prod']->id,
            'cantidad'    => 3,
        ])->assertStatus(200);

        $c = $compra->fresh();
        $this->assertInvariants($c, 'sobrepago + devolución');
        $this->assertEquals('PAGADO', $c->pagado, 'la deuda queda saldada');

        $pagado    = (float) Tranza::where('registro', $compra->id)->where('clase', 'PAG')->where('estado', 'ON')->sum('monto_egreso');
        $reembolso = (float) Tranza::where('registro', $compra->id)->where('clase', 'D-COM')->where('estado', 'ON')->sum('monto_ingreso');

        $this->assertEquals(70.0, $pagado - $reembolso, 'efectivo neto pagado = valor de lo conservado (7 uds × 10)');
        $this->assertEquals(20.0, $reembolso, 'el proveedor reembolsa el excedente pagado de más (20)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. VALIDAR → historial de precios (Precio) + p_comp del producto (contrato)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Contrato del cambio de precio al validar (FIEL AL LEGACY): cuando el costo de
     * compra difiere del p_comp del producto, se REGISTRA una fila `Precio` de historial,
     * pero el `producto.p_comp` NO se muta (el legacy comenta deliberadamente esa línea
     * — el master price es referencia curada manualmente). Este test FIJA ese contrato:
     * si alguien empieza a mutar p_comp al validar, se rompe.
     */
    public function test_validar_registra_historial_de_precio_sin_mutar_p_comp(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->proveedor()->create();
        $prod   = Producto::factory()->create(['p_comp' => 40, 'stock1' => 5]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        // Costo de compra distinto al p_comp actual (40 → 55).
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 10, 'costo' => 55])->assertStatus(200);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);

        // Stock incrementado.
        $this->assertEquals(15, (float) Producto::find($prod->id)->stock1, 'stock += cantidad comprada');
        // p_comp NO mutado (fiel al legacy).
        $this->assertEquals(40.0, (float) Producto::find($prod->id)->p_comp, 'p_comp del producto NO se muta al validar');
        // Fila de historial registrada con orig 40 y nuevo 55.
        $this->assertDatabaseHas('precios', [
            'tipo' => 'COMPRA', 'registro' => $compra->id, 'producto_id' => $prod->id,
            'p_comp_orig' => 40, 'p_comp' => 55,
        ]);
    }

    /**
     * Si el costo coincide con p_comp, NO se registra fila de historial (evita ruido).
     */
    public function test_validar_no_registra_precio_si_costo_igual_a_p_comp(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->proveedor()->create();
        $prod   = Producto::factory()->create(['p_comp' => 40, 'stock1' => 0]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 3, 'costo' => 40])->assertStatus(200);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);

        $this->assertEquals(0, \App\Models\Precio::where('registro', $compra->id)->where('producto_id', $prod->id)->count(), 'sin cambio de costo no se registra historial');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. CONSERVACIÓN DE STOCK — ciclo cerrado validar→devItem→deleteItemDev→anular
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El ciclo completo de una compra debe dejar el stock EXACTAMENTE como empezó:
     *   inicio S  →  validar (+10 = S+10)  →  devItem 3 (-3 = S+7)
     *             →  revertir dev (+3 = S+10)  →  anular (-10 neto = S)
     * Sin perder ni fabricar piezas (espejo de StockIntegrityTest para ventas).
     */
    public function test_ciclo_cerrado_de_stock_conserva_inventario(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->proveedor()->create();
        $prod   = Producto::factory()->create(['p_comp' => 10, 'stock1' => 7]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 10, 'costo' => 10])->assertStatus(200);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);
        $this->assertEquals(17, (float) Producto::find($prod->id)->stock1, 'validar +10');

        $this->postJson('/api/compras/dev-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 3])->assertStatus(200);
        $this->assertEquals(14, (float) Producto::find($prod->id)->stock1, 'devItem -3');

        $dev = Devcompra::where('compra_id', $compra->id)->where('estado', 'ON')->latest('id')->first();
        $this->postJson('/api/compras/delete-item-dev', ['registro' => $dev->id])->assertStatus(200);
        $this->assertEquals(17, (float) Producto::find($prod->id)->stock1, 'revertir dev +3');

        $this->deleteJson("/api/compras/{$compra->id}")->assertStatus(200);
        $this->assertEquals(7, (float) Producto::find($prod->id)->stock1, 'anular restaura el stock inicial exacto');
    }

    /**
     * Anular una compra VALIDO con una devolución previa NO debe doblar el reverso de
     * stock: validar +10 (→S+10), devolver 4 (→S+6), anular debe restar solo el NETO no
     * devuelto (10-4=6) → vuelve a S. Si restara la cantidad bruta (10), el stock caería
     * por debajo del inicio (pieza fantasma perdida).
     */
    public function test_anular_con_devolucion_previa_no_dobla_el_reverso(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->proveedor()->create();
        $prod   = Producto::factory()->create(['p_comp' => 10, 'stock1' => 5]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 10, 'costo' => 10])->assertStatus(200);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200); // 15
        $this->postJson('/api/compras/dev-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 4])->assertStatus(200); // 11

        $this->deleteJson("/api/compras/{$compra->id}")->assertStatus(200);
        $this->assertEquals(5, (float) Producto::find($prod->id)->stock1, 'anular resta solo el neto no devuelto (6), vuelve al inicio');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. FUZZ de pagarCompra (D2/D3/D10) — estados ilegales → 422 limpio
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * pagarCompra debe rechazar con 422 (nunca 500 ni mutación silenciosa) toda
     * combinación ilegal: compra PROFORMA, ANULADA, CONTADO, monto 0/negativo/no
     * numérico, y monto > saldo. Tras cualquier rechazo, NO debe crearse tranza PAG
     * ni inflarse acuenta.
     */
    public function test_pagar_estados_y_montos_ilegales_se_rechazan(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->proveedor()->create();

        // (a) PROFORMA crédito → no se puede pagar (solo validadas).
        $proforma = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO', 'estado' => 'PROFORMA', 'total' => 100, 'saldo' => 100]);
        $this->postJson('/api/compras/pagar', ['compra_id' => $proforma->id, 'monto' => 10])->assertStatus(422);

        // (b) CONTADO validada → no es a crédito.
        $contado = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'VALIDO', 'pagado' => 'PAGADO', 'total' => 100, 'saldo' => 0]);
        $this->postJson('/api/compras/pagar', ['compra_id' => $contado->id, 'monto' => 10])->assertStatus(422);

        // (c) CREDITO validada con saldo 100: montos ilegales.
        $credito = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO', 'estado' => 'VALIDO', 'pagado' => 'POR PAGAR', 'total' => 100, 'acuenta' => 0, 'saldo' => 100]);
        $this->postJson('/api/compras/pagar', ['compra_id' => $credito->id, 'monto' => 0])->assertStatus(422);     // min:0.01
        $this->postJson('/api/compras/pagar', ['compra_id' => $credito->id, 'monto' => -50])->assertStatus(422);   // negativo
        $this->postJson('/api/compras/pagar', ['compra_id' => $credito->id, 'monto' => 'abc'])->assertStatus(422); // no numérico
        $this->postJson('/api/compras/pagar', ['compra_id' => $credito->id, 'monto' => 150])->assertStatus(422);   // > saldo

        // Ningún rechazo dejó tranza PAG ni movió acuenta.
        $this->assertEquals(0, Tranza::where('clase', 'PAG')->where('estado', 'ON')->count(), 'ningún pago ilegal creó tranza');
        $this->assertEquals(0.0, (float) $credito->fresh()->acuenta, 'acuenta intacta tras rechazos');
        $this->assertEquals(100.0, (float) $credito->fresh()->saldo, 'saldo intacto tras rechazos');
    }

    /**
     * Pagar una compra ya PAGADA (saldo 0) debe rechazarse con 422 (monto > saldo=0),
     * sin sobrepago: acuenta nunca supera el total.
     */
    public function test_pagar_compra_ya_pagada_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');
        [$compra, ] = $this->compraCreditoValidada([['costo' => 10, 'cant' => 10]]); // total 100
        $this->postJson('/api/compras/pagar', ['compra_id' => $compra->id, 'monto' => 100])->assertStatus(200);
        $this->assertEquals('PAGADO', $compra->fresh()->pagado);

        $this->postJson('/api/compras/pagar', ['compra_id' => $compra->id, 'monto' => 1])->assertStatus(422);

        $c = $compra->fresh();
        $this->assertEquals(100.0, (float) $c->acuenta, 'acuenta no supera el total tras intentar sobrepagar');
        $this->assertInvariants($c, 'tras intento de pago sobre compra pagada');
    }
}
