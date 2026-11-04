-- Versi kredensial: dinaikkan setiap kali password diganti/direset atau akun dinonaktifkan.
-- Sesi menyimpan versi saat login; versi tidak cocok = sesi tidak berlaku (menutup sesi di perangkat lain).
ALTER TABLE users
  ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER must_change_password;
