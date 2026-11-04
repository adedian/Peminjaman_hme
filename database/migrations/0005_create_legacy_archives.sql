-- Arsip catatan manual (Apr 2025 - Apr 2026) dari sheet "Data Barang Masuk-Keluar" yang tidak punya ID Pinjam. Read-only (keputusan Q1).
CREATE TABLE legacy_manual_loans (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_row    INT UNSIGNED NOT NULL,
  legacy_no     VARCHAR(20) NULL,
  item_text     VARCHAR(255) NULL,
  carrier_text  VARCHAR(255) NULL,
  borrowed_raw  VARCHAR(255) NULL,
  borrowed_at   DATETIME NULL,
  returned_raw  VARCHAR(255) NULL,
  returned_at   DATETIME NULL,
  return_note   VARCHAR(255) NULL,
  team          VARCHAR(255) NULL,
  location      VARCHAR(255) NULL,
  notes         TEXT NULL,
  extra_note    VARCHAR(255) NULL,
  parse_flag    ENUM('ok','swapped_dm','needs_review','unparseable') NOT NULL DEFAULT 'ok',
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_loans_row (source_row),
  KEY idx_legacy_loans_date (borrowed_at),
  KEY idx_legacy_loans_flag (parse_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Arsip sheet "Nama Barang Elektronik" (keputusan Q2)
CREATE TABLE legacy_inventory_register (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section          VARCHAR(60) NOT NULL,
  source_row       INT UNSIGNED NOT NULL,
  no               VARCHAR(20) NULL,
  name             VARCHAR(255) NULL,
  qty              VARCHAR(20) NULL,
  unit             VARCHAR(30) NULL,
  storage_location VARCHAR(255) NULL,
  condition_text   VARCHAR(100) NULL,
  current_position VARCHAR(255) NULL,
  note             VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_register_row (source_row)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
