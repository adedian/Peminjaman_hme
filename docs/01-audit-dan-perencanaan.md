# PEMINJAMAN HME — Tahap 1: Audit Sistem Lama & Rancangan Teknis

| | |
|---|---|
| Versi | **v1.1 — FINAL** (4 November 2026). Kode klien, kode server (`Code.gs`), dan seluruh data spreadsheet sudah dianalisis. |
| Fase | TAHAP 1 & 2 selesai. Implementasi mulai dari TAHAP 3/4a dengan Claude Sonnet 5.5 (High). |
| Status | ✅ **Disetujui pemilik (4 Nov 2026): "Setuju semua" rekomendasi §13.** Keputusan final ada di §14 dan mengikat implementasi. |
| Lingkungan lokal | XAMPP: PHP 8.0.30, MariaDB 10.4.32, Composer tersedia, ekstensi `pdo_mysql`, `zip`, `gd`, `mbstring`, `fileinfo`, `xmlreader` aktif |

---

## 0. Sumber audit

| Sumber | Status | Isi |
|---|---|---|
| Web app (`/exec`) | ✅ | Kode klien `index.html` (806 baris) + 26 fungsi server yang diekspos |
| `Code.gs` | ✅ (dikirim Anda) | Seluruh logika server |
| Spreadsheet | ✅ (ekspor XLSX) | 5 sheet, seluruh data, merged cell. Tidak ada formula atau validasi |
| Foto barang (Drive) | ⚠️ | **3/19 publik**, **16/19 privat** (mengarah ke halaman login Google) |
| Halaman setelah login | ⛔ sengaja tidak dibuka | Tidak perlu lagi: perilakunya terbaca lengkap dari kode |

Kalau ada file `.gs` lain atau **trigger terjadwal** (*Apps Script → Triggers*) selain `Code.gs` dan `index.html`, mohon diinformasikan. Kode yang Anda kirim tidak menunjukkan adanya trigger.

> 🔴 **Spreadsheet harus dikembalikan ke "Restricted"** jika belum. Sheet `Data_Users` berisi password plaintext.

---

## 1. Hasil audit sistem lama

### 1.1 Gambaran umum
- **Sistem Peminjaman PT Hexa Multi Energi (HME).** Aplikasi dipakai sejak **23 April 2026**. Sebelumnya peminjaman dicatat manual di sheet yang sama sejak **April 2025**.
- **Teknologi:** Google Apps Script (HtmlService) + Google Sheets sebagai database (akses berdasarkan **posisi kolom**, bukan nama header) + Google Drive (foto dan laporan) + MailApp (email).
- **Peran:** `admin` dan `staff`. **Modul:** Peminjaman Barang dan Tracker PQA.
- **Sinkronisasi:** polling 10 detik yang mengambil ulang seluruh data.
- **Penguncian:** `LockService` dipakai pada fungsi tulis peminjaman dan PQA, tetapi **tidak** pada fungsi user dan inventaris.

### 1.2 Temuan kekurangan

#### 🔴 Keamanan (kritis, terkonfirmasi dari `Code.gs`)
| # | Temuan | Bukti |
|---|---|---|
| S1 | **Semua password plaintext dikirim ke browser sebelum login** | `getAllUsers()` mengembalikan `pass`; login dicek di JS klien |
| S2 | **Tidak ada autentikasi/otorisasi di server.** Semua fungsi bisa dipanggil siapa pun yang membuka URL | Tidak ada pengecekan sesi/role di fungsi mana pun |
| S3 | Ganti password akun siapa pun tanpa password lama | `dbChangePwd(un, newPwd)` |
| S4 | Hapus user/barang tanpa otorisasi atau pengecekan | `dbDeleteUser`, `dbDeleteInventory` → `deleteRow` |
| S5 | Password disimpan plaintext | `Data_Users` kolom B |
| S6 | Kredensial default di-hardcode (`admin/1234`, `gabriel/1234`) jika sheet user hilang | `getSheet("Data_Users")` |
| S7 | Setiap laporan Excel membuat Google Sheet baru dengan akses **"Anyone with link"** | `generateExcelReport` |
| S8 | Upload tanpa validasi tipe/ukuran, file dibuat publik | `uploadFileToDrive` |
| S9 | Stored XSS: output HTML tidak di-escape | `index.html` |
| S10 | `editPendingLoan` dan `saveLoan` tidak memeriksa pemilik transaksi | Staff A bisa mengubah atau mengembalikan transaksi staff B |

#### 🟠 Integritas data
| # | Temuan |
|---|---|
| D1 | **Barang terhapus masih punya riwayat:** `HME-HLMP-01/02` dirujuk 11 transaksi tetapi sudah tidak ada di master |
| D2 | **Tanggal manual tertukar hari↔bulan:** 51 sel tanggal bawa dan 62 sel tanggal kembali (mis. `8/4/2025` → 4 Agustus) |
| D3 | Format tanggal manual campur aduk, termasuk teks seperti `sudah kembali`, `Pindah lokasi`, `Tidak tahu tanggal berapa` |
| D4 | Pencatatan manual masih terjadi setelah aplikasi live (baris 365) |
| D5 | Nomor "No" ganda (8 nomor) + baris pemisah teks (baris 250) |
| D6 | `dbAddInventory` **tidak mengecek ID duplikat** |
| D7 | **Double-booking**: approve tidak mengecek apakah barang sudah Dipinjam. Ketersediaan hanya dicek di klien |
| D8 | **Konflik Peminjaman ↔ PQA**: form PQA tidak mengecek `status_master` maupun peminjaman. Unit PQA yang sedang dipinjam biasa tetap bisa diajukan pasang. Saat PQA ditolak/dicabut, status di-set "Tersedia" walaupun pinjaman biasa masih aktif |
| D9 | Tanggal bawa memakai **jam perangkat staff** (dikirim klien). Tanggal kembali memakai jam server. |
| D10 | PQA dirujuk lewat **nomor baris sheet**. Penyisipan atau pengurutan baris bisa membuat aksi mengenai baris yang salah |
| D11 | Pengaju PQA tidak tercatat (PPIC hanya teks bebas) |
| D12 | Data user tidak rapi: `"Rian "` (spasi di belakang), `rika` huruf kecil, inisial tidak konsisten |
| D13 | 3 barang memakai foto yang sama. 16 foto privat. |

#### 🟡 Fungsi & UX
| # | Temuan |
|---|---|
| F1 | **Filter bulan/tahun pada Laporan Excel diabaikan.** Isi laporan selalu snapshot stok saat ini; parameter hanya dipakai untuk nama file |
| F2 | Laporan Excel: "Peminjam Terakhir" diambil dari transaksi terakhir **apa pun statusnya** (termasuk pending/ditolak) dan tidak memperhitungkan PQA |
| F3 | Ranking "alat paling sering dipinjam" dikelompokkan berdasarkan **nama saat pinjam**, sehingga barang yang sama dengan nama berbeda terhitung terpisah (mis. "Meteran Laser 70M" vs "Meteran Laser 70M 01") |
| F4 | PQA yang **Ditolak** hilang dari tampilan (tidak masuk filter mana pun) |
| F5 | Email hanya ke **satu alamat admin hardcoded**. Staff tidak menerima notifikasi disetujui/ditolak |
| F6 | Polling 10 detik mengambil semua data. Tab form tidak pernah ter-refresh |
| F7 | `alert()`/`confirm()`, tanpa pencarian, filter, paginasi, skeleton, atau toast |
| F8 | Logout = reload halaman. Tidak ada audit log; tidak tercatat siapa yang menyetujui |
| F9 | Admin tidak punya menu ganti password dan tidak bisa mengelola admin lain |

---

## 2. Daftar seluruh fitur dan menu

### 2.1 Login
Pilih peran **Staff/Admin**, username (dibuat lowercase), password, Enter untuk submit. Login gagal jika peran yang dipilih tidak sama dengan peran akun.

### 2.2 STAFF
| Tab | Fungsi |
|---|---|
| 📋 **Riwayat** | Statistik pribadi Aktif/Pending/Selesai. Semua transaksi miliknya (terbaru di atas), dengan badge Menunggu/Aktif/Selesai/Ditolak. Transaksi *pending* punya tombol **Ganti Unit Alat**. |
| ➕ **Pinjam Baru** | Diblokir bila masih punya transaksi *approved*. Keranjang multi-barang (tanpa duplikat, dengan preview foto). **Tanggal Pinjam*** (default hari ini; jam diambil dari perangkat), **Tim**, **Lokasi*** , **Keterangan**. |
| ↩️ **Kembali** | Transaksi *approved* miliknya → **Konfirmasi Pengembalian Gudang**. |
| 🔒 **Akun** | Ganti password (lama, baru min. 4, konfirmasi). |
| ⚡ **Tracker PQA** | Ajukan pasang, ajukan tambah hari, ajukan cabut. |

### 2.3 ADMIN
| Tab | Fungsi |
|---|---|
| 📋 **Permohonan** | Statistik Perlu Review/Aktif/Selesai. Daftar *pending* dengan **Setujui**/**Tolak**. |
| 🗂️ **Riwayat** | Semua transaksi non-pending (approved, returned, rejected). |
| 📊 **Rekap** | Filter bulan + tahun (tahun tunggal atau rentang `2025-2026`), atau semua data. Menampilkan total, selesai, aktif, rincian barang aktif + peminjam, dan ranking alat. **Laporan Excel** (lihat §2.5). |
| 📦 **Master Barang** | Tambah barang (ID, nama, foto, kategori, Kode Ref). Grid dengan status dan tombol **Hapus**. |
| 👥 **Staff** | Daftar staff, **Hapus** (ditolak jika staff masih punya transaksi *approved*), **Tambah** (inisial otomatis dari huruf pertama tiap kata, maks. 2). |
| ⚡ **Tracker PQA** | Setujui/tolak pasang, tambah hari, dan cabut. |

### 2.4 Email otomatis (ke `wahyuardhana00@gmail.com`)
| Kejadian | Subjek | Isi |
|---|---|---|
| Staff mengajukan peminjaman | `[INVENTORY HME] Permohonan Baru` | nama pembawa, daftar barang (nama + ID), lokasi |
| Staff mengonfirmasi pengembalian | `[INVENTORY HME] Barang Kembali` | "<peminjam> telah mengembalikan <barang>" |

Tidak ada email untuk approve/reject maupun semua aksi PQA.

### 2.5 Laporan Excel ("Laporan_Stok_HME_<bulan>_<tahun>")
Snapshot **seluruh master barang** saat ini, tidak terpengaruh filter:

| No | ID Barang | Foto Alat | Nama Alat | Jumlah | Status | Peminjam Terakhir | Keterangan |
|---|---|---|---|---|---|---|---|
| urut | | gambar | | `0` jika Dipinjam, selain itu `1` | Tersedia/Dipinjam | dari transaksi terakhir barang itu | Dipinjam → "Dibawa ke <lokasi> (Ket: <ket>)"; Tersedia + ada riwayat → "Di Gudang (Terakhir kembali dari <lokasi>)"; tanpa riwayat → "Di Gudang" |

---

## 3. Alur kerja & katalog aturan bisnis

### 3.1 Peminjaman
```
STAFF                                              ADMIN
Punya transaksi approved? ─ya─► diblokir
     │tidak
Keranjang barang (Tersedia & tidak sedang pending/approved)
     ▼ saveMultipleLoans
N baris [pending], No = max(No)+1 …    ──email "Permohonan Baru"──► Permohonan
     │ (Ganti Unit selama pending)                     ┌────┴────┐
     │                                              Setujui     Tolak
     │                                                 │           │
     │                                 [approved] + master "Dipinjam"   [rejected] (baris tetap ada,
     ▼                                                 │                   master tidak berubah)
Kembali: Konfirmasi ◄──────────────────────────────────┘
     ▼ saveLoan(returned)
[returned] + Tanggal kembali = waktu server + master "Tersedia" + email "Barang Kembali"
```

### 3.2 Tracker PQA
```
Ajukan (savePQAPasang) ─► [Pending]  Tanggal Pasang = waktu pengajuan; master unit = "Dipinjam"
   ├─ admin tolak ─► [Ditolak], master "Tersedia"  (tidak tampil di UI)
   └─ admin setuju ─► [Terpasang], Tanggal Pasang DITIMPA = waktu persetujuan
          ├─ staff req +N hari ─► Req Tambah = N ─┬─ setuju: Target += N, Req = 0
          │                                     └─ tolak:  Req = 0
          └─ staff req cabut (+Tim Cabut) ─► [Req Cabut]
                 ├─ admin tolak ─► [Terpasang], Tim Cabut dikosongkan
                 └─ admin setuju ─► [Dicabut], Tanggal Cabut = sekarang,
                                    Durasi = floor((cabut − pasang) / 24 jam), master "Tersedia"
Durasi saat Terpasang/Req Cabut dihitung live: floor((sekarang − pasang) / 24 jam)
```
Rumus durasi sudah dicocokkan dengan **5 dari 5** baris data.

### 3.3 Katalog aturan bisnis (acuan implementasi & testing)
Kolom "Sistem baru": **=** dipertahankan apa adanya · **⇧** dipertahankan, tetapi kini dipaksakan di server/database · **✎** perubahan yang perlu persetujuan Anda (§13).

| ID | Aturan sistem lama | Sistem baru |
|---|---|---|
| BR-01 | Login = username (case-insensitive) + password + peran yang dipilih harus cocok | = (peran dicek di server) |
| BR-02 | Staff yang punya transaksi *approved* tidak boleh mengajukan peminjaman baru. Transaksi *pending* tidak memblokir. | ⇧ |
| BR-03 | Barang bisa dipinjam jika `status_master = Tersedia` **dan** tidak sedang *pending/approved* di transaksi lain | ⇧ (unique key di DB + row lock) |
| BR-04 | Satu pengajuan boleh berisi banyak barang. Barang yang sama tidak boleh dua kali dalam satu pengajuan | ⇧ |
| BR-05 | Lokasi wajib. Tanggal pinjam wajib, default hari ini. Tim dan keterangan opsional | ⇧ |
| BR-06 | Setiap barang menjadi satu transaksi `pending` dengan ID `HME-<ms>-<idx>` dan nomor urut `max(No)+1` | = (ID dan nomor dibuat server; tambahan grup pengajuan) |
| BR-07 | Selama *pending*, unit bisa diganti dengan barang lain yang tersedia | ⇧ (hanya oleh pemilik) |
| BR-08 | Hanya admin yang bisa menyetujui/menolak transaksi *pending* | ⇧ |
| BR-09 | Approve → `master = Dipinjam`. Reject → status `rejected`, master tidak berubah | = |
| BR-10 | Pengembalian dikonfirmasi staff pemilik (tanpa verifikasi admin) → `returned`, tanggal kembali = waktu server, `master = Tersedia` | = **✎ Q4** |
| BR-11 | Tidak ada pembatalan *pending* dan tidak ada perubahan setelah *approved* | = **✎ Q5** |
| BR-12 | Email ke admin saat ada pengajuan baru dan saat pengembalian | = (alamat dikonfigurasi) **✎ Q8** |
| BR-13 | ID barang unik (dari form), wajib nama, kategori dari 6 pilihan, foto dan Kode Ref opsional. Barang baru = Tersedia | ⇧ (cek ID duplikat) |
| BR-14 | Hapus barang = hapus permanen | **✎ Q3** (nonaktifkan) |
| BR-15 | Tambah staff: username unik lowercase, nama, password awal; role selalu `staff`; inisial = huruf pertama tiap kata, maks. 2 | ⇧ |
| BR-16 | Hapus staff ditolak jika masih punya transaksi *approved* | ⇧ **✎ Q3** |
| BR-17 | Ganti password: password lama benar, baru min. 4 karakter, konfirmasi sama | ⇧ **✎ Q7** |
| BR-18 | Unit PQA = barang yang namanya mengandung "pqa" | **✎ Q6** |
| BR-19 | Unit PQA tidak bisa diajukan pasang jika sedang Pending/Terpasang/Req Cabut | ⇧ |
| BR-20 | Pengajuan PQA: unit, PPIC, lokasi, tim pasang wajib; target hari > 0 | ⇧ |
| BR-21 | Pengajuan PQA langsung membuat unit "Dipinjam"; ditolak → "Tersedia" | = |
| BR-22 | Disetujui → Terpasang, tanggal pasang = waktu persetujuan | = |
| BR-23 | Tambah hari: N > 0, satu permintaan menunggu dalam satu waktu; setuju → target += N | ⇧ |
| BR-24 | Request cabut: tim cabut wajib; tidak bisa jika permintaan tambah hari masih menunggu | ⇧ |
| BR-25 | Cabut disetujui → Dicabut, durasi = floor(hari), unit "Tersedia". Ditolak → kembali Terpasang | = |
| BR-26 | Rekap: hanya transaksi dari aplikasi (punya ID), difilter berdasarkan bulan/tahun **tanggal pinjam**, tahun bisa tunggal, rentang, atau semua; menghitung total, approved, returned, pending, rejected, ranking per **nama alat**, daftar aktif | = (paritas angka diuji) |
| BR-27 | Data manual tanpa ID **tidak** tampil di aplikasi dan **tidak** dihitung di rekap | = **✎ Q1** |
| BR-28 | Laporan Excel = snapshot stok master (§2.5) | = **✎ Q9** |
| BR-29 | **[Perbaikan bug D8]** Unit yang sedang dipinjam biasa tidak bisa diajukan PQA, dan sebaliknya. Status master = Dipinjam jika ada pinjaman *approved* **atau** PQA Pending/Terpasang/Req Cabut | **✎ Q6** |

---

## 4. Struktur spreadsheet & relasi data

### 4.1 Sheet
| Sheet | Dipakai kode? | Baris data | Isi |
|---|---|---|---|
| `Data_Users` | ✅ | 16 (1 admin, 15 staff) | Akun |
| `Master_Barang` | ✅ | 19 | Barang yang bisa dipinjam |
| `Data Barang Masuk-Keluar` | ✅ | 147 transaksi aplikasi + 249 manual + 1 pemisah | Peminjaman |
| `Data_PQA` | ✅ | 5 (semua Dicabut) | Pemasangan PQA |
| `Nama Barang Elektronik` | ❌ tidak dirujuk `Code.gs` | 62 + 17 | Register inventaris fisik manual (jumlah, lokasi, kondisi) |

### 4.2 Kolom (akses berdasarkan posisi)
**`Data_Users`**: A Username · B Password (plaintext) · C Role · D Nama Lengkap · E Label

**`Master_Barang`**: A ID Barang · B Nama Barang · C URL Foto (`lh3.googleusercontent.com/d/<id>`, dinormalisasi dengan regex `[-\w]{25,}`) · D Kategori · E Status (kosong = Tersedia) · F Kode Ref (semua kosong)

**`Data Barang Masuk-Keluar`**
| Kol | Header | Aplikasi (147) | Manual (249) |
|---|---|---|---|
| A | No | `max+1`, 243–390 | 1–242 (+357), 8 nomor ganda |
| B | Nama Alat | snapshot nama barang | teks bebas |
| C | Nama Pembawa | = Nama Lengkap user | teks bebas, 23 nama (banyak bukan user) |
| D | Tanggal di bawa | `dd/mm/yyyy HH.MM` (jam perangkat staff) | campur |
| E | Tanggal kembali | `dd/mm/yyyy HH.MM` (jam server) / kosong | campur + teks status |
| F–H | Tim · Lokasi · Keterangan | teks | teks |
| I | Status | returned 145, approved 2 | kosong |
| J | ID Pinjam | `HME-<ms>-<idx>` (penanda transaksi aplikasi) | kosong |
| K | Nama Akun | username | kosong |
| L | ID Barang | → master | kosong |
| O | (tanpa header) | — | 2 catatan |

**`Data_PQA`**: A ID PQA · B Lokasi · C PPIC · D Tim Pasang · E Tanggal Pasang · F Status (`Pending/Terpasang/Req Cabut/Dicabut/Ditolak`) · G Tim Cabut · H Tanggal Cabut · I Durasi Aktual · J Target Hari · K Req Tambah Hari

### 4.3 Relasi
```
Data_Users.A (username) ─1:N─► Masuk-Keluar.K        100% valid
Master_Barang.A (ID)    ─1:N─► Masuk-Keluar.L        11 baris yatim (HLMP-01/02 terhapus)
Master_Barang.A (ID)    ─1:N─► Data_PQA.A            100% valid
Master_Barang.E (status) ◄── diturunkan dari transaksi + PQA (konsisten saat ini: 2 Dipinjam = 2 approved)
```

---

## 5. Rancangan database MySQL — v1.0

MariaDB 10.4+/MySQL 5.7+, InnoDB, `utf8mb4_unicode_ci`, zona waktu Asia/Jakarta.

### 5.1 ERD
```
users ─1:N─ loan_batches ─1:N─ loans ─N:1─ items ─N:1─ categories
  │                                          │
  │                                          └─1:N─ pqa_installations ─1:N─ pqa_requests
  ├─1:N─ activity_logs     ├─1:N─ notifications     ├─1:N─ login_attempts
legacy_manual_loans · legacy_inventory_register        (arsip, read-only)
change_events (feed realtime) · settings · migration_runs ─1:N─ migration_issues
```

### 5.2 Data dictionary

**users**
| Kolom | Tipe | Asal / keterangan |
|---|---|---|
| id | INT UNSIGNED PK AI | |
| username | VARCHAR(50) UNIQUE | A, lowercase |
| password_hash | VARCHAR(255) | `password_hash(B)` |
| name | VARCHAR(100) | D (di-trim) |
| initials | VARCHAR(4) | E |
| role | ENUM('admin','staff') | C |
| must_change_password | TINYINT(1) | 1 untuk semua hasil migrasi |
| is_active | TINYINT(1) | |
| last_login_at, created_at, updated_at, deleted_at | DATETIME | |

**categories**: id · name UNIQUE · sort_order. Seed: Alat Ukur, Alat Berat, Drone, Toolkit, Solar Panel, Lain-lain.

**items**
| Kolom | Tipe | Asal / keterangan |
|---|---|---|
| id | INT UNSIGNED PK | |
| code | VARCHAR(50) UNIQUE | A |
| name | VARCHAR(150) | B |
| category_id | FK categories | D |
| photo_path | VARCHAR(255) NULL | file di `storage/uploads/items` |
| photo_legacy_url | VARCHAR(500) NULL | C |
| finance_ref | VARCHAR(100) NULL | F |
| is_pqa | TINYINT(1) | migrasi: `name LIKE '%pqa%'` (Q6) |
| master_status | ENUM('Tersedia','Dipinjam') | E. Ditulis ulang oleh service di transaksi yang sama (BR-09/21/25/29) |
| created_at, updated_at, deleted_at | DATETIME | `HLMP-01/02` dibuat ulang dengan `deleted_at` terisi |

**loan_batches**: id · legacy_prefix (`HME-<ms>`, UNIQUE NULL) · user_id FK · borrowed_at · team · location · notes · created_at

**loans**
| Kolom | Tipe | Asal / keterangan |
|---|---|---|
| id | INT UNSIGNED PK | |
| legacy_id | VARCHAR(40) UNIQUE NULL | J |
| seq_no | INT UNIQUE | A (`max+1`, BR-06). Duplikat hanya ada di data manual, sehingga aman |
| legacy_row | INT NULL | nomor baris sheet |
| batch_id | FK loan_batches | |
| user_id | FK users | K |
| item_id | FK items | L |
| item_name_snapshot | VARCHAR(150) | B (dasar ranking BR-26) |
| carrier_name_snapshot | VARCHAR(100) | C |
| borrowed_at | DATETIME | D |
| returned_at | DATETIME NULL | E |
| team, location, notes | VARCHAR/TEXT | F, G, H |
| status | ENUM('pending','approved','rejected','returned') | I |
| decided_by, decided_at, returned_by | FK/DATETIME NULL | baru |
| active_item_key | INT UNSIGNED **STORED GENERATED** `IF(status IN('pending','approved'), item_id, NULL)` **UNIQUE** | mencegah double-booking (D7) |
| created_at, updated_at | DATETIME | |

**pqa_installations**
| Kolom | Tipe | Asal |
|---|---|---|
| id | PK | |
| legacy_row | INT NULL | nomor baris sheet |
| item_id | FK items | A |
| requested_by | FK users NULL | baru (D11) |
| location, ppic, install_team | VARCHAR | B, C, D |
| submitted_at | DATETIME | baru (BR-22 menimpa tanggal pasang, jadi waktu pengajuan disimpan terpisah) |
| installed_at | DATETIME NULL | E |
| status | ENUM('Pending','Terpasang','Req Cabut','Dicabut','Ditolak') | F |
| removal_team | VARCHAR NULL | G |
| removed_at | DATETIME NULL | H |
| total_days | INT NULL | I (dibekukan saat Dicabut) |
| target_days | INT | J |
| pending_extension_days | INT DEFAULT 0 | K |
| decided_by, decided_at | | baru |
| active_item_key | STORED GENERATED UNIQUE (Pending/Terpasang/Req Cabut) | BR-19 |

**pqa_requests** (riwayat permintaan, baru): id · installation_id · type ENUM('extend','remove') · days · team · status ENUM('pending','approved','rejected') · requested_by/at · decided_by/at

**legacy_manual_loans** (arsip): id · source_row · legacy_no · item_text · carrier_text · borrowed_raw · borrowed_at NULL · returned_raw · returned_at NULL · return_note · team · location · notes · extra_note · parse_flag ENUM('ok','swapped_dm','needs_review','unparseable')

**legacy_inventory_register** (arsip): id · section · source_row · no · name · qty · unit · storage_location · condition_text · current_position · note

**settings**: key/value (email admin, interval realtime, dll.) supaya alamat email tidak di-hardcode.

**activity_logs** (user_id, action, entity_type, entity_id, before_json, after_json, ip, user_agent, created_at) · **change_events** (id BIGINT, entity_type, entity_id, action, actor_id, created_at) · **notifications** (user_id, type, title, body, link, read_at, created_at) · **login_attempts** (username, ip, success, created_at) · **migration_runs/issues** (run, sheet, row, category, message, raw_json)

### 5.3 Ketersediaan barang (pusat konsistensi)
Semua aksi yang mengubah status barang (ajukan, approve, reject, return, ganti unit, ajukan/tolak/cabut PQA) dijalankan dalam satu transaksi dengan urutan:
1. `SELECT … FROM items WHERE id IN (…) FOR UPDATE`, sehingga aksi paralel terhadap barang yang sama diantrikan.
2. Cek aturan (BR-02/03/19/29).
3. Tulis perubahan + `master_status` + `activity_logs` + `change_events`.
4. Commit. Unique key `active_item_key` menjadi lapisan pengaman terakhir.

---

## 6. Rancangan arsitektur PHP Native

```
Peminjaman_hme/
├── public/                  ← document root (satu-satunya yang diekspos)
│   ├── index.php            ← front controller halaman
│   ├── api.php              ← front controller API JSON /api/v1/*
│   ├── .htaccess
│   └── assets/{css,js,img,fonts}
├── app/
│   ├── Core/                ← Router, Request, Response, Database(PDO), Session, View, Validator, Csrf
│   ├── Config/              ← membaca .env (development / production)
│   ├── Middleware/          ← Auth, Role, Csrf, RateLimit, ForcePasswordChange
│   ├── Controllers/{Web,Api}/
│   ├── Services/            ← AuthService, LoanService, PqaService, InventoryService, UserService,
│   │                          ReportService, RealtimeService, AuditService, NotificationService,
│   │                          MailService, ImportService
│   ├── Models/              ← repository per tabel (PDO prepared statements)
│   ├── Helpers/
│   └── Views/               ← layout, partial, halaman (tanpa business logic)
├── database/{migrations,seeds}/ + migrate.php
├── tools/import/            ← importer XLSX (CLI + UI admin)
├── storage/{uploads,logs,cache,imports,exports}   ← tidak bisa diakses web
├── tests/
└── docs/
```
- Business logic **hanya di Services**. Controller tipis; View hanya menampilkan.
- Setiap endpoint: `Auth` → `Role` → `Csrf` (non-GET) → validasi + cek kepemilikan di Service.
- Frontend: shell PHP + **vanilla JS ES modules** (tanpa build step), store kecil, render per komponen.
- **Composer hanya untuk PhpSpreadsheet** (import XLSX lama dan export laporan); `vendor/` ikut di-deploy (Q10).
- **Email:** PHPMailer via SMTP (Q8), dikirim setelah commit agar kegagalan email tidak menggagalkan transaksi. Kegagalan dicatat di log.
- Untuk XAMPP di subfolder, `.htaccess` di root meneruskan request ke `public/` dan menolak akses ke folder lain.

### 6.1 Keamanan
| Ancaman | Penanganan | Menutup temuan |
|---|---|---|
| Password | `password_hash`/`verify`/`needs_rehash`; wajib ganti saat login pertama | S1, S5 |
| Autentikasi/otorisasi | Sesi server + middleware role + cek kepemilikan | S2, S3, S4, S10 |
| SQLi | PDO prepared, `EMULATE_PREPARES=false` | |
| XSS | escape output PHP & JS, CSP | S9 |
| CSRF | token di header `X-CSRF-Token`, cookie `SameSite=Lax` | |
| Sesi | `HttpOnly`, `Secure`, regenerate saat login/ganti password, idle timeout, logout di server | |
| Brute force | rate limit per username + IP | |
| Upload | `finfo`, whitelist jpg/png/webp, maks. 3 MB, nama acak, re-encode GD, simpan di luar `public/` | S8 |
| Laporan | dibuat on-the-fly dan diunduh langsung (tidak ada file publik) | S7 |
| Default credential | tidak ada; admin awal dibuat lewat skrip instalasi | S6 |
| Audit | semua aksi tulis → `activity_logs` | F8 |

---

## 7. Strategi realtime

**Change-feed + adaptive short-polling (default), SSE opsional.**
1. Setiap aksi tulis menambah baris `change_events` di transaksi yang sama.
2. `GET /api/v1/sync?since=<id>` → **204** jika tidak ada perubahan (satu query indexed + `session_write_close()`), atau **delta** entitas yang berubah.
3. Interval adaptif: **1,5 detik** saat tab aktif → 5 detik saat idle > 1 menit → 15–30 detik saat tab tersembunyi. Langsung sync saat tab kembali fokus.
4. `BroadcastChannel` + leader election: satu poller per browser.
5. `REALTIME_MODE=sse` untuk server yang mendukung, dengan fallback otomatis ke polling.
6. **Input pengguna aman:** hanya daftar, kartu, dan statistik yang di-patch; form tidak di-render ulang. Barang di keranjang yang diambil orang lain memunculkan peringatan inline.
7. Toast realtime + lonceng notifikasi: admin menerima "pengajuan baru", "barang kembali", dan permintaan PQA; staff menerima "disetujui/ditolak" (notifikasi di aplikasi, bukan email, kecuali disetujui di Q8).

Beban: 20 pengguna aktif ≈ 13 request/detik, mayoritas 204. Aman untuk shared hosting.

---

## 8. Strategi migrasi data

Sumber tidak pernah diubah. Migrasi read-only, idempotent, dengan *dry-run*, SHA-256 file sumber dicatat. File ekspor disimpan **di luar `htdocs`** karena berisi password.

| Sumber | Tujuan | Aturan |
|---|---|---|
| `Data_Users` | `users` | trim, lowercase, hash password, `must_change_password=1` |
| `Master_Barang` | `categories`, `items` | normalisasi URL foto (regex lama), unduh foto, status apa adanya |
| (yatim) | `items` terhapus | `HME-HLMP-01/02`, nama dari transaksi terakhir, `deleted_at` diisi |
| Masuk-Keluar ber-ID (147) | `loan_batches` (59), `loans` | parse `dd/mm/yyyy HH.MM`, batch = prefix `HME-<ms>` |
| Masuk-Keluar manual (249) | `legacy_manual_loans` | lewati baris pemisah 250; parser di bawah |
| `Data_PQA` (5) | `pqa_installations` | `installed_at` = E, `submitted_at` = NULL (tidak tercatat), `total_days` dihitung ulang = I |
| `Nama Barang Elektronik` | `legacy_inventory_register` | dua bagian dengan pemetaan kolom berbeda |
| Foto Drive | `storage/uploads/items` | **3 berhasil diunduh; 16 privat.** Butuh folder `HME_Inventory_Images` dibagikan "Anyone with link (Viewer)" atau diunduh sebagai ZIP (§13-A) |

**Parser tanggal data manual:**
1. Teks `d/m/yyyy[ H(:|.|;)MM]` → tanggal format Indonesia.
2. Sel bertipe tanggal (salah-konversi Sheets) → **tukar bulan↔hari**, flag `swapped_dm`.
3. Validasi: kembali ≥ bawa, dan konsisten dengan baris di sekitarnya (± 60 hari). Pelanggaran → `needs_review`, **tidak dikoreksi otomatis**.
4. Teks non-tanggal → NULL + teks asli disimpan (`return_note` untuk "Pindah lokasi" dll.).

**Rekonsiliasi (wajib cocok 100%):**
| Pemeriksaan | Nilai sumber |
|---|---|
| Users / admin / staff | 16 / 1 / 15 |
| Items aktif / Tersedia / Dipinjam / dibuat ulang (terhapus) | 19 / 17 / 2 / 2 |
| Loans / returned / approved / batches | 147 / 145 / 2 / 59 |
| Loans per akun | naufal 58, rian 27, victor 19, yudi 17, rika 7, vega 6, jefri 5, sandi 5, vicky 3 |
| Loans per barang | MTRB-01 21, DRN-01 18, MTRL-02 18, MTRL-01 15, MTRL-03 9, DRN-02 9, … (19 ID) |
| `master_status` = turunan transaksi + PQA | HLMP-03, HLMP-04 |
| **`getMonthlyStats` lama vs ReportService baru** | dihitung untuk setiap bulan Apr–Nov 2026, rentang 2025-2026, dan "semua": total/approved/returned/pending/rejected/topItems/activeList harus identik |
| **Laporan Excel lama vs baru** | 19 baris, kolom Jumlah/Status/Peminjam Terakhir/Keterangan identik |
| Arsip manual / pemisah dilewati | 249 / 1 |
| PQA / durasi | 5 / 9, 14, 80, 8, 9 |
| Register inventaris | 62 + 17 |

Sistem ini tidak memiliki nominal/saldo uang. Padanan "total & saldo" adalah jumlah, status, dan stok di atas.

**Laporan migrasi:** berhasil / gagal / duplikat / perlu diperiksa per baris (sheet + nomor baris), tersedia di halaman admin dan sebagai XLSX.

**Cut-over:** umumkan freeze → nonaktifkan deployment Apps Script (atau setel read-only) → ekspor final → migrasi → rekonsiliasi → go-live → semua user ganti password.

---

## 9. Arah desain UI/UX (referensi Anda)

Yang diambil dari referensi adalah bahasa visual dan pola interaksi. Ilustrasi 3D milik studio pembuatnya **tidak** disalin; gantinya foto barang HME dan ikon.

| Elemen referensi | Penerapan HME |
|---|---|
| Latar abu terang, kartu putih radius besar, bayangan halus | semua kartu |
| Header lokasi + lonceng dengan titik oranye | "Halo, Naufal" + peran + lonceng notifikasi realtime |
| Search pill + tombol bulat hitam | cari barang/transaksi + tombol filter |
| Dua kartu aksi cepat | Staff: **Pinjam Barang**, **Pasang PQA**. Admin: **Permohonan (n)**, **Tambah Barang** |
| "Current Shipment" + stepper titik-titik oranye | **Pinjaman Aktif**: Diajukan → Dipinjam → Dikembalikan (3 tahap: "disetujui" dan "dipinjam" adalah satu status `approved`). **PQA**: Diajukan → Terpasang (X/Y hari) → Req Cabut → Dicabut |
| Chip status pill | Menunggu / Aktif / Selesai / Ditolak / Terpasang / Dicabut |
| Daftar "Recent Shipment" | Riwayat: thumbnail foto + ID + nama + chip |
| **Bottom nav hitam melayang berbentuk pil + "+" oranye di tengah** | Staff: Beranda · Riwayat · **(+) Pinjam** · PQA · Profil. Admin: Beranda · Permohonan · **(+)** · Barang · Lainnya (Rekap, Staff, Arsip, Log) |
| Detail sebagai bottom-sheet gelap (grid label/nilai + stepper) | Detail transaksi/PQA dengan aksi di bagian bawah |
| Bar kurir + tombol aksi bulat | Bar peminjam + tombol ✓ setujui / ✕ tolak (admin) |

**Token awal:** aksen oranye HME `#F26B1D` · ink `#141414` · surface `#FFFFFF` · background `#F2F2F2` · muted `#8A8A8A` · success `#1F9D55` · danger `#E5484D`. Font Inter (atau Plus Jakarta Sans) dengan skala 12/14/16/20/28. Spacing kelipatan 4. Radius 12/20/28/pill. Area sentuh ≥ 44px. Mode gelap disiapkan.
**Breakpoint:** ≤599 HP (bottom nav) · 600–1023 tablet (grid 2 kolom) · ≥1024 desktop (sidebar gelap; detail sebagai panel samping).
Desain dikunci setelah 3 mockup (Beranda staff, Permohonan admin, Detail sheet) Anda setujui di Tahap 3.

---

## 10. Pemetaan fitur lama → baru

| Fitur lama | Sistem baru | Status |
|---|---|---|
| Login Staff/Admin | Login + sesi server, wajib ganti password pertama kali | dipertahankan + aman |
| Riwayat staff + stat | Beranda + Riwayat (cari/filter) | dipertahankan |
| Pinjam Baru (keranjang) | Form keranjang dengan foto, ketersediaan realtime | dipertahankan |
| Ganti Unit Alat | sama, khusus pemilik | dipertahankan |
| Kembali | sama | dipertahankan |
| Akun (ganti password) | Profil; tersedia juga untuk admin | dipertahankan (+ admin, Q7) |
| Permohonan / Riwayat admin | sama + filter | dipertahankan |
| Rekap + Laporan Excel | sama, angka identik | dipertahankan (Q9) |
| Master Barang | CRUD + edit (lama: tidak ada edit) | dipertahankan, edit menunggu persetujuan (Q3) |
| Staff | sama | dipertahankan |
| Tracker PQA (semua aksi) | sama + riwayat permintaan | dipertahankan |
| Email admin | SMTP | dipertahankan (Q8) |
| — | Audit log, notifikasi realtime, arsip manual | baru |

---

## 11. Risiko

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | Password lama sudah terekspos | Kritis | hash + wajib ganti + rotasi saat go-live |
| R2 | Tanggal manual salah-konversi | Tinggi | parser + flag, tanpa koreksi otomatis |
| R3 | 16 foto privat di Drive | Sedang | bagikan folder atau kirim ZIP; fallback placeholder |
| R4 | Pencatatan manual di sheet selama transisi | Sedang | importer idempotent, freeze saat cut-over |
| R5 | Perbedaan angka rekap lama vs baru | Sedang | uji paritas otomatis (§8) |
| R6 | Hosting/SMTP belum diketahui | Sedang | kompatibel PHP 8.0+, polling default, SMTP via `.env` |
| R7 | Pengguna terbiasa dengan alur lama | Rendah | istilah dan alur dipertahankan |

---

## 12. Roadmap & testing

| Tahap | Isi | Model | Uji wajib |
|---|---|---|---|
| 1–2 | Audit & planning (dokumen ini) | Opus | Persetujuan Anda |
| 3 | Design system + 3 mockup | Sonnet | Review di 360 / 768 / 1280px |
| 4a | Fondasi: router, PDO, sesi, CSRF, middleware, migrasi SQL | Sonnet | Unit test core |
| 4b | Auth & user | Sonnet | BR-01/15/16/17; staff → API admin = 403; rate limit |
| 4c | Inventaris + upload | Sonnet | BR-13/14; validasi upload |
| 4d | Peminjaman | Sonnet | BR-02…12; 2 request paralel pada barang yang sama → hanya 1 berhasil |
| 4e | PQA | Sonnet | BR-18…25, 29; durasi = rumus lama |
| 4f | Rekap & Excel | Sonnet | BR-26/28; paritas angka |
| 4g | Audit log, notifikasi, email | Sonnet | setiap aksi tercatat |
| 5 | Frontend + realtime | Sonnet | 2 browser / 2 akun: latensi ≤ 2 detik, form tidak ter-reset |
| 6 | Migrasi | Sonnet + review Opus | rekonsiliasi §8 100% |
| 7 | Testing menyeluruh | Sonnet | functional, CRUD, auth, authz, security (SQLi/XSS/CSRF/IDOR/upload), realtime, responsive, konsistensi, error |
| 8 | Finalisasi | Sonnet | `database.sql`, panduan XAMPP & hosting, backup/restore |

---

## 13. Kebutuhan & keputusan dari Anda

### A. Akses/file
1. **Foto barang:** bagikan folder Drive `HME_Inventory_Images` sebagai *Anyone with the link – Viewer*, **atau** unduh folder itu sebagai ZIP dan simpan di `C:\xampp\hme_legacy\`.
2. Konfirmasi tidak ada file `.gs` lain atau trigger terjadwal.
3. Pastikan spreadsheet sudah kembali *Restricted*.

### B. Keputusan bisnis (default = rekomendasi saya bila Anda setuju)
| # | Pertanyaan | Rekomendasi |
|---|---|---|
| Q1 | 249 catatan manual (Apr 2025–Apr 2026): dijadikan **arsip read-only** yang bisa dicari admin, tetap **tidak** masuk rekap (sama seperti sekarang)? | Ya |
| Q2 | Sheet "Nama Barang Elektronik": hanya diarsipkan, atau barang-barangnya ingin dijadikan master barang yang bisa dipinjam? | Arsip dulu; admin bisa memindahkan ke master satu per satu nanti |
| Q3 | Hapus barang/staff diganti **nonaktifkan** (riwayat tetap utuh)? Admin juga bisa **mengedit** data barang? | Ya, keduanya |
| Q4 | Pengembalian tetap dikonfirmasi staff sendiri tanpa verifikasi admin? | Pertahankan |
| Q5 | Staff boleh **membatalkan** pengajuan yang masih *pending*? | Boleh (tambahan kecil) |
| Q6 | Unit PQA ditandai **flag `is_pqa`** (bukan dari nama), dan aturan BR-29 (unit tidak bisa dipinjam biasa dan dipasang PQA sekaligus) diterapkan? | Ya, keduanya |
| Q7 | Password minimal 8 karakter (lama: 4)? Admin punya menu ganti password dan bisa mengelola akun admin lain? | Ya |
| Q8 | Email tetap ke satu alamat admin (`wahyuardhana00@gmail.com`)? Akun SMTP apa yang dipakai? Staff juga perlu email saat disetujui/ditolak, atau cukup notifikasi di aplikasi? | Alamat bisa diatur; staff cukup notifikasi di aplikasi |
| Q9 | Laporan Excel: pertahankan sheet "Laporan Stok" seperti sekarang **ditambah** sheet "Transaksi Periode" yang mengikuti filter bulan/tahun (perbaikan F1)? "Peminjam Terakhir" hanya dari transaksi *approved/returned* (perbaikan F2)? | Ya |
| Q10 | Composer + PhpSpreadsheet + PHPMailer disetujui? | Ya |
| Q11 | Hosting produksi: penyedia, versi PHP, akses SSH/cron? | — |
| Q12 | Warna aksen oranye HME sesuai referensi? | Ya |

Setelah Q1–Q12 dijawab (cukup "setuju semua rekomendasi" bila sesuai), dokumen dikunci sebagai **spesifikasi final** dan implementasi dimulai dari Tahap 3/4a dengan Claude Sonnet 5.5.

---

## 14. Keputusan final (disetujui 4 November 2026)

Pemilik menyetujui seluruh rekomendasi §13. Bagian ini **mengikat implementasi** dan menggantikan tanda ✎ di §3.3.

| # | Keputusan | Dampak implementasi |
|---|---|---|
| Q1 | 249 catatan manual → **arsip read-only** | Halaman admin "Arsip Manual" (cari + filter + flag tanggal). **Tidak** masuk Rekap (BR-27 tetap). |
| Q2 | "Nama Barang Elektronik" → **arsip** | Halaman admin "Register Inventaris". Tombol **Jadikan Master Barang** membuka form tambah barang yang sudah terisi nama; ID dan kategori diisi admin. |
| Q3 | Hapus → **nonaktifkan**; admin bisa **edit** barang | BR-14: barang punya `deleted_at`/nonaktif, hilang dari form pinjam/PQA, tetap tampil di riwayat; bisa diaktifkan lagi. Nonaktifkan barang ditolak jika barang punya transaksi *pending/approved* atau PQA aktif. BR-16: nonaktifkan staff ditolak jika punya transaksi *pending* atau *approved* (agar tidak ada transaksi yatim); staff nonaktif tidak bisa login. Edit barang: nama, kategori, foto, Kode Ref, `is_pqa`; **ID barang tidak bisa diubah** setelah punya riwayat. |
| Q4 | Pengembalian **tetap** dikonfirmasi staff | BR-10 tidak berubah. |
| Q5 | Staff boleh **membatalkan** pengajuan *pending* | Status baru `cancelled` (badge "Dibatalkan"), hanya oleh pemilik, hanya saat *pending*. `cancelled` **tidak** dihitung di Rekap (total, pending, rejected) agar paritas dengan data lama terjaga. `active_item_key` mengecualikan `cancelled`. Admin mendapat notifikasi. |
| Q6 | Flag **`is_pqa`** + **BR-29** | Migrasi: `is_pqa = name LIKE '%pqa%'`; admin bisa mengubahnya di edit barang. Unit dengan pinjaman *pending/approved* tidak bisa diajukan PQA, dan unit dengan PQA Pending/Terpasang/Req Cabut tidak bisa dipinjam. `master_status = Dipinjam` ⇔ ada loan *approved* atau PQA aktif. Unit PQA tetap boleh dipinjam biasa saat tidak terpasang (sesuai data lama). |
| Q7 | Password min. **8** karakter; admin punya ganti password dan **kelola admin** | Menu Profil untuk semua peran. Halaman "Pengguna" (admin) mengelola staff **dan** admin. Admin tidak bisa menonaktifkan dirinya sendiri, dan minimal harus ada 1 admin aktif. Password migrasi tetap bisa dipakai login sekali, lalu wajib diganti (min. 8). |
| Q8 | Email admin **dapat diatur**; staff cukup **notifikasi in-app** | `settings.admin_notification_email` (default `wahyuardhana00@gmail.com`, bisa beberapa alamat dipisah koma). Email untuk 2 kejadian lama (BR-12). SMTP di `.env`; bila belum dikonfigurasi, email dilewati dan dicatat di log, tanpa menggagalkan transaksi. |
| Q9 | Excel: sheet **"Laporan Stok"** (identik dengan lama) + sheet **"Transaksi Periode"** (mengikuti filter); Peminjam Terakhir hanya dari *approved/returned* | ReportService menghasilkan XLSX untuk diunduh langsung. Sheet 1 kolom sama persis §2.5 (foto sebagai gambar tersemat). Sheet 2: No, ID Pinjam, Tanggal Pinjam, Tanggal Kembali, Peminjam, ID Barang, Nama Alat, Lokasi, Tim, Keterangan, Status. Uji paritas sheet 1 memakai aturan F2 yang sudah diperbaiki; selisih dengan laporan lama didokumentasikan. |
| Q10 | **Composer** dengan `phpoffice/phpspreadsheet` dan `phpmailer/phpmailer` | Versi dikunci di `composer.lock`. `vendor/` ikut paket deployment. |
| Q11 | Hosting: belum diketahui | Target PHP ≥ 8.0, MySQL/MariaDB; `REALTIME_MODE=poll` default. Panduan deployment generik untuk cPanel + VPS. |
| Q12 | Aksen **oranye HME** | Token §9. |

### Masih ditunggu (tidak memblokir pengerjaan)
- Akses folder foto `HME_Inventory_Images` (dibutuhkan saat Tahap 6 migrasi).
- Akun SMTP untuk email (dibutuhkan saat Tahap 4g/8).
- Detail hosting produksi (dibutuhkan saat Tahap 8).

### Urutan eksekusi (Sonnet 5.5, High)
1. **Tahap 3:** design tokens + komponen dasar + 3 mockup HTML statis (Beranda staff, Permohonan admin, Detail sheet). **Berhenti untuk review desain.**
2. **Tahap 4a → 4g:** backend per modul, dengan testing tiap modul sebelum lanjut.
3. **Tahap 5:** frontend + realtime (uji 2 browser).
4. **Tahap 6:** migrasi + rekonsiliasi.
5. **Tahap 7–8:** testing menyeluruh, dokumentasi, paket deployment.

Setiap tahap ditutup dengan catatan perubahan di `docs/CHANGELOG.md` dan hasil uji di `docs/test-reports/`.
