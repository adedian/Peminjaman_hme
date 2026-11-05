# Revisi V2 — Implementasi, keputusan, dan status verifikasi

> **PEMBARUAN (V2b):** bagian WhatsApp di dokumen ini **sudah digantikan** oleh PWA + notifikasi push dari aplikasi — lihat `docs/16-pwa-notifikasi-push.md`.
> Kode, UI, dan pengaturan WhatsApp telah dihapus; bagian WhatsApp di bawah dipertahankan hanya sebagai riwayat keputusan.

Rencana awal: `docs/14-revisi-v2-rencana.md`. Dokumen ini mencatat **apa yang benar-benar dibangun**, keputusan yang diambil
dari usulan yang Anda setujui, cara mengaktifkan WhatsApp, dan **apa yang BELUM terverifikasi**.

## 1. Ringkasan per modul
| Tahap | Hasil | Migrasi |
|---|---|---|
| R1 | Login tidak lagi memaksa ganti password (login pertama, akun baru, reset admin, migrasi, `create-admin`). Password lama dipertahankan. Format lama (teks biasa) tetap bisa login lalu di-hash otomatis tanpa meminta pengguna mengganti apa pun. | 0011 |
| R2 | Master Pengguna: nomor WhatsApp (dinormalisasi ke `62…`, divalidasi, tersamar di audit/log). | 0011 |
| R3 | Peminjaman: **batas pengembalian** (tanggal+jam) wajib saat mengajukan, maks. 30 hari (dapat diatur); status **Terlambat** diturunkan otomatis (`approved` + lewat batas); hitung mundur dari jam server; filter "Terlambat". | 0012 |
| R4 | PQA: tanggal/jam pemasangan + catatan; jadwal cabut = waktu pasang aktual + target × 24 jam (kolom turunan, tidak bisa basi); riwayat perubahan durasi (jadwal lama→baru); pencabutan **kembali ke kantor** atau **pindah site** (pemasangan berikutnya dibuat otomatis, terhubung, menunggu konfirmasi admin); histori lokasi tiap unit. | 0013 |
| R5 | WhatsApp: antrean `wa_outbox` (idempotensi UNIQUE), template yang bisa diedit, retry bertahap, log + kirim ulang, webhook status Meta (HMAC), halaman pengaturan. | 0014 |
| R6 | Penjadwal `tools/scheduler.php` (cron 5 menit): pengingat jatuh tempo/terlambat peminjaman, H-1/terlambat PQA, pembatalan pengingat basi, kirim antrean. | 0014 |
| R7 | Dashboard: KPI, perlu-perhatian, tren 14 hari, komposisi barang, jatuh tempo terdekat, PQA terpasang, barang terpopuler, aktivitas — semua dari database, realtime. Grafik SVG buatan sendiri (tanpa library; CSP tetap ketat). | – |
| R8 | Poles UI: pintasan batas waktu (Besok/3 hari/1 minggu/2 minggu), pilihan tujuan pencabutan berbentuk kartu, fokus & gerak halus (menghormati *reduced motion*). | – |

## 2. Keputusan (usulan yang Anda setujui)
- **Jenis peminjaman:** sistem lama tidak punya → **tidak ada kategori baru**. Yang ditambahkan hanya batas pengembalian.
- **Batas pengembalian:** wajib; maks. 30 hari (`Pengaturan`); pengingat 24 jam sebelumnya (dapat diatur). Untuk peminjaman lebih singkat dari jendela, pengingat dikirim di titik tengah (agar tidak langsung "mengingatkan" saat baru disetujui).
- **Pengembalian:** tetap dikonfirmasi **staff** (aturan lama); admin diberi tahu lewat WhatsApp. Status `Dikembalikan` = `returned`.
- **Hari PQA:** batas = waktu pasang aktual + N × 24 jam (rumus lama sistem); pengingat = batas − 24 jam. Contoh 7 hari: dipasang Senin 09.00 → pengingat Minggu 09.00 (6 × 24 jam setelah dipasang) → batas cabut Senin 09.00 minggu berikutnya. Jika hari pemasangan dihitung "hari ke-1", pengingat jatuh di hari ke-7 dan batas di awal hari ke-8. **Contoh di brief Anda (pengingat hari ke-6, batas hari ke-7) sebenarnya berarti batas = pasang + 6 × 24 jam** — bila itu yang Anda maksud, kabari saya; perubahannya satu rumus (`DATE_ADD(installed_at, INTERVAL target_days DAY)` → `target_days - 1`) beserta tes yang menyertainya.
- **Waktu pemasangan PQA:** pemohon mengisi rencana; jam aktual = saat admin mengonfirmasi (jadwal cabut dihitung dari jam aktual).
- **Pencabutan:** pemohon memilih tujuan (kantor / pindah site + target hari di site baru); admin mengonfirmasi. Site tujuan = input bebas.
- **Staff tanpa nomor WhatsApp:** pesan tercatat `dilewati` + alasan, bisa dikirim ulang setelah nomor diisi.
- **Password reset admin:** tidak dipaksa ganti.
- **Pinjaman lama (tanpa batas):** `due_at = NULL` → tidak pernah dihitung terlambat dan tidak diberi pengingat.

## 3. Cara mengaktifkan WhatsApp (WAJIB dilakukan Anda — saya tidak bisa membuat akun/token Meta)
1. Buat/siapkan **Meta Business** terverifikasi dan aplikasi *WhatsApp Business Platform* (Cloud API) di developers.facebook.com.
2. Siapkan **nomor pengirim khusus** (bukan nomor pribadi yang aktif di aplikasi WhatsApp). Nomor admin **085230365427** adalah **penerima** — di mode uji Meta, daftarkan sebagai penerima uji (maks. 5).
3. Ambil `Phone number ID` dan buat **token permanen** (System User). Isi di `.env`:
   ```
   WA_DRIVER=meta_cloud
   WA_PHONE_NUMBER_ID=...
   WA_ACCESS_TOKEN=...
   WA_APP_SECRET=...        # untuk memverifikasi webhook
   WA_VERIFY_TOKEN=...      # bebas, harus sama dengan yang diisi di Meta
   ```
4. Di Meta, daftarkan **template pesan** (kategori *utility*). Isi tiap template ada di **Pengaturan → Template pesan**, lengkap dengan bentuk `{{1}} {{2}}…` yang harus didaftarkan. Setelah disetujui Meta, isi **nama template Meta** pada kolom yang tersedia. (Pesan yang dimulai bisnis di luar jendela 24 jam *wajib* template.)
5. Webhook (opsional, untuk status terkirim/dibaca): URL `https://DOMAIN/api/v1/webhooks/whatsapp`, verify token = `WA_VERIFY_TOKEN`.
6. Pasang cron (`deploy/crontab.example`): `*/5 * * * * php tools/scheduler.php`.
7. **Pengaturan → Kirim pesan uji**. Hanya pengiriman nyata yang diterima Meta yang menandai gateway **Terverifikasi**.

Selama langkah 3–7 belum dilakukan: `WA_DRIVER=none` → pesan **menunggu di antrean** (maks. 48 jam), tidak hilang dan tidak berpura-pura terkirim. `WA_DRIVER=test` hanya menulis ke `storage/logs/wa.log` dan **tidak** dihitung terverifikasi.

## 4. API baru
| Endpoint | Akses | Fungsi |
|---|---|---|
| `GET /api/v1/dashboard` | login | data dashboard (disaring per peran) |
| `GET/POST /api/v1/webhooks/whatsapp` | publik, HMAC | jabat tangan & status pengiriman Meta |
| `GET /api/v1/wa/status` · `GET/PUT /wa/templates[/{event}]` · `GET /wa/log` · `POST /wa/log/{id}/retry` · `POST /wa/test` | admin | pengelolaan WhatsApp |
| `PUT /api/v1/settings` | admin | + `admin_whatsapp_numbers`, `wa_enabled`, `loan_reminder_hours`, `pqa_reminder_hours`, `loan_max_days` |
| `POST /api/v1/requests` | staff | + `due_at` (wajib) |
| `POST /api/v1/pqa` · `POST /pqa/{id}/remove` | staff | + `planned_install_at`, `notes` · + `destination`, `destination_site`, `move_target_days` |

Nomor admin **6285230365427** sudah diisi sebagai nilai awal pengaturan (`admin_whatsapp_numbers`).

## 5. Status verifikasi — jujur
| Hal | Status |
|---|---|
| Logika antrean, retry, anti-ganda, pengingat basi, penjadwal, webhook, otorisasi | **Teruji otomatis** (transport HTTP diganti penampung; tidak menembak Meta) |
| Cron nyata (proses CLI tanpa aplikasi dibuka) | **Teruji** (mode `test`) |
| **Pengiriman nyata ke WhatsApp lewat Meta** | **BELUM terverifikasi** — butuh kredensial & template yang disetujui Meta |
| Format payload template Meta | Mengikuti dokumentasi Cloud API; **belum dibuktikan** terhadap server Meta |
| Tampilan HP/tablet/desktop | Diukur lewat DOM (tanpa overflow horizontal, target sentuh ≥ 36–44 px, 390/768/1280 px, mode gelap). **Belum ada tinjauan visual manusia / tangkapan layar** (panel browser tersembunyi saat pengujian) — mohon Anda tinjau tampilannya |
| Akun admin `admin` lama | Lihat §6 |

## 6. Temuan yang perlu keputusan Anda
- **Akun `admin` lama:** di database kerja username `admin` sudah dipakai akun admin yang Anda buat sendiri (password berbeda), sehingga password `admin` dari sistem lama **tidak berlaku** di database kerja ini. 15 akun staff lain: password lama cocok semua dan tidak dipaksa ganti. Pada cut-over ke database kosong, importer akan membuat `admin` lama dengan password lamanya. Bila ingin `admin` di database kerja memakai password lama sekarang, itu perubahan password yang harus Anda setujui.
- **Penjadwal tanpa cron:** bila hosting tidak menyediakan cron, pengingat tidak akan jalan. `SchedulerService::tickIfStale()` tersedia sebagai cadangan, tetapi belum dipasang ke permintaan web karena pengingat "tanpa browser" adalah syarat Anda.

## 7. Pemetaan 14 kriteria uji
1. Login seluruh akun lama tanpa ganti password → `tests/cases/revisi_v2.php`, verifikasi terhadap ekspor lama (15/15 staff).
2. Peminjaman baru + WA admin → `revisi_v2_wa.php`, `e2e_v2.php`.
3. Pengembalian + status admin → `revisi_v2_wa.php`.
4. Pengingat H-1 & terlambat → `revisi_v2_sched.php`.
5. Pemasangan PQA + WA admin → `revisi_v2_wa.php`.
6. Hari-6 untuk 7 hari → `revisi_v2_sched.php`.
7. Perpanjangan & hitung ulang H-1 → `revisi_v2_sched.php`, `revisi_v2_pqa.php`.
8. Cabut ke kantor → `revisi_v2_pqa.php`.
9. Cabut pindah site → `revisi_v2_pqa.php`.
10. Cron tanpa aplikasi dibuka → `revisi_v2_sched.php` (CLI nyata).
11. Realtime dua akun → `e2e_v2_dash.php`.
12. Tampilan HP/tablet/desktop → pengukuran DOM (§5).
13. Log, retry, anti-ganda WA → `revisi_v2_wa.php`.
14. Konsistensi inventaris & histori → `revisi_v2_pqa.php`, `tools/check-integrity.php` (15 aturan; 2 baru).
