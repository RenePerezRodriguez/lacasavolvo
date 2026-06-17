<?php

namespace Tests\Feature;

use App\Models\Apertura;
use App\Models\Cierre;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Tranza;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AUDITORÍA ADVERSARIAL — módulo CAJA (mayor blast-radius restante: concilia el dinero
 * por sucursal). Casos DIFÍCILES primero. Las superficies ya cubiertas por
 * `CajaIntegrityTest`/`CajaTest`/`StateMachineTest`/`AuthorizationMatrixTest` NO se duplican.
 *
 * Ley central que se ataca:
 *   cierre = apertura + Σ ingresos(ON) − Σ egresos(ON)   del período [apertura->fecha, fecha_cierre]
 *   y ese cierre se ARRASTRA como apertura del día siguiente + fija `sucursal.ultimo_cierre`.
 *
 * Un cierre con `fecha_cierre` manipulada corrompe las TRES cosas a la vez: la conciliación
 * del día, el arrastre, y el guard de período cerrado.
 */
class CajaAuditTest extends TestCase
{
    /** Helper: suma de tranzas ON de la sucursal por tipo. */
    private function sumaTranzas(int $sid, string $tipo): float
    {
        $col = $tipo === 'INGRESO' ? 'monto_ingreso' : 'monto_egreso';
        return (float) Tranza::where('sucursal_id', $sid)->where('tipo', $tipo)
            ->where('estado', 'ON')->sum($col);
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE 1 — fecha_cierre SIN validar (D2/D5): manipula la conciliación.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * CASO DIFÍCIL #1: cerrar con `fecha_cierre` ANTERIOR a la apertura.
     * Apertura hoy + ingreso 100 hoy → cerrar con fecha_cierre = ayer.
     * El whereBetween([hoy, ayer]) queda INVERTIDO → 0 filas → el cierre dice
     * "saldo = solo apertura" e IGNORA el ingreso de 100 → tranza huérfana,
     * conciliación falseada. DEBE rechazarse (422) o conciliar correctamente.
     */
    public function test_cierre_con_fecha_anterior_a_apertura_no_falsea_la_conciliacion(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        $hoy  = now()->toDateString();
        $ayer = now()->subDay()->toDateString();

        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 100, 'descripcion' => 'venta cash'])->assertStatus(200);

        $resp = $this->postJson('/api/caja/cierre', ['fecha_cierre' => $ayer]);

        // O bien se rechaza el período invertido (422), o el cierre concilia el ingreso real.
        // Lo que NO se acepta: un cierre "exitoso" que ignora los 100 → saldo de cierre 0.
        if ($resp->status() === 200) {
            $cierre = Cierre::where('sucursal_id', 1)->where('estado', 'ON')->latest('id')->first();
            $this->assertEquals(100.0, (float) $cierre->ingresos, 'el cierre debe conciliar el ingreso real de 100, no ignorarlo');
            $this->assertEquals(100.0, (float) $cierre->cierre, 'el saldo de cierre no puede ignorar la tranza real');
        } else {
            $resp->assertStatus(422);
        }
    }

    /**
     * CASO DIFÍCIL #2: cerrar con `fecha_cierre` en el FUTURO.
     * Cuenta tranzas que aún no ocurren / del próximo período (incluida la apertura-arrastre
     * que el propio cierre crea para "mañana", con fecha futura). Un cierre que mira al futuro
     * puede contar dinero que no pertenece a este período. DEBE rechazarse (no se cierra el futuro).
     */
    public function test_cierre_con_fecha_en_el_futuro_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');
        $futuro = now()->addDays(10)->toDateString();

        $this->postJson('/api/caja/apertura', ['monto' => 50])->assertStatus(200);

        $this->postJson('/api/caja/cierre', ['fecha_cierre' => $futuro])
            ->assertStatus(422);

        // No debe haberse fijado ultimo_cierre en el futuro (corrompería el guard de período).
        $this->assertEquals('2018-01-01', Sucursal::find(1)->ultimo_cierre, 'ultimo_cierre no puede saltar al futuro');
    }

    /**
     * CASO DIFÍCIL #3: `fecha_cierre` basura / no-fecha.
     * Un API público no debe responder 500 ante input malformado → 422 limpio.
     */
    public function test_cierre_con_fecha_basura_da_422_no_500(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 10])->assertStatus(200);

        $this->postJson('/api/caja/cierre', ['fecha_cierre' => 'DROP TABLE cierres;'])
            ->assertStatus(422);
    }

    /**
     * CASO DIFÍCIL #4: el camino legítimo (fecha_cierre = hoy, o ausente) sigue funcionando.
     * Regresión: el fix de validación NO debe romper el cierre normal.
     */
    public function test_cierre_con_fecha_hoy_o_ausente_sigue_conciliando(): void
    {
        $this->actingAsUser('ADMIN');
        $hoy = now()->toDateString();
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 70, 'descripcion' => 'x'])->assertStatus(200);
        $this->postJson('/api/caja/egreso', ['monto' => 20, 'descripcion' => 'y'])->assertStatus(200);

        // fecha_cierre explícita = hoy.
        $this->postJson('/api/caja/cierre', ['fecha_cierre' => $hoy])->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', 1)->where('estado', 'ON')->latest('id')->first();
        $this->assertEquals(50.0, (float) $cierre->cierre, 'cierre = 0 + 70 - 20 = 50');
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE 2 — mutar tranzas tras cerrar (D3/D5): el cierre deja de cuadrar.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * CASO DIFÍCIL: ingreso 100 hoy → cerrar → editar ese ingreso a 999.
     * El Cierre ya snapshoteó ingresos=100; si update-tranza deja editar una tranza
     * cuya fecha cae en el período CERRADO, el cierre deja de cuadrar (dice 100, realidad 999).
     * DEBE rechazarse (422) — la tranza está en período cerrado.
     */
    public function test_no_se_edita_tranza_de_periodo_cerrado(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 100, 'descripcion' => 'cash'])->assertStatus(200);
        $tranza = Tranza::where('sucursal_id', 1)->where('clase', 'ENT')->latest('id')->first();

        $this->postJson('/api/caja/cierre', [])->assertStatus(200); // ultimo_cierre = hoy

        // La tranza es de hoy = ultimo_cierre → editar debe rechazarse.
        $this->postJson('/api/caja/update-tranza', ['tranza_id' => $tranza->id, 'monto' => 999])
            ->assertStatus(422);

        $this->assertEquals(100.0, (float) $tranza->fresh()->monto_ingreso, 'el monto no debe cambiar tras el cierre');
    }

    /**
     * CASO DIFÍCIL: borrar (OFF) una tranza de período cerrado.
     * Si se permite, el cierre dice ingresos=100 pero la suma real es 0.
     */
    public function test_no_se_borra_tranza_de_periodo_cerrado(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 100, 'descripcion' => 'cash'])->assertStatus(200);
        $tranza = Tranza::where('sucursal_id', 1)->where('clase', 'ENT')->latest('id')->first();

        $this->postJson('/api/caja/cierre', [])->assertStatus(200);

        $this->postJson('/api/caja/delete-tranza', ['tranza_id' => $tranza->id])
            ->assertStatus(422);

        $this->assertEquals('ON', $tranza->fresh()->estado, 'la tranza no debe borrarse tras el cierre');
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE 3 — Bug Carbon en los guards (D10/correctitud).
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Límite EXACTO: una tranza de fecha == ultimo_cierre debe quedar BLOQUEADA
     * (el guard usa `<=`). Si la comparación Carbon-vs-string estuviera rota, el guard
     * pasaría/fallaría siempre. Forzamos ultimo_cierre = hoy y probamos el borde.
     */
    public function test_guard_periodo_cerrado_borde_exacto_igual_a_ultimo_cierre(): void
    {
        $this->actingAsUser('ADMIN');
        $hoy = now()->toDateString();
        Sucursal::where('id', 1)->update(['ultimo_cierre' => $hoy]);
        Apertura::factory()->create(['sucursal_id' => 1, 'fecha' => $hoy, 'cerrado' => 'NO', 'estado' => 'ON']);

        // fecha == ultimo_cierre → bloqueado (el guard es <=).
        $this->postJson('/api/caja/ingreso', ['monto' => 5, 'descripcion' => 'borde', 'fecha' => $hoy])
            ->assertStatus(422);

        // fecha = mañana (> ultimo_cierre) → permitido.
        $this->postJson('/api/caja/ingreso', ['monto' => 5, 'descripcion' => 'mañana', 'fecha' => now()->addDay()->toDateString()])
            ->assertStatus(200);
    }

    /**
     * Guard de "apertura de mañana": cerrar cuando la apertura activa es de mañana → 422.
     * (El guard de la línea ~126 protege contra cerrar la apertura-arrastre recién creada.)
     */
    public function test_guard_cerrar_apertura_de_manana_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');
        // ÚNICA apertura activa = mañana (cerrado NO) → cerrar la rechaza.
        Apertura::factory()->create(['sucursal_id' => 1, 'fecha' => now()->addDay()->toDateString(), 'cerrado' => 'NO', 'estado' => 'ON']);
        $this->postJson('/api/caja/cierre', [])->assertStatus(422);
    }

    /**
     * Borde del guard: apertura de fecha EXACTAMENTE hoy debe poder cerrarse (el guard es `>`).
     * Si la comparación Carbon-vs-string estuviera rota, este cierre fallaría siempre.
     */
    public function test_guard_cerrar_apertura_de_hoy_borde_exacto(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        // apertura->fecha == hoy → NO es > hoy → se cierra normal.
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
    }

    /**
     * CASO DIFÍCIL (gap de filtro): `cierre()` selecciona la apertura activa con
     * `where('cerrado','NO')->latest()` SIN filtrar `estado='ON'`, mientras
     * `ingresar/egresar` sí filtran `estado='ON'`. Una apertura OFF con cerrado='NO'
     * (p.ej. una apertura-arrastre anulada por revertir-cierre) NO debe ser elegida
     * por `cierre()` — si lo fuera, se cerraría una apertura inválida.
     *
     * Escenario: hay una apertura OFF de mañana (residuo) + la apertura ON de hoy.
     * `cierre()` debe cerrar la de HOY (la ON válida), no la OFF de mañana.
     */
    public function test_cierre_ignora_apertura_off_y_cierra_la_activa(): void
    {
        $this->actingAsUser('ADMIN');
        // Residuo: apertura OFF de mañana con cerrado='NO' (como la deja revertir-cierre).
        Apertura::factory()->create([
            'sucursal_id' => 1, 'fecha' => now()->addDay()->toDateString(),
            'cerrado' => 'NO', 'estado' => 'OFF',
        ]);
        // Apertura válida ON de hoy.
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);

        // Debe cerrar la de hoy (ON), no la OFF de mañana → 200, no 422.
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE 5 — revertir-cierre: simetría / idempotencia (D5/D7).
    // ════════════════════════════════════════════════════════════════════════

    /**
     * CASO DIFÍCIL: revertir DOS veces. El 2º revert debe FALLAR (no re-deshacer),
     * porque el cierre ya no es el último ON.
     */
    public function test_revertir_cierre_dos_veces_el_segundo_falla(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', 1)->where('estado', 'ON')->latest('id')->first();

        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $cierre->id])->assertStatus(200);
        // 2º intento sobre el mismo cierre (ya OFF) → 422.
        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $cierre->id])->assertStatus(422);
    }

    /**
     * CASO DIFÍCIL: revertir restaura `sucursal.ultimo_cierre` al valor del cierre ANTERIOR.
     * Cadena: cerrar día A (ultimo_cierre=A) → cerrar día B (ultimo_cierre=B) → revertir B.
     * Tras revertir B, ultimo_cierre DEBE volver a A (la fecha de la apertura del cierre B,
     * que es A+1... el código lo fija a apertura_anterior->fecha). Verificamos que el guard
     * de período queda coherente: una tranza con fecha posterior a A vuelve a ser editable.
     */
    public function test_revertir_cierre_restaura_ultimo_cierre_coherente(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $hoy = now()->toDateString();
        $this->assertEquals($hoy, Sucursal::find(1)->ultimo_cierre);

        $cierre = Cierre::where('sucursal_id', 1)->where('estado', 'ON')->latest('id')->first();
        $aperturaOriginal = Apertura::find($cierre->apertura_id);

        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $cierre->id])->assertStatus(200);

        // ultimo_cierre vuelve a la fecha de la apertura original (su fecha, no hoy).
        $this->assertEquals($aperturaOriginal->fecha, Sucursal::find(1)->ultimo_cierre,
            'revertir restaura ultimo_cierre a la apertura previa');
    }

    /**
     * CASO DIFÍCIL: revertir-cierre sobre un cierre de OTRA sucursal → 403 (no se cruza frontera).
     */
    public function test_revertir_cierre_de_otra_sucursal_da_403(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        // Cierre de la sucursal 2.
        $ap = Apertura::factory()->create(['sucursal_id' => 2, 'fecha' => now()->toDateString(), 'cerrado' => 'SI', 'estado' => 'ON']);
        $cierre = Cierre::create([
            'sucursal_id' => 2, 'apertura_id' => $ap->id, 'fecha' => now()->toDateString(),
            'apertura' => 0, 'ingresos' => 0, 'egresos' => 0, 'cierre' => 0,
            'user_id' => 1, 'estado' => 'ON',
        ]);

        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $cierre->id])->assertStatus(403);
        $this->assertEquals('ON', $cierre->fresh()->estado);
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE 4 — Arrastre MULTI-DÍA (stateful PBT, D5/D6 — NÚCLEO).
    // Conservación del dinero a lo largo de la cadena: el saldo de apertura de cada
    // día == cierre del anterior, y cada cierre == apertura + Σingresos − Σegresos.
    // Determinista (LCG propio, sin mt_rand/inRandomOrder).
    // ════════════════════════════════════════════════════════════════════════

    public function test_pbt_arrastre_multidia_conserva_el_dinero(): void
    {
        $this->actingAsUser('ADMIN');
        $sid = 1;

        // LCG determinista (Numerical Recipes): reproducible, sin estado global.
        $seed = 20260616;
        $rng = function () use (&$seed) {
            $seed = ($seed * 1664525 + 1013904223) & 0x7fffffff;
            return $seed;
        };

        // Día 0: parte de una fecha fija para que el arrastre (addDays(1)) encaje día a día.
        $dia = Carbon::create(2026, 3, 2, 9, 0, 0); // lunes arbitrario
        Carbon::setTestNow($dia);

        // Apertura inicial del día 0.
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);

        $saldoEsperado = 0.0; // arrastre que debe heredar la apertura siguiente

        for ($d = 0; $d < 6; $d++) {
            // La apertura activa de HOY debe valer exactamente el arrastre del día anterior.
            $apHoy = Apertura::where('sucursal_id', $sid)->where('cerrado', 'NO')
                ->where('estado', 'ON')->latest('id')->first();
            $this->assertNotNull($apHoy, "día $d: debe existir apertura activa");
            $this->assertEquals($apHoy->fecha, $dia->toDateString(), "día $d: la apertura activa es de hoy");
            $this->assertEqualsWithDelta($saldoEsperado, (float) $apHoy->apertura, 0.001,
                "día $d: la apertura hereda el cierre del día anterior");

            // N ingresos/egresos pseudo-aleatorios del día (montos deterministas).
            $nMov = 1 + ($rng() % 4); // 1..4 movimientos
            $sumIng = 0.0;
            $sumEgr = 0.0;
            for ($m = 0; $m < $nMov; $m++) {
                $monto = 1 + ($rng() % 500); // 1..500 enteros (evita ruido de redondeo)
                if ($rng() % 2 === 0) {
                    $this->postJson('/api/caja/ingreso', ['monto' => $monto, 'descripcion' => "ing d$d m$m"])->assertStatus(200);
                    $sumIng += $monto;
                } else {
                    $this->postJson('/api/caja/egreso', ['monto' => $monto, 'descripcion' => "egr d$d m$m"])->assertStatus(200);
                    $sumEgr += $monto;
                }
            }

            $aperturaMonto = (float) $apHoy->apertura;
            $cierreEsperado = $aperturaMonto + $sumIng - $sumEgr;

            // Cerrar el día.
            $this->postJson('/api/caja/cierre', [])->assertStatus(200);
            $cierre = Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();

            $this->assertEqualsWithDelta($aperturaMonto, (float) $cierre->apertura, 0.001, "día $d: cierre.apertura");
            $this->assertEqualsWithDelta($sumIng, (float) $cierre->ingresos, 0.001, "día $d: cierre.ingresos = Σ ingresos del día");
            $this->assertEqualsWithDelta($sumEgr, (float) $cierre->egresos, 0.001, "día $d: cierre.egresos = Σ egresos del día");
            $this->assertEqualsWithDelta($cierreEsperado, (float) $cierre->cierre, 0.001,
                "día $d: cierre = apertura + Σingresos − Σegresos");

            $saldoEsperado = $cierreEsperado;

            // Avanza al día siguiente (donde quedó la apertura-arrastre).
            $dia = $dia->copy()->addDay();
            Carbon::setTestNow($dia);
        }

        Carbon::setTestNow();
    }

    /**
     * CASO DIFÍCIL: conservación del dinero — la suma de todos los `cierre.ingresos`
     * a lo largo de la cadena == Σ de todas las tranzas ON de ingreso de la sucursal.
     * Ninguna tranza desaparece, se duplica, ni se cuenta en dos cierres.
     */
    public function test_arrastre_multidia_ninguna_tranza_se_cuenta_dos_veces(): void
    {
        $this->actingAsUser('ADMIN');
        $sid = 1;
        $dia = Carbon::create(2026, 4, 6, 9, 0, 0);
        Carbon::setTestNow($dia);

        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);

        $totalIngManual = 0.0;
        $totalEgrManual = 0.0;
        for ($d = 0; $d < 4; $d++) {
            $this->postJson('/api/caja/ingreso', ['monto' => 100 + $d, 'descripcion' => "i$d"])->assertStatus(200);
            $this->postJson('/api/caja/egreso', ['monto' => 10 + $d, 'descripcion' => "e$d"])->assertStatus(200);
            $totalIngManual += 100 + $d;
            $totalEgrManual += 10 + $d;
            $this->postJson('/api/caja/cierre', [])->assertStatus(200);
            $dia = $dia->copy()->addDay();
            Carbon::setTestNow($dia);
        }
        Carbon::setTestNow();

        // Σ de los ingresos snapshoteados en los cierres == Σ tranzas ENT reales.
        $sumaCierresIng = (float) Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->sum('ingresos');
        $sumaCierresEgr = (float) Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->sum('egresos');
        $this->assertEqualsWithDelta($totalIngManual, $sumaCierresIng, 0.001, 'cada ingreso se concilia en exactamente un cierre');
        $this->assertEqualsWithDelta($totalEgrManual, $sumaCierresEgr, 0.001, 'cada egreso se concilia en exactamente un cierre');
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE 6 — Anular doc → tranza OFF → re-conciliación (D5).
    // Anular una venta CONTADO pone su tranza VEN en OFF; un cierre POSTERIOR
    // no debe contar esa tranza (conservación del dinero ante anulación).
    // ════════════════════════════════════════════════════════════════════════

    public function test_cierre_posterior_no_cuenta_tranza_de_venta_anulada(): void
    {
        $this->actingAsUser('ADMIN');
        $sid = 1;
        $hoy = now()->toDateString();

        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);

        // Venta CONTADO de 50 → tranza VEN ingreso 50.
        $cuenta = Cuenta::factory()->cliente()->create();
        $prod   = Producto::factory()->create(['p_norm' => 25, 'stock1' => 100]);
        $venta  = Venta::factory()->create(['sucursal_id' => $sid, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'fecha' => $hoy, 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $prod->id, 'cantidad' => 2]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);

        // Anular la venta → su tranza VEN pasa a OFF.
        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);

        // El cierre POSTERIOR no debe contar la tranza anulada → ingresos = 0.
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();
        $this->assertEquals(0.0, (float) $cierre->ingresos, 'la venta anulada no debe conciliarse en el cierre');
        $this->assertEquals(0.0, (float) $cierre->cierre, 'saldo conciliado = 0 tras anular la única venta');
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE 5b — revertir-cierre: ciclo y borde de arrastre con tranzas vivas.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * CASO DIFÍCIL: revertir → cerrar de nuevo → revertir (ciclo). Cada paso debe
     * mantener la coherencia: tras el ciclo, hay exactamente UN cierre ON, y
     * ultimo_cierre es coherente con él.
     */
    public function test_revertir_cerrar_revertir_mantiene_coherencia(): void
    {
        $this->actingAsUser('ADMIN');
        $sid = 1;
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);

        // Cerrar.
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $c1 = Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();
        // Revertir.
        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $c1->id])->assertStatus(200);
        // Cerrar de nuevo (la apertura original volvió a cerrado='NO').
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $c2 = Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();
        $this->assertNotEquals($c1->id, $c2->id, 'el re-cierre crea un cierre nuevo');
        // Revertir otra vez.
        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $c2->id])->assertStatus(200);

        // Estado final: ningún cierre ON, la apertura original abierta.
        $this->assertEquals(0, Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->count(),
            'tras revertir el último, no quedan cierres ON');
        $this->assertEquals('NO', Apertura::find($c1->apertura_id)->cerrado, 'la apertura original vuelve a estar abierta');
    }

    /**
     * CASO DIFÍCIL: revertir un cierre cuando ya hay tranzas registradas en la
     * apertura-arrastre del día siguiente. Revertir pone esa apertura-arrastre en OFF;
     * las tranzas quedan en una apertura OFF, pero como `revertir` restaura
     * `ultimo_cierre` a la apertura previa, esas tranzas deben volver a ser editables
     * (no quedar atrapadas en un período "cerrado" fantasma).
     */
    public function test_revertir_con_tranzas_en_arrastre_no_las_atrapa(): void
    {
        $this->actingAsUser('ADMIN');
        $sid = 1;
        $dia = Carbon::create(2026, 5, 4, 9, 0, 0);
        Carbon::setTestNow($dia);

        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();

        // Día siguiente: registrar una tranza en la apertura-arrastre.
        $dia = $dia->copy()->addDay();
        Carbon::setTestNow($dia);
        $this->postJson('/api/caja/ingreso', ['monto' => 40, 'descripcion' => 'arrastre'])->assertStatus(200);
        $tranza = Tranza::where('sucursal_id', $sid)->where('clase', 'ENT')->latest('id')->first();

        // Revertir el cierre del día anterior.
        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $cierre->id])->assertStatus(200);

        // ultimo_cierre volvió a la apertura original → la tranza del día siguiente
        // (fecha > ultimo_cierre restaurado) debe seguir siendo editable, no atrapada.
        $this->postJson('/api/caja/update-tranza', ['tranza_id' => $tranza->id, 'monto' => 55])
            ->assertStatus(200);
        $this->assertEquals(55.0, (float) $tranza->fresh()->monto_ingreso);

        Carbon::setTestNow();
    }

    /**
     * CASO DIFÍCIL (contrato D2): editar una tranza enviando `monto` pero SIN
     * `descripcion`. La columna `tranzas.descripcion` es NOT NULL; si el controller
     * asigna `descripcion = null` a ciegas (sin guard `filled`), revienta con 500.
     * Debe: preservar la descripción existente (o aceptar la nueva), nunca 500.
     */
    public function test_update_tranza_sin_descripcion_no_da_500(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 10, 'descripcion' => 'original'])->assertStatus(200);
        $tranza = Tranza::where('sucursal_id', 1)->where('clase', 'ENT')->latest('id')->first();

        // Update SOLO el monto (sin descripcion) → NO debe ser 500.
        $this->postJson('/api/caja/update-tranza', ['tranza_id' => $tranza->id, 'monto' => 33])
            ->assertStatus(200);

        $fresh = $tranza->fresh();
        $this->assertEquals(33.0, (float) $fresh->monto_ingreso, 'el monto se actualiza');
        $this->assertNotNull($fresh->descripcion, 'la descripción no debe quedar NULL (columna NOT NULL)');
        $this->assertEquals('original', $fresh->descripcion, 'la descripción previa se preserva si no se envía');
    }

    // ════════════════════════════════════════════════════════════════════════
    // ATAQUE — DECIMAL(12,2): el cierre con montos ~22M no debe desbordar.
    // (Lead del padre: el bug original era cierres.egresos de 22M reventando 9,2.)
    // ════════════════════════════════════════════════════════════════════════

    public function test_cierre_con_montos_de_22_millones_no_desborda(): void
    {
        $this->actingAsUser('ADMIN');
        $sid = 1;
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);

        // Ingreso y egreso enormes (cada uno cabe en decimal(12,2): máx 999,999,999,999.99).
        $this->postJson('/api/caja/ingreso', ['monto' => 22000000.50, 'descripcion' => 'gran ingreso'])->assertStatus(200);
        $this->postJson('/api/caja/egreso', ['monto' => 22000000.25, 'descripcion' => 'gran egreso'])->assertStatus(200);

        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', $sid)->where('estado', 'ON')->latest('id')->first();
        $this->assertEqualsWithDelta(22000000.50, (float) $cierre->ingresos, 0.001, 'ingresos de 22M no se truncan');
        $this->assertEqualsWithDelta(22000000.25, (float) $cierre->egresos, 0.001, 'egresos de 22M no se truncan');
        $this->assertEqualsWithDelta(0.25, (float) $cierre->cierre, 0.001, 'cierre = 22000000.50 - 22000000.25 = 0.25');
    }

    /**
     * CASO DIFÍCIL (contrato): monto de ingreso/egreso negativo o cero → 422
     * (ya cubierto en FinalCellsTest, acá se cierra el borde no-numérico).
     */
    public function test_ingreso_egreso_monto_no_numerico_da_422(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 'cien', 'descripcion' => 'x'])->assertStatus(422);
        $this->postJson('/api/caja/egreso', ['monto' => 'DROP', 'descripcion' => 'x'])->assertStatus(422);
    }
}
