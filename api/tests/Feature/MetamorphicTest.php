<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Medio;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Tests\TestCase;

/**
 * Pruebas METAMÓRFICAS (técnica D): no comprueban un valor esperado fijo, sino
 * RELACIONES que deben mantenerse entre dos formas distintas de llegar al mismo
 * estado. Si dos caminos equivalentes divergen, hay un bug aunque cada camino
 * "parezca" correcto por separado.
 */
class MetamorphicTest extends TestCase
{
    private function stock(int $pid, int $suc = 1): float
    {
        return (float) Producto::find($pid)->{'stock' . $suc};
    }

    private function nuevaVenta(int $cuentaId, string $tipo = 'CONTADO'): Venta
    {
        return Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuentaId, 'tipo' => $tipo,
            'fecha' => now()->toDateString(), 'estado' => 'PROFORMA',
        ]);
    }

    /** MR-A: vender 5 en un solo agregar ≡ vender 2 + 3 (acumulación). */
    public function test_split_sale_equivale_a_venta_combinada(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();

        // Camino 1: una sola línea de 5
        $prodA = Producto::factory()->create(['p_norm' => 30, 'stock1' => 100]);
        $vA = $this->nuevaVenta($cuenta->id);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vA->id, 'producto_id' => $prodA->id, 'cantidad' => 5]);
        $this->postJson("/api/ventas/validar/{$vA->id}")->assertStatus(200);

        // Camino 2: 2 + 3 (debe acumularse en un único renglón)
        $prodB = Producto::factory()->create(['p_norm' => 30, 'stock1' => 100]);
        $vB = $this->nuevaVenta($cuenta->id);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vB->id, 'producto_id' => $prodB->id, 'cantidad' => 2]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vB->id, 'producto_id' => $prodB->id, 'cantidad' => 3]);
        $this->postJson("/api/ventas/validar/{$vB->id}")->assertStatus(200);

        // Relación metamórfica: mismo total, mismo descuento de stock, un solo renglón.
        $this->assertEquals((float) $vA->fresh()->total, (float) $vB->fresh()->total, 'totales deben coincidir');
        $this->assertEquals($this->stock($prodA->id), $this->stock($prodB->id), 'stock descontado debe coincidir');
        $this->assertEquals(95, $this->stock($prodB->id));
        $this->assertEquals(1, Ventadetalle::where('venta_id', $vB->id)->where('estado', 'VALIDO')->count(), 'debe acumular en 1 renglón');
        $this->assertEquals(5, (int) Ventadetalle::where('venta_id', $vB->id)->where('estado', 'VALIDO')->first()->cantidad);
    }

    /** MR-B: el orden de agregar dos productos no cambia el estado final. */
    public function test_orden_de_agregar_no_afecta_resultado(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();

        $p1 = Producto::factory()->create(['p_norm' => 40, 'stock1' => 100]);
        $p2 = Producto::factory()->create(['p_norm' => 70, 'stock1' => 100]);

        // Camino 1: p1 luego p2
        $vA = $this->nuevaVenta($cuenta->id);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vA->id, 'producto_id' => $p1->id, 'cantidad' => 3]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vA->id, 'producto_id' => $p2->id, 'cantidad' => 2]);

        // Camino 2: p2 luego p1
        $vB = $this->nuevaVenta($cuenta->id);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vB->id, 'producto_id' => $p2->id, 'cantidad' => 2]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vB->id, 'producto_id' => $p1->id, 'cantidad' => 3]);

        $this->assertEquals((float) $vA->fresh()->total, (float) $vB->fresh()->total, 'el total no debe depender del orden');
        $this->assertEquals(3 * 40 + 2 * 70, (float) $vA->fresh()->total);
    }

    /**
     * MR-C: devolver 3 de una vez ≡ devolver 1 tres veces (stock y dinero).
     * Ejercita el recálculo determinista de saldo introducido en el loop 1.
     */
    public function test_devolucion_decompuesta_equivale_a_devolucion_unica(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();

        // Camino 1: devolver 3 de una vez (venta CREDITO, sin cobros)
        $pX = Producto::factory()->create(['p_norm' => 50, 'stock1' => 100]);
        $vX = $this->nuevaVenta($cuenta->id, 'CREDITO');
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vX->id, 'producto_id' => $pX->id, 'cantidad' => 5]);
        $this->postJson("/api/ventas/validar/{$vX->id}")->assertStatus(200);
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $vX->id, 'producto_id' => $pX->id, 'cantidad' => 3])->assertStatus(200);

        // Camino 2: devolver 1 tres veces
        $pY = Producto::factory()->create(['p_norm' => 50, 'stock1' => 100]);
        $vY = $this->nuevaVenta($cuenta->id, 'CREDITO');
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $vY->id, 'producto_id' => $pY->id, 'cantidad' => 5]);
        $this->postJson("/api/ventas/validar/{$vY->id}")->assertStatus(200);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/ventas/dev-item', ['venta_id' => $vY->id, 'producto_id' => $pY->id, 'cantidad' => 1])->assertStatus(200);
        }

        // Relación: mismo stock restituido y mismo estado contable.
        $this->assertEquals($this->stock($pX->id), $this->stock($pY->id), 'stock final debe coincidir');
        $this->assertEquals(98, $this->stock($pY->id)); // 100 - 5 + 3
        $vXf = $vX->fresh(); $vYf = $vY->fresh();
        $this->assertEquals((float) $vXf->acuenta, (float) $vYf->acuenta, 'acuenta debe coincidir');
        $this->assertEquals((float) $vXf->saldo, (float) $vYf->saldo, 'saldo debe coincidir');
        $this->assertEquals($vXf->pagado, $vYf->pagado, 'estado de pago debe coincidir');
    }

    /**
     * MR-D: conservación global de stock. La suma de stock de TODAS las sucursales
     * no cambia tras un envío enviado+recibido (la mercadería se mueve, no se crea
     * ni se destruye). En tránsito baja; al recibir, se restaura.
     */
    public function test_envio_conserva_stock_total_entre_sucursales(): void
    {
        $user = $this->actingAsUser('ADMIN'); // origen sucursal 1
        $medio = Medio::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'stock2' => 3, 'stock3' => 0, 'stock4' => 0, 'stock5' => 0]);

        $sumaInicial = $this->sumaStock($producto->id);

        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => $medio->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create(['envio_id' => $envio->id, 'producto_id' => $producto->id, 'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '', 'cantidad' => 4, 'estado' => 'VALIDO']);

        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals($sumaInicial - 4, $this->sumaStock($producto->id), 'en tránsito la suma baja por la cantidad enviada');

        $user->sucursal_id = 2; $user->save(); $this->actingAs($user, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);

        $this->assertEquals($sumaInicial, $this->sumaStock($producto->id), 'tras recibir, la suma total se conserva');
        $this->assertEquals(6, $this->stock($producto->id, 1)); // origen 10-4
        $this->assertEquals(7, $this->stock($producto->id, 2)); // destino 3+4
    }

    private function sumaStock(int $pid): float
    {
        $p = Producto::find($pid);
        return (float) ($p->stock1 + $p->stock2 + $p->stock3 + $p->stock4 + $p->stock5);
    }
}
