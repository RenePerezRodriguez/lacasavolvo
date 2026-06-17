<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Idempotencia / doble-submit (técnica C, porción determinista de concurrencia).
 *
 * El "race" práctico no necesita hilos: un doble-click manda dos requests. Una
 * operación que materializa efectos (crear venta, validar, anular) NO debe duplicar
 * esos efectos si se reintenta. Reproducible de forma determinista (secuencial).
 */
class IdempotencyTest extends TestCase
{
    /** BUG: convertir la misma cotización dos veces creaba DOS ventas. */
    public function test_convertir_cotizacion_dos_veces_no_duplica_venta(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100, 'stock1' => 50]);
        $cot    = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);

        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 2,
        ])->assertStatus(200);

        $this->postJson("/api/cotizaciones/{$cot->id}/venta")->assertStatus(200);
        $this->assertEquals(1, Venta::where('cuenta_id', $cuenta->id)->count(), 'la primera conversión crea 1 venta');

        // Segundo submit (doble-click): NO debe crear una segunda venta.
        $this->postJson("/api/cotizaciones/{$cot->id}/venta")->assertStatus(422);
        $this->assertEquals(1, Venta::where('cuenta_id', $cuenta->id)->count(), 'el doble-submit no debe duplicar la venta');
        $this->assertEquals('CONVERTIDA', $cot->fresh()->estado);
    }

    /** Validar dos veces la misma venta no debe descontar stock dos veces. */
    public function test_validar_venta_dos_veces_es_idempotente(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['stock1' => 10]);
        $venta  = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 4]);

        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(422); // segunda vez rechazada

        $this->assertEquals(6, (float) Producto::find($prod->id)->stock1, 'stock descontado una sola vez');
    }

    /** Anular dos veces la misma venta no debe restituir stock dos veces. */
    public function test_anular_venta_dos_veces_es_idempotente(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['stock1' => 10]);
        $venta  = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200); // stock 6

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);       // restituye → 10
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(422);       // segunda anulación rechazada

        $this->assertEquals(10, (float) Producto::find($prod->id)->stock1, 'stock restituido una sola vez');
    }
}
