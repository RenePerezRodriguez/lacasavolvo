<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Acceso;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo USUARIOS + ROLES + PERFIL — el de MÁS alto
 * riesgo de seguridad del sistema (controla la autorización). Toda falla aquí es
 * ESCALADA DE PRIVILEGIOS.
 *
 * Casos difíciles PRIMERO: simular un rol superior, crear/editarse como ADMIN,
 * inyección de campos en el perfil, protección del super-admin y roles núcleo,
 * y auto-escalada vía edición de roles. DB `tienda_test`, factories,
 * `DatabaseTransactions` (rollback). JAMÁS la BD real; cero DDL.
 *
 * Invariante maestra: NINGÚN usuario puede ganar permisos por encima de los que
 * su rol REAL le otorga.
 */
class UsuariosRolesAuditTest extends TestCase
{
    /**
     * Crea un usuario con un rol dado, sucursal 1 y acceso ON, y lo deja actuando.
     *
     * @param string $role Nombre del rol real (no simulado).
     * @return User El usuario autenticado.
     */
    private function actorConRol(string $role): User
    {
        if (Role::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
        $user = User::factory()->create(['sucursal_id' => 1]);
        $user->assignRole($role);
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => 1, 'estado' => 'ON']);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // D1 — ESCALADA VÍA SIMULADOR (caso difícil #1)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Un GERENTE NO debe poder simular ADMIN: simular el rol con Gate::before
     * total equivale a heredar acceso irrestricto. La dirección es inequívoca
     * ("GERENTE no puede volverse ADMIN").
     */
    public function test_gerente_no_puede_simular_admin(): void
    {
        $this->actorConRol('GERENTE');
        $admin = Role::where('name', 'ADMIN')->firstOrFail();

        $resp = $this->postJson('/api/users/simulate-role', ['role_id' => $admin->id]);

        $resp->assertStatus(403);
    }

    /**
     * Defensa en profundidad: aunque el endpoint rechace, si por alguna vía
     * el GERENTE quedara con simulated_role_id = ADMIN, NO debe ganar el bypass
     * total (Gate::before sólo aplica al ADMIN real sin simulación).
     */
    public function test_gerente_simulando_admin_no_obtiene_bypass_total(): void
    {
        $gerente = $this->actorConRol('GERENTE');
        $admin = Role::where('name', 'ADMIN')->firstOrFail();

        // Forzamos el estado (simulando que el guard fallara) para verificar la
        // defensa en profundidad del Gate, NO por la vía del endpoint.
        $gerente->simulated_role_id = $admin->id;
        $gerente->save();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $gerente->refresh();

        // En tienda_test el rol ADMIN no tiene permisos explícitos (depende de
        // Gate::before). Simular ADMIN NO debe conceder un permiso de admin.
        $this->assertFalse(
            $gerente->can('roles.destroy'),
            'Simular ADMIN no debe otorgar el bypass total de Gate::before.'
        );
    }

    /**
     * Un VENDEDOR (rol sin privilegio de simular) NO puede usar el simulador
     * en absoluto: la ruta está tras role:ADMIN|GERENTE.
     */
    public function test_vendedor_no_puede_simular_ningun_rol(): void
    {
        $this->actorConRol('VENDEDOR');
        $gerente = Role::where('name', 'GERENTE')->firstOrFail();

        $this->postJson('/api/users/simulate-role', ['role_id' => $gerente->id])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // D1 — ESCALADA VÍA CREAR/EDITAR USUARIO (caso difícil #2)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Un GERENTE con users.edit/users.create NO debe poder CREAR un usuario con
     * rol ADMIN — eso fabricaría un super-usuario por encima de su jerarquía.
     */
    public function test_gerente_no_puede_crear_usuario_admin(): void
    {
        $this->actorConRol('GERENTE');

        $resp = $this->postJson('/api/users', [
            'name' => 'Escalado', 'email' => 'escalado.audit@lcv.bo',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
            'sucursal_id' => 1, 'role' => 'ADMIN',
        ]);

        $resp->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'escalado.audit@lcv.bo']);
    }

    /**
     * Un GERENTE NO debe poder EDITAR un usuario existente para volverlo ADMIN.
     */
    public function test_gerente_no_puede_promover_usuario_a_admin(): void
    {
        $this->actorConRol('GERENTE');
        $target = User::factory()->create(['sucursal_id' => 1]);
        $target->assignRole('VENDEDOR');

        $resp = $this->putJson("/api/users/{$target->id}", [
            'name' => 'Promovido', 'email' => $target->email,
            'sucursal_id' => 1, 'role' => 'ADMIN',
        ]);

        $resp->assertStatus(403);
        $this->assertFalse($target->fresh()->hasRole('ADMIN'));
    }

    /**
     * AUTO-ESCALADA: un GERENTE NO debe poder editarse a SÍ MISMO a ADMIN.
     */
    public function test_gerente_no_puede_auto_promoverse_a_admin(): void
    {
        $gerente = $this->actorConRol('GERENTE');

        $resp = $this->putJson("/api/users/{$gerente->id}", [
            'name' => $gerente->name, 'email' => $gerente->email,
            'sucursal_id' => 1, 'role' => 'ADMIN',
        ]);

        $resp->assertStatus(403);
        $this->assertFalse($gerente->fresh()->hasRole('ADMIN'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // D1 — INYECCIÓN DE CAMPOS EN updateProfile (caso difícil #3)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * updateProfile sólo debe tocar name/email/password. Inyectar role/
     * simulated_role_id/sucursal_id/id por el body NO debe escalar ni mover nada.
     */
    public function test_update_profile_no_permite_inyectar_role_ni_simulacion(): void
    {
        $user = $this->actorConRol('VENDEDOR');
        $sucursalOriginal = $user->sucursal_id;
        $admin = Role::where('name', 'ADMIN')->firstOrFail();

        $resp = $this->putJson('/api/profile', [
            'name' => 'Yo Mismo', 'email' => $user->email,
            // payload de inyección:
            'role' => 'ADMIN',
            'simulated_role_id' => $admin->id,
            'sucursal_id' => 99,
            'id' => 1,
        ]);

        $resp->assertStatus(200);
        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('VENDEDOR'), 'El rol no debe cambiar por updateProfile.');
        $this->assertFalse($fresh->hasRole('ADMIN'));
        $this->assertNull($fresh->simulated_role_id, 'No debe inyectarse simulated_role_id.');
        $this->assertEquals($sucursalOriginal, $fresh->sucursal_id, 'No debe inyectarse sucursal_id.');
        $this->assertEquals($user->id, $fresh->id, 'No debe cambiar el id.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // D1 — ESCALADA VÍA EDITAR ROLES (caso difícil #5)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Un GERENTE NO tiene permiso roles.edit/create/destroy (sólo index/show) →
     * NO debe poder agregar permisos de admin a un rol ni crear roles.
     */
    public function test_gerente_no_puede_editar_permisos_de_roles(): void
    {
        $this->actorConRol('GERENTE');
        $vendedor = Role::where('name', 'VENDEDOR')->firstOrFail();

        // Intentar darle a VENDEDOR el permiso de borrar roles (escalada).
        $this->putJson("/api/roles/{$vendedor->id}", [
            'name' => 'VENDEDOR', 'permissions' => ['roles.destroy', 'users.destroy'],
        ])->assertStatus(403);

        $this->postJson('/api/roles', [
            'name' => 'SUPERROL', 'permissions' => ['roles.destroy'],
        ])->assertStatus(403);
    }

    /**
     * Frontera de escalada vía editor de roles: aunque un rol custom TUVIERA
     * roles.edit, NO puede convertirse en ADMIN porque (a) el rol ADMIN es
     * inmutable/no-borrable (protección por nombre) y (b) el bypass total de
     * Gate::before depende de hasRole('ADMIN'), que ningún permiso granular
     * concede. Editar el rol ADMIN está bloqueado incluso para quien tenga
     * roles.edit. Esto fija que la única vía a "todo" es ser ADMIN real.
     */
    public function test_rol_con_roles_edit_no_puede_mutar_el_rol_admin(): void
    {
        // Creamos un rol custom con roles.edit y un usuario con ese rol.
        $custom = Role::create(['name' => 'SUPERVISOR_AUDIT', 'guard_name' => 'web']);
        $custom->syncPermissions(['roles.edit', 'roles.create', 'roles.destroy']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['sucursal_id' => 1]);
        $user->assignRole('SUPERVISOR_AUDIT');
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => 1, 'estado' => 'ON']);
        $this->actingAs($user, 'sanctum');

        $admin = Role::where('name', 'ADMIN')->firstOrFail();

        // Pasa el gate de ruta (tiene roles.edit) pero el controller protege ADMIN.
        $this->putJson("/api/roles/{$admin->id}", ['name' => 'TODO', 'permissions' => []])
            ->assertStatus(403);
        $this->deleteJson("/api/roles/{$admin->id}")->assertStatus(403);
        $this->assertDatabaseHas('roles', ['id' => $admin->id, 'name' => 'ADMIN']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // D1/D10 — SUPER-ADMIN (id 1) Y ROLES NÚCLEO PROTEGIDOS (caso difícil #4)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * El super-admin (user id 1) no debe poder ser SUSPENDIDO ni editado ni
     * perder accesos, ni siquiera por un ADMIN — los guards abortan en id <= 1.
     */
    public function test_no_se_puede_tocar_al_super_admin_id_1(): void
    {
        $this->actorConRol('ADMIN');

        // Garantizar un user id 1 sintético para ejercer el guard real.
        $superadmin = User::find(1);
        if (!$superadmin) {
            $superadmin = User::factory()->create(['id' => 1, 'sucursal_id' => 1]);
            $superadmin->assignRole('ADMIN');
            Acceso::create(['user_id' => 1, 'sucursal_id' => 1, 'estado' => 'ON']);
            Acceso::create(['user_id' => 1, 'sucursal_id' => 2, 'estado' => 'ON']);
        }

        // No se puede suspender (destroy).
        $this->deleteJson('/api/users/1')->assertStatus(403);
        $this->assertFalse($superadmin->fresh()->hasRole('SUSPENDIDO'));

        // No se puede editar (update).
        $this->putJson('/api/users/1', [
            'name' => 'Hacked', 'email' => 'hacked.audit@lcv.bo',
            'sucursal_id' => 1, 'role' => 'VENDEDOR',
        ])->assertStatus(403);
        $this->assertTrue($superadmin->fresh()->hasRole('ADMIN'));

        // No se pueden tocar sus accesos (acces).
        $this->getJson('/api/users/1/2/OFF/acces')->assertStatus(403);
        $this->assertDatabaseHas('accesos', ['user_id' => 1, 'sucursal_id' => 2, 'estado' => 'ON']);
    }

    /**
     * Un ADMIN no debe poder suspenderse a SÍ MISMO (destroy aborta si el id es
     * el del actor) — evita que el sistema quede sin administrador por error.
     */
    public function test_admin_no_puede_auto_suspenderse(): void
    {
        $admin = $this->actorConRol('ADMIN');

        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(403);
        $this->assertTrue($admin->fresh()->hasRole('ADMIN'));
    }

    /**
     * El rol ADMIN (id 1) NO debe poder editarse (renombrarse / cambiar permisos)
     * ni borrarse. Borrar/vaciar ADMIN = catástrofe.
     */
    public function test_no_se_puede_editar_ni_borrar_el_rol_admin(): void
    {
        $this->actorConRol('ADMIN');
        $admin = Role::where('name', 'ADMIN')->firstOrFail();

        $this->putJson("/api/roles/{$admin->id}", ['name' => 'HACKED', 'permissions' => []])
            ->assertStatus(403);
        $this->deleteJson("/api/roles/{$admin->id}")->assertStatus(403);

        $this->assertDatabaseHas('roles', ['id' => $admin->id, 'name' => 'ADMIN']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // D1 — ACCESOS A SUCURSAL (caso difícil #7)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * El guard "al menos 1 acceso activo" no debe poder burlarse para dejar 0
     * accesos: desactivar el último acceso ON devuelve 422.
     */
    public function test_no_se_puede_dejar_usuario_sin_ningun_acceso(): void
    {
        $this->actorConRol('ADMIN');
        $target = User::factory()->create(['sucursal_id' => 1]);
        $target->assignRole('VENDEDOR');
        Acceso::create(['user_id' => $target->id, 'sucursal_id' => 1, 'estado' => 'ON']);

        // Sólo tiene 1 acceso ON → desactivarlo debe fallar.
        $this->getJson("/api/users/{$target->id}/1/OFF/acces")->assertStatus(422);
        $this->assertDatabaseHas('accesos', ['user_id' => $target->id, 'sucursal_id' => 1, 'estado' => 'ON']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // D2 — FUZZ / CONTRATO (caso difícil #8)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * store con rol inexistente / email basura / password corto → 422 limpio,
     * nunca 500 ni creación.
     */
    public function test_store_entradas_basura_dan_422(): void
    {
        $this->actorConRol('ADMIN');

        // rol inexistente
        $this->postJson('/api/users', [
            'name' => 'X', 'email' => 'x.audit@lcv.bo',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
            'sucursal_id' => 1, 'role' => 'ROL_QUE_NO_EXISTE',
        ])->assertStatus(422);

        // email inválido
        $this->postJson('/api/users', [
            'name' => 'X', 'email' => 'no-es-email',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
            'sucursal_id' => 1, 'role' => 'VENDEDOR',
        ])->assertStatus(422);

        // password corto
        $this->postJson('/api/users', [
            'name' => 'X', 'email' => 'x2.audit@lcv.bo',
            'password' => 'abc', 'password_confirmation' => 'abc',
            'sucursal_id' => 1, 'role' => 'VENDEDOR',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'x.audit@lcv.bo']);
    }
}
