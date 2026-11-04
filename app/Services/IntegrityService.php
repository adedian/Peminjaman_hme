<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Pemeriksaan konsistensi data (Tahap 7) — hanya membaca. Dipakai `tools/check-integrity.php`, tes otomatis, dan
 * disarankan dijalankan berkala setelah go-live. Setiap aturan mengembalikan jumlah pelanggaran + contoh ID.
 */
final class IntegrityService
{
    /** @return array{ok:bool,checks:array<int,array{rule:string,violations:int,samples:array<int,string>}>} */
    public static function check(): array
    {
        $rules = [
            'Status barang = turunan transaksi approved + PQA aktif (BR-09/21/25/29)' =>
                "SELECT i.code FROM items i
                 WHERE i.master_status <> IF(EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status = 'approved')
                       OR EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut')), 'Dipinjam', 'Tersedia')",
            'Barang tidak dipinjam biasa & dipasang PQA sekaligus (BR-29)' =>
                "SELECT DISTINCT i.code FROM items i JOIN loans l ON l.item_id = i.id AND l.status IN ('pending','approved')
                 JOIN pqa_installations p ON p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut')",
            'Barang nonaktif tanpa pinjaman/PQA terbuka (Q3)' =>
                "SELECT DISTINCT i.code FROM items i WHERE i.is_active = 0 AND (
                   EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status IN ('pending','approved'))
                   OR EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut')))",
            'Pinjaman returned punya tanggal kembali; approved/pending belum' =>
                "SELECT COALESCE(legacy_id, CONCAT('#', id)) FROM loans
                 WHERE (status = 'returned' AND returned_at IS NULL) OR (status IN ('pending','approved') AND returned_at IS NOT NULL)",
            'Pinjaman dibatalkan punya waktu batal (Q5)' =>
                "SELECT CONCAT('#', id) FROM loans WHERE (status = 'cancelled') <> (cancelled_at IS NOT NULL)",
            'Tanggal kembali tidak sebelum tanggal pinjam (transaksi aplikasi baru)' =>
                "SELECT CONCAT('#', id) FROM loans WHERE legacy_row IS NULL AND returned_at IS NOT NULL AND DATE(returned_at) < DATE(borrowed_at)",
            'Setiap pengajuan berisi ≥ 1 barang milik satu peminjam' =>
                "SELECT CONCAT('batch #', b.id) FROM loan_batches b
                 WHERE NOT EXISTS (SELECT 1 FROM loans l WHERE l.batch_id = b.id)
                    OR EXISTS (SELECT 1 FROM loans l WHERE l.batch_id = b.id AND l.user_id <> b.user_id)",
            'Penghitung nomor urut ≥ nomor terbesar (BR-06)' =>
                "SELECT CONCAT('loan_seq ', s.value, ' < ', m.mx) FROM settings s, (SELECT COALESCE(MAX(seq_no), 0) AS mx FROM loans) m
                 WHERE s.key_name = 'loan_seq' AND CAST(s.value AS UNSIGNED) < m.mx",
            'PQA Terpasang/Req Cabut/Dicabut punya tanggal pasang; Dicabut punya tanggal cabut & durasi' =>
                "SELECT CONCAT('pqa #', id) FROM pqa_installations
                 WHERE (status IN ('Terpasang','Req Cabut','Dicabut') AND installed_at IS NULL)
                    OR (status = 'Dicabut' AND (removed_at IS NULL OR total_days IS NULL))",
            'Permintaan tambah waktu hanya pada PQA aktif' =>
                "SELECT CONCAT('pqa #', id) FROM pqa_installations WHERE pending_extension_days > 0 AND status NOT IN ('Terpasang','Req Cabut')",
            'Minimal satu admin aktif (Q7)' =>
                "SELECT 'tidak ada admin aktif' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND is_active = 1)",
            'Staff nonaktif tanpa pinjaman terbuka (Q3)' =>
                "SELECT DISTINCT u.username FROM users u JOIN loans l ON l.user_id = u.id AND l.status IN ('pending','approved') WHERE u.is_active = 0",
            'Foto barang menunjuk berkas yang ada' => null,
        ];
        $out = [];
        foreach ($rules as $rule => $sql) {
            $rows = $sql === null ? self::missingPhotos() : array_map(static fn(array $r): string => (string) reset($r), Database::all($sql));
            $out[] = ['rule' => $rule, 'violations' => count($rows), 'samples' => array_slice($rows, 0, 10)];
        }
        $ok = array_sum(array_column($out, 'violations')) === 0;
        return ['ok' => $ok, 'checks' => $out];
    }

    /** @return array<int,string> */
    private static function missingPhotos(): array
    {
        $bad = [];
        foreach (Database::all('SELECT code, photo_path FROM items WHERE photo_path IS NOT NULL') as $r) {
            $p = (string) $r['photo_path'];
            if (!ImageService::isValidName($p) || !is_file(ImageService::path($p)) || !is_file(ImageService::path(ImageService::thumbName($p)))) {
                $bad[] = (string) $r['code'];
            }
        }
        return $bad;
    }
}
