<?php
/**
 * Archivo: public/index.php
 * Fecha/Hora: 2026-01-27 07:10 (America/Mexico_City)
 * Versión: 1.0.0
 * Descripción: UI web (Bootstrap) para cargar Layout/Emmanuel/Base General/Folios y generar la Tabla de Relación ULTA.
 * Historial:
 *  - v1.0.0 (2026-01-27 07:10): Creación inicial.
 */

declare(strict_types=1);

require __DIR__ . '/../app/autoload.php';
[$config, $pdo] = (require __DIR__ . '/../app/bootstrap.php');


/*
Nuevos cambios
use App\Lib\ExcelReader;
use App\Lib\BaseGeneralService;
use App\Lib\FoliosService;
use App\Lib\UltaGenerator;
*/

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Cargar paises
$paisesPath = $config['paths']['resources_dir'] . DIRECTORY_SEPARATOR . 'Paises.json';
$paisesMap = [];
if (is_file($paisesPath)) {
    $paisesData = json_decode((string)file_get_contents($paisesPath), true);
    if (is_array($paisesData)) {
        // En el repo viene como array con 1er elemento dict
        if (isset($paisesData[0]) && is_array($paisesData[0])) {
            $paisesMap = array_change_key_case($paisesData[0], CASE_UPPER);
        } else {
            $paisesMap = array_change_key_case($paisesData, CASE_UPPER);
        }
    }
}

function saveUpload(array $file, string $destDir, string $prefix): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir archivo. Código: ' . ($file['error'] ?? -1));
    }
    $ext = pathinfo((string)$file['name'], PATHINFO_EXTENSION);
    $safe = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext);
    $dest = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        throw new RuntimeException('No se pudo mover el archivo subido.');
    }
    return $dest;
}

// Manejo de acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'import_base') {
            if (empty($_FILES['base_general']['name'])) {
                throw new RuntimeException('Selecciona un archivo de Base General.');
            }
            $path = saveUpload($_FILES['base_general'], $config['paths']['uploads_dir'], 'base_general');
            $rows = ExcelReader::readBaseGeneral($path, 0);

            // Filtrar columnas como en Python (nos interesan CODIGO/DESCRIPCION/CONTENIDO/ASIGNACIÓN)
            $mapped = [];
            foreach ($rows as $r) {
                $mapped[] = [
                    'CODIGO' => $r['CODIGO'] ?? ($r['Codigo'] ?? ''),
                    'DESCRIPCION' => $r['DESCRIPCION'] ?? ($r['DESCRIPCIÓN'] ?? ''),
                    'CONTENIDO' => $r['CONTENIDO'] ?? '',
                    'ASIGNACIÓN' => $r['ASIGNACIÓN'] ?? ($r['ASIGNACION'] ?? '1'),
                ];
            }
            $res = BaseGeneralService::upsertFromRows($pdo, $mapped);
            $_SESSION['flash'] = ['type'=>'success', 'msg'=>"Base General importada. Insertados: {$res['inserted']}, Actualizados: {$res['updated']}."]; 
            header('Location: index.php');
            exit;
        }

        if ($action === 'import_folios') {
            if (empty($_FILES['folios']['name'])) {
                throw new RuntimeException('Selecciona un archivo de folios (Excel).');
            }
            $path = saveUpload($_FILES['folios'], $config['paths']['uploads_dir'], 'folios');
            $matrix = ExcelReader::readSheetMatrix($path, 0);
            $count = FoliosService::importFromMatrixSecondColumn($pdo, $matrix);
            $_SESSION['flash'] = ['type'=>'success', 'msg'=>"Folios importados: {$count}. (Se omiten duplicados)"]; 
            header('Location: index.php');
            exit;
        }

        if ($action === 'reset_folios') {
            FoliosService::resetAll($pdo);
            $_SESSION['flash'] = ['type'=>'warning', 'msg'=>'Folios reiniciados (TRUNCATE).'];
            header('Location: index.php');
            exit;
        }

        if ($action === 'generate') {
            if (empty($_FILES['layout']['name'])) {
                throw new RuntimeException('Layout es obligatorio.');
            }
            $layoutPath = saveUpload($_FILES['layout'], $config['paths']['uploads_dir'], 'layout');
            $layoutRows = ExcelReader::readLayout($layoutPath, 'Layout 1');

            $emmanuelRows = [];
            if (!empty($_FILES['emmanuel']['name'])) {
                $emmPath = saveUpload($_FILES['emmanuel'], $config['paths']['uploads_dir'], 'emmanuel');
                $emmanuelRows = ExcelReader::readEmmanuel($emmPath, 0);
            }

            $baseMap = BaseGeneralService::fetchMap($pdo);

            $manual = [
                'solicitud' => (string)($_POST['solicitud'] ?? ''),
                'pedimento' => (string)($_POST['pedimento'] ?? ''),
                'fecha_entrada' => (string)($_POST['fecha_entrada'] ?? ''),
                'fecha_verificacion' => (string)($_POST['fecha_verificacion'] ?? ''),
                'fecha_emision' => (string)($_POST['fecha_emision'] ?? ''),
                'firma' => (string)($_POST['firma'] ?? ''),
            ];

            // Validación ligera (opcional): si no hay base, igual se genera.
            $result = UltaGenerator::generate(
                $pdo,
                $layoutRows,
                $emmanuelRows,
                $baseMap,
                $paisesMap,
                $manual,
                $config['paths']['output_dir'],
                $config['paths']['resources_dir']
            );

            // Descargar
            $file = $result['file'];
            if (!is_file($file)) {
                throw new RuntimeException('No se generó el archivo de salida.');
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }

        throw new RuntimeException('Acción no reconocida.');

    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type'=>'danger', 'msg'=>$e->getMessage()];
        header('Location: index.php');
        exit;
    }
}

$folioStats = FoliosService::stats($pdo);
$baseCount = (int)$pdo->query('SELECT COUNT(*) FROM ulta_base_general')->fetchColumn();

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($config['app']['name']) ?> | Web</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="rounded-4 p-3 mb-3" style="background:#ecd925;">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h1 class="h4 m-0">📊 TABLAS DE RELACIÓN ULTA</h1>
      <span class="badge text-bg-dark">v<?= htmlspecialchars($config['app']['version']) ?></span>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <h5 class="card-title">Datos manuales</h5>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Solicitud</label>
              <input class="form-control" name="solicitud" form="formGenerate" placeholder="Ej. 12345">
            </div>
            <div class="col-md-6">
              <label class="form-label">Pedimento</label>
              <input class="form-control" name="pedimento" form="formGenerate" placeholder="Ej. 12  34  5678  0001234">
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha entrada (dd/mm/yy)</label>
              <input class="form-control" name="fecha_entrada" form="formGenerate" placeholder="27/01/26">
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha verificación (dd/mm/yy)</label>
              <input class="form-control" name="fecha_verificacion" form="formGenerate" placeholder="27/01/26">
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha emisión (dd/mm/yy)</label>
              <input class="form-control" name="fecha_emision" form="formGenerate" placeholder="27/01/26">
            </div>
            <div class="col-md-6">
              <label class="form-label">Firma</label>
              <input class="form-control" name="firma" form="formGenerate" placeholder="Nombre / Iniciales">
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm rounded-4 mt-3">
        <div class="card-body">
          <h5 class="card-title">Estado</h5>
          <ul class="list-unstyled mb-0">
            <li><strong>Base General:</strong> <?= $baseCount ?> registros</li>
            <li><strong>Folios:</strong> <?= (int)$folioStats['disponibles'] ?> disponibles de <?= (int)$folioStats['total'] ?> (siguiente: <?= htmlspecialchars($folioStats['next'] ?? 'N/A') ?>)</li>
          </ul>
        </div>
      </div>

    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <h5 class="card-title">Cargar archivos</h5>
          <p class="text-muted small mb-3">Layout es obligatorio. Emmanuel y Base General son opcionales (Base General mejora Descripción/Contenido/Asignación). Folios sirven para asignar FOLIO por LISTA.</p>

          <form id="formGenerate" method="post" enctype="multipart/form-data" class="border rounded-3 p-3">
            <input type="hidden" name="action" value="generate">

            <div class="mb-3">
              <label class="form-label">Layout (Excel) <span class="text-danger">*</span></label>
              <input type="file" name="layout" class="form-control" accept=".xls,.xlsx" required>
              <div class="form-text">Se busca el encabezado detectando la fila que contiene “Denominación social o nombre” (como en Python).</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Emmanuel (Excel) <span class="text-muted">(opcional)</span></label>
              <input type="file" name="emmanuel" class="form-control" accept=".xls,.xlsx">
              <div class="form-text">Debe contener columnas FACTURA y # ORDEN - ITEM (o ORDEN - ITEM).</div>
            </div>

            <button class="btn btn-dark w-100">Generar y descargar Excel</button>
          </form>

          <div class="row g-2 mt-3">
            <div class="col-md-6">
              <form method="post" enctype="multipart/form-data" class="border rounded-3 p-3">
                <input type="hidden" name="action" value="import_base">
                <label class="form-label">Importar Base General</label>
                <input type="file" name="base_general" class="form-control" accept=".xls,.xlsx" required>
                <button class="btn btn-outline-primary w-100 mt-2">Importar / Actualizar</button>
              </form>
            </div>
            <div class="col-md-6">
              <form method="post" enctype="multipart/form-data" class="border rounded-3 p-3">
                <input type="hidden" name="action" value="import_folios">
                <label class="form-label">Cargar folios (Excel)</label>
                <input type="file" name="folios" class="form-control" accept=".xls,.xlsx" required>
                <button class="btn btn-outline-success w-100 mt-2">Cargar folios</button>
              </form>
              <form method="post" class="mt-2">
                <input type="hidden" name="action" value="reset_folios">
                <button class="btn btn-outline-danger w-100" onclick="return confirm('¿Seguro? Esto elimina todos los folios cargados.');">Reiniciar folios</button>
              </form>
            </div>
          </div>

        </div>
      </div>

      <div class="text-muted small mt-3">
        Requisitos: PHP 8.1+, MySQL 5.7/8.0, Composer + PhpSpreadsheet.
      </div>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
