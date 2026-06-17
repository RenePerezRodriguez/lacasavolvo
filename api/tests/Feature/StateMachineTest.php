<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Medio;
use App\Models\Producto;
use Tests\TestCase;

/**
 * INVARIANTE D3 (Máquina de estados): cada transición ilegal debe bloquearse.
 * Foco en los módulos menos cubiertos: envíos y caja.
 */
class StateMachineTest extends TestCase
{
    private function envioEnviado(int $cant = 2, float $stock = 10): array
    {
        $user = $this->actingAsUser(); // sucursal 1
        $medio = Medio::factory()->create();
        $producto = Producto::factory()->create(['stock1' => $stock, 'stock2' => 0]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => $medio->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create(['envio_id' => $envio->id, 'producto_id' => $producto->id, 'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '', 'cantidad' => $cant, 'estado' => 'VALIDO']);
        return [$user, $producto, $envio];
    }

    public function test_envio_no_se_puede_enviar_dos_veces(): void
    {
        [$user, $producto, $envio] = $this->envioEnviado();
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        // Segundo enviar sobre el ya ENVIADO → 422 (no es proforma)
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(422);
        // Y el stock no se descontó dos veces
        $this->assertEquals(8, Producto::find($producto->id)->stock1);
    }

    public function test_envio_no_se_puede_recibir_sin_haber_enviado(): void
    {
        [$user, $producto, $envio] = $this->envioEnviado();
        // Recibir una PROFORMA (no ENVIADO) → 422. Actuar como destino (suc 2).
        $user->sucursal_id = 2; $user->save(); $this->actingAs($user, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(422);
        $this->assertEquals(0, Producto::find($producto->id)->stock2);
    }

    public function test_envio_no_se_puede_recibir_dos_veces(): void
    {
        [$user, $producto, $envio] = $this->envioEnviado();
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $user->sucursal_id = 2; $user->save(); $this->actingAs($user, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(422); // ya recibido
        $this->assertEquals(2, Producto::find($producto->id)->stock2);          // no se sumó dos veces
    }

    public function test_caja_no_permite_doble_apertura_el_mismo_dia(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/caja/apertura', ['monto' => 100])->assertStatus(200);
        $this->postJson('/api/caja/apertura', ['monto' => 200])->assertStatus(422);
    }

    public function test_caja_no_permite_doble_cierre(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/caja/apertura', ['monto' => 100])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        // La apertura ya fue cerrada → segundo cierre falla
        $this->postJson('/api/caja/cierre', [])->assertStatus(422);
    }
}
