<?php
/**
 * CREAR USUARIO ADMIN — La Casa Volvo (LEGACY)
 * 
 * Script PHP autocontenido. Funciona con la BD legacy (Shinobi, NO Spatie).
 * NO depende de Laravel — usa PDO directo.
 *
 * CREDENCIALES: se leen de scripts/deploy/.deploy.env (gitignored) o del entorno.
 *               Copiá .deploy.env.example a .deploy.env y completá los valores.
 *
 * USO:
 *   1. Subir a public_html/ del legacy: lacasavolvo.com/create-admin-legacy.php
 *   2. Abrir https://lacasavolvo.com/create-admin-legacy.php
 *   3. Se auto-elimina al terminar
 */

// ═══════════════════════════════════════════
// CONFIGURACIÓN — credenciales desde .deploy.env (gitignored) o variables de entorno
// ═══════════════════════════════════════════
$envPath = __DIR__ . '/.deploy.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}
$DB_HOST = getenv('LCV_DB_HOST') ?: 'localhost';
$DB_PORT = getenv('LCV_DB_PORT') ?: '3306';
$DB_NAME = getenv('LCV_DB_NAME') ?: 'tienda';
$DB_USER = getenv('LCV_DB_USER') ?: 'userjavi';
$DB_PASS = getenv('LCV_DB_PASS') ?: '';
if ($DB_PASS === '') {
    die("[✗] Falta LCV_DB_PASS. Copiá scripts/deploy/.deploy.env.example a .deploy.env y completá.\n");
}

// ═══════════════════════════════════════════
// USUARIO A CREAR
// ═══════════════════════════════════════════
// Contraseña desde el entorno (NO hardcodeada — repo público):
//   SEED_ADMIN_PASSWORD=... php scripts/deploy/create-admin-legacy.php
$seedPass = getenv('SEED_ADMIN_PASSWORD') ?: '';
if ($seedPass === '') { fwrite(STDERR, "Definí SEED_ADMIN_PASSWORD antes de correr este script.\n"); exit(1); }

$NEW_USER = [
    'email'       => 'rene_perez@safesoft.tech',
    'name'        => 'Rene Admin',
    'password'    => $seedPass,
    'role_slug'   => 'admin',            // slug en tabla roles (Shinobi)
    'sucursales'  => [1, 2, 3, 4, 5],
];

// ═══════════════════════════════════════════
// EJECUCIÓN
// ═══════════════════════════════════════════

header('Content-Type: text/plain; charset=utf-8');
echo "╔══════════════════════════════════════════╗\n";
echo "║  CREAR USUARIO ADMIN — La Casa Volvo    ║\n";
echo "║  BD: $DB_NAME@$DB_HOST                     ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

try {
    $pdo = new PDO("mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "[✓] Conectado a MySQL\n";
} catch (PDOException $e) {
    die("[✗] Error conexión: " . $e->getMessage() . "\n");
}

// Verificar rol
$role = $pdo->query("SELECT id, name, slug FROM roles WHERE slug = " . $pdo->quote($NEW_USER['role_slug']))->fetch();
if (!$role) die("[✗] Rol '{$NEW_USER['role_slug']}' no encontrado.\n");
echo "[✓] Rol: {$role['name']} (ID {$role['id']})\n";

// Crear o actualizar usuario
$existing = $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($NEW_USER['email']))->fetch();
$hash = password_hash($NEW_USER['password'], PASSWORD_BCRYPT);

if ($existing) {
    $pdo->prepare("UPDATE users SET name=?, password=?, sucursal_id=?, updated_at=NOW() WHERE id=?")
        ->execute([$NEW_USER['name'], $hash, $NEW_USER['sucursales'][0], $existing['id']]);
    $uid = $existing['id'];
    echo "[✓] Usuario actualizado (ID $uid)\n";
} else {
    $pdo->prepare("INSERT INTO users (name, email, password, sucursal_id, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())")
        ->execute([$NEW_USER['name'], $NEW_USER['email'], $hash, $NEW_USER['sucursales'][0]]);
    $uid = $pdo->lastInsertId();
    echo "[✓] Usuario creado (ID $uid)\n";
}

// Asignar rol (Shinobi: role_user)
$hasRole = $pdo->query("SELECT 1 FROM role_user WHERE user_id=$uid AND role_id={$role['id']}")->fetch();
if (!$hasRole) {
    $pdo->prepare("INSERT INTO role_user (user_id, role_id) VALUES (?,?)")->execute([$uid, $role['id']]);
    echo "[✓] Rol asignado\n";
} else {
    echo "[✓] Rol ya asignado\n";
}

// Accesos a sucursales
$n = 0;
foreach ($NEW_USER['sucursales'] as $sid) {
    $suc = $pdo->query("SELECT id FROM sucursals WHERE id=$sid")->fetch();
    if (!$suc) { echo "[!] Sucursal ID=$sid no existe\n"; continue; }
    $has = $pdo->query("SELECT 1 FROM accesos WHERE user_id=$uid AND sucursal_id=$sid")->fetch();
    if (!$has) {
        $pdo->prepare("INSERT INTO accesos (user_id, sucursal_id, estado, created_at, updated_at) VALUES (?,?,'ON',NOW(),NOW())")->execute([$uid, $sid]);
        $n++;
    }
}
echo "[✓] Accesos: $n nuevos (total " . count($NEW_USER['sucursales']) . ")\n";

echo "\n╔══════════════════════════════════════════╗\n";
echo "║  ✅ COMPLETADO                          ║\n";
echo "╚══════════════════════════════════════════╝\n\n";
echo "  Email:    {$NEW_USER['email']}\n";
echo "  Password: {$NEW_USER['password']}\n";
echo "  Rol:      {$role['name']}\n\n";

// Auto-eliminar
if (unlink(__FILE__)) {
    echo "[✓] Script auto-eliminado.\n";
} else {
    echo "[!] Borrar manualmente: " . basename(__FILE__) . "\n";
}
