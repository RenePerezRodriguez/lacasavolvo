<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Casos borde (D2/D3/D10): validaciones, transiciones ilegales y aritmética con descuento.
 * Probes donde el comportamiento no era obvio — para descartar divergencias del legacy.
 */
class EdgeCasesTest extends TestCase
{
    // ── D2: cantidades inválidas rechazadas en TODOS los item-endpoints ──

    public static function itemQtyEndpoints(): array
    {
        return [
            'venta.agregar'  => ['/api/ventas/agregar-item', 'venta_id'],
            'compra.agregar' => ['/api/compras/agregar-item', 'compra_id'],
            'cotiz.agregar'  => ['/api/cotizaciones/agregar-item', 'cotizacion_id'],
            'envio.agregar'  => ['/api/envios/agregar-item', 'envio_id'],
            'pedido.agregar' => ['/api/pedidos/agregar-item', 'pedido_id'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('itemQtyEndpoints')]
    public function test_agregar_item_rechaza_cantidad_cero_o_negativa(string $uri, string $idKey): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create();

        // cantidad 0 y negativa deben dar 422 (validación min:0.01)
        $this->postJson($uri, [$idKey => 1, 'producto_id' => $producto->id, 'cantidad' => 0])->assertStatus(422);
        $this->postJson($uri, [$idKey => 1, 'producto_id' => $producto->id, 'cantidad' => -5])->assertStatus(422);
    }

    // ── D6: cotización CON descuento → venta preserva el total (dentro del redondeo legacy) ──

    public function test_cotizacion_con_descuento_a_venta_preserva_total(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $p1 = Producto::factory()->create(['p_norm' => 100]);
        $p2 = Producto::factory()->create(['p_norm' => 80]);

        // total bruto 260, descuento 60 → total neto 200
        $cotId = $this->postJson('/api/cotizaciones', ['fecha' => now()->format('Y-m-d'), 'cuenta_id' => $cuenta->id, 'descuento' => 60])->json('id');
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cotId, 'producto_id' => $p1->id, 'cantidad' => 2, 'precio' => 100]); // 200
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cotId, 'producto_id' => $p2->id, 'cantidad' => 1, 'precio' => 60]);  // 60 → bruto 260

        $cot = Cotizacion::find($cotId);
        $cotTotal = (float) $cot->total; // 260 - 60 = 200

        $ventaId = $this->postJson("/api/cotizaciones/{$cotId}/venta")->json('id');
        $ventaTotal = (float) Venta::find($ventaId)->total;

        // Fiel al legacy: el total de la venta = total de la cotización, exacto.
        $this->assertEqualsWithDelta($cotTotal, $ventaTotal, 0.001, "venta {$ventaTotal} debe = cotización {$cotTotal}");
        $this->assertEqualsWithDelta(200, $ventaTotal, 0.001);
    }

    // ── D3: no se puede operar sobre documentos en estado terminal ──

    public function test_no_se_puede_agregar_item_a_venta_validada(): void
    {
        $user = $this->actingAsUser();
        $venta = Venta::factory()->valido()->create(['sucursal_id' => 1]);
        $producto = Producto::factory()->create();

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 1])
            ->assertStatus(422);
    }

    public function test_no_se_puede_cobrar_venta_contado(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $venta = Venta::factory()->valido()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'total' => 100, 'saldo' => 0]);

        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 10])
            ->assertStatus(422);
    }

    public function test_no_se_puede_anular_venta_dos_veces(): void
    {
        $user = $this->actingAsUser();
        $venta = Venta::factory()->valido()->create(['sucursal_id' => 1]);

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(422);
    }
}
