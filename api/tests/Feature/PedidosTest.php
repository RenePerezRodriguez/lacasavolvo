<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\Pedidodetalle;
use App\Models\Producto;
use Tests\TestCase;

class PedidosTest extends TestCase
{
    public function test_list_devuelve_pedidos_de_la_sucursal(): void
    {
        $user = $this->actingAsUser();
        Pedido::factory()->count(3)->create(['sucursal_id' => $user->sucursal_id]);

        $response = $this->getJson('/api/pedidos');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
        $this->assertGreaterThanOrEqual(3, $response->json('total'));
    }

    public function test_list_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/pedidos')->assertStatus(401);
    }

    public function test_kpis_devuelve_conteos(): void
    {
        $user = $this->actingAsUser();
        Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);
        Pedido::factory()->valido()->create(['sucursal_id' => $user->sucursal_id]);

        $response = $this->getJson('/api/pedidos/kpis');

        $response->assertStatus(200)->assertJsonStructure(['total', 'proforma', 'valido', 'anulado']);
    }

    public function test_store_crea_pedido_y_devuelve_id(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/pedidos', [
            'observacion' => 'Pedido de repuestos',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('pedidos', ['id' => $response->json('id'), 'estado' => 'PROFORMA']);
    }

    public function test_agregar_item_crea_detalle_en_db(): void
    {
        $user = $this->actingAsUser();
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id]);
        $producto = Producto::factory()->create();

        $response = $this->postJson('/api/pedidos/agregar-item', [
            'pedido_id'  => $pedido->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 5,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pedidodetalles', [
            'pedido_id'  => $pedido->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 5,
            'estado'     => 'VALIDO',
        ]);
    }

    public function test_agregar_item_duplicado_devuelve_duplicado_true(): void
    {
        $user = $this->actingAsUser();
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id]);
        $producto = Producto::factory()->create();

        $this->postJson('/api/pedidos/agregar-item', [
            'pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'cantidad' => 2,
        ]);

        $response = $this->postJson('/api/pedidos/agregar-item', [
            'pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'cantidad' => 3,
        ]);

        $response->assertStatus(200)->assertJsonPath('duplicado', true);
    }

    public function test_validar_pedido_proforma_cambia_estado(): void
    {
        $user = $this->actingAsUser();
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);

        $response = $this->postJson("/api/pedidos/validar/{$pedido->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('pedidos', ['id' => $pedido->id, 'estado' => 'VALIDO']);
    }

    public function test_validar_pedido_ya_valido_devuelve_422(): void
    {
        $user = $this->actingAsUser();
        $pedido = Pedido::factory()->valido()->create(['sucursal_id' => $user->sucursal_id]);

        $response = $this->postJson("/api/pedidos/validar/{$pedido->id}");

        $response->assertStatus(422);
    }

    public function test_destroy_anula_pedido(): void
    {
        $user = $this->actingAsUser();
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id]);

        $response = $this->deleteJson("/api/pedidos/{$pedido->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('pedidos', ['id' => $pedido->id, 'estado' => 'ANULADO']);
    }

    public function test_idor_no_puede_modificar_pedido_de_otra_sucursal(): void
    {
        $user = $this->actingAsUser();
        $pedidoAjeno = Pedido::factory()->create(['sucursal_id' => 2]); // sucursal distinta

        $response = $this->postJson('/api/pedidos/agregar-item', [
            'pedido_id'  => $pedidoAjeno->id,
            'producto_id'=> Producto::factory()->create()->id,
            'cantidad'   => 1,
        ]);

        $response->assertStatus(403);
    }
}
