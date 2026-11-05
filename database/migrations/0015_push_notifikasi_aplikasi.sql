-- Revisi V2b: notifikasi dikirim dari APLIKASI (PWA + Web Push), bukan WhatsApp.
-- Tabel/kolom WhatsApp dari migrasi 0011/0014 DIBIARKAN apa adanya (tidak ada data nyata di dalamnya, tidak lagi dipakai kode).

-- Notifikasi dalam aplikasi (lonceng) kini juga dasar pesan push. Kunci idempotensi mencegah pengingat ganda.
ALTER TABLE notifications
  ADD COLUMN dedupe_key  VARCHAR(190) NULL,
  ADD COLUMN entity_type VARCHAR(20)  NULL,
  ADD COLUMN entity_id   INT UNSIGNED NULL,
  ADD COLUMN due_ref     DATETIME     NULL,
  ADD UNIQUE KEY uq_notif_dedupe (user_id, dedupe_key);

-- Perangkat yang berlangganan push. Satu endpoint = satu browser/perangkat; berpindah pengguna → endpoint diambil alih.
CREATE TABLE push_subscriptions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  endpoint         VARCHAR(700) NOT NULL,
  endpoint_hash    CHAR(64)     NOT NULL,
  p256dh           VARCHAR(200) NOT NULL,
  auth             VARCHAR(100) NOT NULL,
  user_agent       VARCHAR(255) NULL,
  label            VARCHAR(80)  NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  failure_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error       VARCHAR(300) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_success_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id, is_active),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Antrean kirim: satu baris per (notifikasi, perangkat). Dikirim setelah respons / oleh penjadwal; gagal → dicoba ulang bertahap.
CREATE TABLE push_outbox (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_id  BIGINT UNSIGNED NOT NULL,
  subscription_id  INT UNSIGNED NOT NULL,
  status           ENUM('pending','sending','sent','failed','gone','cancelled') NOT NULL DEFAULT 'pending',
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  http_status      SMALLINT NULL,
  last_error       VARCHAR(500) NULL,
  next_attempt_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at          DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pushout_pair (notification_id, subscription_id),
  KEY idx_pushout_due (status, next_attempt_at),
  KEY idx_pushout_sub (subscription_id),
  CONSTRAINT fk_pushout_notif FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE,
  CONSTRAINT fk_pushout_sub FOREIGN KEY (subscription_id) REFERENCES push_subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (key_name, value) VALUES ('push_enabled', '1');
