<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Tranza;
use Tests\TestCase;

/**
 * Buscador de conceptos en el Historial de Caja (pedido de Tefy 23/6 — paridad legacy).
 * Los endpoints de historial de tranzas/efectivos deben aceptar ?search= y filtrar por
 * descripción/clase, recalculando los totales sobre lo filtrado. Marcador ZZQA* para que
 * sea determinista pese a las tranzas legacy de tienda_test.
 */
class CajaBuscadorTest extends TestCase
{
    private function tranza(int $cuentaId, int $userId, string $desc, string $clase, float $ing, float $egr): void
    {
        Tranza::create([
            'sucursal_id' => 1, 'cuenta_id' => $cuentaId, 'fecha' => now()->toDateString(),
            'tipo' => $ing > 0 ? 'INGRESO' : 'EGRESO', 'clase' => $clase, 'registro' => 0,
            'descripcion' => $desc, 'monto_ingreso' => $ing, 'monto_egreso' => $egr,
            'user_id' => $userId, 'estado' => 'ON',
        ]);
    }

    public function test_historial_tranzas_filtra_por_descripcion_y_recalcula_totales(): void
    {
        $u = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $hoy = now()->toDateString();
        $this->tranza($cuenta->id, $u->id, 'DEPOSITO MOVIL ZZQA', 'ENT', 100, 0);
        $this->tranza($cuenta->id, $u->id, 'PASAJE TAXI ZZQB', 'SAL', 0, 50);

        $res = $this->getJson("/api/caja/historial/tranzas?desde=$hoy&hasta=$hoy&search=" . urlencode('deposito movil zzqa'))
            ->assertStatus(200)->json();

        $descs = array_column($res['data'], 'descripcion');
        $this->assertContains('DEPOSITO MOVIL ZZQA', $descs);
        $this->assertNotContains('PASAJE TAXI ZZQB', $descs);
        // Totales recalculados SOBRE lo filtrado (no sobre todo el rango).
        $this->assertEqualsWithDelta(100, $res['total_ingresos'], 0.001);
        $this->assertEqualsWithDelta(0, $res['total_egresos'], 0.001);
    }

    public function test_historial_tranzas_busca_sin_acentos_y_con_conectores(): void
    {
        $u = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $hoy = now()->toDateString();
        $this->tranza($cuenta->id, $u->id, 'DEPÓSITO EN BANCO MERCANTIL ZZQC', 'SAL', 0, 200);

        // Sin tilde + con conector ("en") → debe encontrarlo igual.
        $descs = array_column(
            $this->getJson("/api/caja/historial/tranzas?desde=$hoy&hasta=$hoy&search=" . urlencode('deposito en banco zzqc'))
                 ->assertStatus(200)->json('data'),
            'descripcion'
        );
        $this->assertContains('DEPÓSITO EN BANCO MERCANTIL ZZQC', $descs);
    }

    public function test_historial_efectivos_acepta_search(): void
    {
        $u = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $hoy = now()->toDateString();
        // Clase de efectivo (ENT) para que entre en el whereIn de efectivos.
        $this->tranza($cuenta->id, $u->id, 'INSERCION CAJA CHICA ZZQD', 'ENT', 300, 0);

        $descs = array_column(
            $this->getJson("/api/caja/historial/efectivos?desde=$hoy&hasta=$hoy&search=" . urlencode('insercion zzqd'))
                 ->assertStatus(200)->json('data'),
            'descripcion'
        );
        $this->assertContains('INSERCION CAJA CHICA ZZQD', $descs);
    }
}
