<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Cuenta;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Medio;
use App\Models\Producto;
use App\Models\Tranza;
use App\Models\Venta;
use Tests\TestCase;

/**
 * INVARIANTE D5 (Contable/Caja): al ANULAR un documento, TODAS sus tranzas de caja
 * deben quedar en estado OFF — el efectivo neto que generó vuelve a cero. Si una tranza
 * queda ON tras anular, la caja queda inflada/desinflada (plata fantasma).
 */
class AccountingIntegrityTest extends TestCase
{
    private function tranzasOn(int $registro, int $suc, array $clases): int
    {
        return Tranza::where('registro', $registro)->where('sucursal_id', $suc)
            ->where('estado', 'ON')->whereIn('clase', $clases)->count();
    }

    public function test_anular_venta_contado_revierte_tranza_de_ingreso(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'p_norm' => 50]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->assertEquals(1, $this->tranzasOn($venta->id, 1, ['VEN']), 'CONTADO validada debe crear 1 tranza VEN');

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $this->assertEquals(0, $this->tranzasOn($venta->id, 1, ['VEN', 'COB', 'D-VEN']), 'Anular debe dejar las tranzas en OFF');
    }

    public function test_anular_venta_credito_con_cobro_revierte_todo(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'p_norm' => 50]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 2]); // total 100
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->postJson('/api/ventas/cobrar', ['venta_id' => $venta->id, 'monto' => 40])->assertStatus(200);
        $this->assertEquals(1, $this->tranzasOn($venta->id, 1, ['COB']), 'Cobro debe crear tranza COB');

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $this->assertEquals(0, $this->tranzasOn($venta->id, 1, ['VEN', 'COB', 'D-VEN']), 'Anular crédito debe revertir cobros');
    }

    public function test_anular_venta_con_devolucion_revierte_egreso(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10, 'p_norm' => 50]);
        $venta = Venta::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/ventas/agregar-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 4]);
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(200);
        $this->postJson('/api/ventas/dev-item', ['venta_id' => $venta->id, 'producto_id' => $producto->id, 'cantidad' => 1])->assertStatus(200);
        $this->assertEquals(1, $this->tranzasOn($venta->id, 1, ['D-VEN']), 'Devolución debe crear tranza D-VEN egreso');

        $this->deleteJson("/api/ventas/{$venta->id}")->assertStatus(200);
        $this->assertEquals(0, $this->tranzasOn($venta->id, 1, ['VEN', 'COB', 'D-VEN']), 'Anular debe revertir VEN y D-VEN');
    }

    public function test_anular_compra_contado_revierte_tranza_de_egreso(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CONTADO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 10]);
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);
        $this->assertEquals(1, $this->tranzasOn($compra->id, 1, ['COM']), 'COMPRA CONTADO validada debe crear tranza COM');

        $this->deleteJson("/api/compras/{$compra->id}")->assertStatus(200);
        $this->assertEquals(0, $this->tranzasOn($compra->id, 1, ['COM', 'PAG', 'D-COM']), 'Anular compra debe revertir tranzas');
    }

    public function test_anular_compra_credito_con_pago_revierte_todo(): void
    {
        $user = $this->actingAsUser();
        $cuenta = Cuenta::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $compra = Compra::factory()->create(['sucursal_id' => 1, 'cuenta_id' => $cuenta->id, 'tipo' => 'CREDITO', 'estado' => 'PROFORMA']);

        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $producto->id, 'cantidad' => 5, 'costo' => 10]); // total 50
        $this->postJson("/api/compras/validar/{$compra->id}")->assertStatus(200);
        $this->postJson('/api/compras/pagar', ['compra_id' => $compra->id, 'monto' => 20])->assertStatus(200);
        $this->assertEquals(1, $this->tranzasOn($compra->id, 1, ['PAG']), 'Pago debe crear tranza PAG');

        $this->deleteJson("/api/compras/{$compra->id}")->assertStatus(200);
        $this->assertEquals(0, $this->tranzasOn($compra->id, 1, ['COM', 'PAG', 'D-COM']), 'Anular debe revertir pagos');
    }

    public function test_anular_envio_pagado_revierte_tranza(): void
    {
        $user = $this->actingAsUser(); // sucursal 1 (origen)
        $medio = Medio::factory()->create();
        $producto = Producto::factory()->create(['stock1' => 10]);
        $envio = Envio::factory()->create(['sucursal_id' => 1, 'cuenta_id' => 2, 'medio_id' => $medio->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 30]);
        Enviodetalle::create(['envio_id' => $envio->id, 'producto_id' => $producto->id, 'codigo' => $producto->codigo, 'descripcion' => $producto->descripcion, 'marca' => '', 'cantidad' => 2, 'estado' => 'VALIDO']);

        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals(1, $this->tranzasOn($envio->id, 1, ['ENV']), 'Envío PAGADO debe crear tranza ENV egreso en origen');

        $this->deleteJson("/api/envios/{$envio->id}")->assertStatus(200);
        $this->assertEquals(0, $this->tranzasOn($envio->id, 1, ['ENV']), 'Anular envío debe revertir su tranza');
    }
}
