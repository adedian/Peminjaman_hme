CREATE TABLE pqa_installations (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_row             INT UNSIGNED NULL,
  item_id                INT UNSIGNED NOT NULL,
  requested_by           INT UNSIGNED NULL,
  location               VARCHAR(255) NOT NULL,
  ppic                   VARCHAR(100) NOT NULL,
  install_team           VARCHAR(255) NOT NULL,
  submitted_at           DATETIME NULL,
  installed_at           DATETIME NULL,
  status                 ENUM('Pending','Terpasang','Req Cabut','Dicabut','Ditolak') NOT NULL DEFAULT 'Pending',
  removal_team           VARCHAR(255) NULL,
  removed_at             DATETIME NULL,
  total_days             INT UNSIGNED NULL,
  target_days            INT UNSIGNED NOT NULL,
  pending_extension_days INT UNSIGNED NOT NULL DEFAULT 0,
  decided_by             INT UNSIGNED NULL,
  decided_at             DATETIME NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- BR-19: satu unit PQA tidak boleh punya dua pemasangan aktif sekaligus
  active_item_key        INT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('Pending','Terpasang','Req Cabut'), item_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pqa_active_item (active_item_key),
  KEY idx_pqa_status (status),
  KEY idx_pqa_item (item_id),
  CONSTRAINT fk_pqa_item      FOREIGN KEY (item_id)      REFERENCES items (id),
  CONSTRAINT fk_pqa_requester FOREIGN KEY (requested_by) REFERENCES users (id),
  CONSTRAINT fk_pqa_decider   FOREIGN KEY (decided_by)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat setiap permintaan tambah waktu / pencabutan (sistem lama hanya menyimpan yang terakhir)
CREATE TABLE pqa_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  installation_id INT UNSIGNED NOT NULL,
  type            ENUM('extend','remove') NOT NULL,
  days            INT UNSIGNED NULL,
  team            VARCHAR(255) NULL,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_by    INT UNSIGNED NULL,
  requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by      INT UNSIGNED NULL,
  decided_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_pqareq_inst (installation_id, status),
  CONSTRAINT fk_pqareq_inst      FOREIGN KEY (installation_id) REFERENCES pqa_installations (id),
  CONSTRAINT fk_pqareq_requester FOREIGN KEY (requested_by)    REFERENCES users (id),
  CONSTRAINT fk_pqareq_decider   FOREIGN KEY (decided_by)      REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
