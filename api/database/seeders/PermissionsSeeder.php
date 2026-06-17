<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ── 91 permisos granulares (idénticos al legacy Shinobi) ─────────────
        $permisos = [
            'home.index',
            'sucursales.index', 'sucursales.show', 'sucursales.create', 'sucursales.edit', 'sucursales.destroy',
            'roles.index', 'roles.show', 'roles.create', 'roles.edit', 'roles.destroy',
            'users.index', 'users.show', 'users.edit', 'users.destroy',
            'marcas.index', 'marcas.show', 'marcas.create', 'marcas.edit', 'marcas.destroy',
            'industrias.index', 'industrias.show', 'industrias.create', 'industrias.edit', 'industrias.destroy',
            'empresas.index', 'empresas.show', 'empresas.create', 'empresas.edit', 'empresas.destroy',
            'localidades.index', 'localidades.show', 'localidades.create', 'localidades.edit', 'localidades.destroy',
            'medios.index', 'medios.show', 'medios.create', 'medios.edit', 'medios.destroy',
            'productos.index', 'productos.show', 'productos.create', 'productos.edit', 'productos.destroy',
            'productos.ajustes', 'productos.ajustepositivo', 'productos.ajustenegativo', 'productos.ajustedelete',
            'cuentas.index', 'cuentas.show', 'cuentas.create', 'cuentas.edit', 'cuentas.destroy',
            'pedidos.index', 'pedidos.show', 'pedidos.create', 'pedidos.edit', 'pedidos.destroy', 'pedidos.print',
            'compras.index', 'compras.show', 'compras.create', 'compras.edit', 'compras.destroy', 'compras.print',
            'ventas.index', 'ventas.show', 'ventas.create', 'ventas.edit', 'ventas.destroy', 'ventas.print',
            'envios.index', 'envios.show', 'envios.create', 'envios.edit', 'envios.destroy', 'envios.print',
            'caja.index', 'caja.show', 'caja.cierre', 'caja.destroy', 'caja.print',
            'perfil.index', 'perfil.edit',
            'cotizaciones.index', 'cotizaciones.show', 'cotizaciones.create', 'cotizaciones.edit', 'cotizaciones.destroy', 'cotizaciones.print',
            'estadisticas.index',
        ];

        foreach ($permisos as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        // ── Roles con permisos base ──────────────────────────────────────────
        //
        // ⚠️ IMPORTANTE — los permisos en producción/dev SON LOS DEL LEGACY y deben
        // conservarse (decisión del cliente: "los permisos deben ser como el legacy").
        // `syncPermissions()` REEMPLAZA todos los permisos del rol, así que asignamos
        // SOLO si el rol está vacío:
        //   • Instalación nueva / `tienda_test` (roles recién creados, sin permisos) → se puebla.
        //   • BD con datos legacy (roles ya con permisos) → NO se toca, se respeta el legacy.
        // Sin esta guarda, correr el seeder en prod pisaría la matriz legacy.
        $seedIfEmpty = function (Role $role, array $perms) {
            if ($role->permissions()->count() === 0) {
                $role->syncPermissions($perms);
            }
        };

        Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'SUSPENDIDO', 'guard_name' => 'web']);

        $gerente = Role::firstOrCreate(['name' => 'GERENTE', 'guard_name' => 'web']);
        $seedIfEmpty($gerente, [
            'home.index',
            'sucursales.index', 'sucursales.show', 'sucursales.create', 'sucursales.edit', 'sucursales.destroy',
            'roles.index', 'roles.show',
            'users.index', 'users.show', 'users.edit',
            'marcas.index', 'marcas.show', 'marcas.create', 'marcas.edit', 'marcas.destroy',
            'industrias.index', 'industrias.show', 'industrias.create', 'industrias.edit', 'industrias.destroy',
            'empresas.index', 'empresas.show', 'empresas.create', 'empresas.edit', 'empresas.destroy',
            'localidades.index', 'localidades.show', 'localidades.create', 'localidades.edit', 'localidades.destroy',
            'medios.index', 'medios.show', 'medios.create', 'medios.edit', 'medios.destroy',
            'productos.index', 'productos.show', 'productos.create', 'productos.edit',
            'productos.ajustes', 'productos.ajustepositivo', 'productos.ajustenegativo', 'productos.ajustedelete',
            'cuentas.index', 'cuentas.show', 'cuentas.create', 'cuentas.edit',
            'pedidos.index', 'pedidos.show', 'pedidos.create', 'pedidos.edit', 'pedidos.destroy', 'pedidos.print',
            'compras.index', 'compras.show', 'compras.create', 'compras.edit', 'compras.destroy', 'compras.print',
            'ventas.index', 'ventas.show', 'ventas.create', 'ventas.edit', 'ventas.destroy', 'ventas.print',
            'envios.index', 'envios.show', 'envios.create', 'envios.edit', 'envios.destroy', 'envios.print',
            'caja.index', 'caja.show', 'caja.cierre', 'caja.destroy', 'caja.print',
            'perfil.index', 'perfil.edit',
            'cotizaciones.index', 'cotizaciones.show', 'cotizaciones.create', 'cotizaciones.edit', 'cotizaciones.destroy', 'cotizaciones.print',
            'estadisticas.index',
        ]);

        $vendedor = Role::firstOrCreate(['name' => 'VENDEDOR', 'guard_name' => 'web']);
        // ⚠️ El legacy NO daba a VENDEDOR NINGÚN permiso de `sucursales` (ni index
        // ni create). En particular `sucursales.create` permitía a un VENDEDOR
        // llegar al endpoint de creación de sucursales — un rol de baja jerarquía
        // creando estructura organizacional de alto blast-radius (límite de 5 /
        // columnas stockN). Se elimina para restaurar la fidelidad al legacy.
        $seedIfEmpty($vendedor, [
            'home.index',
            'marcas.index', 'industrias.index', 'empresas.index', 'localidades.index', 'medios.index',
            'productos.index', 'productos.show',
            'cuentas.index', 'cuentas.show',
            'pedidos.index', 'pedidos.show', 'pedidos.create',
            'compras.index', 'compras.show',
            'ventas.index', 'ventas.show', 'ventas.create', 'ventas.edit', 'ventas.print',
            'envios.index', 'envios.show',
            'caja.index', 'caja.show', 'caja.cierre',
            'perfil.index', 'perfil.edit',
            'cotizaciones.index', 'cotizaciones.show', 'cotizaciones.create', 'cotizaciones.edit', 'cotizaciones.print',
        ]);

        $cajero = Role::firstOrCreate(['name' => 'CAJERO', 'guard_name' => 'web']);
        $seedIfEmpty($cajero, [
            'home.index',
            'productos.index', 'productos.show',
            'cuentas.index',
            'ventas.index', 'ventas.show', 'ventas.create',
            'compras.index', 'compras.show',
            'caja.index', 'caja.show', 'caja.cierre',
            'perfil.index', 'perfil.edit',
        ]);

        $operador = Role::firstOrCreate(['name' => 'OPERADOR', 'guard_name' => 'web']);
        $seedIfEmpty($operador, [
            'home.index',
            'productos.index', 'productos.show',
            'cuentas.index',
            'pedidos.index', 'pedidos.show', 'pedidos.create', 'pedidos.edit',
            'compras.index', 'compras.show', 'compras.create', 'compras.edit',
            'envios.index', 'envios.show', 'envios.create', 'envios.edit',
            'perfil.index', 'perfil.edit',
        ]);

        // Roles custom del cliente: se crean vacíos (el admin les asigna permisos manualmente)
        Role::firstOrCreate(['name' => 'VENDEDOR DENNIS', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'VTARIJA', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
