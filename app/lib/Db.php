<?php
/**
 * Archivo: app/lib/Db.php
 * Fecha/Hora: 2026-01-27 06:50 (America/Mexico_City)
 * Versión: 1.0.0
 * Descripción: Utilidades DB (PDO helpers).
 * Historial:
 *  - v1.0.0 (2026-01-27 06:50): Creación inicial.
 */

declare(strict_types=1);

namespace App\Lib;

use PDO;

final class Db
{
    public static function begin(PDO $pdo): void { $pdo->beginTransaction(); }
    public static function commit(PDO $pdo): void { $pdo->commit(); }
    public static function rollBack(PDO $pdo): void { if ($pdo->inTransaction()) $pdo->rollBack(); }
}
