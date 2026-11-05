# Peminjaman HME

Sistem peminjaman barang & tracker PQA PT Hexa Multi Energi (PHP 8 + MySQL/MariaDB), pengganti aplikasi Google Apps Script lama.

## Mulai cepat (pengembangan, XAMPP)
```bash
composer install
cp .env.example .env        # APP_ENV=development, APP_BASE_PATH=/Peminjaman_hme, DB_USER=root
php database/migrate.php --create-db --seed
php tools/create-admin.php --username=admin --name="Admin HME" --generate
php tests/run.php            # 300+ tes (tes HTTP butuh Apache lokal; --no-http untuk melewatinya)
```

## Dokumentasi
| Berkas | Isi |
|---|---|
| `docs/12-tahap-8-deployment.md` | **Deployment, backup/restore, operasional, daftar periksa go-live** |
| `docs/13-panduan-pengguna.md` | Panduan staff & admin |
| `docs/01-audit-dan-perencanaan.md` | Audit sistem lama, aturan bisnis, keputusan |
| `docs/03…11-*.md` | Rincian per tahap (fondasi → migrasi) |
| `docs/CHANGELOG.md`, `docs/test-reports/` | Riwayat perubahan & laporan uji |

## Perintah operasional (`tools/`)
`check-env` · `create-admin` · `backup` · `restore` · `maintenance` · `maintenance-mode` · `check-integrity` · `send-mail` · `migrate-legacy` · `build-package`

## Revisi V2 (dashboard, batas pengembalian, PQA berjadwal, PWA & notifikasi push)
Lihat `docs/15-revisi-v2-implementasi.md` dan `docs/16-pwa-notifikasi-push.md` (cara mengaktifkan notifikasi HP, keputusan, dan status verifikasi).
Wajib di server: **HTTPS**, kunci VAPID (`php tools/generate-vapid.php --write`), dan cron penjadwal `*/5 * * * * php tools/scheduler.php`.
