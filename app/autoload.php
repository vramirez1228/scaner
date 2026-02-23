<?php
/**
 * Archivo: app/autoload.php
 * Fecha/Hora: 2026-01-27 07:05 (America/Mexico_City)
 * Versión: 1.0.1
 * Descripción: Autoload simple para clases del namespace App\* (mapea App\Lib\* -> app/lib/*).
 * Historial:
 *  - v1.0.0 (2026-01-27 07:04): Creación inicial.
 *  - v1.0.1 (2026-01-27 07:05): Ajuste de rutas para carpeta app/lib (case-sensitive).
 */

declare(strict_types=1);

spl_autoload_register(function(string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $rel = substr($class, strlen($prefix)); // e.g. Lib\\ExcelReader
    $parts = explode('\\', $rel);
    if (!$parts) return;

    // Normalizar primer carpeta
    $parts[0] = strtolower($parts[0]); // Lib -> lib
    $relPath = implode(DIRECTORY_SEPARATOR, $parts) . '.php';

    $file = __DIR__ . DIRECTORY_SEPARATOR . $relPath;
    if (is_file($file)) {
        require_once $file;
    }
});
