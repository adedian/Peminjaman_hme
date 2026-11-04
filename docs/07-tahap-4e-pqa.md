# Tahap 4e — Tracker PQA

Status: **selesai dan teruji** — 254 tes otomatis lulus (dijalankan dua kali berurutan pada database tes bersih), termasuk skenario berebut lintas modul dengan proses PHP paralel, ditambah uji visual alur lengkap (staff & admin) di browser pada lebar HP dan desktop.

## 1. Istilah
**Instalasi PQA** = satu baris `pqa_installations`: satu unit PQA (barang dengan flag `is_pqa`) yang dipasang di lokasi klien, dengan PPIC penanggung jawab, tim pemasang, dan target hari.
Status: `Pending → Terpasang → Req Cabut → Dicabut`, serta `Pending → Ditolak`.
Permintaan tambah waktu disimpan di `pqa_installations.pending_extension_days` + riwayat di `pqa_requests`.

## 2. Aturan bisnis → di mana ditegakkan
Penomoran mengikuti katalog di `docs/01-audit-dan-perencanaan.md` §3.3.
| Aturan | Isi | Penegak |
|---|---|---|
| BR-18 / Q6 | Unit PQA = barang berflag `is_pqa` (bukan dari nama); aktif | `PqaService::availableUnits`, `submit` (422 `item_id`) |
| BR-19 / BR-29 | Unit tidak bisa dipasang bila PQA aktif, ada pinjaman pending/approved, atau berstatus Dipinjam — dan sebaliknya | kunci `FOR UPDATE` via `AvailabilityService::lockItems` **+ unique key `active_item_key` di DB** sebagai pengaman terakhir; `AvailabilityService::sync` menghitung `master_status` dari loan **dan** PQA |
| BR-20 | Unit, PPIC, lokasi, tim pasang wajib; target hari > 0 (batas teknis 3650) | validasi server (422 per field) |
| BR-21 | Pengajuan langsung membuat unit "Dipinjam"; ditolak → "Tersedia" | `submit`, `decideInstall` |
| BR-22 | Disetujui → Terpasang, tanggal pasang = waktu **persetujuan** (jam server) | `decideInstall` |
| BR-23 | Tambah hari: N > 0, hanya saat Terpasang, satu permintaan menunggu sekali; setuju → target += N | `requestExtension` (409 `extension_pending`), `decideExtension` |
| BR-24 | Request cabut: tim cabut wajib, hanya saat Terpasang. **Koreksi:** tidak diblokir oleh tambah hari yang menunggu (lihat §5) | `requestRemoval` |
| BR-25 | Cabut disetujui → Dicabut, durasi = floor(hari), unit "Tersedia"; ditolak → kembali Terpasang | `decideRemoval` |

Seluruh aksi berjalan dalam **satu transaksi** dengan urutan kunci seragam (*barang → baris instalasi*), mencatat `activity_logs` dan `change_events`, lalu memanggil `AvailabilityService::sync`.

## 3. Endpoint
| Method & path | Akses |
|---|---|
| `GET /api/v1/pqa/units` · `GET /api/v1/pqa?group=pending\|active\|history&q=&limit=&offset=` · `GET /api/v1/pqa/{id}` | login — **tracker dibagi bersama** (staff melihat semua unit, seperti sistem lama) |
| `POST /api/v1/pqa` · `/pqa/{id}/extend` · `/pqa/{id}/remove` | staff |
| `POST /api/v1/pqa/{id}/approve` · `/reject` · `/extension/approve` · `/extension/reject` · `/removal/approve` · `/removal/reject` | admin |

Halaman: `/pqa` (semua peran; staff mendapat tombol "Pasang PQA Baru", admin tombol keputusan), `/more` (admin: menu Pengguna & Profil). Daftar & detail dirender klien (`pqa.js`, DOM + `textContent`, tanpa `innerHTML`); tombol mengikuti `can` dari server, tetapi server memeriksa ulang setiap aksi. Keputusan yang tak dapat dibatalkan (setujui cabut/pasang) meminta konfirmasi dulu.

## 4. Bukti pengujian (254 tes; 28 baru di 4e: 20 service/DB + 8 E2E)
- **Service/DB (20 tes di `tests/cases/pqa.php`):** pengajuan (status, unit langsung Dipinjam, izin per peran), validasi (bukan PQA/tak ada/nonaktif, kolom wajib, target hari), BR-19/29 (PQA aktif, loan pending/approved, status Dipinjam → 409), admin tak boleh mengajukan, persetujuan/penolakan pemasangan (tanggal pasang = saat disetujui; tak bisa diproses dua kali), tambah waktu (satu menunggu, setuju/tolak), cabut (setuju/tolak, contoh data lama 80 hari), tambah waktu & cabut bersamaan (permintaan menggantung ditutup saat dicabut), durasi aktif & `over_target`, tracker bersama, daftar/grup/pencarian/paginasi, hitungan tindakan admin, penjaga ID/flag barang berpemasangan aktif.
- **Konkurensi (3 dari 20, proses PHP paralel sungguhan):** 5 staff memasang unit yang sama → tepat 1 berhasil; pinjam biasa vs pasang PQA pada unit sama (4 putaran) → tepat 1 menang, status konsisten; admin setujui tambah waktu **bersamaan** dengan setujui cabut → hasil akhir selalu konsisten, tanpa deadlock.
- **E2E lewat HTTP nyata (8 tes di `tests/cases/e2e_pqa.php`):** anonim 401/redirect; staff → aksi admin 403 dan admin → aksi staff 403 (CSRF benar, data tidak berubah); mass-assignment (`status`, `installed_at`, `total_days`, `requested_by`, `decided_by`) diabaikan; akun wajib-ganti-password terkunci; alur lengkap dua staff + admin sampai unit tersedia lagi; daftar & validasi API (422 per field, 404); halaman `/pqa` (tombol per peran, tanpa skrip eksternal, menu & lencana); `/more` admin 200, staff 403.
- **Browser (HP 390 px & desktop):** staff — daftar, bilah progres (oranye 4/14; merah 9/7 "Melewati target 2 hari"), sheet pasang baru (hanya unit PQA bebas, PPIC terisi nama), pengajuan lewat form; lokasi berisi `<b>PT Uji</b>` tampil sebagai **teks** (tidak ada elemen yang dibuat). Admin — setujui pasang → Terpasang 0/10; setujui +5 hari → target 7→12 dan peringatan hilang; setujui cabut (dengan konfirmasi) → pindah ke Riwayat "Selesai · 4 hari (target 14)".

## 5. Temuan & koreksi
- **BR-24 pada katalog perencanaan salah:** katalog menulis bahwa pencabutan diblokir saat tambah waktu pending, padahal kode lama (`Code.gs`) memperlakukan keduanya independen. Diimplementasikan **mengikuti sistem lama**: cabut boleh diminta meski ada tambah waktu pending; saat pencabutan disetujui, permintaan tambah waktu yang menggantung ditutup otomatis (ditolak) agar tidak yatim.
- Bug tes: perhitungan interval `NOW() - 80 DAY + 5 HOUR` memberi 79 hari (tanda interval) → helper tes memakai `- INTERVAL`.
- CSS PQA sempat tidak ter-append karena rantai perintah shell berhenti diam-diam pada `grep` tanpa hasil; ditambahkan ulang dan diverifikasi.

## 6. Keputusan implementasi yang perlu pengecekan Anda
1. **Tracker bersifat bersama:** semua staff melihat seluruh unit PQA (meniru sistem lama); hanya aksi yang dibatasi peran. Bila ingin staff hanya melihat miliknya, beri tahu.
2. **Admin tidak dapat memasang PQA atas nama staff** (hanya staff yang mengajukan; `requested_by` dicatat).
3. **Riwayat kini memuat juga yang `Ditolak`** (sistem lama menghilangkannya), supaya penolakan dapat ditelusuri.
4. `total_days` dihitung **hari penuh** (`FLOOR` selisih detik / 86400) dari `installed_at` (jam server) ke waktu pencabutan.

## 7. Belum ada (tahap berikut)
- Notifikasi in-app & email (hook `PqaNotifier` sudah dipanggil, isinya kosong → 4g).
- **Lencana navigasi (Permohonan/PQA) dirender server saat muat halaman**; belum ikut berubah setelah aksi tanpa muat ulang → diperbarui otomatis oleh `/sync` di Tahap 5 (perilaku yang sama berlaku untuk lencana Permohonan sejak 4d).
- Rekap/laporan Excel (4f).
