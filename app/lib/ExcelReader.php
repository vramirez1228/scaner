<?php
/**
 * Archivo: app/lib/ExcelReader.php
 * Fecha/Hora: 2026-01-27 06:49 (America/Mexico_City)
 * Versión: 1.0.0
 * Descripción: Lectura de Excel (XLS/XLSX) a arreglos asociativos, con detección de fila de encabezados (Layout).
 * Historial:
 *  - v1.0.0 (2026-01-27 06:49): Creación inicial.
 */

declare(strict_types=1);

namespace App\Lib;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ExcelReader
{
    /**
     * Lee una hoja como matriz (filas x columnas) usando PhpSpreadsheet.
     */
    public static function readSheetMatrix(string $filePath, string|int $sheet): array
    {
        $spreadsheet = IOFactory::load($filePath);

        $ws = is_int($sheet)
            ? $spreadsheet->getSheet($sheet)
            : $spreadsheet->getSheetByName((string)$sheet);

        if (!$ws instanceof Worksheet) {
            throw new \RuntimeException('No se encontró la hoja solicitada: ' . $sheet);
        }

        return $ws->toArray(null, true, true, true); // keys A,B,C...
    }

    /**
     * Convierte la matriz (A..Z) en rows asociativos dado un índice de header.
     */
    public static function matrixToAssoc(array $matrix, int $headerRowIndex1Based): array
    {
        if ($headerRowIndex1Based < 1 || $headerRowIndex1Based > count($matrix)) {
            throw new \InvalidArgumentException('headerRowIndex inválido');
        }

        $headerRow = $matrix[$headerRowIndex1Based] ?? [];
        $headers = [];
        foreach ($headerRow as $col => $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $headers[$col] = $name;
            }
        }

        $rows = [];
        for ($i = $headerRowIndex1Based + 1; $i <= count($matrix); $i++) {
            $r = $matrix[$i] ?? [];
            $assoc = [];
            $hasAny = false;
            foreach ($headers as $col => $hname) {
                $val = isset($r[$col]) ? (string)$r[$col] : '';
                $val = trim($val);
                if ($val !== '') {
                    $hasAny = true;
                }
                $assoc[$hname] = $val;
            }
            if ($hasAny) {
                $rows[] = $assoc;
            }
        }
        return $rows;
    }

    /**
     * Layout: detecta la fila de encabezado buscando "Denominación social o nombre" en las primeras 10 filas.
     * Devuelve rows asociativos.
     */
    public static function readLayout(string $filePath, string $sheetName = 'Layout 1'): array
    {
        $matrix = self::readSheetMatrix($filePath, $sheetName);

        $target = 'Denominación social o nombre';
        $headerRow = null;
        $max = min(10, count($matrix));
        for ($i = 1; $i <= $max; $i++) {
            foreach (($matrix[$i] ?? []) as $v) {
                if (trim((string)$v) === $target) {
                    $headerRow = $i;
                    break 2;
                }
            }
        }
        if ($headerRow === null) {
            throw new \RuntimeException('No se encontró encabezado válido en Layout (no aparece "Denominación social o nombre").');
        }

        return self::matrixToAssoc($matrix, $headerRow);
    }

    /**
     * Emmanuel: lee por header en fila 1 (Excel) y normaliza columnas a mayúsculas.
     */
    public static function readEmmanuel(string $filePath, int $sheetIndex = 0): array
    {
        $matrix = self::readSheetMatrix($filePath, $sheetIndex);
        $rows = self::matrixToAssoc($matrix, 1);

        // Normalizar llaves a UPPER (como Python)
        $out = [];
        foreach ($rows as $r) {
            $nr = [];
            foreach ($r as $k => $v) {
                $nr[mb_strtoupper(trim((string)$k))] = trim((string)$v);
            }
            // Compat: "ORDEN - ITEM" -> "# ORDEN - ITEM" si aplica
            if (isset($nr['ORDEN - ITEM']) && !isset($nr['# ORDEN - ITEM'])) {
                $nr['# ORDEN - ITEM'] = $nr['ORDEN - ITEM'];
            }
            $out[] = $nr;
        }
        return $out;
    }

    /**
     * Base general: lee por header en fila 1 (Excel) y devuelve rows asociativos.
     */
    public static function readBaseGeneral(string $filePath, int $sheetIndex = 0): array
    {
        $matrix = self::readSheetMatrix($filePath, $sheetIndex);
        return self::matrixToAssoc($matrix, 1);
    }
}
