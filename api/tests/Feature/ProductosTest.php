<?php

namespace Tests\Feature;

use App\Models\Ajuste;
use App\Models\Industria;
use App\Models\Marca;
use App\Models\Producto;
use Tests\TestCase;

class ProductosTest extends TestCase
{
    public function test_list_devuelve_productos_con_stock_de_sucursal(): void
    {
        $user = $this->actingAsUser();
        Producto::factory()->count(3)->create();

        $response = $this->getJson('/api/productos');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
        $this->assertGreaterThanOrEqual(3, $response->json('total'));
        $response->assertJsonStructure(['data' => [['id', 'codigo', 'descripcion', 'stock', 'estado']]]);
    }

    public function test_kpis_devuelve_estructura_correcta(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/productos/kpis');

        $response->assertStatus(200)->assertJsonStructure(['activos', 'descontinuados', 'sin_stock', 'stock_critico', 'valor_inventario']);
    }

    public function test_quicksearch_devuelve_producto_por_codigo(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['codigo' => 'TEST-9999']);

        $response = $this->getJson('/api/productos/quicksearch?search=TEST-9999');

        $response->assertStatus(200)->assertJsonPath('0.codigo', 'TEST-9999');
    }

    public function test_quicksearch_sin_resultado_devuelve_null(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/productos/quicksearch?search=ZZZNOEXI99999');

        $response->assertStatus(200);
        // response()->json(null) encodes to {} (empty object) in Symfony 7 — no product returned
        $this->assertEmpty((array) json_decode($response->content()));
    }

    /**
     * El ranking de relevancia construía SQL crudo escapando solo comillas (no backslash).
     * En MySQL el `\` es escape → un término como `a\` rompía/insertaba SQL en el ORDER BY.
     * Ahora se usan bindings: cualquier carácter especial debe ser inerte (200, no 500).
     */
    public function test_busqueda_con_caracteres_especiales_no_rompe_sql(): void
    {
        $this->actingAsUser();
        Producto::factory()->create(['codigo' => 'ABC-123', 'descripcion' => 'FILTRO PRUEBA']);

        foreach (['a\\', "x' OR '1'='1", "y') UNION SELECT 1--", 'fil\\tro', "%' ; DROP TABLE productos;--"] as $payload) {
            $this->getJson('/api/productos/quicksearch?search=' . urlencode($payload))->assertStatus(200);
            $this->getJson('/api/productos?search=' . urlencode($payload))->assertStatus(200)->assertJsonStructure(['total', 'data']);
        }

        // La tabla sigue intacta tras los intentos de inyección.
        $this->assertDatabaseHas('productos', ['codigo' => 'ABC-123']);
    }

    public function test_store_crea_producto_con_datos_validos(): void
    {
        $this->actingAsUser();
        $marca = Marca::factory()->create();
        $industria = Industria::factory()->create();

        $response = $this->postJson('/api/productos', [
            'codigo'       => 'COD-TEST-001',
            'descripcion'  => 'Producto de prueba',
            'marca_id'     => $marca->id,
            'industria_id' => $industria->id,
            'p_norm'       => 100.00,
            'p_fact'       => 120.00,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('productos', ['codigo' => 'COD-TEST-001', 'estado' => 'ON']);
    }

    public function test_store_sin_codigo_devuelve_422(): void
    {
        $this->actingAsUser();
        $marca = Marca::factory()->create();
        $industria = Industria::factory()->create();

        $response = $this->postJson('/api/productos', [
            'descripcion'  => 'Sin codigo',
            'marca_id'     => $marca->id,
            'industria_id' => $industria->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('codigo');
    }

    public function test_update_modifica_producto(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create();

        $response = $this->putJson("/api/productos/{$producto->id}", [
            'codigo'       => $producto->codigo,
            'descripcion'  => 'Descripcion actualizada',
            'marca_id'     => $producto->marca_id,
            'industria_id' => $producto->industria_id,
        ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'descripcion' => 'Descripcion actualizada']);
    }

    public function test_destroy_marca_producto_como_eliminado(): void
    {
        // "Eliminar" hace soft delete a OFF (igual que legacy): el producto
        // desaparece del listado, que solo muestra estados ON/DES.
        $this->actingAsUser();
        $producto = Producto::factory()->create();

        $response = $this->deleteJson("/api/productos/{$producto->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'estado' => 'OFF']);
    }

    public function test_ajuste_positivo_incrementa_stock(): void
    {
        $user = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        $response = $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id,
            'cantidad'    => 5,
            'observacion' => 'Ingreso de prueba',
        ]);

        $response->assertStatus(200);
        $stockCol = 'stock' . $user->sucursal_id;
        $this->assertEquals(15, Producto::find($producto->id)->$stockCol);
    }

    public function test_ajuste_negativo_decrementa_stock(): void
    {
        $user = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        $response = $this->postJson('/api/productos/ajuste-negativo', [
            'producto_id' => $producto->id,
            'cantidad'    => 3,
        ]);

        $response->assertStatus(200);
        $stockCol = 'stock' . $user->sucursal_id;
        $this->assertEquals(7, Producto::find($producto->id)->$stockCol);
    }

    public function test_ajuste_destroy_revierte_ajuste(): void
    {
        $user = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        // Crear un ajuste positivo
        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id,
            'cantidad'    => 5,
        ]);

        $ajuste = Ajuste::where('producto_id', $producto->id)->where('estado', 'ON')->first();

        $response = $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id]);

        $response->assertStatus(200);
        $stockCol = 'stock' . $user->sucursal_id;
        $this->assertEquals(10, Producto::find($producto->id)->$stockCol);
    }
}
