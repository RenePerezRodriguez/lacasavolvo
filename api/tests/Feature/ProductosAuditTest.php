<?php

namespace Tests\Feature;

use App\Models\Ajuste;
use App\Models\Marca;
use App\Models\Producto;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo PRODUCTOS — foco en los AJUSTES manuales de stock
 * (mayor blast-radius: mutan inventario sin venta/compra de por medio).
 *
 * Invariantes atacadas:
 *  - NO-NEGATIVIDAD de stock: ninguna operación deja stockN < 0 (corrupción de inventario).
 *  - NO doble-revert: destruir un ajuste OFF dos veces no debe revertir el stock dos veces.
 *  - Idempotencia / conservación: ajuste+destroy vuelve EXACTO al estado previo.
 *  - SQLi: el nombre de columna stock{sid} y la búsqueda nunca deben permitir inyección.
 *  - Precios: p_comp/p_norm/p_fact negativos envenenan valor_inventario y ventas futuras.
 *  - Frontera de sucursal (IDOR): ningún endpoint ajusta/lee stock de otra sucursal.
 *
 * Casos difíciles PRIMERO (negativo, doble-revert, límite, estados OFF).
 */
class ProductosAuditTest extends TestCase
{
    private function stock(int $pid, int $suc = 1): int
    {
        return (int) Producto::find($pid)->{'stock' . $suc};
    }

    // ════════════════ 1. AJUSTE NEGATIVO → STOCK NEGATIVO (D4, núcleo) ════════════════

    /**
     * CASO DIFÍCIL: ajustar negativamente MÁS de lo que hay en stock.
     * Producto con stock1=2, ajuste-negativo de 5 → ¿stock1 = -3?
     * Stock físico negativo es corrupción de inventario (no se pueden tener -3 piezas)
     * y envenena valor_inventario / KPIs / ventas futuras.
     */
    public function test_ajuste_negativo_no_deja_stock_negativo(): void
    {
        $user = $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 2]);

        $response = $this->postJson('/api/productos/ajuste-negativo', [
            'producto_id' => $producto->id,
            'cantidad'    => 5,
        ]);

        // Debe rechazarse (422) — no se puede sacar más de lo que hay.
        $response->assertStatus(422);
        // El stock NO debe haber bajado de 0 ni mutado.
        $this->assertEquals(2, $this->stock($producto->id), 'El stock no debe quedar negativo');
        // No debe haberse persistido el ajuste fallido.
        $this->assertDatabaseMissing('ajustes', [
            'producto_id' => $producto->id, 'tipo' => 'NEGATIVO', 'estado' => 'ON',
        ]);
    }

    /**
     * Límite exacto: ajustar negativamente EXACTAMENTE el stock disponible → 0 (permitido).
     */
    public function test_ajuste_negativo_hasta_cero_es_valido(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 5]);

        $this->postJson('/api/productos/ajuste-negativo', [
            'producto_id' => $producto->id, 'cantidad' => 5,
        ])->assertStatus(200);

        $this->assertEquals(0, $this->stock($producto->id));
    }

    /**
     * Borde: producto en stock 0, cualquier ajuste negativo → 422 (no puede ir a -N).
     */
    public function test_ajuste_negativo_sobre_stock_cero_se_rechaza(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 0]);

        $this->postJson('/api/productos/ajuste-negativo', [
            'producto_id' => $producto->id, 'cantidad' => 1,
        ])->assertStatus(422);

        $this->assertEquals(0, $this->stock($producto->id));
    }

    // ════════════════ 2. AJUSTE DESTROY — DOBLE-REVERT (D4, gemelo deleteItemDev) ════════════════

    /**
     * CASO DIFÍCIL: destruir el MISMO ajuste positivo dos veces (doble-submit).
     * destroy revierte el stock (POSITIVO→resta) y pone estado=OFF. Si no hay guard
     * estado==='ON' ANTES de revertir, la 2ª destrucción revierte OTRA VEZ → doble-conteo.
     */
    public function test_ajuste_destroy_doble_no_revierte_stock_dos_veces_positivo(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id, 'cantidad' => 5,
        ])->assertStatus(200);
        $this->assertEquals(15, $this->stock($producto->id));

        $ajuste = Ajuste::where('producto_id', $producto->id)->latest()->first();

        // 1ª destrucción: revierte (15 - 5 = 10).
        $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id])->assertStatus(200);
        $this->assertEquals(10, $this->stock($producto->id));

        // 2ª destrucción del MISMO ajuste (ya OFF): NO debe volver a restar.
        $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id]);
        $this->assertEquals(10, $this->stock($producto->id), 'Doble destroy no debe revertir el stock dos veces');
    }

    /**
     * Variante NEGATIVO: doble destroy de un ajuste negativo no debe SUMAR stock dos veces.
     */
    public function test_ajuste_destroy_doble_no_revierte_stock_dos_veces_negativo(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 10]);

        $this->postJson('/api/productos/ajuste-negativo', [
            'producto_id' => $producto->id, 'cantidad' => 4,
        ])->assertStatus(200);
        $this->assertEquals(6, $this->stock($producto->id));

        $ajuste = Ajuste::where('producto_id', $producto->id)->latest()->first();

        $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id])->assertStatus(200);
        $this->assertEquals(10, $this->stock($producto->id)); // 6 + 4

        // 2ª destrucción (ya OFF): NO debe sumar otra vez.
        $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajuste->id]);
        $this->assertEquals(10, $this->stock($producto->id), 'Doble destroy negativo no debe inflar el stock');
    }

    /**
     * CASO DIFÍCIL (2º orden): revertir un ajuste POSITIVO cuyo stock ya fue consumido
     * por ajustes negativos posteriores NO debe dejar el stock negativo.
     * stock=5 → +10 (15) → -12 (3) → destroy(+10) ⇒ 3-10 = -7 si no hay floor.
     */
    public function test_ajuste_destroy_positivo_no_deja_stock_negativo(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['stock1' => 5]);

        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id, 'cantidad' => 10,
        ])->assertStatus(200);                                   // 15
        $ajustePos = Ajuste::where('producto_id', $producto->id)->where('tipo', 'POSITIVO')->latest()->first();

        $this->postJson('/api/productos/ajuste-negativo', [
            'producto_id' => $producto->id, 'cantidad' => 12,
        ])->assertStatus(200);                                   // 3
        $this->assertEquals(3, $this->stock($producto->id));

        // Revertir el +10 dejaría 3-10 = -7 → debe rechazarse (422), stock intacto.
        $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $ajustePos->id])
            ->assertStatus(422);
        $this->assertEquals(3, $this->stock($producto->id), 'Revertir el ajuste positivo no debe dejar stock negativo');
        // El ajuste sigue VIVO (no se marcó OFF al rechazar).
        $this->assertDatabaseHas('ajustes', ['id' => $ajustePos->id, 'estado' => 'ON']);
    }

    // ════════════════ 3. METAMÓRFICA / SIMETRÍA ════════════════

    /**
     * Metamórfica: ajuste+N seguido de destroy vuelve EXACTO al stock previo.
     * ajuste+N ≡ ajuste+(N/2)+ajuste+(N/2) en stock final.
     */
    public function test_ajuste_positivo_split_equivale_a_combinado(): void
    {
        $this->actingAsUser();
        $pA = Producto::factory()->create(['stock1' => 7]);
        $pB = Producto::factory()->create(['stock1' => 7]);

        // combinado: +8
        $this->postJson('/api/productos/ajuste-positivo', ['producto_id' => $pA->id, 'cantidad' => 8])->assertStatus(200);
        // split: +4 +4
        $this->postJson('/api/productos/ajuste-positivo', ['producto_id' => $pB->id, 'cantidad' => 4])->assertStatus(200);
        $this->postJson('/api/productos/ajuste-positivo', ['producto_id' => $pB->id, 'cantidad' => 4])->assertStatus(200);

        $this->assertEquals($this->stock($pA->id), $this->stock($pB->id));
        $this->assertEquals(15, $this->stock($pA->id));
    }

    // ════════════════ 4. STATEFUL PBT DE STOCK (cadena sembrada determinista) ════════════════

    /**
     * Property-based / stateful: encadena ajuste+/ajuste-/destroy con una semilla fija
     * y verifica tras CADA paso:
     *   - stock1 >= 0 SIEMPRE (no-negatividad)
     *   - stock1 == stock_inicial + Σ(ajustes POSITIVO ON) - Σ(ajustes NEGATIVO ON)
     *     (consistencia: el stock es exactamente la suma de los movimientos vivos)
     */
    public function test_pbt_cadena_de_ajustes_conserva_no_negatividad_y_consistencia(): void
    {
        $this->actingAsUser();

        // PRNG determinista AUTOCONTENIDO (LCG) — NO usa mt_rand/inRandomOrder, que dependen
        // de estado global / RANDOM() de SQL y volvían el test no reproducible entre corridas
        // (un test no-determinista NO puede ir a la suite verde). Mismo orden siempre.
        $seed = 20260616;
        $rng = function (int $mod) use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            return $seed % $mod;
        };

        $inicial = 50;
        $producto = Producto::factory()->create(['stock1' => $inicial]);

        for ($i = 0; $i < 60; $i++) {
            $dado = $rng(10);

            if ($dado <= 4) {
                $cant = $rng(20) + 1; // 1..20
                $this->postJson('/api/productos/ajuste-positivo', [
                    'producto_id' => $producto->id, 'cantidad' => $cant,
                ]);
            } elseif ($dado <= 8) {
                $cant = $rng(30) + 1; // 1..30 (puede exceder el stock → 422, sin corromper)
                $this->postJson('/api/productos/ajuste-negativo', [
                    'producto_id' => $producto->id, 'cantidad' => $cant,
                ]);
            } else {
                // destruir un ajuste vivo elegido de forma determinista (por posición del PRNG)
                $vivos = Ajuste::where('producto_id', $producto->id)->where('estado', 'ON')
                    ->orderBy('id')->pluck('id')->all();
                if (count($vivos) > 0) {
                    $id = $vivos[$rng(count($vivos))];
                    $this->postJson('/api/productos/ajuste-destroy', ['ajuste_id' => $id]);
                }
            }

            // ── Invariantes tras cada paso ──
            $stock = $this->stock($producto->id);
            $this->assertGreaterThanOrEqual(0, $stock, "Stock negativo en paso $i (seed 20260616)");

            // Consistencia: el stock vivo es exactamente inicial + Σpos(ON) - Σneg(ON).
            // (Solo se aplican ajustes que NO violan la no-negatividad; los rechazados con
            //  422 no mutan ni el stock ni el estado, así que la igualdad se mantiene.)
            $sumPos = (int) Ajuste::where('producto_id', $producto->id)
                ->where('estado', 'ON')->where('tipo', 'POSITIVO')->sum('cantidad');
            $sumNeg = (int) Ajuste::where('producto_id', $producto->id)
                ->where('estado', 'ON')->where('tipo', 'NEGATIVO')->sum('cantidad');

            $this->assertEquals(
                $inicial + $sumPos - $sumNeg,
                $stock,
                "Stock != inicial + Σpos(ON) - Σneg(ON) en paso $i (seed 20260616)"
            );
        }
    }

    // ════════════════ 5. SQLi — columna stock{sid} y búsqueda (regresión) ════════════════

    /**
     * El nombre de columna stock{sid} en api()/kpis() debe venir SIEMPRE de
     * Auth::user()->sucursal_id (int del token). Un query param manipulable (sort, search,
     * marca_id) NO debe poder inyectar el nombre de columna ni romper el SQL.
     */
    public function test_sqli_en_params_de_lista_no_inyecta_ni_rompe(): void
    {
        $this->actingAsUser();
        Producto::factory()->create(['codigo' => 'SAFE-001', 'descripcion' => 'INTACTO']);

        $payloads = [
            'a\\',
            "' OR '1'='1",
            '; DROP TABLE productos; --',
            "stock1 AND 1=1",
            'ٳٳٳ',                // unicode
            '٣',                  // dígito árabe
            "1); DELETE FROM productos WHERE ('1'='1",
        ];

        foreach ($payloads as $p) {
            // search en lista (usa buildRelevanceSQL)
            $this->getJson('/api/productos?search=' . urlencode($p))
                ->assertStatus(200)->assertJsonStructure(['total', 'data']);
            // quicksearch (otro punto que usa buildRelevanceSQL)
            $this->getJson('/api/productos/quicksearch?search=' . urlencode($p))
                ->assertStatus(200);
            // sort manipulable (debe caer al whitelist, nunca inyectar)
            $this->getJson('/api/productos?sort=' . urlencode($p) . '&dir=' . urlencode($p))
                ->assertStatus(200)->assertJsonStructure(['total', 'data']);
            // marca_id / industria_id como inyección
            $this->getJson('/api/productos?marca_id=' . urlencode($p))
                ->assertStatus(200)->assertJsonStructure(['total', 'data']);
        }

        // La tabla sigue intacta tras todos los intentos.
        $this->assertDatabaseHas('productos', ['codigo' => 'SAFE-001']);
    }

    // ════════════════ 6. PRECIOS NEGATIVOS (D2/D6) ════════════════

    /**
     * store con p_norm/p_comp/p_fact negativos debe rechazarse (422): un p_norm negativo
     * envenena valor_inventario (KPI = stockN * p_norm) y el precio de futuras ventas.
     */
    public function test_store_rechaza_precios_negativos(): void
    {
        $this->actingAsUser();
        $marca = Marca::factory()->create();
        $industria = \App\Models\Industria::factory()->create();

        foreach (['p_comp', 'p_norm', 'p_fact'] as $campo) {
            $this->postJson('/api/productos', [
                'codigo'       => 'NEG-' . $campo,
                'descripcion'  => 'Precio negativo',
                'marca_id'     => $marca->id,
                'industria_id' => $industria->id,
                $campo         => -10,
            ])->assertStatus(422)->assertJsonValidationErrorFor($campo);
        }
    }

    /**
     * update con precios negativos también se rechaza (no debe envenenar un producto existente).
     */
    public function test_update_rechaza_precios_negativos(): void
    {
        $this->actingAsUser();
        $producto = Producto::factory()->create(['p_norm' => 100]);

        $this->putJson("/api/productos/{$producto->id}", [
            'codigo'       => $producto->codigo,
            'descripcion'  => $producto->descripcion,
            'marca_id'     => $producto->marca_id,
            'industria_id' => $producto->industria_id,
            'p_norm'       => -50,
        ])->assertStatus(422)->assertJsonValidationErrorFor('p_norm');

        $this->assertEquals(100.0, (float) Producto::find($producto->id)->p_norm);
    }

    // ════════════════ 7. ESTADOS OFF (D3/D10) ════════════════

    /**
     * Un producto soft-deleted (estado=OFF) no debe aparecer en lista ni quicksearch.
     */
    public function test_producto_off_no_aparece_en_lista_ni_quicksearch(): void
    {
        $this->actingAsUser();
        $off = Producto::factory()->create(['codigo' => 'OFF-ZZZ-1', 'estado' => 'OFF']);

        $lista = $this->getJson('/api/productos?search=OFF-ZZZ-1')->assertStatus(200);
        $ids = collect($lista->json('data'))->pluck('id');
        $this->assertNotContains($off->id, $ids, 'Producto OFF no debe listarse');

        $qs = $this->getJson('/api/productos/quicksearch?search=OFF-ZZZ-1')->assertStatus(200);
        $this->assertEmpty($qs->json(), 'Producto OFF no debe salir en quicksearch');
    }

    // ════════════════ 8. IDOR de sucursal en ajustes ════════════════

    /**
     * Un ajuste opera SOLO sobre la columna de la sucursal del token. No debe existir vía
     * (query param sucursal_id) para tocar el stock de OTRA sucursal.
     */
    public function test_ajuste_solo_toca_la_columna_de_la_sucursal_del_token(): void
    {
        $this->actingAsUser(); // sucursal 1
        $producto = Producto::factory()->create(['stock1' => 10, 'stock2' => 20]);

        // Intentar inyectar sucursal_id=2 por el cuerpo.
        $this->postJson('/api/productos/ajuste-positivo', [
            'producto_id' => $producto->id, 'cantidad' => 5, 'sucursal_id' => 2,
        ])->assertStatus(200);

        $fresh = Producto::find($producto->id);
        $this->assertEquals(15, (int) $fresh->stock1, 'Debe tocar SOLO stock1 (token)');
        $this->assertEquals(20, (int) $fresh->stock2, 'stock2 (otra sucursal) NO debe cambiar');
    }
}
