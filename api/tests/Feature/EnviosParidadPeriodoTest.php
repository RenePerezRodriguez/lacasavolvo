<?php

namespace Tests\Feature;

use App\Models\Envio;
use App\Models\Medio;
use App\Models\Producto;
use App\Models\Sucursal;
use Tests\TestCase;

/**
 * Paridad con el legacy en envíos: el módulo NO bloquea por período de caja cerrada
 * (decisión René 29/6, a raíz del bug reportado por Tefy "Error al crear el envío" al
 * registrar un traslado de Tarija con fecha real 27/6 posterior al último cierre).
 *
 * El legacy (Laravel 5.4) crea/despacha/recibe envíos con CUALQUIER fecha. El rediseño
 * había agregado guardas `abort_if(fecha <= ultimo_cierre)` en todo EnvioController; se
 * removieron para recuperar la paridad. Estos tests blindan ese comportamiento.
 */
class EnviosParidadPeriodoTest extends TestCase
{
    /** Crear un envío con fecha dentro de un período YA cerrado se permite (antes daba 422). */
    public function test_store_fecha_en_periodo_cerrado_se_permite(): void
    {
        $user  = $this->actingAsUser();
        $medio = Medio::factory()->create();
        // La caja de la sucursal cerró el 27/06 → registrar un envío fechado el 27/06 NO se bloquea.
        Sucursal::where('id', $user->sucursal_id)->update(['ultimo_cierre' => '2026-06-27']);

        $resp = $this->postJson('/api/envios', [
            'fecha'     => '2026-06-27',
            'cuenta_id' => 2,
            'medio_id'  => $medio->id,
            'monto'     => 0,
            'pagado'    => 'PAGADO',
        ]);

        $resp->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('envios', [
            'id' => $resp->json('id'), 'fecha' => '2026-06-27', 'estado' => 'PROFORMA',
        ]);
    }

    /** Despachar (enviar) con la caja de HOY cerrada se permite (paridad legacy; la tranza usa hoy). */
    public function test_enviar_con_caja_cerrada_se_permite(): void
    {
        $user = $this->actingAsUser(); // sucursal 1
        Sucursal::where('id', 1)->update(['ultimo_cierre' => now()->format('Y-m-d')]);
        $producto = Producto::factory()->create(['stock1' => 10]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'estado' => 'PROFORMA']);

        $this->postJson('/api/envios/agregar-item', [
            'envio_id' => $envio->id, 'producto_id' => $producto->id, 'cantidad' => 3,
        ])->assertStatus(200);

        $resp = $this->postJson("/api/envios/enviar/{$envio->id}");

        $resp->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'ENVIADO']);
        $this->assertEquals(7, Producto::find($producto->id)->stock1);
    }

    /**
     * El medio de transporte SIGUE siendo obligatorio (columna NOT NULL): 422 de validación.
     * La causa viaja en `errors`/`message` (nunca en `error`) → el front la muestra con apiErrorMsg.
     */
    public function test_store_sin_medio_devuelve_422_validacion(): void
    {
        $user = $this->actingAsUser();
        Sucursal::where('id', $user->sucursal_id)->update(['ultimo_cierre' => '2018-01-01']);

        $resp = $this->postJson('/api/envios', [
            'fecha'     => now()->format('Y-m-d'),
            'cuenta_id' => 2,
            // medio_id ausente a propósito
            'monto'     => 0,
            'pagado'    => 'PAGADO',
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['medio_id']);
        $this->assertNull($resp->json('error'));
    }
}
