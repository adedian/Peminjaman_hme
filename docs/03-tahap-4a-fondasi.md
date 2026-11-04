# Tahap 4a — Fondasi Backend

Status: **selesai dan teruji** (90 tes otomatis lulus, dijalankan berulang). Belum ada fitur bisnis; 4b (login & pengguna) berikutnya.

## 1. Menjalankan
```bash
php database/migrate.php --create-db --seed     # buat database + 7 migrasi + data acuan
php tools/check-env.php                         # pemeriksa lingkungan
php tests/run.php                               # 90 tes (database tes terpisah: hme_peminjaman_test)
php tests/run.php --no-http                     # tanpa tes HTTP
php tests/run.php --only=CSRF                   # filter berdasarkan nama
```
Aplikasi: `http://localhost/Peminjaman_hme/` · Health: `/api/v1/health` · Acuan desain (dev saja): `/design/`.
Konfigurasi ada di `.env` (contoh: `.env.example`). Tanpa `.env`, aplikasi berjalan sebagai **production** (default aman).

## 2. Struktur
```
public/            document root (index.php = front controller, .htaccess, assets/)
app/Core/          Env, Config, Database(PDO), Session, Csrf, Request, Response, Router, View,
                   Validator, Logger, ErrorHandler, HttpException, Migrator
app/Middleware/    Auth, Role, Csrf, ForcePasswordChange
app/Controllers/   Api/, Web/          app/Views/   layout, halaman, errors/
app/Services|Models|Helpers            (Services & Models diisi mulai 4b)
routes/            api.php, web.php    database/    migrations/, seeds/, migrate.php
storage/           logs, sessions, uploads, cache, imports, exports (tidak dilayani web)
tests/  tools/  docs/  design/(dev saja)
```
Aturan arsitektur yang sudah dipaksakan oleh kode: controller tipis → logika bisnis di `Services` (mulai 4b); view hanya menampilkan dan memakai `e()`; semua query lewat `Database` (prepared statement).

## 3. Keamanan yang sudah ada dan teruji
| Kontrol | Implementasi | Bukti tes |
|---|---|---|
| SQL injection | PDO native prepared statement, bind bertipe, identifier divalidasi, mode SQL ketat | payload `' OR '1'='1`, `; DROP TABLE`, `1 OR 1=1`; insert/update dengan nama kolom berbahaya ditolak |
| CSRF | otomatis untuk semua POST/PUT/PATCH/DELETE (tidak bisa lupa dipasang), token di header/body, `hash_equals`, cek Origin | tanpa/salah/benar/non-string token, Origin asing & beda port |
| Sesi | cookie HttpOnly + SameSite=Lax + Secure di HTTPS, strict mode, folder sesi sendiri, regenerate saat login, timeout idle & absolut, polling tidak memperpanjang idle | 8 tes sesi, termasuk timeout sungguhan |
| Autentikasi | tiap endpoint memanggil `AuthMiddleware`; status akun dibaca dari DB tiap request → penonaktifan & perubahan role **seketika** | akun dinonaktifkan saat sesi aktif → 401 |
| Otorisasi | `RoleMiddleware`; CSRF dipasang setelah auth (sesi habis → 401) | staff → rute admin = 403 |
| Password | `password_hash`/`verify`; wajib ganti password (hasil migrasi) lewat middleware | hash ≠ plaintext, verify benar/salah |
| XSS | `e()` untuk semua output; CSP `script-src 'self'` tanpa `unsafe-inline`/`unsafe-eval` | halaman tanpa skrip inline |
| Header | CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS (HTTPS), `X-Powered-By` dihapus | HTTP test |
| Kebocoran | production menyembunyikan detail error; log menyamarkan password/token/cookie | tes ErrorHandler & Logger |
| File sensitif | hanya `public/` yang dijangkau; `.env`, `app/`, `database/`, `storage/`, `tests/`, `docs/` → 403/404; traversal diuji | 20 path + 6 payload traversal |
| Anonim | pengunjung tanpa cookie tidak dibuatkan sesi | tidak ada file sesi/Set-Cookie |

## 4. Database
7 migrasi, 16 tabel + `schema_migrations`, semua InnoDB utf8mb4. Aturan bisnis yang dijaga **langsung oleh database** (lapisan terakhir, di luar service):
- **BR-03 anti double-booking:** kolom turunan `loans.active_item_key` + UNIQUE → satu barang hanya bisa punya satu loan `pending/approved`. `rejected/returned/cancelled` tidak memblokir. Teruji, termasuk dua koneksi serentak.
- **BR-19:** `pqa_installations.active_item_key` + UNIQUE untuk satu pemasangan aktif per unit.
- Foreign key RESTRICT: barang/user yang punya riwayat tidak bisa terhapus.
- `seq_no` dan `legacy_id` unik (banyak NULL diizinkan).

Perubahan skema terhadap draf §5 dokumen planning (hasil keputusan Q3 dan penyederhanaan):
| Draf | Final |
|---|---|
| `items.deleted_at` | `items.is_active` (nonaktifkan, keputusan Q3) |
| `loan_batches` berisi tim/lokasi/catatan | hanya `id, legacy_prefix, user_id`; tim/lokasi/catatan tetap per-`loans` agar data lama persis terjaga |
| `users.deleted_at` | tidak ada; hanya `is_active` |
| status loan | ditambah `cancelled` (Q5) |
| `activity_logs.before/after_json` | `LONGTEXT` (kompatibel MariaDB & MySQL) |

## 5. Yang BELUM ada di 4a (sengaja)
Login/logout & halaman masuk, pembuatan akun admin, rate limit login (4b); semua modul bisnis (4c–4g); realtime (`/sync`) dan frontend (5); migrasi data (6). `Services/` dan `Models/` masih kosong.

## 6. Catatan deployment (akan masuk panduan Tahap 8)
- Arahkan document root domain ke `public/`; hapus `.htaccess` di root proyek dan folder `design/`.
- `APP_ENV=production`, `APP_DEBUG=false`, `DB_USER` bukan root, `DB_PASS` terisi; jalankan `php tools/check-env.php`.
- Apache menampilkan versi server/PHP di header `Server` (konfigurasi Apache, bukan aplikasi): set `ServerTokens Prod` dan `expose_php=Off` bila hosting mengizinkan.
- Taruh `LOG_DIR` di luar folder aplikasi bila memungkinkan; pastikan `storage/` dapat ditulis.
- Pastikan HTTPS aktif agar cookie `Secure` dan HSTS berlaku.

## 7. Batas pengujian
Teruji otomatis: seluruh Core, middleware, skema/constraint, dan HTTP ke Apache lokal. **Belum teruji** (karena belum ada fiturnya): alur login nyata di browser, rate limit, upload. Tes dijalankan pada PHP 8.0.30 + MariaDB 10.4.32; MySQL 8 belum dicoba.
