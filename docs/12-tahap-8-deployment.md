# Tahap 8 — Paket deployment, operasional, backup & go-live

Status: **selesai dan teruji di lingkungan uji** (PHP 8.3 + MariaDB 10.11, simulasi instalasi bersih dari paket `.zip`). **Belum dijalankan di hosting produksi sungguhan** — detail hosting belum diberikan (Q11), jadi panduan ini generik (VPS/Apache/Nginx dan cPanel). Bagian §9 mendaftar apa yang masih harus Anda konfirmasi saat go-live.

## 1. Yang ditambahkan di Tahap 8
| Item | Fungsi |
|---|---|
| `tools/build-package.php` | Membangun `dist/hme-peminjaman-<versi>.zip` bersih (tanpa `.env`, `.git`, `tests/`, `design/`, isi `storage/`) + `MANIFEST.sha256` |
| `tools/backup.php` / `BackupService` | Backup database + foto ke satu `.zip` (murni PHP, tanpa `mysqldump`), snapshot konsisten, checksum, retensi `--keep` |
| `tools/restore.php` | Pemulihan dari `.zip`/`.sql`; simulasi tanpa `--yes`; menolak arsip yang diubah |
| `tools/maintenance.php` / `MaintenanceService` | Pembersihan harian notifikasi/email/sesi/feed lama; audit log tidak pernah dibuang kecuali diminta |
| `tools/maintenance-mode.php` | Saklar mode pemeliharaan (503 untuk semua kecuali `/api/v1/health`) saat upgrade/restore |
| `deploy/` | Contoh Apache vhost, Nginx, cron, `.htaccess` alternatif (Opsi B), `php.ini` yang disarankan |
| `check-env.php` | Kini juga memeriksa umur & lokasi backup di production |
| `BACKUP_DIR` (`.env`) | Folder tujuan backup (disarankan di luar folder web) |

**Temuan keamanan dari tes & sudah diperbaiki:** koneksi PDO mengizinkan multi-statement; berkas backup yang diubah bisa menyelipkan `INSERT …; DROP TABLE …` dalam satu baris. Pemulihan kini memakai koneksi dengan `MYSQL_ATTR_MULTI_STATEMENTS=false` (hanya berefek bila diberikan saat koneksi dibuat — `setAttribute()` sesudahnya tidak berefek di PHP 8.3), memvalidasi tiap baris terhadap daftar pernyataan yang dikenal, dan memverifikasi SHA-256 `database.sql` dari manifest.

## 2. Persyaratan server
- PHP ≥ 8.0 (diuji 8.0.30 dan 8.3.6; XAMPP lokal kini PHP 8.3.36 — sintaks seluruh file + rute tanpa login diperiksa 13 Nov 2026) dengan ekstensi `pdo_mysql mbstring fileinfo gd zip openssl json ctype xml xmlreader`.
- MySQL 8 / MariaDB 10.4+ (utf8mb4). MySQL 8 sungguhan **belum** diuji — hanya MariaDB.
- Apache 2.4 (`mod_rewrite`, `mod_headers`) atau Nginx + PHP-FPM. **HTTPS wajib** (cookie `Secure`, HSTS).
- Cron (untuk backup & pembersihan). Akses SSH sangat membantu; tanpa SSH tetap bisa lewat cPanel (lihat §4).
- Pengaturan PHP yang disarankan: `deploy/php.ini.recommended`.

## 3. Instalasi pertama — VPS (Opsi A, disarankan)

**3.1 Bangun paket** (di komputer pengembang, sekali per rilis):
```bash
composer install --no-dev --optimize-autoloader
php tools/build-package.php
```
Hasil: `dist/hme-peminjaman-<versi>.zip` dan SHA-256-nya. Catat SHA-256 dan cocokkan setelah unggah.

**3.2 Unggah & ekstrak di server:**
```bash
sudo mkdir -p /var/www && cd /var/www
sudo unzip hme-peminjaman-<versi>.zip          # menghasilkan /var/www/hme-peminjaman
cd hme-peminjaman && sha256sum -c MANIFEST.sha256 --quiet && echo OK
sudo chown -R www-data:www-data storage         # PHP-FPM harus bisa menulis storage/
sudo chmod -R u+rwX,g+rX,o-rwx storage
```

**3.3 Database** (nama & sandi diganti):
```sql
CREATE DATABASE hme_peminjaman CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hme_app'@'localhost' IDENTIFIED BY '<sandi-panjang-acak>';
GRANT ALL PRIVILEGES ON hme_peminjaman.* TO 'hme_app'@'localhost';
```
Pengguna khusus ini **bukan root** dan hanya punya hak atas satu database.

**3.4 Konfigurasi `.env`:**
```bash
cp .env.example .env && chmod 640 .env && chown www-data:www-data .env
```
Isi minimal: `APP_ENV=production`, `APP_DEBUG=false`, `APP_BASE_PATH=` (kosong), `DB_*`, `BACKUP_DIR=/var/backups/hme` (di luar folder web), `LOG_DIR=/var/log/hme` (opsional). Di belakang proxy/CDN: `TRUST_PROXY=true`. SMTP: lihat §6.

**3.5 Skema, admin pertama, pemeriksaan:**
```bash
php database/migrate.php --seed
php tools/create-admin.php --username=admin --name="Admin HME" --generate
php tools/check-env.php
```
`--generate` mencetak sandi acak **sekali**; admin wajib menggantinya saat login pertama. Tidak ada sandi bawaan.

**3.6 Web server:** pakai `deploy/apache-vhost.conf.example` atau `deploy/nginx.conf.example`. **Document root = `public/`**. Terbitkan sertifikat (mis. `certbot`) lalu muat ulang web server.

**3.7 Cron:** salin isi `deploy/crontab.example` (backup 02:15, pembersihan 03:10, integritas mingguan, email tertunda).

**3.8 Uji asap** (setelah web server aktif):
```bash
curl -s https://peminjaman.example.com/api/v1/health      # {"ok":true,"data":{"status":"ok","db":"up",…}}
curl -sI https://peminjaman.example.com/.env              # harus 403/404
curl -sI https://peminjaman.example.com/storage/logs/     # harus 403/404
```
Lalu login di browser, ganti sandi, buka Beranda, Barang, Pengaturan.

## 4. Instalasi — cPanel / shared hosting
1. **Subdomain/addon domain** dengan document root `…/hme-peminjaman/public` (cPanel → *Domains* → atur *Document Root*). Ini Opsi A dan paling aman.
2. Unggah `.zip` lewat *File Manager*, *Extract*. Buat database + user lewat *MySQL® Databases*; beri semua hak ke user itu.
3. Buat `.env` dari `.env.example` (edit di File Manager). `BACKUP_DIR` ke folder di luar `public_html` (mis. `/home/<akun>/backups/hme`).
4. Pilih versi PHP ≥ 8.0 di *MultiPHP Manager*, aktifkan ekstensi di *Select PHP Version*.
5. Tanpa SSH: jalankan `database/migrate.php` dan `tools/create-admin.php` lewat *Terminal* cPanel bila tersedia. Jika tidak ada terminal sama sekali, minta pihak hosting atau pakai VPS kecil untuk langkah awal — `migrate.php` menolak berjalan dari web.
6. **Opsi B** (document root tidak bisa diubah, aplikasi di `public_html`): salin `deploy/root.htaccess.production` menjadi `.htaccess` di folder induk. Semua permintaan diarahkan ke `public/`. Lebih rapuh dari Opsi A — verifikasi `https://domain/.env` dan `https://domain/app/bootstrap.php` memberi 403/404.
7. Cron Jobs: masukkan keempat baris `deploy/crontab.example` memakai path `php` dari cPanel (mis. `/usr/local/bin/php`).

## 5. Rilis berikutnya (upgrade)
```bash
php tools/backup.php                       # selalu backup dulu
php tools/maintenance-mode.php on
# ekstrak paket baru ke folder baru, salin .env dan (bila tidak di luar app) storage/uploads, lalu arahkan root/symlink
php database/migrate.php                   # hanya migrasi yang belum diterapkan
php tools/check-env.php && php tools/check-integrity.php
php tools/maintenance-mode.php off
```
Cara termudah: ekstrak ke `hme-peminjaman-<versi>/`, pindahkan `.env`, lalu ganti symlink `current → hme-peminjaman-<versi>`. Rollback = kembalikan symlink (+ restore backup bila migrasi sudah mengubah skema). Simpan foto di `UPLOAD_DIR` di luar folder rilis agar tidak ikut berganti.

## 6. Email (SMTP)
Isi `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`. Gmail: pakai *App Password* (verifikasi 2 langkah wajib), port 587/`tls`. Setelah itu admin membuka **Pengaturan → Kirim email uji**. Tanpa `MAIL_HOST`, email dilewati dan dicatat; transaksi tidak terganggu. **Uji kirim SMTP sungguhan belum pernah dijalankan** (akun belum tersedia).

## 7. Backup & pemulihan
| | |
|---|---|
| Apa yang dicadangkan | semua tabel (struktur + data) dan seluruh foto di `UPLOAD_DIR`. **Tidak** termasuk `.env` — simpan terpisah (pengelola sandi / brankas) |
| Kapan | harian lewat cron; tambahan manual sebelum upgrade/migrasi besar |
| Di mana | `BACKUP_DIR` di luar document root. **Salin juga ke lokasi lain** (rclone/rsync/unduh berkala) — backup yang satu mesin dengan aplikasi tidak melindungi dari disk/server rusak |
| Retensi | `--keep=14` (default) menyimpan 14 backup terakhir |
| Cek | `check-env.php` memperingatkan bila backup terakhir > 36 jam |

**Pemulihan** (juga untuk memindahkan ke server baru):
```bash
php tools/maintenance-mode.php on
php tools/restore.php --file=/var/backups/hme/hme-backup-YYYYMMDD-HHMMSS.zip            # simulasi
php tools/restore.php --file=/var/backups/hme/hme-backup-YYYYMMDD-HHMMSS.zip --yes      # sungguhan
php tools/check-integrity.php
php tools/maintenance-mode.php off
```
Pemulihan **menggantikan seluruh tabel**. Pada server baru: pasang paket, buat database kosong + `.env`, lalu `restore.php` (tak perlu `migrate.php`: skema ikut di dalam backup; `schema_migrations` ikut terpulihkan).

**Latihan pemulihan:** lakukan sekali sebelum go-live dan tiap kuartal ke database terpisah (`DB_NAME=hme_uji php tools/restore.php --file=… --yes`) — backup yang tak pernah dipulihkan belum terbukti.

## 8. Operasional rutin
| Frekuensi | Tindakan |
|---|---|
| Harian (otomatis) | backup 02:15, `maintenance.php` 03:10 |
| Mingguan (otomatis) | `check-integrity.php` — periksa `storage/logs/cron-integrity.log` |
| Mingguan (manual) | lihat `storage/logs/app-*.log` untuk baris `ERROR`; cek halaman **Log Aktivitas** untuk login gagal beruntun |
| Bulanan | periksa ruang disk (`storage/`, `BACKUP_DIR`), salinan backup di lokasi kedua, pembaruan keamanan PHP/OS |
| Kuartalan | latihan restore; `composer audit` di mesin pengembang lalu rilis ulang bila ada celah |
| Bila perlu | reset password pengguna dari halaman **Pengguna**; nonaktifkan akun yang tidak lagi dipakai |

Log berotasi harian per berkas; hapus `app-*.log` > 30 hari (`logrotate` atau cron `find`). `maintenance.php --audit-days=730` bila ingin membatasi pertumbuhan audit log (default: tidak pernah dihapus).

## 9. Daftar periksa go-live (cut-over)
Berdasarkan §8 rencana. Centang berurutan.

**H-7 sampai H-1**
- [ ] Hosting siap: HTTPS aktif, PHP ≥ 8.0 + ekstensi, `php tools/check-env.php` tanpa `[GAGAL]`.
- [ ] Akun SMTP terisi; **email uji** dari halaman Pengaturan diterima.
- [ ] Folder foto Drive dibagikan → `migrate-legacy.php --download-photos` (dry-run dulu). *Foto belum termigrasi saat ini.*
- [ ] Latihan restore backup sukses.
- [ ] Pengguna dilatih dengan `docs/13-panduan-pengguna.md`.

**Hari-H (jendela sepi)**
- [ ] Umumkan *freeze*: semua pinjam/kembali dicatat di kertas selama jendela.
- [ ] Nonaktifkan (atau set read-only) deployment Apps Script lama.
- [ ] Ekspor final Google Sheet → `.xlsx` **di luar folder aplikasi** (berisi sandi lama dalam teks biasa).
- [ ] `php tools/backup.php` (jaring pengaman).
- [ ] `php tools/migrate-legacy.php --source=<xlsx> --dry-run`, periksa; lalu tanpa `--dry-run --yes`.
- [ ] Rekonsiliasi: **semua cocok** (halaman Laporan Migrasi); tangani 19 baris "perlu diperiksa" yang diketahui.
- [ ] `php tools/check-integrity.php` → data konsisten.
- [ ] Hapus ekspor `.xlsx` dengan aman dari mesin yang tidak perlu menyimpannya.
- [ ] Login sebagai admin dan 1 staff; pinjam → setujui → kembalikan satu barang uji (lalu batalkan/kembalikan).
- [ ] Buka akses ke semua pengguna; **semua pengguna wajib ganti sandi** saat login pertama (sudah otomatis).

**H+1 sampai H+7**
- [ ] Pastikan backup otomatis pertama muncul dan `check-env.php` hijau.
- [ ] Pantau `storage/logs/` dan keluhan pengguna; sistem lama dipertahankan read-only minimal 30 hari sebagai rujukan.

## 10. Yang BELUM teruji / perlu konfirmasi
1. **Hosting produksi sungguhan** — Apache/Nginx nyata, HTTPS, cron, izin berkas. Konfigurasi di `deploy/` adalah contoh yang ditulis dari praktik standar dan **belum dijalankan atau divalidasi** (`nginx -t` / `apachectl configtest`) — jalankan uji sintaks itu sebelum dipakai.
2. **SMTP sungguhan** (§6).
3. **MySQL 8** (hanya MariaDB 10.4 dan 10.11 yang diuji).
4. **Foto barang hasil migrasi** (menunggu folder Drive dibagikan).
5. **Beban banyak proses** — `php -S` satu proses; ulangi di server produksi.
6. **Safari iOS / Firefox** — hanya Chromium.
7. Server tes lokal tanpa Apache: 13 tes `tests/cases/http.php` memerlukan Apache (XAMPP) dan dilewati di lingkungan ini; selebihnya hijau.
