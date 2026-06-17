<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use Tests\TestCase;

class CuentasTest extends TestCase
{
    public function test_list_devuelve_cuentas_paginadas(): void
    {
        $this->actingAsUser();
        Cuenta::factory()->cliente()->count(3)->create();

        $response = $this->getJson('/api/cuentas?skip=0');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
        $this->assertGreaterThanOrEqual(3, $response->json('total'));
    }

    public function test_list_sin_skip_devuelve_array_simple(): void
    {
        $this->actingAsUser();
        Cuenta::factory()->cliente()->count(2)->create();

        $response = $this->getJson('/api/cuentas');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_list_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/cuentas')->assertStatus(401);
    }

    public function test_kpis_devuelve_estructura(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/cuentas/kpis');

        $response->assertStatus(200)->assertJsonStructure([
            'activas', 'bloqueadas', 'clientes', 'proveedores', 'dual', 'con_saldo', 'saldo_total',
        ]);
    }

    public function test_store_crea_cliente_y_devuelve_id(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/cuentas', [
            'nombre'   => 'Transportes Andinos S.R.L.',
            'tipo'     => 'CLIENTE',
            'telefono' => '4123456',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('cuentas', [
            'id'    => $response->json('id'),
            'tipo'  => 'CLIENTE',
            'estado'=> 'ON',
        ]);
    }

    public function test_store_sin_nombre_devuelve_422(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/cuentas', ['tipo' => 'CLIENTE']);

        $response->assertStatus(422)->assertJsonValidationErrorFor('nombre');
    }

    public function test_store_tipo_invalido_devuelve_422(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Test',
            'tipo'   => 'INVALIDO',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('tipo');
    }

    public function test_update_modifica_cuenta(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create(['nombre' => 'Original', 'tipo' => 'CLIENTE']);

        $response = $this->putJson("/api/cuentas/{$cuenta->id}", [
            'nombre' => 'Nombre Actualizado',
            'tipo'   => 'CLIE-PROV',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cuentas', ['id' => $cuenta->id, 'nombre' => 'Nombre Actualizado', 'tipo' => 'CLIE-PROV']);
    }

    public function test_show_devuelve_cuenta_con_kpis(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();

        $response = $this->getJson("/api/cuentas/{$cuenta->id}");

        $response->assertStatus(200)->assertJsonPath('id', $cuenta->id);
    }
}
