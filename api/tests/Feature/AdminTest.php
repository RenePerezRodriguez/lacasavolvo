<?php

namespace Tests\Feature;

use App\Models\Marca;
use Tests\TestCase;

class AdminTest extends TestCase
{
    // ── ADMIN puede acceder ────────────────────────────────────────────────────

    public function test_admin_puede_listar_users(): void
    {
        $this->actingAsUser('ADMIN');

        $this->getJson('/api/users')->assertStatus(200);
    }

    public function test_admin_puede_listar_sucursales(): void
    {
        $this->actingAsUser('ADMIN');

        $this->getJson('/api/sucursales')->assertStatus(200);
    }

    public function test_admin_puede_listar_marcas(): void
    {
        $this->actingAsUser('ADMIN');

        $this->getJson('/api/marcas')->assertStatus(200);
    }

    public function test_admin_puede_listar_medios(): void
    {
        $this->actingAsUser('ADMIN');

        $this->getJson('/api/medios')->assertStatus(200);
    }

    // ── No-ADMIN recibe 403 ────────────────────────────────────────────────────

    public function test_vendedor_no_puede_listar_users(): void
    {
        $this->actingAsUser('VENDEDOR');

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_vendedor_no_puede_crear_user(): void
    {
        $this->actingAsUser('VENDEDOR');

        $this->postJson('/api/users', [
            'name'                  => 'Hacker',
            'email'                 => 'hacker@lcv.bo',
            'password'              => 'secret1234',
            'password_confirmation' => 'secret1234',
            'sucursal_id'           => 1,
            'role'                  => 'VENDEDOR',
        ])->assertStatus(403);
    }

    public function test_cajero_puede_listar_sucursales(): void
    {
        // Lectura de sucursales es publica para autenticados (selector de sucursal en la UI)
        $this->actingAsUser('CAJERO');

        $this->getJson('/api/sucursales')->assertStatus(200);
    }

    public function test_operador_no_puede_crear_marca(): void
    {
        $this->actingAsUser('OPERADOR');

        $this->postJson('/api/marcas', ['nombre' => 'Volvo Fake'])->assertStatus(403);
    }

    public function test_gerente_puede_listar_roles(): void
    {
        // Lectura de roles es publica para autenticados (simulador de roles ADMIN/GERENTE)
        $this->actingAsUser('GERENTE');

        $this->getJson('/api/roles')->assertStatus(200);
    }

    // ── Perfil propio — cualquier rol puede actualizar ─────────────────────────

    public function test_vendedor_puede_actualizar_su_propio_perfil(): void
    {
        $user = $this->actingAsUser('VENDEDOR');

        $response = $this->putJson('/api/profile', [
            'name'  => 'Nombre Nuevo',
            'email' => $user->email,
        ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nombre Nuevo']);
    }

    // ── Límite estructural de sucursales (stock1..stock5) ──────────────────────

    public function test_no_permite_crear_sexta_sucursal(): void
    {
        // Las fixtures crean sucursales 1-5; el stock vive en columnas stock1..stock5,
        // así que una 6ª sucursal (id 6) rompería todas las operaciones de stock.
        $this->actingAsUser('ADMIN');

        $response = $this->postJson('/api/sucursales', ['nombre' => 'Sucursal Seis']);

        $response->assertStatus(422);
        $this->assertStringContainsString('máximo de 5 sucursales', $response->json('error'));
    }
}
