# Tahap 4g — Audit log, notifikasi, email

Status: **selesai dan teruji** — 284 tes otomatis lulus (dijalankan dua kali berurutan pada database tes bersih; 18 baru di 4g), ditambah uji visual di browser (HP 390 px; data tes). Pengiriman lewat **SMTP sungguhan belum diuji** karena akun SMTP belum tersedia (lihat §6).

## 1. Sumber kebenaran
Isi email meniru kode lama (`sendHmeEmail`, `saveMultipleLoans`, `saveLoan` di `Code.gs`): subjek `[INVENTORY HME] Permohonan Baru` / `[INVENTORY HME] Barang Kembali`, bingkai HTML yang sama, isi "Ada pengajuan dari **X**: daftar (nama + ID) · Lokasi" dan "**X** telah mengembalikan **barang**.". Sistem lama tidak mengirim email untuk keputusan admin maupun aksi PQA — tetap demikian.

## 2. Aturan → di mana ditegakkan
| Aturan | Isi | Penegak |
|---|---|---|
| BR-12 / Q8 | Email ke alamat admin (dapat diatur, beberapa alamat dipisah koma) untuk **pengajuan baru** dan **pengembalian** | `LoanNotifier` → `MailService::queue`; alamat dari `settings.admin_notification_email` (halaman **Pengaturan**) |
| Q8 | SMTP di `.env`; bila belum dikonfigurasi email **dilewati & dicatat di log**, transaksi tetap jalan | `MailService` driver `none` → status `skipped` |
| Q8 / §7 | Notifikasi in-app: admin ← pengajuan baru, pengembalian, pembatalan (Q5), permintaan PQA; staff ← disetujui/ditolak, keputusan PQA | `LoanNotifier`, `PqaNotifier`, `NotificationService` |
| F8 / 4g | Setiap aksi tulis tercatat (siapa, kapan, IP, perangkat, sebelum/sesudah; rahasia disamarkan) | `AuditService` (sudah dipanggil semua service) + halaman **Log Aktivitas** |

## 3. Desain
- **Email lewat antrean (`mail_outbox`, migrasi `0010`).** Notifier dipanggil *setelah* transaksi dikomit dan hanya menulis antrean. Front controller mengirim respons ke browser dulu (`Content-Length` + flush, atau `fastcgi_finish_request` bila ada), baru mengirim email — pengguna tidak menunggu SMTP. Gagal kirim → dicoba ulang (jeda 5, 10, 15, 20 menit), setelah 5 kali `failed`. Klaim baris dengan `UPDATE` bersyarat sehingga dua proses tidak mengirim email yang sama. `php tools/send-mail.php` untuk percobaan ulang via cron (opsional).
- **Isi email di-escape** (nama barang, nama peminjam, lokasi). Versi teks polos ikut dikirim.
- **Notifikasi in-app** (`notifications`): pelaku tidak menerima notifikasinya sendiri; admin nonaktif tidak menerima; setiap notifikasi juga menulis `change_events` (`user_id` = penerima) untuk lonceng realtime di Tahap 5. Kegagalan notifikasi tidak pernah menggagalkan transaksi (diuji dengan tabel notifikasi yang dihilangkan sementara).
- **PQA (tracker bersama):** keputusan dikirim ke pengaju pemasangan **dan** staff yang mengajukan permintaan tambah waktu/cabut tersebut (bisa orang berbeda).
- **Tautan notifikasi** `/requests?open=<id>` dan `/pqa?open=<id>` langsung membuka detailnya.
- **Credential SMTP tidak bisa diubah dari web** — hanya `.env` (`MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; `MAIL_DRIVER=log` untuk development). Password tidak pernah ditulis ke log/antrean.
- Composer: `phpmailer/phpmailer` 6.12.0.

## 4. Endpoint & halaman
| Method & path | Akses |
|---|---|
| `GET /api/v1/notifications?unread=1&limit=&offset=` · `GET /notifications/count` · `POST /notifications/{id}/read` · `POST /notifications/read-all` | login (milik sendiri; milik orang lain → 404) |
| `GET /api/v1/logs?group=&user_id=&q=&from=&to=&limit=&offset=` | admin |
| `GET /api/v1/settings` · `PUT /api/v1/settings` (hanya `admin_notification_email`) · `POST /api/v1/settings/test-email` | admin |
| `/notifications` (semua) · `/logs` · `/settings` (admin; sidebar & menu "Lainnya") | — |

Lonceng di bilah atas semua halaman menampilkan jumlah belum dibaca (dirender server; diperbarui otomatis di Tahap 5).

## 5. Bukti pengujian (18 tes baru)
- **Service (13 di `tests/cases/notify.php`):** daftar penerima; pengajuan baru → notifikasi semua admin aktif + email ber-escape (`<script>`, `<b>`, `&`) + terkirim sekali setelah `drain`; keputusan sebagian hanya menyebut barang yang diputuskan, tanpa email; pengembalian sebagian → email hanya barang yang dikembalikan; pembatalan → in-app tanpa email; SMTP kosong → `skipped`, transaksi berhasil; alamat kosong → tanpa antrean; **SMTP gagal sungguhan** (port tertutup) → dijadwal ulang, password tidak bocor, `failed` setelah 5 kali, baris yang diklaim tidak dikirim ganda; tabel notifikasi hilang → transaksi tetap berhasil; seluruh alur PQA (penerima tepat, tanpa email); daftar/paginasi/filter/tandai dibaca & IDOR; pengaturan (normalisasi, 9 input salah → 422, audit sebelum/sesudah); email uji (log & SMTP kosong → 502); filter log (kelompok, pelaku, kata kunci literal `%_%`, tanggal inklusif, 6 filter salah → 422, tidak ada password di log); **cakupan audit: 31 jenis aksi tulis semuanya tercatat** dengan pelaku & IP.
- **E2E lewat HTTP nyata (5 di `tests/cases/e2e_notify.php`):** anonim 401/redirect; staff 403 untuk log & pengaturan; mass-assignment pengaturan (`loan_seq`, `mail_password`) diabaikan; CSRF wajib untuk `PUT`; IDOR notifikasi (404, tetap belum dibaca, read-all hanya milik sendiri); alur lengkap: staff mengajukan → **respons kembali sebelum email dikirim**, email muncul di `mail.log` sesudahnya, lonceng admin "1", staff menerima "disetujui", tandai dibaca, pengembalian → email "Barang Kembali"; log memuat login HTTP dengan IP & perangkat; halaman tanpa skrip inline.
- **Browser (HP 390 px):** lonceng berangka; daftar notifikasi (belum dibaca ditandai garis & titik oranye; teks `<i>` tampil sebagai teks); log dengan rincian sebelum/sesudah; pengaturan & status pengiriman; tautan `?open=` membuka detail pengajuan dan PQA; tanpa scroll horizontal.
- Tes lama yang menghapus seluruh `activity_logs` diubah agar hanya memeriksa log baru (diperlukan oleh tes cakupan audit).

## 6. Keputusan implementasi yang perlu pengecekan Anda
1. **Satu email per aksi pengembalian.** Sistem lama mengirim satu email per barang (pengembalian dilakukan per baris). Kini satu aksi "kembalikan" = satu email berisi semua barang yang dikembalikan saat itu. Isi dan subjek sama.
2. **Notifikasi tambahan di aplikasi** (tanpa email): pembatalan pengajuan (Q5) dan permintaan PQA ke admin; keputusan peminjaman & PQA ke staff — sesuai §7 butir 7 rencana.
3. **Akun SMTP masih ditunggu.** Hingga diisi di `.env`, email ditandai "dilewati" (terlihat di halaman Pengaturan). Setelah diisi, gunakan tombol **Kirim email uji**. Untuk Gmail perlu *App Password*.
4. Riwayat notifikasi dan antrean email belum dibersihkan otomatis (volume kecil); bisa ditambahkan di Tahap 8 bersama prosedur backup.

## 7. Belum ada (tahap berikut)
- Lonceng & lencana diperbarui otomatis tanpa muat ulang (`/sync`, Tahap 5).
- Uji kirim lewat SMTP sungguhan setelah akun tersedia (Tahap 8).
- Migrasi `0010` sudah diterapkan ke database kerja (bersama `0009` yang sebelumnya belum diterapkan di sana).
