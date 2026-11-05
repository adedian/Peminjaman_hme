# Catatan Perubahan

## 2026-11-05 — Revisi V2b: PWA + notifikasi dari aplikasi (menggantikan WhatsApp) + logo "Peminjaman HME"
- **PWA:** manifes, service worker (push, cache aset, halaman offline), ikon (192/512/maskable/iOS/favicon/lencana), tombol *Pasang aplikasi*.
- **Notifikasi push** (Web Push + VAPID, tanpa layanan pihak ketiga): setiap notifikasi aplikasi otomatis diteruskan ke HP penerima. Peristiwa: peminjaman baru/dipinjam/dikembalikan, jatuh tempo & terlambat, PQA diajukan/dikonfirmasi/diperpanjang/dicabut/H-1/terlambat. Antrean idempoten, retry, perangkat kedaluwarsa dinonaktifkan, pengingat basi dibatalkan, anti-SSRF, logout melepas langganan.
- **Profil:** Aktifkan/matikan notifikasi per perangkat, notifikasi uji, daftar perangkat. **Pengaturan (admin):** status push, jendela pengingat, log pengiriman + kirim ulang.
- **Logo:** heksagon HME dipangkas rapi + tulisan "Peminjaman HME" (login, sidebar, header, offline); semua ikon dibuat ulang (`tools/make-icons.php`).
- **WhatsApp dihapus** dari kode/UI/pengaturan (tabel & kolom lama dibiarkan, tidak dipakai). Migrasi 0015 (aditif).
- Bug ditemukan tes & diperbaiki: service worker belum menyaring URL `javascript:` pada klik notifikasi.
- Detail, cara mengaktifkan, dan status verifikasi (termasuk yang BELUM terbukti di perangkat nyata): `docs/16-pwa-notifikasi-push.md`.

## 2026-11-05 — Revisi V2: dashboard, batas pengembalian, PQA berjadwal, WhatsApp, penjadwal (lulus uji otomatis; pengiriman WhatsApp nyata BELUM terverifikasi)
- **Login:** tidak ada lagi paksa ganti password (login pertama / akun baru / reset admin / migrasi). Password lama dipertahankan; format lama (teks biasa) di-hash otomatis setelah login berhasil.
- **Master Pengguna:** nomor WhatsApp (dinormalisasi, tersamar di log/audit).
- **Peminjaman:** batas pengembalian wajib (maks. 30 hari, dapat diatur), status *Terlambat* otomatis, hitung mundur dari jam server, filter "Terlambat", pintasan batas waktu di form.
- **PQA:** jadwal pasang & cabut berbasis jam aktual, riwayat perubahan durasi, pencabutan *kembali ke kantor* atau *pindah site* (pemasangan berikutnya terhubung), histori lokasi per unit.
- **WhatsApp (Cloud API resmi):** antrean idempoten, template yang dapat diedit, retry bertahap, log + kirim ulang, webhook status (HMAC), pengaturan admin. Tanpa kredensial → pesan menunggu di antrean (bukan pura-pura terkirim).
- **Penjadwal:** `php tools/scheduler.php` (cron 5 menit) — pengingat jatuh tempo/terlambat peminjaman, H-1/terlambat PQA, pengingat basi dibatalkan, log putaran; `check-env` memeriksa kesehatannya.
- **Dashboard:** KPI, perlu-perhatian, grafik (SVG), jatuh tempo terdekat, PQA terpasang, aktivitas; realtime.
- **Migrasi 0011–0014** (aditif; tanpa menghapus data). **Integritas:** 2 aturan baru (rantai pemindahan PQA, jadwal cabut).
- Bug ditemukan tes & diperbaiki: parameter array pada webhook (500), nomor berspasi terpecah, overflow baris batas waktu di HP.
- Detail, keputusan, cara mengaktifkan WhatsApp, dan status verifikasi: `docs/15-revisi-v2-implementasi.md`.

## 2026-11-04 — Hapus permanen master barang
- `ItemService::delete` + `POST /api/v1/items/{id}/delete` (admin) + tombol **Hapus permanen** di menu aksi barang.
- **Aturan:** hanya barang tanpa riwayat peminjaman/PQA (mis. salah input). Barang berriwayat ditolak `409 has_history` dan tombolnya nonaktif dengan penjelasan — gunakan Nonaktifkan, agar riwayat & rekap tetap utuh (BR-14). Foto & thumbnail ikut dihapus setelah transaksi berhasil; tercatat di audit log (`item.deleted`, memuat kode/nama) dan change feed; kode bekas barang terhapus bisa dipakai lagi.
- Tes: 1 service + 1 E2E baru (staff 403, anonim 401, tanpa CSRF 403, berriwayat 409, bersih 200→404); diperiksa di browser.

## 2026-11-04 — Tampilan desktop: konten utama rata tengah
- `.main` di layar ≥ 1024px kini `margin: 0 auto` (sebelumnya menempel ke sidebar).

## 2026-11-04 — Tahap 8: Paket deployment, backup & operasional (selesai di lingkungan uji; hosting produksi belum)
- `tools/build-package.php`: paket `.zip` bersih + `MANIFEST.sha256`; simulasi instalasi bersih dari paket lulus (migrasi, admin, check-env, health 200).
- `BackupService` + `tools/backup.php` / `restore.php`: backup database+foto tanpa `mysqldump` (snapshot konsisten, checksum, retensi), pemulihan hanya menerima pernyataan dikenal.
- **Celah diperbaiki:** pemulihan bisa dibobol lewat multi-statement pada satu baris; kini koneksi memakai `MYSQL_ATTR_MULTI_STATEMENTS=false` (opsi koneksi `Database::connect(..., $extraOptions)`).
- `MaintenanceService` + `tools/maintenance.php` (pembersihan harian), `tools/maintenance-mode.php` (503 untuk semua kecuali `/api/v1/health`, dipakai saat upgrade/restore).
- `deploy/`: contoh Apache, Nginx, cron, `.htaccess` Opsi B (cPanel), `php.ini`; `BACKUP_DIR` di `.env`; `check-env.php` memeriksa umur & lokasi backup.
- Dokumen: `docs/12-tahap-8-deployment.md` (instalasi VPS/cPanel, upgrade, backup, operasional, daftar periksa go-live), `docs/13-panduan-pengguna.md`, `README.md`, laporan uji `docs/test-reports/tahap-8-deployment-dan-operasional.md`.
- Tes: 5 baru (214 tes non-Apache lulus; 13 tes `http.php` memerlukan Apache XAMPP).

## 2026-11-04 — Tahap 7: Pengujian menyeluruh (selesai, 307 tes lulus dua kali berturut-turut)
- Sapuan otomatis atas **seluruh 82 rute** (dibangkitkan dari router): kebijakan akses statis, matriks otorisasi anonim/staff/admin, CSRF, fuzzing query & badan (±2.000 request, tanpa 5xx), XSS tersimpan di semua halaman, production + database mati tanpa kebocoran.
- Bug diperbaiki: byte NUL pada tanggal menyebabkan 500 (`Validator` aturan `date`, filter tanggal log) — PHP 8 `createFromFormat` melempar `ValueError`.
- `IntegrityService` + `php tools/check-integrity.php` (13 aturan); database kerja konsisten.
- Responsif 360/768/1280 px semua halaman: tanpa scroll horizontal; target sentuh "Lihat semua" diperbesar ke 44 px.
- Laporan: `docs/test-reports/tahap-7-pengujian-menyeluruh.md`.

## 2026-11-04 — Tahap 6: Migrasi data & rekonsiliasi (selesai, 300 tes lulus)
- `tools/migrate-legacy.php` + `app/Migration/*`: impor read-only, satu transaksi, dry-run, idempoten, laporan per baris; password lama di-hash & wajib diganti; menolak ekspor di dalam folder aplikasi.
- Rekonsiliasi otomatis dari sumber (akun, barang, transaksi per baris/akun/barang, pengajuan, paritas `getMonthlyStats` 14 periode & Laporan Stok, arsip, PQA, register) — database kerja: 18/18 cocok.
- Koreksi rencana: pengajuan 47 (bukan 59 prefix; ID terpecah antar-milidetik); urutan baris sheet (`legacy_row`) dipakai rekap agar ranking identik.
- Halaman admin **Arsip Data Lama** (catatan manual, register + "Jadikan Master Barang") dan **Laporan Migrasi** (rekonsiliasi, rincian, XLSX); `ImageService::storeLocal` untuk unduh foto Drive.
- Detail: `docs/11-tahap-6-migrasi.md`.

## 2026-11-04 — Tahap 5: Frontend realtime (selesai, 291 tes lulus)
- `GET /api/v1/sync?since=` (`SyncService`): 204 bila tidak ada perubahan, delta jenis+ID yang disaring per peran, reset bila kursor tidak valid / > 500 perubahan, pembersihan feed lama; polling tidak memperpanjang sesi idle.
- `realtime.js`: satu poller per browser (Web Locks + BroadcastChannel), interval adaptif 1,5 / 5 / 20 dtk, backoff, lonceng & lencana & toast global; API `hmeLive.on/swap` untuk halaman.
- Semua halaman diperbarui otomatis tanpa me-reset formulir; peringatan inline saat barang di keranjang diambil orang lain; detail terbuka diperbarui tanpa memindah fokus.
- Uji dua akun: latensi 0,3–1,3 dtk (target ≤ 2 dtk), isian form utuh.
- Detail & bukti: `docs/10-tahap-5-realtime.md`.

## 2026-11-04 — Tahap 4g: Audit log, notifikasi, email (selesai, 284 tes lulus)
- `MailService` (PHPMailer 6.12.0, antrean `mail_outbox` / migrasi `0010`, kirim setelah respons, coba ulang bertahap, `tools/send-mail.php`), email BR-12 meniru sistem lama ("Permohonan Baru", "Barang Kembali"); SMTP kosong → dilewati & dicatat.
- `NotificationService` + lonceng di bilah atas, halaman `/notifications`; `LoanNotifier`/`PqaNotifier` diisi (penerima per peran, pelaku tidak menerima notifikasinya sendiri); tautan `?open=` membuka detail pengajuan/PQA.
- Halaman admin **Log Aktivitas** (`/logs`, filter kelompok/pelaku/kata kunci/tanggal, rincian sebelum/sesudah) dan **Pengaturan** (`/settings`, alamat email penerima, status pengiriman, email uji).
- Tes cakupan audit: 31 jenis aksi tulis tercatat; tes lama tidak lagi menghapus seluruh `activity_logs`.
- Detail, keputusan yang perlu konfirmasi, dan bukti uji: `docs/09-tahap-4g-notifikasi.md`.

## 2026-11-04 — Tahap 4f: Rekap & laporan Excel (selesai, 266 tes lulus)
- `ReportService` (filter bulan/tahun/rentang/semua, rekap BR-26, Laporan Stok BR-28, Transaksi Periode Q9), API `reports/summary` & `reports/export` (admin), halaman `/recap` + menu Rekap (sidebar & "Lainnya").
- Logika diambil langsung dari `getMonthlyStats`/`generateExcelReport` lama; tes paritas memakai port fungsi lama pada 53 kombinasi periode.
- Perbaikan F1 (filter memengaruhi Excel), F2 (Peminjam Terakhir hanya approved/returned), S7 (unduhan langsung, tanpa berkas publik); foto tersemat; teks ditulis sebagai string (anti formula injection); unduhan tercatat di audit log.
- Composer: `phpoffice/phpspreadsheet` 1.30.7 (platform PHP 8.0.30).
- Detail, keputusan yang perlu konfirmasi, dan bukti uji: `docs/08-tahap-4f-rekap.md`.

## 2026-11-04 — Tahap 4e: Tracker PQA (selesai, 254 tes lulus)
- `PqaService` (pasang, tambah waktu, request cabut, keputusan admin atas ketiganya, durasi hari penuh, aksi per peran), hook `PqaNotifier` (isi di 4g); API `pqa/*`; halaman `/pqa` (kartu + bilah progres + sheet) dan menu admin `/more`; navigasi dengan lencana PQA.
- **Koreksi katalog BR-24:** kode lama tidak melarang request cabut saat tambah waktu menunggu → mengikuti sistem lama; persetujuan cabut menutup otomatis permintaan tambah waktu yang menggantung.
- Riwayat kini menampilkan juga pemasangan yang `Ditolak`; `requested_by` dicatat; tracker dibagi bersama seluruh staff.
- Tes konkurensi lintas modul (pinjam biasa vs pasang PQA) memakai proses PHP paralel.
- Catatan: lencana navigasi dirender server → pembaruan otomatis menunggu `/sync` di Tahap 5.
- Detail, keputusan yang perlu konfirmasi, dan bukti uji: `docs/07-tahap-4e-pqa.md`.

## 2026-11-04 — Tahap 4d: peminjaman (selesai, 226 tes lulus)
- `LoanService` (ajukan multi-barang, setujui/tolak per pengajuan & per barang, batalkan, kembalikan, ganti unit), `AvailabilityService` (status barang dari loan+PQA, penguncian, nomor urut atomik), hook `LoanNotifier` (isi di 4g).
- API `requests`/`loans`/`items/available`; halaman beranda, `/requests` (Permohonan/Riwayat), `/requests/new`; navigasi per peran dengan lencana permohonan.
- **Deadlock ditemukan tes paralel & diperbaiki:** penghitung nomor urut tanpa `INSERT…SELECT`; isolasi `READ COMMITTED` (`DB_ISOLATION`); migrasi `0009` (baris `loan_seq`).
- Tes konkurensi memakai proses PHP paralel sungguhan (`tests/child_loan.php`).
- Detail, keputusan yang perlu konfirmasi, dan bukti uji: `docs/06-tahap-4d-peminjaman.md`.

## 2026-11-04 — Tahap 4c: inventaris barang (selesai, 188 tes lulus)
- Master barang: halaman `/items` (grid berfoto, cari, filter), API `items`/`categories`, tambah/ubah/nonaktifkan-aktifkan, kunci ID bila berriwayat, guard unit PQA.
- Foto: `ImageService` (validasi isi, batas piksel/memori, olah ulang GD, nama acak, thumbnail), penyaji `/media/items/{nama}` berotorisasi (ETag/304), `UPLOAD_DIR` dapat diatur, klien JS memperkecil foto sebelum kirim.
- Bug ditemukan & diperbaiki: `lock()` tanpa `category_id` (500 saat ubah); gagal baca berkas → 500 (kini 422); media 200 berbadan kosong saat berkas terkunci (kini 503); atribut `hidden` kalah oleh `display`; filter bertabrakan di lebar menengah.
- Detail & catatan tes sporadis di Windows: `docs/05-tahap-4c-inventaris.md`.

## 2026-11-04 — Tahap 4b: autentikasi & manajemen pengguna (selesai, 152 tes lulus)
- Login/logout (formulir + API), sesi terikat versi kredensial (`users.auth_version`, migrasi 0008), pembatasan percobaan login, wajib ganti password, ganti password, kelola pengguna (tambah/ubah/nonaktifkan/reset), audit log, change feed.
- Halaman: `/login`, `/profile`, `/`, `/users`; shell aplikasi (sidebar desktop + bottom-nav HP) dan JS tanpa skrip inline (`ui.js`, `api.js`, `login.js`, `profile.js`, `users.js`).
- `tools/create-admin.php` (tanpa password bawaan); tes end-to-end dengan server PHP + klien bercookie.
- Bug ditemukan tes & diperbaiki: input bertipe array menyebabkan 500 (kini 422); celah vertikal layout desktop.
- Detail: `docs/04-tahap-4b-auth-pengguna.md`.

## 2026-11-04 — Tahap 4a: fondasi backend (selesai, 90 tes lulus)
- Menambah `app/Core` (Env, Config, Database, Session, Csrf, Request, Response, Router, View, Validator, Logger, ErrorHandler, HttpException, Migrator), `app/Middleware` (Auth, Role, Csrf, ForcePasswordChange), `public/index.php` + `.htaccess`, `routes/`, view dasar.
- Menambah 7 migrasi SQL (16 tabel) + seed data acuan, `database/migrate.php`, `tools/check-env.php`, dan suite tes `tests/` (database tes terpisah).
- Bug ditemukan tes dan diperbaiki: (1) error parsing JSON pada request API tampil sebagai HTML → kini selalu JSON; (2) parameter integer dikirim sebagai string → kini di-bind bertipe (`LIMIT ?` bekerja, tanpa konversi implisit).
- Pengerasan: pengunjung anonim tidak dibuatkan sesi; CSRF otomatis untuk semua method penulisan; `LOG_DIR` dapat diatur.
- Detail: `docs/03-tahap-4a-fondasi.md`.

## 2026-11-04 — Tahap 3: UI/UX (menunggu review)
- Menambah `design/` : `css/tokens.css`, `css/components.css`, `js/ui.js`, tiga halaman acuan (`staff-home`, `admin-requests`, `components`).
- Menambah `docs/02-design-system.md`.
- Hasil uji tampilan: tidak ada overflow horizontal di 360 / 390 / 768 / 1024 px; tidak ada target sentuh < 44 px; mode terang dan gelap; sheet HP (dari bawah) dan desktop (panel kanan); tanpa error konsol.
- Koreksi spesifikasi: stepper peminjaman 3 tahap (lihat `02-design-system.md` §4).
- Perbaikan selama pengerjaan: grid dengan teks `nowrap` melebarkan halaman di HP → semua kolom grid memakai `minmax(0, 1fr)` dan item `min-width: 0`.

## 2026-11-04 — Tahap 1–2: audit & spesifikasi
- `docs/01-audit-dan-perencanaan.md` v1.1 FINAL (keputusan Q1–Q12 disetujui).
