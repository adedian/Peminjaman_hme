-- Revisi V2 (R3): batas pengembalian (tanggal + jam) per barang yang dipinjam.
-- NULL = pinjaman lama sebelum revisi (tidak punya batas) → tidak pernah dihitung terlambat.
-- Status "Terlambat" TIDAK disimpan: diturunkan dari (approved AND due_at < sekarang), jadi otomatis tanpa cron.
ALTER TABLE loans ADD COLUMN due_at DATETIME NULL AFTER borrowed_at;
ALTER TABLE loans ADD KEY idx_loans_status_due (status, due_at);

INSERT IGNORE INTO settings (key_name, value) VALUES ('loan_max_days', '30');
INSERT IGNORE INTO settings (key_name, value) VALUES ('loan_reminder_hours', '24');
