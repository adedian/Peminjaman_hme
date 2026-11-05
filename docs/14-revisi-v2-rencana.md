# Revisi V2 — Audit, Analisis Dampak & Rencana Implementasi

Status: **RENCANA — menunggu persetujuan. Belum ada kode atau struktur database yang diubah.**
Tanggal audit: 2026-11-05 · Dasar: kode di `main` (commit `dbb7397`), 314 tes lulus, database kerja `hme_peminjaman`.

## 1. Hasil audit: apa yang sudah ada vs permintaan revisi

| # | Permintaan revisi | Kondisi sekarang | Dampak |
|---|---|---|---|
| 1 | Dashboard | Beranda berisi 3 angka + daftar (`home.php`, `requests.js`). Tidak ada grafik. Realtime sudah ada (`hmeLive`). | Tambah endpoint statistik + grafik; susun ulang beranda |
| 2 | Jenis peminjaman & batas kembali | **Sistem lama tidak punya "jenis peminjaman"** (hanya Tim, Lokasi, Keterangan). **Tidak ada batas pengembalian sama sekali** (`loans` tidak punya kolom jatuh tempo). `borrowed_at` = tanggal pilihan + jam server saat *mengajukan*. | Kolom baru `due_at`; "jenis" butuh keputusan Anda (Q1) |
| 3 | Login & password | 15 akun staff hasil migrasi punya `must_change_password = 1` (dipaksa ganti sandi oleh `ForcePasswordChangeMiddleware`). Password lama **sudah** di-`password_hash` saat migrasi dari teks asli, jadi password lama tetap berlaku. | Matikan pemaksaan; set flag ke 0; pertahankan `password_needs_rehash` |
| 4 | Nomor WhatsApp di Master Pengguna | Tidak ada. | Kolom `users.whatsapp_number` + validasi + UI |
| 5 | Notifikasi WhatsApp peminjaman | Hanya in-app + email (`LoanNotifier`, `MailService` dengan outbox + retry). Pola ini bisa dipakai ulang. | Layanan WhatsApp baru, antrean `wa_outbox` |
| 6 | Modul pemasangan PQA | Ada alur Pending → Terpasang → Req Cabut → Dicabut; target hari, tambah waktu, tim, lokasi. `installed_at` diisi **saat admin menyetujui**, bukan input pemohon. Tidak ada jadwal cabut tersimpan (dihitung dari `target_days`). Riwayat durasi hanya via `pqa_requests`. | Kolom jadwal, riwayat perubahan durasi |
| 7 | Pencabutan: kembali ke kantor / pindah site | Hanya "Dicabut → unit Tersedia". Tidak ada tujuan pindah site, tidak ada histori lokasi. | Tabel histori lokasi + tautan pemasangan berikutnya |
| 8 | Realtime & penjadwalan | Realtime siap (`/api/v1/sync`). Cron ada untuk backup/maintenance/kirim email, **belum ada penjadwal pengingat**. Zona waktu `Asia/Jakarta` sudah di config & sesi DB. | `tools/scheduler.php` + tabel log |
| 9 | Redesign UI | Design system sendiri (`tokens.css`, `components.css`), sudah mobile-first. CSP `script-src 'self'` → **tidak boleh CDN**. | Grafik dibuat sendiri (SVG) tanpa library |

## 2. Konflik dengan aturan bisnis lama (perlu keputusan sebelum coding)
- **BR-10 / Q4:** di sistem lama pengembalian dikonfirmasi **staff pemilik** tanpa admin. Revisi bagian 5 menyatakan "setelah pengembalian **dikonfirmasi admin**, status Dikembalikan". Dua hal ini bertentangan → Q3.
- **BR-02:** staff yang masih punya pinjaman aktif tidak boleh mengajukan baru. Tetap berlaku kecuali Anda ubah (tidak saya ubah).
- **Pinjaman aktif lama (2 baris `approved`) tidak punya batas kembali.** Usulan: dibiarkan kosong ("tanpa batas") dan **tidak** dihitung terlambat, agar tidak muncul peringatan palsu.
- **PQA lama (5 baris, semua Dicabut):** tetap utuh; jadwal cabut hanya untuk pemasangan baru.

## 3. Pertanyaan yang harus dijawab (tidak saya karang)
- **Q1 Jenis peminjaman.** Sistem lama tidak punya. Pilihan apa yang Anda inginkan? (Kategori barang yang sudah ada — 6 kategori — adalah atribut *barang*, bukan jenis peminjaman.) Bila memang tidak ada, bagian "pilihan jenis" saya lewati dan yang ditambahkan hanya batas pengembalian.
- **Q2 Batas pengembalian.** Wajib diisi pemohon saat mengajukan? Ada batas maksimum (mis. 30 hari)? Pengingat "mendekati jatuh tempo" berapa jam sebelumnya (usul: 24 jam)?
- **Q3 Siapa yang mengonfirmasi pengembalian?** (a) tetap staff seperti sistem lama, admin hanya diberi tahu; (b) staff menyatakan kembali → admin konfirmasi → baru "Dikembalikan" (menambah status "Menunggu konfirmasi").
- **Q4 Hitungan hari PQA.** Contoh Anda: pasang hari ke-1, pengingat hari ke-6, batas hari ke-7 (7 hari). Rumus lama: `durasi = floor((cabut − pasang)/24 jam)` → batas = waktu pasang + 7×24 jam, pengingat = batas − 24 jam. Apakah ini yang dimaksud, atau batas = pasang + 6×24 jam (hari ke-1 dihitung)?
- **Q5 Waktu pemasangan PQA.** Tanggal-jam pemasangan diisi pemohon (rencana) lalu dikonfirmasi admin, atau tetap dicatat saat admin menyetujui seperti sekarang? Usul: pemohon mengisi rencana; jam aktual = saat admin mengonfirmasi, jadwal cabut dihitung dari jam aktual.
- **Q6 Pencabutan.** Tujuan akhir dipilih pemohon saat mengajukan cabut dan dikonfirmasi admin (usul), atau admin memilih saat konfirmasi? Site tujuan: input bebas atau daftar site?
- **Q7 Provider WhatsApp.** Satu-satunya jalur resmi tanpa risiko blokir adalah **WhatsApp Cloud API (Meta)** atau BSP resmi. Syaratnya (tidak bisa saya buat dari sini): akun Meta Business terverifikasi, **nomor pengirim khusus** untuk bisnis, Phone Number ID, access token permanen, dan **template pesan yang disetujui Meta** (pesan yang dimulai bisnis di luar jendela 24 jam wajib template). Apakah akun itu sudah ada, atau pakai BSP tertentu?
- **Q8 Staff tanpa nomor WhatsApp.** Usul: kolom opsional; staff tanpa nomor dilewati dan dicatat di log pengiriman (status "dilewati: nomor kosong").
- **Q9 Password hasil reset admin.** Usul: ikut tidak dipaksa ganti (konsisten dengan instruksi Anda).

## 4. Tentang "buatkan API WhatsApp" + nomor admin 085230365427
- Nomor admin akan disimpan sebagai `6285230365427` (format internasional) di pengaturan sistem, bukan di kode.
- Yang saya bangun: **lapisan integrasi** (`WhatsAppService`, driver `meta_cloud` dan driver `test`), antrean, retry, log, endpoint webhook status pengiriman, halaman pengaturan & log. Token/ID **tidak** dimasukkan ke database atau repo — hanya `.env`.
- Yang **tidak** bisa saya buat: akun Meta, token, dan persetujuan template. Sebelum itu ada, pengiriman aktual ditandai **"belum terverifikasi"**; driver `test` hanya menulis ke log dan **bukan** pengganti kirim sungguhan.
- Catatan: pada mode uji Meta, pesan hanya bisa dikirim ke nomor penerima yang sudah didaftarkan (maks. 5), jadi 085230365427 harus didaftarkan sebagai penerima uji.

## 5. Rancangan database (migrasi aditif; tidak ada DROP/hapus data)
Backup otomatis (`tools/backup.php`) dijalankan sebelum tiap migrasi. Semua migrasi hanya `ADD COLUMN` / `CREATE TABLE`.

| Migrasi | Isi |
|---|---|
| 0011 | `users.whatsapp_number VARCHAR(20) NULL`; `UPDATE users SET must_change_password = 0` |
| 0012 | `loans.due_at DATETIME NULL` (+ `loans.loan_type` bila Q1 ada jawaban); indeks `(status, due_at)`; status **Terlambat tidak disimpan** — diturunkan: `approved AND due_at < NOW()` (otomatis, tanpa cron) |
| 0013 | PQA: `planned_install_at`, `due_at` (jadwal cabut), `removal_destination ENUM('office','site')`, `destination_site`, `next_installation_id`, `previous_installation_id`; `pqa_requests` + `old_due_at`, `new_due_at`; tabel `pqa_location_history` (item, site, dari/sampai, installation_id, dikonfirmasi oleh) |
| 0014 | `wa_outbox` (`idempotency_key` UNIQUE, event, penerima, template, variabel, status pending/sent/failed/skipped/cancelled, attempts, next_attempt_at, response ringkas, `verified` flag), `wa_templates`, `scheduler_runs` (log tiap eksekusi cron) |

**Idempotency:** kunci `loan:{id}:due_soon:{due_at}`, `pqa:{id}:h1:{due_at}`, `pqa:{id}:overdue:{due_at}`. Perpanjangan mengubah `due_at` → kunci baru, dan pengingat lama yang belum terkirim otomatis `cancelled` (sesuai syarat "jangan kirim pengingat tanggal lama").

**Pengiriman:** WA hanya di-enqueue **setelah commit** (pola sama dengan `MailService::queue`), dikirim setelah respons atau oleh cron; kegagalan tidak membatalkan transaksi; retry bertahap (maks. 5, jeda 1/5/15/60/180 menit); kunci baris saat kirim agar tidak ganda.

## 6. Tahapan implementasi (satu modul, uji, lalu lanjut)
| Tahap | Isi | Bukti uji |
|---|---|---|
| R0 | Backup + runner migrasi, tes migrasi pada salinan data nyata | Jumlah baris sebelum = sesudah; `check-integrity` hijau |
| R1 | Login tanpa paksa ganti sandi; kompatibilitas hash lama + rehash transparan | Login seluruh 16 akun lama; tidak ada redirect paksa |
| R2 | Master Pengguna + WhatsApp (validasi/normalisasi, UI, audit) | Tes validasi nomor; E2E otorisasi |
| R3 | Peminjaman: batas kembali, waktu aktual, status Terlambat turunan, countdown | Tes batas/terlambat; konkurensi lama tetap hijau |
| R4 | PQA: jadwal, perpanjangan + riwayat, pencabutan kantor/pindah site, histori lokasi | Tes hari-6 dari 7, perpanjangan hitung ulang, 2 skenario cabut |
| R5 | WhatsApp: service, template, outbox, retry, webhook, halaman pengaturan & log | Tes retry, anti-ganda, mode uji; ditandai belum terverifikasi |
| R6 | Penjadwal `tools/scheduler.php` (cron 5 menit) + contoh crontab | Aplikasi tidak dibuka, jam dimajukan di DB uji → pengingat keluar sekali |
| R7 | Dashboard (kartu, grafik SVG tanpa library, aktivitas, PQA) + realtime | Dua akun, latensi ≤ 2 dtk |
| R8 | Redesign UI/UX premium: dashboard & form dulu → **Anda review** → terapkan ke halaman lain | Tangkapan layar 360/768/1280, tanpa overflow |
| R9 | Uji 14 skenario Anda, regresi 314 tes, dokumentasi, CHANGELOG | Laporan di `docs/test-reports/` |

Urutan R3–R6 saling bergantung (due_at → jadwal → WA); R7–R8 setelah data siap. Perkiraan tes baru: ±80–100.

## 7. Risiko
| Risiko | Mitigasi |
|---|---|
| Template Meta butuh persetujuan (hari) | Mulai ajukan template lebih awal; teks dirancang di R5 |
| Hosting cron belum ditentukan | Penjadwal juga bisa dipicu tiap permintaan (fallback ringan) — tetapi cron tetap disarankan |
| Dua pengiriman bersamaan (cron + web) | Kunci baris + UNIQUE idempotency |
| Perubahan status "Terlambat" memengaruhi Rekap/Excel | Rekap tidak diubah; Terlambat hanya tampilan |
| Redesign merusak 314 tes/regresi | Redesign hanya CSS/markup; suite penuh dijalankan tiap tahap |
| Data pribadi (nomor WA) | Disamarkan di log aplikasi; hanya admin yang melihat penuh |

## 8. Catatan proses
Analisis ini ditulis di sesi yang berjalan dengan Sonnet 5.5, bukan Opus 5.5 seperti instruksi Anda. Bila Anda ingin pengkajian ulang oleh Opus sebelum disetujui, rencana ini siap dijadikan masukannya.
