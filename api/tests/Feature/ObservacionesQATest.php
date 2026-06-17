<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Medio;
use App\Models\Producto;
use App\Models\Envio;
use App\Models\User;
use App\Models\Cuenta;
use App\Models\Compra;
use App\Models\Compradetalle;
use App\Models\Venta;
use App\Models\Ventadetalle;
use Tests\TestCase;

/**
 * Auditoría dirigida por las OBSERVACIONES DE QA (Tefy Garro, 16/06/2026) sobre el
 * sistema nuevo de La Casa Volvo. Cubre los dos hallazgos de AUTORIZACIÓN:
 *
 *  - #1 (ALTA) Fuga de COSTOS al rol VENDEDOR: el listado/quicksearch/detalle de
 *    productos y el KPI `valor_inventario` exponían `p_comp` (costo) y el valor de
 *    inventario a roles que NO pueden ver costos. Debe gatearse con `costos.ver`
 *    (respetando la simulación de roles), no por nombre de rol.
 *  - #2 (ALTA) Frontera de sucursal en ENVÍOS: solo la sucursal ORIGEN edita un
 *    envío en PROFORMA; el destino (o cualquier otra) lo ve en SOLO-LECTURA. El
 *    backend ya lo bloquea (abort_if 403) — este test FIJA esa frontera como
 *    regresión y verifica el flag `puede_editar` que el front usa para no mostrar
 *    los controles de edición.
 */
class ObservacionesQATest extends TestCase
{
    /**
     * Crea un usuario actuando en la sucursal indicada con su Acceso ON (para probar
     * fronteras con identidades distintas; `actingAsUser` siempre usa la sucursal 1).
     *
     * @param  int     $sucursalId  Sucursal activa del usuario.
     * @param  string  $role        Rol Spatie a asignar.
     * @return User
     */
    private function actingInSucursal(int $sucursalId, string $role = 'ADMIN'): User
    {
        $this->actingAsUser($role); // garantiza roles/permisos sembrados
        $user = User::factory()->create(['sucursal_id' => $sucursalId]);
        $user->assignRole($role);
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => $sucursalId, 'estado' => 'ON']);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    // ───────────────────────── #1 · Fuga de costos a VENDEDOR ─────────────────────────

    /**
     * Un VENDEDOR (sin `costos.ver`) NO debe recibir `p_comp` (costo) en el LISTADO de
     * productos. Antes viajaba el costo en el payload aunque el front ocultara la columna.
     */
    public function test_vendedor_no_recibe_costo_en_el_listado_de_productos(): void
    {
        $this->actingAsUser('VENDEDOR');
        Producto::factory()->create(['codigo' => 'COSTO-LEAK-1', 'p_comp' => 123.45, 'p_norm' => 200, 'p_fact' => 230, 'estado' => 'ON', 'stock1' => 10]);

        $res = $this->getJson('/api/productos?search=COSTO-LEAK-1')->assertStatus(200);

        $item = collect($res->json('data'))->firstWhere('codigo', 'COSTO-LEAK-1');
        $this->assertNotNull($item, 'el producto debe aparecer en el listado');
        $this->assertNull($item['p_comp'], 'el costo (p_comp) NO debe exponerse a un VENDEDOR');
        // Los precios de VENTA (sin/con factura) sí se exponen: el vendedor los necesita.
        $this->assertNotNull($item['p_norm']);
        $this->assertNotNull($item['p_fact']);
    }

    /**
     * Mismo gate en el quicksearch (el modal de productos de toda la app).
     */
    public function test_vendedor_no_recibe_costo_en_quicksearch(): void
    {
        $this->actingAsUser('VENDEDOR');
        Producto::factory()->create(['codigo' => 'COSTO-LEAK-2', 'p_comp' => 99.99, 'p_norm' => 150, 'p_fact' => 170, 'estado' => 'ON', 'stock1' => 5]);

        $res = $this->getJson('/api/productos/quicksearch?search=COSTO-LEAK-2')->assertStatus(200);
        $item = collect($res->json())->firstWhere('codigo', 'COSTO-LEAK-2');

        $this->assertNotNull($item);
        $this->assertNull($item['p_comp'], 'el costo NO debe exponerse en quicksearch a un VENDEDOR');
    }

    /**
     * Y en el detalle (`show`): el costo viaja null para el VENDEDOR.
     */
    public function test_vendedor_no_recibe_costo_en_detalle_de_producto(): void
    {
        $this->actingAsUser('VENDEDOR');
        $prod = Producto::factory()->create(['p_comp' => 77.00, 'p_norm' => 120, 'p_fact' => 140, 'estado' => 'ON', 'stock1' => 3]);

        $res = $this->getJson("/api/productos/{$prod->id}")->assertStatus(200);
        $this->assertNull($res->json('p_comp'), 'el costo NO debe exponerse en el detalle a un VENDEDOR');
    }

    /**
     * El KPI `valor_inventario` (deriva del precio/costo) viaja null para el VENDEDOR
     * → el front oculta la tarjeta. Los conteos (activos/sin_stock) sí se exponen.
     */
    public function test_vendedor_no_ve_valor_de_inventario_en_kpis(): void
    {
        $this->actingAsUser('VENDEDOR');
        Producto::factory()->create(['p_norm' => 500, 'estado' => 'ON', 'stock1' => 10]);

        $res = $this->getJson('/api/productos/kpis')->assertStatus(200);
        $res->assertJsonStructure(['activos', 'descontinuados', 'sin_stock', 'stock_critico', 'valor_inventario']);
        $this->assertNull($res->json('valor_inventario'), 'el valor de inventario NO debe verlo un VENDEDOR');
    }

    /**
     * Contraprueba: ADMIN SÍ ve el costo y el valor de inventario (no se rompió el caso
     * legítimo al cerrar la fuga).
     */
    public function test_admin_si_ve_costo_y_valor_de_inventario(): void
    {
        $this->actingAsUser('ADMIN');
        Producto::factory()->create(['codigo' => 'ADMIN-COSTO-1', 'p_comp' => 321.00, 'p_norm' => 400, 'p_fact' => 450, 'estado' => 'ON', 'stock1' => 8]);

        $lista = $this->getJson('/api/productos?search=ADMIN-COSTO-1')->assertStatus(200);
        $item  = collect($lista->json('data'))->firstWhere('codigo', 'ADMIN-COSTO-1');
        $this->assertNotNull($item['p_comp'], 'ADMIN sí debe ver el costo');

        $kpis = $this->getJson('/api/productos/kpis')->assertStatus(200);
        $this->assertNotNull($kpis->json('valor_inventario'), 'ADMIN sí debe ver el valor de inventario');
    }

    // ──────────────────── #2 · Frontera de sucursal en ENVÍOS ────────────────────

    /**
     * Crea un envío 1→2 (origen sucursal 1, destino sucursal 2).
     *
     * @return Envio
     */
    private function envio12(): Envio
    {
        return Envio::factory()->create([
            'sucursal_id' => 1,
            'cuenta_id'   => 2,
            'medio_id'    => Medio::factory()->create()->id,
            'estado'      => 'PROFORMA',
            'pagado'      => 'PAGADO',
            'monto'       => 0,
        ]);
    }

    /**
     * El DESTINO de un envío (sucursal 2) NO puede editar el encabezado ni agregar
     * ítems a un envío que pertenece al ORIGEN (sucursal 1): 403. Es la frontera que
     * Tefy reportó (el "encargado de ventas" de otra sucursal podía editar en la UI).
     */
    public function test_destino_no_puede_editar_envio_del_origen(): void
    {
        $envio = $this->envio12();
        $prod  = Producto::factory()->create(['estado' => 'ON', 'stock1' => 50]);

        // Usuario en la sucursal DESTINO (2). Incluso ADMIN: la frontera de envíos es por
        // sucursal, sin bypass de rol (el envío es propiedad del origen).
        $this->actingInSucursal(2, 'ADMIN');

        $this->postJson('/api/envios/agregar-item', [
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'cantidad' => 1,
        ])->assertStatus(403);

        $this->postJson('/api/envios/update-encabezado', [
            'envio_id' => $envio->id, 'cuenta_id' => 3, 'fecha' => now()->format('Y-m-d'), 'medio_id' => $envio->medio_id, 'monto' => 0,
        ])->assertStatus(403);
    }

    /**
     * El flag `puede_editar` del `show` refleja la frontera: false para el destino,
     * true para el origen (en PROFORMA). El front lo usa para no pintar los controles.
     */
    public function test_show_expone_puede_editar_segun_la_frontera(): void
    {
        $envio = $this->envio12();

        // Destino (sucursal 2): solo-lectura.
        $this->actingInSucursal(2, 'ADMIN');
        $this->getJson("/api/envios/{$envio->id}")
            ->assertStatus(200)
            ->assertJson(['puede_editar' => false, 'sucursal_id' => 1]);

        // Origen (sucursal 1): editable.
        $this->actingInSucursal(1, 'ADMIN');
        $this->getJson("/api/envios/{$envio->id}")
            ->assertStatus(200)
            ->assertJson(['puede_editar' => true]);
    }

    // ──────────────── #5 · Conteo público de sucursales para el login ────────────────

    /**
     * `/api/public-info` es PÚBLICO (sin token) y devuelve SOLO el conteo de sucursales
     * activas — reemplaza el "5 sucursales" hardcodeado del login. No expone nada
     * sensible (ni nombres ni NITs). En el fixture hay 5 sucursales ON.
     */
    public function test_public_info_devuelve_solo_el_conteo_de_sucursales_sin_token(): void
    {
        // Sin autenticar (vista pública del login).
        $res = $this->getJson('/api/public-info')->assertStatus(200);

        $res->assertJsonStructure(['sucursales']);
        $this->assertIsInt($res->json('sucursales'));
        $this->assertGreaterThanOrEqual(1, $res->json('sucursales'));
        // No debe filtrar estructura sensible.
        $this->assertNull($res->json('data'));
        $this->assertNull($res->json('0.nombre'));
    }

    // ──────────── #9 · Precio de referencia en movimientos (venta vs costo) ────────────

    /**
     * Crea, para un producto en la sucursal 1, UN movimiento de VENTA (precio de venta 250)
     * y UNO de COMPRA (costo 100), ambos validados. Devuelve el producto.
     *
     * @return Producto
     */
    private function productoConMovimientos(): Producto
    {
        $prod   = Producto::factory()->create(['estado' => 'ON', 'stock1' => 100]);
        $cuenta = Cuenta::factory()->create();

        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        Ventadetalle::factory()->create([
            'venta_id' => $venta->id, 'producto_id' => $prod->id,
            'costo' => 250, 'p_comp' => 0, 'cantidad' => 2,
            'monto' => 500, 'descuento' => 0, 'subtotal' => 500, 'estado' => 'VALIDO',
        ]);

        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO']);
        Compradetalle::create([
            'compra_id' => $compra->id, 'producto_id' => $prod->id,
            'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion, 'marca' => '',
            'costo' => 100, 'p_comp' => 100, 'p_norm' => 0, 'p_fact' => 0,
            'cantidad' => 5, 'monto' => 500, 'descuento' => 0, 'subtotal' => 500,
            'user_id' => 1, 'estado' => 'VALIDO',
        ]);

        return $prod;
    }

    /**
     * El VENDEDOR ve el precio de VENTA en movimientos (no es un costo), pero NO el costo
     * de COMPRA (que sí es sensible). Antes la columna entera se ocultaba a quien no veía
     * costos → Tefy reportó que "faltaba la columna" (el sistema viejo la mostraba).
     */
    public function test_movimientos_vendedor_ve_precio_de_venta_pero_no_costo_de_compra(): void
    {
        $prod = $this->productoConMovimientos();
        $this->actingAsUser('VENDEDOR');

        $res = $this->getJson("/api/productos/{$prod->id}/movimientos")->assertStatus(200);
        $data = collect($res->json('data'));

        $ven = $data->firstWhere('tipo', 'VEN');
        $com = $data->firstWhere('tipo', 'COM');

        $this->assertNotNull($ven, 'debe existir el movimiento de venta');
        $this->assertEquals(250.0, $ven['costo'], 'el vendedor SÍ ve el precio de venta del movimiento');
        $this->assertNotNull($com, 'debe existir el movimiento de compra');
        $this->assertNull($com['costo'], 'el vendedor NO ve el costo de compra');
    }

    /**
     * Contraprueba: ADMIN ve ambos (precio de venta y costo de compra).
     */
    public function test_movimientos_admin_ve_precio_de_venta_y_costo_de_compra(): void
    {
        $prod = $this->productoConMovimientos();
        $this->actingAsUser('ADMIN');

        $res = $this->getJson("/api/productos/{$prod->id}/movimientos")->assertStatus(200);
        $data = collect($res->json('data'));

        $this->assertEquals(250.0, $data->firstWhere('tipo', 'VEN')['costo']);
        $this->assertEquals(100.0, $data->firstWhere('tipo', 'COM')['costo'], 'ADMIN sí ve el costo de compra');
    }

    // ──────────── #1 (legacy) · Costos en COMPRAS solo para ADMIN/GERENTE ────────────

    /**
     * Crea una compra validada (sucursal 1) con un ítem de costo 100.
     *
     * @return Compra
     */
    private function compraValidadaConItem(): Compra
    {
        $prod   = Producto::factory()->create(['estado' => 'ON']);
        $cuenta = Cuenta::factory()->create();
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'estado' => 'VALIDO', 'total' => 500]);
        Compradetalle::create([
            'compra_id' => $compra->id, 'producto_id' => $prod->id,
            'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion, 'marca' => '',
            'costo' => 100, 'p_comp' => 100, 'p_norm' => 0, 'p_fact' => 0,
            'cantidad' => 5, 'monto' => 500, 'descuento' => 0, 'subtotal' => 500,
            'user_id' => 1, 'estado' => 'VALIDO',
        ]);
        return $compra;
    }

    /**
     * FIEL AL LEGACY: en Compras el VENDEDOR SÍ ve el costo por ítem y su subtotal. Las
     * vistas de lectura del legacy (compras/show.blade.php, index.blade.php) muestran
     * costo/subtotal/monto/total SIN `@role` — Compras es un módulo de costos por naturaleza.
     * El ocultamiento de costos aplica al RESTO (productos/movimientos), no a Compras.
     */
    public function test_compras_vendedor_si_ve_costo_por_item(): void
    {
        $compra = $this->compraValidadaConItem();
        $this->actingAsUser('VENDEDOR');

        $res  = $this->getJson("/api/compras/{$compra->id}/detalles")->assertStatus(200);
        $item = collect($res->json())->first();

        $this->assertNotNull($item, 'el vendedor ve los ítems de la compra');
        $this->assertEquals(100.0, $item['costo'], 'en Compras el vendedor SÍ ve el costo por ítem (legacy)');
        $this->assertNotNull($item['subtotal'], 'y su subtotal');
    }

    /**
     * Contraprueba: ADMIN sí ve el costo por ítem y su subtotal en compras.
     */
    public function test_compras_admin_si_ve_costo_por_item(): void
    {
        $compra = $this->compraValidadaConItem();
        $this->actingAsUser('ADMIN');

        $res  = $this->getJson("/api/compras/{$compra->id}/detalles")->assertStatus(200);
        $item = collect($res->json())->first();

        $this->assertEquals(100.0, $item['costo'], 'ADMIN sí ve el costo por ítem');
        $this->assertNotNull($item['subtotal']);
    }
}
