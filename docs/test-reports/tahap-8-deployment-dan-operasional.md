# Laporan Uji — Tahap 8: Deployment & operasional

Tanggal: 4 November 2026 · Lingkungan: Linux, PHP 8.3.6, MariaDB 10.11.14 (bukan XAMPP/Windows seperti Tahap 7) · Perintah: `php tests/run.php`

## 1. Ringkasan
| | |
|---|---|
| Tes otomatis (tanpa HTTP-Apache) | **214 lulus, 0 gagal** (+5 tes baru Tahap 8: 4 service/CLI, 1 E2E) |
| Run penuh dengan E2E (`php -S`) | **299 lulus, 13 gagal** — semua E2E lulus; **13 tes `cases/http.php` gagal hanya karena tidak ada Apache** di lingkungan ini (pesan "server Apache tidak dapat dihubungi") |
| Catatan lingkungan | 2 tes (`Request`, `Sesi` cookie path) mengasumsikan `APP_BASE_PATH=/Peminjaman_hme` dari `.env` XAMPP; tanpa `.env` jalankan `APP_BASE_PATH=/Peminjaman_hme php tests/run.php` |
| Bug/celah ditemukan & diperbaiki | **1 celah keamanan** (multi-statement pada pemulihan, lihat docs/12 §1) |

## 2. Tes baru
| Tes | Membuktikan |
|---|---|
| Backup → rusak → pulihkan | setiap tabel identik (hash per tabel) setelah restore; foto kembali; teks dengan kutip, `\`, baris baru, emoji, `;`, NULL selamat; integritas data lulus; CLI `backup.php` jalan, `restore.php` tanpa `--yes` tidak mengubah apa pun |
| Arsip/SQL berbahaya ditolak | checksum tak cocok; `DROP DATABASE`, `GRANT`, `SET GLOBAL`, baris tanpa `;`, **`INSERT …; DROP TABLE …` satu baris (multi-statement)** → ditolak & database utuh; zip-slip (`../`), `.htaccess`, `.php`, non-gambar tidak diekstrak |
| Retensi backup | `--keep` menyisakan yang terbaru; berkas lain tidak disentuh |
| Pembersihan | hanya data usang dibuang (notifikasi dibaca/tak terbaca, email terkirim/gagal, feed, percobaan login, sesi/ekspor); dry-run menghitung tanpa menghapus; data bisnis & audit log utuh; `--audit-days` minimal 90 |
| E2E mode pemeliharaan | halaman 503 + `Retry-After`, API 503 JSON (`maintenance`), sesi login pun ditahan, `/api/v1/health` tetap 200; `off` memulihkan |

## 3. Simulasi instalasi bersih dari paket
Dengan paket hasil `build-package.php` (4.245 berkas, 12,5 MB) diekstrak ke folder kosong:
`sha256sum -c MANIFEST.sha256` ✓ → `.env` dari contoh + user DB non-root → `migrate.php --seed` (10 migrasi) ✓ → `create-admin.php` ✓ → `check-env.php` "siap digunakan" ✓ → `backup.php` ✓ → `restore.php --yes` ke database lain ✓ → `check-integrity.php` "Data konsisten" ✓ → `php -S public/` : `/api/v1/health` 200 `db: up` dengan header keamanan lengkap, `/login` 200, `/.env` 404.
Paket tidak berisi `.env`, `.git`, `tests/`, `design/`, `docs/test-reports/`, atau isi `storage/`.

## 4. Belum teruji
Lihat `docs/12-tahap-8-deployment.md` §10 (hosting produksi, SMTP, MySQL 8, beban multi-proses, Safari/Firefox, foto migrasi, konfigurasi Apache/Nginx contoh).
