<?php

namespace Tests\Feature;

use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Medio;
use App\Models\Producto;
use Tests\TestCase;

class EnviosTest extends TestCase
{
    public function test_list_devuelve_envios_de_la_sucursal(): void
    {
        $user = $this->actingAsUser();
        Envio::factory()->count(3)->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => 2]);

        $response = $this->getJson('/api/envios');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
        $this->assertGreaterThanOrEqual(3, $response->json('total'));
    }

    public function test_list_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/envios')->assertStatus(401);
    }

    public function test_kpis_devuelve_estructura(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/envios/kpis');

        $response->assertStatus(200)->assertJsonStructure(['total', 'proforma', 'enviado', 'recibido']);
    }

    public function test_store_crea_envio_y_devuelve_id(): void
    {
        $user = $this->actingAsUser();
        $medio = Medio::factory()->create();

        $response = $this->postJson('/api/envios', [
            'fecha'    => now()->format('Y-m-d'),
            'cuenta_id'=> 2,
            'medio_id' => $medio->id,
            'monto'    => 0,
            'pagado'   => 'PAGADO',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('envios', ['id' => $response->json('id'), 'estado' => 'PROFORMA']);
    }

    public function test_agregar_item_crea_detalle_en_db(): void
    {
        $user = $this->actingAsUser();
        $envio = Envio::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => 2]);
        $producto = Producto::factory()->create();

        $response = $this->postJson('/api/envios/agregar-item', [
            'envio_id'   => $envio->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 4,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('enviodetalles', [
            'envio_id'   => $envio->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 4,
            'estado'     => 'VALIDO',
        ]);
    }

    public function test_enviar_descuenta_stock_de_sucursal_origen(): void
    {
        $user = $this->actingAsUser(); // sucursal_id = 1
        $producto = Producto::factory()->create(['stock1' => 10]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'estado' => 'PROFORMA']);

        $this->postJson('/api/envios/agregar-item', [
            'envio_id' => $envio->id, 'producto_id' => $producto->id, 'cantidad' => 3,
        ]);

        $response = $this->postJson("/api/envios/enviar/{$envio->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'ENVIADO']);
        $this->assertEquals(7, Producto::find($producto->id)->stock1);
    }

    public function test_recibir_incrementa_stock_de_sucursal_destino(): void
    {
        // User is in sucursal 2 (destination) to receive the envio
        $user = $this->actingAsUser();
        // Manually set user's sucursal to 2 for this test
        $user->sucursal_id = 2;
        $user->save();

        $producto = Producto::factory()->create(['stock1' => 7, 'stock2' => 0]);
        $envio = Envio::factory()->enviado()->create(['sucursal_id' => 1, 'cuenta_id' => 2]);

        Enviodetalle::create([
            'envio_id'   => $envio->id,
            'producto_id'=> $producto->id,
            'codigo'     => $producto->codigo,
            'descripcion'=> $producto->descripcion,
            'marca'      => '',
            'cantidad'   => 3,
            'estado'     => 'VALIDO',
        ]);

        $response = $this->postJson("/api/envios/recibir/{$envio->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'RECIBIDO']);
        $this->assertEquals(3, Producto::find($producto->id)->stock2);
    }

    public function test_destroy_anula_envio_proforma(): void
    {
        $user = $this->actingAsUser();
        $envio = Envio::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => 2]);

        $response = $this->deleteJson("/api/envios/{$envio->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'ANULADO']);
    }

    public function test_idor_no_puede_enviar_envio_de_otra_sucursal(): void
    {
        $this->actingAsUser(); // sucursal 1
        $envio = Envio::factory()->create(['sucursal_id' => 2, 'cuenta_id' => 3, 'estado' => 'PROFORMA']);

        $response = $this->postJson("/api/envios/enviar/{$envio->id}");

        $response->assertStatus(403);
    }

    public function test_enviar_con_stock_insuficiente_devuelve_422_y_no_descuenta(): void
    {
        $this->actingAsUser(); // sucursal_id = 1
        $producto = Producto::factory()->create(['stock1' => 2]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'estado' => 'PROFORMA']);

        $this->postJson('/api/envios/agregar-item', [
            'envio_id' => $envio->id, 'producto_id' => $producto->id, 'cantidad' => 5,
        ]);

        $response = $this->postJson("/api/envios/enviar/{$envio->id}");

        $response->assertStatus(422)->assertJsonStructure(['error', 'items']);
        // El stock no se tocó y el envío sigue en PROFORMA (no quedó negativo).
        $this->assertEquals(2, Producto::find($producto->id)->stock1);
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'PROFORMA']);
    }
}
