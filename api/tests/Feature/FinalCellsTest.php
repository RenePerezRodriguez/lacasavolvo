<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Medio;
use App\Models\Producto;
use Tests\TestCase;

/**
 * Cierre de las últimas celdas con valor real del AUDIT-MATRIX:
 *  - Compras D7: validar es idempotente (no descuenta/agrega stock dos veces).
 *  - Envíos D10/D1: recibir un envío no-enviado → 422; recibir de sucursal ajena → 403.
 *  - Caja D2: validación de monto (negativo/cero/no numérico) en ingreso y egreso.
 *  - Cotizaciones D10: no se convierte una cotización ANULADA.
 *
 * (Las celdas restantes del matrix se marcan ➖ N/A con justificación: CRUD de una fila
 * sin transacción multi-paso, reportes de solo-lectura, o concurrencia real con hilos.)
 */
class FinalCellsTest extends TestCase
{
    public function test_compra_validar_dos_veces_es_idempotente(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        $prod = Producto::factory()->create(['stock1' => 10]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'estado' => 'PROFORMA']);
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 5, 'costo' => 3]);

        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200); // stock 15
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(422); // segunda vez rechazada

        $this->assertEquals(15, (float) Producto::find($prod->id)->stock1, 'stock agregado una sola vez');
    }

    public function test_recibir_envio_no_enviado_o_de_sucursal_ajena_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1

        // Destino = sucursal 1 (la del usuario) pero aún PROFORMA → 422 (no está en tránsito).
        $propio = Envio::factory()->create(['sucursal_id' => 2, 'cuenta_id' => 1, 'medio_id' => Medio::factory()->create()->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        $this->postJson("/api/envios/recibir/{$propio->id}")->assertStatus(422);

        // Destino = sucursal 2 (ajena) → 403 aunque esté ENVIADO.
        $ajeno = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => Medio::factory()->create()->id, 'estado' => 'ENVIADO', 'pagado' => 'PAGADO', 'monto' => 0]);
        $this->postJson("/api/envios/recibir/{$ajeno->id}")->assertStatus(403);
    }

    public function test_caja_monto_invalido_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');

        foreach (['ingreso', 'egreso'] as $op) {
            $this->postJson("/api/caja/{$op}", ['monto' => -5, 'descripcion' => 'x'])->assertStatus(422);
            $this->postJson("/api/caja/{$op}", ['monto' => 0, 'descripcion' => 'x'])->assertStatus(422);
            $this->postJson("/api/caja/{$op}", ['monto' => 'abc', 'descripcion' => 'x'])->assertStatus(422);
        }
    }

    public function test_no_se_convierte_cotizacion_anulada(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        $cuenta = Cuenta::factory()->cliente()->create();
        $cot = Cotizacion::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'ANULADO']);

        $this->postJson("/api/cotizaciones/{$cot->id}/venta")->assertStatus(422);
        $this->assertEquals(0, \App\Models\Venta::where('cuenta_id', $cuenta->id)->count(), 'no se crea venta desde una cotización anulada');
    }
}
