<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Apertura;
use App\Models\Cierre;
use App\Models\Cotizacion;
use App\Models\Compra;
use App\Models\Cuenta;
use App\Models\Devcompra;
use App\Models\Devventa;
use App\Models\Empresa;
use App\Models\Envio;
use App\Models\Marca;
use App\Models\Medio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Tranza;
use App\Models\User;
use App\Models\Venta;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Barrido de cobertura: ejercita los métodos MUTANTES que la suite por-módulo
 * no cubría (edición/eliminación de ítems, devoluciones de ida y vuelta,
 * ciclo de caja, escrituras de catálogos/usuarios/roles, toggles y ocultamiento
 * de montos por rol). Todo corre dentro de DatabaseTransactions (rollback).
 */
class CoberturaTest extends TestCase
{
    // ─────────────────────────── VENTAS ───────────────────────────

    public function test_venta_update_encabezado_cambia_cuenta_y_tipo(): void
    {
        $user   = $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();
        $venta  = Venta::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id,
            'estado' => 'PROFORMA', 'tipo' => 'CONTADO', 'total' => 0,
        ]);
        $otra = Cuenta::factory()->cliente()->create();

        $resp = $this->postJson('/api/ventas/update-encabezado', [
            'venta_id' => $venta->id, 'cuenta_id' => $otra->id,
            'tipo' => 'CREDITO', 'fecha' => now()->format('Y-m-d'),
        ]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'cuenta_id' => $otra->id, 'tipo' => 'CREDITO']);
    }

    public function test_venta_update_item_recalcula_subtotal(): void
    {
        $user     = $this->actingAsUser();
        $cuenta   = Cuenta::factory()->cliente()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'p_norm' => 5]);
        $venta    = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]);
        $detalleId = $venta->detalles()->first()->id;

        $resp = $this->postJson('/api/ventas/update-item', ['registro' => $detalleId, 'costo' => 10, 'cantidad' => 3]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('ventadetalles', ['id' => $detalleId, 'costo' => 10, 'cantidad' => 3, 'subtotal' => 30]);
    }

    public function test_venta_delete_item_anula_detalle(): void
    {
        $user     = $this->actingAsUser();
        $cuenta   = Cuenta::factory()->cliente()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $venta    = Venta::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]);
        $detalleId = $venta->detalles()->first()->id;

        $resp = $this->postJson("/api/ventas/delete-item/{$detalleId}");

        $resp->assertStatus(200);
        $this->assertDatabaseHas('ventadetalles', ['id' => $detalleId, 'estado' => 'ANULADO']);
    }

    public function test_venta_devolucion_ida_y_vuelta(): void
    {
        $user     = $this->actingAsUser();
        $cuenta   = Cuenta::factory()->cliente()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'p_norm' => 8]);
        $venta    = Venta::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id,
            'estado' => 'PROFORMA', 'tipo' => 'CONTADO',
        ]);
        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->assertEquals(6, Producto::find($producto->id)->stock1); // 10 - 4

        // Devolver 2 → stock vuelve a 8, se crea Devventa + Tranza D-VEN
        $this->postJson('/api/ventas/dev-item', [
            'venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2,
        ])->assertStatus(200);

        $this->assertEquals(8, Producto::find($producto->id)->stock1);
        $dev = Devventa::where('venta_id', $venta->id)->where('estado', 'ON')->first();
        $this->assertNotNull($dev);
        $this->assertDatabaseHas('tranzas', ['clase' => 'D-VEN', 'registro' => $venta->id, 'estado' => 'ON']);

        // Revertir la devolución → stock vuelve a 6, Devventa OFF
        $this->postJson('/api/ventas/delete-item-dev', ['registro' => $dev->id])->assertStatus(200);
        $this->assertEquals(6, Producto::find($producto->id)->stock1);
        $this->assertDatabaseHas('devventas', ['id' => $dev->id, 'estado' => 'OFF']);
    }

    // ─────────────────────────── COMPRAS ───────────────────────────

    public function test_compra_update_item_recalcula(): void
    {
        $user     = $this->actingAsUser();
        $prov     = Cuenta::factory()->proveedor()->create();
        $producto = Producto::factory()->create(['stock1' => 0]);
        $compra   = Compra::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $prov->id, 'estado' => 'PROFORMA']);
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 3]);
        $detalleId = $compra->detalles()->first()->id;

        $resp = $this->postJson('/api/compras/update-item', ['registro' => $detalleId, 'costo' => 4, 'cantidad' => 6]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('compradetalles', ['id' => $detalleId, 'costo' => 4, 'cantidad' => 6, 'subtotal' => 24]);
    }

    public function test_compra_delete_item_anula_detalle(): void
    {
        $user     = $this->actingAsUser();
        $prov     = Cuenta::factory()->proveedor()->create();
        $producto = Producto::factory()->create(['stock1' => 0]);
        $compra   = Compra::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $prov->id, 'estado' => 'PROFORMA']);
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 3]);
        $detalleId = $compra->detalles()->first()->id;

        $resp = $this->postJson("/api/compras/delete-item/{$detalleId}");

        $resp->assertStatus(200);
        $this->assertDatabaseHas('compradetalles', ['id' => $detalleId, 'estado' => 'ANULADO']);
    }

    public function test_compra_devolucion_ida_y_vuelta(): void
    {
        $user     = $this->actingAsUser();
        $prov     = Cuenta::factory()->proveedor()->create();
        $producto = Producto::factory()->create(['stock1' => 0]);
        $compra   = Compra::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => $prov->id,
            'estado' => 'PROFORMA', 'tipo' => 'CONTADO',
        ]);
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 3]);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);
        $this->assertEquals(5, Producto::find($producto->id)->stock1); // 0 + 5

        // Devolver 2 al proveedor → stock baja a 3, Devcompra + Tranza D-COM
        $this->postJson('/api/compras/dev-item', [
            'compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 2,
        ])->assertStatus(200);

        $this->assertEquals(3, Producto::find($producto->id)->stock1);
        $dev = Devcompra::where('compra_id', $compra->id)->where('estado', 'ON')->first();
        $this->assertNotNull($dev);
        $this->assertDatabaseHas('tranzas', ['clase' => 'D-COM', 'registro' => $compra->id, 'estado' => 'ON']);

        $this->postJson('/api/compras/delete-item-dev', ['registro' => $dev->id])->assertStatus(200);
        $this->assertEquals(5, Producto::find($producto->id)->stock1);
        $this->assertDatabaseHas('devcompras', ['id' => $dev->id, 'estado' => 'OFF']);
    }

    public function test_compra_destroy_anula_y_revierte_stock(): void
    {
        $user     = $this->actingAsUser();
        $prov     = Cuenta::factory()->proveedor()->create();
        $producto = Producto::factory()->create(['stock1' => 0]);
        $compra   = Compra::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $prov->id, 'estado' => 'PROFORMA', 'tipo' => 'CONTADO']);
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 3]);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);
        $this->assertEquals(5, Producto::find($producto->id)->stock1);

        $resp = $this->deleteJson("/api/compras/{$compra->id}");

        $resp->assertStatus(200);
        $this->assertDatabaseHas('compras', ['id' => $compra->id, 'estado' => 'ANULADO']);
        $this->assertEquals(0, Producto::find($producto->id)->stock1); // stock revertido
    }

    // ─────────────────────────── COTIZACIONES ───────────────────────────

    public function test_cotizacion_agregar_actualizar_y_eliminar_item(): void
    {
        $user     = $this->actingAsUser();
        $cuenta   = Cuenta::factory()->cliente()->create();
        $producto = Producto::factory()->create(['p_norm' => 5]);
        $cot      = Cotizacion::factory()->create(['sucursal_id' => $user->sucursal_id, 'cuenta_id' => $cuenta->id]);

        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cot->id, 'producto_id' => $producto->id, 'cantidad' => 2])->assertStatus(200);
        $detalleId = $cot->detalles()->first()->id;

        $this->postJson('/api/cotizaciones/update-item', ['registro' => $detalleId, 'cantidad' => 4])->assertStatus(200);
        $this->assertDatabaseHas('cotizaciondetalles', ['id' => $detalleId, 'cantidad' => 4]);

        $this->postJson("/api/cotizaciones/delete-item/{$detalleId}")->assertStatus(200);
        $this->assertDatabaseMissing('cotizaciondetalles', ['id' => $detalleId, 'estado' => 'VALIDO']);
    }

    public function test_cotizacion_destroy(): void
    {
        $user = $this->actingAsUser();
        $cot  = Cotizacion::factory()->create(['sucursal_id' => $user->sucursal_id]);

        $this->deleteJson("/api/cotizaciones/{$cot->id}")->assertStatus(200);
        $this->assertDatabaseMissing('cotizacions', ['id' => $cot->id, 'estado' => 'VALIDO']);
    }

    // ─────────────────────────── PEDIDOS ───────────────────────────

    public function test_pedido_kpis_devuelve_estructura(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/pedidos/kpis')->assertStatus(200);
    }

    public function test_pedido_agregar_actualizar_y_eliminar_item(): void
    {
        $user     = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $pedido   = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);

        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $producto->id, 'cantidad' => 2])->assertStatus(200);
        $detalleId = $pedido->detalles()->first()->id;

        $this->postJson('/api/pedidos/update-item', ['registro' => $detalleId, 'cantidad' => 5])->assertStatus(200);
        $this->assertDatabaseHas('pedidodetalles', ['id' => $detalleId, 'cantidad' => 5]);

        $this->postJson("/api/pedidos/delete-item/{$detalleId}")->assertStatus(200);
    }

    // ─────────────────────────── ENVIOS ───────────────────────────

    public function test_envio_agregar_actualizar_y_eliminar_item(): void
    {
        $user     = $this->actingAsUser();
        $medio    = Medio::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $envio    = Envio::factory()->create([
            'sucursal_id' => $user->sucursal_id, 'cuenta_id' => 2, 'medio_id' => $medio->id, 'estado' => 'PROFORMA',
        ]);

        $this->postJson('/api/envios/agregar-item', ['envio_id' => $envio->id, 'producto_id' => $producto->id, 'cantidad' => 2])->assertStatus(200);
        $detalleId = $envio->detalles()->first()->id;

        $this->postJson('/api/envios/update-item', ['registro' => $detalleId, 'cantidad' => 5])->assertStatus(200);
        $this->assertDatabaseHas('enviodetalles', ['id' => $detalleId, 'cantidad' => 5]);

        $this->postJson("/api/envios/delete-item/{$detalleId}")->assertStatus(200);
    }

    // ─────────────────────────── PRODUCTOS ───────────────────────────

    public function test_producto_show_y_movimientos(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 7]);

        $this->getJson("/api/productos/{$producto->id}")->assertStatus(200);
        $this->getJson("/api/productos/{$producto->id}/movimientos")->assertStatus(200);
    }

    // ─────────────────────────── CAJA ───────────────────────────

    public function test_caja_tranza_ciclo_ingreso_update_delete(): void
    {
        $user = $this->actingAsUser();
        $this->postJson('/api/caja/apertura', ['monto' => 100])->assertStatus(200);
        $this->postJson('/api/caja/ingreso', ['monto' => 50, 'descripcion' => 'Aporte'])->assertStatus(200);

        $tranza = Tranza::where('sucursal_id', $user->sucursal_id)->where('clase', 'ENT')->latest('id')->first();
        $this->assertNotNull($tranza);

        $this->postJson('/api/caja/update-tranza', ['tranza_id' => $tranza->id, 'monto' => 75, 'descripcion' => 'Aporte editado'])->assertStatus(200);
        $this->assertDatabaseHas('tranzas', ['id' => $tranza->id, 'monto_ingreso' => 75]);

        $this->postJson('/api/caja/delete-tranza', ['tranza_id' => $tranza->id])->assertStatus(200);
        $this->assertDatabaseHas('tranzas', ['id' => $tranza->id, 'estado' => 'OFF']);
    }

    public function test_caja_cierre_y_reversion(): void
    {
        $user = $this->actingAsUser();
        $this->postJson('/api/caja/apertura', ['monto' => 100])->assertStatus(200);

        $this->postJson('/api/caja/cierre')->assertStatus(200);
        $cierre = Cierre::where('sucursal_id', $user->sucursal_id)->where('estado', 'ON')->latest('id')->first();
        $this->assertNotNull($cierre);

        $this->postJson('/api/caja/revertir-cierre', ['cierre_id' => $cierre->id])->assertStatus(200);
        $this->assertDatabaseHas('cierres', ['id' => $cierre->id, 'estado' => 'OFF']);
    }

    // ─────────────────────────── CUENTAS ───────────────────────────

    public function test_cuenta_toggle_invierte_estado(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->create(['estado' => 'ON']);

        $this->getJson("/api/cuentas/{$cuenta->id}/toggle")->assertStatus(200);
        $this->assertDatabaseHas('cuentas', ['id' => $cuenta->id, 'estado' => 'OFF']);
    }

    // ─────────────────────────── CATALOGOS ───────────────────────────

    public function test_marca_store_update_toggle(): void
    {
        $this->actingAsUser();

        $id = $this->postJson('/api/marcas', ['nombre' => 'Volvo Trucks Test'])->assertStatus(200)->json('id');
        $this->assertDatabaseHas('marcas', ['id' => $id, 'nombre' => 'Volvo Trucks Test', 'estado' => 'ON']);

        $this->putJson("/api/marcas/{$id}", ['nombre' => 'Volvo Renombrada'])->assertStatus(200);
        $this->assertDatabaseHas('marcas', ['id' => $id, 'nombre' => 'Volvo Renombrada']);

        $this->getJson("/api/marcas/{$id}/toggle")->assertStatus(200);
        $this->assertDatabaseHas('marcas', ['id' => $id, 'estado' => 'OFF']);
    }

    public function test_empresa_destroy_la_desactiva(): void
    {
        $this->actingAsUser();
        $empresa = Empresa::factory()->create(['estado' => 'ON']);

        $this->deleteJson("/api/empresas/{$empresa->id}")->assertStatus(200);
        $this->assertDatabaseHas('empresas', ['id' => $empresa->id, 'estado' => 'OFF']);
    }

    // ─────────────────────────── USUARIOS ───────────────────────────

    public function test_user_store_con_accesos(): void
    {
        $this->actingAsUser('ADMIN');

        $resp = $this->postJson('/api/users', [
            'name' => 'Nuevo Operador', 'email' => 'nuevo.op.test@lcv.bo',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
            'sucursal_id' => 1, 'role' => 'OPERADOR', 'accesos' => [2, 3],
        ]);

        $resp->assertStatus(200);
        $uid = $resp->json('id');
        $this->assertDatabaseHas('users', ['id' => $uid, 'email' => 'nuevo.op.test@lcv.bo']);
        // Sucursal predeterminada (1) + seleccionadas (2,3) quedan ON
        $this->assertDatabaseHas('accesos', ['user_id' => $uid, 'sucursal_id' => 1, 'estado' => 'ON']);
        $this->assertDatabaseHas('accesos', ['user_id' => $uid, 'sucursal_id' => 2, 'estado' => 'ON']);
        $this->assertDatabaseHas('accesos', ['user_id' => $uid, 'sucursal_id' => 4, 'estado' => 'OFF']);
    }

    public function test_user_update_email_y_password(): void
    {
        $this->actingAsUser('ADMIN');
        $target = User::factory()->create(['sucursal_id' => 1]);
        $target->assignRole('VENDEDOR');

        $resp = $this->putJson("/api/users/{$target->id}", [
            'name' => 'Editado', 'email' => 'editado.test@lcv.bo', 'sucursal_id' => 2,
            'role' => 'CAJERO', 'password' => 'nuevopass123', 'password_confirmation' => 'nuevopass123',
        ]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => 'editado.test@lcv.bo', 'sucursal_id' => 2]);
        $this->assertTrue($target->fresh()->hasRole('CAJERO'));
    }

    public function test_user_acces_toggle_off(): void
    {
        $this->actingAsUser('ADMIN');
        $target = User::factory()->create(['sucursal_id' => 1]);
        $target->assignRole('VENDEDOR');
        Acceso::create(['user_id' => $target->id, 'sucursal_id' => 1, 'estado' => 'ON']);
        Acceso::create(['user_id' => $target->id, 'sucursal_id' => 2, 'estado' => 'ON']);

        $this->getJson("/api/users/{$target->id}/2/OFF/acces")->assertStatus(200);
        $this->assertDatabaseHas('accesos', ['user_id' => $target->id, 'sucursal_id' => 2, 'estado' => 'OFF']);
    }

    public function test_user_destroy_suspende(): void
    {
        $this->actingAsUser('ADMIN');
        $target = User::factory()->create(['sucursal_id' => 1]);
        $target->assignRole('VENDEDOR');

        $this->deleteJson("/api/users/{$target->id}")->assertStatus(200);
        $this->assertTrue($target->fresh()->hasRole('SUSPENDIDO'));
    }

    public function test_user_simulate_y_stop(): void
    {
        $this->actingAsUser('ADMIN');
        $gerente = Role::where('name', 'GERENTE')->firstOrFail();

        $this->postJson('/api/users/simulate-role', ['role_id' => $gerente->id])->assertStatus(200)->assertJsonPath('ok', true);
        $this->postJson('/api/users/stop-simulate')->assertStatus(200)->assertJsonPath('ok', true);
    }

    // ─────────────────────────── ROLES ───────────────────────────

    public function test_role_store_update_destroy(): void
    {
        $this->actingAsUser('ADMIN');

        $id = $this->postJson('/api/roles', ['name' => 'rol_prueba', 'permissions' => ['ventas.index']])
            ->assertStatus(200)->json('id');
        $this->assertDatabaseHas('roles', ['id' => $id, 'name' => 'ROL_PRUEBA']);

        $this->putJson("/api/roles/{$id}", ['name' => 'rol_editado', 'permissions' => ['ventas.index', 'ventas.create']])->assertStatus(200);
        $this->assertDatabaseHas('roles', ['id' => $id, 'name' => 'ROL_EDITADO']);

        $this->deleteJson("/api/roles/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('roles', ['id' => $id]);
    }

    // ───────────────── OCULTAMIENTO DE MONTOS POR ROL ─────────────────

    public function test_compras_kpi_oculta_monto_a_vendedor(): void
    {
        $this->actingAsUser('VENDEDOR');
        $resp = $this->getJson('/api/compras/kpis');
        $resp->assertStatus(200);
        $this->assertNull($resp->json('monto'));
    }

    public function test_compras_kpi_muestra_monto_a_gerente(): void
    {
        $this->actingAsUser('GERENTE');
        $resp = $this->getJson('/api/compras/kpis');
        $resp->assertStatus(200);
        $this->assertNotNull($resp->json('monto'));
    }

    public function test_cotizaciones_kpi_oculta_monto_a_vendedor(): void
    {
        $this->actingAsUser('VENDEDOR');
        $resp = $this->getJson('/api/cotizaciones/kpis');
        $resp->assertStatus(200);
        $this->assertNull($resp->json('monto'));
    }
}
