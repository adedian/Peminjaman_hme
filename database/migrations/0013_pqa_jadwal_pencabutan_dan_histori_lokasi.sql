-- Revisi V2 (R4): jadwal pencabutan PQA, tujuan pencabutan (kantor / pindah site), histori durasi & lokasi.
-- Semua perubahan aditif; data pemasangan lama tetap utuh.
ALTER TABLE pqa_installations
  ADD COLUMN planned_install_at DATETIME NULL AFTER submitted_at,
  ADD COLUMN removal_destination ENUM('office','site') NULL AFTER removal_team,
  ADD COLUMN destination_site VARCHAR(255) NULL AFTER removal_destination,
  ADD COLUMN move_target_days INT UNSIGNED NULL AFTER destination_site,
  ADD COLUMN removed_by INT UNSIGNED NULL AFTER removed_at,
  ADD COLUMN previous_installation_id INT UNSIGNED NULL,
  ADD COLUMN next_installation_id INT UNSIGNED NULL,
  ADD COLUMN notes TEXT NULL,
  -- Jadwal pencabutan = waktu pasang AKTUAL + target hari x 24 jam. Kolom turunan: otomatis ikut berubah
  -- saat target diperpanjang, sehingga tidak mungkin basi / tidak sinkron.
  ADD COLUMN due_at DATETIME GENERATED ALWAYS AS (DATE_ADD(installed_at, INTERVAL target_days DAY)) STORED AFTER installed_at,
  ADD KEY idx_pqa_due (status, due_at),
  ADD CONSTRAINT fk_pqa_removed_by FOREIGN KEY (removed_by) REFERENCES users (id),
  ADD CONSTRAINT fk_pqa_prev FOREIGN KEY (previous_installation_id) REFERENCES pqa_installations (id),
  ADD CONSTRAINT fk_pqa_next FOREIGN KEY (next_installation_id) REFERENCES pqa_installations (id);

-- Riwayat perubahan durasi: setiap persetujuan tambah waktu menyimpan jadwal sebelum & sesudah.
ALTER TABLE pqa_requests
  ADD COLUMN old_target_days INT UNSIGNED NULL,
  ADD COLUMN new_target_days INT UNSIGNED NULL,
  ADD COLUMN old_due_at DATETIME NULL,
  ADD COLUMN new_due_at DATETIME NULL;

-- Histori lokasi setiap unit PQA (satu baris per kejadian).
--   installed        dipasang pertama kali di site (dikonfirmasi admin)
--   moved_out        dicabut dari site dan langsung dipindah ke site lain (to_location = site tujuan)
--   moved_in         tiba & dikonfirmasi terpasang di site tujuan
--   removed_office   dicabut dan kembali ke kantor
--   removed          dicabut (data lama sebelum revisi: tujuan tidak tercatat)
CREATE TABLE pqa_location_history (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id         INT UNSIGNED NOT NULL,
  installation_id INT UNSIGNED NULL,
  event           VARCHAR(20) NOT NULL,
  location        VARCHAR(255) NOT NULL,
  to_location     VARCHAR(255) NULL,
  occurred_at     DATETIME NOT NULL,
  confirmed_by    INT UNSIGNED NULL,
  note            VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_pqahist_item (item_id, occurred_at),
  KEY idx_pqahist_inst (installation_id),
  CONSTRAINT fk_pqahist_item FOREIGN KEY (item_id) REFERENCES items (id),
  CONSTRAINT fk_pqahist_inst FOREIGN KEY (installation_id) REFERENCES pqa_installations (id),
  CONSTRAINT fk_pqahist_user FOREIGN KEY (confirmed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengisian awal dari data lama
INSERT INTO pqa_location_history (item_id, installation_id, event, location, occurred_at, confirmed_by)
  SELECT item_id, id, 'installed', location, installed_at, decided_by FROM pqa_installations WHERE installed_at IS NOT NULL;
INSERT INTO pqa_location_history (item_id, installation_id, event, location, occurred_at, note)
  SELECT item_id, id, 'removed', location, removed_at, 'Tujuan tidak tercatat (data sebelum revisi)'
  FROM pqa_installations WHERE status = 'Dicabut' AND removed_at IS NOT NULL;
