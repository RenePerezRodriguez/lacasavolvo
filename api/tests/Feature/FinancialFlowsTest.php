<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Devventa;
use App\Models\Producto;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Flujos financieros enredados (D6/D10): crédito + cobro + devolución, y guards de caja cerrada.
 */
class FinancialFlowsTest extends TestCase
{
    public function test_credito_cobro_y_devolucion_mantienen_saldo_consistente(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 100, 'p_norm' => 25]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]); // total 100
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        // Cobro parcial 30 → saldo 70
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 30])->assertStatus(200);
        // Devolución de 1 unidad (25) → en crédito sube acuenta y baja saldo → saldo 45
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 1])->assertStatus(200);

        $v = Venta::find($venta->id);
        $this->assertGreaterThanOrEqual(0, (float) $v->saldo, 'saldo nunca negativo');
        // total - acuenta = saldo (invariante)
        $this->assertEqualsWithDelta((float) $v->total - (float) $v->acuenta, (float) $v->saldo, 0.001);
    }

    public function test_validar_venta_en_periodo_cerrado_se_bloquea(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'fecha' => now()->toDateString(), 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]);

        // Abrir y cerrar caja hoy → ultimo_cierre = hoy
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);

        // Validar una venta de hoy (periodo cerrado) → 422, y stock intacto
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(422);
        $this->assertEquals(10, Producto::find($producto->id)->stock1);
    }

    public function test_devolucion_en_caja_cerrada_se_bloquea(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'fecha' => now()->toDateString(), 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200); // stock 6

        // Cerrar caja hoy
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);

        // Devolver con caja de hoy cerrada → 422, stock sin inflar
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 1])->assertStatus(422);
        $this->assertEquals(6, Producto::find($producto->id)->stock1);
        $this->assertEquals(0, Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->count());
    }
}
