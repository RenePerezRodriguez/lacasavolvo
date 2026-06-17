<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Cuenta;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo SUCURSALES (loop 20).
 *
 * Invariante DURA: el sistema soporta EXACTAMENTE 5 sucursales porque el stock
 * vive en columnas fijas `stock1..stock5` de `productos`, accedidas como
 * `'stock'.$sucursal_id`. Una sucursal con id > 5 (o id sin columna `stockN`)
 * corrompe ventas/compras/envíos/ajustes. El guard de `store` y la atomicidad
 * de sus efectos colaterales (accesos + cuenta INTERNO) son el blast-radius.
 *
 * Casos difíciles primero: el límite de 5 (robustez del guard `max('id')`),
 * la frontera de la sucursal central (id==1), la atomicidad del `store`, y el
 * overflow de columnas (validador `max:` vs ancho real `varchar(191)`).
 *
 * DB `tienda_test` (fixtures: 5 sucursales seedeadas), factories,
 * DatabaseTransactions (rollback por test). JAMÁS la BD real.
 */
class SucursalesAuditTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    //  Helper: dejar el fixture en N sucursales (ids 1..N) borrando el resto.
    //  Se borran las filas de sucursals con id > N (y sus accesos) para poder
    //  ejercitar el camino "permitido" (≤4) del guard. Devuelve el AUTO_INCREMENT
    //  intacto (no se resetea) para reproducir el escenario de IDs no contiguos.
    // ─────────────────────────────────────────────────────────────────────
    private function dejarSoloSucursales(int $n): void
    {
        DB::table('accesos')->where('sucursal_id', '>', $n)->delete();
        DB::table('sucursals')->where('id', '>', $n)->delete();
    }

    // NOTA (hardening de aislamiento): los tests del "camino permitido" del `store`
    // (crear la 5ª sucursal con id válido) requerían `ALTER TABLE ... AUTO_INCREMENT`
    // para que el INSERT recibiera un id ≤ 5. ALTER TABLE causa COMMIT IMPLÍCITO en
    // MySQL → rompe el aislamiento de `DatabaseTransactions` (la transacción del test
    // queda confirmada y TODO lo que se crea después —en este test y en los siguientes
    // del proceso— se persiste en `tienda_test` en vez de revertirse). Esos tests se
    // RETIRARON de la suite durable por eso. Lo que probaban queda cubierto así:
    //   - guard de las 5 / id>5: `test_no_crea_sucursal_con_id_mayor_a_5_aunque_haya_gap`
    //     (usa solo DELETE en transacción → revierte; NO usa ALTER) + el guard `count()>=5`.
    //   - overflow/NOT NULL en `store`: misma validación (`max:191`, defaults) que el
    //     `update`, cubierta por `test_direccion_que_excede_la_columna_da_422_no_500_en_update`.
    //   - atomicidad (accesos + cuenta INTERNO + apertura) y el id==5 exacto del camino
    //     feliz: verificados manualmente (script standalone) — no son reproducibles en la
    //     suite transaccional sin DDL. Riesgo residual documentado en AUDIT-LEDGER (loop 20).

    // ═══════════════════════════════════════════════════════════════════════
    //  1. GUARD DE LAS 5 SUCURSALES (D10/D4 — ALTO blast-radius, primero)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Camino normal: con 5 sucursales (el fixture), NINGÚN payload —ni los que
     * intentan forzar un id— debe burlar el guard. Siempre 422.
     */
    public function test_con_5_sucursales_ningun_payload_burla_el_guard(): void
    {
        $this->actingAsUser('ADMIN');

        $payloads = [
            ['nombre' => 'Intento simple'],
            ['nombre' => 'Con id explícito', 'id' => 3],          // intentar pisar un id existente
            ['nombre' => 'Con id alto', 'id' => 99],              // intentar id arbitrario
            ['nombre' => 'Estado OFF', 'estado' => 'OFF'],        // crear desactivada
            ['nombre' => 'Campos completos', 'alias' => 'XX', 'nit' => '123',
             'direccion' => 'Calle', 'telefono' => '777', 'email' => 'x@x.co', 'supervisor' => 'S'],
        ];

        foreach ($payloads as $p) {
            $resp = $this->postJson('/api/sucursales', $p);
            $resp->assertStatus(422);
            $this->assertStringContainsString('máximo de 5 sucursales', (string) $resp->json('error'));
        }

        // No se creó nada: siguen siendo 5.
        $this->assertSame(5, Sucursal::count());
    }

    /**
     * CASO DIFÍCIL (gap de IDs): el guard usa `max('id') >= 5`, que asume ids
     * contiguos 1..5. Si quedan 3 sucursales (ids 1,2,3) pero el AUTO_INCREMENT
     * está alto (porque se borraron filas), `max('id') = 3 < 5` PASA el guard,
     * pero el INSERT recibe un id >> 5 → sucursal SIN columna `stockN` →
     * inventario roto. Este test EXIGE que el guard NO permita crear una
     * sucursal cuyo id resultante sea > 5.
     */
    public function test_no_crea_sucursal_con_id_mayor_a_5_aunque_haya_gap(): void
    {
        $this->actingAsUser('ADMIN');

        // Escenario peligroso SIN ALTER (que rompería DatabaseTransactions): el
        // AUTO_INCREMENT histórico de `sucursals` en tienda_test ya está MUY por
        // encima de 5 (>6000, por filas borradas a lo largo del tiempo) y NO baja
        // al borrar filas. Dejamos solo 1,2,3 → count()=3 y max(id)=3 (< 5, el
        // guard viejo `max('id')>=5` PASARÍA), pero el próximo INSERT recibe un id
        // >> 5, sin columna stockN. Las filas borradas viven dentro de la
        // transacción de DatabaseTransactions → se revierten solas al terminar.
        $this->dejarSoloSucursales(3);
        $this->assertSame(3, Sucursal::count());
        $this->assertLessThan(5, (int) Sucursal::max('id'));
        $this->assertGreaterThan(5, $this->proximoAutoIncrement());

        $resp = $this->postJson('/api/sucursales', [
            'nombre' => 'Sucursal con id peligroso', 'alias' => 'PEL', 'nit' => '999',
            'direccion' => 'X', 'telefono' => '1', 'email' => 'p@p.co',
        ]);

        // El guard robusto debe RECHAZAR (422), no 200 ni 500: el id que asignaría
        // el INSERT (>6000) queda fuera de stock1..stock5. El guard viejo
        // (`max('id')>=5`) devolvía 200 y creaba la sucursal corrupta.
        $resp->assertStatus(422);

        // No se persistió ninguna sucursal con id > 5 (la transacción de store
        // revirtió el INSERT al detectar el id fuera de rango).
        $this->assertSame(
            0,
            Sucursal::where('id', '>', 5)->count(),
            'Quedó una sucursal con id > 5 → sin columna de stock (inventario roto).'
        );
    }

    /**
     * Lee el AUTO_INCREMENT que information_schema reporta para `sucursals`.
     * (Puede estar cacheado por conexión, pero para la precondición del test —
     * "el próximo id sería > 5" — basta: en tienda_test vale >6000.)
     */
    private function proximoAutoIncrement(): int
    {
        $row = DB::selectOne(
            'SELECT AUTO_INCREMENT AS next_id FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ?',
            ['sucursals']
        );
        return (int) ($row->next_id ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  2. RBAC (D1) — store/update/toggle/destroy exigen permiso de sucursales
    // ═══════════════════════════════════════════════════════════════════════

    public function test_rol_sin_permiso_no_escribe_sucursales(): void
    {
        // Un rol SIN sucursales.create/edit/destroy → 403 en los 4 endpoints de
        // escritura. Se revocan explícitamente del rol para que el test verifique
        // la CAPA DE ENFORCEMENT (ruta + Gate) con independencia de qué permisos
        // tenga VENDEDOR por seeder/legacy: aunque el seeder regresara a otorgar
        // sucursales.* a VENDEDOR, la frontera de escritura debe seguir negando a
        // quien no la tiene. El contrato del SEEDER (que VENDEDOR no la tenga) se
        // fija aparte en test_seeder_no_otorga_escritura_de_sucursales_a_vendedor.
        $user = $this->actingAsUser('VENDEDOR');
        $user->revokePermissionTo(
            array_intersect(
                ['sucursales.create', 'sucursales.edit', 'sucursales.destroy'],
                $user->getAllPermissions()->pluck('name')->all()
            )
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson('/api/sucursales', ['nombre' => 'X'])->assertStatus(403);
        $this->putJson('/api/sucursales/2', ['nombre' => 'Y'])->assertStatus(403);
        $this->deleteJson('/api/sucursales/2')->assertStatus(403);
        $this->getJson('/api/sucursales/2/toggle')->assertStatus(403);
    }

    /**
     * Contrato del SEEDER (D1 — el bug real hallado): el rol VENDEDOR NO debe
     * recibir NINGÚN permiso de escritura de sucursales. En el dump legacy
     * (verificado) VENDEDOR no tenía ningún permiso `sucursales.*`; el seeder
     * había agregado `sucursales.create`, dejando a un rol de baja jerarquía
     * llegar al endpoint que crea estructura organizacional de alto blast-radius
     * (límite de 5 / columnas stockN). Este test re-seedea roles/permisos en una
     * pizarra limpia (dentro de la transacción → rollback) y verifica el grant.
     */
    public function test_seeder_no_otorga_escritura_de_sucursales_a_vendedor(): void
    {
        // Pizarra limpia dentro de la transacción (DELETE, no TRUNCATE: TRUNCATE
        // causa commit implícito y rompería el aislamiento de DatabaseTransactions).
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(\Database\Seeders\PermissionsSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $vendedor = \Spatie\Permission\Models\Role::where('name', 'VENDEDOR')->firstOrFail();
        $perms = $vendedor->permissions()->pluck('name')->all();

        foreach (['sucursales.create', 'sucursales.edit', 'sucursales.destroy'] as $p) {
            $this->assertNotContains(
                $p,
                $perms,
                "El seeder otorga '$p' a VENDEDOR (rol de baja jerarquía) — el legacy no lo daba."
            );
        }
    }

    public function test_admin_si_puede_actualizar_sucursal(): void
    {
        $this->actingAsUser('ADMIN');
        $this->putJson('/api/sucursales/2', ['nombre' => 'Norte Editada'])
            ->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('sucursals', ['id' => 2, 'nombre' => 'Norte Editada']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  4. SUCURSAL CENTRAL id==1 INMUTABLE (D1/D10)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_central_no_se_desactiva_via_destroy_ni_toggle(): void
    {
        $this->actingAsUser('ADMIN');
        $this->deleteJson('/api/sucursales/1')->assertStatus(403);
        $this->getJson('/api/sucursales/1/toggle')->assertStatus(403);
        $this->assertSame('ON', Sucursal::find(1)->estado);
    }

    /**
     * CASO DIFÍCIL (esquivar el guard por otra vía): `update` acepta `estado`
     * (`nullable|in:ON,OFF`) y solo lo `unset`ea cuando id==1. Si esa condición
     * fallara, se podría DESACTIVAR la sucursal central vía `update` con
     * `estado=OFF`, esquivando el guard de `toggle`/`destroy` → sistema sin
     * sucursal central (ALTA). El sistema NO debe permitirlo.
     */
    public function test_central_no_se_desactiva_via_update_con_estado_off(): void
    {
        $this->actingAsUser('ADMIN');

        $this->putJson('/api/sucursales/1', ['nombre' => 'Central', 'estado' => 'OFF'])
            ->assertStatus(200);

        $this->assertSame(
            'ON',
            Sucursal::find(1)->estado,
            'La sucursal central (id 1) fue desactivada vía update → sistema sin central.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  5. OVERFLOW / VALIDACIÓN (D2) — validador `max:` vs ancho real varchar(191)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * CASO DIFÍCIL (overflow): la columna `sucursals.direccion` es `varchar(191)`
     * pero el validador la deja en `max:255`. Un valor de 192..255 chars PASA la
     * validación y revienta el UPDATE con 1406 → 500. Un API público debe
     * contestar 4xx limpio, nunca 500. (Se prueba por `update` porque `store`
     * está bloqueado por el guard de 5 en el fixture.)
     */
    public function test_direccion_que_excede_la_columna_da_422_no_500_en_update(): void
    {
        $this->actingAsUser('ADMIN');

        $resp = $this->putJson('/api/sucursales/2', [
            'nombre' => 'Norte',
            'direccion' => str_repeat('A', 200), // 200 > varchar(191)
        ]);

        $this->assertNotSame(500, $resp->status(), 'Overflow de direccion devolvió 500 en vez de 422.');
        $resp->assertStatus(422);
    }

    /** email con formato inválido → 422 (no se persiste basura). */
    public function test_email_invalido_da_422(): void
    {
        $this->actingAsUser('ADMIN');
        $this->putJson('/api/sucursales/2', ['nombre' => 'Norte', 'email' => 'no-es-email'])
            ->assertStatus(422);
    }

    /**
     * `ultimo_cierre` NO es editable vía store/update (no está en el `validate`).
     * Es el guard del período de caja → no debe poder manipularse desde acá.
     * Este test FIJA ese contrato.
     */
    public function test_ultimo_cierre_no_es_manipulable_via_update(): void
    {
        $this->actingAsUser('ADMIN');
        $original = Sucursal::find(2)->ultimo_cierre;

        $this->putJson('/api/sucursales/2', [
            'nombre' => 'Norte', 'ultimo_cierre' => '2099-12-31',
        ])->assertStatus(200);

        $this->assertEquals(
            $original,
            Sucursal::find(2)->fresh()->ultimo_cierre,
            'ultimo_cierre fue manipulado vía update → se puede falsear el período de caja.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  6. TOGGLE / ESTADO (D3/D10)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_toggle_es_reversible_en_doble_toggle(): void
    {
        $this->actingAsUser('ADMIN');
        $this->assertSame('ON', Sucursal::find(2)->estado);

        $this->getJson('/api/sucursales/2/toggle')->assertStatus(200);
        $this->assertSame('OFF', Sucursal::find(2)->fresh()->estado);

        $this->getJson('/api/sucursales/2/toggle')->assertStatus(200);
        $this->assertSame('ON', Sucursal::find(2)->fresh()->estado);
    }
}
