-- Laporan migrasi data dari spreadsheet (Tahap 6)
CREATE TABLE migration_runs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at   DATETIME NULL,
  source_file   VARCHAR(255) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  mode          ENUM('dry','real') NOT NULL,
  status        ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  summary_json  LONGTEXT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE migration_issues (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id     INT UNSIGNED NOT NULL,
  sheet      VARCHAR(80) NOT NULL,
  source_row INT UNSIGNED NULL,
  category   ENUM('ok','failed','duplicate','needs_review') NOT NULL,
  message    VARCHAR(500) NOT NULL,
  raw_json   LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mig_issues_run (run_id, category),
  CONSTRAINT fk_mig_issues_run FOREIGN KEY (run_id) REFERENCES migration_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
