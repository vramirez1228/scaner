<?php
/**
 * Archivo: app/lib/FoliosService.php
 * Fecha/Hora: 2026-01-27 06:55 (America/Mexico_City)
 * Versión: 1.0.0
 * Descripción: Gestión de folios (carga desde Excel, cola de folios disponibles, asignación por LISTA).
 * Historial:
 *  - v1.0.0 (2026-01-27 06:55): Creación inicial.
 */

declare(strict_types=1);

namespace App\Lib;

use PDO;

final class FoliosService
{
    /**
     * Importa folios desde filas de Excel. En Python toma la columna 2 (índice 1).
     * Aquí se espera un arreglo de filas (matriz), y se extrae el valor de la 2da col.
     * @return int cantidad importada
     */
    public static function importFromMatrixSecondColumn(PDO $pdo, array $matrix): int
    {
        $folios = [];
        // matrix es [1=>['A'=>..,'B'=>..], ...]
        for ($i = 2; $i <= count($matrix); $i++) { // asumiendo header en fila 1
            $row = $matrix[$i] ?? [];
            $val = isset($row['B']) ? trim((string)$row['B']) : '';
            if ($val === '' || strtolower($val) === 'nan') continue;
            // aceptar numérico, rellenar a 6
            $val = preg_replace('/\s+/', '', $val);
            if (preg_match('/^\d+$/', $val)) {
                $val = str_pad($val, 6, '0', STR_PAD_LEFT);
            }
            $folios[] = $val;
        }

        if (!$folios) return 0;

        Db::begin($pdo);
        try {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO ulta_folios (folio, estado, created_at) VALUES (?, "DISPONIBLE", NOW())'
            );
            $count = 0;
            foreach ($folios as $f) {
                $stmt->execute([$f]);
                $count += $stmt->rowCount();
            }
            Db::commit($pdo);
            return $count;
        } catch (\Throwable $e) {
            Db::rollBack($pdo);
            throw $e;
        }
    }

    /**
     * Reserva el siguiente folio DISPONIBLE y lo marca como USADO.
     */
    public static function popNext(PDO $pdo): ?string
    {
        Db::begin($pdo);
        try {
            // SELECT ... FOR UPDATE para no duplicar
            $q = $pdo->query('SELECT id, folio FROM ulta_folios WHERE estado="DISPONIBLE" ORDER BY id ASC LIMIT 1 FOR UPDATE');
            $row = $q->fetch();
            if (!$row) {
                Db::commit($pdo);
                return null;
            }
            $upd = $pdo->prepare('UPDATE ulta_folios SET estado="USADO", used_at=NOW() WHERE id = ?');
            $upd->execute([(int)$row['id']]);
            Db::commit($pdo);
            return (string)$row['folio'];
        } catch (\Throwable $e) {
            Db::rollBack($pdo);
            throw $e;
        }
    }

    public static function stats(PDO $pdo): array
    {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM ulta_folios')->fetchColumn();
        $disp  = (int)$pdo->query('SELECT COUNT(*) FROM ulta_folios WHERE estado="DISPONIBLE"')->fetchColumn();
        $next  = $pdo->query('SELECT folio FROM ulta_folios WHERE estado="DISPONIBLE" ORDER BY id ASC LIMIT 1')->fetchColumn();
        return ['total'=>$total, 'disponibles'=>$disp, 'next'=>$next ?: null];
    }

    public static function resetAll(PDO $pdo): void
    {
        $pdo->exec('TRUNCATE TABLE ulta_folios');
    }
}
