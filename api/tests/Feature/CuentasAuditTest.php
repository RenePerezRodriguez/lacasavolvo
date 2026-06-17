<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Auditoría adversarial del módulo CUENTAS (catálogo de clientes/proveedores).
 *
 * Cuentas es BAJO blast-radius: NO mueve stock ni caja; `cuentas.saldo` es un
 * campo heredado ESTÁTICO (no lo computa la app). El blast-radius real es
 * D2 (validación/integridad de datos) y D1 (RBAC + cuenta principal protegida).
 *
 * Casos difíciles primero:
 *  - Overflow de columnas no validadas (`nit`/`email`/`telefono`/`direccion`/
 *    `departamento`) → un input que pasa la validación NO debe reventar la
 *    inserción con 500 (PDOException 1406). Anchos reales en `tienda_test`:
 *    varchar(191) en todas salvo `email` que es varchar(255). Cubre store Y update.
 *  - Whitelist de `tipo` en store Y update.
 *  - `saldo` NO inyectable por body (confirma la celda D6 ➖ del matrix).
 *  - RBAC: rol sin `cuentas.*` → 403 en write; VENDEDOR SÍ crea cliente.
 *  - Cuenta principal id==1 inmutable por update/toggle.
 */
class CuentasAuditTest extends TestCase
{
    /** Cadena de longitud arbitraria de un carácter ASCII de 1 byte. */
    private function str(int $n): string
    {
        return str_repeat('A', $n);
    }

    /** Columnas varchar(191) insertadas sin `max:` en el validador original. */
    public static function campos191(): array
    {
        return [
            'nit'          => ['nit'],
            'telefono'     => ['telefono'],
            'direccion'    => ['direccion'],
            'departamento' => ['departamento'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // D2 — Overflow de columnas no validadas en STORE (varchar(191))
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('campos191')]
    public function test_store_campo_191_que_excede_la_columna_da_422_no_500(string $campo): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Cuenta Overflow',
            'tipo'   => 'CLIENTE',
            $campo   => $this->str(192),
        ]);

        // ANTES del fix: 500 (PDOException 1406 "Data too long").
        // DESPUÉS: 422 limpio (contrato D2).
        $response->assertStatus(422)->assertJsonValidationErrorFor($campo);
    }

    public function test_store_email_que_excede_255_da_422_no_500(): void
    {
        $this->actingAsUser();

        // email es varchar(255) → el borde de overflow está en 256, no en 192.
        $largo = $this->str(250) . '@x.com'; // 256 chars
        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Cuenta Email Overflow',
            'tipo'   => 'CLIENTE',
            'email'  => $largo,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_store_email_largo_pero_dentro_de_255_se_acepta(): void
    {
        // Contrato: el límite de email es 255, NO 191 — un email largo válido
        // (196 chars) debe ENTRAR, no rechazarse de más.
        $this->actingAsUser();

        $email = $this->str(190) . '@x.com'; // 196 chars, < 255
        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Cuenta Email Largo Valido',
            'tipo'   => 'CLIENTE',
            'email'  => $email,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cuentas', ['id' => $response->json('id'), 'email' => $email]);
    }

    public function test_store_campos_en_el_borde_191_se_aceptan(): void
    {
        // Exactamente 191 chars debe caber (no rechazar de más).
        $this->actingAsUser();

        $response = $this->postJson('/api/cuentas', [
            'nombre'       => 'Cuenta Borde 191',
            'tipo'         => 'CLIENTE',
            'nit'          => $this->str(191),
            'telefono'     => $this->str(191),
            'direccion'    => $this->str(191),
            'departamento' => $this->str(191),
        ]);

        $response->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D2 — Overflow de columnas no validadas en UPDATE
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('campos191')]
    public function test_update_campo_191_que_excede_la_columna_da_422_no_500(string $campo): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();

        $response = $this->putJson("/api/cuentas/{$cuenta->id}", [
            'nombre' => 'Cuenta Overflow Update',
            'tipo'   => 'CLIENTE',
            $campo   => $this->str(192),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor($campo);
    }

    public function test_update_email_que_excede_255_da_422_no_500(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();

        $response = $this->putJson("/api/cuentas/{$cuenta->id}", [
            'nombre' => 'Cuenta Email Update',
            'tipo'   => 'CLIENTE',
            'email'  => $this->str(250) . '@x.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    // ─────────────────────────────────────────────────────────────────────
    // D2 — Whitelist de `tipo` en UPDATE (store ya cubierto en CuentasTest)
    // ─────────────────────────────────────────────────────────────────────

    public function test_update_tipo_invalido_da_422(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create();

        $response = $this->putJson("/api/cuentas/{$cuenta->id}", [
            'nombre' => 'X',
            'tipo'   => 'HACKER',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('tipo');
    }

    // ─────────────────────────────────────────────────────────────────────
    // D6 — `saldo` NO inyectable por body (confirma celda ➖ del matrix)
    // ─────────────────────────────────────────────────────────────────────

    public function test_store_ignora_saldo_inyectado_por_body(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Cuenta Saldo Inyectado',
            'tipo'   => 'CLIENTE',
            'saldo'  => 999999,
        ]);

        $response->assertStatus(200);
        // El saldo NO se setea desde el body: queda en el default de columna (0).
        $this->assertDatabaseHas('cuentas', ['id' => $response->json('id'), 'saldo' => 0]);
    }

    public function test_update_ignora_saldo_inyectado_por_body(): void
    {
        $this->actingAsUser();
        $cuenta = Cuenta::factory()->cliente()->create(['saldo' => 50]);

        $response = $this->putJson("/api/cuentas/{$cuenta->id}", [
            'nombre' => 'Cuenta Actualizada',
            'tipo'   => 'CLIENTE',
            'saldo'  => 999999,
        ]);

        $response->assertStatus(200);
        // El saldo heredado (50) NO se altera vía la API de edición.
        $this->assertDatabaseHas('cuentas', ['id' => $cuenta->id, 'saldo' => 50]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D1 — Cuenta principal id==1 inmutable
    // ─────────────────────────────────────────────────────────────────────

    public function test_update_cuenta_principal_id_1_da_403(): void
    {
        $this->actingAsUser();
        Cuenta::find(1) ?? Cuenta::factory()->create(['id' => 1, 'nombre' => 'CENTRAL']);

        $response = $this->putJson('/api/cuentas/1', [
            'nombre' => 'HACKED',
            'tipo'   => 'CLIENTE',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('cuentas', ['id' => 1, 'nombre' => 'HACKED']);
    }

    public function test_toggle_cuenta_principal_id_1_da_403(): void
    {
        $this->actingAsUser();
        Cuenta::find(1) ?? Cuenta::factory()->create(['id' => 1, 'nombre' => 'CENTRAL', 'estado' => 'ON']);

        $response = $this->getJson('/api/cuentas/1/toggle');

        $response->assertStatus(403);
        $this->assertDatabaseHas('cuentas', ['id' => 1, 'estado' => 'ON']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D1 — RBAC de escritura
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Contrato RBAC observado (NO inventado): según `PermissionsSeeder`, VENDEDOR
     * tiene SOLO `cuentas.index`/`cuentas.show` (lectura) — NO `cuentas.create`.
     * El front es consistente: `cuentas.jsx` gatea el botón "Nueva cuenta" por
     * `cuentas.edit || cuentas.create`, y `VentaNueva` NO ofrece creación inline
     * de cliente. Por tanto un VENDEDOR creando cuenta DEBE recibir 403.
     *
     * (El prompt del padre asumía "VENDEDOR necesita crear clientes"; la config
     * REAL del proyecto dice lo contrario. Es regla de negocio → se PREGUNTA en
     * el ledger, NO se cambia el seeder. El test FIJA el comportamiento actual.)
     */
    public function test_vendedor_sin_cuentas_create_recibe_403(): void
    {
        $this->actingAsUser('VENDEDOR');

        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Cliente del Vendedor',
            'tipo'   => 'CLIENTE',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('cuentas', ['nombre' => 'Cliente del Vendedor']);
    }

    public function test_admin_puede_crear_cliente(): void
    {
        // ADMIN (Gate::before) SÍ puede crear → 200 (red de seguridad positiva
        // del RBAC: el 403 del VENDEDOR no es un fallo global del endpoint).
        $this->actingAsUser('ADMIN');

        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Cliente del Admin',
            'tipo'   => 'CLIENTE',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['id']);
    }

    public function test_cajero_sin_cuentas_create_no_puede_crear(): void
    {
        // CAJERO no tiene cuentas.create → 403 (no 200, no 500).
        $this->actingAsUser('CAJERO');

        $response = $this->postJson('/api/cuentas', [
            'nombre' => 'Intento Cajero',
            'tipo'   => 'CLIENTE',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('cuentas', ['nombre' => 'Intento Cajero']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D2 — Fuzz: SQLi/XSS inerte, sort basura
    // ─────────────────────────────────────────────────────────────────────

    public function test_search_sqli_no_inyecta_ni_rompe(): void
    {
        $this->actingAsUser();
        Cuenta::factory()->cliente()->create(['nombre' => 'Cliente Real']);

        foreach (["' OR '1'='1", "'; DROP TABLE cuentas; --", '%00', '٣'] as $payload) {
            $response = $this->getJson('/api/cuentas?skip=0&search=' . urlencode($payload));
            $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
        }
        // La tabla sigue existiendo tras los payloads.
        $this->assertDatabaseHas('cuentas', ['nombre' => 'Cliente Real']);
    }

    public function test_sort_no_whitelisteado_cae_a_default_sin_500(): void
    {
        $this->actingAsUser();
        Cuenta::factory()->cliente()->count(2)->create();

        $response = $this->getJson('/api/cuentas?skip=0&sort=' . urlencode('saldo; DROP TABLE cuentas'));

        $response->assertStatus(200)->assertJsonStructure(['total', 'data']);
    }

    public function test_xss_en_nombre_queda_inerte_verbatim(): void
    {
        $this->actingAsUser();
        $payload = '<script>alert(1)</script>';

        $response = $this->postJson('/api/cuentas', [
            'nombre' => $payload,
            'tipo'   => 'CLIENTE',
        ]);

        $response->assertStatus(200);
        // Se persiste verbatim (sin ejecutar, sin escapar en BD): el front escapa al render.
        $this->assertDatabaseHas('cuentas', ['id' => $response->json('id'), 'nombre' => $payload]);
    }
}
