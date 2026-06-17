<?php
/**
 * SETUP UNIFICADO — La Casa Volvo v2
 * 
 * Script PHP autocontenido para configurar la API en producción vía navegador.
 * Diseñado para shared hosting sin SSH (cPanel, etc.).
 *
 * USO:
 *   1. Copiar este archivo a public/setup.php
 *   2. Abrir https://api.lacasavolvo.com/setup.php
 *   3. Esperar a que termine (~30-60 segundos)
 *   4. Si hay timeout: recargar (todo es idempotente)
 *   5. El archivo se auto-elimina al completar sin errores
 *
 * QUÉ HACE — Paso a paso:
 *   ✓ Verifica PHP 8.1+, vendor/, conexión BD
 *   ✓ migrate --force       → 8 migraciones (la #3 adapta roles/permisos del legacy a Spatie)
 *   ✓ storage:link --force  → Crea acceso público a archivos privados (PDFs, avatares)
 *   ✓ Limpia cachés         → config, cache, view, route
 *   ✓ Usuarios iniciales    → Crea Rene Admin (ADMIN) + Rene Vendedor (VENDEDOR) con accesos a sucursales
 *
 * SI ALGO FALLA:
 *   - El script muestra el error y NO se auto-elimina
 *   - Corregí el problema y recargá la página
 *   - Al terminar bien, borralo manualmente: setup.php se auto-elimina
 *
 * CREDENCIALES DEL SERVIDOR (fuera de este archivo):
 *   - PHP time limits: .user.ini o cPanel → max_execution_time=300, memory_limit=512M
 *   - BD: configurada en .env del proyecto
 */

// ══════════════════════════════════════════════════════════════════════════════
// CHECKS INICIALES
// ══════════════════════════════════════════════════════════════════════════════

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    die('<p style="color:#ef4444">❌ Requiere PHP ≥ 8.1. Actual: ' . PHP_VERSION . '</p>');
}

set_time_limit(300);
ini_set('memory_limit', '512M');
ini_set('display_errors', 0); // Errores manejados por try/catch

define('LARAVEL_START', microtime(true));

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    die('<p style="color:#ef4444">❌ vendor/ no encontrado. Subí las dependencias (composer install).</p>');
}

require $autoloadPath;
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Verificar BD
try {
    DB::connection()->getPdo();
} catch (\Exception $e) {
    http_response_code(500);
    die('<p style="color:#ef4444">❌ Error de conexión a BD: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNCIONES
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Ejecuta un comando Artisan y muestra el resultado con estilo.
 * Devuelve true si OK, false si error.
 */
function paso(string $label, string $cmd, array $params = []): bool {
    try {
        $code = Artisan::call($cmd, $params);
        $output = trim(Artisan::output());
        $ok = $code === 0;
    } catch (\Throwable $e) {
        $ok = false;
        $output = $e->getMessage();
    }

    $color = $ok ? '#22c55e' : '#ef4444';
    $icon  = $ok ? '✓' : '✗';

    echo "<div style='margin:8px 0'>";
    echo "<span style='color:$color;font-weight:700'>$icon</span> ";
    echo "<strong style='color:#e2e8f0'>$label</strong>";
    if ($output) {
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            echo "<br><span style='color:#94a3b8;margin-left:24px;font-family:monospace;font-size:.85rem'>" . htmlspecialchars($line) . "</span>";
        }
    }
    echo "</div>";

    try { ob_flush(); flush(); } catch (\Throwable $e) {}
    return $ok;
}

// ══════════════════════════════════════════════════════════════════════════════
// UI
// ══════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Setup — La Casa Volvo v2</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0f172a;color:#f1f5f9;padding:2rem;max-width:800px;margin:0 auto;font-family:system-ui,-apple-system,sans-serif}
h1{color:#0B7EC2;font-size:1.4rem;margin-bottom:.25rem}
.sub{color:#94a3b8;font-size:.85rem;margin-bottom:1.5rem}
.note{background:rgba(11,126,194,.1);border:1px solid rgba(11,126,194,.3);border-radius:8px;padding:12px 16px;margin:16px 0;font-size:.85rem;color:#94a3b8}
.card{background:rgba(255,255,255,.03);border:1px solid #1e293b;border-radius:10px;padding:16px 20px;margin:12px 0}
.card h2{color:#e2e8f0;font-size:1rem;margin-bottom:4px}
hr{border-color:#1e293b;margin:20px 0}
.success{color:#22c55e;font-weight:700;font-size:1.1rem}
.error{color:#ef4444;font-weight:700}
</style>
</head>
<body>

<h1>⚙ Setup — La Casa Volvo v2</h1>
<p class="sub">PHP <?= PHP_VERSION ?> · Laravel <?= app()->version() ?> · BD: <?= config('database.connections.mysql.database') ?></p>

<div class="note">
  <strong>☝🏼 No cierres ni recargues hasta que termine.</strong><br>
  Si ves timeout (pantalla blanca), recargá — todo es idempotente.
</div>

<?php
$errors = 0;

// ══════════════════════════════════════════════════════════════════════════════
// PASO 1: Migraciones
// ══════════════════════════════════════════════════════════════════════════════
echo '<div class="card"><h2>1. Migraciones</h2>';
$errors += !paso('migrate --force', 'migrate', ['--force' => true]);
echo '<p style="color:#94a3b8;font-size:.85rem">La migración Shinobi→Spatie copia automáticamente los roles y permisos del legacy.</p>';
echo '</div>';

// ══════════════════════════════════════════════════════════════════════════════
// PASO 2: Storage link
// ══════════════════════════════════════════════════════════════════════════════
echo '<div class="card"><h2>2. Enlace storage</h2>';
$errors += !paso('storage:link --force', 'storage:link', ['--force' => true]);
echo '</div>';

// ══════════════════════════════════════════════════════════════════════════════
// PASO 3: Limpieza de cachés
// ══════════════════════════════════════════════════════════════════════════════
echo '<div class="card"><h2>3. Limpieza de cachés</h2>';
$errors += !paso('config:clear', 'config:clear');
$errors += !paso('cache:clear',  'cache:clear');
$errors += !paso('view:clear',   'view:clear');
$errors += !paso('route:clear',  'route:clear');
echo '</div>';

// ══════════════════════════════════════════════════════════════════════════════
// PASO 4: Usuarios iniciales
// ══════════════════════════════════════════════════════════════════════════════
echo '<div class="card"><h2>4. Usuarios iniciales</h2>';

// Contraseña de los usuarios de prueba desde el entorno (NO hardcodeada — repo público).
// Sin SEED_ADMIN_PASSWORD se OMITE la creación (en producción los usuarios reales vienen
// de la copia de `tienda`; la migración Shinobi→Spatie convierte sus roles).
$seedPass = getenv('SEED_ADMIN_PASSWORD') ?: ($_SERVER['SEED_ADMIN_PASSWORD'] ?? env('SEED_ADMIN_PASSWORD', ''));
$usersToCreate = $seedPass === '' ? [] : [
    [
        'email'    => 'rene@softlat.com',
        'name'     => 'Rene Perez',
        'password' => $seedPass,
        'role'     => 'ADMIN',
        'sucursales' => [1, 2, 3, 4, 5],
    ],
    [
        'email'    => 'rene_perez@safesoft.tech',
        'name'     => 'Rene Admin',
        'password' => $seedPass,
        'role'     => 'ADMIN',
        'sucursales' => [1, 2, 3, 4],
    ],
    [
        'email'    => 'rene_perez@outlook.it',
        'name'     => 'Rene Vendedor',
        'password' => $seedPass,
        'role'     => 'VENDEDOR',
        'sucursales' => [1, 2],
    ],
];
if (empty($usersToCreate)) {
    echo '<p style="color:#94a3b8">SEED_ADMIN_PASSWORD no definida — se omite la creación de usuarios de prueba.</p>';
}

foreach ($usersToCreate as $u) {
    try {
        $user = \App\Models\User::firstOrCreate(
            ['email' => $u['email']],
            [
                'name'        => $u['name'],
                'password'    => bcrypt($u['password']),
                'sucursal_id' => $u['sucursales'][0],
            ]
        );
        $user->assignRole($u['role']);

        foreach ($u['sucursales'] as $sid) {
            \Illuminate\Support\Facades\DB::table('accesos')->updateOrInsert(
                ['user_id' => $user->id, 'sucursal_id' => $sid],
                ['estado' => 'ON']
            );
        }

        echo "<div style='margin:4px 0'>";
        echo "<span style='color:#22c55e;font-weight:700'>✓</span> ";
        echo "<span style='color:#e2e8f0'>{$u['email']}</span>";
        echo "<span style='color:#94a3b8;margin-left:8px'>→ {$u['role']} (ID:{$user->id})</span>";
        echo "</div>";
        try { @ob_flush(); @flush(); } catch (\Throwable $e) {}
    } catch (\Throwable $e) {
        echo "<div style='margin:4px 0'>";
        echo "<span style='color:#ef4444;font-weight:700'>✗</span> ";
        echo "<span style='color:#e2e8f0'>{$u['email']}</span>";
        echo "<span style='color:#ef4444;margin-left:8px'>" . htmlspecialchars($e->getMessage()) . "</span>";
        echo "</div>";
        try { @ob_flush(); @flush(); } catch (\Throwable $e) {}
        $errors++;
    }
}
echo '</div>';

// ══════════════════════════════════════════════════════════════════════════════
// RESULTADO
// ══════════════════════════════════════════════════════════════════════════════
echo '<hr>';

if ($errors === 0) {
    echo '<p class="success">✓ SETUP COMPLETADO — ' . date('Y-m-d H:i:s') . '</p>';
    echo '<p style="color:#94a3b8;font-size:.85rem;margin-top:8px">API lista. Este archivo se eliminó automáticamente.</p>';
    @unlink(__FILE__);
} else {
    echo '<p class="error">⚠ Setup completado con <strong>' . $errors . '</strong> error(es).</p>';
    echo '<p style="color:#94a3b8;font-size:.85rem;margin-top:8px">Revisá los errores arriba, corregilos, y recargá esta página. Este archivo NO se eliminará hasta que todo esté OK.</p>';
}

echo '</body></html>';
