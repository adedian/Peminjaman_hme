-- Revisi V2 (R1+R2): nomor WhatsApp di Master Pengguna; akun tidak lagi dipaksa mengganti password.
-- Password lama TIDAK diubah. Hanya penanda "wajib ganti" yang dimatikan.
ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(20) NULL AFTER initials;
UPDATE users SET must_change_password = 0 WHERE must_change_password = 1;
ALTER TABLE users MODIFY must_change_password TINYINT(1) NOT NULL DEFAULT 0;
