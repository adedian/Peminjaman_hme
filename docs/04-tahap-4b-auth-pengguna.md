# Tahap 4b — Autentikasi & Manajemen Pengguna

Status: **selesai dan teruji** — 152 tes otomatis lulus (dijalankan berulang), ditambah uji visual di browser (HP & desktop, mode terang/gelap).

## 1. Membuat admin pertama
Tidak ada akun/password bawaan. Buat admin dari terminal:
```bash
php tools/create-admin.php --username=admin --name="Admin HME"             # password diminta di terminal (diketik 2×)
php tools/create-admin.php --username=admin --name="Admin HME" --generate  # atau dibuatkan acak, tampil SEKALI
```
Password tidak boleh lewat argumen (`--password=` ditolak karena tersimpan di riwayat shell). Untuk otomasi: variabel lingkungan `HME_ADMIN_PASSWORD`. Admin baru **wajib mengganti password** saat login pertama. Lalu buka `http://localhost/Peminjaman_hme/login`, pilih tab **Admin**.

## 2. Yang dibangun
| Area | Isi |
|---|---|
| Login (BR-01) | Tab Staff/Admin, username tak peka huruf besar, peran harus cocok dengan akun. Formulir biasa (tanpa JS) + `POST /api/v1/auth/login` |
| Sesi | `auth_version` pada akun: password diganti/direset, akun dinonaktifkan, atau peran diubah → **sesi lama langsung gugur** (di semua perangkat). Perangkat yang mengganti password tetap masuk dengan ID sesi baru |
| Pembatasan percobaan | Jendela geser 15 menit: 5 gagal per (username+IP), 20 per IP, 30 per username (serangan terdistribusi). Login benar tetap ditolak selama terkunci (HTTP 429 + `Retry-After`) |
| Pesan gagal | Selalu `Username atau password salah.` — username tak ada, password salah, peran salah, akun nonaktif semuanya identik; `password_verify` selalu dijalankan agar waktu respons tidak membedakan |
| Ganti password (BR-17, Q7) | Password lama harus benar, min. 8 karakter (maks. 72 byte — batas bcrypt), tolak password umum/sama dengan username, harus berbeda dari lama, konfirmasi cocok. Hanya untuk akun sendiri (tanpa parameter id). Menebak password lama dibatasi 5× |
| Wajib ganti password | Akun hasil migrasi/dibuatkan admin/direset hanya bisa membuka halaman ganti password; API lain → `403 password_change_required` (dicek sebelum peran) |
| Kelola pengguna (admin) | Tambah staff **atau admin** (Q7), ubah nama/peran, nonaktifkan/aktifkan (Q3), reset password. Password sementara dibuat sistem (12 karakter, tanpa karakter ambigu) dan ditampilkan **sekali** |
| Aturan bisnis | BR-15 username unik huruf kecil & inisial otomatis · BR-16 tidak bisa dinonaktifkan jika punya pengajuan *pending* / pinjaman *approved* · tidak bisa mengubah/menonaktifkan/mereset akun sendiri · admin aktif terakhir tidak bisa diturunkan/dinonaktifkan |
| Audit | `auth.login`, `auth.login_failed`, `auth.logout`, `auth.password_changed`, `user.created/updated/activated/deactivated/password_reset` — tanpa pernah menyimpan password |
| Realtime | Setiap perubahan pengguna menulis `change_events` (dipakai di Tahap 5) |

## 3. Endpoint
| Method & path | Akses |
|---|---|
| `POST /api/v1/auth/login` | publik (CSRF + cookie sesi tamu) |
| `GET /api/v1/me` · `POST /api/v1/auth/logout` · `POST /api/v1/me/password` | login |
| `GET/POST /api/v1/users` · `PUT /users/{id}` · `POST /users/{id}/activate|deactivate|reset-password` | login + password sudah diganti + **admin** |
| Halaman: `/login` (publik) · `/profile` `/profile/password` (login) · `/` (login+pwd) · `/users` (admin) | |

Setiap endpoint menjaga dirinya sendiri lewat middleware grup (`routes/api.php`); menyembunyikan tombol di UI bukan kontrol akses.

## 4. Bukti pengujian (152 tes)
Baru di 4b: 62 tes — kebijakan password, login, pembatasan percobaan (termasuk jendela geser & serangan terdistribusi), ganti password, kelola pengguna, **17 skenario end-to-end** lewat server nyata dengan klien bercookie, dan CLI `create-admin`.

Skenario E2E penting yang lulus:
- Cookie sesi berganti setelah login; cookie lama / sesi tamu tidak berguna (anti session-fixation); replay cookie setelah logout → 401.
- Token CSRF dirotasi saat login; token tamu/kosong/palsu → 403; Origin asing → 403.
- Staff + CSRF benar ke **semua** endpoint admin → 403 dan **data korban tidak berubah** (akun tidak terbentuk, password utuh).
- `/me/password` mengabaikan `id`/`role` yang disusupkan (tidak ada IDOR/eskalasi).
- Nonaktifkan / reset password / turunkan peran → sesi yang sedang berjalan gugur seketika.
- Ganti password di HP → sesi di laptop gugur.
- Nama berisi `<img onerror>` tampil ter-escape; diverifikasi via DOM (tidak ada elemen/atribut berbahaya).
- Input bertipe array/objek/injeksi → 4xx, tidak pernah 500; `must_change_password`/`is_active` tidak bisa disetel dari input (mass-assignment).

## 5. Bug yang ditemukan tes dan diperbaiki
1. **Input bertipe array (`{"role":["admin"]}`, `{"username":["x"]}`) menyebabkan HTTP 500** (konversi array→string di `Validator` dan `UserService`). Kini ditolak 422 di `Validator` untuk semua aturan.
2. Layout desktop: baris grid meregang mengisi tinggi layar → celah vertikal besar (`align-content: start`).
3. Pesan kesalahan parsing JSON pada API tampil sebagai HTML (sudah di 4a).

## 6. Keputusan implementasi yang perlu diketahui
- Batas password **72 byte** (bcrypt memotong lebih panjang secara diam-diam). Password lama dari migrasi boleh lebih pendek untuk login pertama, tetapi wajib diganti ke kebijakan baru.
- Penonaktifan memakai kunci `FOR UPDATE` sehingga dua admin yang menonaktifkan admin bersamaan tidak dapat meninggalkan sistem tanpa admin.
- Cookie sesi hanya dibuat saat halaman login dibuka atau saat login (pengunjung/bot anonim tidak membuat file sesi).
- Pemicu 429 berbasis IP memakai `REMOTE_ADDR`; di belakang proxy/CDN aktifkan `TRUST_PROXY=true` agar IP asli terbaca.

## 7. Belum ada / tahap berikut
Modul inventaris (4c), peminjaman (4d), PQA (4e), rekap (4f), notifikasi & email (4g). Realtime `/sync` dan frontend lengkap di Tahap 5. Rotasi `session_id` otomatis berkala belum diterapkan (regenerate terjadi saat login/ganti password).
