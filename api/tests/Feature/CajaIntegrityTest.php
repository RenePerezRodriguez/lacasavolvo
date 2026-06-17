<?php

namespace Tests\Feature;

use App\Models\Apertura;
use App\Models\Cierre;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Invariantes de CAJA (D5/D6/D10) — el módulo de dinero que la rotación A–E no había
 * tocado a fondo. Ley central: el cierre concilia el efectivo:
 *
 *   cierre = apertura + Σ ingresos − Σ egresos     (y se arrastra a la apertura siguiente)
 *
 * más los guards de estado (no doble apertura, no cerrar sin abrir, no mover caja en un
 * periodo ya cerrado) y la reversión del cierre.
 */
class CajaIntegrityTest extends TestCase
{
    public function test_cierre_concilia_apertura_ingresos_egresos_y_arrastra_saldo(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1, ultimo_cierre fixture = 2018-01-01
        $hoy = now()->toDateString();

        // Apertura de 100.
        $this->postJson('/api/caja/apertura', ['monto' => 100])->assertStatus(200);

        // Venta CONTADO de 50 → al validar crea una tranza VEN de ingreso (fecha = hoy).
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 25, 'stock1' => 100]);
        $venta  = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'fecha' => $hoy, 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2]); // 50
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        // Egreso manual de 30.
        $this->postJson('/api/caja/egreso', ['monto' => 30, 'descripcion' => 'gasto'])->assertStatus(200);

        // KPIs reflejan saldo = 100 + 50 - 30 = 120. (JSON no distingue int/float → comparar laxo.)
        $kpis = $this->getJson('/api/caja/kpis')->assertStatus(200)->json();
        $this->assertEquals(50, $kpis['ingresos']);
        $this->assertEquals(30, $kpis['egresos']);
        $this->assertEquals(120, $kpis['saldo']);

        // Cierre: concilia y deja el registro con la aritmética exacta.
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', 1)->where('estado', 'ON')->latest('id')->first();
        $this->assertEquals(100.0, (float) $cierre->apertura);
        $this->assertEquals(50.0, (float) $cierre->ingresos);
        $this->assertEquals(30.0, (float) $cierre->egresos);
        $this->assertEquals(120.0, (float) $cierre->cierre, 'cierre = apertura + ingresos - egresos');

        // Arrastre: la apertura siguiente hereda el saldo de cierre (120).
        $siguiente = Apertura::where('sucursal_id', 1)->where('cerrado', 'NO')->where('estado', 'ON')->latest('id')->first();
        $this->assertEquals(120.0, (float) $siguiente->apertura, 'el saldo se arrastra a la apertura siguiente');
    }

    public function test_no_permite_doble_apertura_el_mismo_dia(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 100])->assertStatus(200);
        $this->postJson('/api/caja/apertura', ['monto' => 200])->assertStatus(422); // ya hay apertura hoy
        $this->assertEquals(1, Apertura::where('sucursal_id', 1)->whereDate('fecha', now())->where('estado', 'ON')->count());
    }

    public function test_cierre_sin_apertura_y_doble_cierre_se_bloquean(): void
    {
        $this->actingAsUser('ADMIN');

        // Sin apertura activa → 422.
        $this->postJson('/api/caja/cierre', [])->assertStatus(422);

        // Con apertura: primer cierre ok, segundo bloqueado (la apertura activa pasa a ser la de mañana).
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(422);
    }

    public function test_no_se_mueve_caja_en_periodo_ya_cerrado(): void
    {
        $this->actingAsUser('ADMIN');
        // Cerrar hoy → ultimo_cierre = hoy.
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);

        // Ingreso/egreso con fecha de hoy (≤ ultimo_cierre) → 422.
        $this->postJson('/api/caja/ingreso', ['monto' => 10, 'descripcion' => 'x', 'fecha' => now()->toDateString()])->assertStatus(422);
        $this->postJson('/api/caja/egreso', ['monto' => 10, 'descripcion' => 'x', 'fecha' => now()->toDateString()])->assertStatus(422);
    }

    public function test_revertir_cierre_restaura_estado(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $apertura = Apertura::where('sucursal_id', 1)->where('cerrado', 'NO')->latest('id')->first();
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', 1)->where('estado', 'ON')->latest('id')->first();

        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $cierre->id])->assertStatus(200);

        $this->assertEquals('OFF', $cierre->fresh()->estado, 'el cierre queda anulado');
        $this->assertEquals('NO', $apertura->fresh()->cerrado, 'la apertura original vuelve a estar abierta');
    }
}
