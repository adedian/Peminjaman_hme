# Tahap 5 — Frontend realtime

Status: **selesai dan teruji** — 291 tes otomatis lulus (dijalankan dua kali berurutan pada database tes bersih; 7 baru di Tahap 5), ditambah **uji dua akun** di browser: latensi ≤ 2 detik dan isian form tidak ter-reset (§4).

Catatan cakupan: halaman-halaman frontend sudah dibangun bertahap di 4b–4g. Tahap 5 menambahkan lapisan realtime ke semua halaman tersebut.

## 1. Rancangan (sesuai §7 rencana)
| Butir rencana | Implementasi |
|---|---|
| Change-feed di transaksi yang sama | `change_events` (sudah diisi semua service sejak 4b) |
| `GET /api/v1/sync?since=<id>` → 204 / delta | `SyncController` + `SyncService`; sesi dilepas sebelum query; satu query rentang PK |
| Interval adaptif | 1,5 dtk aktif (`REALTIME_INTERVAL_MS`) → 5 dtk bila tidak ada interaksi > 1 menit → 20 dtk bila semua tab tersembunyi; sync seketika saat tab terlihat lagi; backoff s.d. 30 dtk saat jaringan gagal |
| Satu poller per browser | Web Locks (`hme-sync-leader`) memilih tab pemimpin; hasil dibagikan lewat `BroadcastChannel`; bila tab pemimpin ditutup, tab lain otomatis mengambil alih. Browser tanpa dukungan: tiap tab polling sendiri |
| SSE opsional | **Belum** — polling sudah memenuhi target latensi dan aman untuk shared hosting. `REALTIME_MODE=sse` dapat ditambahkan di Tahap 8 bila hosting mendukung |
| Input pengguna aman | Hanya daftar, kartu, statistik, lencana, lonceng yang diperbarui. Formulir & isian tidak pernah dirender ulang. Barang di keranjang yang diambil orang lain → peringatan inline |
| Toast + lonceng | Notifikasi baru muncul sebagai toast di tab yang terlihat; lonceng & lencana Permohonan/PQA diperbarui di semua halaman |

**Keamanan & privasi delta.** `/sync` hanya mengirim *jenis + ID* entitas yang berubah — datanya dimuat ulang lewat API biasa yang sudah menegakkan hak akses. Delta disaring per peran: staff hanya menerima ID pengajuan **miliknya**, notifikasinya sendiri, dan akunnya sendiri; ketersediaan barang dan tracker PQA dibagi bersama (memang terlihat semua staff). Akun dinonaktifkan / password direset di perangkat lain → polling berikutnya 401 → kembali ke halaman login. Akun wajib-ganti-password tidak menjalankan polling.

**Sesi.** Polling **tidak** memperpanjang timeout idle (`AuthMiddleware` → `Session::start(touch: false)` khusus `/api/v1/sync`), sehingga tab yang dibiarkan terbuka tetap keluar otomatis sesuai `SESSION_IDLE_MINUTES`.

**Batas & pemulihan.** Lebih dari 500 perubahan sejak kursor, atau kursor lebih besar dari data (database dipulihkan) → `reset: true`, klien memuat ulang semua yang tampil. Feed lebih tua dari 2 hari dibersihkan otomatis (±1 dari 200 polling).

## 2. Apa yang diperbarui di tiap halaman
| Halaman | Pemicu | Perilaku |
|---|---|---|
| Semua | notifikasi, pengajuan, PQA | lonceng, lencana Permohonan & PQA, toast |
| Beranda, Permohonan/Riwayat | pengajuan | daftar, feed, statistik; detail yang terbuka diperbarui **tanpa membuka ulang sheet / memindah fokus**, dilewati saat aksi sedang berjalan |
| Pinjam Barang (staff) | barang, pengajuan | daftar barang tersedia; pilihan yang tidak lagi tersedia dihapus + peringatan "… baru saja tidak tersedia"; lokasi, tim, keterangan, pencarian, dan fokus tetap |
| Tracker PQA | PQA, barang | daftar; detail terbuka; daftar unit di formulir pasang baru (pilihan dipertahankan, atau peringatan bila unitnya diambil orang lain) |
| Master Barang, Pengguna (admin) | barang / akun | kartu/daftar diganti versi terbaru dari server, filter & pencarian diterapkan ulang; sheet formulir tidak disentuh |
| Rekap (admin) | pengajuan | angka periode yang **sedang tampil** dihitung ulang diam-diam (isian filter yang belum diterapkan tidak dipakai) |
| Notifikasi | notifikasi | halaman pertama dimuat ulang |

## 3. Bukti pengujian otomatis (7 tes baru)
- **Service (4 di `tests/cases/sync.php`):** tanpa perubahan → 204; delta per jenis, ID unik, kursor maju, tidak mengulang; **penyaringan per peran** (staff tidak melihat pengajuan/notifikasi/akun staff lain; admin melihat semua; barang & PQA bersama); hanya perubahan tak relevan → kursor tetap maju tanpa daftar; kursor dari masa depan dan > 500 perubahan → reset; **polling tidak memperpanjang idle**, request biasa memperpanjang.
- **E2E (3 di `tests/cases/e2e_sync.php`):** anonim 401; `since` tidak valid (teks, negatif, desimal, terlalu panjang, array) → 422; `since=0` → kursor; 204 tanpa badan; dua akun lewat HTTP: staff mengajukan → admin langsung menerima ID + lencana + judul notifikasi, staff lain tidak menerima apa pun; disetujui → staff menerima; akun dinonaktifkan saat polling → 401; akun wajib-ganti-password → 403 dan halamannya tanpa skrip polling; halaman memuat kursor/interval/atribut lencana.

## 4. Uji dua akun di browser (server lokal, database tes)
Akun kedua bertindak lewat klien HTTP terpisah (sesi berbeda), browser sebagai akun pertama.
| Skenario | Hasil |
|---|---|
| Admin di halaman Permohonan; staff lain mengajukan | lencana & lonceng **1,03 dtk**, kartu baru di daftar **1,24 dtk**, toast "Permohonan baru dari E_staff2" **0,51 dtk** |
| Staff sedang mengisi form pinjam (2 barang dipilih, lokasi & keterangan terisi, kursor di kolom keterangan); staff lain mengajukan salah satu barang itu | peringatan muncul **0,34 dtk**; barang itu hilang dari daftar & keranjang, barang lain tetap; lokasi, keterangan, dan fokus **tidak berubah** |
| Dua tab terbuka di browser yang sama | hanya tab pemimpin yang memanggil `/sync`; tab lain menerima lewat BroadcastChannel (0 request dalam 6 dtk) |

Catatan: DevTools menandai respons 204 sebagai `ERR_ABORTED` karena badannya kosong tidak dibaca; `fetch` sendiri berhasil (`ok: true`).

## 5. Keputusan yang perlu pengecekan Anda
1. **SSE belum dibuat** (rencana menyebutnya opsional). Polling 1,5 detik sudah memenuhi target ≤ 2 detik dengan beban kecil (mayoritas 204).
2. Toast notifikasi hanya muncul di tab yang sedang terlihat; tab tersembunyi cukup memperbarui lonceng.

## 6. Belum ada (tahap berikut)
- Tahap 6: migrasi data lama + rekonsiliasi (termasuk uji paritas rekap pada data asli).
- Uji beban dan uji di hosting produksi (Tahap 7–8).
