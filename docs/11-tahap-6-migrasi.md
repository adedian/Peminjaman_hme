# Tahap 6 — Migrasi data & rekonsiliasi

Status: **selesai dan teruji** — 300 tes otomatis lulus (dijalankan dua kali berurutan; 9 baru di Tahap 6). Migrasi dijalankan pada database kerja `hme_peminjaman` dengan ekspor audit (4 Nov 2026, SHA-256 `185955c6…b107ca`) dan **rekonsiliasi 18/18 cocok**. Foto barang belum dimigrasikan (§6).

## 1. Alat
```
php tools/migrate-legacy.php --source="C:\hme_legacy\data.xlsx" --dry-run     # uji coba: semua dijalankan lalu dibatalkan
php tools/migrate-legacy.php --source="C:\hme_legacy\data.xlsx"               # migrasi + rekonsiliasi
php tools/migrate-legacy.php --source=... --download-photos                   # + unduh foto Drive (folder harus dibagikan)
php tools/migrate-legacy.php --source=... --reconcile-only                    # hanya rekonsiliasi
```
- **Sumber hanya dibaca**, SHA-256 dicatat di setiap run. Skrip **menolak** berkas yang berada di dalam folder aplikasi karena ekspor berisi password lama dalam teks biasa.
- **Satu transaksi**: gagal di tengah → tidak ada data setengah jadi. **Dry-run** = jalankan semuanya lalu rollback; laporannya tetap tersimpan.
- **Idempoten** lewat kunci alami (username, kode barang, ID Pinjam, nomor baris sheet). Menjalankan ulang tidak menggandakan apa pun dan **tidak menimpa** data yang sudah ada.
- Setiap baris sumber tercatat di laporan: berhasil / gagal / duplikat / perlu diperiksa (halaman admin **Laporan Migrasi**, unduh XLSX).
- Production wajib `--yes` untuk migrasi sungguhan.

Kode: `app/Migration/` — `LegacySource` (pembaca XLSX per posisi kolom), `LegacyDate` (parser), `LegacyImporter`, `LegacyReference` (port baris-per-baris `getMonthlyStats` & `generateExcelReport` lama), `Reconciler`.

## 2. Pemetaan (§8 rencana)
| Sumber | Tujuan | Aturan yang diterapkan |
|---|---|---|
| Data_Users (16) | `users` | username huruf kecil & di-trim, nama dirapikan (`"Rian "` → `Rian`), password lama di-**hash**, `must_change_password = 1`. Akun yang sudah ada (mis. `admin` buatan Anda) **tidak diubah** → dicatat "duplikat" |
| Master_Barang (19) | `items` | kategori dicocokkan, URL foto dinormalisasi (regex lama), `is_pqa` dari nama, status apa adanya lalu **diturunkan ulang** dari transaksi + PQA (selisih dilaporkan) |
| Barang yatim (HLMP-01/02) | `items` nonaktif | nama dari transaksi terakhir |
| Transaksi ber-ID (147) | `loan_batches` + `loans` | tanggal `dd/mm/yyyy HH.MM`, snapshot nama/pembawa/keterangan **apa adanya** (paritas laporan), `legacy_row` disimpan, nomor urut berikutnya = 391 |
| Catatan manual (249) + pemisah (1) | `legacy_manual_loans` | parser §8; tidak masuk rekap (BR-27) |
| Data_PQA (5) | `pqa_installations` | tanggal pasang/cabut dari sel tanggal asli, durasi sheet dipakai; selisih dengan hitung ulang dilaporkan |
| Nama Barang Elektronik (62 + 17) | `legacy_inventory_register` | dua bagian dengan peta kolom berbeda (bagian 2 punya kolom SATUAN) |

**Parser tanggal catatan manual:** teks `d/m/yyyy[ H(:|.|;)MM]`; sel tanggal hasil salah-konversi Sheets → bulan↔hari ditukar (`swapped_dm`, teks asli disimpan); teks bukan tanggal → `return_note`; validasi kembali ≥ bawa (tanggal tanpa jam dibandingkan per tanggal) dan ± 60 hari dari baris di sekitarnya → `needs_review`, **tidak dikoreksi otomatis**.

## 3. Koreksi terhadap rencana
1. **Pengajuan = 47, bukan 59.** Baseline §8 menghitung prefix `HME-<ms>`. Ternyata Apps Script membuat ID per barang dengan `Date.now()` di dalam loop, sehingga 12 pengajuan terpecah di pergantian milidetik (selisih 1–4 ms), dan baris satu pengajuan kadang tidak berurutan di sheet (mis. baris 267 milik pengajuan baris 261). Pengelompokan yang benar: akun + tanggal bawa + lokasi + selisih ID ≤ 1 detik. Prefix pertama disimpan di `loan_batches.legacy_prefix`; semua ID asli tetap di `loans.legacy_id`.
2. **Urutan "baris sheet" untuk paritas.** Ranking alat dengan jumlah seri mengikuti urutan baris sheet, dan urutan itu tidak sama dengan nomor urut. `ReportService` kini mengurutkan data migrasi menurut `legacy_row`, lalu transaksi baru menurut nomor urut. Tanpa ini, 2 periode rekap berbeda urutan.

## 4. Hasil di database kerja
| Pemeriksaan (sumber = sistem) | Hasil |
|---|---|
| Akun di sumber / admin / staff | 16 / 1 / 15 ✓ (akun `admin` lama dilewati, admin Anda dipakai) |
| Barang aktif · Tersedia / Dipinjam | 19 · 17 / 2 ✓ (HLMP-03, HLMP-04 dipinjam Naufal) |
| Barang terhapus dibuat ulang nonaktif | HME-HLMP-01, HME-HLMP-02 ✓ |
| Transaksi · returned / approved | 147 · 145 / 2 ✓ |
| Per akun | naufal 58, rian 27, victor 19, yudi 17, rika 7, vega 6, jefri 5, sandi 5, vicky 3 ✓ |
| Per barang · isi per baris · pengajuan | ✓ · 147/147 ✓ · 47 ✓ |
| **Paritas rekap `getMonthlyStats`** | 14 periode (semua data, 2026, 12 bulan 2026, 2025-2026) identik: total, status, ranking, daftar aktif ✓ |
| **Paritas Laporan Stok (Excel)** | 19 barang: Jumlah, Status, Peminjam Terakhir, Keterangan identik ✓ (aturan F2 tidak mengubah apa pun karena seluruh transaksi lama berstatus approved/returned) |
| Catatan manual · pemisah | 249 · 1 ✓ (ok 155, tanggal ditukar 77, perlu diperiksa 9, tidak terbaca 8) |
| PQA · durasi | 5 · 9, 14, 80, 8, 9 ✓ |
| Register | 62 + 17 ✓ |

**19 baris "perlu diperiksa"** (lihat halaman Laporan Migrasi): 9 catatan manual dengan tanggal janggal (mis. tahun "1016", kembali di tahun sebelum bawa, jam kembali lebih awal), 8 catatan manual tanpa tanggal yang dapat dibaca ("Tidak tahu tanggal berapa", "sudah kembali"), dan 2 barang yatim yang dibuat ulang. Semuanya diarsipkan apa adanya beserta teks aslinya.

## 5. Halaman admin baru
- **Arsip Data Lama** (`/archive`): tab *Catatan manual* (cari, filter tanda tanggal, teks asli ditampilkan) dan *Register inventaris* (cari, filter bagian, tombol **Jadikan Master Barang** → form tambah barang terisi nama; ID & kategori diisi admin — Q2).
- **Laporan Migrasi** (`/migration`): riwayat run (dry/nyata, SHA-256, jumlah per kategori), hasil rekonsiliasi per pemeriksaan, rincian per baris dengan filter & pencarian, unduh XLSX (teks ditulis sebagai string).
- API admin: `GET /api/v1/archive/manual|register`, `/migration/runs`, `/migration/runs/{id}/issues`, `/migration/runs/{id}/export`.

## 6. Bukti pengujian (9 tes baru)
- **Service (8 di `tests/cases/migration.php`)** dengan ekspor sintetis yang memuat semua kasus tepi (pengajuan terpecah milidetik + baris berselang, barang yatim, kategori tak dikenal, akun rusak & duplikat, password angka, sel tanggal tertukar, tanggal tak terbaca, kembali < bawa, kembali tanpa jam, baris pemisah, PQA dengan durasi berbeda, register dua bagian): parser tanggal; dry-run tidak menyimpan data; impor nyata per entitas; **password tidak pernah muncul di audit log maupun laporan**; idempoten & tidak menimpa akun yang ada; CLI menolak berkas di dalam folder aplikasi; **rekonsiliasi pada database bersih terpisah** cocok semua, mendeteksi perubahan data, dan CLI keluar 0; arsip & laporan (filter, 404, XLSX anti formula injection); `ImageService::storeLocal`.
- **E2E (1 di `tests/cases/e2e_migration.php`)**: anonim 401/redirect, staff 403 untuk semua API/unduhan/halaman, admin 200, XLSX valid, halaman tanpa skrip inline, prefill barang.
- **Browser** (salinan database hasil migrasi + akun uji sementara, lalu dihapus): Laporan Migrasi (rekonsiliasi 18 pemeriksaan cocok), Arsip manual & register, "Jadikan Master Barang" membuka form dengan nama terisi dan fokus di ID barang.

## 7. Yang masih perlu dari Anda
1. **Foto barang.** 16 dari 19 foto masih privat di Drive. Setelah folder `HME_Inventory_Images` dibagikan "Anyone with the link – Viewer", jalankan `--download-photos`. Foto diunduh, divalidasi, dan diproses ulang seperti unggahan biasa; yang gagal dicatat di laporan dan URL lamanya tetap disimpan.
2. **Cut-over.** Data saat ini berasal dari ekspor audit pagi 4 Nov. Saat go-live: umumkan freeze → nonaktifkan Apps Script → ekspor final ke luar `htdocs` → **database kosong** (`php database/migrate.php --fresh --yes --seed` lalu buat admin) → `--dry-run` → migrasi → rekonsiliasi → semua user login dengan password lama lalu wajib menggantinya. Migrasi ulang ke database yang sudah berisi tidak memperbarui baris lama (misalnya pinjaman yang sesudahnya dikembalikan), karena itu cut-over memakai database kosong.
3. Hapus salinan ekspor audit di folder sementara sesi sebelumnya setelah ekspor final dibuat (berisi password lama).
