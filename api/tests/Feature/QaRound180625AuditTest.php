<?php

namespace Tests\Feature;

use App\Models\Apertura;
use App\Models\Cierre;
use App\Models\Cotizacion;
use App\Models\Industria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Tranza;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AUDITORÍA ADVERSARIAL de la ronda QA 18/6 (commit 712b972).
 *
 * Ataca los vectores prioritarios:
 *  1) Autorización de costos (p_comp) en api/show/quicksearch/kpis + simulación.
 *  2) Caja con fecha pasada: burlar el cierre, fechas futuras/inválidas, imputación de apertura.
 *  3) `unique` removido en productos + overflow de DECIMAL(9,2) sin `max` en el validador.
 *  4) Cotización observacion/encabezado: IDOR, estado terminal, descuento, overflow.
 *
 * Casos DIFÍCILES primero. No se valida el camino feliz.
 */
class QaRound180625AuditTest extends TestCase
{
    /** Crea un producto con marca/industria reales. */
    private function producto(array $attrs = []): Producto
    {
        return Producto::factory()->create($attrs);
    }

    // ════════════════════════════════════════════════════════════════════════
    // VECTOR 1 — Autorización de COSTO (p_comp)
    // ════════════════════════════════════════════════════════════════════════

    /** VENDEDOR NO debe ver p_comp en el listado /api/productos. */
    public function test_vendedor_no_ve_costo_en_listado(): void
    {
        $this->producto(['codigo' => 'COST-1', 'p_comp' => 123.45]);
        $this->actingAsUser('VENDEDOR');
        $r = $this->getJson('/api/productos?search=COST-1')->assertStatus(200);
        $row = collect($r->json('data'))->firstWhere('codigo', 'COST-1');
        $this->assertNotNull($row, 'el producto debe listarse');
        $this->assertNull($row['p_comp'], 'VENDEDOR no debe recibir el costo (p_comp)');
        $this->assertNotNull($row['p_norm'], 'el precio de venta sí va');
    }

    /** CAJERO NO debe ver p_comp en quicksearch. */
    public function test_cajero_no_ve_costo_en_quicksearch(): void
    {
        $this->producto(['codigo' => 'COST-2', 'p_comp' => 999.99]);
        $this->actingAsUser('CAJERO');
        $r = $this->getJson('/api/productos/quicksearch?search=COST-2')->assertStatus(200);
        $row = collect($r->json())->firstWhere('codigo', 'COST-2');
        $this->assertNotNull($row);
        $this->assertNull($row['p_comp'], 'CAJERO no debe ver el costo en quicksearch');
    }

    /** VENDEDOR NO debe ver p_comp en show. */
    public function test_vendedor_no_ve_costo_en_show(): void
    {
        $p = $this->producto(['p_comp' => 77.77]);
        $this->actingAsUser('VENDEDOR');
        $r = $this->getJson('/api/productos/' . $p->id)->assertStatus(200);
        $this->assertNull($r->json('p_comp'), 'VENDEDOR no debe ver el costo en show');
    }

    /** VENDEDOR NO debe ver valor_inventario en KPIs. */
    public function test_vendedor_no_ve_valor_inventario_en_kpis(): void
    {
        $this->producto(['p_norm' => 100, 'stock1' => 10]);
        $this->actingAsUser('VENDEDOR');
        $r = $this->getJson('/api/productos/kpis')->assertStatus(200);
        $this->assertNull($r->json('valor_inventario'), 'VENDEDOR no debe ver el valor de inventario');
    }

    /** GERENTE SÍ debe ver p_comp (control: el gate no es demasiado restrictivo). */
    public function test_gerente_si_ve_costo(): void
    {
        $this->producto(['codigo' => 'COST-G', 'p_comp' => 50.00]);
        $this->actingAsUser('GERENTE');
        $r = $this->getJson('/api/productos?search=COST-G')->assertStatus(200);
        $row = collect($r->json('data'))->firstWhere('codigo', 'COST-G');
        $this->assertNotNull($row['p_comp'], 'GERENTE sí debe ver el costo');
        $this->assertEquals(50.0, $row['p_comp']);
    }

    /** ADMIN SIMULANDO VENDEDOR NO debe ver p_comp (la simulación se respeta). */
    public function test_admin_simulando_vendedor_no_ve_costo(): void
    {
        $this->producto(['codigo' => 'COST-SIM', 'p_comp' => 321.00]);
        $admin = $this->actingAsUser('ADMIN');
        $vendRole = \Spatie\Permission\Models\Role::findByName('VENDEDOR', 'web');
        $admin->simulated_role_id = $vendRole->id;
        $admin->save();
        $admin->refresh();
        $this->actingAs($admin, 'sanctum');

        $r = $this->getJson('/api/productos?search=COST-SIM')->assertStatus(200);
        $row = collect($r->json('data'))->firstWhere('codigo', 'COST-SIM');
        $this->assertNotNull($row, 'el producto debe listarse aun simulando');
        $this->assertNull($row['p_comp'], 'ADMIN simulando VENDEDOR NO debe ver el costo (fuga de simulador)');
    }

    // ════════════════════════════════════════════════════════════════════════
    // VECTOR 2 — Caja con fecha pasada (burlar el cierre)
    // ════════════════════════════════════════════════════════════════════════

    /** Egreso fechado DENTRO de un periodo ya cerrado (fecha < ultimo_cierre) → 422. */
    public function test_egreso_backdateado_en_periodo_cerrado_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        // Cierre real: ultimo_cierre = hoy.
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $hoy = now()->toDateString();
        $this->assertEquals($hoy, Carbon::parse(Sucursal::find(1)->ultimo_cierre)->toDateString());

        $ayer = now()->subDay()->toDateString();
        // Backdatear un egreso a ayer (dentro del periodo cerrado) DEBE rechazarse.
        $this->postJson('/api/caja/egreso', ['monto' => 500, 'descripcion' => 'gasto retro', 'fecha' => $ayer])
            ->assertStatus(422);
        $this->assertEquals(0.0, (float) Tranza::where('sucursal_id', 1)->where('tipo', 'EGRESO')->sum('monto_egreso'),
            'no debe quedar ninguna tranza backdateada en periodo cerrado');
    }

    /** Egreso fechado EXACTAMENTE en ultimo_cierre → 422 (el guard es <=). */
    public function test_egreso_fecha_igual_a_ultimo_cierre_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $this->postJson('/api/caja/cierre', [])->assertStatus(200);
        $hoy = now()->toDateString();

        $this->postJson('/api/caja/egreso', ['monto' => 10, 'descripcion' => 'x', 'fecha' => $hoy])
            ->assertStatus(422);
    }

    /** Fecha con formato basura → 422 limpio (no 500). */
    public function test_egreso_con_fecha_basura_da_422_limpio(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/ingreso', ['monto' => 10, 'descripcion' => 'x', 'fecha' => 'no-es-fecha'])
            ->assertStatus(422)->assertJsonValidationErrorFor('fecha');
    }

    /**
     * CASO DIFÍCIL: fecha FUTURA. ¿Se puede ingresar una tranza fechada mañana,
     * inflando el saldo futuro / quedando huérfana del cierre? El guard solo mira
     * `fecha <= ultimo_cierre`; una fecha futura PASA el guard.
     * Esto documenta el comportamiento: si pasa, la tranza futura NO debería
     * contar en el saldo de HOY (la ventana de KPIs llega hasta hoy).
     */
    public function test_ingreso_con_fecha_futura_no_infla_el_saldo_de_hoy(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $manana = now()->addDay()->toDateString();

        $resp = $this->postJson('/api/caja/ingreso', ['monto' => 5000, 'descripcion' => 'futuro', 'fecha' => $manana]);
        // El backend lo acepta (no hay guard de fecha futura). Verificamos que NO
        // contamine el saldo de hoy: el KPI usa rango [apertura..hoy].
        if ($resp->status() === 200) {
            $k = $this->getJson('/api/caja/kpis')->assertStatus(200);
            $this->assertEquals(0.0, (float) $k->json('ingresos'),
                'una tranza fechada en el futuro no debe contar en los ingresos de hoy');
            $this->assertEquals(0.0, (float) $k->json('saldo') - (float) $k->json('apertura_monto'),
                'el saldo de hoy no debe incluir dinero fechado en el futuro');
        }
    }

    /**
     * CASO DIFÍCIL: imputación de apertura al BACKDATEAR dentro de un periodo abierto.
     * Apertura HOY (monto 0). Se registra un egreso fechado AYER (ayer > ultimo_cierre 2018,
     * así que pasa el guard). La ventana de KPIs/movimientos arranca en la fecha de la
     * apertura activa (HOY) → la tranza de ayer queda FUERA del rango [hoy..hoy].
     *
     * Resultado: el gasto se persiste (existe en la BD) pero NO aparece en el saldo ni en
     * la lista de movimientos del día. Para el cajero, el dinero "desaparece": registró un
     * gasto real que no resta del saldo visible y que tampoco se incluirá en el cierre de hoy
     * (cierre concilia [apertura->fecha=hoy .. fin=hoy]). Documenta el efecto.
     */
    public function test_egreso_backdateado_en_apertura_abierta_queda_fuera_del_saldo(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1, ultimo_cierre = 2018-01-01
        $this->postJson('/api/caja/apertura', ['monto' => 0])->assertStatus(200);
        $ayer = now()->subDay()->toDateString();

        // Pasa el guard (ayer > 2018) y se persiste.
        $this->postJson('/api/caja/egreso', ['monto' => 300, 'descripcion' => 'gasto de ayer', 'fecha' => $ayer])
            ->assertStatus(200);
        $this->assertEquals(300.0, (float) Tranza::where('sucursal_id', 1)->where('tipo', 'EGRESO')->sum('monto_egreso'),
            'la tranza de ayer SÍ se persiste');

        // Pero el saldo de HOY no lo refleja: la ventana arranca en la apertura (hoy).
        $k = $this->getJson('/api/caja/kpis')->assertStatus(200);
        $egresosVisibles = (float) $k->json('egresos');

        // Movimientos del día tampoco lo listan.
        $m = $this->getJson('/api/caja/movimientos')->assertStatus(200);
        $idsVisibles = collect($m->json('data'))->pluck('descripcion');

        // Esto DOCUMENTA el comportamiento actual (no necesariamente correcto): el gasto de
        // ayer no entra al saldo de hoy ni a la lista. Si el negocio espera que un gasto
        // backdateado de un periodo ABIERTO se concilie, esto es un hueco de visibilidad.
        $this->assertEquals(0.0, $egresosVisibles,
            'COMPORTAMIENTO ACTUAL: el egreso de ayer NO entra al saldo de hoy (ventana arranca en la apertura)');
        $this->assertFalse($idsVisibles->contains('gasto de ayer'),
            'COMPORTAMIENTO ACTUAL: el egreso de ayer NO aparece en los movimientos del día');
    }

    // ════════════════════════════════════════════════════════════════════════
    // VECTOR 3 — `unique` removido + overflow DECIMAL(9,2)
    // ════════════════════════════════════════════════════════════════════════

    /** Código duplicado se PERMITE (paridad legacy) — store. */
    public function test_codigo_duplicado_se_permite_en_store(): void
    {
        $this->actingAsUser('ADMIN');
        $marca = Marca::factory()->create();
        $ind = Industria::factory()->create();
        Producto::factory()->create(['codigo' => 'DUPX', 'marca_id' => $marca->id, 'industria_id' => $ind->id]);

        $this->postJson('/api/productos', [
            'codigo' => 'DUPX', 'descripcion' => 'otro', 'marca_id' => $marca->id, 'industria_id' => $ind->id,
        ])->assertStatus(200);
        $this->assertEquals(2, Producto::where('codigo', 'DUPX')->count());
    }

    /**
     * OVERFLOW: precio > 9,999,999.99 (máx de DECIMAL(9,2)). El validador no tiene
     * `max`, así que el INSERT revienta. Con STRICT_TRANS_TABLES MySQL lanza 1264
     * → 500 (no un 422 limpio). DEBE ser un 4xx limpio, no un 500.
     */
    public function test_precio_overflow_da_4xx_no_500_en_store(): void
    {
        $this->actingAsUser('ADMIN');
        $marca = Marca::factory()->create();
        $ind = Industria::factory()->create();

        $resp = $this->postJson('/api/productos', [
            'codigo' => 'BIG-1', 'descripcion' => 'caro', 'marca_id' => $marca->id, 'industria_id' => $ind->id,
            'p_comp' => 99999999.99, // > 9,999,999.99
        ]);
        $this->assertLessThan(500, $resp->status(),
            'un precio fuera de rango debe dar 4xx limpio, no 500 (falta max en el validador)');
    }

    /** OVERFLOW en update también. */
    public function test_precio_overflow_da_4xx_no_500_en_update(): void
    {
        $this->actingAsUser('ADMIN');
        $p = $this->producto();

        $resp = $this->putJson('/api/productos/' . $p->id, [
            'codigo' => $p->codigo, 'descripcion' => $p->descripcion,
            'marca_id' => $p->marca_id, 'industria_id' => $p->industria_id,
            'p_norm' => 50000000, // > máx
        ]);
        $this->assertLessThan(500, $resp->status(),
            'update con precio fuera de rango debe dar 4xx limpio, no 500');
    }

    /** Inyección/unicode en codigo no rompe (se guarda como string). */
    public function test_codigo_con_payload_xss_sqli_se_guarda_como_texto(): void
    {
        $this->actingAsUser('ADMIN');
        $marca = Marca::factory()->create();
        $ind = Industria::factory()->create();
        $payload = "'; DROP TABLE productos;-- <script>alert(1)</script>";

        $r = $this->postJson('/api/productos', [
            'codigo' => $payload, 'descripcion' => 'unicode 你好 😀', 'marca_id' => $marca->id, 'industria_id' => $ind->id,
        ])->assertStatus(200);
        // La tabla sigue viva y el valor se guardó literal.
        $this->assertGreaterThan(0, Producto::count());
        $this->assertEquals($payload, Producto::find($r->json('id'))->codigo);
    }

    // ════════════════════════════════════════════════════════════════════════
    // VECTOR 4 — Cotización: observacion / encabezado
    // ════════════════════════════════════════════════════════════════════════

    /** show() de una cotización de OTRA sucursal → 403 (IDOR). */
    public function test_show_cotizacion_de_otra_sucursal_es_403(): void
    {
        $user = $this->actingAsUser('GERENTE'); // sucursal 1
        $cot = Cotizacion::create([
            'sucursal_id' => 2, 'cuenta_id' => 1, 'fecha' => now()->toDateString(),
            'tipo' => 'CONTADO', 'monto' => 100, 'descuento' => 0, 'total' => 100,
            'estado' => 'VALIDO', 'user_id' => $user->id, 'observacion' => 'secreto sucursal 2',
        ]);
        $this->getJson('/api/cotizaciones/' . $cot->id)->assertStatus(403);
    }

    /** updateEncabezado de cotización CONVERTIDA → 422 (estado terminal). */
    public function test_update_encabezado_cotizacion_convertida_es_422(): void
    {
        $user = $this->actingAsUser('GERENTE');
        $cot = Cotizacion::create([
            'sucursal_id' => 1, 'cuenta_id' => 1, 'fecha' => now()->toDateString(),
            'tipo' => 'CONTADO', 'monto' => 100, 'descuento' => 0, 'total' => 100,
            'estado' => 'CONVERTIDA', 'user_id' => $user->id, 'observacion' => '',
        ]);
        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => 1, 'fecha' => now()->toDateString(), 'descuento' => 0,
        ])->assertStatus(422);
    }

    /** updateEncabezado de cotización de OTRA sucursal → 403 (IDOR). */
    public function test_update_encabezado_otra_sucursal_es_403(): void
    {
        $user = $this->actingAsUser('GERENTE'); // sucursal 1
        $cot = Cotizacion::create([
            'sucursal_id' => 2, 'cuenta_id' => 1, 'fecha' => now()->toDateString(),
            'tipo' => 'CONTADO', 'monto' => 200, 'descuento' => 0, 'total' => 200,
            'estado' => 'VALIDO', 'user_id' => $user->id, 'observacion' => '',
        ]);
        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => 1, 'fecha' => now()->toDateString(), 'descuento' => 0,
        ])->assertStatus(403);
    }

    /** descuento negativo en updateEncabezado → 422 (no inflar total). */
    public function test_update_encabezado_descuento_negativo_es_422(): void
    {
        $user = $this->actingAsUser('GERENTE');
        $cot = Cotizacion::create([
            'sucursal_id' => 1, 'cuenta_id' => 1, 'fecha' => now()->toDateString(),
            'tipo' => 'CONTADO', 'monto' => 100, 'descuento' => 0, 'total' => 100,
            'estado' => 'VALIDO', 'user_id' => $user->id, 'observacion' => '',
        ]);
        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => 1, 'fecha' => now()->toDateString(), 'descuento' => -50,
        ])->assertStatus(422);
        $this->assertEquals(100.0, (float) Cotizacion::find($cot->id)->total, 'el total no debe inflarse');
    }

    /** observacion > 191 chars → 422 limpio (no 1406/500). */
    public function test_observacion_overflow_da_422(): void
    {
        $user = $this->actingAsUser('GERENTE');
        $cot = Cotizacion::create([
            'sucursal_id' => 1, 'cuenta_id' => 1, 'fecha' => now()->toDateString(),
            'tipo' => 'CONTADO', 'monto' => 100, 'descuento' => 0, 'total' => 100,
            'estado' => 'VALIDO', 'user_id' => $user->id, 'observacion' => '',
        ]);
        $this->postJson('/api/cotizaciones/update-encabezado', [
            'cotizacion_id' => $cot->id, 'cuenta_id' => 1, 'fecha' => now()->toDateString(),
            'descuento' => 0, 'observacion' => str_repeat('A', 500),
        ])->assertStatus(422)->assertJsonValidationErrorFor('observacion');
    }

    /** show() devuelve observacion (la corrección principal del fix). */
    public function test_show_devuelve_observacion(): void
    {
        $user = $this->actingAsUser('GERENTE');
        $cot = Cotizacion::create([
            'sucursal_id' => 1, 'cuenta_id' => 1, 'fecha' => now()->toDateString(),
            'tipo' => 'CONTADO', 'monto' => 100, 'descuento' => 0, 'total' => 100,
            'estado' => 'VALIDO', 'user_id' => $user->id, 'observacion' => 'Juan Perez 70000000',
        ]);
        $r = $this->getJson('/api/cotizaciones/' . $cot->id)->assertStatus(200);
        $this->assertEquals('Juan Perez 70000000', $r->json('observacion'));
    }
}
