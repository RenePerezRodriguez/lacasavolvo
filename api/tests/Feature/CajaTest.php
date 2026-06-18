<?php

namespace Tests\Feature;

use App\Models\Apertura;
use App\Models\Tranza;
use Tests\TestCase;

class CajaTest extends TestCase
{
    public function test_kpis_devuelve_estructura_correcta(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/caja/kpis');

        $response->assertStatus(200)->assertJsonStructure(['ingresos', 'egresos', 'saldo', 'abierta', 'apertura_id']);
    }

    public function test_apertura_crea_registro_en_db(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/caja/apertura', ['monto' => 200.00]);

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('aperturas', [
            'sucursal_id' => $user->sucursal_id,
            'cerrado'     => 'NO',
            'estado'      => 'ON',
        ]);
    }

    public function test_ingreso_crea_tranza_en_db(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/caja/ingreso', [
            'monto'       => 150.00,
            'descripcion' => 'Ingreso de prueba',
        ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('tranzas', [
            'sucursal_id'    => $user->sucursal_id,
            'tipo'           => 'INGRESO',
            'monto_ingreso'  => 150.00,
        ]);
    }

    public function test_egreso_crea_tranza_en_db(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/caja/egreso', [
            'monto'       => 75.00,
            'descripcion' => 'Egreso de prueba',
        ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('tranzas', [
            'sucursal_id'   => $user->sucursal_id,
            'tipo'          => 'EGRESO',
            'monto_egreso'  => 75.00,
        ]);
    }

    public function test_movimientos_devuelve_tranzas(): void
    {
        $user = $this->actingAsUser();

        $response = $this->getJson('/api/caja/movimientos');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
    }

    public function test_cierre_cierra_apertura_activa(): void
    {
        $user = $this->actingAsUser();

        // Primero abrir
        $this->postJson('/api/caja/apertura', ['monto' => 100]);
        $apertura = Apertura::where('sucursal_id', $user->sucursal_id)->where('cerrado', 'NO')->first();

        $response = $this->postJson('/api/caja/cierre', []);

        $response->assertStatus(200)->assertJsonPath('ok', true);
        if ($apertura) {
            $this->assertDatabaseHas('aperturas', ['id' => $apertura->id, 'cerrado' => 'SI']);
        }
    }

    public function test_caja_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/caja/kpis')->assertStatus(401);
    }

    /**
     * Regresión del DOBLE CONTEO del saldo (reportado en Tarija: el saldo salía el doble).
     * El cierre crea la apertura siguiente (mañana) con `apertura = saldo de cierre`, que ya
     * incluye el neto del día. El KPI de saldo NO debe volver a sumar las tranzas de hoy:
     * el saldo después de cerrar debe ser IGUAL al de antes de cerrar (con el bug daba +neto).
     */
    public function test_saldo_no_duplica_movimientos_del_dia_tras_cerrar_caja(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/caja/apertura', ['monto' => 100]);
        $this->postJson('/api/caja/ingreso', ['monto' => 150, 'descripcion' => 'venta de prueba']);
        $this->postJson('/api/caja/egreso',  ['monto' => 40,  'descripcion' => 'gasto de prueba']);

        $saldoAntes = (float) $this->getJson('/api/caja/kpis')->json('saldo');

        // Cerrar caja: crea la apertura de mañana con apertura = saldo de cierre.
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);

        $saldoDespues = (float) $this->getJson('/api/caja/kpis')->json('saldo');

        $this->assertEqualsWithDelta(
            $saldoAntes, $saldoDespues, 0.001,
            'El saldo no debe duplicar los movimientos del día tras cerrar caja (bug de doble conteo).'
        );
    }
}
