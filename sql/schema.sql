-- Archivo: sql/schema.sql
-- Fecha/Hora: 2026-01-27 07:12 (America/Mexico_City)
-- Versión: 1.0.0
-- Descripción: Estructura MySQL para el sistema "Tablas de Relación ULTA" (Base General y Folios).
-- Historial:
--  - v1.0.0 (2026-01-27 07:12): Creación inicial.

CREATE DATABASE IF NOT EXISTS ulta_relacion CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ulta_relacion;

-- Base general: mapa de CODIGO -> DESCRIPCION/CONTENIDO/ASIGNACION
CREATE TABLE IF NOT EXISTS ulta_base_general (
  codigo VARCHAR(64) NOT NULL,
  descripcion TEXT NULL,
  contenido VARCHAR(255) NULL,
  asignacion VARCHAR(32) NOT NULL DEFAULT '1',
  updated_at DATETIME NULL,
  PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Folios: cola de folios disponibles
CREATE TABLE IF NOT EXISTS ulta_folios (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio CHAR(6) NOT NULL,
  estado ENUM('DISPONIBLE','USADO') NOT NULL DEFAULT 'DISPONIBLE',
  created_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_folio (folio),
  KEY idx_estado_id (estado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
