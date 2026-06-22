<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Compradetalle;
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

    /**
     * REGRESIÓN DECIMALES (fix 20/6, decisión Opción B = replicar legacy): el total del
     * documento suma el SUBTOTAL GUARDADO —calculado desde el precio de precisión completa
     * ANTES de que la columna `costo` decimal(9,2) lo trunque—, NO `costo_truncado × cantidad`.
     * Antes del fix, COMPRAS sumaba SUM(costo*cantidad) → 83.33×12 = 999.96 (descuadre que
     * reportó Tefy). 83.3333×12 = 999.9996 → la columna subtotal redondea a 1000.00.
     */
    public function test_compra_total_usa_subtotal_guardado_con_precio_fraccionario(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $prod   = Producto::factory()->create();
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', [
            'compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 12, 'costo' => 83.3333,
        ])->assertStatus(200);

        $det = Compradetalle::where('compra_id', $compra->id)->where('estado', 'VALIDO')->first();
        $this->assertEqualsWithDelta(1000.00, (float) $det->subtotal, 0.001, 'El subtotal se calcula desde el precio completo (no costo truncado)');
        $this->assertEqualsWithDelta(1000.00, (float) Compra::find($compra->id)->total, 0.001, 'El total suma SUM(subtotal), no costo_truncado×cantidad (daría 999.96)');

        // apiDetalles debe exponer el subtotal GUARDADO (no recomputar costo×cantidad).
        $detalles = $this->getJson("/api/compras/{$compra->id}/detalles")->assertStatus(200)->json();
        $this->assertEqualsWithDelta(1000.00, (float) $detalles[0]['subtotal_num'], 0.001);
    }

    /**
     * Multi-renglón con precios fraccionarios (patrón de los 111 renglones legacy donde
     * subtotal != round(costo×cantidad)): total = Σ subtotales guardados, exacto.
     * 166.6667×3=500.00 ; 16.6667×24=400.00 → 900.00 (con costo truncado daría 900.09).
     */
    public function test_compra_total_multirenglon_fraccionario_cuadra(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $p1->id, 'cantidad' => 3,  'costo' => 166.6667])->assertStatus(200);
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $p2->id, 'cantidad' => 24, 'costo' => 16.6667])->assertStatus(200);

        $suma  = (float) Compradetalle::where('compra_id', $compra->id)->where('estado', 'VALIDO')->sum('subtotal');
        $total = (float) Compra::find($compra->id)->total;
        $this->assertEqualsWithDelta($suma, $total, 0.001);
        $this->assertEqualsWithDelta(900.00, $total, 0.001);
    }

    /**
     * VENTAS (regresión del fix 19/6 + PDF 20/6): un precio de precisión completa cuadra
     * el total del documento contra la suma de subtotales guardados.
     */
    public function test_venta_precio_fraccionario_cuadra_total(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $prod   = Producto::factory()->create(['stock1' => 100]);
        $venta  = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 12])->assertStatus(200);
        $det = Ventadetalle::where('venta_id', $venta->id)->where('estado', 'VALIDO')->first();
        $this->postJson('/api/ventas/update-item', ['registro' => $det->id, 'costo' => 83.3333, 'cantidad' => 12])->assertStatus(200);

        $this->assertEqualsWithDelta(1000.00, (float) Ventadetalle::find($det->id)->subtotal, 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) Venta::find($venta->id)->total, 0.001);
    }

    /**
     * COTIZACIONES (regresión del fix 19/6): precio fraccionario cuadra el total.
     */
    public function test_cotizacion_precio_fraccionario_cuadra_total(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $prod   = Producto::factory()->create();
        $cotId  = $this->postJson('/api/cotizaciones', ['fecha' => now()->format('Y-m-d'), 'cuenta_id' => $cuenta->id, 'descuento' => 0])->json('id');

        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cotId, 'producto_id' => $prod->id, 'cantidad' => 12, 'precio' => 83.3333])->assertStatus(200);
        $this->assertEqualsWithDelta(1000.00, (float) Cotizacion::find($cotId)->total, 0.001);
    }
}
