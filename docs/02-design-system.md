# Design System HME — v1 (Tahap 3)

Status: **menunggu review pemilik.** Seluruh nilai ada di `design/css/tokens.css`; komponen di `design/css/components.css`. Halaman acuan (buka lewat XAMPP: `http://localhost/Peminjaman_hme/design/`):

| Halaman | Isi |
|---|---|
| `staff-home.html` | Beranda staff: aksi cepat, pinjaman aktif + stepper, riwayat, sheet detail (data asli Naufal) |
| `admin-requests.html` | Permohonan admin: statistik, filter, kartu pengajuan, tabel desktop. Tambahkan `#detail` di URL untuk membuka sheet |
| `components.html` | Galeri: warna, tipografi, chip, tombol, form + validasi, stepper, kartu PQA, skeleton, empty state, toast, dialog |

> Data pada mockup berasal dari spreadsheet asli (Naufal, ID pinjam, barang, lokasi, tanggal). Dua kartu pengajuan "Menunggu" di halaman admin adalah **ilustrasi** (saat ini tidak ada pengajuan yang menunggu) dan diberi label.

## 1. Prinsip
1. **HP dulu.** Semua gaya dasar untuk layar ≤ 599 px; tablet dan desktop hanya menambah (`min-width`).
2. **Satu tangan.** Navigasi utama di bawah (jangkauan jempol), aksi utama = tombol "+" oranye di tengah.
3. **Tidak ada nilai literal.** Warna, jarak, radius, dan ukuran font hanya lewat token.
4. **Kontras dan target sentuh.** Target ≥ 44 px (terverifikasi otomatis di galeri), input 16 px agar iOS tidak zoom.
5. **Gerak seperlunya.** Transisi 120–320 ms; hormati `prefers-reduced-motion`.

## 2. Token
| Kelompok | Nilai |
|---|---|
| Aksen | `#F26B1D` (hover `#D95A10`, soft `#FFF0E6`) — dari logo segi enam HME |
| Netral | bg `#F2F2F2` · surface `#FFFFFF` · ink `#141414` · muted `#8A8A8A` · line `#E8E8E8` |
| Gelap (nav, sheet, sidebar) | `#1A1A1A` / `#262626` |
| Semantik | success `#1F9D55` · warning `#B7791F` · danger `#E5484D` · info `#2F6FDE` (masing-masing punya varian *soft*) |
| Font | Inter 400/500/600/700 · skala 12 / 14 / 16 / 20 / 28 px · angka `tabular-nums` |
| Spacing | 4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 |
| Radius | 12 (kontrol) · 20 (kartu) · 28 (sheet) · pill |
| Mode gelap | otomatis mengikuti sistem; tombol "Ganti tema" menyimpan pilihan (`localStorage`, dibungkus try/catch) |

## 3. Breakpoint & layout
| Lebar | Navigasi | Layout |
|---|---|---|
| **≤ 599** HP | Bottom nav pil gelap + FAB "+" | 1 kolom, gutter 16, sheet dari bawah |
| **600–1023** tablet | Bottom nav (maks. 440 px, di tengah) | gutter 24, grid 2 kolom untuk kartu |
| **≥ 1024** desktop | Sidebar gelap 248 px | gutter 32, tabel padat, detail jadi **panel samping 440 px** |

Navigasi:
- **Staff:** Beranda · Riwayat · **(+) Pinjam** · PQA · Profil.
- **Admin:** Beranda · Permohonan · **(+) Tambah barang** · Barang · Lainnya (Rekap & Laporan, Tracker PQA, Pengguna, Arsip & Log).

## 4. Komponen
`topbar` · `search` (pill + tombol filter bulat) · `quick-card` (aksi cepat) · `card` · `loan-card` · `stepper` (terang & gelap) · `chip` (pending / active / done / rejected / cancelled / info) · `row` (daftar riwayat) · `stat` · `btn` (primary, ghost, success, danger, block) · `icon-btn` / `round-btn` · `field` + `input/select/textarea` (+ `.invalid`) · `bottom-nav` + `fab` · `sidebar` · `sheet` (gelap, HP bawah / desktop kanan) · `toast` · `skeleton` · `empty` · `seg` (filter) · `table` (khusus desktop).

### Pemetaan status → tampilan
| Status sistem | Chip | Stepper |
|---|---|---|
| `pending` | Menunggu (kuning) | Diajukan *(saat ini)* |
| `approved` | **Aktif** (oranye) | Diajukan ✓ → **Dipinjam** *(saat ini)* |
| `returned` | Selesai (hijau) | semua ✓ |
| `rejected` | Ditolak (merah) | berhenti di Diajukan |
| `cancelled` (baru, Q5) | Dibatalkan (abu) | berhenti di Diajukan |
| PQA `Pending / Terpasang / Req Cabut / Dicabut / Ditolak` | Menunggu / Terpasang / Menunggu cabut / Selesai / Ditolak | Diajukan → Terpasang (X/Y hari) → Dicabut |

Catatan: stepper peminjaman memakai **3 tahap** (Diajukan → Dipinjam → Dikembalikan) karena pada logika asli "disetujui" dan "dipinjam" adalah satu status (`approved`). Dokumen planning §9 yang menyebut 4 tahap sudah disesuaikan.

## 5. Pola interaksi
- **Realtime tanpa mengganggu input:** daftar dan kartu di-patch; form tidak pernah di-render ulang. Perubahan penting muncul sebagai toast + lonceng.
- **Aksi berisiko** (tolak, kembalikan, nonaktifkan) memakai dialog konfirmasi bergaya sheet, bukan `confirm()` bawaan.
- **Memuat:** skeleton, bukan teks "Memuat…". **Kosong:** ikon + judul + satu kalimat petunjuk.
- **Validasi:** pesan di bawah field, `aria-invalid`, border merah; tombol kirim tidak dinonaktifkan diam-diam.
- **Aksesibilitas:** sheet `role="dialog"` + `aria-modal`, Esc menutup, fokus terlihat (outline oranye), ikon dekoratif `aria-hidden`.

## 6. Yang dipakai dari referensi dan yang tidak
Dipakai: bahasa visual (kartu putih membulat, bottom nav pil gelap + FAB oranye, stepper titik-titik, chip status, sheet gelap berisi grid label/nilai, bar orang + tombol bulat). **Tidak** dipakai: ilustrasi 3D, nama/merek, dan teks dari referensi. Foto barang HME ditampilkan bila publik; barang tanpa foto memakai ikon.

## 7. Batasan mockup (akan diselesaikan saat implementasi)
- Interaksi hanya demonstrasi (`design/js/ui.js`); belum terhubung database.
- Font Inter dimuat dari Google Fonts; versi produksi akan menyimpan file font secara lokal agar tidak bergantung internet.
- 16 dari 19 foto barang masih privat di Drive, jadi mockup hanya memakai 3 foto publik.
