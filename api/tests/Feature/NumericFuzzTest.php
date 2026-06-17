<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Cuenta;
use App\Models\Envio;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Tests\TestCase;

/**
 * Fuzzing de bordes numéricos (técnica E) sobre endpoints de dinero/stock.
 *
 * Un API público NUNCA debe responder 500 ni corromper datos ante entradas
 * numéricas malformadas o extremas: debe contestar 4xx limpio. Aquí se bombardea
 * `cantidad`/`costo` con una batería adversaria.
 *
 * Hallazgo dirigido: los validadores usan `numeric` (admite decimales) mientras la
 * columna `ventadetalles.cantidad` y `productos.stockN` son `int(11)`. Una cantidad
 * fraccionaria pasa validación y revienta/corrompe en la capa de BD.
 */
class NumericFuzzTest extends TestCase
{
    private function nuevaProforma(): array
    {
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 100, 'stock1' => 1000]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
        return [$venta, $prod];
    }

    public function test_cantidad_fraccionaria_no_revienta_ni_corrompe(): void
    {
        $this->actingAsUser('ADMIN');
        [$venta, $prod] = $this->nuevaProforma();

        $resp = $this->postJson('/api/ventas/agregar-item', [
            'venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2.5,
        ]);

        // Debe rechazarse limpio (422), NO 500 ni guardar una línea corrupta.
        $resp->assertStatus(422);
        $this->assertEquals(0, Ventadetalle::where('venta_id', $venta->id)->where('estado', 'VALIDO')->count(),
            'una cantidad fraccionaria no debe crear renglón');
    }

    /**
     * Batería: ninguna entrada numérica basura debe producir 500.
     * Acepta 422 (rechazo) o 200 (si es válida y se guarda íntegra).
     */
    public function test_bateria_de_cantidades_basura_nunca_da_500(): void
    {
        $this->actingAsUser('ADMIN');

        $valores = [
            '-5', '0', '0.001', 'abc', '1,5', 'NaN', 'Infinity', '1e3',
            '  3  ', '0x10', '3.0000001', '٣', '999999999999', '2.5',
        ];

        foreach ($valores as $v) {
            [$venta, $prod] = $this->nuevaProforma();
            $resp = $this->postJson('/api/ventas/agregar-item', [
                'venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => $v,
            ]);
            $this->assertNotEquals(500, $resp->status(), "cantidad basura '{$v}' produjo 500 (esperado 4xx)");

            // Si pasó (200), el renglón guardado debe tener cantidad ENTERA y consistente.
            if ($resp->status() === 200) {
                $d = Ventadetalle::where('venta_id', $venta->id)->where('estado', 'VALIDO')->first();
                $this->assertNotNull($d, "200 sin renglón para '{$v}'");
                $this->assertEquals((int) $d->cantidad, (float) $d->cantidad,
                    "cantidad '{$v}' quedó fraccionaria en columna int");
                $this->assertEqualsWithDelta((float) $d->costo * (float) $d->cantidad, (float) $d->monto, 0.01,
                    "monto inconsistente para '{$v}'");
            }
        }
    }

    /**
     * La misma clase de bug existía en Compras y Envíos (cantidad numeric → columna int).
     * Representativo del fix sistémico en los 6 controladores de documento.
     */
    public function test_cantidad_fraccionaria_rechazada_en_compras_y_envios(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal_id = 1
        $prod = Producto::factory()->create(['stock1' => 100]);

        $compra = Compra::factory()->create(['sucursal_id' => 1, 'estado' => 'PROFORMA']);
        $this->postJson('/api/compras/agregar-item', [
            'compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 2.5,
        ])->assertStatus(422);

        $envio = Envio::factory()->create(['sucursal_id' => 1, 'estado' => 'PROFORMA']);
        $this->postJson('/api/envios/agregar-item', [
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'cantidad' => 2.5,
        ])->assertStatus(422);

        // Y el desbordamiento entero tampoco revienta (clean 422 en vez de 500).
        $this->postJson('/api/compras/agregar-item', [
            'compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 999999999999,
        ])->assertStatus(422);
    }

    /**
     * Tras ampliar las columnas de dinero a DECIMAL(12,2), una venta legítima > 10M Bs
     * (que antes desbordaba DECIMAL(9,2) → 500) se procesa sin reventar y guarda el total
     * exacto, incluida la tranza de caja por ese monto.
     */
    public function test_venta_mayor_a_10_millones_no_desborda(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 300, 'stock1' => 60000]);
        $venta  = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO',
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);

        // 50.000 × 300 = 15.000.000 Bs (excede el viejo tope de 9.999.999,99).
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 50000])
            ->assertStatus(200);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        $this->assertEquals(15000000.0, (float) $venta->fresh()->total, 'total grande exacto, sin truncar');
        $this->assertEquals(10000, (float) Producto::find($prod->id)->stock1);
        $this->assertEquals(15000000.0, (float) \App\Models\Tranza::where('registro', $venta->id)
            ->where('clase', 'VEN')->where('estado', 'ON')->sum('monto_ingreso'), 'tranza de caja sin desbordar');
    }
}
