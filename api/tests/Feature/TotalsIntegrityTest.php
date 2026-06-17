<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Tests\TestCase;

/**
 * INVARIANTE D6 (Totales/Saldos): la aritmética de los documentos debe ser consistente:
 *  - total = Σ subtotales de renglones VALIDO
 *  - saldo = total − acuenta, y nunca negativo
 *  - la conversión cotización→venta preserva el total
 */
class TotalsIntegrityTest extends TestCase
{
    public function test_venta_total_es_suma_exacta_de_subtotales(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $p1 = Producto::factory()->create(['p_norm' => 12.50]);
        $p2 = Producto::factory()->create(['p_norm' => 7.25]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $p1->id, 'cantidad' => 3]); // 37.50
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $p2->id, 'cantidad' => 4]); // 29.00

        $total = (float) Venta::find($venta->id)->total;
        $suma  = (float) Ventadetalle::where('venta_id', $venta->id)->where('estado', 'VALIDO')->sum('subtotal');
        $this->assertEqualsWithDelta($suma, $total, 0.001);
        $this->assertEqualsWithDelta(66.50, $total, 0.001);
    }

    public function test_venta_credito_saldo_consistente_y_no_negativo(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 100, 'p_norm' => 25]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]); // total 100
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 40])->assertStatus(200);
        $v = Venta::find($venta->id);
        $this->assertEqualsWithDelta(60, (float) $v->saldo, 0.001);
        $this->assertEqualsWithDelta((float) $v->total - (float) $v->acuenta, (float) $v->saldo, 0.001);

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 60])->assertStatus(200);
        $v = Venta::find($venta->id);
        $this->assertEqualsWithDelta(0, (float) $v->saldo, 0.001);
        $this->assertEquals('PAGADO', $v->pagado);

        // No se puede cobrar de más (saldo ya 0)
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 1])->assertStatus(422);
        $this->assertGreaterThanOrEqual(0, (float) Venta::find($venta->id)->saldo);
    }

    public function test_cotizacion_sin_descuento_a_venta_preserva_total(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $p1 = Producto::factory()->create(['p_norm' => 30]);
        $p2 = Producto::factory()->create(['p_norm' => 45]);

        $cotId = $this->postJson('/api/cotizaciones', ['fecha' => now()->format('Y-m-d'), 'cuenta_id' => $cuenta->id, 'descuento' => 0])->json('id');
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cotId, 'producto_id' => $p1->id, 'cantidad' => 2, 'precio' => 30]); // 60
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cotId, 'producto_id' => $p2->id, 'cantidad' => 1, 'precio' => 45]); // 45

        $cotTotal = (float) Cotizacion::find($cotId)->total; // 105
        $ventaId  = $this->postJson("/api/cotizaciones/{$cotId}/venta")->json('id');

        $this->assertNotNull($ventaId);
        $ventaTotal = (float) Venta::find($ventaId)->total;
        $this->assertEqualsWithDelta($cotTotal, $ventaTotal, 0.001, 'La venta convertida debe preservar el total de la cotización');
        $this->assertEqualsWithDelta(105, $ventaTotal, 0.001);
    }
}
