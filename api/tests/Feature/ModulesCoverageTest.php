<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Industria;
use App\Models\Marca;
use App\Models\Pedido;
use App\Models\Producto;
use Tests\TestCase;

/**
 * Cobertura de módulos menos críticos (D2/D4): pedidos (no mueven stock), productos (códigos
 * únicos, conservación de precios) y cuentas (validación de tipo).
 */
class ModulesCoverageTest extends TestCase
{
    public function test_pedido_validar_no_mueve_stock(): void
    {
        // Los pedidos son órdenes internas (documento), NO mueven inventario. Solo los envíos sí.
        $user = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $pedido = Pedido::factory()->create(['sucursal_id' => 1, 'estado' => 'PROFORMA']);
        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'cantidad' => 3]);

        $this->postJson("/api/pedidos/validar/{$pedido->id}");

        $this->assertEquals(10, Producto::find($producto->id)->stock1, 'Validar pedido NO debe tocar stock');
    }

    public function test_producto_codigo_duplicado_se_permite(): void
    {
        // El catálogo heredado tiene >1000 productos con código repetido (480 con "SIN CODIGO",
        // 134 con "---", etc.) y el sistema legacy NUNCA exigió código único. Crear/editar un
        // producto con código duplicado debe ACEPTARSE: la regla `unique` rompía la edición de
        // esos productos (422 genérico reportado en QA con marcas DFG/TECNOPARTS).
        $this->actingAsUser('ADMIN');
        $marca = Marca::factory()->create();
        $industria = Industria::factory()->create();
        Producto::factory()->create(['codigo' => 'DUP-001']);

        $this->postJson('/api/productos', [
            'codigo' => 'DUP-001', 'descripcion' => 'Otro', 'marca_id' => $marca->id, 'industria_id' => $industria->id,
        ])->assertStatus(200);

        $this->assertEquals(2, Producto::where('codigo', 'DUP-001')->count(), 'El código duplicado debe permitirse (paridad con el legacy)');
    }

    public function test_producto_update_conserva_precios_si_no_se_envian(): void
    {
        $this->actingAsUser('ADMIN');
        $marca = Marca::factory()->create();
        $industria = Industria::factory()->create();
        $producto = Producto::factory()->create(['codigo' => 'KEEP-1', 'p_comp' => 50, 'p_norm' => 80, 'p_fact' => 90]);

        // Update sin enviar precios: deben conservarse (las columnas no admiten NULL).
        $this->putJson("/api/productos/{$producto->id}", [
            'codigo' => 'KEEP-1', 'descripcion' => 'Nueva desc', 'marca_id' => $marca->id, 'industria_id' => $industria->id,
        ])->assertStatus(200);

        $p = Producto::find($producto->id);
        $this->assertEquals(50, (float) $p->p_comp);
        $this->assertEquals(80, (float) $p->p_norm);
        $this->assertEquals(90, (float) $p->p_fact);
    }

    public function test_cuenta_store_rechaza_tipo_invalido(): void
    {
        $this->actingAsUser('ADMIN');

        $this->postJson('/api/cuentas', ['nombre' => 'Test', 'tipo' => 'BASURA'])->assertStatus(422);
        $this->postJson('/api/cuentas', ['nombre' => 'Test OK', 'tipo' => 'CLIENTE'])->assertStatus(200);
    }
}
