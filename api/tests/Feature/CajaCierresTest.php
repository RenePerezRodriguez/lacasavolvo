<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Cierre;
use App\Models\User;
use Tests\TestCase;

/**
 * Cubre los endpoints NUEVOS de "Lista de Cierres" + ojito (réplica del legacy):
 *   GET /api/caja/cierres                    → lista
 *   GET /api/caja/cierres/{cierre}/detalle   → resumen + movimientos
 *   GET /api/caja/cierres/{cierre}/pdf        → PDF
 *
 * Foco principal: el guard de pertenencia por sucursal (IDOR) en detalle y PDF — un
 * cierre de otra sucursal NO debe exponerse aunque el usuario sea ADMIN (los datos de
 * caja están acotados a la sucursal ACTIVA, igual que revertir-cierre).
 */
class CajaCierresTest extends TestCase
{
    /** Crea un cierre real para la sucursal 1 vía el flujo apertura→ingreso→cierre. */
    private function crearCierreSuc1(): Cierre
    {
        $this->actingAsUser('ADMIN'); // siempre sucursal 1
        $this->postJson('/api/caja/apertura', ['monto' => 100])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 50, 'descripcion' => 'venta test'])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);

        return Cierre::where('sucursal_id', 1)->where('estado', 'ON')->latest('id')->first();
    }

    /** Autentica como un usuario ADMIN de OTRA sucursal (para los casos IDOR). */
    private function actingAsOtraSucursal(int $sucursalId = 2): User
    {
        $intruso = User::factory()->create(['sucursal_id' => $sucursalId]);
        $intruso->assignRole('ADMIN');
        Acceso::create(['user_id' => $intruso->id, 'sucursal_id' => $sucursalId, 'estado' => 'ON']);
        $this->actingAs($intruso, 'sanctum');

        return $intruso;
    }

    public function test_lista_de_cierres_devuelve_columnas_legacy(): void
    {
        $this->crearCierreSuc1();

        $resp = $this->getJson('/api/caja/cierres');

        $resp->assertStatus(200)->assertJsonStructure([
            'data' => [[
                'id', 'apertura_id', 'fecha_apertura', 'fecha_cierre',
                'apertura', 'ingresos', 'egresos', 'efectivo', 'usuario', 'es_ultimo',
            ]],
        ]);
        $this->assertNotEmpty($resp->json('data'));
    }

    public function test_detalle_de_cierre_incluye_resumen_y_movimientos(): void
    {
        $cierre = $this->crearCierreSuc1();

        $resp = $this->getJson("/api/caja/cierres/{$cierre->id}/detalle");

        $resp->assertStatus(200)
            ->assertJsonStructure([
                'id', 'apertura', 'ingresos', 'egresos', 'efectivo',
                'usuario_apertura', 'usuario_cierre', 'es_ultimo', 'movimientos',
            ])
            ->assertJsonPath('es_ultimo', true);
    }

    public function test_detalle_de_cierre_de_otra_sucursal_devuelve_403(): void
    {
        $cierre = $this->crearCierreSuc1();   // sucursal 1
        $this->actingAsOtraSucursal(2);        // intruso de sucursal 2

        $this->getJson("/api/caja/cierres/{$cierre->id}/detalle")->assertStatus(403);
    }

    public function test_pdf_de_cierre_de_otra_sucursal_devuelve_403(): void
    {
        $cierre = $this->crearCierreSuc1();
        $this->actingAsOtraSucursal(2);

        $this->getJson("/api/caja/cierres/{$cierre->id}/pdf")->assertStatus(403);
    }
}
