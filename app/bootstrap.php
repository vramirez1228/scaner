<?php
/**
 * Archivo: app/bootstrap.php
 * Fecha/Hora: 2026-01-27 06:47 (America/Mexico_City)
 * Versión: 1.0.0
 * Descripción: Inicialización del sistema (sesión, timezone, autoload, PDO).
 * Historial:
 *  - v1.0.0 (2026-01-27 06:47): Creación inicial.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

date_default_timezone_set($config['app']['timezone']);

// Sesión
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Mantén el nombre de sesión estable (evita el warning session_name() con sesión activa)
    if (!headers_sent()) {
        session_name('ULTA_RELACION');
    }
    session_start();
}

// Composer autoload (PhpSpreadsheet)
$autoload = $config['paths']['base_dir'] . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Asegurar carpetas
foreach (['uploads_dir', 'output_dir', 'resources_dir'] as $k) {
    if (!is_dir($config['paths'][$k])) {
        @mkdir($config['paths'][$k], 0775, true);
    }
}

// PDO
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    (int)$config['db']['port'],
    $config['db']['database'],
    $config['db']['charset']
);

try {
    $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error de conexión a BD: ' . htmlspecialchars($e->getMessage());
    exit;
}

return [$config, $pdo];
