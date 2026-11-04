# Laporan Uji — Tahap 7: Pengujian menyeluruh

Tanggal: 4 November 2026 · Lingkungan: Windows 11, XAMPP PHP 8.0.30, MariaDB 10.4, Chromium (panel browser) · Perintah: `php tests/run.php`

## 1. Ringkasan
| | |
|---|---|
| Tes otomatis | **307 lulus, 0 gagal** — dijalankan **dua kali berurutan** pada database tes yang dibuat ulang dari nol |
| Tes baru di Tahap 7 | 7 (sapuan seluruh rute, fuzzing, XSS seluruh halaman, error production/DB mati, integritas data) + 1 uji regresi Validator |
| Bug ditemukan & diperbaiki | **2** (lihat §4) + 1 temuan aksesibilitas |
| Database kerja (data hasil migrasi) | `php tools/check-integrity.php` → **13/13 aturan konsisten** |

## 2. Cakupan per kategori (sesuai §12 rencana)
| Kategori | Bukti | Jumlah tes |
|---|---|---|
| **Fungsional** | alur lengkap pinjam → setujui/tolak → kembali/batal/ganti unit; PQA pasang → tambah waktu → cabut; rekap & Excel; notifikasi & email; migrasi | loans 24, pqa 20, reports 7, notify 13, migration 8 + E2E |
| **CRUD** | pengguna (tambah/ubah/nonaktif/reset), barang (tambah/ubah/foto/nonaktif), pengaturan | users 15, items 23, e2e_items 13 |
| **Autentikasi** | login/logout, rate limit, wajib ganti password, sesi (idle/absolut, fixation, versi kredensial) | auth 18, security 21, e2e 29 |
| **Otorisasi** | **matriks seluruh 82 rute** (dibangkitkan dari router): anonim 401/redirect, staff → rute admin 403, admin → rute staff 403; kebijakan statis (publik hanya daftar putih, semua rute lain ber-auth/pwd, rute tanpa peran hanya daftar "bersama") | e2e_sweep 2 + per modul |
| **IDOR** | pengajuan/loan/notifikasi milik orang lain → 404 & tidak berubah; sync tidak membocorkan ID milik orang lain | per modul (loans, notify, sync) |
| **SQL injection** | fuzzing query (17 parameter × 12 payload, termasuk array & byte NUL) pada **setiap GET** dan badan berbahaya (9 jenis) pada **setiap penulisan**, admin & staff — ±2.000 request; tidak ada 5xx, log server tanpa ERROR, tabel utuh | e2e_sweep 2 |
| **XSS** | payload `"><img onerror><script>` di nama pengguna, barang, lokasi, catatan, tim, PQA, arsip → **semua halaman** (admin & staff, termasuk `?open=`, `?new=1&name=`) ter-escape; JS bebas `innerHTML`/`outerHTML`/`insertAdjacentHTML`/`document.write`/`eval` | e2e_sweep 1 + per modul |
| **CSRF** | setiap rute penulisan menolak token salah (403); Origin asing; token dirotasi saat login | e2e_sweep + security |
| **Upload** | validasi isi, polyglot/webshell, ukuran/piksel, re-encode GD, path traversal media | items 23, e2e_items 13 |
| **Formula injection (Excel)** | teks `=…`, `+…`, `@…` di rekap & laporan migrasi tetap string | reports, migration |
| **Realtime** | 204/delta, filter per peran, reset kursor, tidak memperpanjang idle; uji 2 akun di browser (latensi 0,3–1,3 dtk; form tidak ter-reset) | sync 4, e2e_sync 3 + Tahap 5 |
| **Konsistensi** | `IntegrityService` (13 aturan) pada DB bersih dengan alur nyata + 8 jenis kerusakan sengaja → semua terdeteksi; konkurensi (proses paralel) di 4d/4e; rekonsiliasi migrasi | integrity 1, loans/pqa (paralel), migration |
| **Error handling** | 404/405/400/413/422; **production + database mati**: API 503 JSON, health "degraded", tanpa SQLSTATE/PDO/path/credential, header keamanan tetap ada, `APP_DEBUG` diabaikan | http 13, core, e2e_sweep 1 |
| **Responsif** | panel browser 360 / 768 / 1280 px, semua halaman admin & staff (§3) | manual terukur |

## 3. Responsif & aksesibilitas sentuh (panel browser, data tes)
Diukur dengan skrip di halaman: lebar dokumen vs viewport, elemen yang melewati tepi kanan (di luar kontainer gulir), dan elemen interaktif < 40 px.

| Lebar | Halaman | Scroll horizontal | Elemen meluber | Target sentuh kecil |
|---|---|---|---|---|
| 360 px | admin: beranda, permohonan, PQA, barang, rekap, pengguna, arsip (2 tab), migrasi, log, pengaturan, notifikasi, profil · staff: login, beranda, pinjam, riwayat, PQA | tidak ada | tidak ada | "Lihat semua" 18 px → **diperbaiki (44 px)**. Kotak cari & checkbox barang: positif palsu — target sentuhnya label/baris ≥ 48–62 px |
| 768 px | beranda, permohonan, barang, log, migrasi | tidak ada | tidak ada | idem, sudah diperbaiki |
| 1280 px | arsip register (+ sampel lain di tahap sebelumnya) | tidak ada | tidak ada | — |

## 4. Temuan & perbaikan
| # | Temuan | Dampak | Perbaikan |
|---|---|---|---|
| 1 | **Byte NUL pada tanggal** (`from`/`to` di `/api/v1/logs`) → 500. Di PHP 8 `DateTime::createFromFormat` melempar `ValueError` untuk NUL | error 500 (tanpa kebocoran data) | `AuditService` memvalidasi pola `YYYY-MM-DD` sebelum parsing |
| 2 | Bug yang sama pada aturan `date` di `Validator` (dipakai `borrow_date` pengajuan pinjam) | error 500 dari input rusak | pola diperiksa dulu; tes regresi ditambah |
| 3 | Tautan "Lihat semua" di beranda hanya 18 px | sulit disentuh di HP | area sentuh 44 px tanpa mengubah tata letak |
| 4 | DevTools menandai polling 204 sebagai `ERR_ABORTED` | kosmetik (log konsol) | badan respons 204 dibaca |
| — | Klien uji: ±40.000 koneksi per dua run berturut-turut menghabiskan port sementara Windows (*connect timeout*) | hanya infrastruktur tes | klien uji mengulang **hanya** bila koneksi TCP tidak pernah terbentuk (request belum sampai server); batas connect 5 dtk |

## 5. Kinerja (indikatif, `php -S` satu proses, data tes)
| Endpoint | Median | p95 |
|---|---|---|
| `GET /api/v1/sync` (204, tanpa perubahan) | 27 ms | 42 ms |
| `GET /api/v1/requests` (30 terbaru) | 30 ms | 51 ms |
| `GET /api/v1/pqa?group=active` | 24 ms | 40 ms |
| `GET /api/v1/reports/summary` (semua data) | 39 ms | 72 ms |
| `GET /api/v1/reports/export` (XLSX) | 189 ms | 195 ms |
| Beranda admin (HTML) | 25 ms | 47 ms |

Dengan polling 1,5 dtk, 20 pengguna aktif ≈ 13 request/detik yang mayoritas 204 — sesuai perkiraan rencana §7.

## 6. Alat yang ditambahkan
- `php tools/check-integrity.php` — 13 aturan konsistensi (status barang turunan, BR-29, barang/staff nonaktif tanpa transaksi terbuka, tanggal & status pinjaman, pengajuan, penghitung nomor urut, PQA, admin aktif, berkas foto). Hanya membaca; exit 1 bila ada pelanggaran. Disarankan cron mingguan dan setiap selesai restore backup.
- `Router::routes()` — daftar rute untuk tes matriks otomatis (rute baru otomatis ikut diuji dan wajib masuk daftar "bersama" bila tanpa pembatasan peran).

## 7. Di luar cakupan / belum diuji
- **SMTP sungguhan** (akun belum tersedia) — antrean, retry, dan escaping sudah diuji dengan driver log dan server SMTP tertutup.
- **Hosting produksi** (HTTPS, Apache/Nginx sungguhan, cron) — Tahap 8.
- **Browser lain** (Safari iOS, Firefox) — hanya Chromium; fitur yang dipakai (Web Locks, BroadcastChannel) punya fallback bila tidak tersedia.
- **Foto barang hasil migrasi** — menunggu folder Drive dibagikan.
- Uji beban dengan banyak proses (php -S hanya satu proses); disarankan diulang di server produksi.
