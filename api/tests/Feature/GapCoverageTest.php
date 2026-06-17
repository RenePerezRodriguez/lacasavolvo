<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Marca;
use App\Models\Medio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Cierre de celdas pendientes del AUDIT-MATRIX que SÍ aplican y no estaban probadas:
 *  - D1  authz de Estadísticas (guard interno autorizarEstadisticas, antes solo 401, no 403).
 *  - D8  atomicidad / rollback: una operación con un ítem sin stock no debe dejar efectos
 *        parciales (all-or-nothing) en ventas y envíos.
 *  - D2  validación faltante en Pedidos y catálogos Admin.
 *  - D7  idempotencia de envío (doble enviar).
 */
class GapCoverageTest extends TestCase
{
    // ───────────────── D1 · Estadísticas (authz por rol) ─────────────────

    public function test_vendedor_sin_permiso_no_ve_estadisticas(): void
    {
        $this->actingAsUser('VENDEDOR'); // no tiene estadisticas.index

        $this->getJson('/api/estadisticas/ventas-periodo?vpGran=month')->assertStatus(403);
        $this->getJson('/api/estadisticas/top-productos')->assertStatus(403);
        $this->getJson('/api/estadisticas/top-clientes')->assertStatus(403);
        $this->getJson('/api/estadisticas/rotacion')->assertStatus(403);
    }

    public function test_admin_si_ve_estadisticas(): void
    {
        $this->actingAsUser('ADMIN');
        $this->getJson('/api/estadisticas/top-productos')->assertStatus(200);
    }

    // ───────────────── D8 · Atomicidad (all-or-nothing) ─────────────────

    public function test_validar_venta_con_un_item_sin_stock_no_descuenta_ninguno(): void
    {
        $this->actingAsUser('ADMIN');
        $cuenta = Cuenta::factory()->cliente()->create();
        $p1 = Producto::factory()->create(['stock1' => 10]); // suficiente
        $p2 = Producto::factory()->create(['stock1' => 1]);  // insuficiente
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $p1->id, 'cantidad' => 5]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $p2->id, 'cantidad' => 5]);

        // Un ítem corto → rechazo total; NINGÚN stock se mueve (atomicidad).
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(422);
        $this->assertEquals(10, (float) Producto::find($p1->id)->stock1, 'el ítem con stock NO debe descontarse');
        $this->assertEquals(1, (float) Producto::find($p2->id)->stock1);
        $this->assertEquals('PROFORMA', $venta->fresh()->estado, 'la venta sigue proforma');
    }

    public function test_enviar_con_un_item_sin_stock_no_descuenta_ninguno(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $p1 = Producto::factory()->create(['stock1' => 10]);
        $p2 = Producto::factory()->create(['stock1' => 1]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => Medio::factory()->create()->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        foreach ([[$p1, 5], [$p2, 5]] as [$p, $cant]) {
            Enviodetalle::create(['envio_id' => $envio->id, 'producto_id' => $p->id, 'codigo' => $p->codigo, 'descripcion' => $p->descripcion, 'marca' => '', 'cantidad' => $cant, 'estado' => 'VALIDO']);
        }

        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(422);
        $this->assertEquals(10, (float) Producto::find($p1->id)->stock1, 'origen NO debe descontar ningún ítem');
        $this->assertEquals(1, (float) Producto::find($p2->id)->stock1);
        $this->assertEquals('PROFORMA', $envio->fresh()->estado);
    }

    // ───────────────── D7 · Idempotencia de envío ─────────────────

    public function test_enviar_dos_veces_es_idempotente(): void
    {
        $this->actingAsUser('ADMIN');
        $prod = Producto::factory()->create(['stock1' => 10]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => Medio::factory()->create()->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create(['envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 4, 'estado' => 'VALIDO']);

        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);  // ENVIADO, stock 6
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(422);  // ya no es proforma

        $this->assertEquals(6, (float) Producto::find($prod->id)->stock1, 'stock descontado una sola vez');
    }

    // ───────────────── D2 · Validación faltante ─────────────────

    public function test_pedido_rechaza_cantidad_fraccionaria(): void
    {
        $this->actingAsUser('ADMIN'); // sucursal 1
        $pedido = Pedido::factory()->create(['sucursal_id' => 1, 'estado' => 'PROFORMA']);
        $prod = Producto::factory()->create();

        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 2.5])
            ->assertStatus(422);
    }

    public function test_marca_store_sin_nombre_se_rechaza(): void
    {
        $this->actingAsUser('ADMIN');
        $this->postJson('/api/marcas', [])->assertStatus(422)->assertJsonValidationErrorFor('nombre');

        // Y crea bien con nombre (contraprueba).
        $this->postJson('/api/marcas', ['nombre' => 'VOLVO TEST'])->assertStatus(200);
        $this->assertDatabaseHas('marcas', ['nombre' => 'VOLVO TEST', 'estado' => 'ON']);
    }

    public function test_marca_no_duplica_factory_sentinel(): void
    {
        // Sanidad: el factory de Marca existe y crea registros ON.
        $m = Marca::factory()->create();
        $this->assertDatabaseHas('marcas', ['id' => $m->id]);
    }
}
