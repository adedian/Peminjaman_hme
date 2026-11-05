<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Setting;

/**
 * Data dashboard beranda (revisi V2, R7). Semua angka dihitung langsung dari database pada setiap permintaan, jadi
 * perubahan data langsung tercermin (halaman memanggil ulang saat ada sinyal realtime).
 *
 * Pembatasan per peran (ditegakkan di sini, bukan di JavaScript):
 *  - admin : seluruh peminjaman, seluruh aktivitas, nama peminjam tampil.
 *  - staff : hanya peminjaman & aktivitas MILIK SENDIRI; inventaris dan tracker PQA bersifat bersama (seperti sistem lama),
 *            tanpa nama orang lain pada daftar peminjaman.
 */
final class DashboardService
{
    private const DAYS = 14;

    /**
     * @param array<string,mixed> $viewer
     * @return array<string,mixed>
     */
    public static function data(array $viewer): array
    {
        $admin = ($viewer['role'] ?? '') === 'admin';
        $uid = (int) $viewer['id'];
        $own = $admin ? '' : ' AND l.user_id = ' . $uid;     // $uid integer hasil cast → aman disisipkan
        $ownPlain = $admin ? '' : ' AND user_id = ' . $uid;

        $loanWin = max(1, Setting::int('loan_reminder_hours', 24)) * 3600;
        $pqaWin = max(1, Setting::int('pqa_reminder_hours', 24)) * 3600;

        // ---- Inventaris (bersama) --------------------------------------------------------
        $items = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(master_status = 'Tersedia') AS available,
                    SUM(master_status = 'Dipinjam') AS in_use
             FROM items WHERE is_active = 1"
        ) ?? [];
        $inLoan = (int) Database::value(
            "SELECT COUNT(DISTINCT l.item_id) FROM loans l JOIN items i ON i.id = l.item_id
             WHERE l.status IN ('pending','approved') AND i.is_active = 1"
        );
        $inPqa = (int) Database::value(
            "SELECT COUNT(DISTINCT p.item_id) FROM pqa_installations p JOIN items i ON i.id = p.item_id
             WHERE p.status IN ('Pending','Terpasang','Req Cabut') AND i.is_active = 1"
        );

        // ---- Peminjaman -------------------------------------------------------------------
        $loan = Database::one(
            "SELECT SUM(l.status = 'approved') AS active,
                    SUM(l.status = 'approved' AND l.due_at IS NOT NULL AND l.due_at < NOW()) AS overdue,
                    SUM(l.status = 'approved' AND l.due_at IS NOT NULL AND l.due_at >= NOW() AND l.due_at <= NOW() + INTERVAL {$loanWin} SECOND) AS due_soon,
                    SUM(l.status = 'returned' AND DATE(l.returned_at) = CURDATE()) AS returned_today
             FROM loans l WHERE 1=1{$own}"
        ) ?? [];
        $pendingBatches = (int) Database::value("SELECT COUNT(DISTINCT l.batch_id) FROM loans l WHERE l.status = 'pending'{$own}");

        // ---- PQA (tracker bersama) --------------------------------------------------------
        $pqa = Database::one(
            "SELECT SUM(status IN ('Terpasang','Req Cabut')) AS active,
                    SUM(status = 'Pending') AS pending,
                    SUM(status IN ('Terpasang','Req Cabut') AND due_at IS NOT NULL AND due_at < NOW()) AS overdue,
                    SUM(status IN ('Terpasang','Req Cabut') AND due_at IS NOT NULL AND due_at >= NOW() AND due_at <= NOW() + INTERVAL {$pqaWin} SECOND) AS due_soon
             FROM pqa_installations"
        ) ?? [];

        return [
            'role'        => $admin ? 'admin' : 'staff',
            'generated_at' => date('Y-m-d H:i:s'),
            'kpi' => [
                'items_total'     => (int) ($items['total'] ?? 0),
                'items_available' => (int) ($items['available'] ?? 0),
                'items_in_use'    => (int) ($items['in_use'] ?? 0),
                'loans_active'    => (int) ($loan['active'] ?? 0),
                'loans_overdue'   => (int) ($loan['overdue'] ?? 0),
                'loans_due_soon'  => (int) ($loan['due_soon'] ?? 0),
                'loans_pending'   => $pendingBatches,
                'returned_today'  => (int) ($loan['returned_today'] ?? 0),
                'pqa_active'      => (int) ($pqa['active'] ?? 0),
                'pqa_pending'     => (int) ($pqa['pending'] ?? 0),
                'pqa_overdue'     => (int) ($pqa['overdue'] ?? 0),
                'pqa_due_soon'    => (int) ($pqa['due_soon'] ?? 0),
            ],
            'composition' => [
                // Tersedia + dipinjam biasa + dipasang PQA + lainnya = seluruh barang aktif
                ['key' => 'available', 'label' => 'Tersedia', 'value' => (int) ($items['available'] ?? 0)],
                ['key' => 'loan',      'label' => 'Dipinjam', 'value' => $inLoan],
                ['key' => 'pqa',       'label' => 'Pemasangan PQA', 'value' => $inPqa],
            ],
            'trend'      => self::trend($own),
            'top_items'  => self::topItems(),
            'deadlines'  => self::deadlines($admin, $uid),
            'pqa_active' => self::pqaActive(),
            'activity'   => self::activity($admin, $uid),
        ];
    }

    /** Pengajuan & pengembalian per hari, 14 hari terakhir (hari tanpa data = 0). @return array<string,mixed> */
    private static function trend(string $own): array
    {
        $from = date('Y-m-d', strtotime('-' . (self::DAYS - 1) . ' days'));
        $sub = Database::all(
            "SELECT DATE(l.created_at) AS d, COUNT(DISTINCT l.batch_id) AS n FROM loans l
             WHERE l.created_at >= ?{$own} GROUP BY DATE(l.created_at)",
            [$from . ' 00:00:00']
        );
        $ret = Database::all(
            "SELECT DATE(l.returned_at) AS d, COUNT(*) AS n FROM loans l
             WHERE l.status = 'returned' AND l.returned_at >= ?{$own} GROUP BY DATE(l.returned_at)",
            [$from . ' 00:00:00']
        );
        $s = array_column($sub, 'n', 'd');
        $r = array_column($ret, 'n', 'd');
        $days = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $days[] = ['date' => $d, 'submitted' => (int) ($s[$d] ?? 0), 'returned' => (int) ($r[$d] ?? 0)];
        }
        return ['days' => $days, 'total_submitted' => array_sum(array_column($days, 'submitted')), 'total_returned' => array_sum(array_column($days, 'returned'))];
    }

    /** Barang paling sering dipinjam 90 hari terakhir (nama barang saja, tanpa identitas peminjam). @return array<int,array<string,mixed>> */
    private static function topItems(): array
    {
        return array_map(static fn(array $r): array => ['name' => (string) $r['name'], 'count' => (int) $r['n']], Database::all(
            "SELECT item_name_snapshot AS name, COUNT(*) AS n FROM loans
             WHERE status IN ('approved','returned') AND created_at >= (NOW() - INTERVAL 90 DAY)
             GROUP BY item_id, item_name_snapshot ORDER BY n DESC, name LIMIT 5"
        ));
    }

    /** Peminjaman aktif terdekat jatuh tempo. @return array<int,array<string,mixed>> */
    private static function deadlines(bool $admin, int $uid): array
    {
        $rows = Database::all(
            'SELECT l.batch_id, l.due_at, TIMESTAMPDIFF(SECOND, NOW(), l.due_at) AS left_s, u.name AS user_name,
                    GROUP_CONCAT(l.item_name_snapshot ORDER BY l.seq_no SEPARATOR ", ") AS names
             FROM loans l JOIN users u ON u.id = l.user_id
             WHERE l.status = "approved" AND l.due_at IS NOT NULL' . ($admin ? '' : ' AND l.user_id = ' . $uid) . '
             GROUP BY l.batch_id, l.due_at, u.name ORDER BY l.due_at ASC LIMIT 6'
        );
        return array_map(static fn(array $r): array => [
            'request_id' => (int) $r['batch_id'], 'items' => (string) $r['names'], 'due_at' => (string) $r['due_at'],
            'remaining_seconds' => (int) $r['left_s'], 'overdue' => (int) $r['left_s'] < 0,
            'borrower' => $admin ? (string) $r['user_name'] : null,
        ], $rows);
    }

    /** PQA terpasang berurutan jadwal cabut terdekat. @return array<int,array<string,mixed>> */
    private static function pqaActive(): array
    {
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'unit' => (string) $r['name'], 'site' => (string) $r['location'], 'status' => (string) $r['status'],
            'due_at' => $r['due_at'], 'remaining_seconds' => $r['due_at'] === null ? null : (int) $r['left_s'],
            'overdue' => $r['due_at'] !== null && (int) $r['left_s'] < 0,
            'elapsed_days' => (int) $r['elapsed'], 'target_days' => (int) $r['target_days'],
        ], Database::all(
            "SELECT p.id, p.location, p.status, p.due_at, p.target_days, i.name,
                    TIMESTAMPDIFF(SECOND, NOW(), p.due_at) AS left_s, FLOOR(TIMESTAMPDIFF(SECOND, p.installed_at, NOW()) / 86400) AS elapsed
             FROM pqa_installations p JOIN items i ON i.id = p.item_id
             WHERE p.status IN ('Terpasang','Req Cabut') ORDER BY (p.due_at IS NULL), p.due_at ASC LIMIT 6"
        ));
    }

    /** Aktivitas peminjaman & PQA terbaru. Staff hanya melihat aktivitasnya sendiri. @return array<int,array<string,mixed>> */
    private static function activity(bool $admin, int $uid): array
    {
        $rows = Database::all(
            "SELECT a.action, a.entity_type, a.entity_id, a.created_at, u.name AS actor
             FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE (a.action LIKE 'request.%' OR a.action LIKE 'loan.%' OR a.action LIKE 'pqa.%')" . ($admin ? '' : ' AND a.user_id = ' . $uid) . '
             ORDER BY a.id DESC LIMIT 8'
        );
        return array_map(static fn(array $r): array => [
            'action' => (string) $r['action'], 'actor' => $r['actor'] === null ? 'Sistem' : (string) $r['actor'],
            'at' => substr((string) $r['created_at'], 0, 19),
            'link' => in_array($r['entity_type'], ['pqa'], true) ? '/pqa?open=' . (int) $r['entity_id'] : null,
        ], $rows);
    }
}
