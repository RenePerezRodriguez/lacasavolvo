<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Industria;
use App\Models\Localidad;
use App\Models\Marca;
use App\Models\Medio;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Auditoría adversarial de los DATOS RAÍZ — los 5 catálogos base del sistema:
 * Marcas, Industrias, Medios (de pago), Empresas y Localidades.
 *
 * Son CRUD simples con toggle ON/OFF (patrón `SimpleCrudScreen` en `admin.jsx`),
 * BAJO blast-radius (no mueven stock ni caja), pero son **5 controllers casi
 * idénticos** → se atacan COMO CLASE (igual que `NumericFuzzTest` con `cantidad`).
 *
 * El blast-radius real es D2 (integridad de datos / validación) y D1 (RBAC +
 * registro principal protegido):
 *
 *  - **D2 (update sin validación)**: `MarcaController`/`IndustriaController` validan
 *    `nombre` en store Y update; **`Medio`/`Empresa`/`Localidad` NO validan en
 *    `update`** → un `update` SIN `nombre` mete NULL en columna NOT NULL (500), y un
 *    `nombre` que excede la columna `varchar(191)` revienta el UPDATE con 1406 (500).
 *    Debe ser 422 limpio. Misma clase recurrente que `cantidad` (loop 2), `observacion`
 *    de pedidos (loop 14) y los 5 campos de cuentas (loop 17).
 *  - **Anchos verificados empíricamente** en `tienda_test`: las 5 columnas `nombre`
 *    son `varchar(191) NOT NULL`. El `max:191` del store de Medio/Empresa/Localidad
 *    está alineado; el `max:100` de Marca/Industria es más estricto (no rompe).
 *  - **D1 (RBAC)**: según `PermissionsSeeder`, VENDEDOR tiene SOLO `*.index` de los 5
 *    catálogos (lectura) — NO `*.create`/`*.edit`/`*.destroy`. Un VENDEDOR escribiendo
 *    DEBE recibir 403; ADMIN (Gate::before) → 200.
 *  - **D1/D10 (registro principal)**: id==1 (Empresa usa `id <= 1`) no se puede
 *    editar/togglear/eliminar → 403 y estado intacto.
 *  - **D3/D10**: toggle alterna ON↔OFF y es reversible (doble-toggle vuelve al original).
 */
class DatosRaizAuditTest extends TestCase
{
    /** Cadena de N caracteres ASCII de 1 byte. */
    private function str(int $n): string
    {
        return str_repeat('A', $n);
    }

    /**
     * Catálogos cuyo `update` NO validaba `nombre` (los 3 con el hueco D2).
     * [ruta, claseModelo]
     */
    public static function catalogosSinValidacionUpdate(): array
    {
        return [
            'medios'      => ['medios', Medio::class],
            'empresas'    => ['empresas', Empresa::class],
            'localidades' => ['localidades', Localidad::class],
        ];
    }

    /**
     * Los 5 catálogos: [prefijoRuta, claseModelo, soportaDestroy].
     * El prefijo de la ruta NO siempre coincide con la tabla (localidades→localidads).
     */
    public static function todosLosCatalogos(): array
    {
        return [
            'marcas'      => ['marcas', Marca::class, false],
            'industrias'  => ['industrias', Industria::class, false],
            'medios'      => ['medios', Medio::class, false],
            'empresas'    => ['empresas', Empresa::class, true],
            'localidades' => ['localidades', Localidad::class, true],
        ];
    }

    /** Crea una fila de catálogo con id ≠ 1 (para no chocar con el registro principal). */
    private function nuevoCatalogo(string $modelo): object
    {
        /** @var \Illuminate\Database\Eloquent\Model $modelo */
        return $modelo::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────
    // D2 — UPDATE sin validación: overflow de columna → debe ser 422, no 500
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('catalogosSinValidacionUpdate')]
    public function test_update_nombre_excede_la_columna_da_422_no_500(string $ruta, string $modelo): void
    {
        $this->actingAsUser('ADMIN');
        $row = $this->nuevoCatalogo($modelo);

        // 192 chars > varchar(191) → ANTES del fix: 500 (PDOException 1406).
        // DESPUÉS: 422 limpio (contrato D2).
        $response = $this->putJson("/api/{$ruta}/{$row->id}", ['nombre' => $this->str(192)]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('nombre');
    }

    #[DataProvider('catalogosSinValidacionUpdate')]
    public function test_update_sin_nombre_da_422_no_500(string $ruta, string $modelo): void
    {
        $this->actingAsUser('ADMIN');
        $row = $this->nuevoCatalogo($modelo);

        // Sin `nombre` → ANTES del fix: NULL en columna NOT NULL → 500 (1048).
        // DESPUÉS: 422 (required).
        $response = $this->putJson("/api/{$ruta}/{$row->id}", []);

        $response->assertStatus(422)->assertJsonValidationErrorFor('nombre');
        // El nombre original NO se sobrescribió con NULL.
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'nombre' => $row->nombre]);
    }

    #[DataProvider('catalogosSinValidacionUpdate')]
    public function test_update_nombre_vacio_da_422(string $ruta, string $modelo): void
    {
        $this->actingAsUser('ADMIN');
        $row = $this->nuevoCatalogo($modelo);

        $response = $this->putJson("/api/{$ruta}/{$row->id}", ['nombre' => '']);

        $response->assertStatus(422)->assertJsonValidationErrorFor('nombre');
    }

    // ─────────────────────────────────────────────────────────────────────
    // D2 — Bordes válidos: 191 chars debe ENTRAR en los 5 (no rechazar de más)
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('catalogosSinValidacionUpdate')]
    public function test_update_nombre_borde_191_se_acepta(string $ruta, string $modelo): void
    {
        $this->actingAsUser('ADMIN');
        $row = $this->nuevoCatalogo($modelo);
        $nombre = $this->str(191);

        $response = $this->putJson("/api/{$ruta}/{$row->id}", ['nombre' => $nombre]);

        $response->assertStatus(200);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'nombre' => $nombre]);
    }

    public function test_update_marca_e_industria_ya_validan_nombre(): void
    {
        // Marca/Industria SÍ validaban (max:100). Se fija el contrato: 101 chars
        // (entre su max:100 y la columna 191) → 422, nunca 500. Esto blinda contra
        // una regresión que "relaje" su validador al de los otros tres.
        $this->actingAsUser('ADMIN');

        foreach (['marcas' => Marca::class, 'industrias' => Industria::class] as $ruta => $modelo) {
            $row = $this->nuevoCatalogo($modelo);
            $this->putJson("/api/{$ruta}/{$row->id}", ['nombre' => $this->str(101)])
                ->assertStatus(422)->assertJsonValidationErrorFor('nombre');
            $this->putJson("/api/{$ruta}/{$row->id}", [])
                ->assertStatus(422)->assertJsonValidationErrorFor('nombre');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // D1 — RBAC de escritura: VENDEDOR (solo *.index) → 403 en TODA escritura
    //      Atacado COMO CLASE en los 5 controllers × {store, update, toggle}.
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('todosLosCatalogos')]
    public function test_vendedor_no_puede_crear(string $ruta, string $modelo, bool $destroy): void
    {
        // VENDEDOR pasa el middleware de ruta (tiene *.index del grupo OR) pero
        // NO tiene *.create → el Gate::authorize del controller debe abortar con 403.
        $this->actingAsUser('VENDEDOR');

        $response = $this->postJson("/api/{$ruta}", ['nombre' => 'Inyectado por Vendedor']);

        $response->assertStatus(403);
        $this->assertDatabaseMissing((new $modelo)->getTable(), ['nombre' => 'Inyectado por Vendedor']);
    }

    #[DataProvider('todosLosCatalogos')]
    public function test_vendedor_no_puede_editar(string $ruta, string $modelo, bool $destroy): void
    {
        $this->actingAsUser('VENDEDOR');
        $row = $this->nuevoCatalogo($modelo);
        $original = $row->nombre;

        $response = $this->putJson("/api/{$ruta}/{$row->id}", ['nombre' => 'HACKED']);

        $response->assertStatus(403);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'nombre' => $original]);
    }

    #[DataProvider('todosLosCatalogos')]
    public function test_vendedor_no_puede_togglear(string $ruta, string $modelo, bool $destroy): void
    {
        $this->actingAsUser('VENDEDOR');
        $row = $this->nuevoCatalogo($modelo); // estado ON
        $this->getJson("/api/{$ruta}/{$row->id}/toggle")->assertStatus(403);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'estado' => 'ON']);
    }

    #[DataProvider('todosLosCatalogos')]
    public function test_admin_si_puede_crear(string $ruta, string $modelo, bool $destroy): void
    {
        // Red de seguridad positiva: el 403 del VENDEDOR no es un fallo global del
        // endpoint — ADMIN (Gate::before) SÍ crea (200).
        $this->actingAsUser('ADMIN');

        $response = $this->postJson("/api/{$ruta}", ['nombre' => 'Catalogo del Admin']);

        $response->assertStatus(200)->assertJsonStructure(['id']);
        $this->assertDatabaseHas((new $modelo)->getTable(), ['nombre' => 'Catalogo del Admin']);
    }

    public function test_vendedor_no_puede_eliminar_empresa(): void
    {
        // Empresa es el ÚNICO catálogo con DELETE expuesto por la API (soft-delete = OFF).
        // VENDEDOR no tiene `empresas.destroy` → 403.
        // (Localidades NO tiene ruta DELETE — el método `LocalidadController::destroy`
        //  existe pero está sin rutear; se documenta como código muerto en el ledger.)
        $this->actingAsUser('VENDEDOR');

        $row = $this->nuevoCatalogo(Empresa::class);
        $this->deleteJson("/api/empresas/{$row->id}")->assertStatus(403);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'estado' => 'ON']);
    }

    public function test_localidades_no_expone_ruta_delete(): void
    {
        // Contrato: el frontend nunca borra localidades (sin `destroy` en el api client);
        // la ruta DELETE NO existe → 405. Fija que el método dead-code de `destroy` NO se
        // exponga accidentalmente en una refactorización futura.
        $this->actingAsUser('ADMIN');
        $row = $this->nuevoCatalogo(Localidad::class);

        $this->deleteJson("/api/localidades/{$row->id}")->assertStatus(405);
        // No se borró ni se desactivó.
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'estado' => 'ON']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D1/D10 — Registro principal (id==1, Empresa id<=1) inmutable
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('todosLosCatalogos')]
    public function test_no_se_puede_editar_el_registro_principal(string $ruta, string $modelo, bool $destroy): void
    {
        $this->actingAsUser('ADMIN');
        $modelo::find(1) ?? $modelo::factory()->create(['id' => 1, 'nombre' => 'PRINCIPAL', 'estado' => 'ON']);
        $previo = $modelo::find(1)->nombre;

        $response = $this->putJson("/api/{$ruta}/1", ['nombre' => 'HACKED PRINCIPAL']);

        $response->assertStatus(403);
        $this->assertDatabaseHas((new $modelo)->getTable(), ['id' => 1, 'nombre' => $previo]);
        $this->assertDatabaseMissing((new $modelo)->getTable(), ['id' => 1, 'nombre' => 'HACKED PRINCIPAL']);
    }

    #[DataProvider('todosLosCatalogos')]
    public function test_no_se_puede_togglear_el_registro_principal(string $ruta, string $modelo, bool $destroy): void
    {
        $this->actingAsUser('ADMIN');
        $modelo::find(1) ?? $modelo::factory()->create(['id' => 1, 'nombre' => 'PRINCIPAL', 'estado' => 'ON']);
        // Asegura estado conocido ON.
        $modelo::where('id', 1)->update(['estado' => 'ON']);

        $response = $this->getJson("/api/{$ruta}/1/toggle");

        $response->assertStatus(403);
        $this->assertDatabaseHas((new $modelo)->getTable(), ['id' => 1, 'estado' => 'ON']);
    }

    public function test_no_se_puede_eliminar_el_registro_principal_empresa(): void
    {
        // Empresa es el único catálogo con DELETE; el registro principal (id<=1) es inmutable.
        $this->actingAsUser('ADMIN');

        Empresa::find(1) ?? Empresa::factory()->create(['id' => 1, 'nombre' => 'PRINCIPAL', 'estado' => 'ON']);
        Empresa::where('id', 1)->update(['estado' => 'ON']);

        $this->deleteJson('/api/empresas/1')->assertStatus(403);
        $this->assertDatabaseHas('empresas', ['id' => 1, 'estado' => 'ON']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D3/D10 — toggle alterna y es reversible (idempotencia de la operación par)
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('todosLosCatalogos')]
    public function test_toggle_alterna_y_doble_toggle_vuelve_al_original(string $ruta, string $modelo, bool $destroy): void
    {
        $this->actingAsUser('ADMIN');
        $row = $this->nuevoCatalogo($modelo); // ON

        $this->getJson("/api/{$ruta}/{$row->id}/toggle")->assertStatus(200);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'estado' => 'OFF']);

        $this->getJson("/api/{$ruta}/{$row->id}/toggle")->assertStatus(200);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'estado' => 'ON']);
    }

    public function test_destroy_empresa_es_soft_off_y_toggle_lo_reactiva(): void
    {
        // Empresa: destroy = soft-delete (estado OFF), recuperable por toggle (no hard-delete).
        $this->actingAsUser('ADMIN');

        $row = $this->nuevoCatalogo(Empresa::class);

        $this->deleteJson("/api/empresas/{$row->id}")->assertStatus(200);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'estado' => 'OFF']);

        // Sigue existiendo y se reactiva con toggle.
        $this->getJson("/api/empresas/{$row->id}/toggle")->assertStatus(200);
        $this->assertDatabaseHas($row->getTable(), ['id' => $row->id, 'estado' => 'ON']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D2 — SQLi / XSS en `nombre` quedan inertes (verbatim, sin 500)
    // ─────────────────────────────────────────────────────────────────────

    public function test_sqli_xss_en_nombre_quedan_inertes(): void
    {
        $this->actingAsUser('ADMIN');

        $payloads = ["'; DROP TABLE marcas; --", '<script>alert(1)</script>', "' OR '1'='1"];
        foreach (['marcas' => Marca::class, 'medios' => Medio::class] as $ruta => $modelo) {
            foreach ($payloads as $p) {
                $response = $this->postJson("/api/{$ruta}", ['nombre' => $p]);
                $response->assertStatus(200);
                // Persistido verbatim (sin ejecutar, sin escapar en BD).
                $this->assertDatabaseHas((new $modelo)->getTable(), ['id' => $response->json('id'), 'nombre' => $p]);
            }
            // La tabla sigue existiendo tras los payloads.
            $this->assertDatabaseHas((new $modelo)->getTable(), ['nombre' => "' OR '1'='1"]);
        }
    }
}
