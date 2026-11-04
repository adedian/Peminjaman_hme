<?php
declare(strict_types=1);

namespace App\Migration;

use App\Core\Database;
use App\Services\ReportService;

/**
 * Rekonsiliasi §8: setiap angka dihitung DARI SUMBER lalu dibandingkan dengan database. Wajib cocok 100%.
 * Dijalankan tepat setelah migrasi (transaksi baru yang dibuat sesudahnya akan membuat rekap tidak lagi sama).
 */
final class Reconciler
{
    /** @var array<int,array{group:string,label:string,expected:mixed,actual:mixed,ok:bool,details:array<int,string>}> */
    private array $checks = [];
    private LegacySource $src;

    private function __construct(LegacySource $src)
    {
        $this->src = $src;
    }

    /** @return array{ok:bool,checks:array<int,array<string,mixed>>,failed:int} */
    public static function run(LegacySource $src): array
    {
        $r = new self($src);
        $r->users();
        $r->items();
        $r->loans();
        $r->monthlyParity();
        $r->stockParity();
        $r->manual();
        $r->pqa();
        $r->register();
        $failed = count(array_filter($r->checks, static fn(array $c): bool => !$c['ok']));
        return ['ok' => $failed === 0, 'failed' => $failed, 'checks' => $r->checks];
    }

    /** @param mixed $expected @param mixed $actual @param array<int,string> $details */
    private function check(string $group, string $label, $expected, $actual, array $details = []): void
    {
        $this->checks[] = ['group' => $group, 'label' => $label, 'expected' => $expected, 'actual' => $actual,
                           'ok' => $expected === $actual && $details === [], 'details' => array_slice($details, 0, 30)];
    }

    private function users(): void
    {
        $src = [];
        foreach ($this->src->rows(LegacySource::SHEET_USERS) as $row) {
            $u = strtolower(trim(LegacySource::text($row, 'A')));
            if ($u !== '') {
                $src[$u] = ['role' => strtolower(trim(LegacySource::text($row, 'C'))), 'name' => trim(preg_replace('/\s+/u', ' ', LegacySource::text($row, 'D')) ?? '')];
            }
        }
        $db = [];
        foreach (Database::all('SELECT username, role, name FROM users') as $u) {
            $db[(string) $u['username']] = $u;
        }
        $roles = ['admin' => 0, 'staff' => 0];
        $dbRoles = ['admin' => 0, 'staff' => 0];
        $details = [];
        foreach ($src as $u => $s) {
            $roles[$s['role']] = ($roles[$s['role']] ?? 0) + 1;
            if (!isset($db[$u])) {
                $details[] = "akun {$u} tidak ada";
                continue;
            }
            $dbRoles[(string) $db[$u]['role']] = ($dbRoles[(string) $db[$u]['role']] ?? 0) + 1;
            if ($db[$u]['role'] !== $s['role']) {
                $details[] = "akun {$u}: peran {$db[$u]['role']} ≠ {$s['role']}";
            }
        }
        $this->check('Akun', 'Jumlah akun di sumber yang ada di sistem baru', count($src), count(array_intersect_key($db, $src)), $details);
        $this->check('Akun', 'Admin / staff', $roles['admin'] . ' / ' . $roles['staff'], $dbRoles['admin'] . ' / ' . $dbRoles['staff']);
    }

    private function items(): void
    {
        $db = [];
        foreach (Database::all('SELECT i.code, i.name, i.is_active, i.master_status, c.name AS cat FROM items i JOIN categories c ON c.id = i.category_id') as $i) {
            $db[(string) $i['code']] = $i;
        }
        $count = ['Tersedia' => 0, 'Dipinjam' => 0];
        $dbCount = ['Tersedia' => 0, 'Dipinjam' => 0];
        $details = [];
        $codes = [];
        foreach ($this->src->rows(LegacySource::SHEET_ITEMS) as $row) {
            $code = trim(LegacySource::text($row, 'A'));
            if ($code === '') {
                continue;
            }
            $codes[$code] = true;
            $st = trim(LegacySource::text($row, 'E')) === 'Dipinjam' ? 'Dipinjam' : 'Tersedia';
            $count[$st]++;
            $d = $db[$code] ?? null;
            if ($d === null) {
                $details[] = "barang {$code} tidak ada";
                continue;
            }
            $dbCount[(string) $d['master_status']]++;
            if ((int) $d['is_active'] !== 1) { $details[] = "barang {$code} nonaktif"; }
            if ((string) $d['name'] !== trim(LegacySource::text($row, 'B'))) { $details[] = "nama {$code} berbeda"; }
            if (strcasecmp((string) $d['cat'], trim(LegacySource::text($row, 'D'))) !== 0) { $details[] = "kategori {$code}: {$d['cat']}"; }
        }
        $this->check('Barang', 'Barang aktif dari Master_Barang', count($codes), count(array_intersect_key($db, $codes)), $details);
        $this->check('Barang', 'Status Tersedia / Dipinjam (diturunkan dari transaksi + PQA)', $count['Tersedia'] . ' / ' . $count['Dipinjam'], $dbCount['Tersedia'] . ' / ' . $dbCount['Dipinjam']);

        $orphans = [];
        foreach ($this->src->rows(LegacySource::SHEET_LOANS) as $row) {
            $c = trim(LegacySource::text($row, 'L'));
            if ($c !== '' && LegacySource::text($row, 'J') !== '' && !isset($codes[$c])) {
                $orphans[$c] = true;
            }
        }
        ksort($orphans);
        $dbOrphans = array_keys(array_filter(array_intersect_key($db, $orphans), static fn(array $i): bool => (int) $i['is_active'] === 0));
        sort($dbOrphans);
        $this->check('Barang', 'Barang terhapus dibuat ulang nonaktif', implode(', ', array_keys($orphans)), implode(', ', $dbOrphans));
    }

    /** @return array<int,string> */
    private function loans(): array
    {
        $src = [];
        foreach ($this->src->rows(LegacySource::SHEET_LOANS) as $r => $row) {
            $id = trim(LegacySource::text($row, 'J'));
            if ($id !== '') {
                $src[$id] = ['row' => $r] + [
                    'seq' => (int) LegacySource::text($row, 'A'), 'user' => strtolower(trim(LegacySource::text($row, 'K'))), 'item' => trim(LegacySource::text($row, 'L')),
                    'status' => strtolower(trim(LegacySource::text($row, 'I'))), 'borrowed' => LegacyDate::appText(LegacySource::text($row, 'D')),
                    'returned' => trim(LegacySource::text($row, 'E')) === '' ? null : LegacyDate::appText(LegacySource::text($row, 'E')),
                    'name' => LegacySource::text($row, 'B'), 'carrier' => LegacySource::text($row, 'C'),
                    'key' => strtolower(trim(LegacySource::text($row, 'K'))) . '|' . LegacySource::text($row, 'D') . '|' . trim(LegacySource::text($row, 'G')),
                    'ms' => (int) (explode('-', $id)[1] ?? 0),
                ];
            }
        }
        $db = [];
        if ($src !== []) {
            foreach (array_chunk(array_keys($src), 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                foreach (Database::all(
                    "SELECT l.legacy_id, l.seq_no, l.status, l.borrowed_at, l.returned_at, l.item_name_snapshot, l.carrier_name_snapshot, l.batch_id,
                            u.username, i.code FROM loans l JOIN users u ON u.id = l.user_id JOIN items i ON i.id = l.item_id WHERE l.legacy_id IN ({$in})", $chunk
                ) as $l) {
                    $db[(string) $l['legacy_id']] = $l;
                }
            }
        }
        $this->check('Transaksi', 'Transaksi aplikasi (ber-ID Pinjam)', count($src), count($db));

        $by = static function (array $rows, string $field): array {
            $c = [];
            foreach ($rows as $r) {
                $c[(string) $r[$field]] = ($c[(string) $r[$field]] ?? 0) + 1;
            }
            ksort($c);
            return $c;
        };
        $fmt = static fn(array $c): string => implode(', ', array_map(static fn($k, $v) => "{$k} {$v}", array_keys($c), $c));
        $this->check('Transaksi', 'Per status', $fmt($by($src, 'status')), $fmt($by($db, 'status')));
        $this->check('Transaksi', 'Per akun', $fmt($by($src, 'user')), $fmt($by($db, 'username')));
        $this->check('Transaksi', 'Per barang', $fmt($by($src, 'item')), $fmt($by($db, 'code')));

        $details = [];
        foreach ($src as $id => $s) {
            $d = $db[$id] ?? null;
            if ($d === null) {
                $details[] = "baris {$s['row']} ({$id}) tidak ada";
                continue;
            }
            foreach ([['seq', 'seq_no'], ['status', 'status'], ['borrowed', 'borrowed_at'], ['returned', 'returned_at'], ['name', 'item_name_snapshot'], ['carrier', 'carrier_name_snapshot']] as [$a, $b]) {
                $x = $s[$a] === null ? null : (string) $s[$a];
                $y = $d[$b] === null ? null : (string) $d[$b];
                if ($x !== $y) {
                    $details[] = "baris {$s['row']} {$b}: sumber " . var_export($x, true) . ', db ' . var_export($y, true);
                }
            }
        }
        $this->check('Transaksi', 'Isi per baris (no, status, tanggal, snapshot nama)', count($src), count($src) - count($details), $details);

        // Pengajuan: kelompok di sumber vs batch di database
        $groups = [];
        foreach ($src as $id => $s) {
            $gi = null;
            foreach ($groups as $i => $g) {
                if ($g['key'] === $s['key'] && abs($g['ms'] - $s['ms']) <= 1000) { $gi = $i; break; }
            }
            if ($gi === null) { $groups[] = ['key' => $s['key'], 'ms' => $s['ms'], 'ids' => []]; $gi = count($groups) - 1; }
            $groups[$gi]['ids'][] = $id;
        }
        $bad = [];
        foreach ($groups as $g) {
            $b = array_unique(array_map(static fn(string $id) => isset($db[$id]) ? (int) $db[$id]['batch_id'] : 0, $g['ids']));
            if (count($b) !== 1) {
                $bad[] = 'pengajuan ' . $g['ids'][0] . ' terpecah menjadi ' . count($b) . ' batch';
            }
        }
        $dbBatches = count(array_unique(array_map(static fn(array $l) => (int) $l['batch_id'], $db)));
        $this->check('Transaksi', 'Pengajuan (batch)', count($groups), $dbBatches, $bad);
        return array_keys($src);
    }

    private function monthlyParity(): void
    {
        $rows = LegacyReference::loanRows($this->src);
        $years = [];
        foreach ($rows as $r) {
            if ($r['id'] !== '' && $r['date']['t'] !== 'n' && preg_match('#/(\d{4})#', (string) $r['date']['v'], $m)) {
                $years[(int) $m[1]] = true;
            }
        }
        ksort($years);
        $periods = [['all', 'semua']];
        foreach (array_keys($years) as $y) {
            $periods[] = ['all', (string) $y];
            for ($m = 1; $m <= 12; $m++) {
                $periods[] = [(string) $m, (string) $y];
            }
        }
        if (count($years) > 1) {
            $periods[] = ['all', array_key_first($years) . '-' . array_key_last($years)];
        }
        $details = [];
        foreach ($periods as [$m, $y]) {
            $old = LegacyReference::monthlyStats($rows, $m, $y);
            $new = ReportService::summary(ReportService::period($m, $y));
            $a = [$old['total'], $old['approved'], $old['returned'], $old['pending'], $old['rejected'], $old['topItems'], $old['activeList']];
            $b = [$new['total'], $new['approved'], $new['returned'], $new['pending'], $new['rejected'],
                  array_map(static fn(array $t) => [$t['name'], $t['count']], $new['top_items']),
                  array_map(static fn(array $x) => ['namaAlat' => $x['item_name'], 'peminjam' => $x['borrower']], $new['active'])];
            if ($a !== $b) {
                $details[] = "periode {$m}/{$y}: lama total {$old['total']}, baru {$new['total']}";
            }
        }
        $this->check('Paritas', 'Rekap getMonthlyStats (total, status, ranking, daftar aktif)', count($periods), count($periods) - count($details), $details);
    }

    private function stockParity(): void
    {
        $old = LegacyReference::stockReport($this->src);
        $new = [];
        foreach (ReportService::stockRows() as $r) {
            $new[$r['code']] = ['qty' => $r['qty'], 'status' => $r['status'], 'borrower' => $r['borrower'], 'note' => $r['note']];
        }
        $details = [];
        $bad = [];
        foreach ($old as $code => $o) {
            if (!isset($new[$code])) {
                $details[] = "{$code} tidak ada di laporan baru";
                $bad[$code] = true;
                continue;
            }
            foreach (['qty', 'status', 'borrower', 'note'] as $f) {
                if ($o[$f] !== $new[$code][$f]) {
                    $details[] = "{$code} {$f}: lama " . var_export($o[$f], true) . ', baru ' . var_export($new[$code][$f], true);
                    $bad[$code] = true;
                }
            }
        }
        $this->check('Paritas', 'Laporan Excel "Laporan Stok" (Jumlah, Status, Peminjam Terakhir, Keterangan)', count($old), count($old) - count($bad), $details);
    }

    private function manual(): void
    {
        $rows = [];
        $sep = 0;
        foreach ($this->src->rows(LegacySource::SHEET_LOANS) as $r => $row) {
            if (trim(LegacySource::text($row, 'J')) !== '') {
                continue;
            }
            $a = trim(LegacySource::text($row, 'A'));
            if (trim(LegacySource::text($row, 'B')) === '' && $a !== '' && !ctype_digit($a)) {
                $sep++;
                continue;
            }
            $rows[] = $r;
        }
        $db = $rows === [] ? 0 : (int) Database::value('SELECT COUNT(*) FROM legacy_manual_loans WHERE source_row IN (' . implode(',', array_map('intval', $rows)) . ')');
        $this->check('Arsip', 'Catatan manual diarsipkan (tidak masuk rekap)', count($rows), $db);
        $this->check('Arsip', 'Baris pemisah dilewati', $sep, $sep);
    }

    private function pqa(): void
    {
        $src = [];
        foreach ($this->src->rows(LegacySource::SHEET_PQA) as $r => $row) {
            if (trim(LegacySource::text($row, 'A')) !== '') {
                $src[$r] = LegacySource::text($row, 'I');
            }
        }
        $db = [];
        foreach (Database::all('SELECT legacy_row, total_days FROM pqa_installations WHERE legacy_row IS NOT NULL ORDER BY legacy_row') as $p) {
            $db[(int) $p['legacy_row']] = $p['total_days'] === null ? '' : (string) $p['total_days'];
        }
        $db = array_intersect_key($db, $src);
        $this->check('PQA', 'Pemasangan PQA', count($src), count($db));
        $this->check('PQA', 'Durasi aktual (hari)', implode(', ', $src), implode(', ', array_replace($src, $db)), array_diff_assoc($src, $db) === [] ? [] : ['durasi berbeda']);
    }

    private function register(): void
    {
        $rows = Database::all('SELECT section, COUNT(*) AS n FROM legacy_inventory_register GROUP BY section ORDER BY MIN(source_row)');
        $total = 0;
        foreach ($this->src->rows(LegacySource::SHEET_REGISTER) as $row) {
            $a = strtoupper(trim(LegacySource::text($row, 'A')));
            $b = trim(LegacySource::text($row, 'B'));
            if ($a === 'NO' || ($b === '' && ($a === '' || !is_numeric($a)))) {
                continue;
            }
            $total++;
        }
        $this->check('Arsip', 'Register inventaris (per bagian: ' . implode(' + ', array_map(static fn($r) => $r['section'] . ' ' . $r['n'], $rows)) . ')',
            $total, (int) array_sum(array_column($rows, 'n')));
    }
}
