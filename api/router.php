<?php
/**
 * Router para PHP built-in development server.
 * Equivalente a `php artisan serve`.
 * Uso: php -S 0.0.0.0:8000 router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Si el archivo existe en public/, servirlo directamente
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    return false;
}

// Caso contrario, pasar por el front controller de Laravel
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once __DIR__ . '/public/index.php';
