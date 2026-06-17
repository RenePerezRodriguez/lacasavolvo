<?php
/**
 * Creación de usuarios iniciales post-deploy.
 * Ejecutar una sola vez después del migrate.
 *
 * USO: php scripts/deploy/create-users.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== CREANDO USUARIOS INICIALES ===\n\n";

// Contraseña desde el entorno o desde .deploy.env (gitignored) — NO hardcodeada (repo público).
//   SEED_ADMIN_PASSWORD=... php scripts/deploy/create-users.php   (o ponerla en scripts/deploy/.deploy.env)
$deployEnv = __DIR__.'/.deploy.env';
if (is_file($deployEnv)) {
    foreach (file($deployEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        if (getenv(trim($k)) === false) putenv(trim($k).'='.trim($v));
    }
}
$seedPass = getenv('SEED_ADMIN_PASSWORD') ?: env('SEED_ADMIN_PASSWORD', '');
if ($seedPass === '') {
    echo "ERROR: definí SEED_ADMIN_PASSWORD (variable de entorno o en scripts/deploy/.deploy.env) antes de correr este script.\n";
    exit(1);
}

$users = [
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

foreach ($users as $u) {
    $user = App\Models\User::firstOrCreate(
        ['email' => $u['email']],
        [
            'name'        => $u['name'],
            'password'    => bcrypt($u['password']),
            'sucursal_id' => $u['sucursales'][0],
        ]
    );
    $user->assignRole($u['role']);

    foreach ($u['sucursales'] as $sid) {
        DB::table('accesos')->updateOrInsert(
            ['user_id' => $user->id, 'sucursal_id' => $sid],
            ['estado' => 'ON']
        );
    }

    echo "✓ {$u['email']} → {$u['role']} (ID:{$user->id})\n";
}

echo "\nDone.\n";
