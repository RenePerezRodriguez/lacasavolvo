<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VentasTest extends TestCase
{
    public function test_list_devuelve_ventas_de_la_sucursal(): void
    {
        $user = $this->actingAsUser();
        Venta::factory()->count(3)->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);

        $response = $this->getJson('/api/ventas');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
        $this->assertGreaterThanOrEqual(3, $response->json('total'));
    }

    public function test_list_filtra_por_estado(): void
    {
        $user = $this->actingAsUser();
        Venta::factory()->count(2)->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'VALIDO']);
        Venta::factory()->count(3)->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);

        $response = $this->getJson('/api/ventas?estado_filtro=VALIDO');

        $response->assertStatus(200);
        foreach ($response->json('data') as $v) {
            $this->assertEquals('VALIDO', $v['estado']);
        }
    }

    public function test_list_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/ventas')->assertStatus(401);
    }

    public function test_store_crea_venta_y_devuelve_id(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();

        $response = $this->postJson('/api/ventas', [
            'fecha'    => now()->format('Y-m-d'),
            'tipo'     => 'CONTADO',
            'cuenta_id'=> $cuenta->id,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('ventas', ['id' => $response->json('id'), 'estado' => 'PROFORMA']);
    }

    public function test_store_sin_cuenta_id_devuelve_422(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/ventas', [
            'fecha' => now()->format('Y-m-d'),
            'tipo'  => 'CONTADO',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('cuenta_id');
    }

    public function test_agregar_item_crea_detalle_en_db(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id]);
        $producto = Producto::factory()->create();

        $response = $this->postJson('/api/ventas/agregar-item', [
            'venta_id'   => $venta->id,
            'producto_id'=> $producto->id,
            'cantidad'   => 2,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json());
        $this->assertDatabaseHas('ventadetalles', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]);
    }

    public function test_validar_venta_proforma_cambia_estado_y_descuenta_stock(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', [
            'venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 3,
        ]);

        $response = $this->postJson("/api/ventas/validar/{$venta->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'estado' => 'VALIDO']);

        $stockCol = 'stock' . $user->sucursal_id;
        $this->assertEquals(7, Producto::find($producto->id)->$stockCol);
    }

    public function test_validar_venta_ya_valida_devuelve_422(): void
    {
        $user = $this->actingAsUser();
        $venta = Venta::factory()->valido()->create(['sucursal_id' => $user->sucursal_id]);

        $response = $this->postJson("/api/ventas/validar/{$venta->id}");

        $response->assertStatus(422)->assertJsonPath('error', 'No es proforma.');
    }

    public function test_negativos_detecta_stock_insuficiente(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 2]);
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id]);

        $this->postJson('/api/ventas/agregar-item', [
            'venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 5,
        ]);

        $response = $this->postJson('/api/ventas/negativos', ['venta_id' => $venta->id]);

        $response->assertStatus(200)->assertJsonPath('negativo', true);
        $this->assertNotEmpty($response->json('items'));
    }

    public function test_cobrar_actualiza_pagado_en_db(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        // tipo CREDITO explícito: la factory lo aleatoriza y cobrar solo aplica a crédito
        $venta = Venta::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id,
            'estado' => 'VALIDO', 'tipo' => 'CREDITO', 'total' => 100, 'acuenta' => 0, 'saldo' => 100,
        ]);

        $response = $this->postJson('/api/ventas/cobrar', [
            'venta_id' => $venta->id,
            'monto'    => 100,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'pagado' => 'PAGADO']);
    }

    public function test_destroy_venta_valida_la_anula(): void
    {
        $user = $this->actingAsUser();
        $venta = Venta::factory()->valido()->create(['sucursal_id' => $user->sucursal_id]);

        $response = $this->deleteJson("/api/ventas/{$venta->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'estado' => 'ANULADO']);
    }

    public function test_destroy_venta_proforma_la_anula(): void
    {
        // Anular una PROFORMA esta permitido: la descarta sin tocar stock ni tranzas
        $user = $this->actingAsUser();
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);

        $response = $this->deleteJson("/api/ventas/{$venta->id}");

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'estado' => 'ANULADO']);
    }

    public function test_validar_sin_stock_suficiente_devuelve_422_sin_tocar_stock(): void
    {
        // Guard de stock server-side: una llamada directa a validar() no puede
        // dejar el stock negativo aunque el front no haya chequeado negativos.
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 2]);
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', [
            'venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 5,
        ])->assertStatus(200);

        $response = $this->postJson("/api/ventas/validar/{$venta->id}");

        $response->assertStatus(422)->assertJsonStructure(['error', 'items']);
        // La venta sigue PROFORMA y el stock no se movió
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'estado' => 'PROFORMA']);
        $this->assertEquals(2, Producto::find($producto->id)->stock1);
    }

    public function test_filtro_por_pagar_solo_devuelve_pendientes(): void
    {
        $user = $this->actingAsUser();
        Venta::factory()->valido()->create(['sucursal_id' => $user->sucursal_id, 'pagado' => 'POR PAGAR', 'tipo' => 'CREDITO']);
        Venta::factory()->valido()->create(['sucursal_id' => $user->sucursal_id, 'pagado' => 'PAGADO', 'tipo' => 'CONTADO']);

        $response = $this->getJson('/api/ventas?pagado_filtro=' . urlencode('POR PAGAR'));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $v) {
            $this->assertEquals('POR PAGAR', $v['pagado']);
        }
    }

    public function test_store_con_cuenta_inexistente_devuelve_422(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/ventas', [
            'fecha' => now()->format('Y-m-d'), 'tipo' => 'CONTADO', 'cuenta_id' => 999999,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('cuenta_id');
    }

    public function test_vendedor_puede_crear_pero_no_anular_venta(): void
    {
        // RBAC por ruta: VENDEDOR tiene ventas.create (puede crear) pero NO
        // ventas.destroy (no puede anular). Antes el OR de grupo lo permitía.
        $user = $this->actingAsUser('VENDEDOR');
        $cuenta = Cuenta::factory()->create();

        $this->postJson('/api/ventas', [
            'fecha' => now()->format('Y-m-d'), 'tipo' => 'CONTADO', 'cuenta_id' => $cuenta->id,
        ])->assertStatus(200);

        $venta = Venta::factory()->valido()->create(['sucursal_id' => $user->sucursal_id]);
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(403);
    }

    public function test_agregar_mismo_producto_suma_en_un_solo_renglon(): void
    {
        // Agregar dos veces el mismo producto NO crea filas duplicadas: suma la cantidad.
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);
        $producto = Producto::factory()->create(['p_norm' => 10]);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2])->assertStatus(200);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 3])->assertStatus(200);

        $this->assertEquals(1, Ventadetalle::where('venta_id', $venta->id)->where('estado', 'VALIDO')->count());
        $this->assertDatabaseHas('ventadetalles', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 5]);
    }

    public function test_devolucion_total_de_producto_agregado_dos_veces(): void
    {
        // Tras el merge, el límite de devolución es el total vendido (5), no un renglón.
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 20, 'p_norm' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 3]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 5])->assertStatus(200);
        // Una unidad más supera el total vendido
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 1])->assertStatus(422);
    }

    public function test_no_se_puede_devolver_sobre_venta_no_validada(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]);

        // PROFORMA: no se puede devolver (no descontó stock)
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 1])
            ->assertStatus(422);
        // El stock no se infló
        $this->assertEquals(10, Producto::find($producto->id)->stock1);
    }

    public function test_usuario_solo_lectura_no_puede_escribir_ni_anular_ventas(): void
    {
        $this->actingAsUser('VENDEDOR'); // garantiza roles/permisos sembrados

        $sucursal = Sucursal::find(1);
        $user = User::factory()->create(['sucursal_id' => $sucursal->id]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ventas.index', 'guard_name' => 'web']));
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => $sucursal->id, 'estado' => 'ON']);
        $this->actingAs($user, 'sanctum');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/ventas')->assertStatus(200);
        $this->postJson('/api/ventas', [
            'fecha' => now()->format('Y-m-d'), 'tipo' => 'CONTADO', 'cuenta_id' => 1,
        ])->assertStatus(403);

        $venta = Venta::factory()->valido()->create(['sucursal_id' => $sucursal->id]);
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(403);
    }
}
