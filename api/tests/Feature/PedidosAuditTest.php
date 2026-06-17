<?php

namespace Tests\Feature;

use App\Models\Acceso;
use App\Models\Pedido;
use App\Models\Pedidodetalle;
use App\Models\Producto;
use App\Models\User;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo PEDIDOS (loop 14).
 *
 * Pedidos = órdenes internas entre sucursales (el DOCUMENTO de solicitud). A diferencia
 * de Ventas/Compras/Envíos, NO mueve stock ni caja ni computa totales/saldo: `validar`
 * solo cambia `estado` a VALIDO. Por tanto el blast-radius real es:
 *   - D1 (autorización/IDOR entre sucursales) — el modelo es ASIMÉTRICO:
 *       LECTURA: la CENTRAL (sucursal 1) ve TODOS los pedidos; las demás solo los suyos.
 *       ESCRITURA: TODOS (incl. la central) quedan restringidos a `pedido->sucursal_id
 *                  == Auth::user()->sucursal_id` (sin bypass de central).
 *   - D3 (máquina de estados) — PROFORMA → VALIDO → ANULADO; transiciones ilegales → 422.
 *
 * Esta clase barre los casos DIFÍCILES PRIMERO: las 6 escrituras con un usuario de
 * sucursal ajena (incluida la central como atacante), las transiciones de estado
 * ilegales, la ruta `duplicado`, y el fuzz de `observacion`. Demuestra además que
 * D4/D5/D6 son N/A de verdad (validar no toca stockN ni crea tranzas).
 */
class PedidosAuditTest extends TestCase
{
    /**
     * Crea un usuario operativo en una sucursal != 1 con acceso ON a esa sucursal,
     * autenticado por sanctum. Necesario para probar fronteras ENTRE sucursales
     * (actingAsUser fija siempre sucursal 1).
     *
     * @param  int     $sucursalId  sucursal del usuario (2..5)
     * @param  string  $role        rol Spatie a asignar
     * @return User
     */
    private function userEnSucursal(int $sucursalId, string $role = 'GERENTE'): User
    {
        // Garantiza que los roles/permisos existan (mismo patrón que actingAsUser).
        if (\Spatie\Permission\Models\Role::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $user = User::factory()->create(['sucursal_id' => $sucursalId]);
        $user->assignRole($role);
        Acceso::create(['user_id' => $user->id, 'sucursal_id' => $sucursalId, 'estado' => 'ON']);

        $this->actingAs($user, 'sanctum');
        return $user;
    }

    // ───────────────── D1 · Fronteras de ESCRITURA entre sucursales ─────────────────

    /**
     * Las 6 escrituras (validar, destroy, updateEncabezado, agregarItem, updateItem,
     * deleteItem) sobre un pedido de OTRA sucursal deben devolver 403. Atacante:
     * GERENTE de la sucursal 3 (con todos los permisos de pedidos) intentando operar
     * un pedido de la sucursal 2. Si alguno NO devuelve 403 → IDOR (ALTA).
     */
    public function test_sucursal_ajena_no_puede_escribir_ninguno_de_los_6_endpoints(): void
    {
        // Pedido + detalle pertenecientes a la sucursal 2.
        $pedido = Pedido::factory()->create(['sucursal_id' => 2, 'estado' => 'PROFORMA']);
        $prod = Producto::factory()->create();
        $detalle = Pedidodetalle::create([
            'pedido_id' => $pedido->id, 'producto_id' => $prod->id,
            'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion,
            'marca' => '', 'cantidad' => 1, 'estado' => 'VALIDO',
        ]);

        // Atacante: usuario de la sucursal 3 (frontera != central).
        $this->userEnSucursal(3, 'GERENTE');

        $this->postJson("/api/pedidos/validar/{$pedido->id}")->assertStatus(403);
        $this->postJson('/api/pedidos/update-encabezado', ['pedido_id' => $pedido->id, 'observacion' => 'hack'])->assertStatus(403);
        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(403);
        $this->postJson('/api/pedidos/update-item', ['registro' => $detalle->id, 'cantidad' => 9])->assertStatus(403);
        $this->postJson("/api/pedidos/delete-item/{$detalle->id}")->assertStatus(403);
        $this->deleteJson("/api/pedidos/{$pedido->id}")->assertStatus(403);

        // Y nada cambió: el pedido sigue PROFORMA y el detalle intacto.
        $this->assertEquals('PROFORMA', $pedido->fresh()->estado);
        $this->assertEquals(1, (int) $detalle->fresh()->cantidad);
        $this->assertEquals('VALIDO', $detalle->fresh()->estado);
    }

    /**
     * La CENTRAL (sucursal 1) VE todos los pedidos (lectura) pero NO debe poder
     * ESCRIBIR sobre un pedido ajeno: el guard de escritura compara contra
     * `Auth::user()->sucursal_id` sin bypass de central. Caso difícil señalado por
     * el padre: ¿el que ve todo puede también editar todo? Debe ser 403.
     */
    public function test_central_ve_pero_no_escribe_pedido_ajeno(): void
    {
        $pedido = Pedido::factory()->create(['sucursal_id' => 2, 'estado' => 'PROFORMA']);
        $prod = Producto::factory()->create();
        $detalle = Pedidodetalle::create([
            'pedido_id' => $pedido->id, 'producto_id' => $prod->id,
            'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion,
            'marca' => '', 'cantidad' => 1, 'estado' => 'VALIDO',
        ]);

        // ADMIN está en sucursal 1 (central) — pero ADMIN tiene Gate::before (bypass de
        // PERMISOS, no de sucursal). Para aislar la frontera de SUCURSAL sin el ruido de
        // Gate::before usamos un GERENTE de la central: ve todo (lectura) por ser sid=1,
        // pero NO es ADMIN.
        $this->userEnSucursal(1, 'GERENTE');

        // LECTURA: la central SÍ ve el pedido ajeno (asimetría documentada).
        $this->getJson("/api/pedidos/{$pedido->id}")->assertStatus(200);
        $this->getJson("/api/pedidos/{$pedido->id}/detalles")->assertStatus(200);

        // ESCRITURA: la central NO puede mutar el pedido ajeno → 403 en las 6.
        $this->postJson("/api/pedidos/validar/{$pedido->id}")->assertStatus(403);
        $this->postJson('/api/pedidos/update-encabezado', ['pedido_id' => $pedido->id, 'observacion' => 'hack'])->assertStatus(403);
        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 1])->assertStatus(403);
        $this->postJson('/api/pedidos/update-item', ['registro' => $detalle->id, 'cantidad' => 9])->assertStatus(403);
        $this->postJson("/api/pedidos/delete-item/{$detalle->id}")->assertStatus(403);
        $this->deleteJson("/api/pedidos/{$pedido->id}")->assertStatus(403);

        $this->assertEquals('PROFORMA', $pedido->fresh()->estado);
    }

    /**
     * Frontera de LECTURA: una sucursal NO-central (2) no debe ver/listar pedidos de
     * la sucursal 3 (ni show, ni detalles, ni pdf). El list/kpis ya escopan por sid.
     */
    public function test_sucursal_no_central_no_lee_pedido_ajeno(): void
    {
        $ajeno = Pedido::factory()->create(['sucursal_id' => 3, 'estado' => 'VALIDO']);

        $this->userEnSucursal(2, 'GERENTE');

        $this->getJson("/api/pedidos/{$ajeno->id}")->assertStatus(403);
        $this->getJson("/api/pedidos/{$ajeno->id}/detalles")->assertStatus(403);
        $this->getJson("/api/pedidos/{$ajeno->id}/pdf")->assertStatus(403);
    }

    /**
     * El list de una sucursal NO-central no debe FILTRAR pedidos de otras sucursales
     * (no solo el show por id). Crea pedidos en sucursal 2 y 3; el usuario de la 2 solo
     * ve los suyos.
     */
    public function test_list_de_sucursal_no_central_solo_devuelve_los_propios(): void
    {
        $mio = Pedido::factory()->count(2)->create(['sucursal_id' => 2]);
        $ajeno = Pedido::factory()->count(3)->create(['sucursal_id' => 3]);

        $this->userEnSucursal(2, 'GERENTE');

        $ids = collect($this->getJson('/api/pedidos?take=100')->json('data'))->pluck('id')->all();
        foreach ($mio as $p) {
            $this->assertContains($p->id, $ids, 'debe ver los pedidos de su propia sucursal');
        }
        foreach ($ajeno as $p) {
            $this->assertNotContains($p->id, $ids, 'NO debe ver pedidos de la sucursal 3');
        }
    }

    // ───────────────── D3 · Máquina de estados (PROFORMA→VALIDO→ANULADO) ─────────────────

    /**
     * No se puede validar un pedido ya VALIDO ni uno ANULADO (transición ilegal → 422).
     * Solo PROFORMA puede validarse.
     */
    public function test_validar_estado_no_proforma_da_422(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $valido = Pedido::factory()->valido()->create(['sucursal_id' => $user->sucursal_id]);
        $anulado = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'ANULADO']);

        $this->postJson("/api/pedidos/validar/{$valido->id}")->assertStatus(422);
        $this->postJson("/api/pedidos/validar/{$anulado->id}")->assertStatus(422);
    }

    /**
     * No se puede agregar/editar/borrar ítem sobre un pedido VALIDO o ANULADO (los
     * mutadores de ítem solo operan sobre PROFORMA → 422).
     */
    public function test_no_se_mutan_items_sobre_pedido_validado_o_anulado(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $prod = Producto::factory()->create();

        foreach (['VALIDO', 'ANULADO'] as $estado) {
            $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => $estado]);
            $detalle = Pedidodetalle::create([
                'pedido_id' => $pedido->id, 'producto_id' => $prod->id,
                'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion,
                'marca' => '', 'cantidad' => 1, 'estado' => 'VALIDO',
            ]);

            $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 1])
                ->assertStatus(422);
            $this->postJson('/api/pedidos/update-item', ['registro' => $detalle->id, 'cantidad' => 9])
                ->assertStatus(422);
            $this->postJson("/api/pedidos/delete-item/{$detalle->id}")
                ->assertStatus(422);
            // Y el encabezado tampoco (updateEncabezado exige PROFORMA, devuelve 403).
            $this->postJson('/api/pedidos/update-encabezado', ['pedido_id' => $pedido->id, 'observacion' => 'x'])
                ->assertStatus(403);

            $this->assertEquals(1, (int) $detalle->fresh()->cantidad, "[$estado] el ítem no debió cambiar");
            $this->assertEquals('VALIDO', $detalle->fresh()->estado, "[$estado] el ítem no debió anularse");
        }
    }

    /**
     * destroy es IDEMPOTENTE: anular un pedido ya ANULADO no falla y deja el estado
     * ANULADO (no duplica efectos, no revive). Reintentar es seguro.
     */
    public function test_destroy_es_idempotente_sobre_anulado(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'ANULADO']);

        $this->deleteJson("/api/pedidos/{$pedido->id}")->assertStatus(200)->assertJsonPath('ok', true);
        $this->deleteJson("/api/pedidos/{$pedido->id}")->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertEquals('ANULADO', $pedido->fresh()->estado);
    }

    /**
     * Se puede anular un pedido ya VALIDO (transición VALIDO→ANULADO es válida; destroy
     * no exige PROFORMA, solo evita re-anular). Confirma que validado != bloqueado.
     */
    public function test_destroy_anula_pedido_validado(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $pedido = Pedido::factory()->valido()->create(['sucursal_id' => $user->sucursal_id]);

        $this->deleteJson("/api/pedidos/{$pedido->id}")->assertStatus(200);
        $this->assertEquals('ANULADO', $pedido->fresh()->estado);
    }

    // ───────────────── Ruta `duplicado` (agregarItem) ─────────────────

    /**
     * `duplicado` se evalúa SOLO contra detalles VALIDO. Si el producto ya está como
     * detalle pero fue ANULADO (deleteItem), volver a agregarlo NO debe contar como
     * duplicado: debe crear una nueva línea. Verifica que el guard de duplicado no se
     * dispara por renglones muertos.
     */
    public function test_duplicado_ignora_detalles_anulados(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);
        $prod = Producto::factory()->create();

        // Agrega y luego anula el renglón.
        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 2])
            ->assertStatus(200);
        $detalle = Pedidodetalle::where('pedido_id', $pedido->id)->where('producto_id', $prod->id)->first();
        $this->postJson("/api/pedidos/delete-item/{$detalle->id}")->assertStatus(200);

        // Re-agregar el mismo producto: con el renglón anterior ANULADO, NO es duplicado.
        $resp = $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 4]);
        $resp->assertStatus(200);
        $this->assertNotSame(true, $resp->json('duplicado'), 'un renglón anulado no debe bloquear como duplicado');

        // Debe existir un nuevo renglón VALIDO con la nueva cantidad.
        $this->assertDatabaseHas('pedidodetalles', [
            'pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 4, 'estado' => 'VALIDO',
        ]);
    }

    /**
     * `duplicado` NO crea una segunda línea: agregar dos veces el mismo producto VALIDO
     * deja exactamente UN renglón VALIDO (la 2da llamada responde {duplicado:true} sin
     * insertar). Idempotencia de la línea de detalle.
     */
    public function test_duplicado_no_crea_segunda_linea(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);
        $prod = Producto::factory()->create();

        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 2]);
        $this->postJson('/api/pedidos/agregar-item', ['pedido_id' => $pedido->id, 'producto_id' => $prod->id, 'cantidad' => 7])
            ->assertJsonPath('duplicado', true);

        $count = Pedidodetalle::where('pedido_id', $pedido->id)
            ->where('producto_id', $prod->id)->where('estado', 'VALIDO')->count();
        $this->assertEquals(1, $count, 'la ruta duplicado no debe crear una segunda línea VALIDO');
        // Y la cantidad sigue siendo la del primer renglón (la 2da no la pisó).
        $this->assertEquals(2, (int) Pedidodetalle::where('pedido_id', $pedido->id)
            ->where('producto_id', $prod->id)->where('estado', 'VALIDO')->first()->cantidad);
    }

    // ───────────────── D2 · Validación / fuzz de `observacion` y `search` ─────────────────

    /**
     * Contrato de longitud de `observacion` en `store`. La columna es varchar(191):
     * un valor entre 192 y 500 chars PASABA la validación (`max:500`) y luego reventaba
     * la inserción con un 500 (Data too long, col overflow) — mismo patrón que el bug de
     * `cantidad` del loop 2 (validador más laxo que la columna). Debe ser 422 limpio.
     * Contraprueba: 191 exactos (el ancho real) se persiste OK.
     */
    public function test_observacion_excede_la_columna_da_422_no_500_en_store(): void
    {
        $this->actingAsUser('ADMIN');

        // 192 chars: cabe en max:500 pero NO en varchar(191) → debe rechazarse con 422.
        $this->postJson('/api/pedidos', ['observacion' => str_repeat('A', 192)])
            ->assertStatus(422)->assertJsonValidationErrorFor('observacion');

        // Contraprueba: 191 exactos (ancho real de la columna) se persiste sin 500.
        $resp = $this->postJson('/api/pedidos', ['observacion' => str_repeat('B', 191)]);
        $resp->assertStatus(200);
        $this->assertDatabaseHas('pedidos', ['id' => $resp->json('id')]);
    }

    /**
     * Mismo contrato en `updateEncabezado`, que NO validaba longitud en absoluto: una
     * observacion larga sobre un pedido PROFORMA propio reventaba con 500. Debe ser 422.
     */
    public function test_observacion_excede_la_columna_da_422_no_500_en_update_encabezado(): void
    {
        $user = $this->actingAsUser('ADMIN');
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);

        $this->postJson('/api/pedidos/update-encabezado', [
            'pedido_id' => $pedido->id, 'observacion' => str_repeat('A', 600),
        ])->assertStatus(422);

        // Contraprueba: una observacion válida sí se persiste.
        $this->postJson('/api/pedidos/update-encabezado', [
            'pedido_id' => $pedido->id, 'observacion' => 'Reposición urgente sucursal Norte',
        ])->assertStatus(200);
        $this->assertEquals('Reposición urgente sucursal Norte', $pedido->fresh()->observacion);
    }

    /**
     * Payloads XSS/SQLi en `observacion` quedan INERTES: se persisten verbatim como
     * texto (sin ejecutar, sin romper la query) y se devuelven igual. No 500.
     */
    public function test_observacion_payload_xss_sqli_queda_inerte(): void
    {
        $this->actingAsUser('ADMIN');
        $payload = "<script>alert(1)</script>'; DROP TABLE pedidos;--";

        $resp = $this->postJson('/api/pedidos', ['observacion' => $payload]);
        $resp->assertStatus(200);
        // La tabla sigue existiendo y el texto se guardó literal (Eloquent parametriza).
        $this->assertDatabaseHas('pedidos', ['id' => $resp->json('id'), 'observacion' => $payload]);
    }

    /**
     * El filtro `search` con un payload SQLi no inyecta ni rompe: responde 200 (lista
     * vacía o filtrada), nunca 500. Eloquent parametriza el `like`/`where`.
     */
    public function test_search_sqli_no_inyecta_ni_rompe(): void
    {
        $user = $this->actingAsUser('ADMIN');
        Pedido::factory()->create(['sucursal_id' => $user->sucursal_id]);

        foreach (["' OR '1'='1", "%'; DROP TABLE pedidos;--", "#999999999999999", str_repeat('💥', 50)] as $needle) {
            $this->getJson('/api/pedidos?search=' . urlencode($needle))->assertStatus(200);
        }
        // La tabla sobrevive a los intentos.
        $this->assertDatabaseHas('pedidos', ['sucursal_id' => $user->sucursal_id]);
    }

    // ───────────────── D4/D5/D6 = N/A (demostración, no asunción) ─────────────────

    /**
     * Demuestra que Pedidos NO mueve stock ni caja: validar un pedido con detalles NO
     * altera ninguna columna stock1..stock5 del producto ni crea filas en `tranzas`.
     * Esto JUSTIFICA las celdas ➖ (D4/D5/D6) del AUDIT-MATRIX, en vez de asumirlas.
     */
    public function test_validar_pedido_no_toca_stock_ni_caja(): void
    {
        $user = $this->actingAsUser('ADMIN'); // sucursal 1
        $prod = Producto::factory()->create([
            'stock1' => 10, 'stock2' => 20, 'stock3' => 30, 'stock4' => 40, 'stock5' => 50,
        ]);
        $pedido = Pedido::factory()->create(['sucursal_id' => $user->sucursal_id, 'estado' => 'PROFORMA']);
        Pedidodetalle::create([
            'pedido_id' => $pedido->id, 'producto_id' => $prod->id,
            'codigo' => $prod->codigo, 'descripcion' => $prod->descripcion,
            'marca' => '', 'cantidad' => 4, 'estado' => 'VALIDO',
        ]);

        $tranzasAntes = \Illuminate\Support\Facades\DB::table('tranzas')->count();

        $this->postJson("/api/pedidos/validar/{$pedido->id}")->assertStatus(200);

        $fresh = Producto::find($prod->id);
        $this->assertEquals(10, (float) $fresh->stock1, 'validar NO debe tocar stock1');
        $this->assertEquals(20, (float) $fresh->stock2);
        $this->assertEquals(30, (float) $fresh->stock3);
        $this->assertEquals(40, (float) $fresh->stock4);
        $this->assertEquals(50, (float) $fresh->stock5);
        $this->assertEquals('VALIDO', $pedido->fresh()->estado);
        $this->assertEquals($tranzasAntes, \Illuminate\Support\Facades\DB::table('tranzas')->count(),
            'validar un pedido NO debe crear movimientos de caja');
    }
}
