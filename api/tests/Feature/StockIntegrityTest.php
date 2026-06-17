<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Devcompra;
use App\Models\Devventa;
use App\Models\Devenvio;
use App\Models\Compra;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Medio;
use App\Models\Producto;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Verifica la INTEGRIDAD DE STOCK a lo largo de los ciclos completos:
 * cada operación que descuenta stock debe tener su contraparte que lo restituye,
 * de modo que un ciclo cerrado (validar→anular, devolución→revertir, etc.) deje
 * el stock EXACTAMENTE como estaba. Cubre ventas, compras, envíos y ajustes.
 */
class StockIntegrityTest extends TestCase
{
    private function stock(int $pid, int $suc = 1): float
    {
        return (float) Producto::find($pid)->{'stock' . $suc};
    }

    // ───────────────────────── VENTAS ─────────────────────────

    public function test_venta_validar_luego_anular_restaura_stock(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->assertEquals(6, $this->stock($producto->id));   // 10 - 4

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $this->assertEquals(10, $this->stock($producto->id));  // restituido
    }

    public function test_venta_devolucion_parcial_y_anular_balancea(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}");          // stock 6
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 1]); // +1 = 7
        $this->assertEquals(7, $this->stock($producto->id));

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $this->assertEquals(10, $this->stock($producto->id));         // total restituido
    }

    public function test_venta_devolucion_y_revertir_balancea(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}");          // 6
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]); // 8
        $dev = Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->first();

        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id])->assertStatus(200); // revierte: -2 = 6
        $this->assertEquals(6, $this->stock($producto->id));
    }

    public function test_venta_revertir_devolucion_sobre_anulada_no_corrompe_stock(): void
    {
        // EDGE: tras anular, las devoluciones quedan ON. Revertir una NO debe volver
        // a descontar stock (la anulación ya saldó el neto).
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}");          // 6
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]); // 8
        $dev = Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->first();
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);  // anular → 10
        $this->assertEquals(10, $this->stock($producto->id));

        // Intentar revertir la devolución de una venta ya anulada
        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id]);
        $this->assertEquals(10, $this->stock($producto->id), 'Revertir devolución sobre venta anulada NO debe alterar stock');
    }

    // ───────────────────────── COMPRAS ─────────────────────────

    public function test_compra_validar_luego_anular_restaura_stock(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 3]);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);
        $this->assertEquals(15, $this->stock($producto->id));   // 10 + 5

        $this->deleteJson("/api/compras/{$compra->id}")->assertStatus(200);
        $this->assertEquals(10, $this->stock($producto->id));   // restituido
    }

    public function test_compra_revertir_devolucion_sobre_anulada_no_corrompe_stock(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 3]);
        $this->postJson("/api/compras/validar/{$compra->id}");        // 15
        $this->postJson('/api/compras/dev-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 2]); // -2 = 13
        $dev = Devcompra::where('compra_id', $compra->id)->where('estado', 'ON')->first();
        $this->deleteJson("/api/compras/{$compra->id}")->assertStatus(200); // anular → 10
        $this->assertEquals(10, $this->stock($producto->id));

        $this->postJson('/api/compras/delete-item-dev', ['registro' => $dev->id]);
        $this->assertEquals(10, $this->stock($producto->id), 'Revertir devolución sobre compra anulada NO debe alterar stock');
    }

    // ───────────────────────── ENVÍOS ─────────────────────────

    public function test_envio_enviar_recibir_y_anular_balancea(): void
    {
        $user = $this->actingAsUser(); // sucursal 1 (origen)
        $medio = Medio::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'stock2' => 0]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => $medio->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create(['envio_id' => $envio->id, 'producto_id' => $producto->id, 'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '', 'cantidad' => 4, 'estado' => 'VALIDO']);

        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals(6, $this->stock($producto->id, 1));  // origen 10-4

        // Recibir: actuar como sucursal destino (2)
        $user->sucursal_id = 2; $user->save();
        $this->actingAs($user, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);
        $this->assertEquals(4, $this->stock($producto->id, 2));  // destino 0+4

        // Anular el envío RECIBIDO: actuar como origen (1)
        $user->sucursal_id = 1; $user->save();
        $this->actingAs($user, 'sanctum');
        $this->deleteJson("/api/envios/{$envio->id}")->assertStatus(200);
        $this->assertEquals(10, $this->stock($producto->id, 1)); // origen restituido
        $this->assertEquals(0,  $this->stock($producto->id, 2)); // destino revertido
    }

    public function test_envio_revertir_devolucion_sobre_anulado_no_corrompe_stock(): void
    {
        $user = $this->actingAsUser(); // sucursal 1 (origen)
        $medio = Medio::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'stock2' => 0]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => $medio->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create(['envio_id' => $envio->id, 'producto_id' => $producto->id, 'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '', 'cantidad' => 4, 'estado' => 'VALIDO']);

        $this->postJson("/api/envios/enviar/{$envio->id}");          // origen 6

        $user->sucursal_id = 2; $user->save(); $this->actingAs($user, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}");          // destino 4
        $this->postJson('/api/envios/dev-item', ['envio_id' => $envio->id, 'registro' => Enviodetalle::where('envio_id', $envio->id)->first()->id, 'cantidad' => 2]); // destino 2, origen 8
        $dev = Devenvio::where('envio_id', $envio->id)->where('estado', 'ON')->first();

        $user->sucursal_id = 1; $user->save(); $this->actingAs($user, 'sanctum');
        $this->deleteJson("/api/envios/{$envio->id}")->assertStatus(200); // anular: origen 10, destino 0
        $this->assertEquals(10, $this->stock($producto->id, 1));
        $this->assertEquals(0,  $this->stock($producto->id, 2));

        // Revertir la devolución de un envío ya anulado NO debe mover stock
        $user->sucursal_id = 2; $user->save(); $this->actingAs($user, 'sanctum');
        $this->postJson('/api/envios/delete-item-dev', ['registro' => $dev->id]);
        $this->assertEquals(10, $this->stock($producto->id, 1), 'Origen no debe cambiar');
        $this->assertEquals(0,  $this->stock($producto->id, 2), 'Destino no debe cambiar');
    }

    // ───────────────────────── AJUSTES ─────────────────────────

    public function test_ajuste_positivo_y_destroy_balancea(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        $this->postJson('/api/productos/ajuste-positivo', ['producto_id' => $producto->id, 'cantidad' => 5]);
        $this->assertEquals(15, $this->stock($producto->id));
        $ajuste = \App\Models\Ajuste::where('producto_id', $producto->id)->latest()->first();

        $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id]);
        $this->assertEquals(10, $this->stock($producto->id));
    }

    public function test_ajuste_negativo_y_destroy_balancea(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        $this->postJson('/api/productos/ajuste-negativo', ['producto_id' => $producto->id, 'cantidad' => 3]);
        $this->assertEquals(7, $this->stock($producto->id));
        $ajuste = \App\Models\Ajuste::where('producto_id', $producto->id)->latest()->first();

        $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id]);
        $this->assertEquals(10, $this->stock($producto->id));
    }
}
