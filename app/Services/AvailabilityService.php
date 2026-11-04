<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Sumber kebenaran tunggal untuk status barang.
 *
 * Aturan (BR-09/21/25/29): items.master_status = 'Dipinjam' ⇔ ada loan *approved* ATAU ada pemasangan PQA
 * aktif (Pending/Terpasang/Req Cabut). Selain itu 'Tersedia'. Pengajuan *pending* tidak mengubah master_status
 * (barang "tertahan" oleh loan pending, dijaga oleh unique key active_item_key — lihat migrasi 0003).
 *
 * Dipanggil DI DALAM transaksi yang mengubah loan/PQA, setelah baris barang dikunci (FOR UPDATE).
 */
final class AvailabilityService
{
    /** Hitung ulang & simpan master_status satu barang. @return string status baru */
    public static function sync(int $itemId): string
    {
        $busy = (bool) Database::value(
            "SELECT EXISTS (SELECT 1 FROM loans WHERE item_id = ? AND status = 'approved')
                 OR EXISTS (SELECT 1 FROM pqa_installations WHERE item_id = ? AND status IN ('Pending','Terpasang','Req Cabut'))",
            [$itemId, $itemId]
        );
        $status = $busy ? 'Dipinjam' : 'Tersedia';
        Database::run('UPDATE items SET master_status = ? WHERE id = ? AND master_status <> ?', [$status, $itemId, $status]);
        return $status;
    }

    /**
     * Baris barang yang dikunci + penanda kesibukan, diurutkan menurut id (urutan kunci seragam → tanpa deadlock).
     *
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>> dikunci per id
     */
    public static function lockItems(array $ids, ?int $exceptLoanId = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT i.id, i.code, i.name, i.is_active, i.master_status, i.is_pqa,
                    EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status IN ('pending','approved') AND l.id <> ?) AS busy_loan,
                    EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut')) AS busy_pqa
             FROM items i WHERE i.id IN ({$in}) ORDER BY i.id FOR UPDATE",
            array_merge([$exceptLoanId ?? 0], $ids)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** Dapat dipinjam/diajukan sekarang? (BR-03 + BR-29). @param array<string,mixed> $lockedRow hasil lockItems() */
    public static function isFree(array $lockedRow): bool
    {
        return (int) $lockedRow['is_active'] === 1
            && $lockedRow['master_status'] === 'Tersedia'
            && (int) $lockedRow['busy_loan'] === 0
            && (int) $lockedRow['busy_pqa'] === 0;
    }

    /** Nomor urut transaksi berikutnya (BR-06). Atomik; baris penghitung terkunci sampai commit sehingga tidak ada nomor ganda. */
    public static function nextLoanSeq(): int
    {
        // JANGAN memakai INSERT ... SELECT MAX(...) FROM loans di sini: itu mengambil kunci baca pada tabel loans
        // dan menyebabkan deadlock dengan transaksi lain yang sedang menyisipkan loan.
        $bump = "UPDATE settings SET value = LAST_INSERT_ID(CAST(value AS UNSIGNED) + 1) WHERE key_name = 'loan_seq'";
        if (Database::run($bump)->rowCount() === 0) {
            // Baris penghitung belum ada (normalnya dibuat migrasi 0009): buat dari MAX tanpa kunci
            Database::run("INSERT IGNORE INTO settings (key_name, value) VALUES ('loan_seq', ?)",
                [(string) (int) Database::value('SELECT COALESCE(MAX(seq_no), 0) FROM loans')]);
            Database::run($bump);
        }
        $n = (int) Database::value('SELECT LAST_INSERT_ID()');
        // Pengaman bila penghitung tertinggal dari data (mis. setelah impor): lompat ke MAX+1
        $max = (int) Database::value('SELECT COALESCE(MAX(seq_no), 0) FROM loans');
        if ($n <= $max) {
            $n = $max + 1;
            Database::run("UPDATE settings SET value = ? WHERE key_name = 'loan_seq'", [(string) $n]);
        }
        return $n;
    }
}
