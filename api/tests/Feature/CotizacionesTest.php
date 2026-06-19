<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Producto;
use Tests\TestCase;

class CotizacionesTest extends TestCase
{
    public function test_list_devuelve_cotizaciones(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();
        Cotizacion::factory()->count(2)->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id,
        ]);

        $response = $this->getJson('/api/cotizaciones');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
    }

    public function test_busqueda_por_texto_no_revienta(): void
    {
        // Regresión: el buscador hacía orWhere('tipo') sobre una columna INEXISTENTE en
        // `cotizacions` → buscar por texto (no numérico) tiraba 500 (SQL 1054). Debe dar 200
        // y matchear por nombre de cliente u observación.
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create(['nombre' => 'JUAN PEREZ']);
        Cotizacion::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'observacion' => 'kit td122',
        ]);

        $this->getJson('/api/cotizaciones?search=JUAN')->assertStatus(200);
        $this->getJson('/api/cotizaciones?search=td122')->assertStatus(200);
    }

    public function test_store_crea_cotizacion_y_devuelve_id(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();

        $response = $this->postJson('/api/cotizaciones', [
            'fecha'    => now()->format('Y-m-d'),
            'cuenta_id'=> $cuenta->id,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('cotizacions', ['id' => $response->json('id'), 'estado' => 'VALIDO']);
    }

    public function test_convertir_cotizacion_a_venta_crea_venta(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();
        $producto = Producto::factory()->create();

        $cotizacion = Cotizacion::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id,
        ]);

        $this->postJson('/api/cotizaciones/agregar-item', [
            'cotizacion_id'=> $cotizacion->id,
            'producto_id'  => $producto->id,
            'cantidad'     => 1,
        ]);

        $response = $this->postJson("/api/cotizaciones/{$cotizacion->id}/venta");

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas('ventas', ['id' => $response->json('id'), 'estado' => 'PROFORMA']);
    }
}
