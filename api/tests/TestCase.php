<?php

namespace Tests;

use App\Models\Acceso;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // Boot the app first so DB is available, then seed fixtures BEFORE
        // the transaction starts (parent::setUp calls setUpTraits which begins
        // the transaction — data inserted here is committed permanently).
        $this->refreshApplication();
        $this->ensureFixtureSucursals();

        parent::setUp();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureFixtureSucursals(): void
    {
        DB::table('empresas')->insertOrIgnore([
            ['id' => 1, 'nombre' => 'La Casa Volvo', 'estado' => 'ON',
             'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
        ]);

        DB::table('localidads')->insertOrIgnore([
            ['id' => 1, 'nombre' => 'Cochabamba', 'estado' => 'ON',
             'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
        ]);

        DB::table('sucursals')->insertOrIgnore([
            ['id' => 1, 'nombre' => 'Central', 'alias' => 'CEN', 'nit' => '1000000001',
             'direccion' => 'Av. Central', 'telefono' => '4111111', 'email' => 'central@lcv.bo',
             'estado' => 'ON', 'ultimo_cierre' => '2018-01-01',
             'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
            ['id' => 2, 'nombre' => 'Norte', 'alias' => 'NOR', 'nit' => '1000000002',
             'direccion' => 'Av. Norte', 'telefono' => '4222222', 'email' => 'norte@lcv.bo',
             'estado' => 'ON', 'ultimo_cierre' => '2018-01-01',
             'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
            ['id' => 3, 'nombre' => 'Sur', 'alias' => 'SUR', 'nit' => '1000000003',
             'direccion' => 'Av. Sur', 'telefono' => '4333333', 'email' => 'sur@lcv.bo',
             'estado' => 'ON', 'ultimo_cierre' => '2018-01-01',
             'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
            ['id' => 4, 'nombre' => 'Este', 'alias' => 'EST', 'nit' => '1000000004',
             'direccion' => 'Av. Este', 'telefono' => '4444444', 'email' => 'este@lcv.bo',
             'estado' => 'ON', 'ultimo_cierre' => '2018-01-01',
             'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
            ['id' => 5, 'nombre' => 'Oeste', 'alias' => 'OES', 'nit' => '1000000005',
             'direccion' => 'Av. Oeste', 'telefono' => '4555555', 'email' => 'oeste@lcv.bo',
             'estado' => 'ON', 'ultimo_cierre' => '2018-01-01',
             'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
        ]);
    }

    protected function actingAsUser(string $role = 'ADMIN'): User
    {
        if (Role::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        // Always use sucursal 1 so stock1-5 columns always map correctly.
        $sucursal = Sucursal::find(1);
        $user = User::factory()->create(['sucursal_id' => $sucursal->id]);
        $user->assignRole($role);
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => $sucursal->id, 'estado' => 'ON']);

        $this->actingAs($user, 'sanctum');
        return $user;
    }
}
