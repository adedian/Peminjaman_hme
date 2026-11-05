-- Revisi V2 (R5+R6): notifikasi WhatsApp (antrean, template, log) dan log penjadwal.
-- Pesan hanya dimasukkan SETELAH transaksi bisnis berhasil dikomit, dikirim setelah respons / oleh cron, dan
-- tidak pernah ganda: idempotency_key bersifat UNIQUE.

CREATE TABLE wa_templates (
  event_key          VARCHAR(40)  NOT NULL,
  label              VARCHAR(100) NOT NULL,
  audience           VARCHAR(60)  NOT NULL,
  body               TEXT NOT NULL,
  meta_template_name VARCHAR(100) NULL,
  meta_language      VARCHAR(10)  NOT NULL DEFAULT 'id',
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (event_key),
  CONSTRAINT fk_watpl_user FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_outbox (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  idempotency_key     VARCHAR(190) NOT NULL,
  event_key           VARCHAR(40)  NOT NULL,
  recipient_user_id   INT UNSIGNED NULL,
  recipient_phone     VARCHAR(20)  NULL,
  recipient_kind      ENUM('admin','user') NOT NULL,
  body                TEXT NOT NULL,
  params_json         TEXT NULL,
  entity_type         VARCHAR(20)  NULL,
  entity_id           INT UNSIGNED NULL,
  due_ref             DATETIME NULL,
  status              ENUM('pending','sending','sent','failed','skipped','cancelled') NOT NULL DEFAULT 'pending',
  attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error          VARCHAR(500) NULL,
  next_attempt_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until        DATETIME NULL,
  driver              VARCHAR(20)  NULL,
  provider_message_id VARCHAR(120) NULL,
  delivery_status     VARCHAR(20)  NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at             DATETIME NULL,
  delivered_at        DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_waout_idem (idempotency_key),
  KEY idx_waout_due (status, next_attempt_at),
  KEY idx_waout_entity (entity_type, entity_id),
  KEY idx_waout_msgid (provider_message_id),
  KEY idx_waout_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduler_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  trigger_by  VARCHAR(20) NOT NULL DEFAULT 'cron',
  enqueued    INT UNSIGNED NOT NULL DEFAULT 0,
  cancelled   INT UNSIGNED NOT NULL DEFAULT 0,
  sent        INT UNSIGNED NOT NULL DEFAULT 0,
  failed      INT UNSIGNED NOT NULL DEFAULT 0,
  detail      VARCHAR(500) NULL,
  error       VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_sched_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (key_name, value) VALUES ('admin_whatsapp_numbers', '6285230365427');
INSERT IGNORE INTO settings (key_name, value) VALUES ('wa_enabled', '1');
INSERT IGNORE INTO settings (key_name, value) VALUES ('pqa_reminder_hours', '24');

INSERT INTO wa_templates (event_key, label, audience, body) VALUES
('loan.submitted', 'Peminjaman baru', 'Admin',
 'Peminjaman baru masuk dari {peminjam}.\nBarang: {barang}\nLokasi: {lokasi}\nBatas pengembalian: {batas}\nMohon diproses di aplikasi HME.'),
('loan.approved', 'Barang dipinjam', 'Admin',
 'Barang dipinjam.\nPeminjam: {peminjam}\nBarang: {barang}\nBatas pengembalian: {batas}'),
('loan.due_soon', 'Mendekati jatuh tempo', 'Peminjam',
 'Halo {peminjam}, pengingat: {barang} harus dikembalikan paling lambat {batas} ({sisa}). Mohon kembalikan tepat waktu.'),
('loan.overdue', 'Melewati batas waktu', 'Peminjam',
 'Halo {peminjam}, {barang} sudah melewati batas pengembalian ({batas}), terlambat {sisa}. Mohon segera dikembalikan.'),
('loan.returned', 'Barang dikembalikan', 'Admin',
 '{peminjam} telah mengembalikan {barang} pada {waktu}.'),
('pqa.submitted', 'Pengajuan pemasangan PQA', 'Admin',
 'Permintaan pemasangan PQA baru dari {pemohon}.\nUnit: {unit}\nSite: {site}\nRencana pasang: {rencana}\nDurasi: {durasi} hari'),
('pqa.confirmed', 'Pemasangan PQA dikonfirmasi', 'Admin + pemohon',
 'Pemasangan PQA dikonfirmasi.\nUnit {unit} terpasang di {site} sejak {waktu}.\nJadwal pencabutan: {jadwal} (durasi {durasi} hari).\nPemohon: {pemohon}'),
('pqa.due_soon', 'H-1 pencabutan PQA', 'Admin + pemohon',
 'Pengingat: PQA {unit} di {site} harus dicabut besok, {jadwal} ({sisa}).\nPemohon: {pemohon}'),
('pqa.extended', 'Perpanjangan waktu PQA', 'Admin + pemohon',
 'Jadwal PQA diperpanjang.\nUnit {unit} di {site}: durasi {durasi_lama} menjadi {durasi} hari.\nJadwal pencabutan baru: {jadwal} (sebelumnya {jadwal_lama}).\nPemohon: {pemohon}'),
('pqa.overdue', 'PQA melewati jadwal pencabutan', 'Admin + pemohon',
 'Peringatan: PQA {unit} di {site} melewati jadwal pencabutan {jadwal} (terlambat {sisa}). Mohon segera dicabut.\nPemohon: {pemohon}'),
('pqa.removed', 'PQA dicabut', 'Admin + pemohon',
 'PQA {unit} dicabut dari {site} pada {waktu}.\nTujuan: {tujuan}\nDikonfirmasi: {admin}');
