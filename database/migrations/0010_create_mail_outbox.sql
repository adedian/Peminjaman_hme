-- Antrean email (outbox). Email disimpan dulu setelah transaksi bisnis berhasil, lalu dikirim SETELAH respons
-- dikirim ke browser (atau oleh tools/send-mail.php via cron). Kegagalan SMTP tidak pernah menggagalkan transaksi
-- dan email dicoba ulang dengan jeda bertahap.
CREATE TABLE mail_outbox (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event           VARCHAR(40)  NULL,
  recipients      VARCHAR(1000) NOT NULL,
  subject         VARCHAR(200) NOT NULL,
  html_body       MEDIUMTEXT NOT NULL,
  text_body       MEDIUMTEXT NULL,
  status          ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(500) NULL,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at         DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_outbox_due (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
