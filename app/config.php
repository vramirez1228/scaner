<?php
/**
 * Archivo: app/config.php
 * Fecha/Hora: 2026-01-27 06:46 (America/Mexico_City)
 * Versión: 1.0.0
 * Descripción: Configuración central (DB, rutas, opciones) para "Tablas de Relación ULTA" en PHP.
 * Historial:
 *  - v1.0.0 (2026-01-27 06:46): Creación inicial.
 */

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Tablas de Relación ULTA',
        'version' => '1.0.0',
        'timezone' => 'America/Mexico_City',
    ],

    // Ajusta a tu entorno (XAMPP): host=localhost, usuario=root, pass="".
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'ulta_relacion',
        'username' => 'root',
        'password' => 'Adm1n.Imp3R',
        'charset' => 'utf8mb4',
    ],

    // Rutas (relativas al proyecto)
    'paths' => [
        'base_dir' => dirname(__DIR__),
        'uploads_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads',
        'output_dir'  => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'output',
        'resources_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources',
    ],
];
