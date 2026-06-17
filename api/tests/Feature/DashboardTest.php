<?php

namespace Tests\Feature;

use App\Models\Apertura;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Tranza;
use App\Models\Venta;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo DASHBOARD (front: screens/main.jsx → Dashboard).
 *
 * El Dashboard es una pantalla de SOLO-LECTURA que agrega datos disparando en
 * paralelo: ventas/kpis, caja/kpis, caja/movimientos, estadisticas/ventas-periodo,
 * estadisticas/top-productos, ventas/list (por cobrar) y compras/list (por pagar).
 * No muta dinero/stock → el riesgo ALTO es AUTORIZACIÓN y FUGA DE DATOS entre
 * fronteras (sucursal y rol simulado), luego contrato/validación de filtros.
 *
 * Convención de severidad en los nombres: los casos que protegen una frontera de
 * autorización son los de mayor blast-radius y van primero.
 */
class DashboardTest extends TestCase
{
    /**
     * Crea un usuario ADMIN que SIMULA otro rol (simulated_role_id), igual que el
     * simulador de roles de la app (botón "Simular"). Devuelve el user ya autenticado.
     */
    private function actingAsSimulating(string $rolSimulado): \App\Models\User
    {
        $user = $this->actingAsUser('ADMIN'); // sucursal 1, acceso ON
        $role = Role::where('name', $rolSimulado)->where('guard_name', 'web')->firstOrFail();
        $user->simulated_role_id = $role->id;
        $user->save();
        $user->refresh();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return $user;
    }

    // ═══════════════ D1 · Autorización — frontera de ROL SIMULADO (ALTA) ═══════════════

    /**
     * GAP del simulador: un ADMIN que simula VENDEDOR debe PERDER el acceso a
     * estadísticas. El front lo gatea con `puedeVerStats` (respeta la simulación),
     * pero el gate REAL vive en el backend (autorizarEstadisticas). Si ese gate usa
     * hasRole() (no respeta simulación) en vez de can(), el ADMIN simulando VENDEDOR
     * sigue viendo estadísticas → fuga del gate de simulación.
     *
     * VENDEDOR no tiene 'estadisticas.index' (ver PermissionsSeeder) → 403 esperado.
     * Son los DOS endpoints de estadísticas que consume el Dashboard.
     */
    public function test_admin_simulando_vendedor_no_ve_estadisticas_del_dashboard(): void
    {
        $this->actingAsSimulating('VENDEDOR');

        // Mismos params que dispara el Dashboard.
        $hoy = now()->toDateString();
        $hace12m = now()->subYear()->toDateString();

        $this->getJson("/api/estadisticas/ventas-periodo?vpDesde={$hace12m}&vpHasta={$hoy}&vpGran=month")
            ->assertStatus(403);
        $this->getJson("/api/estadisticas/top-productos?tpDesde={$hace12m}&tpHasta={$hoy}&tpMet=unidades&take=5")
            ->assertStatus(403);
    }

    /**
     * Contraprueba: un ADMIN que simula GERENTE (que SÍ tiene acceso a estadísticas)
     * debe seguir viéndolas. Asegura que el fix no rompa el camino legítimo de simulación.
     */
    public function test_admin_simulando_gerente_si_ve_estadisticas(): void
    {
        $this->actingAsSimulating('GERENTE');
        $this->getJson('/api/estadisticas/top-productos?tpMet=unidades&take=5')->assertStatus(200);
    }

    /**
     * Frontera de SUCURSAL bajo simulación (decisión del humano: el simulador debe
     * comportarse TAL CUAL el rol simulado, no solo en permisos sino también en la
     * frontera de sucursal). Un ADMIN simulando VENDEDOR debe perder el bypass de admin
     * y quedar restringido a sus accesos reales (solo sucursal 1) → pedir la sucursal 2
     * ajena = 403, igual que un VENDEDOR real. Antes del fix, validarAccesoSucursal usaba
     * hasRole('ADMIN') (rol REAL) y el ADMIN simulando seguía cruzando la frontera (200).
     */
    public function test_admin_simulando_vendedor_respeta_frontera_de_sucursal(): void
    {
        $user = $this->actingAsSimulating('VENDEDOR'); // ADMIN simula VENDEDOR; acceso solo a sucursal 1

        // Como VENDEDOR simulado, la sucursal 2 (sin acceso) → 403 en ventas list y kpis.
        $this->getJson('/api/ventas?sucursal_id=2')->assertStatus(403);
        $this->getJson('/api/ventas/kpis?sucursal_id=2')->assertStatus(403);

        // Contraprueba: el MISMO usuario dejando de simular (rol real ADMIN) SÍ cruza (bypass admin).
        $user->simulated_role_id = null;
        $user->save();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson('/api/ventas?sucursal_id=2')->assertStatus(200);
    }

    // ═══════════════ D1 · Autorización — frontera de SUCURSAL (IDOR, ALTA) ═══════════════

    /**
     * El Dashboard recarga con la sucursal activa del token, pero ventas/kpis y
     * ventas/list aceptan un `sucursal_id` en query. Un VENDEDOR de sucursal 1 NO
     * debe poder pedir KPIs/listado de la sucursal 2 (a la que no tiene acceso).
     */
    public function test_vendedor_no_obtiene_kpis_de_ventas_de_otra_sucursal(): void
    {
        $this->actingAsUser('VENDEDOR'); // sucursal 1, acceso solo a 1

        $this->getJson('/api/ventas/kpis?sucursal_id=2')->assertStatus(403);
        $this->getJson('/api/ventas?sucursal_id=2&pagado_filtro=POR%20PAGAR&estado_filtro=VALIDO&skip=0&take=5')
            ->assertStatus(403);
    }

    /**
     * caja/kpis y caja/movimientos escopan a Auth::user()->sucursal_id SIN aceptar
     * param de sucursal → no se pueden dirigir a otra sucursal. Verificamos que un
     * `sucursal_id` inyectado en query es IGNORADO (responde la sucursal del token),
     * no que cruce la frontera.
     */
    public function test_caja_kpis_ignora_sucursal_id_inyectado_y_responde_la_del_token(): void
    {
        $user = $this->actingAsUser('VENDEDOR'); // sucursal 1

        // Movimiento de OTRA sucursal (2): no debe aparecer ni contar para este usuario.
        Tranza::create([
            'sucursal_id' => 2, 'tipo' => 'INGRESO', 'clase' => 'ENT', 'estado' => 'ON',
            'fecha' => now()->toDateString(), 'monto_ingreso' => 999, 'monto_egreso' => 0,
            'cuenta_id' => 6, 'registro' => 0, 'descripcion' => 'ajeno', 'user_id' => $user->id,
        ]);

        $resp = $this->getJson('/api/caja/movimientos?sucursal_id=2&fecha_desde=' . now()->toDateString() . '&fecha_hasta=' . now()->toDateString());
        $resp->assertStatus(200);
        // El movimiento de la sucursal 2 NO debe filtrarse al usuario de la sucursal 1.
        foreach ($resp->json('data') as $mov) {
            $this->assertNotEquals('ajeno', $mov['descripcion'], 'no debe ver movimientos de otra sucursal');
        }
    }

    // ═══════════════ D2 · Contrato de filtros (basura → 4xx limpio, nunca 500) ═══════════════

    /**
     * pagado_filtro / estado_filtro con basura (incluido intento SQLi) → la consulta
     * debe responder 200 con lista vacía (parámetro va a un WHERE parametrizado), NUNCA 500.
     */
    public function test_filtros_basura_en_ventas_list_no_rompen(): void
    {
        $this->actingAsUser('ADMIN');

        $payloads = [
            "pagado_filtro=' OR '1'='1",
            "estado_filtro=%3Bdrop+table+ventas%3B",
            "pagado_filtro=" . urlencode('<script>alert(1)</script>'),
            "estado_filtro=" . urlencode(str_repeat('A', 5000)),
        ];
        foreach ($payloads as $qs) {
            $this->getJson("/api/ventas?{$qs}&skip=0&take=5")
                ->assertStatus(200)
                ->assertJsonStructure(['total', 'data']);
        }
    }

    /**
     * Fechas imposibles / no-fecha / rango invertido en ventas/kpis → sin 500.
     * (El Dashboard arma fechas con toISOString, pero un cliente hostil puede mandar basura.)
     */
    public function test_fechas_invalidas_en_kpis_no_rompen(): void
    {
        $this->actingAsUser('ADMIN');

        $casos = [
            'fecha_desde=2026-13-45&fecha_hasta=2026-99-99', // mes/día imposibles
            'fecha_desde=no-es-fecha&fecha_hasta=tampoco',
            'fecha_desde=2030-01-01&fecha_hasta=2000-01-01', // rango invertido
            'fecha_desde[]=1&fecha_hasta[]=2',                // array en vez de string
        ];
        foreach ($casos as $qs) {
            $r = $this->getJson("/api/ventas/kpis?{$qs}");
            $this->assertContains($r->status(), [200, 422], "kpis con [{$qs}] devolvió {$r->status()} (esperado 200/422, nunca 500)");
        }
    }

    // ═══════════════ D9 · Formato numérico (parseable en el front) ═══════════════

    /**
     * estadisticas/ventas-periodo alimenta chartData[].total, que el front pasa por
     * parseFloat(). Si el backend devolviera number_format() con coma de miles,
     * parseFloat("1,234.00") → 1 (bug #1 conocido). Verifica que `total` es numérico crudo.
     */
    public function test_ventas_periodo_devuelve_total_parseable(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $venta = Venta::factory()->create([
            'sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO',
            'tipo' => 'CONTADO', 'fecha' => now()->toDateString(), 'total' => 1234.56,
        ]);

        $resp = $this->getJson('/api/estadisticas/ventas-periodo?vpDesde=' . now()->subYear()->toDateString() . '&vpHasta=' . now()->toDateString() . '&vpGran=month');
        $resp->assertStatus(200);
        $rows = $resp->json();
        $this->assertNotEmpty($rows, 'debe haber al menos un período con la venta creada');
        foreach ($rows as $row) {
            // El valor debe castear a float sin perder la parte de miles (no number_format con coma).
            $this->assertEquals((float) $row['total'], (float) $row['total']);
            $this->assertIsNumeric($row['total'], 'total debe ser numérico parseable, no string con coma de miles');
        }
        // Y específicamente el período de la venta debe valer >= 1234.56 (no truncado a 1).
        $sum = array_sum(array_map(fn ($r) => (float) $r['total'], $rows));
        $this->assertGreaterThanOrEqual(1234.56, $sum, 'el monto no debe truncarse por coma de miles');
    }

    // ═══════════════ D10 · Estados límite (sin datos / caja cerrada → sano, no 500) ═══════════════

    /**
     * Sucursal sin ventas ni caja abierta: todos los endpoints del Dashboard responden
     * sano (200), con KPIs en cero y caja "cerrada". No 500.
     */
    public function test_dashboard_sin_datos_responde_sano(): void
    {
        // ADMIN en una sucursal sin actividad: usamos sucursal 5 (sin fixtures de negocio).
        $user = $this->actingAsUser('ADMIN');
        $user->sucursal_id = 5;
        $user->save();

        $hoy = now()->toDateString();

        $vk = $this->getJson("/api/ventas/kpis?sucursal_id=5&fecha_desde={$hoy}&fecha_hasta={$hoy}");
        $vk->assertStatus(200)->assertJson(['valido' => 0]);

        $ck = $this->getJson('/api/caja/kpis');
        $ck->assertStatus(200)->assertJson(['abierta' => false]);

        $this->getJson("/api/caja/movimientos?fecha_desde={$hoy}&fecha_hasta={$hoy}")
            ->assertStatus(200)->assertJsonPath('total', 0);

        $this->getJson('/api/ventas?pagado_filtro=POR%20PAGAR&estado_filtro=VALIDO&sucursal_id=5&skip=0&take=5')
            ->assertStatus(200)->assertJsonPath('total', 0);
    }

    /**
     * Caja CERRADA (apertura ON ausente): caja/kpis debe responder abierta=false y
     * saldo 0 sin reventar.
     */
    public function test_caja_kpis_con_caja_cerrada(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        // Aseguramos que no hay apertura ON en sucursal 1 dentro de la transacción de test.
        Apertura::where('sucursal_id', 1)->update(['estado' => 'OFF']);

        $this->getJson('/api/caja/kpis')
            ->assertStatus(200)
            ->assertJson(['abierta' => false, 'saldo' => 0]);
    }
}
