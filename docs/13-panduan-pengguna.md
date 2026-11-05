# Panduan Pengguna — Sistem Peminjaman HME

Untuk staff dan admin. Buka alamat sistem di browser HP atau komputer, lalu masuk dengan username dan password dari admin.

## 1. Masuk & keamanan akun
- **Login pertama:** sistem meminta Anda mengganti password (minimal 8 karakter). Ini juga berlaku setelah admin mereset password Anda.
- Gagal login 5 kali berturut-turut → akun dikunci sementara beberapa menit. Tunggu, jangan coba terus-menerus.
- Sesi berakhir otomatis bila tidak dipakai lama (±2 jam) atau setelah 12 jam. Cukup masuk lagi.
- Ganti password kapan saja di **Profil**. Setelah diganti, perangkat lain otomatis keluar.
- **Jangan bagikan password.** Admin tidak pernah meminta password Anda.

## 2. Tampilan
- **HP:** bilah menu di bawah. **Komputer:** menu di samping kiri. Staff: *Beranda · Pinjam · Riwayat · PQA · Profil*. Admin: *Beranda · Permohonan · PQA · Barang · Lainnya* (Rekap, Pengguna, Arsip Data Lama, Log Aktivitas, Pengaturan).
- **Lonceng** di kanan atas menampilkan notifikasi; angka merah = belum dibaca. Halaman terbarui otomatis (±2 detik) — tidak perlu me-refresh, dan isian formulir Anda tidak hilang.
- Mode terang/gelap mengikuti pengaturan perangkat.

## 3. Staff

### 3.1 Meminjam barang
1. **Pinjam** → pilih 1–30 barang yang berstatus *Tersedia* (cari dengan kolom pencarian).
2. Isi **lokasi** (wajib), tim, dan catatan bila perlu → **Ajukan**.
3. Status awal **Menunggu**; admin menerima notifikasi (dan email). Barang langsung ditahan untuk Anda.
4. Bila ada barang yang diambil orang lain saat Anda mengisi, sistem memberi peringatan di formulir.

Aturan penting:
- Selama masih punya pinjaman yang **sedang berjalan (*Dipinjam*)**, Anda belum bisa mengajukan lagi. Pengajuan yang masih *Menunggu* tidak menghalangi.
- Satu barang tidak dapat diajukan dua orang sekaligus; yang lebih dulu menang, yang lain diberi tahu.

### 3.2 Setelah diajukan
| Status | Artinya | Yang bisa Anda lakukan |
|---|---|---|
| **Menunggu** | belum diputuskan admin | **Batalkan**, atau **ganti unit** ke barang sejenis yang tersedia |
| **Dipinjam** (filter *Aktif*) | disetujui, barang Anda | **Kembalikan** saat barang sudah dikembalikan |
| **Selesai** (*Dikembalikan*) | sudah dikembalikan | — |
| **Ditolak** | ditolak admin | ajukan ulang bila perlu |
| **Dibatalkan** | Anda batalkan | — |

**Mengembalikan barang:** buka **Riwayat** → pilih pinjaman → **Kembalikan**. Anda sendiri yang mengonfirmasi; waktunya dicatat otomatis oleh sistem. Barang kembali berstatus *Tersedia*.

### 3.3 Tracker PQA
Daftar unit PQA yang dipasang di lokasi klien — **terlihat oleh semua staff**.
1. **Pasang PQA** → pilih unit, isi PPIC, lokasi, tim pemasang, target hari → ajukan. Unit langsung ditahan.
2. Setelah admin menyetujui, status **Terpasang** dan hitungan hari berjalan dari saat disetujui (bilah progres menunjukkan X/Y hari).
3. **Tambah waktu:** ajukan tambahan hari (satu permintaan menunggu sekali).
4. **Request cabut:** isi tim pencabut. Setelah admin menyetujui, status **Dicabut** dan unit tersedia kembali; durasi dihitung dalam hari penuh.

## 4. Admin

### 4.1 Permohonan
**Permohonan** → tab *Permohonan* (menunggu) dan *Riwayat*. Buka pengajuan lalu **Setujui** atau **Tolak** — untuk seluruh pengajuan atau per barang. Angka pada menu = jumlah yang menunggu. Peminjam menerima notifikasi.

### 4.2 PQA
Setujui/tolak pemasangan, tambah waktu, dan pencabutan dari halaman **Tracker PQA**. Menyetujui pencabut otomatis menutup permintaan tambah waktu yang masih menggantung.

### 4.3 Barang
**Barang** → *Tambah* (ID/kode unik, nama, kategori, foto opsional, centang *Unit PQA* bila perlu). *Ubah* kapan saja; ID terkunci bila barang sudah punya riwayat. **Nonaktifkan** menyembunyikan barang dari form pinjam tanpa menghapus riwayat — ditolak bila barang sedang dipinjam/dipasang. **Hapus permanen** hanya untuk barang yang belum pernah dipakai (mis. salah input); barang berriwayat tidak bisa dihapus, nonaktifkan saja. Foto otomatis diperkecil; maksimal 3 MB.

### 4.4 Rekap & laporan Excel
**Rekap** → pilih bulan, tahun, rentang tanggal, atau semua data. Menampilkan total, status, peringkat alat, dan daftar aktif. **Unduh Excel** menghasilkan dua sheet: *Laporan Stok* dan *Transaksi Periode* (mengikuti filter). Unduhan tercatat di log aktivitas.

### 4.5 Pengguna
**Pengguna** → tambah staff/admin, ubah nama/peran, **Reset password** (password baru ditampilkan sekali untuk diserahkan ke pengguna; pengguna tidak dipaksa menggantinya), isi **Nomor WhatsApp** untuk notifikasi, nonaktifkan. Anda tidak bisa menonaktifkan diri sendiri, dan harus selalu ada minimal satu admin aktif.

### 4.6 Arsip Data Lama
Catatan manual dan register inventaris dari sistem lama (hanya baca). Pada register, **Jadikan Master Barang** membuka form tambah barang yang sudah terisi nama. **Laporan Migrasi** menampilkan hasil perpindahan data; baris bertanda *perlu diperiksa* sebaiknya ditinjau sekali.

### 4.7 Log Aktivitas & Pengaturan
- **Log Aktivitas:** siapa melakukan apa dan kapan (filter kelompok, pelaku, kata kunci, tanggal; rincian sebelum/sesudah).
- **Pengaturan:** alamat email penerima notifikasi (pisahkan dengan koma), status pengiriman, tombol **Kirim email uji**.

## 5. Bila ada masalah
| Gejala | Tindakan |
|---|---|
| "Sistem sedang dalam pemeliharaan" | Sedang upgrade/pemulihan data. Tunggu beberapa menit lalu muat ulang |
| Lupa password | Minta admin **Reset password** |
| Barang tidak muncul di daftar pinjam | Sedang dipinjam/dipasang, atau dinonaktifkan |
| "Sesi berakhir" / diminta masuk lagi | Normal; masuk kembali. Isian yang belum diajukan perlu diulang |
| Aksi ditolak karena barang sudah diambil | Pilih unit lain yang tersedia |
| Notifikasi/halaman tidak terbarui | Periksa koneksi internet; muat ulang halaman |

Untuk kendala lain, sebutkan **ID permintaan** dari pesan error (kode pendek 12 karakter, juga ada di Log) kepada admin.

## Revisi V2 — yang berubah untuk pengguna
**Meminjam barang:** isi **Batas pengembalian** (tanggal & jam). Pintasan *Besok / 3 hari / 1 minggu / 2 minggu* hanya mengisi kolom; Anda bebas mengubahnya. Di daftar & detail tampil **sisa waktu**; bila lewat dan barang belum dikembalikan, statusnya otomatis **Terlambat**. Anda tetap mengonfirmasi pengembalian sendiri.

**Pemasangan PQA:** isi tanggal & jam pemasangan, lokasi, durasi, dan catatan. Setelah admin mengonfirmasi, tampil **jadwal pencabutan** dan sisa waktunya. *Tambah waktu* menggeser jadwal (pengingat dihitung ulang). *Request cabut*: pilih **Kembali ke kantor** atau **Pindah ke site lain** (isi site tujuan dan target hari; admin lalu mengonfirmasi pemasangan di sana).

**Notifikasi di HP (PWA):** buka **Profil → Notifikasi di HP → Aktifkan di perangkat ini** dan izinkan notifikasi. Pengingat batas pengembalian, keterlambatan, jadwal PQA, dan keputusan admin akan muncul di layar HP walau aplikasi ditutup. Pasang aplikasinya lewat kartu **Pasang aplikasi** (di iPhone: Bagikan → Tambah ke Layar Utama, lalu buka dari ikon). Saat Keluar, notifikasi di perangkat itu otomatis dimatikan.

**Admin:** Beranda kini berupa dashboard. **Pengaturan → Notifikasi HP & pengingat** untuk status server, jendela pengingat, dan log pengiriman (ada tombol *Kirim ulang* untuk yang gagal). Penjadwal pengingat harus dipasang di cron server (lihat `deploy/crontab.example`) dan kunci VAPID dibuat sekali (`php tools/generate-vapid.php --write`).
