<?php
/**
 * Archivo: app/lib/BaseGeneralService.php
 * Fecha/Hora: 2026-01-27 06:54 (America/Mexico_City)
 * Versión: 1.0.1
 * Descripción: Importación/consulta de Base General (relación de CODIGO->DESCRIPCION/CONTENIDO/ASIGNACION).
 * Historial:
 *  - v1.0.0 (2026-01-27 06:51): Creación inicial.
 *  - v1.0.1 (2026-01-27 06:54): Corrección de UPSERT/contadores; eliminación de llamadas inválidas.
 */

declare(strict_types=1);

namespace App\Lib;

use PDO;

final class BaseGeneralService
{
    /**
     * Importa/actualiza Base General desde filas (Excel ya leído a arreglos asociativos).
     * Espera columnas: CODIGO, DESCRIPCION, CONTENIDO, ASIGNACIÓN/ASIGNACION.
     */
    public static function upsertFromRows(PDO $pdo, array $rows): array
    {
        $inserted = 0;
        $updated = 0;

        Db::begin($pdo);
        try {
            $existsStmt = $pdo->prepare('SELECT 1 FROM ulta_base_general WHERE codigo = ? LIMIT 1');
            $stmt = $pdo->prepare(
                'INSERT INTO ulta_base_general (codigo, descripcion, contenido, asignacion, updated_at)\n'
                . 'VALUES (:codigo, :descripcion, :contenido, :asignacion, NOW())\n'
                . 'ON DUPLICATE KEY UPDATE\n'
                . 'descripcion=VALUES(descripcion), contenido=VALUES(contenido), asignacion=VALUES(asignacion), updated_at=NOW()'
            );

            foreach ($rows as $r) {
                $codigo = self::cleanCodigo((string)($r['CODIGO'] ?? ($r['Codigo'] ?? '')));
                if ($codigo === '') {
                    continue;
                }

                $descripcion = trim((string)($r['DESCRIPCION'] ?? ($r['DESCRIPCIÓN'] ?? '')));
                $contenido   = trim((string)($r['CONTENIDO'] ?? ''));
                $asign       = trim((string)($r['ASIGNACIÓN'] ?? ($r['ASIGNACION'] ?? '1')));
                if ($asign === '') {
                    $asign = '1';
                }

                $existsStmt->execute([$codigo]);
                $had = (bool)$existsStmt->fetchColumn();
                if ($had) {
                    $updated++;
                } else {
                    $inserted++;
                }

                $stmt->execute([
                    ':codigo' => $codigo,
                    ':descripcion' => $descripcion,
                    ':contenido' => $contenido,
                    ':asignacion' => $asign,
                ]);
            }

            Db::commit($pdo);
        } catch (\Throwable $e) {
            Db::rollBack($pdo);
            throw $e;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Devuelve mapa por codigo: [codigo => ['descripcion'=>..,'contenido'=>..,'asignacion'=>..]]
     */
    public static function fetchMap(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT codigo, descripcion, contenido, asignacion FROM ulta_base_general')->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['codigo']] = [
                'descripcion' => (string)$r['descripcion'],
                'contenido' => (string)$r['contenido'],
                'asignacion' => (string)$r['asignacion'],
            ];
        }
        return $map;
    }

    public static function cleanCodigo(string $x): string
    {
        $x = trim($x);
        if ($x === '' || strtolower($x) === 'nan') return '';
        if (preg_match('/^\d+(\.0+)?$/', $x)) {
            return (string)intval((float)$x);
        }
        return $x;
    }
}
