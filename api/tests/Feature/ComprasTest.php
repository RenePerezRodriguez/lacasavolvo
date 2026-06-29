<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Cuenta;
use App\Models\Producto;
use Tests\TestCase;

class ComprasTest extends TestCase
{
    public function test_list_devuelve_compras_de_la_sucursal(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->proveedor()->create();
        Compra::factory()->count(2)->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id]);

        $response = $this->getJson('/api/compras');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
    }

    public function test_store_crea_compra_y_devuelve_id(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->proveedor()->create();

        $response = $this->postJson('/api/compras', [
            'fecha'     => now()->format('Y-m-d'),
            'tipo'      => 'CONTADO',
            'cuenta_id' => $cuenta->id,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('compras', ['id' => $response->json('id'), 'estado' => 'PROFORMA']);
    }

    public function test_validar_compra_incrementa_stock(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->proveedor()->create();
        $producto = Producto::factory()->create(['stock1' => 5]);

        $compra = Compra::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA',
        ]);

        $this->postJson('/api/compras/agregar-item', [
            'compra_id'  => $compra->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 10,
        ]);

        $response = $this->postJson("/api/compras/validar/{$compra->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('compras', ['id' => $compra->id, 'estado' => 'VALIDO']);

        $stockCol = 'stock' . $user->sucursal_id;
        $this->assertEquals(15, Producto::find($producto->id)->$stockCol);
    }

    public function test_agregar_item_duplicado_en_compra_es_bloqueado(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->proveedor()->create();
        $producto = Producto::factory()->create(['stock1' => 5]);

        $compra = Compra::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA',
        ]);

        // Primer agregado del repuesto: OK.
        $this->postJson('/api/compras/agregar-item', [
            'compra_id'  => $compra->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 3,
        ])->assertStatus(200);

        // Segundo agregado del MISMO repuesto: rechazado (Compras no admite líneas duplicadas).
        $this->postJson('/api/compras/agregar-item', [
            'compra_id'  => $compra->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 2,
        ])->assertStatus(422);

        // Queda un único renglón VALIDO de ese producto en la compra.
        $this->assertEquals(1, \App\Models\Compradetalle::where('compra_id', $compra->id)
            ->where('producto_id', $producto->id)->where('estado', 'VALIDO')->count());
    }

    public function test_pagar_compra_actualiza_pagado(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $compra = Compra::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id,
            'estado' => 'VALIDO', 'tipo' => 'CREDITO', 'total' => 200, 'acuenta' => 0, 'saldo' => 200,
        ]);

        $response = $this->postJson('/api/compras/pagar', [
            'compra_id' => $compra->id,
            'monto'     => 200,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('compras', ['id' => $compra->id, 'pagado' => 'PAGADO']);
    }
}
