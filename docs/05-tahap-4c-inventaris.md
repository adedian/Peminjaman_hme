# Tahap 4c — Inventaris Barang

Status: **selesai dan teruji** — 188 tes otomatis lulus, ditambah uji visual di browser (unggah foto lewat UI, grid, status).

## 1. Yang dibangun
| Area | Isi |
|---|---|
| Master barang (admin) | Halaman **/items**: grid kartu berfoto, pencarian (nama / ID / kode ref), filter status (Semua · Tersedia · Dipinjam · Nonaktif) dan kategori; tambah, ubah, nonaktifkan/aktifkan lewat sheet |
| Aturan BR-13/14 (Q3, Q6) | ID barang unik (tak peka huruf) · **tidak ada hapus permanen** — barang dinonaktifkan · tidak bisa dinonaktifkan bila ada pengajuan *pending* / pinjaman *approved* / PQA aktif / berstatus Dipinjam (409 `item_in_use`) · ID **terkunci** bila barang sudah punya riwayat (409 `code_locked`) · flag `is_pqa` tidak bisa diubah saat ada pemasangan PQA aktif (409 `pqa_active`) |
| `master_status` | **Tidak dapat diubah dari API/UI barang** (mass-assignment diuji). Hanya service peminjaman/PQA (4d/4e) yang menyetelnya |
| Foto | Unggah JPG/PNG/WebP; divalidasi isinya, diolah ulang oleh GD, disimpan acak di luar web root, disajikan lewat controller berotorisasi (lihat §3) |
| Audit & realtime | `item.created/updated/activated/deactivated` di `activity_logs`; setiap perubahan menulis `change_events` |

## 2. Endpoint (semua: login + password sudah diganti + **admin**)
| Method & path | Fungsi |
|---|---|
| `GET /api/v1/categories` | daftar kategori |
| `GET /api/v1/items?q=&category_id=&status=&active=&limit=&offset=` | daftar + total (limit maks. 200) |
| `GET /api/v1/items/{id}` | satu barang |
| `POST /api/v1/items` | tambah (multipart: field + `photo`) |
| `POST /api/v1/items/{id}` | ubah (multipart; `remove_photo=1` menghapus foto). Memakai POST karena PHP tidak mengurai multipart untuk PUT |
| `POST /api/v1/items/{id}/activate` · `/deactivate` | aktifkan / nonaktifkan |
| `GET /media/items/{nama}` (halaman, **login**) | foto; `private` cache + ETag/304 |

Staff **tidak** punya endpoint barang di 4c. Daftar barang tersedia untuk form peminjaman akan disediakan di 4d (dengan ketersediaan real).

## 3. Keamanan unggahan foto
Berkas unggahan **tidak pernah disimpan apa adanya**. Alurnya:
1. Berkas harus benar-benar hasil unggahan HTTP (`is_uploaded_file`) — `tmp_name` buatan penyerang (mis. `.env`) ditolak.
2. Ukuran ≤ 3 MB (`UPLOAD_MAX_BYTES`), tidak kosong; kode galat PHP dipetakan ke pesan yang jelas.
3. **Isi** diperiksa dengan `finfo` + `getimagesize` (nama berkas dan Content-Type kiriman klien diabaikan). Hanya JPEG/PNG/WebP — **SVG ditolak** (dapat memuat skrip), begitu pula GIF/PDF/ZIP/HTML/PHP.
4. Batas **24 megapiksel** dicek dari header SEBELUM dekode (anti decompression-bomb) + estimasi memori terhadap `memory_limit`.
5. Didekode lalu **dienkode ulang oleh GD**: EXIF/GPS, komentar, dan muatan tersembunyi (poliglot GIF+PHP, `<?php` di segmen komentar) hilang. Orientasi EXIF diterapkan lebih dulu.
6. Disimpan sebagai `<32 hex acak>.jpg|png|webp` (+ `_t` thumbnail 360 px; utama maks. 1200 px) di `storage/uploads/items` (**di luar** `public/`, dapat dipindah lewat `UPLOAD_DIR`).
7. Disajikan lewat `/media/items/{nama}` yang mewajibkan login; nama divalidasi regex ketat → tidak ada path traversal; `nosniff`.
8. Foto lama dihapus **setelah** perubahan berhasil dikomit; foto dari percobaan yang gagal dibersihkan (tidak ada berkas yatim).

Di sisi klien (`items.js`), foto diperkecil via canvas (maks. 1600 px, JPEG 85%) sebelum dikirim agar hemat kuota seluler; server tetap memvalidasi ulang semuanya.

## 4. Bukti pengujian (188 tes; 56 baru di 4c)
- **Gambar (9):** diperkecil & nama acak; transparansi PNG; WebP; metadata & kode PHP di komentar JPEG **terbuang**; orientasi EXIF; 10 jenis berkas berbahaya ditolak; batas piksel/ukuran/galat unggahan; `tmp_name` palsu; pola nama (traversal).
- **Service (17):** validasi tiap field (termasuk injeksi & tipe array), duplikat, rollback foto, kunci ID, kunci PQA, ganti/hapus foto, guard nonaktifkan (8 kondisi), filter/pencarian/paginasi (wildcard `%`/`_` literal, SQLi).
- **E2E lewat HTTP nyata (13):** anonim → 401 / redirect; **staff + CSRF + foto valid ke semua endpoint → 403 dan tidak ada data/berkas berubah**; CSRF pada multipart; foto, thumbnail, ETag→304; 7 unggahan berbahaya via multipart mentah → 422 tanpa berkas/barang; `photo[]` bertingkat tidak menyebabkan 500; 10 percobaan traversal media → 404; halaman `/items` ter-escape (diverifikasi DOM).

## 5. Bug yang ditemukan tes/pengujian dan diperbaiki
1. `ItemService::update` gagal (500) karena `lock()` tidak mengambil `category_id` untuk audit.
2. Kegagalan membaca berkas unggahan (mis. dikunci antivirus) menghasilkan 500 → kini 422 dengan pesan jelas.
3. `MediaController` bisa mengirim **200 dengan badan kosong** bila berkas terkunci sesaat → kini 503 + `Retry-After`.
4. Atribut `hidden` kalah oleh `display:flex` komponen (kotak "Hapus foto" tampil di mode tambah) → aturan global `[hidden]`.
5. Filter status & dropdown kategori bertabrakan pada lebar menengah.

## 6. Catatan
- **Tes sporadis di Windows:** dua kali dari ±20 run penuh, satu tes berbasis foto gagal sekali (tidak dapat direproduksi: 20 run berikutnya hijau). Keduanya terjadi tepat setelah banyak berkas ditulis/dihapus, sesuai dugaan kunci berkas sesaat oleh antivirus. Muatan berbahaya dalam tes dikirim dari memori (bukan lewat berkas) karena Windows Defender menghapus berkas webshell uji.
- URL foto lama dari Google Drive (`photo_legacy_url`) **belum** ditampilkan (CSP `img-src 'self'` dan 16/19 foto privat); foto dimigrasikan ke penyimpanan lokal di Tahap 6.
- Pengaturan hosting: pastikan `upload_max_filesize`/`post_max_size` ≥ 4 MB dan `memory_limit` ≥ 128 MB (24 MP × 4 byte × 2,5 ≈ 240 MB; foto di atas kemampuan server ditolak dengan pesan, bukan error).
- Thumbnail dibuat saat unggah; foto lama hasil migrasi akan diproses dengan alur yang sama.

## 7. Berikutnya
4d Peminjaman (pengajuan multi-barang, setujui/tolak/kembalikan, anti double-booking di service + DB, ketersediaan barang) — modul inti.
