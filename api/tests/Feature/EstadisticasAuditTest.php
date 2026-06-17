<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Compradetalle;
use App\Models\Cuenta;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo ESTADÍSTICAS.
 *
 * Foco (el blast-radius real, NO authz, ya cubierto en DashboardTest/GapCoverageTest):
 *   1. SQLi / whitelist en la granularidad ($label interpolado con DB::raw).
 *   2. Correctitud de agregados (property / metamórfica) — el corazón.
 *   3. Fuzz de params (fechas invertidas/imposibles, take negativo/enorme, métrica basura).
 *   4. Formato numérico parseable (D9).
 *
 * Casos DIFÍCILES primero: división por cero, denominadores 0, empates, bordes de fecha.
 */
class EstadisticasAuditTest extends TestCase
{
    // ── Helper: crea una compra VALIDA con un detalle, devuelve [compra, producto] ──
    private function compraConDetalle(int $sid, int $productoId, int $cant, float $costo, string $fecha): Compra
    {
        $compra = Compra::factory()->valido()->create(['sucursal_id' => $sid, 'fecha' => $fecha]);
        $p = Producto::find($productoId);
        Compradetalle::create([
            'compra_id' => $compra->id, 'producto_id' => $productoId,
            'codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'marca' => '',
            'costo' => $costo, 'p_comp' => $costo, 'p_norm' => $costo * 1.5, 'p_fact' => $costo * 1.8,
            'cantidad' => $cant, 'monto' => $cant * $costo, 'descuento' => 0, 'subtotal' => $cant * $costo,
            'user_id' => 1, 'estado' => 'VALIDO',
        ]);
        return $compra;
    }

    private function ventaConDetalle(int $sid, int $productoId, int $cant, float $precioUnit, float $pComp, string $fecha): Venta
    {
        $venta = Venta::factory()->valido()->create([
            'sucursal_id' => $sid, 'cuenta_id' => Cuenta::factory()->cliente(),
            'fecha' => $fecha, 'total' => $cant * $precioUnit,
        ]);
        Ventadetalle::factory()->create([
            'venta_id' => $venta->id, 'producto_id' => $productoId,
            'cantidad' => $cant, 'subtotal' => $cant * $precioUnit, 'costo' => $precioUnit, 'p_comp' => $pComp,
            'monto' => $cant * $precioUnit, 'descuento' => 0, 'estado' => 'VALIDO',
        ]);
        return $venta;
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1. SQLi / whitelist en granularidad ($label vía DB::raw)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Payloads SQLi/basura/unicode en vpGran. El whitelist DEBE caer a 'month'
     * (o un default seguro) y JAMÁS inyectar ni devolver 500. Si la tabla 'ventas'
     * siguiera existiendo tras un "DROP TABLE", confirma que no se ejecutó.
     */
    public function test_vpgran_payloads_sqli_no_inyectan_ni_500(): void
    {
        $this->actingAsUser('ADMIN');

        $payloads = [
            "day'; DROP TABLE ventas; --",
            "month) UNION SELECT password FROM users --",
            "DATE(fecha)); DELETE FROM ventas WHERE 1=1; --",
            '1=1',
            'YEAR(fecha)',           // función válida pero NO en whitelist → debe caer a month
            '"; --',
            '٣',                     // unicode árabe
            str_repeat('A', 5000),   // gigante
            '',                      // vacío
            'MONTH',                 // case distinto
        ];

        foreach ($payloads as $p) {
            $resp = $this->getJson('/api/estadisticas/ventas-periodo?vpGran=' . urlencode($p));
            $this->assertSame(200, $resp->status(), "vpGran=[$p] debe devolver 200 (whitelist), no " . $resp->status());
            $this->assertIsArray($resp->json(), "vpGran=[$p] debe devolver un array.");
        }

        // La tabla ventas DEBE seguir existiendo (ningún DROP se ejecutó).
        $this->assertTrue(Schema::hasTable('ventas'), 'La tabla ventas NO debe haber sido dropeada.');
    }

    /** Mismo ataque sobre la exportación CSV (segundo punto de interpolación). */
    public function test_export_vpgran_payloads_sqli_no_inyectan(): void
    {
        $this->actingAsUser('ADMIN');

        foreach (["day'; DROP TABLE ventas; --", 'YEAR(fecha)', 'basura'] as $p) {
            $resp = $this->get('/api/estadisticas/exportar-ventas-periodo?vpGran=' . urlencode($p));
            $this->assertSame(200, $resp->status(), "export vpGran=[$p] debe ser 200, no " . $resp->status());
        }
        $this->assertTrue(Schema::hasTable('ventas'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // 2. Correctitud de agregados — el corazón
    // ════════════════════════════════════════════════════════════════════════

    /**
     * METAMÓRFICA: vender 6 u. en un renglón ≡ vender 3 u. en dos renglones.
     * El ranking de topProductos (unidades y monto) debe ser idéntico en ambos
     * mundos. Ataca la suma de agregados sobre múltiples detalles.
     */
    public function test_top_productos_metamorfica_renglon_unico_vs_doble(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid = $user->sucursal_id;

        // Mundo A: un renglón de 6
        $pA = Producto::factory()->create();
        $vA = Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-03-10', 'total' => 600]);
        Ventadetalle::factory()->create(['venta_id' => $vA->id, 'producto_id' => $pA->id, 'cantidad' => 6, 'costo' => 100, 'p_comp' => 60, 'subtotal' => 600, 'monto' => 600, 'estado' => 'VALIDO']);

        // Mundo B: dos renglones de 3 (misma venta)
        $pB = Producto::factory()->create();
        $vB = Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-03-10', 'total' => 600]);
        Ventadetalle::factory()->create(['venta_id' => $vB->id, 'producto_id' => $pB->id, 'cantidad' => 3, 'costo' => 100, 'p_comp' => 60, 'subtotal' => 300, 'monto' => 300, 'estado' => 'VALIDO']);
        Ventadetalle::factory()->create(['venta_id' => $vB->id, 'producto_id' => $pB->id, 'cantidad' => 3, 'costo' => 100, 'p_comp' => 60, 'subtotal' => 300, 'monto' => 300, 'estado' => 'VALIDO']);

        $resp = $this->getJson('/api/estadisticas/top-productos?tpDesde=2026-01-01&tpHasta=2026-12-31&tpMet=unidades&take=100');
        $resp->assertStatus(200);
        $data = collect($resp->json('data'));

        $rowA = $data->firstWhere('codigo', $pA->codigo);
        $rowB = $data->firstWhere('codigo', $pB->codigo);
        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertEquals(6, (float) $rowA['total_vendido'], 'Renglón único: 6 u.');
        $this->assertEquals(6, (float) $rowB['total_vendido'], 'Dos renglones de 3: también 6 u. (suma).');
        $this->assertEquals((float) $rowA['total_monto'], (float) $rowB['total_monto'], 'El monto debe coincidir.');
    }

    /**
     * El ranking de topProductos debe ORDENAR DE VERDAD por la métrica pedida,
     * y unidades vs monto deben producir ÓRDENES DISTINTOS cuando difieren.
     *
     * Producto X: 100 unidades a costo 1  → 100 u, monto 100
     * Producto Y:   5 unidades a costo 100 → 5 u,  monto 500
     * Por unidades gana X; por monto gana Y.
     */
    public function test_top_productos_ordena_por_metrica_correcta(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid = $user->sucursal_id;

        $x = Producto::factory()->create();
        $y = Producto::factory()->create();
        $vx = Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-04-01', 'total' => 100]);
        Ventadetalle::factory()->create(['venta_id' => $vx->id, 'producto_id' => $x->id, 'cantidad' => 100, 'costo' => 1, 'p_comp' => 1, 'subtotal' => 100, 'monto' => 100, 'estado' => 'VALIDO']);
        $vy = Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-04-01', 'total' => 500]);
        Ventadetalle::factory()->create(['venta_id' => $vy->id, 'producto_id' => $y->id, 'cantidad' => 5, 'costo' => 100, 'p_comp' => 60, 'subtotal' => 500, 'monto' => 500, 'estado' => 'VALIDO']);

        $base = '/api/estadisticas/top-productos?tpDesde=2026-01-01&tpHasta=2026-12-31&take=100&tpMet=';

        $porUnid = collect($this->getJson($base . 'unidades')->json('data'));
        $this->assertSame($x->codigo, $porUnid->first()['codigo'], 'Por unidades, X (100u) primero.');

        $porMonto = collect($this->getJson($base . 'monto')->json('data'));
        $this->assertSame($y->codigo, $porMonto->first()['codigo'], 'Por monto, Y (Bs.500) primero.');
    }

    /**
     * topClientes: el ticket promedio = monto/ventas. Cliente con 2 ventas de 100 y 300
     * → ticket 200. Confirma el guard IF(COUNT>0,...) y que el cálculo es correcto.
     */
    public function test_top_clientes_ticket_promedio_correcto(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid = $user->sucursal_id;
        $cli = Cuenta::factory()->cliente()->create(['nombre' => 'CLIENTE TICKET']);

        Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => $cli->id, 'fecha' => '2026-05-01', 'total' => 100]);
        Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => $cli->id, 'fecha' => '2026-05-02', 'total' => 300]);

        $resp = $this->getJson('/api/estadisticas/top-clientes?tcDesde=2026-01-01&tcHasta=2026-12-31&take=100');
        $resp->assertStatus(200);
        $row = collect($resp->json('data'))->firstWhere('cliente', 'CLIENTE TICKET');
        $this->assertNotNull($row);
        $this->assertEquals(2, (float) $row['ventas']);
        $this->assertEquals(400, (float) $row['monto']);
        $this->assertEquals(200, (float) $row['ticket'], 'ticket = 400/2 = 200.');
    }

    /**
     * ventasPeriodo: la SUMA de los buckets debe cuadrar con la suma de las ventas
     * subyacentes (conservación). Y fecha_desde/fecha_hasta deben ser INCLUSIVE en
     * ambos extremos (una venta el día exacto de 'hasta' debe contar).
     */
    public function test_ventas_periodo_suma_cuadra_y_bordes_inclusive(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid = $user->sucursal_id;

        // Venta justo en el borde inferior y superior.
        Venta::factory()->valido()->create(['sucursal_id' => $sid, 'fecha' => '2026-06-01', 'total' => 111]);
        Venta::factory()->valido()->create(['sucursal_id' => $sid, 'fecha' => '2026-06-30', 'total' => 222]);
        // Fuera de rango (no debe contar).
        Venta::factory()->valido()->create(['sucursal_id' => $sid, 'fecha' => '2026-07-01', 'total' => 999]);

        $resp = $this->getJson('/api/estadisticas/ventas-periodo?vpDesde=2026-06-01&vpHasta=2026-06-30&vpGran=month&vpSucursal=' . $sid);
        $resp->assertStatus(200);
        $rows = collect($resp->json());
        $sumaBuckets = $rows->sum(fn($r) => (float) $r['total']);
        $sumaVentas = $rows->sum(fn($r) => (int) $r['ventas']);

        $this->assertEquals(333, $sumaBuckets, 'Suma de buckets = 111 + 222 (ambos bordes inclusive, excluye el 1-jul).');
        $this->assertEquals(2, $sumaVentas, 'Exactamente 2 ventas dentro del rango inclusive.');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 3. Fuzz de params — contrato (D2/D3)
    // ════════════════════════════════════════════════════════════════════════

    /** Fechas invertidas (desde>hasta) → whereBetween vacío, 200 sano, jamás 500. */
    public function test_fechas_invertidas_devuelven_vacio_no_500(): void
    {
        $this->actingAsUser('ADMIN');
        foreach (['ventas-periodo?vpDesde=2030-01-01&vpHasta=2020-01-01',
                  'top-productos?tpDesde=2030-01-01&tpHasta=2020-01-01',
                  'top-clientes?tcDesde=2030-01-01&tcHasta=2020-01-01',
                  'rotacion?rotDesde=2030-01-01&rotHasta=2020-01-01'] as $url) {
            $resp = $this->getJson('/api/estadisticas/' . $url);
            $this->assertSame(200, $resp->status(), "[$url] fechas invertidas → 200 vacío, no " . $resp->status());
        }
    }

    /** Fechas imposibles / no-fecha → no 500 (4xx limpio o resultado sano). */
    public function test_fechas_imposibles_no_500(): void
    {
        $this->actingAsUser('ADMIN');
        foreach (['2026-13-40', 'no-soy-fecha', "2026'; DROP TABLE ventas; --", '٢٠٢٦'] as $f) {
            $resp = $this->getJson('/api/estadisticas/ventas-periodo?vpDesde=' . urlencode($f) . '&vpHasta=' . urlencode($f));
            $this->assertLessThan(500, $resp->status(), "vpDesde=[$f] no debe causar 500, dio " . $resp->status());
        }
        $this->assertTrue(Schema::hasTable('ventas'));
    }

    /** take negativo / cero / enorme → no 500, no fuga; resultado acotado o vacío sano. */
    public function test_take_extremos_no_500(): void
    {
        $this->actingAsUser('ADMIN');
        foreach (['-1', '0', '999999999', '٣', 'abc'] as $t) {
            foreach (['rotacion', 'top-productos', 'top-clientes'] as $ep) {
                $resp = $this->getJson("/api/estadisticas/$ep?take=" . urlencode($t));
                $this->assertLessThan(500, $resp->status(), "$ep?take=[$t] no debe 500, dio " . $resp->status());
            }
        }
    }

    /** Métricas inválidas en tpMet/tcMet → caen a un orden por defecto sano, no 500. */
    public function test_metricas_invalidas_no_500(): void
    {
        $this->actingAsUser('ADMIN');
        foreach (["x'; DROP TABLE ventas;--", 'unidades OR 1=1', '', '٣'] as $m) {
            $this->getJson('/api/estadisticas/top-productos?tpMet=' . urlencode($m))->assertStatus(200);
            $this->getJson('/api/estadisticas/top-clientes?tcMet=' . urlencode($m))->assertStatus(200);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // 4. División por cero / denominadores (rotacionSucursal, código fresco)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * División por cero en rotacionSucursal: un producto cuyo único movimiento es
     * un DESPACHO completo (entrada == despachado → disponible 0) no debe romper el
     * cálculo de rotación (vend/disponible). Debe devolver 200 sano.
     *
     * sucA compra 5, despacha los 5 a sucB (RECIBIDO), no vende nada en sucA.
     *   sucA: entrada 5, despachado 5, disponible 0 → rotación 0 (sin división por cero).
     */
    public function test_rotacion_sucursal_disponible_cero_no_divide_por_cero(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sucA = Sucursal::factory()->create();
        $sucB = Sucursal::factory()->create();
        $p = Producto::factory()->create();

        $this->compraConDetalle($sucA->id, $p->id, 5, 40, '2026-01-01');

        $envio = \App\Models\Envio::factory()->create(['sucursal_id' => $sucA->id, 'cuenta_id' => $sucB->id, 'estado' => 'RECIBIDO', 'fecha' => '2026-01-10']);
        \App\Models\Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $p->id,
            'codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'marca' => '',
            'cantidad' => 5, 'estado' => 'VALIDO',
        ]);

        $resp = $this->getJson('/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=' . $sucA->id);
        $resp->assertStatus(200);
        $row = collect($resp->json('data'))->firstWhere('producto_id', $p->id);
        $this->assertNotNull($row, 'El producto entró a sucA, debe aparecer.');
        $this->assertEquals(0, $row['disponible'], 'disponible = entrada 5 - despachado 5 = 0.');
        $this->assertEquals(0, $row['rotacion'], 'rotación con disponible 0 = 0 (no NaN/Inf/500).');
    }

    /**
     * El rotacion (por compra) ya tiene guard CASE en costo_unitario (línea 608 del
     * FIFO), pero el costo_unitario de la propia query rotacion() (línea 72) hace
     * SUM(cant*costo)/SUM(cant) SIN guard. Si una compra tuviera SUM(cantidad)=0 esto
     * dividiría por cero. La validación de cantidad es integer|min:1, así que SUM>0
     * en la práctica — confirmamos que el caso real (cantidad mínima 1) NO rompe y
     * el costo_unitario sale correcto.
     */
    public function test_rotacion_costo_unitario_correcto_cantidad_minima(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid = $user->sucursal_id;
        $p = Producto::factory()->create();
        $compra = $this->compraConDetalle($sid, $p->id, 1, 250, '2026-02-01');

        $resp = $this->getJson('/api/estadisticas/rotacion?rotDesde=2026-01-01&rotHasta=2026-12-31&rotSucursal=' . $sid . '&take=100');
        $resp->assertStatus(200);
        $row = collect($resp->json('data'))->firstWhere('compra_id', '#' . $compra->id);
        $this->assertNotNull($row);
        $this->assertSame('Bs. 250.00', $row['costo_unitario'], 'costo_unitario = 250/1.');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 5. Formato numérico parseable (D9) — los paneles del front hacen parseFloat
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Los agregados que el FRONT parsea con parseFloat NO deben venir con coma de
     * miles (bug #1 del proyecto: number_format("1,234.56") → parseFloat → 1).
     * ventasPeriodo.total, topProductos.total_monto/total_vendido, topClientes.monto/ticket,
     * rotacionSucursal.utilidad/rotacion deben ser numéricos crudos.
     */
    public function test_agregados_parseables_sin_coma_de_miles(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid = $user->sucursal_id;
        $p = Producto::factory()->create();

        // Monto grande para forzar separador de miles si alguien usara number_format.
        $this->ventaConDetalle($sid, $p->id, 1000, 5000, 2000, '2026-03-15');

        // ventas-periodo
        $vp = $this->getJson('/api/estadisticas/ventas-periodo?vpDesde=2026-01-01&vpHasta=2026-12-31&vpSucursal=' . $sid);
        foreach ($vp->json() as $r) {
            $this->assertStringNotContainsString(',', (string) $r['total'], 'ventas-periodo.total no debe tener coma de miles.');
        }

        // top-productos
        $tp = $this->getJson('/api/estadisticas/top-productos?tpDesde=2026-01-01&tpHasta=2026-12-31&tpSucursal=' . $sid);
        foreach ($tp->json('data') as $r) {
            $this->assertStringNotContainsString(',', (string) $r['total_monto'], 'top-productos.total_monto no debe tener coma.');
        }

        // rotacion-sucursal (utilidad/rotacion son números, no strings formateados)
        $rs = $this->getJson('/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=' . $sid);
        foreach ($rs->json('data') as $r) {
            $this->assertIsNumeric($r['utilidad'], 'rotacion-sucursal.utilidad debe ser numérico crudo.');
            $this->assertIsNumeric($r['rotacion'], 'rotacion-sucursal.rotacion debe ser numérico crudo.');
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // 6. rotacionSucursal — correctitud de COGS/utilidad y métricas (código fresco)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * METAMÓRFICA en rotacionSucursal: vender 6 u. en un renglón ≡ vender 3 u. en dos
     * renglones (misma sucursal, mismo precio/costo). El 'vendido', 'utilidad' y
     * 'rotacion' deben ser IDÉNTICOS entre ambos mundos (en sucursales distintas para
     * aislar).
     */
    public function test_rotacion_sucursal_metamorfica_renglon_unico_vs_doble(): void
    {
        $this->actingAsUser('ADMIN');
        $sucA = Sucursal::factory()->create();
        $sucB = Sucursal::factory()->create();
        $p = Producto::factory()->create();

        // sucA: compra 10, vende 6 en UN renglón (precio 100, p_comp 40)
        $this->compraConDetalle($sucA->id, $p->id, 10, 40, '2026-01-01');
        $vA = Venta::factory()->valido()->create(['sucursal_id' => $sucA->id, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-02-01', 'total' => 600]);
        Ventadetalle::factory()->create(['venta_id' => $vA->id, 'producto_id' => $p->id, 'cantidad' => 6, 'costo' => 100, 'p_comp' => 40, 'subtotal' => 600, 'monto' => 600, 'estado' => 'VALIDO']);

        // sucB: compra 10, vende 3+3 en DOS renglones (misma venta)
        $this->compraConDetalle($sucB->id, $p->id, 10, 40, '2026-01-01');
        $vB = Venta::factory()->valido()->create(['sucursal_id' => $sucB->id, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-02-01', 'total' => 600]);
        Ventadetalle::factory()->create(['venta_id' => $vB->id, 'producto_id' => $p->id, 'cantidad' => 3, 'costo' => 100, 'p_comp' => 40, 'subtotal' => 300, 'monto' => 300, 'estado' => 'VALIDO']);
        Ventadetalle::factory()->create(['venta_id' => $vB->id, 'producto_id' => $p->id, 'cantidad' => 3, 'costo' => 100, 'p_comp' => 40, 'subtotal' => 300, 'monto' => 300, 'estado' => 'VALIDO']);

        $base = '/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=';
        $rowA = collect($this->getJson($base . $sucA->id)->json('data'))->firstWhere('producto_id', $p->id);
        $rowB = collect($this->getJson($base . $sucB->id)->json('data'))->firstWhere('producto_id', $p->id);

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertEquals($rowA['vendido'], $rowB['vendido'], 'vendido idéntico (6).');
        $this->assertEquals($rowA['utilidad'], $rowB['utilidad'], 'utilidad idéntica.');
        $this->assertEquals($rowA['rotacion'], $rowB['rotacion'], 'rotación idéntica.');
        // Sanidad de los valores absolutos: utilidad = ingreso 600 - cogs (6*40=240) = 360.
        $this->assertEquals(360, $rowA['utilidad'], 'utilidad = 600 - 240.');
    }

    /**
     * rotacionSucursal: la rotación NUNCA debe superar 100% aunque se venda más de lo
     * disponible (cap por diseño con min(100,...)). Confirma que no hay overflow ni
     * valores absurdos (>100) en el número mostrado.
     */
    public function test_rotacion_sucursal_cap_100(): void
    {
        $this->actingAsUser('ADMIN');
        $sucA = Sucursal::factory()->create();
        $p = Producto::factory()->create();

        // Compra 5, vende 8 (más de lo disponible: arrastre de stock previo). rot capada a 100.
        $this->compraConDetalle($sucA->id, $p->id, 5, 40, '2026-01-01');
        $v = Venta::factory()->valido()->create(['sucursal_id' => $sucA->id, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-02-01', 'total' => 800]);
        Ventadetalle::factory()->create(['venta_id' => $v->id, 'producto_id' => $p->id, 'cantidad' => 8, 'costo' => 100, 'p_comp' => 40, 'subtotal' => 800, 'monto' => 800, 'estado' => 'VALIDO']);

        $row = collect($this->getJson('/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=' . $sucA->id)->json('data'))->firstWhere('producto_id', $p->id);
        $this->assertNotNull($row);
        $this->assertLessThanOrEqual(100, $row['rotacion'], 'rotación capada a 100%.');
    }

    /**
     * El resumen agregado de rotacionSucursal debe cuadrar con la suma de las filas:
     * entrada_total = Σ entrada, vendido_total = Σ vendido, utilidad_total = Σ utilidad.
     * (conservación entre detalle y total — un error de acumulación aquí corrompe el KPI).
     */
    public function test_rotacion_sucursal_resumen_cuadra_con_filas(): void
    {
        $this->actingAsUser('ADMIN');
        $sucA = Sucursal::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();

        $this->compraConDetalle($sucA->id, $p1->id, 10, 40, '2026-01-01');
        $this->compraConDetalle($sucA->id, $p2->id, 20, 30, '2026-01-01');
        foreach ([[$p1->id, 4, 100, 40], [$p2->id, 10, 80, 30]] as [$pid, $cant, $precio, $pcomp]) {
            $v = Venta::factory()->valido()->create(['sucursal_id' => $sucA->id, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-02-01', 'total' => $cant * $precio]);
            Ventadetalle::factory()->create(['venta_id' => $v->id, 'producto_id' => $pid, 'cantidad' => $cant, 'costo' => $precio, 'p_comp' => $pcomp, 'subtotal' => $cant * $precio, 'monto' => $cant * $precio, 'estado' => 'VALIDO']);
        }

        $res = $this->getJson('/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=' . $sucA->id)->json();
        $data = collect($res['data']);
        $this->assertEqualsWithDelta($data->sum('entrada'), (float) $res['resumen']['entrada_total'], 0.01, 'entrada_total = Σ entrada.');
        $this->assertEqualsWithDelta($data->sum('vendido'), (float) $res['resumen']['vendido_total'], 0.01, 'vendido_total = Σ vendido.');
        $this->assertEqualsWithDelta($data->sum('utilidad'), (float) $res['resumen']['utilidad_total'], 0.01, 'utilidad_total = Σ utilidad.');
    }

    /**
     * DEFECTO POTENCIAL (money displayed): cuando una venta se DEVUELVE parcialmente,
     * 'vendido' se neta contra la devolución, pero 'utilidad' (= ingreso - cogs) NO,
     * porque ingreso/cogs son SUM crudos de los renglones VALIDO sin restar devventas.
     * Resultado esperado correcto: la utilidad debería bajar proporcionalmente a las
     * unidades devueltas (igual criterio que rotacion() por compra, que SÍ neta).
     *
     * Escenario: compra 10 @ p_comp 40; vende 8 @ precio 100 (ingreso 800, cogs 320,
     * utilidad 480); devuelve 3 → vendido neto 5. La utilidad correcta = 5*(100-40)=300.
     *
     * Si la utilidad reportada sigue siendo 480 (sin netear la devolución), es un bug
     * de consistencia: 'vendido' dice 5 pero 'utilidad' corresponde a 8 vendidas.
     */
    public function test_rotacion_sucursal_utilidad_neta_devolucion(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sucA = Sucursal::factory()->create();
        $p = Producto::factory()->create();

        $this->compraConDetalle($sucA->id, $p->id, 10, 40, '2026-01-01');
        $v = $this->ventaConDetalle($sucA->id, $p->id, 8, 100, 40, '2026-02-01');

        // registro = id del RENGLÓN vendido (igual que el flujo real devItem en VentaController),
        // para que el neteo recupere su p_comp (40) y el COGS devuelto sea exacto: 3×40=120.
        $det = Ventadetalle::where('venta_id', $v->id)->where('producto_id', $p->id)->first();
        \App\Models\Devventa::create([
            'sucursal_id' => $sucA->id, 'venta_id' => $v->id, 'registro' => $det->id, 'tranza_id' => 0,
            'producto_id' => $p->id, 'codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'marca' => '',
            'costo' => 100, 'cantidad' => 3, 'total' => 300, 'estado' => 'ON', 'user_id' => $user->id,
        ]);

        $row = collect($this->getJson('/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=' . $sucA->id)->json('data'))->firstWhere('producto_id', $p->id);
        $this->assertNotNull($row);
        $this->assertEquals(5, $row['vendido'], 'vendido neto = 8 - 3 devueltas.');
        // Consistencia: utilidad debe corresponder a las 5 unidades netas, no a 8.
        $this->assertEquals(300, $row['utilidad'], 'utilidad debe netear la devolución: 5*(100-40)=300, no 8*60=480.');
    }

    /**
     * PRECISIÓN (estadísticas → exactitud, no aproximación): cuando el producto se vendió
     * en lotes con costo/precio DISTINTOS, netear la utilidad por el margen PROMEDIO del
     * período (prorrateo) da un número incorrecto; hay que netear con el ingreso y el COGS
     * REALES del renglón devuelto. devventas.registro apunta al ventadetalle original, así
     * que su p_comp exacto es recuperable.
     *
     * Escenario (entrada 10 para que la fila exista):
     *   Venta A: 2 u. @ precio 100, p_comp 40 → ingreso 200, cogs 80,  util 120
     *   Venta B: 2 u. @ precio 200, p_comp 150 → ingreso 400, cogs 300, util 100
     *   Bruto: vendido 4, ingreso 600, cogs 380, utilidad 220.
     *   Se DEVUELVEN las 2 u. de la venta B (las caras). Vendido neto = 2.
     *
     *   Exacto (correcto):  ingreso 600-400=200, cogs 380-300=80 → utilidad 120.
     *   Prorrateo (erróneo): 220 × (2/4) = 110.  ← el método viejo daba esto.
     */
    public function test_rotacion_sucursal_utilidad_neta_exacta_por_renglon(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sucA = Sucursal::factory()->create();
        $p = Producto::factory()->create();

        $this->compraConDetalle($sucA->id, $p->id, 10, 40, '2026-01-01');
        $this->ventaConDetalle($sucA->id, $p->id, 2, 100, 40, '2026-02-01');   // lote barato
        $vB = $this->ventaConDetalle($sucA->id, $p->id, 2, 200, 150, '2026-02-02'); // lote caro

        // Renglón original de la venta cara → registro de la devolución (recupera su p_comp 150).
        $dB = Ventadetalle::where('venta_id', $vB->id)->where('producto_id', $p->id)->first();
        \App\Models\Devventa::create([
            'sucursal_id' => $sucA->id, 'venta_id' => $vB->id, 'registro' => $dB->id, 'tranza_id' => 0,
            'producto_id' => $p->id, 'codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'marca' => '',
            'costo' => 200, 'cantidad' => 2, 'total' => 400, 'estado' => 'ON', 'user_id' => $user->id,
        ]);

        $row = collect($this->getJson('/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=' . $sucA->id)->json('data'))->firstWhere('producto_id', $p->id);
        $this->assertNotNull($row);
        $this->assertEquals(2, $row['vendido'], 'vendido neto = 4 - 2 devueltas.');
        // EXACTO = 120. El prorrateo por margen promedio daría 110 (incorrecto).
        $this->assertEquals(120, $row['utilidad'], 'utilidad exacta por renglón: 600-400 ingreso, 380-300 cogs = 120 (no 110 del prorrateo).');
    }

    /**
     * topClientes: el orden por 'ventas' (N° de ventas) y por 'monto' (Bs) deben
     * diferir cuando los datos lo justifican. Cliente A: 3 ventas de 10 (monto 30);
     * cliente B: 1 venta de 1000 (monto 1000). Por ventas gana A, por monto gana B.
     * Confirma que el orderCol se aplica de verdad (no siempre 'monto').
     */
    public function test_top_clientes_ordena_por_metrica_correcta(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid = $user->sucursal_id;
        $a = Cuenta::factory()->cliente()->create(['nombre' => 'CLIENTE A FRECUENTE']);
        $b = Cuenta::factory()->cliente()->create(['nombre' => 'CLIENTE B GRANDE']);

        foreach (range(1, 3) as $i) {
            Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => $a->id, 'fecha' => '2026-05-0' . $i, 'total' => 10]);
        }
        Venta::factory()->valido()->create(['sucursal_id' => $sid, 'cuenta_id' => $b->id, 'fecha' => '2026-05-05', 'total' => 1000]);

        $base = '/api/estadisticas/top-clientes?tcDesde=2026-01-01&tcHasta=2026-12-31&take=100&tcMet=';
        $porVentas = collect($this->getJson($base . 'ventas')->json('data'));
        $this->assertSame('CLIENTE A FRECUENTE', $porVentas->first()['cliente'], 'Por N° de ventas, A (3) primero.');

        $porMonto = collect($this->getJson($base . 'monto')->json('data'));
        $this->assertSame('CLIENTE B GRANDE', $porMonto->first()['cliente'], 'Por monto, B (Bs.1000) primero.');
    }

    /**
     * rotacionDetalle: compra inexistente → 404 limpio (no 500 ni fuga). Id enorme
     * (overflow de int) → 404, no 500. Contrato de la ruta {compra}.
     */
    public function test_rotacion_detalle_compra_inexistente_404(): void
    {
        $this->actingAsUser('ADMIN');
        $this->getJson('/api/estadisticas/rotacion-detalle/999999999')->assertStatus(404);
    }
}
