<?php
declare(strict_types=1);

namespace App\Migration;

use App\Core\Database;
use App\Core\Logger;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\AvailabilityService;
use App\Services\ImageService;
use RuntimeException;
use Throwable;

/**
 * Migrasi data spreadsheet lama → MySQL (§8 rencana). Read-only terhadap sumber, IDEMPOTEN, dengan dry-run.
 *
 *  - Seluruh impor berjalan dalam SATU transaksi: gagal di tengah → tidak ada data setengah jadi.
 *  - Dry-run menjalankan semuanya lalu ROLLBACK; hanya laporan (migration_runs/issues) yang disimpan.
 *  - Idempoten lewat kunci alami: username, kode barang, ID Pinjam (legacy_id), nomor baris sheet (PQA, arsip).
 *    Menjalankan ulang tidak menggandakan data; data yang sudah ada TIDAK ditimpa (mis. akun admin yang dibuat manual).
 *  - Setiap baris sumber mendapat catatan di migration_issues: ok / failed / duplicate / needs_review.
 *  - Password lama di-hash (password_hash) dan password lama tetap berlaku (tanpa paksa ganti); teks password tidak
 *    pernah ditulis ke log, laporan, maupun audit.
 */
final class LegacyImporter
{
    private const DRY_MARKER = 'hme-dry-run-rollback';
    private const PHOTO_ID = '/[-\w]{25,}/';

    private LegacySource $src;
    private bool $dry;
    /** @var array<int,array{sheet:string,row:?int,category:string,message:string,raw:?array}> */
    private array $issues = [];
    /** @var array<string,int> */
    private array $stats = [];
    /** @var array<string,int> username → id */
    private array $userIds = [];
    /** @var array<string,int> kode barang → id */
    private array $itemIds = [];
    /** @var array<string,string> kode barang → status di sheet Master_Barang */
    private array $sheetStatus = [];
    /** @var array<int,array{item_id:int,file_id:string}> */
    private array $photoQueue = [];

    private function __construct(LegacySource $src, bool $dry)
    {
        $this->src = $src;
        $this->dry = $dry;
    }

    /**
     * @param array{download_photos?:bool,photo_timeout?:int} $opts
     * @return array<string,mixed> ringkasan (id run, statistik, jumlah per kategori)
     */
    public static function run(LegacySource $src, bool $dry, array $opts = []): array
    {
        $self = new self($src, $dry);
        $runId = Database::insert('migration_runs', [
            'source_file' => mb_substr(basename($src->path()), 0, 255), 'source_sha256' => $src->sha256(), 'mode' => $dry ? 'dry' : 'real',
        ]);
        $status = 'success';
        $error = null;
        try {
            Database::transaction(function () use ($self, $dry): void {
                $self->importAll();
                if ($dry) {
                    throw new RuntimeException(self::DRY_MARKER);
                }
            });
        } catch (Throwable $e) {
            if ($e->getMessage() !== self::DRY_MARKER) {
                $status = 'failed';
                $error = $e->getMessage();
                $self->issue('-', null, 'failed', 'Migrasi dibatalkan seluruhnya (tidak ada data yang tersimpan): ' . $e->getMessage());
                Logger::error('Migrasi data lama gagal', ['err' => $e->getMessage()]);
            }
        }
        if ($status === 'success' && !$dry && !empty($opts['download_photos'])) {
            $self->downloadPhotos((int) ($opts['photo_timeout'] ?? 20));
        }
        $summary = $self->finish($runId, $status, $error);
        AuditService::log($dry ? 'migration.dry_run' : 'migration.imported', null, 'migration', $runId, null,
            ['status' => $status, 'source_sha256' => $src->sha256(), 'stats' => $summary['stats']]);
        return $summary;
    }

    // ======================================================================================
    //  Impor
    // ======================================================================================

    private function importAll(): void
    {
        $this->importUsers();
        $this->importItems();
        $this->importLoans();
        $this->importManual();
        $this->importPqa();
        $this->importRegister();
        $this->syncStatuses();
    }

    private function importUsers(): void
    {
        foreach (Database::all('SELECT id, username FROM users') as $u) {
            $this->userIds[(string) $u['username']] = (int) $u['id'];
        }
        foreach ($this->src->rows(LegacySource::SHEET_USERS) as $r => $row) {
            $username = strtolower(trim(LegacySource::text($row, 'A')));
            $pass = LegacySource::text($row, 'B');
            $role = strtolower(trim(LegacySource::text($row, 'C')));
            $name = trim(preg_replace('/\s+/u', ' ', LegacySource::text($row, 'D')) ?? '');
            $label = trim(LegacySource::text($row, 'E'));
            $raw = ['username' => $username, 'role' => $role, 'name' => $name, 'label' => $label];   // tanpa password
            if ($username === '') {
                $this->issue(LegacySource::SHEET_USERS, $r, 'failed', 'Username kosong.', $raw);
                continue;
            }
            if (!preg_match('/^[a-z0-9._-]{2,50}$/', $username) || !in_array($role, ['admin', 'staff'], true) || $pass === '') {
                $this->issue(LegacySource::SHEET_USERS, $r, 'failed', 'Username/role tidak valid atau password kosong.', $raw);
                continue;
            }
            if (isset($this->userIds[$username])) {
                $this->issue(LegacySource::SHEET_USERS, $r, 'duplicate', "Akun \"{$username}\" sudah ada di sistem baru — tidak diubah (password lama tidak dipakai).", $raw);
                $this->bump('users_skipped');
                continue;
            }
            $id = Database::insert('users', [
                'username' => $username, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'name' => mb_substr($name !== '' ? $name : $username, 0, 100),
                'initials' => mb_substr($label !== '' ? strtoupper($label) : strtoupper(substr($username, 0, 2)), 0, 4),
                'role' => $role, 'is_active' => 1, 'must_change_password' => 0,
            ]);
            $this->userIds[$username] = $id;
            $this->bump('users_created');
            $this->issue(LegacySource::SHEET_USERS, $r, 'ok', "Akun {$username} ({$role}) dibuat; password lama tetap berlaku (di-hash, tanpa paksa ganti).", $raw);
        }
    }

    private function importItems(): void
    {
        $cats = [];
        foreach (Database::all('SELECT id, name FROM categories') as $c) {
            $cats[strtolower((string) $c['name'])] = (int) $c['id'];
        }
        $fallback = $cats['lain-lain'] ?? (int) array_values($cats)[0];
        foreach (Database::all('SELECT id, code FROM items') as $i) {
            $this->itemIds[(string) $i['code']] = (int) $i['id'];
        }

        foreach ($this->src->rows(LegacySource::SHEET_ITEMS) as $r => $row) {
            $code = trim(LegacySource::text($row, 'A'));
            if ($code === '') {
                continue;   // sama seperti Code.gs: baris tanpa ID diabaikan
            }
            $name = trim(LegacySource::text($row, 'B'));
            $catText = trim(LegacySource::text($row, 'D'));
            $status = trim(LegacySource::text($row, 'E')) === 'Dipinjam' ? 'Dipinjam' : 'Tersedia';
            $raw = ['code' => $code, 'name' => $name, 'category' => $catText, 'status' => $status];
            $this->sheetStatus[$code] = $status;
            if (isset($this->itemIds[$code])) {
                $this->issue(LegacySource::SHEET_ITEMS, $r, 'duplicate', "Barang {$code} sudah ada — tidak diubah.", $raw);
                $this->bump('items_skipped');
                continue;
            }
            $catId = $cats[strtolower($catText)] ?? null;
            if ($catId === null) {
                $catId = $fallback;
                $this->issue(LegacySource::SHEET_ITEMS, $r, 'needs_review', "Kategori \"{$catText}\" tidak dikenal → Lain-lain.", $raw);
            }
            $url = trim(LegacySource::text($row, 'C'));
            $photo = null;
            if (preg_match(self::PHOTO_ID, $url, $m)) {
                $photo = 'https://lh3.googleusercontent.com/d/' . $m[0];   // normalisasi sama dengan Code.gs
            } elseif ($url !== '') {
                $photo = mb_substr($url, 0, 500);
            }
            $ref = trim(LegacySource::text($row, 'F'));
            $id = Database::insert('items', [
                'code' => mb_substr($code, 0, 50), 'name' => mb_substr($name !== '' ? $name : $code, 0, 150), 'category_id' => $catId,
                'photo_legacy_url' => $photo, 'finance_ref' => $ref === '' ? null : mb_substr($ref, 0, 100),
                'is_pqa' => stripos($name, 'pqa') !== false ? 1 : 0, 'master_status' => $status, 'is_active' => 1,
            ]);
            $this->itemIds[$code] = $id;
            if ($photo !== null && preg_match(self::PHOTO_ID, $photo, $m)) {
                $this->photoQueue[] = ['item_id' => $id, 'file_id' => $m[0]];
            }
            $this->bump('items_created');
            $this->issue(LegacySource::SHEET_ITEMS, $r, 'ok', "Barang {$code} dibuat.", $raw);
        }

        // Barang yatim: dirujuk transaksi tetapi sudah dihapus dari Master_Barang → dibuat ulang NONAKTIF agar riwayat utuh
        $orphans = [];
        foreach ($this->src->rows(LegacySource::SHEET_LOANS) as $r => $row) {
            $code = trim(LegacySource::text($row, 'L'));
            if ($code !== '' && LegacySource::text($row, 'J') !== '' && !isset($this->itemIds[$code])) {
                $orphans[$code] = ['row' => $r, 'name' => trim(LegacySource::text($row, 'B'))];   // nama dari transaksi terakhir
            }
        }
        foreach ($orphans as $code => $o) {
            $id = Database::insert('items', [
                'code' => mb_substr($code, 0, 50), 'name' => mb_substr($o['name'] !== '' ? $o['name'] : $code, 0, 150), 'category_id' => $fallback,
                'is_pqa' => stripos($o['name'], 'pqa') !== false ? 1 : 0, 'master_status' => 'Tersedia', 'is_active' => 0,
            ]);
            $this->itemIds[$code] = $id;
            $this->bump('items_orphan_created');
            $this->issue(LegacySource::SHEET_LOANS, $o['row'], 'needs_review',
                "Barang {$code} sudah dihapus dari Master_Barang tetapi dirujuk transaksi → dibuat ulang sebagai barang NONAKTIF bernama \"{$o['name']}\".",
                ['code' => $code]);
        }
    }

    private function importLoans(): void
    {
        $existing = [];
        foreach (Database::all('SELECT legacy_id FROM loans WHERE legacy_id IS NOT NULL') as $l) {
            $existing[(string) $l['legacy_id']] = true;
        }

        // Kelompokkan baris menjadi pengajuan. Satu pengajuan = akun + tanggal bawa + lokasi yang sama dengan kode HME-<ms>
        // berdekatan (≤ 1 dtk): Apps Script membuat ID per barang dengan Date.now(), sehingga milidetik bisa berganti di
        // tengah satu pengajuan, dan baris satu pengajuan tidak selalu berurutan di sheet.
        $groups = [];
        foreach ($this->src->rows(LegacySource::SHEET_LOANS) as $r => $row) {
            $legacyId = trim(LegacySource::text($row, 'J'));
            if ($legacyId === '') {
                continue;
            }
            if (!preg_match('/^HME-(\d{10,16})-(\d{1,4})$/', $legacyId, $m)) {
                $this->issue(LegacySource::SHEET_LOANS, $r, 'failed', "Format ID Pinjam tidak dikenal: {$legacyId}", ['id' => $legacyId]);
                continue;
            }
            $ms = (int) $m[1];
            $key = strtolower(trim(LegacySource::text($row, 'K'))) . '|' . LegacySource::text($row, 'D') . '|' . trim(LegacySource::text($row, 'G'));
            $gi = null;
            foreach ($groups as $i => $g) {
                if ($g['key'] === $key && abs($g['ms'] - $ms) <= 1000) {
                    $gi = $i;
                    break;
                }
            }
            if ($gi === null) {
                $groups[] = ['key' => $key, 'ms' => $ms, 'rows' => []];
                $gi = count($groups) - 1;
            }
            $groups[$gi]['rows'][$r] = $row;
        }
        $this->stats['loan_batches_found'] = count($groups);

        $maxSeq = 0;
        foreach ($groups as $g) {
            $batchId = null;
            foreach ($g['rows'] as $r => $row) {
                $legacyId = trim(LegacySource::text($row, 'J'));
                $username = strtolower(trim(LegacySource::text($row, 'K')));
                $code = trim(LegacySource::text($row, 'L'));
                $status = strtolower(trim(LegacySource::text($row, 'I')));
                $seq = LegacySource::text($row, 'A');
                $raw = ['id' => $legacyId, 'no' => $seq, 'account' => $username, 'item' => $code, 'status' => $status,
                        'borrowed' => LegacySource::text($row, 'D'), 'returned' => LegacySource::text($row, 'E')];
                if (isset($existing[$legacyId])) {
                    $this->issue(LegacySource::SHEET_LOANS, $r, 'duplicate', "Transaksi {$legacyId} sudah ada — dilewati.", $raw);
                    $this->bump('loans_skipped');
                    continue;
                }
                $problems = [];
                if (!isset($this->userIds[$username])) {
                    $problems[] = "akun \"{$username}\" tidak ada";
                }
                if (!isset($this->itemIds[$code])) {
                    $problems[] = "barang \"{$code}\" tidak ada";
                }
                if (!in_array($status, ['pending', 'approved', 'returned', 'rejected'], true)) {
                    $problems[] = "status \"{$status}\" tidak dikenal";
                }
                if (!ctype_digit($seq)) {
                    $problems[] = 'kolom No bukan angka';
                }
                $borrowed = LegacyDate::appText(LegacySource::text($row, 'D'));
                if ($borrowed === null) {
                    $problems[] = 'tanggal bawa tidak terbaca';
                }
                $retText = trim(LegacySource::text($row, 'E'));
                $returned = $retText === '' ? null : LegacyDate::appText($retText);
                if ($retText !== '' && $returned === null) {
                    $problems[] = 'tanggal kembali tidak terbaca';
                }
                if ($problems !== []) {
                    $this->issue(LegacySource::SHEET_LOANS, $r, 'failed', 'Transaksi tidak diimpor: ' . implode('; ', $problems) . '.', $raw);
                    $this->bump('loans_failed');
                    continue;
                }
                if ($batchId === null) {
                    $prefix = 'HME-' . $g['ms'];
                    $batchId = (int) (Database::value('SELECT id FROM loan_batches WHERE legacy_prefix = ?', [$prefix]) ?? 0);
                    if ($batchId === 0) {
                        $batchId = Database::insert('loan_batches', ['legacy_prefix' => $prefix, 'user_id' => $this->userIds[$username], 'created_at' => $borrowed]);
                        $this->bump('loan_batches_created');
                    }
                }
                $loanId = Database::insert('loans', [
                    'legacy_id' => $legacyId, 'seq_no' => (int) $seq, 'legacy_row' => $r, 'batch_id' => $batchId,
                    'user_id' => $this->userIds[$username], 'item_id' => $this->itemIds[$code],
                    'item_name_snapshot' => mb_substr(LegacySource::text($row, 'B'), 0, 150),
                    'carrier_name_snapshot' => mb_substr(LegacySource::text($row, 'C'), 0, 100),
                    'borrowed_at' => $borrowed, 'returned_at' => $returned,
                    'team' => self::nullable(LegacySource::text($row, 'F'), 255),
                    'location' => mb_substr(trim(LegacySource::text($row, 'G')) !== '' ? LegacySource::text($row, 'G') : '-', 0, 255),
                    // Snapshot teks disimpan apa adanya (tanpa trim) agar laporan stok identik dengan sistem lama
                    'notes' => trim(LegacySource::text($row, 'H')) === '' ? null : mb_substr(LegacySource::text($row, 'H'), 0, 65000),
                    'status' => $status, 'created_at' => $borrowed,
                ]);
                $maxSeq = max($maxSeq, (int) $seq);
                $this->bump('loans_created');
                $msg = "Transaksi {$legacyId} diimpor (#{$loanId}).";
                if ($status === 'returned' && $returned === null) {
                    $this->issue(LegacySource::SHEET_LOANS, $r, 'needs_review', $msg . ' Status returned tetapi tanggal kembali kosong.', $raw);
                } elseif ($returned !== null && $returned < $borrowed) {
                    $this->issue(LegacySource::SHEET_LOANS, $r, 'needs_review', $msg . ' Tanggal kembali lebih awal dari tanggal bawa (jam perangkat staff).', $raw);
                } else {
                    $this->issue(LegacySource::SHEET_LOANS, $r, 'ok', $msg, $raw);
                }
            }
        }
        if ($maxSeq > Setting::int('loan_seq', 0)) {
            Setting::set('loan_seq', (string) $maxSeq);   // nomor urut berikutnya melanjutkan data lama (BR-06)
        }
    }

    private function importManual(): void
    {
        $existing = [];
        foreach (Database::all('SELECT source_row FROM legacy_manual_loans') as $m) {
            $existing[(int) $m['source_row']] = true;
        }
        $parsed = [];
        foreach ($this->src->rows(LegacySource::SHEET_LOANS) as $r => $row) {
            if (trim(LegacySource::text($row, 'J')) !== '') {
                continue;
            }
            $a = trim(LegacySource::text($row, 'A'));
            $b = trim(LegacySource::text($row, 'B'));
            if ($b === '' && $a !== '' && !ctype_digit($a)) {
                $this->issue(LegacySource::SHEET_LOANS, $r, 'ok', 'Baris pemisah dilewati: ' . mb_substr($a, 0, 120), ['text' => $a]);
                $this->bump('manual_separators');
                continue;
            }
            [$borrowed, $bFlag, $bRaw] = self::manualDate($row, 'D');
            [$returned, $rFlag, $rRaw] = self::manualDate($row, 'E');
            $parsed[$r] = [
                'row' => $row, 'borrowed' => $borrowed, 'returned' => $returned, 'b_raw' => $bRaw, 'r_raw' => $rRaw,
                'flag' => $bFlag === 'unparseable' ? 'unparseable' : (($bFlag === 'swapped_dm' || $rFlag === 'swapped_dm') ? 'swapped_dm' : 'ok'),
                'reasons' => [],
            ];
            if ($bFlag === 'unparseable') {
                $parsed[$r]['reasons'][] = 'tanggal bawa tidak terbaca';
            }
        }

        // Validasi: kembali ≥ bawa, dan tanggal bawa konsisten dengan baris di sekitarnya (± 60 hari). Tidak dikoreksi otomatis.
        $rows = array_keys($parsed);
        foreach ($rows as $i => $r) {
            $p = &$parsed[$r];
            // Tanggal kembali tanpa jam (00:00) dibandingkan per tanggal saja: "kembali 8/6" pada hari yang sama bukan kesalahan.
            $ret = $p['returned'];
            $cmpB = $ret !== null && substr($ret, 11) === '00:00:00' && $p['borrowed'] !== null ? substr($p['borrowed'], 0, 10) . ' 00:00:00' : $p['borrowed'];
            if ($p['borrowed'] !== null && $ret !== null && $ret < $cmpB) {
                $p['reasons'][] = 'tanggal kembali lebih awal dari tanggal bawa';
            }
            if ($p['borrowed'] !== null) {
                $near = [];
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($parsed[$rows[$j]]['borrowed'] !== null) { $near[] = $parsed[$rows[$j]]['borrowed']; break; }
                }
                for ($j = $i + 1; $j < count($rows); $j++) {
                    if ($parsed[$rows[$j]]['borrowed'] !== null) { $near[] = $parsed[$rows[$j]]['borrowed']; break; }
                }
                $far = $near !== [] && count(array_filter($near, static fn(string $n): bool => abs(LegacyDate::days($n, (string) $p['borrowed'])) > 60)) === count($near);
                if ($far) {
                    $p['reasons'][] = 'tanggal bawa jauh (> 60 hari) dari baris di sekitarnya';
                }
            }
            if ($p['reasons'] !== [] && $p['flag'] !== 'unparseable') {
                $p['flag'] = 'needs_review';
            }
            unset($p);
        }

        foreach ($parsed as $r => $p) {
            $row = $p['row'];
            $raw = ['no' => LegacySource::text($row, 'A'), 'item' => LegacySource::text($row, 'B'), 'borrowed' => $p['b_raw'], 'returned' => $p['r_raw']];
            if (isset($existing[$r])) {
                $this->issue(LegacySource::SHEET_LOANS, $r, 'duplicate', 'Catatan manual sudah diarsipkan — dilewati.', $raw);
                $this->bump('manual_skipped');
                continue;
            }
            $retText = trim(LegacySource::text($row, 'E'));
            Database::insert('legacy_manual_loans', [
                'source_row' => $r, 'legacy_no' => self::nullable(LegacySource::text($row, 'A'), 20),
                'item_text' => self::nullable(LegacySource::text($row, 'B'), 255), 'carrier_text' => self::nullable(LegacySource::text($row, 'C'), 255),
                'borrowed_raw' => self::nullable($p['b_raw'], 255), 'borrowed_at' => $p['borrowed'],
                'returned_raw' => self::nullable($p['r_raw'], 255), 'returned_at' => $p['returned'],
                'return_note' => $p['returned'] === null && $retText !== '' && !LegacySource::isNumeric($row, 'E') ? self::nullable($retText, 255) : null,
                'team' => self::nullable(LegacySource::text($row, 'F'), 255), 'location' => self::nullable(LegacySource::text($row, 'G'), 255),
                'notes' => self::nullable(LegacySource::text($row, 'H'), 65000), 'extra_note' => self::nullable(LegacySource::text($row, 'O'), 255),
                'parse_flag' => $p['flag'],
            ]);
            $this->bump('manual_created');
            $this->bump('manual_flag_' . $p['flag']);
            $cat = in_array($p['flag'], ['needs_review', 'unparseable'], true) ? 'needs_review' : 'ok';
            $msg = 'Catatan manual diarsipkan' . ($p['flag'] === 'swapped_dm' ? ' (tanggal dari sel Sheets: bulan↔hari ditukar)' : '')
                . ($p['reasons'] !== [] ? ' — perlu diperiksa: ' . implode('; ', $p['reasons']) : '') . '.';
            $this->issue(LegacySource::SHEET_LOANS, $r, $cat, $msg, $raw);
        }
    }

    /** @return array{0:?string,1:string,2:string} [tanggal, flag ok|swapped_dm|unparseable|empty, teks asli] */
    private static function manualDate(array $row, string $col): array
    {
        if (LegacySource::isNumeric($row, $col)) {
            $v = (float) $row[$col]['v'];
            $asSheets = LegacyDate::serial($v);
            $d = LegacyDate::serialSwapped($v);
            $rawText = 'Sel tanggal Sheets: ' . ($asSheets ?? (string) $v);
            return $d === null ? [null, 'unparseable', $rawText] : [$d, 'swapped_dm', $rawText];
        }
        $t = trim(LegacySource::text($row, $col));
        if ($t === '') {
            return [null, 'unparseable', ''];
        }
        $d = LegacyDate::freeText($t);
        return $d === null ? [null, 'unparseable', $t] : [$d, 'ok', $t];
    }

    private function importPqa(): void
    {
        $existing = [];
        foreach (Database::all('SELECT legacy_row FROM pqa_installations WHERE legacy_row IS NOT NULL') as $p) {
            $existing[(int) $p['legacy_row']] = true;
        }
        $statuses = ['Pending', 'Terpasang', 'Req Cabut', 'Dicabut', 'Ditolak'];
        foreach ($this->src->rows(LegacySource::SHEET_PQA) as $r => $row) {
            $code = trim(LegacySource::text($row, 'A'));
            if ($code === '') {
                continue;
            }
            $status = trim(LegacySource::text($row, 'F'));
            $raw = ['unit' => $code, 'location' => LegacySource::text($row, 'B'), 'status' => $status, 'actual_days' => LegacySource::text($row, 'I')];
            if (isset($existing[$r])) {
                $this->issue(LegacySource::SHEET_PQA, $r, 'duplicate', 'Pemasangan PQA sudah ada — dilewati.', $raw);
                $this->bump('pqa_skipped');
                continue;
            }
            $installed = self::pqaDate($row, 'E');
            $removed = self::pqaDate($row, 'H');
            $problems = [];
            if (!isset($this->itemIds[$code])) {
                $problems[] = "unit \"{$code}\" tidak ada";
            }
            if (!in_array($status, $statuses, true)) {
                $problems[] = "status \"{$status}\" tidak dikenal";
            }
            if ($problems !== []) {
                $this->issue(LegacySource::SHEET_PQA, $r, 'failed', 'Pemasangan tidak diimpor: ' . implode('; ', $problems) . '.', $raw);
                $this->bump('pqa_failed');
                continue;
            }
            $actual = LegacySource::text($row, 'I');
            $total = ctype_digit($actual) ? (int) $actual : null;
            $id = Database::insert('pqa_installations', [
                'legacy_row' => $r, 'item_id' => $this->itemIds[$code], 'requested_by' => null,
                'location' => mb_substr(trim(LegacySource::text($row, 'B')) ?: '-', 0, 255),
                'ppic' => mb_substr(trim(LegacySource::text($row, 'C')) ?: '-', 0, 100),
                'install_team' => mb_substr(trim(LegacySource::text($row, 'D')) ?: '-', 0, 255),
                'submitted_at' => null, 'installed_at' => $installed, 'status' => $status,
                'removal_team' => self::nullable(LegacySource::text($row, 'G'), 255), 'removed_at' => $removed,
                'total_days' => $status === 'Dicabut' ? $total : null,
                'target_days' => max(0, (int) LegacySource::text($row, 'J')),
                'pending_extension_days' => max(0, (int) LegacySource::text($row, 'K')),
            ]);
            $this->bump('pqa_created');
            $msg = "Pemasangan PQA {$code} di {$raw['location']} diimpor (#{$id}).";
            $calc = ($installed !== null && $removed !== null) ? (int) floor(LegacyDate::days($installed, $removed)) : null;
            if ($status === 'Dicabut' && $calc !== null && $total !== null && $calc !== $total) {
                $this->issue(LegacySource::SHEET_PQA, $r, 'needs_review', $msg . " Durasi di sheet {$total} hari, dihitung ulang {$calc} hari (nilai sheet dipakai).", $raw);
            } else {
                $this->issue(LegacySource::SHEET_PQA, $r, 'ok', $msg, $raw);
            }
        }
    }

    private static function pqaDate(array $row, string $col): ?string
    {
        if (LegacySource::isNumeric($row, $col)) {
            return LegacyDate::serial((float) $row[$col]['v']);
        }
        $t = trim(LegacySource::text($row, $col));
        return $t === '' ? null : (LegacyDate::appText($t) ?? LegacyDate::freeText($t));
    }

    private function importRegister(): void
    {
        $existing = [];
        foreach (Database::all('SELECT source_row FROM legacy_inventory_register') as $x) {
            $existing[(int) $x['source_row']] = true;
        }
        $section = 'Inventaris Kantor';
        // Bagian 1: NO, NAMA, JUMLAH, LOKASI PENYIMPANAN, STATUS(kondisi), KETERANGAN(posisi), catatan
        $map = ['no' => 'A', 'name' => 'B', 'qty' => 'C', 'unit' => null, 'storage_location' => 'D', 'condition_text' => 'E', 'current_position' => 'F', 'note' => 'G'];
        foreach ($this->src->rows(LegacySource::SHEET_REGISTER) as $r => $row) {
            $a = trim(LegacySource::text($row, 'A'));
            $b = trim(LegacySource::text($row, 'B'));
            if (strtoupper($a) === 'NO') {
                // Header bagian berikutnya. Bagian 2 punya kolom SATUAN → peta kolom bergeser satu.
                if (strtoupper(trim(LegacySource::text($row, 'D'))) === 'SATUAN') {
                    $map = ['no' => 'A', 'name' => 'B', 'qty' => 'C', 'unit' => 'D', 'storage_location' => 'E', 'condition_text' => 'F', 'current_position' => 'G', 'note' => null];
                }
                continue;
            }
            if ($b === '' && $a !== '' && !is_numeric($a)) {
                $section = mb_substr(self::titleCase($a), 0, 60);   // judul bagian
                continue;
            }
            if ($b === '' && $a === '') {
                continue;
            }
            if (isset($existing[$r])) {
                $this->issue(LegacySource::SHEET_REGISTER, $r, 'duplicate', 'Baris register sudah diarsipkan — dilewati.', ['name' => $b]);
                $this->bump('register_skipped');
                continue;
            }
            $v = static fn(?string $c, int $len): ?string => $c === null ? null : self::nullable(LegacySource::text($row, $c), $len);
            Database::insert('legacy_inventory_register', [
                'section' => $section, 'source_row' => $r, 'no' => $v($map['no'], 20), 'name' => $v($map['name'], 255),
                'qty' => $v($map['qty'], 20), 'unit' => $v($map['unit'], 30), 'storage_location' => $v($map['storage_location'], 255),
                'condition_text' => $v($map['condition_text'], 100), 'current_position' => $v($map['current_position'], 255), 'note' => $v($map['note'], 255),
            ]);
            $this->bump('register_created');
            $this->bump('register_section:' . $section);
            $this->issue(LegacySource::SHEET_REGISTER, $r, 'ok', "Diarsipkan ke register ({$section}).", ['name' => $b]);
        }
    }

    /** Status barang diturunkan ulang dari transaksi + PQA (aturan sistem baru); selisih dengan sheet dilaporkan. */
    private function syncStatuses(): void
    {
        foreach ($this->itemIds as $code => $id) {
            $derived = AvailabilityService::sync($id);
            if (isset($this->sheetStatus[$code]) && $this->sheetStatus[$code] !== $derived) {
                $this->issue(LegacySource::SHEET_ITEMS, null, 'needs_review',
                    "Status {$code} di sheet \"{$this->sheetStatus[$code]}\" tetapi menurut transaksi/PQA \"{$derived}\" — dipakai \"{$derived}\".", ['code' => $code]);
                $this->bump('status_mismatch');
            }
        }
    }

    // ======================================================================================
    //  Foto
    // ======================================================================================

    private function downloadPhotos(int $timeout): void
    {
        $tmpDir = sys_get_temp_dir();
        $done = [];
        foreach ($this->photoQueue as $p) {
            if ((int) Database::value('SELECT COUNT(*) FROM items WHERE id = ? AND photo_path IS NOT NULL', [$p['item_id']]) > 0) {
                continue;
            }
            try {
                if (!isset($done[$p['file_id']])) {
                    $tmp = tempnam($tmpDir, 'hmeph');
                    $ch = curl_init('https://lh3.googleusercontent.com/d/' . rawurlencode($p['file_id']));
                    $fh = fopen((string) $tmp, 'wb');
                    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                        CURLOPT_TIMEOUT => $timeout, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_USERAGENT => 'HME-Migration/1.0', CURLOPT_MAXFILESIZE => 15 * 1024 * 1024]);
                    $ok = curl_exec($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                    curl_close($ch);
                    fclose($fh);
                    if ($ok === false || $code !== 200 || strpos($type, 'image/') !== 0) {
                        @unlink((string) $tmp);
                        throw new RuntimeException("foto tidak dapat diunduh (HTTP {$code}" . ($type !== '' ? ", {$type}" : '') . ') — kemungkinan masih privat di Drive');
                    }
                    $done[$p['file_id']] = ImageService::storeLocal((string) $tmp);
                    @unlink((string) $tmp);
                }
                Database::run('UPDATE items SET photo_path = ? WHERE id = ?', [$done[$p['file_id']], $p['item_id']]);
                $this->bump('photos_downloaded');
            } catch (Throwable $e) {
                $code = (string) Database::value('SELECT code FROM items WHERE id = ?', [$p['item_id']]);
                $this->issue(LegacySource::SHEET_ITEMS, null, 'needs_review', "Foto {$code}: " . $e->getMessage() . '. URL lama tetap disimpan.', ['code' => $code]);
                $this->bump('photos_failed');
            }
        }
    }

    // ======================================================================================
    //  Laporan
    // ======================================================================================

    /** @param array<string,mixed>|null $raw */
    private function issue(string $sheet, ?int $row, string $category, string $message, ?array $raw = null): void
    {
        $this->issues[] = ['sheet' => $sheet, 'row' => $row, 'category' => $category, 'message' => mb_substr($message, 0, 500), 'raw' => $raw];
    }

    private function bump(string $key, int $n = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $n;
    }

    /** @return array<string,mixed> */
    private function finish(int $runId, string $status, ?string $error): array
    {
        $counts = ['ok' => 0, 'failed' => 0, 'duplicate' => 0, 'needs_review' => 0];
        foreach (array_chunk($this->issues, 200) as $chunk) {
            $ph = [];
            $params = [];
            foreach ($chunk as $i) {
                $counts[$i['category']]++;
                $ph[] = '(?, ?, ?, ?, ?, ?)';
                array_push($params, $runId, $i['sheet'], $i['row'], $i['category'], $i['message'],
                    $i['raw'] === null ? null : json_encode($i['raw'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            }
            Database::run('INSERT INTO migration_issues (run_id, sheet, source_row, category, message, raw_json) VALUES ' . implode(',', $ph), $params);
        }
        ksort($this->stats);
        $summary = ['run_id' => $runId, 'mode' => $this->dry ? 'dry' : 'real', 'status' => $status, 'error' => $error,
                    'source' => basename($this->src->path()), 'sha256' => $this->src->sha256(), 'stats' => $this->stats, 'counts' => $counts];
        Database::run("UPDATE migration_runs SET finished_at = NOW(), status = ?, summary_json = ? WHERE id = ?",
            [$status, json_encode($summary, JSON_UNESCAPED_UNICODE), $runId]);
        return $summary;
    }

    private static function nullable(string $s, int $len): ?string
    {
        $s = trim($s);
        return $s === '' ? null : mb_substr($s, 0, $len);
    }

    private static function titleCase(string $s): string
    {
        return mb_convert_case(mb_strtolower(trim($s)), MB_CASE_TITLE, 'UTF-8');
    }
}
