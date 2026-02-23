# Tablas de Relación ULTA (PHP + MySQL)

**Archivo:** README.md  
**Fecha/Hora:** 2026-01-27 07:13 (America/Mexico_City)  
**Versión:** 1.0.0  
**Descripción:** Migración del sistema en Python "TablaDeRelacionV4.py" a una aplicación web en PHP (Bootstrap) con MySQL.

## 1) Requisitos
- PHP 8.1+ (recomendado 8.2)
- MySQL 5.7+ / 8.0
- Composer
- Librería: `phpoffice/phpspreadsheet`

## 2) Instalación rápida (XAMPP)
1. Copia la carpeta del proyecto dentro de:
   - `C:\xampp\htdocs\ulta_relacion\`
2. Crea la BD:
   - Importa `sql/schema.sql` desde phpMyAdmin.
3. Configura DB:
   - Edita `app/config.php` (host, user, pass, database).
4. Instala dependencias (en la raíz del proyecto):
   ```bash
   composer require phpoffice/phpspreadsheet
   ```
5. Abre en el navegador:
   - `http://localhost/ulta_relacion/public/index.php`

> Nota: si usas Apache sin VirtualHost, asegúrate de entrar a `/public/index.php`.

## 3) Uso
### A) Importar Base General (opcional)
- En **Importar Base General** sube tu Excel.
- Se carga en tabla `ulta_base_general` usando `codigo` como llave.

### B) Cargar folios
- Sube Excel de folios.
- Se toma la **2da columna (B)** como en el sistema Python.
- Se guardan como `CHAR(6)` con ceros a la izquierda.

### C) Generar Tabla
1. Sube **Layout** (obligatorio).
   - Se busca la fila de encabezados detectando el texto **“Denominación social o nombre”** en las primeras 10 filas.
2. Emmanuel (opcional):
   - Debe tener columnas `FACTURA` y `# ORDEN - ITEM` (o `ORDEN - ITEM`).
   - Se usa para mapear `FACTURA` por código (última parte de `# ORDEN - ITEM`).
3. Captura datos manuales (Solicitud, Pedimento, fechas dd/mm/yy, Firma).
4. Click **Generar y descargar Excel**.

## 4) Paridad con Python
- Reglas NOM (ND/D):
  - NOM-004 y NOM-050 -> 004=ND, 050=D
  - NOM-189 y NOM-050 -> 189=D, 050=ND
  - Default: 004=ND, resto=D
- Asignación de LISTA: bloques de **11** por `ASIGNACION`.
- Folio por LISTA: consume la cola de `ulta_folios`.
- Formato Excel: se toma `resources/machote.json` para encabezado y widths.

## 5) Estructura
- `public/index.php` UI
- `app/lib/*` lógica
- `resources/Paises.json` mapeo de países
- `resources/machote.json` formato de encabezados/columnas
- `uploads/` temporales
- `output/` archivos generados

