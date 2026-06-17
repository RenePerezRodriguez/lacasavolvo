<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Envio;
use App\Models\Medio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Venta;
use Tests\TestCase;

/**
 * Frontera de sucursal (IDOR, dimensión D1). Cierra un hueco detectado por mutation
 * testing manual: la red NO verificaba el `abort(403)` de `validarAccesoSucursal` —
 * borrarlo no rompía ningún test (mutante sobreviviente). Estos tests lo matan.
 *
 * Un usuario NO-admin con acceso solo a su sucursal no puede leer ni operar datos
 * de OTRA sucursal a la que no tiene `acceso`, aunque tenga el permiso de la acción.
 */
class CrossSucursalAccessTest extends TestCase
{
    public function test_vendedor_no_lista_ventas_de_sucursal_ajena(): void
    {
        // actingAsUser crea acceso SOLO a la sucursal 1.
        $this->actingAsUser('VENDEDOR');

        // Pedir explícitamente la sucursal 2 (sin acceso) → 403.
        $this->getJson('/api/ventas?sucursal_id=2')->assertStatus(403);
        $this->getJson('/api/ventas/kpis?sucursal_id=2')->assertStatus(403);
    }

    public function test_vendedor_no_valida_venta_de_sucursal_ajena(): void
    {
        $this->actingAsUser('VENDEDOR'); // acceso solo sucursal 1
        $cuenta = Cuenta::factory()->cliente()->create();
        // Venta perteneciente a la sucursal 2 (ajena).
        $venta = Venta::factory()->create(['sucursal_id' => 2, 'cuenta_id' => $cuenta->id, 'estado' => 'PROFORMA']);

        // Operar sobre una venta de sucursal ajena → 403 (la frontera se evalúa antes del estado).
        $this->postJson("/api/ventas/validar/{$venta->id}")->assertStatus(403);
    }

    public function test_admin_si_accede_a_cualquier_sucursal(): void
    {
        // Contraprueba: ADMIN (Gate::before) sí puede pedir otra sucursal.
        $this->actingAsUser('ADMIN');
        $this->getJson('/api/ventas?sucursal_id=2')->assertStatus(200);
    }

    /**
     * Compras/Envíos/Pedidos/Cotizaciones usan el guard `abort_if(doc->sucursal_id !=
     * user->sucursal_id)`. Un GERENTE (sucursal 1, con todos los permisos no-admin) NO
     * debe poder agregar ítems a un documento de la sucursal 2. Cubre la misma clase
     * IDOR en los 4 controladores de documento restantes.
     */
    public function test_no_se_opera_documento_de_sucursal_ajena(): void
    {
        $this->actingAsUser('GERENTE'); // sucursal 1, permisos de los 4 módulos
        $prod = Producto::factory()->create(['stock1' => 100, 'stock2' => 100]);

        $compra = Compra::factory()->create(['sucursal_id' => 2, 'estado' => 'PROFORMA']);
        $this->postJson('/api/compras/agregar-item', ['compra_id' => $compra->id, 'producto_id' => $prod->id, 'cantidad' => 1, 'costo' => 5])
            ->assertStatus(403);

        $envio = Envio::factory()->create(['sucursal_id' => 2, 'cuenta_id' => 1, 'medio_id' => Medio::factory()->create()->id, 'estado' => 'PROFORMA', 'pagado' => 'PAGADO', 'monto' => 0]);
        $this->postJson('/api/envios/agregar-item', ['envio_id' => $envio->id, 'producto_id' => $prod->id, 'cantidad' => 1])
            ->assertStatus(403);

        $pedido = Pedido::factory()->create(['sucursal_id' => 2, 'estado' => 'PROFORMA']);
        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 1])
            ->assertStatus(403);

        $cot = Cotizacion::factory()->create(['sucursal_id' => 2, 'estado' => 'VALIDO']);
        $this->postJson('/api/cotizaciones/agregar-item', ['cotizacion_id' => $cot->id, 'producto_id' => $prod->id, 'cantidad' => 1])
            ->assertStatus(403);
    }
}
