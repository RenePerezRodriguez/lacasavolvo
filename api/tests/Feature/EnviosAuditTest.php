<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Devenvio;
use App\Models\Envio;
use App\Models\Enviodetalle;
use App\Models\Medio;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Tranza;
use App\Models\User;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo ENVÍOS (traslado físico de stock ENTRE sucursales).
 *
 * Alto blast-radius: cada operación mueve stock real entre dos columnas `stockN` y,
 * según `pagado`, dispara un EGRESO de caja por el flete. El flujo es
 * `PROFORMA → ENVIADO → RECIBIDO`, con `Devenvio` (devolución del destino al origen)
 * y `ANULADO` (revierte el efecto del estado en que estaba).
 *
 * Invariantes atacadas (casos difíciles primero):
 *  - D1 IDOR de LECTURA en `pdf` (gemelo del bug cerrado en Pedidos).
 *  - D4 conservación de stock: Σ(stock1..stock5) del producto es constante salvo
 *    en tránsito ENVIADO (donde baja temporalmente lo que salió del origen).
 *  - D5 conservación del flete: se cobra UNA sola vez en el ciclo y `anular` lo revierte.
 *  - D1 frontera origen/destino: `enviar` solo el origen, `recibir` solo el destino.
 */
class EnviosAuditTest extends TestCase
{
    /**
     * Crea un usuario actuando en la sucursal indicada (con su Acceso ON), para
     * probar fronteras origen/destino con identidades distintas. `actingAsUser`
     * de TestCase siempre usa sucursal 1; acá necesitamos 2, 3, etc.
     *
     * @param  int     $sucursalId  Sucursal activa del usuario.
     * @param  string  $role        Rol Spatie a asignar.
     * @return User
     */
    private function actingInSucursal(int $sucursalId, string $role = 'ADMIN'): User
    {
        // Garantiza que existan roles/permisos (idéntico a actingAsUser).
        $this->actingAsUser($role);

        $user = User::factory()->create(['sucursal_id' => $sucursalId]);
        $user->assignRole($role);
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => $sucursalId, 'estado' => 'ON']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /**
     * Factory de envío con todos los campos NOT NULL fijados (evita 500 por constraint).
     *
     * @param  array<string,mixed>  $attrs
     * @return Envio
     */
    private function envio(array $attrs = []): Envio
    {
        return Envio::factory()->create(array_merge([
            'sucursal_id' => 1,
            'cuenta_id'   => 2,
            'medio_id'    => Medio::factory()->create()->id,
            'estado'      => 'PROFORMA',
            'pagado'      => 'PAGADO',
            'monto'       => 0,
        ], $attrs));
    }

    /**
     * Suma de las 5 columnas de stock de un producto (inventario total del sistema
     * para ese producto). Oráculo de conservación de inventario.
     *
     * @param  int  $productoId
     * @return int
     */
    private function stockTotal(int $productoId): int
    {
        $p = Producto::find($productoId);
        return (int) ($p->stock1 + $p->stock2 + $p->stock3 + $p->stock4 + $p->stock5);
    }

    // ───────────────────────── D1 · IDOR de lectura en pdf ─────────────────────────

    /**
     * Una sucursal que NO es ni el origen ni el destino del traslado NO debe poder
     * descargar el PDF del envío. `show`/`apiDetalles` ya tienen el guard dual
     * (`sucursal_id != sid && cuenta_id != sid → 403`); `pdf` no lo tenía → IDOR de
     * lectura (gemelo exacto del bug cerrado en `PedidoController::pdf`, loop 14).
     */
    public function test_sucursal_ajena_no_descarga_el_pdf_de_un_envio(): void
    {
        // Envío 1 → 2. El usuario actúa en la sucursal 3 (tercera, ni origen ni destino).
        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2]);
        $this->actingInSucursal(3, 'ADMIN');

        $this->getJson("/api/envios/{$envio->id}/pdf")->assertStatus(403);
    }

    /**
     * Contraprueba: el origen y el destino SÍ pueden descargar el PDF (no se rompe
     * el caso legítimo al cerrar el IDOR).
     */
    public function test_origen_y_destino_si_descargan_el_pdf(): void
    {
        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2]);

        // Origen (sucursal 1).
        $this->actingInSucursal(1, 'ADMIN');
        $this->get("/api/envios/{$envio->id}/pdf")->assertStatus(200);

        // Destino (sucursal 2).
        $this->actingInSucursal(2, 'ADMIN');
        $this->get("/api/envios/{$envio->id}/pdf")->assertStatus(200);
    }

    // ───────────────────── D4 · Conservación de stock (stateful) ─────────────────────

    /**
     * Stateful PBT del ciclo COMPLETO de un traslado 1→2 con devoluciones y reverso.
     *
     * Oráculo de conservación: el inventario TOTAL del producto (Σ stock1..stock5) es
     * CONSTANTE en todo momento, salvo cuando el envío está en tránsito (ENVIADO), en
     * cuyo caso el total baja exactamente por la cantidad enviada (salió del origen y
     * aún no entró al destino). Tras recibir, el total vuelve al valor inicial y NO se
     * altera por devoluciones (que solo mueven stock destino↔origen) ni por anular.
     *
     * Cadena sembrada (determinista): enviar → recibir → devolver parcial → revertir esa
     * devolución → devolver otra cantidad → anular el RECIBIDO. Tras cada paso se verifica
     * el total. Casos difíciles: devolver, revertir-dev (gemelo del bug deleteItemDev de
     * ventas/compras), y anular un RECIBIDO con una devolución previa viva.
     */
    public function test_pbt_conservacion_de_stock_ciclo_completo_envio(): void
    {
        $inicialOrigen = 50;
        $cantidad      = 12;

        $origen  = $this->actingInSucursal(1, 'ADMIN');
        $destino = $this->actingInSucursal(2, 'ADMIN');

        $prod  = Producto::factory()->create(['stock1' => $inicialOrigen, 'stock2' => 0]);
        $total0 = $this->stockTotal($prod->id); // = 50

        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2, 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => $cantidad, 'estado' => 'VALIDO',
        ]);
        $registro = Enviodetalle::where('envio_id', $envio->id)->first()->id;

        // 1) ENVIAR (lo hace el origen). En tránsito → total baja por la cantidad.
        $this->actingAs($origen, 'sanctum');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals($total0 - $cantidad, $this->stockTotal($prod->id), 'en tránsito el total baja por lo enviado');
        $this->assertEquals($inicialOrigen - $cantidad, Producto::find($prod->id)->stock1);

        // 2) RECIBIR (lo hace el destino). Total restituido.
        $this->actingAs($destino, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);
        $this->assertEquals($total0, $this->stockTotal($prod->id), 'tras recibir el total vuelve al inicial');
        $this->assertEquals($cantidad, Producto::find($prod->id)->stock2);

        // 3) DEVOLVER parcial (destino → origen). Total constante.
        $this->postJson('/api/envios/dev-item', ['envio_id' => $envio->id, 'registro' => $registro, 'cantidad' => 5])
            ->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertEquals($total0, $this->stockTotal($prod->id), 'devolver no cambia el inventario total');
        $this->assertEquals($cantidad - 5, Producto::find($prod->id)->stock2);
        $this->assertEquals($inicialOrigen - $cantidad + 5, Producto::find($prod->id)->stock1);

        // 4) REVERTIR esa devolución (origen → destino). Vuelve al estado post-recibir.
        $dev = Devenvio::where('envio_id', $envio->id)->where('estado', 'ON')->first();
        $this->postJson('/api/envios/delete-item-dev', ['registro' => $dev->id])->assertStatus(200);
        $this->assertEquals($total0, $this->stockTotal($prod->id), 'revertir-dev no cambia el inventario total');
        $this->assertEquals($cantidad, Producto::find($prod->id)->stock2, 'revertir-dev restaura el destino');
        $this->assertEquals($inicialOrigen - $cantidad, Producto::find($prod->id)->stock1, 'revertir-dev restaura el origen');

        // 5) DEVOLVER otra cantidad (queda viva al anular).
        $this->postJson('/api/envios/dev-item', ['envio_id' => $envio->id, 'registro' => $registro, 'cantidad' => 4])
            ->assertStatus(200)->assertJsonPath('ok', true);
        $this->assertEquals($total0, $this->stockTotal($prod->id));

        // 6) ANULAR el RECIBIDO con una devolución viva (4 ya volvió al origen).
        //    destroy resta del destino y suma al origen SOLO el neto no devuelto (cantidad-devuelto).
        $this->actingAs($origen, 'sanctum');
        $this->deleteJson("/api/envios/{$envio->id}")->assertStatus(200);
        $this->assertEquals($total0, $this->stockTotal($prod->id), 'anular un RECIBIDO no crea ni destruye inventario');
        // Tras anular un traslado completo: todo el stock vuelve al origen.
        $this->assertEquals($inicialOrigen, Producto::find($prod->id)->stock1, 'todo el stock regresa al origen');
        $this->assertEquals(0, Producto::find($prod->id)->stock2, 'el destino queda en 0');
    }

    /**
     * Caso difícil: anular un envío ENVIADO (en tránsito) debe RESTITUIR el stock al
     * origen — si no, el stock descontado al enviar se evapora (desaparece inventario).
     */
    public function test_anular_envio_enviado_restituye_stock_al_origen(): void
    {
        $origen = $this->actingInSucursal(1, 'ADMIN');
        $prod   = Producto::factory()->create(['stock1' => 30, 'stock2' => 0]);
        $total0 = $this->stockTotal($prod->id);

        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2, 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 8, 'estado' => 'VALIDO',
        ]);

        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals(22, Producto::find($prod->id)->stock1);
        $this->assertEquals($total0 - 8, $this->stockTotal($prod->id), 'en tránsito el total bajó');

        // Anular en tránsito → el stock vuelve íntegro al origen, total restituido.
        $this->deleteJson("/api/envios/{$envio->id}")->assertStatus(200);
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'ANULADO']);
        $this->assertEquals(30, Producto::find($prod->id)->stock1, 'el stock regresa al origen');
        $this->assertEquals(0, Producto::find($prod->id)->stock2);
        $this->assertEquals($total0, $this->stockTotal($prod->id), 'no se evaporó inventario al anular en tránsito');
    }

    /**
     * Caso difícil: anular un envío PROFORMA (nunca movió stock) NO debe tocar el stock.
     */
    public function test_anular_envio_proforma_no_toca_stock(): void
    {
        $this->actingInSucursal(1, 'ADMIN');
        $prod   = Producto::factory()->create(['stock1' => 15, 'stock2' => 3]);
        $total0 = $this->stockTotal($prod->id);

        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2, 'estado' => 'PROFORMA']);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 5, 'estado' => 'VALIDO',
        ]);

        $this->deleteJson("/api/envios/{$envio->id}")->assertStatus(200);
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'ANULADO']);
        $this->assertEquals(15, Producto::find($prod->id)->stock1, 'PROFORMA nunca movió stock');
        $this->assertEquals(3, Producto::find($prod->id)->stock2);
        $this->assertEquals($total0, $this->stockTotal($prod->id));
    }

    // ───────────────────── D5 · Flete / caja (asimétrico) ─────────────────────

    /**
     * Cuenta tranzas ENV vivas (estado ON) de un envío en una sucursal.
     *
     * @param  int  $envioId
     * @param  int  $suc
     * @return int
     */
    private function fleteOn(int $envioId, int $suc): int
    {
        return Tranza::where('registro', $envioId)->where('sucursal_id', $suc)
            ->where('clase', 'ENV')->where('estado', 'ON')->count();
    }

    /**
     * Conservación del flete (caso difícil): el cargo de flete se cobra EXACTAMENTE UNA
     * vez en todo el ciclo enviar→recibir. Como `pagado` es un único campo mutuamente
     * excluyente (PAGADO cobra en el origen al enviar; POR PAGAR cobra en el destino al
     * recibir), nunca debe haber DOS egresos para el mismo envío. Aquí PAGADO: el flete
     * sale solo en el origen, y recibir NO genera un segundo egreso.
     */
    public function test_flete_pagado_se_cobra_una_sola_vez_en_origen_no_se_duplica_al_recibir(): void
    {
        $origen  = $this->actingInSucursal(1, 'ADMIN');
        $destino = $this->actingInSucursal(2, 'ADMIN');
        $prod    = Producto::factory()->create(['stock1' => 10, 'stock2' => 0]);

        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2, 'pagado' => 'PAGADO', 'monto' => 50]);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 3, 'estado' => 'VALIDO',
        ]);

        $this->actingAs($origen, 'sanctum');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals(1, $this->fleteOn($envio->id, 1), 'PAGADO: 1 egreso en el origen al enviar');
        $this->assertEquals(0, $this->fleteOn($envio->id, 2), 'aún no hay egreso en el destino');

        $this->actingAs($destino, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);
        // El flete PAGADO NO debe cobrarse otra vez en el destino al recibir.
        $this->assertEquals(0, $this->fleteOn($envio->id, 2), 'recibir un PAGADO no genera un segundo egreso');
        $this->assertEquals(1, $this->fleteOn($envio->id, 1), 'sigue habiendo exactamente 1 egreso (en el origen)');

        // Suma total de egresos ENV vivos en TODO el sistema para este envío = 1.
        $totalFlete = Tranza::where('registro', $envio->id)->where('clase', 'ENV')->where('estado', 'ON')->count();
        $this->assertEquals(1, $totalFlete, 'el flete se cobra exactamente una vez en el ciclo');
    }

    /**
     * Flete POR PAGAR: se cobra en el DESTINO al recibir, NO en el origen al enviar.
     * Y al anular el RECIBIDO, ese egreso del destino debe revertirse (estado OFF) —
     * de lo contrario el dinero queda colgado en la caja del destino.
     */
    public function test_flete_por_pagar_se_cobra_en_destino_y_anular_lo_revierte(): void
    {
        $origen  = $this->actingInSucursal(1, 'ADMIN');
        $destino = $this->actingInSucursal(2, 'ADMIN');
        $prod    = Producto::factory()->create(['stock1' => 10, 'stock2' => 0]);

        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2, 'pagado' => 'POR PAGAR', 'monto' => 40]);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 2, 'estado' => 'VALIDO',
        ]);

        $this->actingAs($origen, 'sanctum');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals(0, $this->fleteOn($envio->id, 1), 'POR PAGAR: el origen NO paga el flete al enviar');

        $this->actingAs($destino, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);
        $this->assertEquals(1, $this->fleteOn($envio->id, 2), 'POR PAGAR: el destino paga el flete al recibir');

        // Anular el RECIBIDO debe revertir la tranza del destino (no queda dinero colgado).
        $this->actingAs($origen, 'sanctum');
        $this->deleteJson("/api/envios/{$envio->id}")->assertStatus(200);
        $this->assertEquals(0, $this->fleteOn($envio->id, 2), 'anular revierte el egreso del flete en el destino');
        $totalFlete = Tranza::where('registro', $envio->id)->where('clase', 'ENV')->where('estado', 'ON')->count();
        $this->assertEquals(0, $totalFlete, 'tras anular no queda ningún egreso de flete vivo');
    }

    /**
     * Contrato de `pagado`: solo PAGADO (cobra en origen) y POR PAGAR (cobra en destino)
     * disparan el egreso del flete. Antes del fix, un `pagado` con CUALQUIER otro valor
     * (vía llamada directa a la API; el front nunca lo envía) hacía que el flete con
     * `monto>0` NUNCA se cobrara — ni en origen ni en destino → costo de traslado perdido
     * y caja descuadrada respecto al `monto` registrado en el envío. El validador de
     * `store`/`updateEncabezado` ahora restringe `pagado` a esos dos valores (422 limpio).
     */
    public function test_store_rechaza_pagado_con_valor_invalido(): void
    {
        $this->actingInSucursal(1, 'ADMIN');
        $medio = Medio::factory()->create();

        // pagado basura → 422 (antes pasaba y dejaba el flete sin cobrar).
        $this->postJson('/api/envios', [
            'fecha' => now()->format('Y-m-d'), 'cuenta_id' => 2, 'medio_id' => $medio->id,
            'monto' => 99, 'pagado' => 'GRATIS',
        ])->assertStatus(422);

        // Los dos valores legítimos siguen aceptándose.
        $this->postJson('/api/envios', [
            'fecha' => now()->format('Y-m-d'), 'cuenta_id' => 2, 'medio_id' => $medio->id,
            'monto' => 99, 'pagado' => 'PAGADO',
        ])->assertStatus(200);
        $this->postJson('/api/envios', [
            'fecha' => now()->format('Y-m-d'), 'cuenta_id' => 2, 'medio_id' => $medio->id,
            'monto' => 99, 'pagado' => 'POR PAGAR',
        ])->assertStatus(200);

        // Omitirlo sigue cayendo al default PAGADO (el front no envía `pagado`).
        $res = $this->postJson('/api/envios', [
            'fecha' => now()->format('Y-m-d'), 'cuenta_id' => 2, 'medio_id' => $medio->id, 'monto' => 0,
        ]);
        $res->assertStatus(200);
        $this->assertDatabaseHas('envios', ['id' => $res->json('id'), 'pagado' => 'PAGADO']);
    }

    // ───────────────────── D1 · Frontera origen/destino ─────────────────────

    /**
     * Las 4 combinaciones de la frontera asimétrica enviar(origen)/recibir(destino),
     * sobre un envío 1→2:
     *  1. El DESTINO no puede ENVIAR (enviar es solo del origen) → 403.
     *  2. El ORIGEN no puede RECIBIR (recibir es solo del destino) → 403.
     *  3. Una TERCERA sucursal no puede ni enviar ni recibir → 403 en ambos.
     */
    public function test_frontera_origen_destino_los_4_cruces(): void
    {
        // Envío 1 → 2, en PROFORMA, con un detalle para que enviar tenga qué mover.
        $prod  = Producto::factory()->create(['stock1' => 10]);
        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2, 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 2, 'estado' => 'VALIDO',
        ]);

        // (1) El DESTINO (sucursal 2) intenta ENVIAR → 403 (solo el origen envía).
        $this->actingInSucursal(2, 'ADMIN');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(403);

        // (3a) Una TERCERA sucursal intenta ENVIAR → 403.
        $this->actingInSucursal(3, 'ADMIN');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(403);

        // El origen lo envía legítimamente para poder probar recibir.
        $this->actingInSucursal(1, 'ADMIN');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);

        // (2) El ORIGEN (sucursal 1) intenta RECIBIR → 403 (solo el destino recibe).
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(403);

        // (3b) Una TERCERA sucursal intenta RECIBIR → 403.
        $this->actingInSucursal(3, 'ADMIN');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(403);

        // Sigue en ENVIADO (ningún cruce ilegal lo movió).
        $this->assertDatabaseHas('envios', ['id' => $envio->id, 'estado' => 'ENVIADO']);
    }

    /**
     * Caso límite: envío a la MISMA sucursal (`sucursal_id == cuenta_id`). enviar
     * descuenta de `stockN` y recibir lo vuelve a sumar a la MISMA `stockN` → el ciclo
     * completo es un no-op para el inventario (no se corrompe ni se duplica el stock).
     * En tránsito el stock baja temporalmente, como cualquier envío.
     */
    public function test_envio_a_si_mismo_ciclo_completo_es_no_op_de_stock(): void
    {
        $user = $this->actingInSucursal(1, 'ADMIN');
        $prod = Producto::factory()->create(['stock1' => 20]);

        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 1, 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 6, 'estado' => 'VALIDO',
        ]);

        // Enviar: stock baja en tránsito.
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals(14, Producto::find($prod->id)->stock1, 'en tránsito el stock baja');

        // Recibir (el mismo usuario es origen Y destino): vuelve a la misma columna.
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);
        $this->assertEquals(20, Producto::find($prod->id)->stock1, 'self-envío recibido restaura el stock exacto (no-op)');
    }

    // ───────────────────── D3 · Máquina de estados (sin doble efecto) ─────────────────────

    /**
     * Re-disparar transiciones ya consumidas NO debe mover stock por segunda vez:
     *  - enviar un ENVIADO → 422 (no vuelve a descontar el origen).
     *  - doble-recibir → el 2º recibir da 422 (no vuelve a sumar al destino).
     *  - enviar un RECIBIDO → 422.
     * Es la red de idempotencia de la máquina de estados sobre el inventario.
     */
    public function test_transiciones_repetidas_no_mueven_stock_dos_veces(): void
    {
        $origen  = $this->actingInSucursal(1, 'ADMIN');
        $destino = $this->actingInSucursal(2, 'ADMIN');
        $prod    = Producto::factory()->create(['stock1' => 10, 'stock2' => 0]);

        $envio = $this->envio(['sucursal_id' => 1, 'cuenta_id' => 2, 'pagado' => 'PAGADO', 'monto' => 0]);
        Enviodetalle::create([
            'envio_id' => $envio->id, 'producto_id' => $prod->id, 'codigo' => $prod->codigo,
            'descripcion' => $prod->descripcion, 'marca' => '', 'cantidad' => 4, 'estado' => 'VALIDO',
        ]);

        // Enviar una vez (origen): stock1 = 6.
        $this->actingAs($origen, 'sanctum');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(200);
        $this->assertEquals(6, Producto::find($prod->id)->stock1);

        // Re-enviar un ENVIADO → 422, sin segundo descuento.
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(422);
        $this->assertEquals(6, Producto::find($prod->id)->stock1, 're-enviar no descuenta de nuevo');

        // Recibir una vez (destino): stock2 = 4.
        $this->actingAs($destino, 'sanctum');
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(200);
        $this->assertEquals(4, Producto::find($prod->id)->stock2);

        // Doble-recibir → 422, sin segundo incremento.
        $this->postJson("/api/envios/recibir/{$envio->id}")->assertStatus(422);
        $this->assertEquals(4, Producto::find($prod->id)->stock2, 'doble-recibir no suma de nuevo');

        // Enviar un RECIBIDO → 422 (origen).
        $this->actingAs($origen, 'sanctum');
        $this->postJson("/api/envios/enviar/{$envio->id}")->assertStatus(422);
        $this->assertEquals(6, Producto::find($prod->id)->stock1);
        $this->assertEquals(4, Producto::find($prod->id)->stock2);
    }
}
