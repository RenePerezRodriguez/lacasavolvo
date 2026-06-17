<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Devventa;
use App\Models\Producto;
use App\Models\Tranza;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo VENTAS — rincones DIFÍCILES y MENOS cubiertos.
 *
 * No re-corre el camino feliz (ya verde en VentasTest/MoneyPropertyTest/etc.). Ataca:
 *  1. Flujo de cobros (`/cobrar`) en estados ilegales y bordes (sobrepago, 0/neg, PROFORMA/ANULADA).
 *  2. Stateful PBT del libro de cobros: Σ(cobros COB ON) + Σ(devs ON) == acuenta SIEMPRE.
 *  3. Descuento del encabezado: ¿hay superficie para total < 0? (no la hay → se documenta).
 *  4. Simetría dev/revertir-dev en bordes; estados terminales.
 *  5. CONTADO vs CREDITO: conservación de dinero en cadenas (devolución parcial → egreso exacto).
 *  6. Stock multi-sucursal: validar/anular tocan solo la columna stock{sucursal_id}.
 *
 * DB sintética `tienda_test`, factories, DatabaseTransactions (rollback).
 */
class VentasAuditTest extends TestCase
{
    /**
     * Crea una venta CREDITO VALIDADA con un solo producto, lista para cobrar/devolver.
     *
     * @return array{venta:Venta,prod:Producto,total:float}
     */
    private function ventaCreditoValidada(int $cantidad = 4, float $precio = 25.0, int $stock = 100): array
    {
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => $precio, 'stock1' => $stock]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => $cantidad])->assertStatus(200);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        return ['venta' => $venta->fresh(), 'prod' => $prod, 'total' => $cantidad * $precio];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. COBROS — estados ilegales y bordes (el flujo MENOS barrido)
    // ─────────────────────────────────────────────────────────────────────────

    /** Cobrar una PROFORMA (no validada) debe rechazarse: no hay deuda real aún. */
    public function test_cobrar_proforma_es_rechazado(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA', 'total' => 100, 'saldo' => 100,
        ]);

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 10])->assertStatus(422);
        $this->assertEquals(0.0, (float) $venta->fresh()->acuenta, 'no debe acreditarse nada a una proforma');
        $this->assertEquals(0, Tranza::where('registro', $venta->id)->where('clase', 'COB')->count(), 'no debe crearse tranza COB');
    }

    /** Cobrar una venta ANULADA debe rechazarse (estado terminal, no es VALIDO). */
    public function test_cobrar_venta_anulada_es_rechazado(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta] = $this->ventaCreditoValidada();
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200); // ANULADO

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 10])->assertStatus(422);
        $this->assertEquals(0, Tranza::where('registro', $venta->id)->where('clase', 'COB')->where('estado', 'ON')->count());
    }

    /** Sobrepago: cobrar MÁS que el saldo debe rechazarse, sin crear COB ni dejar acuenta>total. */
    public function test_sobrepago_supera_saldo_es_rechazado(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta, 'total' => $total] = $this->ventaCreditoValidada(); // total 100

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => $total + 0.01])->assertStatus(422);
        $v = $venta->fresh();
        $this->assertEquals(0.0, (float) $v->acuenta, 'el sobrepago no debe acreditarse');
        $this->assertEquals($total, (float) $v->saldo, 'el saldo no cambia tras un cobro rechazado');
        $this->assertEquals(0, Tranza::where('registro', $venta->id)->where('clase', 'COB')->count());
    }

    /** Cobrar una venta ya PAGADA (saldo 0) debe rechazarse (monto > saldo=0). */
    public function test_cobrar_venta_ya_pagada_es_rechazado(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta, 'total' => $total] = $this->ventaCreditoValidada();
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => $total])->assertStatus(200); // PAGADO

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 1])->assertStatus(422);
        $this->assertEquals(1, Tranza::where('registro', $venta->id)->where('clase', 'COB')->where('estado', 'ON')->count(), 'solo el cobro válido');
    }

    /** Cobrar 0 o negativo debe dar 422 (validación min:0.01). */
    public function test_cobrar_cero_o_negativo_es_rechazado(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta] = $this->ventaCreditoValidada();

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 0])->assertStatus(422);
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => -50])->assertStatus(422);
        $this->assertEquals(0, Tranza::where('registro', $venta->id)->where('clase', 'COB')->count());
    }

    /** Múltiples cobros parciales: acuenta = Σcobros, saldo = total - Σcobros, sin desbordar. */
    public function test_cobros_parciales_multiples_suman_exacto(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta, 'total' => $total] = $this->ventaCreditoValidada(4, 25.0); // total 100

        foreach ([30, 30, 40] as $m) {
            $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => $m])->assertStatus(200);
        }
        $v = $venta->fresh();
        $this->assertEquals(100.0, (float) $v->acuenta);
        $this->assertEquals(0.0, (float) $v->saldo);
        $this->assertEquals('PAGADO', $v->pagado);
        // El 4º cobro (saldo=0) debe rechazarse.
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 1])->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. STATEFUL PBT — invariante del LIBRO de cobros (no solo saldo>=0)
    //    Σ(cobros COB ON) + Σ(devs ON) == acuenta  Y  saldo == max(0,total-acuenta)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pbt_libro_de_cobros_cuadra_con_acuenta(): void
    {
        $this->actingAsUser('ADMIN');
        mt_srand(424242);

        for ($n = 0; $n < 40; $n++) {
            $precio = mt_rand(5, 60);
            $cant   = mt_rand(2, 10);
            ['venta' => $venta, 'prod' => $prod, 'total' => $total] = $this->ventaCreditoValidada($cant, $precio, 1000);

            // Cadena aleatoria de operaciones que mueven el libro.
            $steps = mt_rand(2, 6);
            for ($s = 0; $s < $steps; $s++) {
                $v = $venta->fresh();
                $op = mt_rand(0, 2);
                if ($op === 0) {                 // cobrar dentro del saldo
                    $saldo = (int) floor((float) $v->saldo);
                    if ($saldo >= 1) {
                        $monto = mt_rand(1, $saldo);
                        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => $monto]);
                    }
                } elseif ($op === 1) {           // devolver dentro del límite
                    $devAcum = (int) Devventa::where('venta_id', $venta->id)->where('producto_id', $prod->id)->where('estado', 'ON')->sum('cantidad');
                    $rem = $cant - $devAcum;
                    if ($rem >= 1) {
                        $d = mt_rand(1, $rem);
                        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => $d]);
                    }
                } else {                          // revertir la última devolución ON
                    $dev = Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->latest('id')->first();
                    if ($dev) {
                        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id]);
                    }
                }
                $this->assertLibroCuadra($venta->fresh(), "scn {$n} step {$s}");
            }
        }
    }

    private function assertLibroCuadra(Venta $v, string $ctx): void
    {
        $cobros = (float) Tranza::where('registro', $v->id)->where('sucursal_id', $v->sucursal_id)
            ->where('clase', 'COB')->where('estado', 'ON')->sum('monto_ingreso');
        $devs = (float) Devventa::where('venta_id', $v->id)->where('estado', 'ON')->sum('total');
        $total = (float) $v->total;

        $creditoCap = min($total, $cobros + $devs);
        $this->assertEqualsWithDelta($creditoCap, (float) $v->acuenta, 0.01, "acuenta == min(total, Σcobros+Σdevs) roto en {$ctx}");
        $this->assertEqualsWithDelta(max(0.0, $total - ($cobros + $devs)), (float) $v->saldo, 0.01, "saldo roto en {$ctx}");
        $this->assertGreaterThanOrEqual(-0.005, (float) $v->saldo, "saldo<0 en {$ctx}");
        $this->assertLessThanOrEqual($total + 0.005, (float) $v->acuenta, "acuenta>total en {$ctx}");

        // INV de CONSERVACIÓN DE EFECTIVO (CREDITO): la tienda nunca reembolsa más efectivo
        // del que el cliente le pagó. Σ egresos por devolución (D-VEN ON) <= Σ cobros (COB ON).
        $reembolsos = (float) Tranza::where('registro', $v->id)->where('sucursal_id', $v->sucursal_id)
            ->where('clase', 'D-VEN')->where('estado', 'ON')->sum('monto_egreso');
        $this->assertLessThanOrEqual($cobros + 0.005, $reembolsos, "la tienda reembolsa más efectivo del cobrado en {$ctx}: reemb={$reembolsos} cobros={$cobros}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5b. STATEFUL PBT — CONTADO: conservación de caja en cadenas de devolución
    //     Caja neta de la venta (VEN - D-VEN) == valor de lo que el cliente conserva.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pbt_contado_caja_neta_igual_a_lo_conservado(): void
    {
        $this->actingAsUser('ADMIN');
        mt_srand(990011);

        for ($n = 0; $n < 30; $n++) {
            $precio = mt_rand(5, 60);
            $cant   = mt_rand(2, 10);
            $cuenta = Cuenta::factory()->cliente()->create();
            $prod   = Producto::factory()->create(['p_norm' => $precio, 'stock1' => 1000]);
            $venta  = Venta::factory()->create([
                'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
                'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
            ]);
            $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => $cant])->assertStatus(200);
            $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

            $steps = mt_rand(1, 5);
            for ($s = 0; $s < $steps; $s++) {
                $op = mt_rand(0, 1);
                if ($op === 0) {
                    $devAcum = (int) Devventa::where('venta_id', $venta->id)->where('producto_id', $prod->id)->where('estado', 'ON')->sum('cantidad');
                    $rem = $cant - $devAcum;
                    if ($rem >= 1) {
                        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => mt_rand(1, $rem)]);
                    }
                } else {
                    $dev = Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->latest('id')->first();
                    if ($dev) {
                        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id]);
                    }
                }

                // Caja neta = VEN - D-VEN ; conservado = (vendido - devuelto) * precio.
                $ingreso = (float) Tranza::where('registro', $venta->id)->where('clase', 'VEN')->where('estado', 'ON')->sum('monto_ingreso');
                $egreso  = (float) Tranza::where('registro', $venta->id)->where('clase', 'D-VEN')->where('estado', 'ON')->sum('monto_egreso');
                $devUds  = (int) Devventa::where('venta_id', $venta->id)->where('producto_id', $prod->id)->where('estado', 'ON')->sum('cantidad');
                $conservado = ($cant - $devUds) * $precio;
                $this->assertEqualsWithDelta($conservado, $ingreso - $egreso, 0.01,
                    "CONTADO caja neta != conservado en scn {$n} step {$s}: caja=" . ($ingreso - $egreso) . " conservado={$conservado}");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. CONTADO — conservación de dinero en devolución parcial
    // ─────────────────────────────────────────────────────────────────────────

    /** CONTADO: devolver parcial → egreso de caja = valor devuelto exacto (ni más ni menos). */
    public function test_contado_devolucion_parcial_egreso_exacto(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 30, 'stock1' => 100]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 5])->assertStatus(200); // total 150
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        // Ingreso de caja al validar = 150 (CONTADO crea tranza VEN).
        $this->assertEquals(150.0, (float) Tranza::where('registro', $venta->id)->where('clase', 'VEN')->where('estado', 'ON')->sum('monto_ingreso'));

        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2])->assertStatus(200);

        // Egreso = 2×30 = 60 exacto.
        $this->assertEquals(60.0, (float) Tranza::where('registro', $venta->id)->where('clase', 'D-VEN')->where('estado', 'ON')->sum('monto_egreso'), 'egreso = valor devuelto');
        // Caja neta de la venta = 150 - 60 = 90 = valor de 3 piezas conservadas.
        $ingreso = (float) Tranza::where('registro', $venta->id)->whereIn('clase', ['VEN'])->where('estado', 'ON')->sum('monto_ingreso');
        $egreso  = (float) Tranza::where('registro', $venta->id)->where('clase', 'D-VEN')->where('estado', 'ON')->sum('monto_egreso');
        $this->assertEquals(90.0, $ingreso - $egreso, 'caja neta = valor conservado (3×30)');
        // Stock: 100 - 5 + 2 = 97.
        $this->assertEquals(97, Producto::find($prod->id)->stock1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. SIMETRÍA dev / revertir-dev en bordes
    // ─────────────────────────────────────────────────────────────────────────

    /** Devolver el TOTAL vendido y revertir → stock y saldo vuelven EXACTO al estado validado. */
    public function test_devolver_todo_y_revertir_restaura_exacto(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta, 'prod' => $prod, 'total' => $total] = $this->ventaCreditoValidada(4, 25.0, 100); // stock 100→96
        $this->assertEquals(96, Producto::find($prod->id)->stock1);

        // Devolver las 4 (total).
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 4])->assertStatus(200);
        $this->assertEquals(100, Producto::find($prod->id)->stock1, 'devolver todo restaura stock');
        $v = $venta->fresh();
        $this->assertEquals(0.0, (float) $v->saldo, 'devolver todo (sin cobros) → saldo 0 (deuda cancelada)');
        $this->assertEquals($total, (float) $v->acuenta, 'devolución total acredita el total a cuenta');

        // Revertir: stock vuelve al neto vendido, deuda reaparece.
        $dev = Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->latest('id')->first();
        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id])->assertStatus(200);
        $this->assertEquals(96, Producto::find($prod->id)->stock1, 'revertir devuelve al neto vendido');
        $v = $venta->fresh();
        $this->assertEquals($total, (float) $v->saldo, 'la deuda reaparece tras revertir');
        $this->assertEquals(0.0, (float) $v->acuenta);
    }

    /** Devolver MÁS de lo vendido (límite) debe rechazarse, incluso fraccionando devoluciones. */
    public function test_devolver_mas_de_lo_vendido_es_rechazado(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta, 'prod' => $prod] = $this->ventaCreditoValidada(3, 25.0, 100);

        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2])->assertStatus(200);
        // Ya devueltas 2 de 3 → devolver 2 más excede el límite.
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2])->assertStatus(422);
        // La 3ª sí entra.
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(200);
        // Ahora cualquier devolución adicional excede.
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(422);
        $this->assertEquals(3, (int) Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->sum('cantidad'), 'solo se devolvieron las 3 vendidas');
    }

    /**
     * Anular una venta VALIDO que tiene una devolución ON: el stock debe quedar en su valor
     * ORIGINAL (todo restituido) sin doble-conteo, y NO debe quedar plata "fantasma" en caja.
     * (destroy() restaura `cantidad - cantDev` y apaga las tranzas D-VEN; este test fija que el
     * stock neto y la caja queden coherentes tras anular con devolución previa.)
     */
    public function test_anular_venta_con_devolucion_previa_no_dobla_stock_ni_caja(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 25, 'stock1' => 100]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 6])->assertStatus(200);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200); // stock 100→94, caja +150

        // Devolver 2 → stock 96, egreso 50.
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2])->assertStatus(200);
        $this->assertEquals(96, Producto::find($prod->id)->stock1);

        // Anular: debe restituir las 4 unidades que siguen vendidas (96+4=100) — NO 96+6.
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $this->assertEquals(100, Producto::find($prod->id)->stock1, 'stock vuelve EXACTO al original (sin doblar la devolución)');

        // Caja: todas las tranzas (VEN, D-VEN, COB) de la venta quedan OFF → neto 0.
        $netoOn = (float) Tranza::where('registro', $venta->id)->where('estado', 'ON')
            ->selectRaw('COALESCE(SUM(monto_ingreso - monto_egreso),0) n')->value('n');
        $this->assertEquals(0.0, $netoOn, 'sin plata fantasma en caja tras anular');

        // No se puede revertir la devolución de una venta ya anulada (evita doble-conteo).
        $dev = Devventa::where('venta_id', $venta->id)->latest('id')->first();
        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id])->assertStatus(422);
        $this->assertEquals(100, Producto::find($prod->id)->stock1, 'el intento de revertir sobre anulada no toca el stock');
    }

    /**
     * Intermedio difícil: agregar el MISMO producto en dos llamadas acumula en un renglón
     * (no duplica), y el límite de devolución usa la cantidad ACUMULADA — devolver hasta el
     * total acumulado se permite, una unidad más se rechaza.
     */
    public function test_acumulacion_de_renglon_define_limite_de_devolucion(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 10, 'stock1' => 100]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        // Dos llamadas del mismo producto: 3 + 2 = 5 acumuladas en UN renglón.
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 3])->assertStatus(200);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2])->assertStatus(200);
        $this->assertEquals(1, Ventadetalle::where('venta_id', $venta->id)->where('estado', 'VALIDO')->count(), 'un solo renglón acumulado');
        $this->assertEquals(50.0, (float) $venta->fresh()->total, 'total = 5×10');

        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        // Devolver las 5 acumuladas: OK. La 6ª: rechazada.
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 5])->assertStatus(200);
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(422);
    }

    /**
     * Cobro con fecha futura debe rechazarse (no se puede cobrar "mañana").
     * Y la fecha de cobro no puede ser anterior a la fecha de la venta.
     */
    public function test_cobro_con_fecha_futura_o_anterior_a_la_venta_es_rechazado(): void
    {
        $this->actingAsUser('ADMIN');
        ['venta' => $venta] = $this->ventaCreditoValidada();

        $manana = now()->addDay()->format('Y-m-d');
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 10, 'fecha' => $manana])->assertStatus(422);

        $ayerDeLaVenta = now()->subDays(5)->format('Y-m-d'); // anterior a la venta (hoy)
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 10, 'fecha' => $ayerDeLaVenta])->assertStatus(422);

        $this->assertEquals(0, Tranza::where('registro', $venta->id)->where('clase', 'COB')->count(), 'ningún cobro con fecha inválida');
    }

    /**
     * Validar tras stock agotado por OTRA venta (TOCTOU determinista): negativos pasó cuando
     * había stock, pero entre el chequeo y validar el stock cayó. El guard de validar DEBE
     * re-chequear y rechazar (no dejar stock negativo / sobreventa).
     */
    public function test_validar_rechaza_si_el_stock_cayo_tras_negativos(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['stock1' => 5]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 5])->assertStatus(200);

        // negativos dice que NO hay faltante (stock 5 == pedido 5).
        $this->postJson('/api/ventas/negativos', ['venta_id' => $venta->id])->assertJson(['negativo' => false]);

        // Simulamos que otra operación consumió el stock (cae a 1) ANTES de validar.
        $p = Producto::find($prod->id); $p->stock1 = 1; $p->save();

        // validar DEBE rechazar (re-chequeo del lado servidor) y NO dejar stock negativo.
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(422);
        $this->assertEquals(1, Producto::find($prod->id)->stock1, 'el stock no se descuenta si es insuficiente');
        $this->assertEquals('PROFORMA', $venta->fresh()->estado, 'la venta sigue proforma');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. DESCUENTO — superficie de ataque del encabezado
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Contrato: la API de ventas NO expone una vía para fijar `descuento` del encabezado
     * (updateEncabezado no lo acepta; updateItem fuerza descuento=0 por renglón). El total
     * SIEMPRE = Σ(costo·cantidad), nunca negativo. Este test FIJA ese contrato: si alguien
     * agrega un campo descuento al encabezado sin clampearlo, este test debe romperse.
     */
    public function test_descuento_no_inyectable_por_encabezado_total_no_negativo(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 50, 'stock1' => 100]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2])->assertStatus(200); // total 100

        // Intentar inyectar un descuento gigante por el encabezado (no debe afectar el total).
        $this->postJson('/api/ventas/update-encabezado', [
            'venta_id' => $venta->id, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'descuento' => 999999,
        ])->assertStatus(200);

        $v = $venta->fresh();
        $this->assertEquals(100.0, (float) $v->total, 'el total ignora cualquier descuento inyectado por encabezado');
        $this->assertGreaterThanOrEqual(0.0, (float) $v->total, 'total nunca negativo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. STOCK MULTI-SUCURSAL — validar/anular solo tocan stock{sucursal_id}
    // ─────────────────────────────────────────────────────────────────────────

    /** Validar una venta de sucursal 1 descuenta SOLO stock1; el resto intacto. Anular lo restituye. */
    public function test_validar_y_anular_solo_tocan_la_columna_de_la_sucursal(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['stock1' => 50, 'stock2' => 7, 'stock3' => 3, 'stock4' => 9, 'stock5' => 11]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 8])->assertStatus(200);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        $p = Producto::find($prod->id);
        $this->assertEquals(42, $p->stock1, 'descuenta stock1');
        $this->assertEquals([7, 3, 9, 11], [$p->stock2, $p->stock3, $p->stock4, $p->stock5], 'otras sucursales intactas');

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $p = Producto::find($prod->id);
        $this->assertEquals(50, $p->stock1, 'anular restituye stock1');
        $this->assertEquals([7, 3, 9, 11], [$p->stock2, $p->stock3, $p->stock4, $p->stock5], 'otras sucursales siguen intactas');
    }
}
