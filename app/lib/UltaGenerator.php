<?php
/**
 * Archivo: app/lib/UltaGenerator.php
 * Fecha/Hora: 2026-01-27 07:02 (America/Mexico_City)
 * Versión: 1.0.0
 * Descripción: Lógica principal para generar la "Tabla de Relación ULTA" desde Layout (+Emmanuel opcional) + Base General + Folios.
 * Historial:
 *  - v1.0.0 (2026-01-27 07:02): Creación inicial (paridad de lógica con TablaDeRelacionV4.py).
 */

declare(strict_types=1);

namespace App\Lib;

use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

final class UltaGenerator
{
    public const COLUMNAS_RELACION = [
        'SOLICITUD', 'LISTA', 'PEDIMENTO', 'FECHA ENTRADA', 'FECHA DE VERIFICACION',
        'MARCA', 'CODIGO', 'FACTURA', 'CANTIDAD', 'PAIS DE ORIGEN',
        'DESCRIPCION', 'CONTENIDO', 'INSUMO', 'FORRO', 'CLASF UVA', 'NORMA UVA',
        'ESTATUS', 'FIRMA', 'OBSERVACIONES', 'OBSERVACIONES DE DICTAMEN',
        'TIPO DE DOCUMENTO', 'FOLIO', 'MEDIDAS', 'PAIS DE PROCEDENCIA',
        'TIPO DE LISTA', 'FECHA DE EMISION DE SOLICITUD', 'PUNTO DPNS',
        'NO DE INVENTARIO DE MEDICION', 'ASIGNACION'
    ];

    /**
     * Genera filas (arreglos) + archivo Excel.
     * @return array{rows: array<int,array<string,mixed>>, file: string}
     */
    public static function generate(PDO $pdo, array $layoutRows, array $emmanuelRows, array $baseMap, array $paisesMap, array $manual, string $outputDir, string $resourcesDir): array
    {
        // 1) construir filas base
        $rows = [];

        // --- agrupar NOM por CODIGO (Parte) ---
        $nomPorCodigo = [];
        foreach ($layoutRows as $r) {
            $codigoRaw = trim((string)($r['Parte'] ?? ''));
            if ($codigoRaw === '') continue;
            $nom = mb_strtoupper(trim((string)($r['NOM'] ?? '')));
            if (!isset($nomPorCodigo[$codigoRaw])) $nomPorCodigo[$codigoRaw] = [];
            $nomPorCodigo[$codigoRaw][$nom] = true;
        }

        // --- reglas de estatus por (codigo, nom_num) ---
        $estatusDict = [];
        foreach ($nomPorCodigo as $codigoRaw => $set) {
            $nums = [];
            foreach (array_keys($set) as $n) {
                $nums[] = self::extractNomNumber($n);
            }
            $has4 = in_array('4', $nums, true);
            $has50 = in_array('50', $nums, true);
            $has189 = in_array('189', $nums, true);

            if ($has4 && $has50) {
                $estatusDict[$codigoRaw . '|4'] = 'ND';
                $estatusDict[$codigoRaw . '|50'] = 'D';
            } elseif ($has189 && $has50) {
                $estatusDict[$codigoRaw . '|189'] = 'D';
                $estatusDict[$codigoRaw . '|50'] = 'ND';
            } else {
                foreach (array_keys($set) as $n) {
                    $num = self::extractNomNumber($n);
                    if ($num === '4') {
                        $estatusDict[$codigoRaw . '|4'] = 'ND';
                    } elseif ($num === '50') {
                        $estatusDict[$codigoRaw . '|50'] = 'D';
                    } elseif ($num === '189') {
                        $estatusDict[$codigoRaw . '|189'] = 'D';
                    } elseif ($num !== '') {
                        $estatusDict[$codigoRaw . '|' . $num] = 'D';
                    } else {
                        $estatusDict[$codigoRaw . '|'] = '';
                    }
                }
            }
        }

        // 2) mapeo principal por fila
        foreach ($layoutRows as $r) {
            $fila = array_fill_keys(self::COLUMNAS_RELACION, '');

            $marca = trim((string)($r['Marca del producto'] ?? ''));
            if ($marca === '') {
                $marca = trim((string)($r['Denominación social o nombre'] ?? ''));
            }
            $fila['MARCA'] = $marca;

            $fila['FACTURA'] = trim((string)($r['Folio de Solicitud'] ?? ''));

            $fila['CANTIDAD'] = self::toInt((string)($r['Cantidad'] ?? '0'));

            $pais = mb_strtoupper(trim((string)($r['Pais Origen'] ?? '')));
            $fila['PAIS DE ORIGEN'] = $pais !== '' ? ((string)($paisesMap[$pais] ?? $pais)) : '';

            // CODIGO (Parte) - conservar alfanum, limpiar .0 si num puro
            $codigo = self::processCodigo((string)($r['Parte'] ?? ''));
            $fila['CODIGO'] = $codigo;

            // NOM / CLASF UVA / NORMA UVA
            $nomTxt = mb_strtoupper(trim((string)($r['NOM'] ?? '')));
            $nomNum = self::extractNomNumber($nomTxt);
            $clasf = $nomNum === '' ? 0 : (int)$nomNum;
            $fila['CLASF UVA'] = $clasf;
            $fila['NORMA UVA'] = $clasf;

            // ESTATUS segun dict
            $estatus = $estatusDict[$codigo . '|' . $nomNum] ?? $estatusDict[$codigo . '|'] ?? '';
            $fila['ESTATUS'] = $estatus;

            // TIPO DE DOCUMENTO
            $fila['TIPO DE DOCUMENTO'] = self::tipoDocumento($estatus, $clasf, $clasf);

            // Base general: descripcion, contenido, asignacion
            $codigoClean = BaseGeneralService::cleanCodigo($codigo);
            if ($codigoClean !== '' && isset($baseMap[$codigoClean])) {
                $fila['DESCRIPCION'] = (string)$baseMap[$codigoClean]['descripcion'];
                $fila['CONTENIDO'] = self::formatearContenidoNeto((string)$baseMap[$codigoClean]['contenido']);
                $fila['ASIGNACION'] = (string)($baseMap[$codigoClean]['asignacion'] ?: '1');
            } else {
                $fila['DESCRIPCION'] = trim((string)($r['Descripción del producto'] ?? ''));
                $fila['CONTENIDO'] = '';
                $fila['ASIGNACION'] = '1';
            }

            $rows[] = $fila;
        }

        // 3) ordenar por ASIGNACION (letra primero), y generar LISTA en bloques de 11
        $rows = self::sortByAsignacion($rows);
        $rows = self::assignLista($rows, 11);

        // 4) datos manuales
        $sol = trim((string)($manual['solicitud'] ?? ''));
        $ped = trim((string)($manual['pedimento'] ?? ''));
        $firma = trim((string)($manual['firma'] ?? ''));

        $fEntrada = self::formatDateMx((string)($manual['fecha_entrada'] ?? ''));
        $fVerif   = self::formatDateMx((string)($manual['fecha_verificacion'] ?? ''));
        $fEmision = self::formatDateMx((string)($manual['fecha_emision'] ?? ''));

        foreach ($rows as &$fila) {
            $fila['SOLICITUD'] = $sol;
            $fila['PEDIMENTO'] = $ped;
            $fila['FIRMA'] = $firma;
            $fila['FECHA ENTRADA'] = $fEntrada;
            $fila['FECHA DE VERIFICACION'] = $fVerif;
            $fila['FECHA DE EMISION DE SOLICITUD'] = $fEmision;

            // fijos
            $fila['OBSERVACIONES'] = 'N/A';
            $fila['OBSERVACIONES DE DICTAMEN'] = 'N/A';
            $fila['MEDIDAS'] = 'N/A';
            $fila['PAIS DE PROCEDENCIA'] = 'E.U.A.';
            $fila['TIPO DE LISTA'] = 'N/A';
            $fila['PUNTO DPNS'] = 'N/A';
            $fila['NO DE INVENTARIO DE MEDICION'] = 'N/A';
            $fila['INSUMO'] = 'N/A';
            $fila['FORRO'] = 'N/A';
        }
        unset($fila);

        // 5) mapear FACTURA desde Emmanuel (opcional)
        if (!empty($emmanuelRows)) {
            $mapaFacturas = [];
            foreach ($emmanuelRows as $er) {
                $factura = trim((string)($er['FACTURA'] ?? ''));
                $orden = (string)($er['# ORDEN - ITEM'] ?? '');
                if ($orden === '') continue;
                $parts = explode(' - ', $orden);
                $cod = self::normalizarCodigo(end($parts));
                if ($cod !== '' && $factura !== '') {
                    $mapaFacturas[$cod] = $factura;
                }
            }
            foreach ($rows as &$fila) {
                $codNorm = self::normalizarCodigo((string)$fila['CODIGO']);
                if ($codNorm !== '' && isset($mapaFacturas[$codNorm])) {
                    $fila['FACTURA'] = $mapaFacturas[$codNorm];
                }
            }
            unset($fila);
        }

        // 6) asignar FOLIO por LISTA consumiendo cola de BD
        $maxLista = 0;
        foreach ($rows as $fila) {
            $maxLista = max($maxLista, (int)$fila['LISTA']);
        }
        for ($i = 1; $i <= $maxLista; $i++) {
            $folio = FoliosService::popNext($pdo);
            if ($folio === null || $folio === '') {
                $folio = 'SIN FOLIO';
            } else {
                $folio = str_pad($folio, 6, '0', STR_PAD_LEFT);
            }
            foreach ($rows as &$fila) {
                if ((int)$fila['LISTA'] === $i) {
                    $fila['FOLIO'] = $folio;
                }
            }
            unset($fila);
        }

        // 7) generar Excel
        $filename = 'TablaRelacion_ULTA_' . date('Ymd_His') . '.xlsx';
        $outPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        self::writeExcel($rows, $outPath, $resourcesDir);

        return ['rows' => $rows, 'file' => $outPath];
    }

    private static function writeExcel(array $rows, string $path, string $resourcesDir): void
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setTitle('TablaRelacion');

        // Encabezados
        $colIndex = 1;
        foreach (self::COLUMNAS_RELACION as $col) {
            $ws->setCellValueByColumnAndRow($colIndex, 1, $col);
            $colIndex++;
        }

        // Datos
        $r = 2;
        foreach ($rows as $fila) {
            $c = 1;
            foreach (self::COLUMNAS_RELACION as $col) {
                $ws->setCellValueByColumnAndRow($c, $r, $fila[$col] ?? '');
                $c++;
            }
            $r++;
        }

        // Formato (machote.json)
        $machotePath = $resourcesDir . DIRECTORY_SEPARATOR . 'machote.json';
        $machote = [];
        if (is_file($machotePath)) {
            $machote = json_decode((string)file_get_contents($machotePath), true) ?: [];
        }

        $headerColor = (string)($machote['header']['color'] ?? '#FF9900');
        $headerFontName = (string)($machote['header']['font']['name'] ?? 'Calibri');
        $headerFontSize = (int)($machote['header']['font']['size'] ?? 11);
        $headerBold = (bool)($machote['header']['font']['bold'] ?? true);

        $lastCol = $ws->getHighestColumn();
        $ws->getStyle('A1:' . $lastCol . '1')->applyFromArray([
            'font' => ['bold' => $headerBold, 'name' => $headerFontName, 'size' => $headerFontSize, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => ltrim(str_replace('#','',$headerColor),'#')]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Auto width (simple): basado en longitudes
        $minW = (int)($machote['columns']['min_width'] ?? 10);
        $maxW = (int)($machote['columns']['max_width'] ?? 50);
        foreach (range(1, count(self::COLUMNAS_RELACION)) as $i) {
            $colLetter = $ws->getCellByColumnAndRow($i, 1)->getColumn();
            $maxLen = mb_strlen((string)self::COLUMNAS_RELACION[$i-1]);
            for ($rr = 2; $rr <= min(count($rows)+1, 5000); $rr++) {
                $v = (string)$ws->getCellByColumnAndRow($i, $rr)->getFormattedValue();
                $maxLen = max($maxLen, mb_strlen($v));
            }
            $w = min($maxW, max($minW, $maxLen + 2));
            $ws->getColumnDimension($colLetter)->setWidth($w);
        }

        // Alineación: CODIGO y CANTIDAD a la izquierda (como Python)
        $idxCodigo = array_search('CODIGO', self::COLUMNAS_RELACION, true);
        $idxCantidad = array_search('CANTIDAD', self::COLUMNAS_RELACION, true);
        $lastRow = count($rows) + 1;
        foreach ([$idxCodigo, $idxCantidad] as $idx) {
            if ($idx === false) continue;
            $colLetter = $ws->getCellByColumnAndRow($idx+1, 1)->getColumn();
            $ws->getStyle($colLetter . '2:' . $colLetter . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        $writer = new Xlsx($ss);
        $writer->save($path);
    }

    private static function toInt(string $x): int
    {
        $x = trim($x);
        if ($x === '') return 0;
        $x = str_replace([',', ' '], '', $x);
        if (!is_numeric($x)) return 0;
        return (int)round((float)$x);
    }

    private static function processCodigo(string $x): string
    {
        $x = trim((string)$x);
        if ($x === '' || strtolower($x) === 'nan') return '';
        if (preg_match('/^\d+(\.0+)?$/', $x)) {
            return (string)intval((float)$x);
        }
        return $x;
    }

    private static function extractNomNumber(string $nom): string
    {
        $nom = mb_strtoupper($nom);
        if (strpos($nom, 'NOM-004') !== false || strpos($nom, 'NOM-4') !== false) return '4';
        if (strpos($nom, 'NOM-050') !== false || strpos($nom, 'NOM-50') !== false) return '50';
        if (strpos($nom, 'NOM-189') !== false) return '189';
        if (preg_match('/NOM-(\d+)/', $nom, $m)) return (string)$m[1];
        return '';
    }

    private static function tipoDocumento(string $estatus, int $clasf, int $norma): string
    {
        if ($estatus === 'ND') return 'ND';
        if ($estatus === 'D') return 'D';
        if ($clasf === 4 && $norma === 4) return 'ND';
        return 'D';
    }

    private static function formatearContenidoNeto(string $valor): string
    {
        $texto = trim($valor);
        if ($texto === '' || strtolower($texto) === 'nan') return '';

        $patSimple = '/\d+(\.\d+)?\s*(g|gr|ml|l)/i';
        $patComb = '/\d+(\.\d+)?\s*(g|gr|ml|l)\s*\/\s*\d+(\.\d+)?\s*(g|gr|ml|l)/i';
        if (preg_match($patComb, $texto) || preg_match($patSimple, $texto)) {
            return 'NETO: ' . $texto;
        }
        return $texto;
    }

    private static function sortByAsignacion(array $rows): array
    {
        usort($rows, function($a, $b) {
            [$na, $sa] = self::splitAsignacion((string)($a['ASIGNACION'] ?? ''));
            [$nb, $sb] = self::splitAsignacion((string)($b['ASIGNACION'] ?? ''));

            $ha = $sa !== '' ? 0 : 1;
            $hb = $sb !== '' ? 0 : 1;
            if ($ha !== $hb) return $ha <=> $hb;
            if ($na !== $nb) return $na <=> $nb;
            return strcmp($sa, $sb);
        });
        return $rows;
    }

    private static function assignLista(array $rows, int $reps): array
    {
        $listaCounter = 1;
        $out = [];
        $i = 0;
        while ($i < count($rows)) {
            $asig = (string)($rows[$i]['ASIGNACION'] ?? '');
            // tomar bloque del mismo ASIGNACION
            $j = $i;
            while ($j < count($rows) && (string)($rows[$j]['ASIGNACION'] ?? '') === $asig) {
                $j++;
            }
            $n = $j - $i;
            $bloques = (int)ceil($n / $reps);
            for ($b = 0; $b < $bloques; $b++) {
                $inicio = $i + ($b * $reps);
                $fin = min($i + (($b+1) * $reps), $j);
                for ($k = $inicio; $k < $fin; $k++) {
                    $rows[$k]['LISTA'] = $listaCounter;
                }
                $listaCounter++;
            }
            $i = $j;
        }
        return $rows;
    }

    private static function splitAsignacion(string $val): array
    {
        $val = trim($val);
        if (preg_match('/^(\d+)(?:-(\w+))?$/', $val, $m)) {
            return [(int)$m[1], isset($m[2]) ? (string)$m[2] : ''];
        }
        return [PHP_INT_MAX, ''];
    }

    private static function formatDateMx(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        // Espera dd/mm/yy
        $dt = \DateTime::createFromFormat('d/m/y', $s);
        if (!$dt) {
            // intentar dd/mm/YYYY
            $dt = \DateTime::createFromFormat('d/m/Y', $s);
        }
        return $dt ? $dt->format('d/m/Y') : '';
    }

    private static function normalizarCodigo(string $x): string
    {
        $x = trim($x);
        if ($x === '' || strtolower($x) === 'nan') return '';
        $x = preg_replace('/\s+/', '', $x);
        $x = ltrim($x, '0');
        return $x;
    }
}
