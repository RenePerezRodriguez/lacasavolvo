<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Compradetalle;
use App\Models\Cuenta;
use App\Models\Devventa;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstadisticasTest extends TestCase
{
    public function test_ventas_periodo_devuelve_estructura(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/estadisticas/ventas-periodo?vpGran=month');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_ventas_periodo_gran_invalido_usa_month(): void
    {
        $this->actingAsUser();

        // El parámetro real es vpGran. Un valor fuera del whitelist ('year', o algo
        // inyectable como "day; DROP TABLE ventas") debe caer a 'month' y retornar 200.
        $response = $this->getJson('/api/estadisticas/ventas-periodo?vpGran=year');

        $response->assertStatus(200); // whitelist convierte a 'month', no explota
    }

    public function test_top_productos_devuelve_estructura(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/estadisticas/top-productos');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_top_clientes_devuelve_estructura(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/estadisticas/top-clientes');

        $response->assertStatus(200)
            ->assertJsonStructure(['total', 'data', 'mostrador' => ['ventas', 'monto']]);
    }

    public function test_top_clientes_excluye_mostrador_del_ranking(): void
    {
        $user = $this->actingAsUser();

        // Cuenta de mostrador (id 6 = "SIN NOMBRE") + un cliente real con nombre propio.
        DB::table('cuentas')->updateOrInsert(['id' => 6], [
            'nombre' => 'SIN NOMBRE', 'nit' => '0', 'empresa_id' => 1, 'localidad_id' => 1,
            'tipo' => 'CLIENTE', 'telefono' => '0', 'direccion' => 'Mostrador',
            'departamento' => 'COCHABAMBA', 'saldo' => 0, 'estado' => 'ON',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $real = Cuenta::factory()->cliente()->create(['nombre' => 'CLIENTE REAL TEST']);

        Venta::factory()->valido()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => 6, 'total' => 5000]);
        Venta::factory()->valido()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $real->id, 'total' => 100]);

        $resp = $this->getJson('/api/estadisticas/top-clientes?tcDesde=2018-01-01&tcHasta=2030-12-31');
        $resp->assertStatus(200);

        $nombres = collect($resp->json('data'))->pluck('cliente');
        $this->assertFalse($nombres->contains('SIN NOMBRE'), 'El mostrador NO debe aparecer en el ranking.');
        $this->assertTrue($nombres->contains('CLIENTE REAL TEST'), 'El cliente real SÍ debe aparecer.');
        $this->assertGreaterThanOrEqual(5000, (float) $resp->json('mostrador.monto'), 'El monto del mostrador se reporta aparte.');
    }

    public function test_rotacion_devuelve_estructura(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/estadisticas/rotacion');

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
    }

    /**
     * FIFO con devolución de venta: la devolución debe bajar 'vendidos' Y 'utilidad'
     * de forma proporcional, y la lista de rotación y el detalle deben coincidir.
     *
     * Escenario: compra 10 u. a costo 50; venta de 8 u. a precio 80 (margen 30/u);
     * devolución de 3 u. → quedan 5 vendidas, utilidad 5×30 = 150, rotación 5/10 = 50%.
     */
    public function test_rotacion_descuenta_utilidad_en_devolucion_y_coincide_lista_detalle(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $sid  = $user->sucursal_id;

        $producto = Producto::factory()->create();

        $compra = Compra::factory()->valido()->create(['sucursal_id' => $sid, 'fecha' => '2026-01-01']);
        Compradetalle::create([
            'compra_id' => $compra->id, 'producto_id' => $producto->id,
            'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '',
            'costo' => 50, 'p_comp' => 50, 'p_norm' => 80, 'p_fact' => 90,
            'cantidad' => 10, 'monto' => 500, 'descuento' => 0, 'subtotal' => 500,
            'user_id' => $user->id, 'estado' => 'VALIDO',
        ]);

        $venta = Venta::factory()->valido()->create([
            'sucursal_id' => $sid, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-02-01', 'total' => 640,
        ]);
        Ventadetalle::factory()->create([
            'venta_id' => $venta->id, 'producto_id' => $producto->id,
            'cantidad' => 8, 'subtotal' => 640, 'costo' => 80, 'p_comp' => 50,
            'monto' => 640, 'descuento' => 0, 'estado' => 'VALIDO',
        ]);

        // Devolución de 3 unidades de esa venta.
        Devventa::create([
            'sucursal_id' => $sid, 'venta_id' => $venta->id, 'registro' => $venta->id, 'tranza_id' => 0, 'producto_id' => $producto->id,
            'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '',
            'costo' => 80, 'cantidad' => 3, 'total' => 240, 'estado' => 'ON', 'user_id' => $user->id,
        ]);

        // ── Lista de rotación ──
        $lista = $this->getJson('/api/estadisticas/rotacion?rotDesde=2026-01-01&rotHasta=2026-12-31&rotCorte=2026-12-31&rotSucursal=0&take=100');
        $lista->assertStatus(200);
        $fila = collect($lista->json('data'))->firstWhere('compra_id', '#' . $compra->id);
        $this->assertNotNull($fila, 'La compra debe aparecer en la lista de rotación.');
        $this->assertSame(5, $fila['ventas'], 'vendidos = 8 - 3 devueltos.');
        $this->assertSame(50, $fila['rotacion'], 'rotación = 5/10.');
        $this->assertSame('Bs. 150.00', $fila['utilidad'], 'utilidad = 5×30, NO 8×30 (debe restar la devolución).');

        // ── Detalle de la misma compra: debe coincidir con la fila ──
        $detalle = $this->getJson('/api/estadisticas/rotacion-detalle/' . $compra->id . '?fecha_corte=2026-12-31');
        $detalle->assertStatus(200);
        $item = collect($detalle->json('items'))->firstWhere('id', $producto->id);
        $this->assertNotNull($item, 'El producto debe aparecer en el detalle.');
        $this->assertEquals(5, $item['vendidos'], 'El detalle debe coincidir con la lista (5).');
        $this->assertEquals(50, $item['rotacion'], 'El detalle debe coincidir con la lista (50%).');
        $this->assertEquals(150, $item['utilidad'], 'El detalle debe coincidir con la lista (150).');
    }

    /**
     * El filtro de sucursal alterna entre vista GLOBAL (toda la red) y POR SUCURSAL.
     * Una venta hecha en una sucursal distinta a la de la compra:
     *   - cuenta en la vista global (FIFO de toda la red),
     *   - NO cuenta al filtrar por la sucursal de la compra (FIFO acotado).
     */
    public function test_rotacion_global_vs_por_sucursal(): void
    {
        $user = $this->actingAsUser('ADMIN');

        $producto = Producto::factory()->create();
        $sucA = Sucursal::factory()->create();   // la compra vive acá
        $sucB = Sucursal::factory()->create();   // la venta ocurre en otra sucursal

        $compra = Compra::factory()->valido()->create(['sucursal_id' => $sucA->id, 'fecha' => '2026-01-01']);
        Compradetalle::create([
            'compra_id' => $compra->id, 'producto_id' => $producto->id,
            'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '',
            'costo' => 50, 'p_comp' => 50, 'p_norm' => 80, 'p_fact' => 90,
            'cantidad' => 10, 'monto' => 500, 'descuento' => 0, 'subtotal' => 500,
            'user_id' => $user->id, 'estado' => 'VALIDO',
        ]);

        $venta = Venta::factory()->valido()->create([
            'sucursal_id' => $sucB->id, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-02-01', 'total' => 320,
        ]);
        Ventadetalle::factory()->create([
            'venta_id' => $venta->id, 'producto_id' => $producto->id,
            'cantidad' => 4, 'subtotal' => 320, 'costo' => 80, 'p_comp' => 50,
            'monto' => 320, 'descuento' => 0, 'estado' => 'VALIDO',
        ]);

        $base = '/api/estadisticas/rotacion?rotDesde=2026-01-01&rotHasta=2026-12-31&rotCorte=2026-12-31&take=100';

        // Global (toda la red): la venta de sucB SÍ se atribuye a la compra de sucA.
        $global = $this->getJson($base . '&rotSucursal=0');
        $filaG  = collect($global->json('data'))->firstWhere('compra_id', '#' . $compra->id);
        $this->assertNotNull($filaG, 'La compra debe aparecer en la vista global.');
        $this->assertSame(4, $filaG['ventas'], 'Global: la venta de otra sucursal SÍ cuenta.');

        // Por sucursal A: no hubo ventas en sucA → 0 (la venta de sucB no cuenta acá).
        $porSuc = $this->getJson($base . '&rotSucursal=' . $sucA->id);
        $filaS  = collect($porSuc->json('data'))->firstWhere('compra_id', '#' . $compra->id);
        $this->assertNotNull($filaS, 'La compra de sucA debe aparecer al filtrar por sucA.');
        $this->assertSame(0, $filaS['ventas'], 'Por sucursal A: la venta de sucB NO cuenta.');
    }

    /**
     * Rotación POR SUCURSAL con traslado: lo recibido por envío cuenta como entrada en el
     * DESTINO (cuenta_id) y como despachado en el ORIGEN (sucursal_id). Esto es justo lo
     * que el reporte por compra no ve, y la dirección que el código viejo tenía al revés.
     *
     * Escenario: sucA compra 10; envía 4 a sucB (RECIBIDO); sucA vende 5; sucB vende 3.
     *   sucA → comprado 10, despachado 4, disponible 6, vendido 5, rot 83.3%
     *   sucB → recibido 4, disponible 4, vendido 3, rot 75%
     */
    public function test_rotacion_sucursal_considera_traslados(): void
    {
        $user = $this->actingAsUser('ADMIN');

        $producto = Producto::factory()->create();
        $sucA = Sucursal::factory()->create();
        $sucB = Sucursal::factory()->create();

        // sucA compra 10
        $compra = Compra::factory()->valido()->create(['sucursal_id' => $sucA->id, 'fecha' => '2026-01-01']);
        Compradetalle::create([
            'compra_id' => $compra->id, 'producto_id' => $producto->id,
            'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '',
            'costo' => 40, 'p_comp' => 40, 'p_norm' => 80, 'p_fact' => 90,
            'cantidad' => 10, 'monto' => 400, 'descuento' => 0, 'subtotal' => 400,
            'user_id' => $user->id, 'estado' => 'VALIDO',
        ]);

        // sucA envía 4 a sucB (origen=sucursal_id, destino=cuenta_id), recibido
        $envio = Envio::factory()->create(['sucursal_id' => $sucA->id, 'cuenta_id' => $sucB->id, 'estado' => 'RECIBIDO', 'fecha' => '2026-01-15']);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $producto->id,
            'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '',
            'cantidad' => 4, 'estado' => 'VALIDO',
        ]);

        // ventas: sucA vende 5, sucB vende 3
        foreach ([[$sucA->id, 5], [$sucB->id, 3]] as [$sucVenta, $cant]) {
            $venta = Venta::factory()->valido()->create([
                'sucursal_id' => $sucVenta, 'cuenta_id' => Cuenta::factory()->cliente(), 'fecha' => '2026-02-01', 'total' => $cant * 80,
            ]);
            Ventadetalle::factory()->create([
                'venta_id' => $venta->id, 'producto_id' => $producto->id,
                'cantidad' => $cant, 'subtotal' => $cant * 80, 'costo' => 80, 'p_comp' => 40,
                'monto' => $cant * 80, 'descuento' => 0, 'estado' => 'VALIDO',
            ]);
        }

        $base = '/api/estadisticas/rotacion-sucursal?rsDesde=2026-01-01&rsHasta=2026-12-31&rsSucursal=';

        // ── Sucursal A (origen): compró y despachó ──
        $a = $this->getJson($base . $sucA->id);
        $a->assertStatus(200);
        $rowA = collect($a->json('data'))->firstWhere('producto_id', $producto->id);
        $this->assertNotNull($rowA, 'El producto debe aparecer en la rotación de sucA.');
        $this->assertEquals(10, $rowA['comprado']);
        $this->assertEquals(0,  $rowA['recibido'], 'sucA no recibió por traslado.');
        $this->assertEquals(4,  $rowA['despachado'], 'sucA despachó 4 (origen del envío).');
        $this->assertEquals(6,  $rowA['disponible'], 'disponible = entrada 10 - despachado 4.');
        $this->assertEquals(5,  $rowA['vendido']);
        $this->assertEquals(83.3, $rowA['rotacion']);

        // ── Sucursal B (destino): recibió por traslado ──
        $b = $this->getJson($base . $sucB->id);
        $b->assertStatus(200);
        $rowB = collect($b->json('data'))->firstWhere('producto_id', $producto->id);
        $this->assertNotNull($rowB, 'El producto debe aparecer en la rotación de sucB.');
        $this->assertEquals(0, $rowB['comprado']);
        $this->assertEquals(4, $rowB['recibido'], 'sucB recibió 4 (destino = cuenta_id).');
        $this->assertEquals(0, $rowB['despachado']);
        $this->assertEquals(3, $rowB['vendido']);
        $this->assertEquals(75, $rowB['rotacion']);
    }

    public function test_rotacion_sucursal_sin_sucursal_devuelve_422(): void
    {
        $this->actingAsUser('ADMIN');
        $this->getJson('/api/estadisticas/rotacion-sucursal')->assertStatus(422);
    }

    public function test_estadisticas_sin_auth_devuelve_401(): void
    {
        $this->getJson('/api/estadisticas/top-productos')->assertStatus(401);
    }
}
