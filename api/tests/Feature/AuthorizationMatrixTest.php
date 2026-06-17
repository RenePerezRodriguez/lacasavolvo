<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Sucursal;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * INVARIANTE D1 (AuthZ): TODO endpoint de escritura debe rechazar (403) a un usuario
 * autenticado SIN el permiso correspondiente. Un solo test que barre todos los módulos:
 * si mañana alguien afloja un middleware, esto lo caza.
 *
 * Usa un usuario con un rol vacío (sin permisos) + acceso a la sucursal, así el único
 * motivo de rechazo posible es la autorización (no la falta de sesión ni de sucursal).
 */
class AuthorizationMatrixTest extends TestCase
{
    /** Endpoints de escritura [método, uri] que NO usan route-model-binding (id va en el body). */
    public static function writeEndpoints(): array
    {
        return [
            // Ventas
            'ventas.store'            => ['post', '/api/ventas'],
            'ventas.update-enc'       => ['post', '/api/ventas/update-encabezado'],
            'ventas.agregar-item'     => ['post', '/api/ventas/agregar-item'],
            'ventas.update-item'      => ['post', '/api/ventas/update-item'],
            'ventas.dev-item'         => ['post', '/api/ventas/dev-item'],
            'ventas.delete-item-dev'  => ['post', '/api/ventas/delete-item-dev'],
            'ventas.cobrar'           => ['post', '/api/ventas/cobrar'],
            // Compras
            'compras.store'           => ['post', '/api/compras'],
            'compras.update-enc'      => ['post', '/api/compras/update-encabezado'],
            'compras.agregar-item'    => ['post', '/api/compras/agregar-item'],
            'compras.update-item'     => ['post', '/api/compras/update-item'],
            'compras.dev-item'        => ['post', '/api/compras/dev-item'],
            'compras.delete-item-dev' => ['post', '/api/compras/delete-item-dev'],
            'compras.pagar'           => ['post', '/api/compras/pagar'],
            // Cotizaciones
            'cotiz.store'             => ['post', '/api/cotizaciones'],
            'cotiz.update-enc'        => ['post', '/api/cotizaciones/update-encabezado'],
            'cotiz.agregar-item'      => ['post', '/api/cotizaciones/agregar-item'],
            'cotiz.update-item'       => ['post', '/api/cotizaciones/update-item'],
            // Pedidos
            'pedidos.store'           => ['post', '/api/pedidos'],
            'pedidos.update-enc'      => ['post', '/api/pedidos/update-encabezado'],
            'pedidos.agregar-item'    => ['post', '/api/pedidos/agregar-item'],
            'pedidos.update-item'     => ['post', '/api/pedidos/update-item'],
            // Envíos
            'envios.store'            => ['post', '/api/envios'],
            'envios.update-enc'       => ['post', '/api/envios/update-encabezado'],
            'envios.agregar-item'     => ['post', '/api/envios/agregar-item'],
            'envios.update-item'      => ['post', '/api/envios/update-item'],
            'envios.dev-item'         => ['post', '/api/envios/dev-item'],
            'envios.delete-item-dev'  => ['post', '/api/envios/delete-item-dev'],
            // Productos / Ajustes
            'prod.store'              => ['post', '/api/productos'],
            'prod.ajuste-pos'         => ['post', '/api/productos/ajuste-positivo'],
            'prod.ajuste-neg'         => ['post', '/api/productos/ajuste-negativo'],
            'prod.ajuste-destroy'     => ['post', '/api/productos/ajuste-destroy'],
            // Cuentas
            'cuentas.store'           => ['post', '/api/cuentas'],
            // Caja (un usuario SIN permisos no entra ni al grupo)
            'caja.apertura'           => ['post', '/api/caja/apertura'],
            'caja.cierre'             => ['post', '/api/caja/cierre'],
            'caja.ingreso'            => ['post', '/api/caja/ingreso'],
            'caja.egreso'             => ['post', '/api/caja/egreso'],
            'caja.update-tranza'      => ['post', '/api/caja/update-tranza'],
            'caja.delete-tranza'      => ['post', '/api/caja/delete-tranza'],
            'caja.revertir-cierre'    => ['post', '/api/caja/revertir-cierre'],
            // Admin
            'users.store'             => ['post', '/api/users'],
            'roles.store'             => ['post', '/api/roles'],
            'sucursales.store'        => ['post', '/api/sucursales'],
            'marcas.store'            => ['post', '/api/marcas'],
            'industrias.store'        => ['post', '/api/industrias'],
            'medios.store'            => ['post', '/api/medios'],
            'empresas.store'          => ['post', '/api/empresas'],
            'localidades.store'       => ['post', '/api/localidades'],
        ];
    }

    private function noPermUser(): User
    {
        $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
        $rol = Role::firstOrCreate(['name' => 'SINPERMISOS', 'guard_name' => 'web']);
        $rol->syncPermissions([]);
        $sucursal = Sucursal::firstOrCreate(['id' => 1], ['nombre' => 'TEST', 'estado' => 'ON']);
        $user = User::factory()->create(['sucursal_id' => $sucursal->id]);
        $user->syncRoles([$rol]);
        Acceso::firstOrCreate(['user_id' => $user->id, 'sucursal_id' => $sucursal->id], ['estado' => 'ON']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return $user;
    }

    #[DataProvider('writeEndpoints')]
    public function test_usuario_sin_permiso_recibe_403(string $method, string $uri): void
    {
        $user = $this->noPermUser();
        $this->actingAs($user, 'sanctum');

        $res = $this->json($method, $uri, []);

        $this->assertContains($res->status(), [403], "{$method} {$uri} debería devolver 403 para un usuario sin permisos (devolvió {$res->status()})");
    }

    public function test_caja_cierre_exige_permiso_cierre_fiel_al_legacy(): void
    {
        // Legacy: caja/cierre_caja → permission:caja.cierre. Un rol con SOLO caja.index
        // puede leer la caja pero NO cerrarla (el OR de grupo lo dejaba pasar; ya no).
        $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
        $rol = Role::firstOrCreate(['name' => 'CAJA_LECTURA', 'guard_name' => 'web']);
        $rol->syncPermissions([Permission::firstOrCreate(['name' => 'caja.index', 'guard_name' => 'web'])]);
        $sucursal = Sucursal::firstOrCreate(['id' => 1], ['nombre' => 'TEST', 'estado' => 'ON']);
        $user = User::factory()->create(['sucursal_id' => 1]);
        $user->syncRoles([$rol]);
        Acceso::firstOrCreate(['user_id' => $user->id, 'sucursal_id' => 1], ['estado' => 'ON']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/caja/movimientos')->assertStatus(200);          // lectura: OK
        $this->postJson('/api/caja/cierre', [])->assertStatus(403);          // cierre: requiere caja.cierre
        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => 1])->assertStatus(403);
    }
}
