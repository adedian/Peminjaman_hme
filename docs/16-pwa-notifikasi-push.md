# PWA & notifikasi dari aplikasi (menggantikan WhatsApp)

Permintaan: **jangan lewat WhatsApp** — notifikasi harus muncul dari aplikasi ini di layar HP, sistem dijadikan **PWA**, dan logo
"HME" diganti menjadi **"Peminjaman HME"** (logo diperbaiki). Dokumen ini mencatat apa yang dibangun, cara mengaktifkannya, dan apa
yang **belum bisa saya buktikan**.

## 1. Cara kerja
```
kejadian (pinjam, setujui, kembali, PQA, jadwal) ─► NotificationService::notify()
        ├─► lonceng di aplikasi (selalu)
        └─► push_outbox ─► dikirim setelah respons / oleh cron ─► layanan push browser (FCM / Mozilla / Apple)
                                                                  └─► Service Worker (sw.js) ─► notifikasi di layar HP
```
- **PWA**: `manifest.webmanifest` (nama "Peminjaman HME", mode *standalone*, ikon 192/512/maskable, pintasan Pinjam/PQA/Notifikasi),
  `sw.js` (push + cache aset statis + halaman offline), `pwa.js` (pendaftaran SW & tombol "Pasang aplikasi").
- **Web Push standar** (RFC 8030/8291/8292, VAPID) — **bukan** layanan pihak ketiga berbayar, tanpa akun Meta/WhatsApp. Enkripsi dan
  tanda tangan dibuat sendiri dengan `openssl` PHP (`app/Services/WebPush.php`), dibuktikan dengan **vektor uji resmi RFC 8291**.
- Notifikasi **tidak ganda**: kunci idempotensi per penerima (`notifications.dedupe_key`) + `UNIQUE(notifikasi, perangkat)`.
- **Hanya setelah transaksi tersimpan**: notifikasi dibuat oleh hook pasca-komit; kegagalan push tidak pernah menggagalkan transaksi.
- **Retry**: 1, 5, 15, 60, 180 menit (maks. 5); `404/410` → perangkat dinonaktifkan; `429 Retry-After` dihormati.
- **Pengingat basi dibatalkan**: bila jadwal diperpanjang / barang sudah dikembalikan sebelum push terkirim.
- **Penjadwal** `tools/scheduler.php` (cron 5 menit) tetap menyusun pengingat jatuh tempo/terlambat (peminjaman) dan H-1/terlambat (PQA)
  walau tidak ada yang membuka aplikasi.
- Saat aplikasi sedang dilihat, SW tidak memunculkan notifikasi sistem (toast & lonceng realtime sudah cukup) → tidak dobel.

## 2. Siapa menerima apa
| Kejadian | Penerima |
|---|---|
| Peminjaman baru | semua admin |
| Barang dipinjam (disetujui) | admin lain (+ peminjam: "disetujui/ditolak") |
| Mendekati jatuh tempo (H-1) / melewati batas | peminjam |
| Barang dikembalikan | semua admin |
| Pengajuan PQA baru (juga pemasangan di site tujuan) | semua admin |
| Pemasangan dikonfirmasi / perpanjangan / pencabutan | pemohon + admin lain (isi memuat jadwal cabut baru / tujuan) |
| H-1 pencabutan PQA / melewati jadwal | semua admin + pemohon |

## 3. Mengaktifkan (wajib dilakukan di server Anda)
1. **HTTPS** di server produksi (syarat browser untuk service worker & push; `localhost` dikecualikan saat pengembangan).
2. Buat kunci VAPID sekali: `php tools/generate-vapid.php --write` (menulis `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` ke `.env`);
   ganti `VAPID_SUBJECT` dengan email kontak Anda. **Jangan ganti kunci setelah dipakai** — semua perangkat harus berlangganan ulang.
3. Pasang cron: `*/5 * * * * php tools/scheduler.php` (`deploy/crontab.example`).
4. Tiap pengguna: buka **Profil → Notifikasi di HP → Aktifkan di perangkat ini**, izinkan notifikasi, lalu **Kirim notifikasi uji**.
   - **Android/Chrome**: bisa langsung; disarankan *Pasang aplikasi* (kartu di Profil) agar terasa seperti aplikasi.
   - **iPhone/iPad (iOS 16.4+)**: wajib *Bagikan → Tambah ke Layar Utama*, buka dari ikon, baru aktifkan notifikasi.
5. Admin: **Pengaturan → Notifikasi HP & pengingat** (status server, jumlah perangkat, log pengiriman + kirim ulang, jendela pengingat).

Tanpa kunci VAPID aplikasi tetap berjalan normal: notifikasi tampil di lonceng, push menunggu di antrean (maks. 48 jam).

## 4. Keamanan
- **Anti-SSRF**: server hanya mau mengirim ke endpoint dari layanan push resmi (`fcm.googleapis.com`, `*.push.services.mozilla.com`,
  `*.push.apple.com`, `*.notify.windows.com`); HTTPS:443, bukan IP, tanpa pengalihan (redirect), tanpa userinfo.
- Pengguna hanya mengelola **perangkatnya sendiri**; daftar perangkat tidak memuat endpoint/kunci. Maks. 10 perangkat per pengguna.
- **Keluar (logout)** melepas langganan perangkat itu lebih dulu, sehingga HP yang dipakai bergantian tidak menampilkan notifikasi akun lama.
  Bila akun lain masuk di perangkat yang sama dan mengaktifkan notifikasi, langganan berpindah ke akun itu.
- Kunci privat VAPID hanya di `.env` dan tidak pernah keluar lewat API (diuji).
- Service worker **tidak pernah** menyimpan HTML aplikasi, `/api/*`, atau `/media/*` (tidak ada data pengguna di cache perangkat) dan hanya
  membuka URL satu-asal dari notifikasi.

## 5. Logo
`logo-hme.png` lama punya tepi kosong tak simetris dan hanya bertuliskan "HME". Kini:
- `logo-mark.png`: heksagon HME dipangkas rapat (transparan) — dipakai di header, sidebar, halaman masuk, halaman offline.
- Tulisan **"Peminjaman HME"** ditampilkan bersama tanda (login, sidebar, header ponsel).
- Ikon aplikasi (`icon-192/512`, `icon-maskable-512`, `apple-touch-icon`, `favicon-32`, `badge-96`) memuat heksagon + tulisan **PEMINJAMAN**.
  Dibuat ulang dengan `php tools/make-icons.php --font=<ttf tebal>`.

## 6. Yang DIHAPUS / DIBIARKAN
- Dihapus: seluruh kode WhatsApp (`WhatsAppService`, `WaNotifier`, webhook, UI pengaturan WA, kolom nomor WhatsApp di Master Pengguna, `.env` `WA_*`).
- Dibiarkan (tidak dipakai, tanpa data nyata): tabel `wa_outbox`, `wa_templates`, kolom `users.whatsapp_number`, setelan `admin_whatsapp_numbers`.
  Aman dibuang dengan migrasi terpisah bila Anda mau.
- **Template pesan yang dapat diedit** (permintaan awal untuk WhatsApp) tidak dibawa ke push: teks notifikasi kini didefinisikan di kode.

## 7. Status verifikasi — jujur
| Hal | Status |
|---|---|
| Enkripsi RFC 8291 | **Teruji**: keluaran identik dengan vektor resmi + 30 putaran enkripsi/dekripsi acak |
| VAPID ES256 | **Teruji**: 60 putaran tanda tangan/verifikasi, klaim `aud/exp/sub` |
| Antrean, retry, anti-ganda, pengingat basi, batas perangkat, anti-SSRF, API & otorisasi | **Teruji otomatis** (transport HTTP palsu) |
| Logika service worker (push, klik, fetch, cache) | **Teruji di Node** dengan lingkungan SW tiruan |
| Berkas PWA (manifes, ikon, sw.js, offline) | **Teruji lewat HTTP** (tipe, ukuran ikon, isi, header) |
| Cron tanpa aplikasi dibuka | **Teruji** (CLI nyata) |
| **Pendaftaran SW & push sungguhan di browser/HP** | **BELUM terverifikasi** — peramban panel tempat saya menguji tidak mendukung service worker (bahkan skrip statis biasa gagal didaftarkan). Perlu dicoba di Chrome/HP Anda dengan HTTPS |
| **Notifikasi benar-benar muncul di HP** | **BELUM terverifikasi** (butuh perangkat, izin pengguna, dan layanan push sungguhan) |
| Tampilan logo/ikon | Gambar dibuat dan dilihat langsung; tinjauan estetika oleh Anda tetap diperlukan |
