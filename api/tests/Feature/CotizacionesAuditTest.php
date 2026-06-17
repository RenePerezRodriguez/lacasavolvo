<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\Cotizaciondetalle;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo COTIZACIONES (La Casa Volvo).
 *
 * Cotizaciones es el ÚNICO módulo que expone `descuento` por API → su blast-radius
 * real es D6 (descuento/total) y D3 (máquina de estados sobre el estado terminal
 * CONVERTIDA), más la fidelidad de la conversión a venta.
 *
 * Invariante de dinero defendida en TODA cotización (subtotal == `monto`):
 *   0 <= total <= monto      (total nunca negativo ni inflado por encima del subtotal)
 *
 * Cada test reproduce primero en ROJO (antes del fix) y pasa en VERDE después.
 * Datos sintéticos vía factories + DatabaseTransactions (rollback por test).
 */
class CotizacionesAuditTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // D6 — DESCUENTO: bordes que rompen 0 <= total <= subtotal (casos difíciles)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * BUG (MEDIA): `updateEncabezado` con descuento NEGATIVO infla el total por
     * encima del subtotal. El guard `descuento >= monto/2` no atrapa negativos y
     * `total = monto - (-100) = monto + 100`. Viola total <= subtotal.
     */
    public function test_update_encabezado_descuento_negativo_no_infla_el_total(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);

        // subtotal (monto) = 200
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100,
        ])->assertStatus(200);

        $resp = $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id,
            'cuenta_id'     => $cuenta->id,
            'fecha'         => now()->format('Y-m-d'),
            'descuento'     => -100, // descuento negativo
        ]);

        // Debe rechazarse (422) o, como mínimo, NO inflar el total.
        $cot->refresh();
        $this->assertLessThanOrEqual(
            (float) $cot->monto,
            (float) $cot->total,
            "total ({$cot->total}) no debe superar el subtotal ({$cot->monto}) con descuento negativo"
        );
        $this->assertGreaterThanOrEqual(0, (float) $cot->total, 'total no debe ser negativo');
        // El descuento almacenado tampoco debe ser negativo.
        $this->assertGreaterThanOrEqual(0, (float) $cot->descuento, 'descuento no debe almacenarse negativo');
        $resp->assertStatus(422);
    }

    /**
     * BUG (MEDIA): `updateEncabezado` con monto=0 y descuento>0. El guard se salta
     * por `&& monto > 0` → `total = 0 - descuento` = NEGATIVO. Viola total >= 0.
     */
    public function test_update_encabezado_monto_cero_con_descuento_no_da_total_negativo(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        // Cotización SIN ítems → monto = 0.
        $cot = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO', 'monto' => 0]);

        $resp = $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id,
            'cuenta_id'     => $cuenta->id,
            'fecha'         => now()->format('Y-m-d'),
            'descuento'     => 50, // descuento sobre subtotal 0
        ]);

        $cot->refresh();
        $this->assertGreaterThanOrEqual(0, (float) $cot->total, "total ({$cot->total}) no debe ser negativo con monto=0");
        $resp->assertStatus(422);
    }

    /**
     * BUG (MEDIA): `store` acepta `descuento` SIN validación. Un descuento negativo
     * en creación se persiste y, al agregar ítems, `recalcular` infla el total
     * (`total = monto - (-X) = monto + X`). Misma clase que el bug de updateEncabezado
     * pero por otra puerta de entrada.
     */
    public function test_store_descuento_negativo_no_se_persiste(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100]);

        $resp = $this->postJson('/api/cotizaciones', [
            'fecha'     => now()->format('Y-m-d'),
            'cuenta_id' => $cuenta->id,
            'descuento' => -500,
        ]);
        $resp->assertStatus(422);
    }

    /**
     * Defensa en profundidad de `recalcular` (chokepoint de `total`): aunque los
     * validadores de borde rechazan un descuento negativo, una cotización con
     * descuento POISONED en la BD (dato legacy: la columna `decimal(9,2)` admite
     * negativos y el dump tiene filas viejas) debe quedar saneada al recalcular.
     * Se inyecta el descuento directo por DB (sin pasar por validador) y se dispara
     * `recalcular()` agregando un ítem. Invariante: 0 <= total <= subtotal.
     */
    public function test_recalcular_sanea_descuento_negativo_poisoned_en_bd(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100]);
        // Descuento negativo persistido directo (simula dato legacy, salta el validador).
        $cot = Cotizacion::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO', 'descuento' => -300,
        ]);

        // Agregar ítem (subtotal 100) → dispara recalcular().
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100,
        ])->assertStatus(200);

        $cot->refresh();
        // Sin saneo: total = 100 - (-300) = 400 (inflado). Con saneo: descuento→0, total=100.
        $this->assertEqualsWithDelta(100, (float) $cot->total, 0.001, 'total saneado al subtotal');
        $this->assertGreaterThanOrEqual(0, (float) $cot->descuento, 'descuento negativo saneado a 0');
        $this->assertLessThanOrEqual((float) $cot->monto, (float) $cot->total, 'total no debe superar el subtotal');
    }

    /**
     * Misma defensa por el otro extremo: un descuento POISONED MAYOR al subtotal
     * (dato legacy) daría total negativo. `recalcular` lo clampa al subtotal → total >= 0.
     */
    public function test_recalcular_sanea_descuento_mayor_al_subtotal_poisoned(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100]);
        $cot = Cotizacion::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO', 'descuento' => 5000,
        ]);

        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100,
        ])->assertStatus(200);

        $cot->refresh();
        // Sin saneo: total = 100 - 5000 = -4900. Con clamp: descuento→100, total=0.
        $this->assertGreaterThanOrEqual(0, (float) $cot->total, 'total no debe ser negativo tras recalcular');
        $this->assertLessThanOrEqual((float) $cot->monto, (float) $cot->total, 'total no debe superar el subtotal');
    }

    /**
     * Borde exacto: descuento == monto/2 (el guard usa `>=`, así que la mitad
     * exacta se rechaza). Confirma el contrato del límite superior del descuento.
     */
    public function test_update_encabezado_descuento_igual_a_la_mitad_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100,
        ]); // monto 200

        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => $cuenta->id,
            'fecha' => now()->format('Y-m-d'), 'descuento' => 100, // == monto/2
        ])->assertStatus(422);
    }

    /**
     * Descuento válido (menos de la mitad) sí se aplica → total correcto.
     * Asegura que el endurecimiento no rompe el camino legítimo.
     */
    public function test_update_encabezado_descuento_valido_se_aplica(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100,
        ]); // monto 200

        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => $cuenta->id,
            'fecha' => now()->format('Y-m-d'), 'descuento' => 50,
        ])->assertStatus(true === false ? 422 : 200); // 200 esperado

        $cot->refresh();
        $this->assertEqualsWithDelta(150, (float) $cot->total, 0.001, 'total = 200 - 50');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D3 — MÁQUINA DE ESTADOS: no mutar una cotización ya CONVERTIDA (terminal)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * BUG (MEDIA): los guards de agregarItem/updateItem/deleteItem solo bloquean
     * ANULADO, no CONVERTIDA. Tras convertir a venta, la cotización es un documento
     * terminal ya consumido; agregar/editar/borrar ítems lo deja inconsistente con
     * la venta generada.
     */
    public function test_no_se_puede_agregar_item_a_cotizacion_convertida(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100,
        ])->assertStatus(200);

        // Convertir → estado terminal CONVERTIDA.
        $this->postJson("/api/cotizaciones/{$cot->id}/venta")->assertStatus(200);
        $this->assertEquals('CONVERTIDA', $cot->fresh()->estado);

        // Agregar ítem a una cotización CONVERTIDA debe rechazarse.
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100,
        ])->assertStatus(422);

        $this->assertEquals(1, Cotizaciondetalle::where('cotizacion_id', $cot->id)->where('estado', 'VALIDO')->count(),
            'la cotización convertida no debe ganar renglones nuevos');
    }

    /** Mismo invariante por update-item sobre cotización CONVERTIDA. */
    public function test_no_se_puede_editar_item_de_cotizacion_convertida(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100,
        ])->assertStatus(200);
        $detalle = Cotizaciondetalle::where('cotizacion_id', $cot->id)->first();

        $this->postJson("/api/cotizaciones/{$cot->id}/venta")->assertStatus(200);

        $this->postJson('/api/cotizaciones/update-item', [
            'registro' => $detalle->id, 'cantidad' => 99, 'precio' => 999,
        ])->assertStatus(422);

        $this->assertEquals(2, (int) $detalle->fresh()->cantidad, 'la cantidad del renglón no debe cambiar');
    }

    /** Mismo invariante por delete-item sobre cotización CONVERTIDA. */
    public function test_no_se_puede_borrar_item_de_cotizacion_convertida(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100,
        ])->assertStatus(200);
        $detalle = Cotizaciondetalle::where('cotizacion_id', $cot->id)->first();

        $this->postJson("/api/cotizaciones/{$cot->id}/venta")->assertStatus(200);

        $this->postJson("/api/cotizaciones/delete-item/{$detalle->id}")->assertStatus(422);

        $this->assertEquals('VALIDO', $detalle->fresh()->estado, 'el renglón no debe quedar anulado');
    }

    /**
     * BUG (MEDIA): tampoco se puede editar el encabezado (descuento/cuenta/fecha)
     * de una cotización CONVERTIDA. updateEncabezado solo verificaba sucursal, no
     * el estado terminal → permitía mutar el documento ya consumido por la venta.
     */
    public function test_no_se_puede_editar_encabezado_de_cotizacion_convertida(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100,
        ])->assertStatus(200);

        $this->postJson("/api/cotizaciones/{$cot->id}/venta")->assertStatus(200);
        $totalConvertida = (float) $cot->fresh()->total;

        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => $cuenta->id,
            'fecha' => now()->format('Y-m-d'), 'descuento' => 30,
        ])->assertStatus(422);

        $this->assertEqualsWithDelta($totalConvertida, (float) $cot->fresh()->total, 0.001,
            'el total de una cotización convertida no debe cambiar');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D6 — FIDELIDAD DE LA CONVERSIÓN: la venta vale lo mismo que la cotización
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Metamórfica/conservación: tras convertir una cotización con descuento que NO
     * divide exacto entre ítems, el HEADER de la venta debe valer exactamente el
     * total acordado en la cotización (no se pierde ni gana dinero en el reparto).
     */
    public function test_conversion_header_venta_igual_a_total_cotizacion_con_descuento_no_exacto(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $p1 = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $p2 = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $p3 = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $cot = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);

        // 3 ítems → subtotal 900. Descuento 100 que NO divide exacto entre 3 (33.33).
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cot->id, 'producto_id' => $p1->id, 'cantidad' => 3, 'precio' => 100]);
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cot->id, 'producto_id' => $p2->id, 'cantidad' => 3, 'precio' => 100]);
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cot->id, 'producto_id' => $p3->id, 'cantidad' => 3, 'precio' => 100]);
        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => $cuenta->id,
            'fecha' => now()->format('Y-m-d'), 'descuento' => 100,
        ])->assertStatus(200);

        $cot->refresh();
        $cotTotal = (float) $cot->total; // 900 - 100 = 800

        $ventaId    = $this->postJson("/api/cotizaciones/{$cot->id}/venta")->json('id');
        $ventaTotal = (float) Venta::find($ventaId)->total;

        $this->assertEqualsWithDelta($cotTotal, $ventaTotal, 0.001,
            "el header de la venta ({$ventaTotal}) debe igualar el total de la cotización ({$cotTotal})");
        $this->assertEqualsWithDelta(800, $ventaTotal, 0.001);
    }

    /**
     * Metamórfica: convertir N ítems idénticos preserva el total acordado sin drift.
     * 1 ítem de cantidad 7 a precio 100 con descuento 50 → total 650 → venta 650.
     */
    public function test_conversion_metamorfica_un_renglon_sin_drift(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 7, 'precio' => 100]);
        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => $cuenta->id,
            'fecha' => now()->format('Y-m-d'), 'descuento' => 50,
        ])->assertStatus(200);

        $ventaId    = $this->postJson("/api/cotizaciones/{$cot->id}/venta")->json('id');
        $ventaTotal = (float) Venta::find($ventaId)->total;
        $this->assertEqualsWithDelta(650, $ventaTotal, 0.001);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D2 — CONTRATO / FUZZ: basura en descuento/fecha → 4xx limpio, nunca 500
    // ─────────────────────────────────────────────────────────────────────────

    /** Descuento no numérico → 422 limpio (no 500, no corrupción). */
    public function test_descuento_no_numerico_da_422(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);

        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => $cuenta->id,
            'fecha' => now()->format('Y-m-d'), 'descuento' => 'DROP TABLE',
        ])->assertStatus(422);
    }

    /** Descuento gigantesco no debe inflar/escapar la cota → 422. */
    public function test_descuento_gigante_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100]);

        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => $cuenta->id,
            'fecha' => now()->format('Y-m-d'), 'descuento' => 999999999999,
        ])->assertStatus(422); // supera la mitad del monto → rechazado
    }
}
