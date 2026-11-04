# Tahap 4d — Peminjaman (modul inti)

Status: **selesai dan teruji** — 226 tes otomatis lulus (dijalankan berulang), termasuk 48 skenario berebut barang dengan proses PHP paralel, ditambah uji visual alur lengkap di browser.

## 1. Istilah
**Pengajuan (request)** = satu `loan_batches` berisi 1–30 **loan** (satu per barang). Kode tampilan sama dengan sistem lama: pengajuan `HME-<ms>`, barang `HME-<ms>-<n>`; nomor urut `seq_no` atomik. Status loan: `pending → approved → returned`, `pending → rejected`, `pending → cancelled`.

## 2. Aturan bisnis → di mana ditegakkan
| Aturan | Isi | Penegak |
|---|---|---|
| BR-02 | Punya loan *approved* → tidak boleh mengajukan (pending tidak memblokir) | `LoanService::submit` di bawah kunci baris user (409 `has_active_loan`) |
| BR-03 | Barang hanya bisa diajukan bila aktif, Tersedia, tanpa loan pending/approved lain | service (kunci `FOR UPDATE` per barang) **+ unique key `active_item_key` di DB** sebagai pengaman terakhir |
| BR-29 | Unit dengan PQA aktif tidak bisa dipinjam | cek `busy_pqa` + `AvailabilityService::sync` menghitung `master_status` dari loan **dan** PQA |
| BR-04/05 | 1–30 barang tanpa duplikat; lokasi wajib; tanggal wajib | validasi server (422 per field) |
| BR-06 | Nomor urut tak pernah ganda; snapshot nama peminjam & barang | penghitung atomik `settings.loan_seq` |
| BR-07 | Ganti unit selama pending (pemilik) | `swapItem` — unit baru wajib bebas |
| BR-08/09 | Hanya admin setujui/tolak; setujui → `Dipinjam`, tolak → barang tak berubah | route + **diulang di service** (`assertRole`) |
| BR-10 | Pemilik konfirmasi pengembalian (jam server) → status dihitung ulang | `returnLoan(s)` |
| Q5 | Pemilik boleh membatalkan yang masih pending | `cancelLoan(s)` |

Semua aksi ganda-tabel berjalan dalam **satu transaksi** dengan urutan kunci seragam: *user → barang (urut id) → loan* (mencegah deadlock). Setiap aksi menulis `activity_logs` dan `change_events` (untuk realtime di Tahap 5).

## 3. Endpoint
| Method & path | Akses |
|---|---|
| `GET /api/v1/items/available` | login (daftar barang yang bisa diajukan sekarang) |
| `GET /api/v1/loans/summary` · `GET /api/v1/requests?status=&q=&user_id=&limit=&offset=` · `GET /api/v1/requests/{id}` | login — **staff hanya miliknya** (dipaksa server; milik orang lain = 404) |
| `POST /api/v1/requests` | staff |
| `POST /requests/{id}/cancel` · `/return` · `POST /loans/{id}/cancel` · `/return` · `/swap` | staff pemilik (selain pemilik = 404) |
| `POST /requests/{id}/approve` · `/reject` · `POST /loans/{id}/approve` · `/reject` | admin |

Halaman: `/` (beranda: ringkasan + daftar), `/requests` (admin: Permohonan; staff: Riwayat — filter status, cari, muat lebih banyak), `/requests/new` (staff: form pinjam). Daftar dan detail dirender klien dari API (`requests.js`, DOM + `textContent`, tanpa `innerHTML`); tombol mengikuti izin `can` dari server, namun server memeriksa ulang setiap aksi.

## 4. Konkurensi — temuan penting
Tes paralel (proses PHP sungguhan, dimulai pada detik yang sama) menemukan **deadlock database (error 1213)** saat 5 staff mengajukan barang berbeda bersamaan. Akar masalah & perbaikan:
1. `INSERT … SELECT MAX(seq_no) FROM loans` untuk penghitung nomor mengambil kunci baca pada tabel `loans` → berbenturan dengan transaksi lain yang menyisipkan loan. **Diganti:** baris penghitung dibuat oleh migrasi `0009`, dinaikkan dengan `UPDATE … LAST_INSERT_ID(value+1)`, dan pembacaan `MAX` hanya sebagai pengaman tanpa kunci.
2. Pada isolasi bawaan (REPEATABLE READ), pengecekan "barang sibuk" membaca snapshot lama dan bisa menyebabkan gap-lock. **Diubah:** koneksi memakai **READ COMMITTED** (`DB_ISOLATION`, dapat diganti ke `REPEATABLE READ` bila hosting mewajibkan). Tanpa gap-lock; unique key tetap menjadi pengaman terakhir.

Hasil: 12 run berturut-turut × 4 skenario paralel lulus — (a) 5 staff berebut 1 barang → tepat 1 menang, 4 mendapat 409; (b) 5 staff, 5 barang berbeda → semua berhasil dengan nomor urut unik berurutan; (c) admin menyetujui **saat** staff membatalkan → tepat satu menang dan `master_status` konsisten; (d) dua staff mengganti unit ke barang yang sama → tepat satu berhasil.

## 5. Bukti pengujian (226 tes; 38 baru di 4d)
- **Service (25):** semua aturan di §2; semua-atau-tidak-sama-sekali (pengajuan/persetujuan); keputusan per barang; privasi/IDOR pada daftar & detail; pencarian (wildcard literal, SQLi); ringkasan; izin aksi; 4 skenario paralel.
- **E2E lewat HTTP nyata (13):** anonim 401/redirect untuk 14 endpoint; **staff → aksi admin 403 dan admin → aksi staff 403 dengan CSRF benar tanpa mengubah data**; staff lain mendapat 404 pada 6 operasi terhadap milik korban (milik korban utuh); mass-assignment (`status`, `user_id`, `decided_by`, `seq_no`, … diabaikan); CSRF/Origin; akun wajib-ganti-password terkunci; alur lengkap dua staff + admin; halaman & lencana navigasi; parameter status tidak dapat menyuntikkan HTML.
- **Browser:** pengajuan lewat form (pilih barang, keranjang), approve dari beranda admin (statistik & lencana ikut berubah), konfirmasi pengembalian staff; lokasi/keterangan berisi `<img onerror>`/`<script>` tampil sebagai teks (tidak ada elemen/skrip yang tereksekusi).

## 6. Bug yang ditemukan dan diperbaiki
Deadlock penghitung (di atas); statistik beranda tidak ikut diperbarui setelah aksi; aksi cepat di kartu membuka sheet detail tanpa diminta; nama barang terpotong di sheet detail sempit.

## 7. Keputusan implementasi yang perlu persetujuan/pengecekan Anda
Diambil agar mengikuti perilaku lama, tetapi **tidak tertulis eksplisit** di kode Apps Script, jadi tolong konfirmasi:
1. **Hanya staff yang dapat mengajukan peminjaman** (admin tidak punya menu Pinjam di sistem lama). Admin tidak dapat mengajukan atau mengonfirmasi pengembalian atas nama staff.
2. **Pengembalian hanya dikonfirmasi pemilik** (Q4); tidak ada verifikasi admin.
3. **Batas teknis 30 barang per pengajuan** (data lama maksimal 7) dan tanggal pinjam dibatasi 2020-01-01 s.d. +1 tahun (menangkap salah ketik). Bukan aturan bisnis; mudah diubah.
4. Jam pada `borrowed_at` = **jam server** (sistem lama memakai jam perangkat staff). Tanggal tetap dipilih staff.
5. **Setujui/tolak seluruh pengajuan** bersifat semua-atau-tidak-sama-sekali; persetujuan per barang tetap tersedia di sheet detail (seperti sistem lama yang per baris).

## 8. Belum ada (tahap berikut)
Notifikasi in-app & email (hook `LoanNotifier` sudah dipanggil, isinya kosong → 4g, BR-12); Tracker PQA (4e); rekap & laporan (4f); realtime otomatis tanpa muat ulang (Tahap 5); `loan_seq` harus disetel ke `MAX(seq_no)` data lama saat migrasi (Tahap 6, sudah ada pengaman lompat).
