<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_con_credenciales_validas_devuelve_token(): void
    {
        $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
        $sucursal = Sucursal::factory()->create();
        $user = User::factory()->create([
            'sucursal_id' => $sucursal->id,
            'email'    => 'admin@lcv.bo',
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole('ADMIN');

        $response = $this->postJson('/api/login', [
            'email'    => 'admin@lcv.bo',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'sucursal']]);
    }

    public function test_login_con_contrasena_incorrecta_devuelve_422(): void
    {
        $user = User::factory()->create(['email' => 'test@lcv.bo', 'password' => Hash::make('correcto')]);

        $response = $this->postJson('/api/login', [
            'email'    => 'test@lcv.bo',
            'password' => 'incorrecto',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.email.0', 'Las credenciales no son correctas.');
    }

    public function test_login_usuario_suspendido_devuelve_422(): void
    {
        $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
        $user = User::factory()->create(['email' => 'susp@lcv.bo', 'password' => Hash::make('password')]);
        $user->assignRole('SUSPENDIDO');

        $response = $this->postJson('/api/login', [
            'email'    => 'susp@lcv.bo',
            'password' => 'password',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.email.0', 'Tu cuenta está suspendida.');
    }

    public function test_get_user_autenticado_devuelve_payload_completo(): void
    {
        $user = $this->actingAsUser('ADMIN');

        $response = $this->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('id', $user->id)
            ->assertJsonStructure(['id', 'name', 'email', 'role', 'roles', 'permissions', 'sucursal', 'accesos']);
    }

    public function test_get_user_sin_token_devuelve_401(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_api_sin_token_y_sin_accept_json_devuelve_401_no_500(): void
    {
        // Request no-JSON (Accept: text/html) a una ruta protegida. Sin forzar JSON en
        // api/*, Laravel intentaba redirect()->route('login') —inexistente— y daba 500.
        $response = $this->get('/api/user', ['Accept' => 'text/html']);

        $response->assertStatus(401);
    }

    public function test_switch_sucursal_con_acceso_activo(): void
    {
        $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
        $s1 = Sucursal::factory()->create();
        $s2 = Sucursal::factory()->create();
        $user = User::factory()->create(['sucursal_id' => $s1->id]);
        $user->assignRole('VENDEDOR');
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => $s1->id, 'estado' => 'ON']);
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => $s2->id, 'estado' => 'ON']);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/switch-sucursal', ['sucursal_id' => $s2->id]);

        $response->assertStatus(200)->assertJsonPath('sucursal_id', $s2->id);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'sucursal_id' => $s2->id]);
    }

    public function test_switch_sucursal_sin_acceso_devuelve_403(): void
    {
        $s1 = Sucursal::factory()->create();
        $s2 = Sucursal::factory()->create();
        $user = User::factory()->create(['sucursal_id' => $s1->id]);
        // Sin acceso a s2
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/switch-sucursal', ['sucursal_id' => $s2->id]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'sucursal_id' => $s1->id]);
    }

    public function test_logout_revoca_token(): void
    {
        $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
        $user = User::factory()->create(['sucursal_id' => 1, 'password' => Hash::make('password')]);
        $user->assignRole('ADMIN');
        $newToken = $user->createToken('spa');
        $tokenId  = $newToken->accessToken->id;
        $plain    = $newToken->plainTextToken;

        $this->withToken($plain)->postJson('/api/logout')
            ->assertStatus(200)->assertJsonPath('ok', true);

        // Token must be deleted from DB — avoid Sanctum's in-process user cache
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}
