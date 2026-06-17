<?php

namespace Tests\Feature;

use App\Models\Ajuste;
use App\Models\Producto;
use Tests\TestCase;

class AjustesTest extends TestCase
{
    public function test_list_devuelve_ajustes_de_la_sucursal(): void
    {
        $user = $this->actingAsUser();

        $response = $this->getJson('/api/productos/ajustes');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
    }

    public function test_list_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/productos/ajustes')->assertStatus(401);
    }

    public function test_ajuste_positivo_incrementa_stock(): void
    {
        $user = $this->actingAsUser(); // sucursal_id = 1
        $producto = Producto::factory()->create(['stock1' => 5]);

        $response = $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id,
            'cantidad'    => 3,
            'descripcion' => 'Ajuste de inventario',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(8, Producto::find($producto->id)->stock1);
        $this->assertDatabaseHas('ajustes', [
            'producto_id' => $producto->id,
            'cantidad'    => 3,
            'tipo'        => 'POSITIVO',
        ]);
    }

    public function test_ajuste_negativo_decrementa_stock(): void
    {
        $user = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        $response = $this->postJson('/api/productos/ajuste-negativo', [
            'producto_id' => $producto->id,
            'cantidad'    => 4,
            'descripcion' => 'Corrección inventario',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(6, Producto::find($producto->id)->stock1);
    }

    public function test_ajuste_destroy_elimina_ajuste_y_revierte_stock(): void
    {
        $user = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        // Crear un ajuste positivo
        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id,
            'cantidad'    => 3,
            'descripcion' => 'Ajuste a eliminar',
        ]);

        $ajuste = Ajuste::where('producto_id', $producto->id)->latest()->first();
        $this->assertEquals(13, Producto::find($producto->id)->stock1);

        $response = $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id]);

        $response->assertStatus(200);
        $this->assertEquals(10, Producto::find($producto->id)->stock1);
        $this->assertDatabaseHas('ajustes', ['id' => $ajuste->id, 'estado' => 'OFF']);
    }

    public function test_ajuste_positivo_rechaza_cantidad_invalida(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 5]);

        // cantidad cero/negativa/ausente no debe alterar stock ni crear ajuste basura
        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id, 'cantidad' => 0,
        ])->assertStatus(422);

        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id, 'cantidad' => -3,
        ])->assertStatus(422);

        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id,
        ])->assertStatus(422);

        $this->assertEquals(5, Producto::find($producto->id)->stock1);
        $this->assertDatabaseMissing('ajustes', ['producto_id' => $producto->id]);
    }

    public function test_idor_no_puede_eliminar_ajuste_de_otra_sucursal(): void
    {
        $this->actingAsUser(); // sucursal 1
        $producto = Producto::factory()->create(['stock2' => 5]);

        // Crear ajuste en sucursal 2 directamente
        $ajuste = Ajuste::create([
            'sucursal_id' => 2,
            'producto_id' => $producto->id,
            'codigo'      => $producto->codigo,
            'descripcion' => 'Ajuste ajeno',
            'marca'       => '',
            'cantidad'    => 2,
            'tipo'        => 'POSITIVO',
            'estado'      => 'ON',
            'user_id'     => 1,
        ]);

        $response = $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id]);

        $response->assertStatus(403);
    }
}
