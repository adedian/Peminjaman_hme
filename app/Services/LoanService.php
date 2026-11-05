<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use PDOException;

/**
 * Peminjaman barang. "Request" (pengajuan) = satu loan_batches yang berisi 1..N loans (satu per barang).
 *
 * Aturan yang ditegakkan DI SINI (dan, untuk anti double-booking, juga oleh unique key di database):
 *  BR-02  staff yang masih punya loan *approved* tidak boleh mengajukan lagi (pending tidak memblokir)
 *  BR-03  barang hanya bisa diajukan bila aktif, Tersedia, tanpa loan pending/approved lain, dan bukan PQA aktif (BR-29)
 *  BR-04  1..30 barang per pengajuan, tanpa duplikat
 *  BR-05  lokasi wajib, tanggal pinjam wajib (jam = jam server), tim & catatan opsional
 *  BR-06  nomor urut atomik, kode HME-<ms> (pengajuan) dan HME-<ms>-<n> (barang), nama peminjam & barang di-snapshot
 *  BR-07  selama pending, pemilik boleh mengganti unit
 *  BR-08  hanya admin menyetujui/menolak
 *  BR-09  setujui → barang 'Dipinjam'; tolak → status rejected, barang tidak berubah
 *  BR-10  pemilik mengonfirmasi pengembalian (jam server) → barang 'Tersedia' (dihitung ulang)
 *  Q5     pemilik boleh membatalkan pengajuan yang masih pending
 *
 * Urutan penguncian (seragam, mencegah deadlock): baris user → baris barang (urut id) → baris loan.
 * Orang lain yang mencoba mengakses pengajuan bukan miliknya mendapat 404 (tidak membocorkan keberadaannya).
 */
final class LoanService
{
    public const MAX_ITEMS = 30;                 // batas teknis ukuran pengajuan (data lama maksimal 7)
    private const DATE_MIN = '2020-01-01';

    private const STATUSES = ['pending', 'approved', 'returned', 'rejected', 'cancelled'];

    // ======================================================================================
    //  Pembacaan
    // ======================================================================================

    /**
     * Barang yang dapat diajukan SEKARANG (untuk form pinjam). Bagian dari BR-03; server mengecek ulang saat submit.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function availableItems(): array
    {
        $rows = Database::all(
            "SELECT i.id, i.code, i.name, i.photo_path, i.is_pqa, c.name AS category_name
             FROM items i JOIN categories c ON c.id = i.category_id
             WHERE i.is_active = 1 AND i.master_status = 'Tersedia'
               AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status IN ('pending','approved'))
               AND NOT EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut'))
             ORDER BY c.sort_order, i.name, i.id"
        );
        return array_map(static function (array $r): array {
            $photo = $r['photo_path'] === null ? null : (string) $r['photo_path'];
            return [
                'id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'category_name' => (string) $r['category_name'], 'is_pqa' => (int) $r['is_pqa'] === 1,
                'thumb_url' => $photo === null ? null : url('/media/items/' . ImageService::thumbName($photo)),
            ];
        }, $rows);
    }

    /**
     * Ringkasan jumlah per status. Staff: milik sendiri. Admin: semua.
     *
     * @param array<string,mixed> $viewer
     * @return array{pending:int,approved:int,returned:int,rejected:int,cancelled:int,requests_pending:int}
     */
    public static function summary(array $viewer): array
    {
        $where = $viewer['role'] === 'admin' ? '' : ' WHERE user_id = ?';
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM loans' . $where . ' GROUP BY status', $viewer['role'] === 'admin' ? [] : [(int) $viewer['id']]);
        $out = ['pending' => 0, 'approved' => 0, 'returned' => 0, 'rejected' => 0, 'cancelled' => 0];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        $out['overdue'] = (int) Database::value(
            "SELECT COUNT(*) FROM loans WHERE status = 'approved' AND due_at IS NOT NULL AND due_at < NOW()" . ($viewer['role'] === 'admin' ? '' : ' AND user_id = ?'),
            $viewer['role'] === 'admin' ? [] : [(int) $viewer['id']]
        );
        $out['requests_pending'] = (int) Database::value(
            "SELECT COUNT(DISTINCT batch_id) FROM loans WHERE status = 'pending'" . ($viewer['role'] === 'admin' ? '' : ' AND user_id = ?'),
            $viewer['role'] === 'admin' ? [] : [(int) $viewer['id']]
        );
        return $out;
    }

    /** Jumlah pengajuan menunggu (badge navigasi admin). */
    public static function pendingRequestCount(): int
    {
        return (int) Database::value("SELECT COUNT(DISTINCT batch_id) FROM loans WHERE status = 'pending'");
    }

    /**
     * Daftar pengajuan. Staff hanya melihat miliknya (dipaksa server). Admin melihat semua.
     *
     * @param array<string,mixed> $viewer
     * @param array<string,mixed> $filters status (pending|approved|returned|rejected|cancelled|open), q, user_id (admin)
     * @return array{requests:array<int,array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public static function listRequests(array $viewer, array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $f = Validator::make($filters, [
            'status'  => 'nullable|in:pending,approved,returned,rejected,cancelled,open,overdue',
            'q'       => 'nullable|string|max:100',
            'user_id' => 'nullable|int|min:1',
        ])->validate();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        if ($viewer['role'] !== 'admin') {
            $where[] = 'l.user_id = ?';
            $params[] = (int) $viewer['id'];
        } elseif ($f['user_id'] !== null) {
            $where[] = 'l.user_id = ?';
            $params[] = (int) $f['user_id'];
        }
        if ($f['status'] === 'open') {
            $where[] = "l.status IN ('pending','approved')";
        } elseif ($f['status'] === 'overdue') {
            $where[] = "l.status = 'approved' AND l.due_at IS NOT NULL AND l.due_at < NOW()";
        } elseif ($f['status'] !== null) {
            $where[] = 'l.status = ?';
            $params[] = $f['status'];
        }
        if ($f['q'] !== null && $f['q'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $where[] = "(l.item_name_snapshot LIKE ? ESCAPE '\\\\' OR l.location LIKE ? ESCAPE '\\\\' OR u.name LIKE ? ESCAPE '\\\\'
                         OR u.username LIKE ? ESCAPE '\\\\' OR b.legacy_prefix LIKE ? ESCAPE '\\\\' OR i.code LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM loans l JOIN loan_batches b ON b.id = l.batch_id JOIN users u ON u.id = l.user_id JOIN items i ON i.id = l.item_id';

        $total = (int) Database::value('SELECT COUNT(DISTINCT l.batch_id)' . $from . $w, $params);
        $ids = array_map('intval', array_column(Database::all(
            'SELECT l.batch_id' . $from . $w . ' GROUP BY l.batch_id ORDER BY MAX(l.created_at) DESC, l.batch_id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        ), 'batch_id'));

        return ['requests' => self::present($ids, $viewer), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Satu pengajuan. Bukan pemilik & bukan admin → null (pemanggil menjawab 404).
     *
     * @param array<string,mixed> $viewer
     * @return array<string,mixed>|null
     */
    public static function findRequest(int $batchId, array $viewer): ?array
    {
        $list = self::present([$batchId], $viewer);
        return $list[0] ?? null;
    }

    // ======================================================================================
    //  Pengajuan (staff)
    // ======================================================================================

    /**
     * @param array<string,mixed> $user pelaku (staff)
     * @param array<string,mixed> $input item_ids[], borrow_date, team, location, notes
     * @return array<string,mixed> pengajuan yang dibuat
     */
    public static function submit(array $user, array $input, Request $request): array
    {
        self::assertRole($user, 'staff');
        $d = self::validateSubmit($input);

        $batchId = 0;
        $attempt = 0;
        while (true) {
            try {
                $batchId = Database::transaction(function () use ($user, $d): int {
                    self::lockUser((int) $user['id']);

                    // BR-02
                    if ((int) Database::value("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'approved'", [(int) $user['id']]) > 0) {
                        throw new HttpException(409, 'has_active_loan', 'Anda masih memiliki barang yang dipinjam. Kembalikan terlebih dahulu sebelum mengajukan peminjaman baru.');
                    }

                    // BR-03 / BR-29 — barang dikunci, lalu dicek di bawah kunci
                    $items = AvailabilityService::lockItems($d['item_ids']);
                    $bad = [];
                    foreach ($d['item_ids'] as $id) {
                        if (!isset($items[$id])) {
                            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['item_ids' => 'Ada barang yang tidak ditemukan.']);
                        }
                        if (!AvailabilityService::isFree($items[$id])) {
                            $bad[] = (string) $items[$id]['name'];
                        }
                    }
                    if ($bad !== []) {
                        throw self::unavailable($bad);
                    }

                    $ms = (int) floor(microtime(true) * 1000);
                    $batch = Database::insert('loan_batches', ['legacy_prefix' => 'HME-' . $ms, 'user_id' => (int) $user['id']]);
                    $borrowedAt = $d['borrow_date'] . ' ' . date('H:i:s');
                    foreach ($d['item_ids'] as $idx => $itemId) {
                        Database::insert('loans', [
                            'legacy_id'             => 'HME-' . $ms . '-' . $idx,
                            'seq_no'                => AvailabilityService::nextLoanSeq(),
                            'batch_id'              => $batch,
                            'user_id'               => (int) $user['id'],
                            'item_id'               => $itemId,
                            'item_name_snapshot'    => (string) $items[$itemId]['name'],
                            'carrier_name_snapshot' => (string) $user['name'],
                            'borrowed_at'           => $borrowedAt,
                            'due_at'                => $d['due_at'],
                            'team'                => $d['team'],
                            'location'              => $d['location'],
                            'notes'                 => $d['notes'],
                            'status'                => 'pending',
                        ]);
                        ChangeFeed::emit('item', $itemId, 'availability', (int) $user['id']);
                    }
                    ChangeFeed::emit('request', $batch, 'submitted', (int) $user['id']);
                    return $batch;
                });
                break;
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                $msg = (string) $e->getMessage();
                // Kode HME-<ms> bentrok (dua pengajuan di milidetik yang sama) → ulangi dengan waktu baru
                if ($code === 1062 && strpos($msg, 'uq_batches_legacy') !== false && ++$attempt < 5) {
                    usleep(2000);
                    continue;
                }
                // Pengaman terakhir database: barang direbut transaksi lain di antara cek dan insert
                if ($code === 1062 && strpos($msg, 'uq_loans_active_item') !== false) {
                    throw self::unavailable([]);
                }
                throw $e;
            }
        }

        $req = self::findRequest($batchId, $user) ?? [];
        AuditService::log('request.submitted', (int) $user['id'], 'request', $batchId, null,
            ['items' => array_column(array_column($req['loans'] ?? [], 'item'), 'code'), 'location' => $d['location']], $request);
        LoanNotifier::submitted($req);
        return $req;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> pengajuan setelah dibatalkan */
    public static function cancelRequest(int $batchId, array $user, Request $request): array
    {
        $ids = self::ownedLoanIds($batchId, (int) $user['id'], 'pending');
        return self::cancelLoans($ids, $batchId, $user, $request);
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public static function cancelLoan(int $loanId, array $user, Request $request): array
    {
        $loan = self::ownedLoan($loanId, (int) $user['id']);
        return self::cancelLoans([$loanId], (int) $loan['batch_id'], $user, $request);
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public static function returnRequest(int $batchId, array $user, Request $request): array
    {
        $ids = self::ownedLoanIds($batchId, (int) $user['id'], 'approved');
        return self::returnLoans($ids, $batchId, $user, $request);
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public static function returnLoan(int $loanId, array $user, Request $request): array
    {
        $loan = self::ownedLoan($loanId, (int) $user['id']);
        return self::returnLoans([$loanId], (int) $loan['batch_id'], $user, $request);
    }

    /**
     * Ganti unit pada loan yang masih pending (BR-07). Hanya pemilik.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function swapItem(int $loanId, $newItemId, array $user, Request $request): array
    {
        $new = self::intId($newItemId, 'item_id');
        $loan = self::ownedLoan($loanId, (int) $user['id']);
        $batchId = (int) $loan['batch_id'];

        try {
            $oldItem = Database::transaction(function () use ($loanId, $new, $user): int {
                self::lockUser((int) $user['id']);
                $row = self::lockLoan($loanId);
                if ($row['status'] !== 'pending') {
                    throw new HttpException(409, 'invalid_state', 'Unit hanya dapat diganti selama pengajuan masih menunggu persetujuan.');
                }
                $old = (int) $row['item_id'];
                if ($old === $new) {
                    throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['item_id' => 'Pilih barang yang berbeda dari unit saat ini.']);
                }
                $items = AvailabilityService::lockItems([$old, $new], $loanId);
                if (!isset($items[$new])) {
                    throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['item_id' => 'Barang tidak ditemukan.']);
                }
                if (!AvailabilityService::isFree($items[$new])) {
                    throw self::unavailable([(string) $items[$new]['name']]);
                }
                Database::update('loans', ['item_id' => $new, 'item_name_snapshot' => (string) $items[$new]['name']], ['id' => $loanId]);
                ChangeFeed::emit('item', $old, 'availability', (int) $user['id']);
                ChangeFeed::emit('item', $new, 'availability', (int) $user['id']);
                ChangeFeed::emit('request', (int) $row['batch_id'], 'updated', (int) $user['id']);
                return $old;
            });
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw self::unavailable([]);
            }
            throw $e;
        }

        AuditService::log('loan.swapped', (int) $user['id'], 'loan', $loanId, ['item_id' => $oldItem], ['item_id' => $new], $request);
        return self::findRequest($batchId, $user) ?? [];
    }

    // ======================================================================================
    //  Keputusan admin
    // ======================================================================================

    /** @param array<string,mixed> $admin @return array<string,mixed> */
    public static function approveLoan(int $loanId, array $admin, Request $request): array
    {
        return self::decide(self::pendingIdsOfLoan($loanId), 'approved', $admin, $request);
    }

    /** @param array<string,mixed> $admin @return array<string,mixed> */
    public static function rejectLoan(int $loanId, array $admin, Request $request): array
    {
        return self::decide(self::pendingIdsOfLoan($loanId), 'rejected', $admin, $request);
    }

    /** Setujui SEMUA barang pending pada pengajuan (semua-atau-tidak-sama-sekali). @param array<string,mixed> $admin */
    public static function approveRequest(int $batchId, array $admin, Request $request): array
    {
        return self::decide(self::pendingIdsOfBatch($batchId), 'approved', $admin, $request);
    }

    /** @param array<string,mixed> $admin @return array<string,mixed> */
    public static function rejectRequest(int $batchId, array $admin, Request $request): array
    {
        return self::decide(self::pendingIdsOfBatch($batchId), 'rejected', $admin, $request);
    }

    // ======================================================================================
    //  Implementasi
    // ======================================================================================

    /**
     * @param array<int,int> $loanIds semua milik SATU pengajuan
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    private static function decide(array $loanIds, string $decision, array $admin, Request $request): array
    {
        self::assertRole($admin, 'admin');
        $batchId = (int) Database::value('SELECT batch_id FROM loans WHERE id = ?', [$loanIds[0]]);
        $userId = (int) Database::value('SELECT user_id FROM loans WHERE id = ?', [$loanIds[0]]);
        $approve = $decision === 'approved';

        try {
            Database::transaction(function () use ($loanIds, $approve, $admin, $userId): void {
                self::lockUser($userId);   // serialisasi dengan pengajuan baru pemilik (BR-02)
                $itemIds = array_map('intval', array_column(Database::all(
                    'SELECT item_id FROM loans WHERE id IN (' . implode(',', array_fill(0, count($loanIds), '?')) . ')', $loanIds
                ), 'item_id'));
                $items = AvailabilityService::lockItems($itemIds);

                $bad = [];
                foreach ($loanIds as $lid) {
                    $row = self::lockLoan($lid);
                    if ($row['status'] !== 'pending') {
                        throw new HttpException(409, 'invalid_state', 'Pengajuan ini sudah diproses sebelumnya.');
                    }
                    if ($approve) {
                        $it = $items[(int) $row['item_id']] ?? null;
                        // Loan ini sendiri berstatus pending → dikecualikan dari "busy_loan"; yang dicek: aktif, Tersedia, bukan PQA aktif
                        if ($it === null || (int) $it['is_active'] !== 1 || $it['master_status'] !== 'Tersedia' || (int) $it['busy_pqa'] !== 0) {
                            $bad[] = (string) ($it['name'] ?? $row['item_name_snapshot']);
                        }
                    }
                }
                if ($bad !== []) {
                    throw self::unavailable($bad, 'Tidak dapat disetujui. Barang berikut sedang tidak tersedia: ');
                }

                foreach ($loanIds as $lid) {
                    Database::run(
                        'UPDATE loans SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?',
                        [$approve ? 'approved' : 'rejected', (int) $admin['id'], $lid]
                    );
                }
                foreach (array_keys($items) as $itemId) {
                    AvailabilityService::sync($itemId);
                    ChangeFeed::emit('item', $itemId, 'availability', (int) $admin['id']);
                }
                ChangeFeed::emit('request', (int) Database::value('SELECT batch_id FROM loans WHERE id = ?', [$loanIds[0]]), $approve ? 'approved' : 'rejected', (int) $admin['id'], $userId);
            });
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw self::unavailable([]);
            }
            throw $e;
        }

        foreach ($loanIds as $lid) {
            AuditService::log($approve ? 'loan.approved' : 'loan.rejected', (int) $admin['id'], 'loan', $lid, ['status' => 'pending'], ['status' => $decision], $request);
        }
        $req = self::findRequest($batchId, $admin) ?? [];
        LoanNotifier::decided($req, $decision, $loanIds, (int) $admin['id']);
        return $req;
    }

    /** @param array<int,int> $ids @param array<string,mixed> $user */
    private static function cancelLoans(array $ids, int $batchId, array $user, Request $request): array
    {
        Database::transaction(function () use ($ids, $user, $batchId): void {
            self::lockUser((int) $user['id']);
            foreach ($ids as $lid) {
                $row = self::lockLoan($lid);
                if ($row['status'] !== 'pending') {
                    throw new HttpException(409, 'invalid_state', 'Pengajuan hanya dapat dibatalkan selama masih menunggu persetujuan.');
                }
                Database::run("UPDATE loans SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?", [$lid]);
                ChangeFeed::emit('item', (int) $row['item_id'], 'availability', (int) $user['id']);
            }
            ChangeFeed::emit('request', $batchId, 'cancelled', (int) $user['id']);
        });
        foreach ($ids as $lid) {
            AuditService::log('loan.cancelled', (int) $user['id'], 'loan', $lid, ['status' => 'pending'], ['status' => 'cancelled'], $request);
        }
        $req = self::findRequest($batchId, $user) ?? [];
        LoanNotifier::cancelled($req, $ids, (int) $user['id']);
        return $req;
    }

    /** @param array<int,int> $ids @param array<string,mixed> $user */
    private static function returnLoans(array $ids, int $batchId, array $user, Request $request): array
    {
        Database::transaction(function () use ($ids, $user, $batchId): void {
            self::lockUser((int) $user['id']);
            $itemIds = array_map('intval', array_column(Database::all(
                'SELECT item_id FROM loans WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids
            ), 'item_id'));
            AvailabilityService::lockItems($itemIds);
            foreach ($ids as $lid) {
                $row = self::lockLoan($lid);
                if ($row['status'] !== 'approved') {
                    throw new HttpException(409, 'invalid_state', 'Hanya barang yang sedang dipinjam yang dapat dikonfirmasi pengembaliannya.');
                }
                Database::run(
                    "UPDATE loans SET status = 'returned', returned_at = NOW(), returned_by = ? WHERE id = ?",
                    [(int) $user['id'], $lid]
                );
                AvailabilityService::sync((int) $row['item_id']);
                ChangeFeed::emit('item', (int) $row['item_id'], 'availability', (int) $user['id']);
            }
            ChangeFeed::emit('request', $batchId, 'returned', (int) $user['id']);
        });
        foreach ($ids as $lid) {
            AuditService::log('loan.returned', (int) $user['id'], 'loan', $lid, ['status' => 'approved'], ['status' => 'returned'], $request);
        }
        $req = self::findRequest($batchId, $user) ?? [];
        LoanNotifier::returned($req, $ids, (int) $user['id']);
        return $req;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{item_ids:array<int,int>,borrow_date:string,due_at:string,team:?string,location:string,notes:?string}
     */
    private static function validateSubmit(array $input): array
    {
        $raw = $input['item_ids'] ?? null;
        $errors = [];
        $ids = [];
        if (!is_array($raw) || $raw === []) {
            $errors['item_ids'] = 'Pilih minimal satu barang.';
        } elseif (count($raw) > self::MAX_ITEMS) {
            $errors['item_ids'] = 'Maksimal ' . self::MAX_ITEMS . ' barang dalam satu pengajuan.';
        } else {
            foreach ($raw as $v) {
                if ((!is_int($v) && !(is_string($v) && ctype_digit($v))) || (int) $v < 1 || (int) $v > 2147483647) {
                    $errors['item_ids'] = 'Daftar barang tidak valid.';
                    break;
                }
                $ids[] = (int) $v;
            }
            if (!isset($errors['item_ids']) && count($ids) !== count(array_unique($ids))) {
                $errors['item_ids'] = 'Barang yang sama tidak boleh dipilih dua kali.';
            }
        }

        $today = date('Y-m-d');
        if (!isset($input['borrow_date']) || $input['borrow_date'] === '' || $input['borrow_date'] === null) {
            $input['borrow_date'] = $today;
        }
        $v = Validator::make($input, [
            'borrow_date' => 'required|date',
            'team'        => 'nullable|string|max:255',
            'location'    => 'required|string|min:2|max:255',
            'notes'       => 'nullable|string|max:2000',
        ], ['borrow_date' => 'Tanggal pinjam', 'team' => 'Tim', 'location' => 'Lokasi tujuan', 'notes' => 'Keterangan']);
        $errors += $v->errors();
        if (!isset($errors['borrow_date']) && ($input['borrow_date'] < self::DATE_MIN || $input['borrow_date'] > date('Y-m-d', strtotime('+1 year')))) {
            $errors['borrow_date'] = 'Tanggal pinjam di luar rentang yang wajar.';
        }
        $due = null;
        if (!isset($errors['borrow_date'])) {
            [$due, $dueErr] = self::parseDue($input['due_at'] ?? null, (string) $input['borrow_date']);
            if ($dueErr !== null) {
                $errors['due_at'] = $dueErr;
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', $errors);
        }
        $c = $v->validated();
        return [
            'item_ids'    => $ids,
            'borrow_date' => (string) $c['borrow_date'],
            'due_at'      => (string) $due,
            'team'        => $c['team'] === null ? null : (string) $c['team'],
            'location'    => (string) $c['location'],
            'notes'       => $c['notes'] === null ? null : (string) $c['notes'],
        ];
    }

    /**
     * Batas pengembalian (wajib). Format "YYYY-MM-DD HH:MM" atau "YYYY-MM-DDTHH:MM" (input datetime-local), waktu WIB.
     * Harus setelah saat ini dan setelah waktu pinjam, dan paling lama `loan_max_days` hari dari yang lebih akhir
     * di antara keduanya.
     *
     * @param mixed $raw
     * @return array{0:?string,1:?string} [nilai 'Y-m-d H:i:00' | null, pesan galat | null]
     */
    public static function parseDue($raw, string $borrowDate): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [null, 'Batas pengembalian wajib diisi.'];
        }
        $s = str_replace('T', ' ', trim($raw));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s)) {
            return [null, 'Format batas pengembalian tidak valid.'];
        }
        $s = substr($s, 0, 16);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $s);
        $errs = \DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errs !== false && ($errs['warning_count'] > 0 || $errs['error_count'] > 0))) {
            return [null, 'Batas pengembalian bukan tanggal/jam yang valid.'];
        }
        $now = new \DateTimeImmutable('now');
        $borrowed = new \DateTimeImmutable($borrowDate . ' ' . $now->format('H:i:s'));
        $base = $borrowed > $now ? $borrowed : $now;
        if ($dt <= $base) {
            return [null, 'Batas pengembalian harus setelah waktu peminjaman.'];
        }
        $max = \App\Models\Setting::int('loan_max_days', 30);
        if ($dt > $base->modify('+' . $max . ' days')) {
            return [null, 'Batas pengembalian maksimal ' . $max . ' hari dari waktu peminjaman.'];
        }
        return [$dt->format('Y-m-d H:i:00'), null];
    }

    /** Pertahanan berlapis: aturan peran juga dijaga di service, bukan hanya di middleware route. @param array<string,mixed> $user */
    private static function assertRole(array $user, string $role): void
    {
        if (($user['role'] ?? null) !== $role) {
            throw new HttpException(403, 'forbidden', 'Anda tidak memiliki akses ke fitur ini.');
        }
    }

    /** @param mixed $v */
    private static function intId($v, string $field): int
    {
        if ((!is_int($v) && !(is_string($v) && ctype_digit($v))) || (int) $v < 1 || (int) $v > 2147483647) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', [$field => 'Barang tidak valid.']);
        }
        return (int) $v;
    }

    /** @param array<int,string> $names */
    private static function unavailable(array $names, string $prefix = 'Barang berikut sudah tidak tersedia: '): HttpException
    {
        $msg = $names === [] ? 'Ada barang yang baru saja diambil pengguna lain. Muat ulang daftar barang lalu pilih kembali.' : $prefix . implode(', ', $names) . '.';
        return new HttpException(409, 'item_unavailable', $msg, ['item_ids' => $msg]);
    }

    /** @return array<string,mixed> */
    private static function lockUser(int $id): array
    {
        $r = Database::one('SELECT id, name, role, is_active FROM users WHERE id = ? FOR UPDATE', [$id]);
        if ($r === null) {
            throw new HttpException(401, 'unauthenticated', 'Silakan masuk terlebih dahulu.');
        }
        return $r;
    }

    /** @return array<string,mixed> */
    private static function lockLoan(int $id): array
    {
        $r = Database::one('SELECT id, batch_id, user_id, item_id, item_name_snapshot, status FROM loans WHERE id = ? FOR UPDATE', [$id]);
        if ($r === null) {
            throw new HttpException(404, 'not_found', 'Data peminjaman tidak ditemukan.');
        }
        return $r;
    }

    /** Loan milik user, atau 404 (tidak membedakan "tidak ada" dari "bukan milik Anda"). @return array<string,mixed> */
    private static function ownedLoan(int $loanId, int $userId): array
    {
        $r = Database::one('SELECT id, batch_id, status FROM loans WHERE id = ? AND user_id = ?', [$loanId, $userId]);
        if ($r === null) {
            throw new HttpException(404, 'not_found', 'Data peminjaman tidak ditemukan.');
        }
        return $r;
    }

    /** @return array<int,int> id loan berstatus $status dalam pengajuan milik user; 404 bila bukan milik/ tidak ada; 409 bila tak ada yang berstatus itu */
    private static function ownedLoanIds(int $batchId, int $userId, string $status): array
    {
        $all = Database::all('SELECT id, status FROM loans WHERE batch_id = ? AND user_id = ? ORDER BY id', [$batchId, $userId]);
        if ($all === []) {
            throw new HttpException(404, 'not_found', 'Pengajuan tidak ditemukan.');
        }
        $ids = array_map('intval', array_column(array_filter($all, static fn($r) => $r['status'] === $status), 'id'));
        if ($ids === []) {
            throw new HttpException(409, 'invalid_state', $status === 'pending'
                ? 'Tidak ada barang yang masih menunggu persetujuan pada pengajuan ini.'
                : 'Tidak ada barang yang sedang dipinjam pada pengajuan ini.');
        }
        return $ids;
    }

    /** @return array<int,int> */
    private static function pendingIdsOfLoan(int $loanId): array
    {
        $r = Database::one('SELECT id, status FROM loans WHERE id = ?', [$loanId]);
        if ($r === null) {
            throw new HttpException(404, 'not_found', 'Data peminjaman tidak ditemukan.');
        }
        if ($r['status'] !== 'pending') {
            throw new HttpException(409, 'invalid_state', 'Pengajuan ini sudah diproses sebelumnya.');
        }
        return [$loanId];
    }

    /** @return array<int,int> */
    private static function pendingIdsOfBatch(int $batchId): array
    {
        $all = Database::all('SELECT id, status FROM loans WHERE batch_id = ? ORDER BY id', [$batchId]);
        if ($all === []) {
            throw new HttpException(404, 'not_found', 'Pengajuan tidak ditemukan.');
        }
        $ids = array_map('intval', array_column(array_filter($all, static fn($r) => $r['status'] === 'pending'), 'id'));
        if ($ids === []) {
            throw new HttpException(409, 'invalid_state', 'Pengajuan ini sudah diproses sebelumnya.');
        }
        return $ids;
    }

    /**
     * Bangun representasi pengajuan (dengan loans) untuk daftar id, mempertahankan urutan id.
     *
     * @param array<int,int> $batchIds
     * @param array<string,mixed> $viewer
     * @return array<int,array<string,mixed>>
     */
    private static function present(array $batchIds, array $viewer): array
    {
        if ($batchIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($batchIds), '?'));
        $rows = Database::all(
            "SELECT l.id, l.batch_id, l.legacy_id, l.seq_no, l.user_id, l.item_id, l.item_name_snapshot, l.carrier_name_snapshot,
                    l.borrowed_at, l.due_at, TIMESTAMPDIFF(SECOND, NOW(), l.due_at) AS due_in, l.returned_at, l.team, l.location, l.notes,
                    l.status, l.decided_at, l.cancelled_at, l.created_at,
                    i.code AS item_code, i.photo_path, i.is_pqa, b.legacy_prefix, u.name AS user_name, u.username, u.initials
             FROM loans l JOIN items i ON i.id = l.item_id JOIN loan_batches b ON b.id = l.batch_id JOIN users u ON u.id = l.user_id
             WHERE l.batch_id IN ({$in}) ORDER BY l.seq_no, l.id",
            $batchIds
        );

        $isAdmin = $viewer['role'] === 'admin';
        $groups = [];
        foreach ($rows as $r) {
            $owner = (int) $r['user_id'] === (int) $viewer['id'];
            if (!$isAdmin && !$owner) {
                continue; // staff tidak pernah melihat milik orang lain
            }
            $bid = (int) $r['batch_id'];
            $photo = $r['photo_path'] === null ? null : (string) $r['photo_path'];
            $status = (string) $r['status'];
            $groups[$bid]['loans'][] = [
                'id'          => (int) $r['id'],
                'code'        => $r['legacy_id'] === null ? null : (string) $r['legacy_id'],
                'seq_no'      => (int) $r['seq_no'],
                'status'      => $status,
                'item'        => [
                    'id' => (int) $r['item_id'], 'code' => (string) $r['item_code'], 'name' => (string) $r['item_name_snapshot'],
                    'is_pqa' => (int) $r['is_pqa'] === 1,
                    'thumb_url' => $photo === null ? null : url('/media/items/' . ImageService::thumbName($photo)),
                ],
                'decided_at'  => $r['decided_at'],
                'returned_at' => $r['returned_at'],
                'due_at'      => $r['due_at'],
                // Sisa waktu dihitung di server (jam DB) agar countdown di browser tidak bergantung jam perangkat
                'remaining_seconds' => ($r['due_at'] !== null && in_array($status, ['pending', 'approved'], true)) ? (int) $r['due_in'] : null,
                'overdue'     => $status === 'approved' && $r['due_at'] !== null && (int) $r['due_in'] < 0,
                'can'         => [
                    'approve' => $isAdmin && $status === 'pending',
                    'reject'  => $isAdmin && $status === 'pending',
                    'cancel'  => $owner && $status === 'pending',
                    'swap'    => $owner && $status === 'pending',
                    'return'  => $owner && $status === 'approved',
                ],
            ];
            if (!isset($groups[$bid]['meta'])) {
                $groups[$bid]['meta'] = [
                    'id'          => $bid,
                    'code'        => $r['legacy_prefix'] === null ? 'REQ-' . $bid : (string) $r['legacy_prefix'],
                    'user'        => ['id' => (int) $r['user_id'], 'name' => (string) $r['user_name'], 'username' => (string) $r['username'], 'initials' => (string) $r['initials']],
                    'borrowed_at' => (string) $r['borrowed_at'],
                    'due_at'      => $r['due_at'],
                    'team'        => $r['team'],
                    'location'    => (string) $r['location'],
                    'notes'       => $r['notes'],
                    'created_at'  => (string) $r['created_at'],
                ];
            }
        }

        $out = [];
        foreach ($batchIds as $bid) {
            if (!isset($groups[$bid])) {
                continue;
            }
            $loans = $groups[$bid]['loans'];
            $counts = array_fill_keys(self::STATUSES, 0);
            $overdue = 0;
            $remaining = null;
            foreach ($loans as $l) {
                $counts[$l['status']]++;
                $overdue += $l['overdue'] ? 1 : 0;
                if ($l['status'] === 'approved' && $l['remaining_seconds'] !== null) {
                    $remaining = $remaining === null ? $l['remaining_seconds'] : min($remaining, $l['remaining_seconds']);
                }
            }
            $out[] = $groups[$bid]['meta'] + [
                'loans'  => $loans,
                'counts' => $counts,
                'status' => self::overall($counts),
                'overdue' => $overdue > 0,                  // Terlambat (turunan): approved + lewat batas
                'overdue_count' => $overdue,
                'remaining_seconds' => $remaining,          // sisa waktu terkecil di antara barang yang sedang dipinjam

                'can'    => [
                    'approve' => $counts['pending'] > 0 && $isAdmin,
                    'reject'  => $counts['pending'] > 0 && $isAdmin,
                    'cancel'  => $counts['pending'] > 0 && (int) $groups[$bid]['meta']['user']['id'] === (int) $viewer['id'],
                    'return'  => $counts['approved'] > 0 && (int) $groups[$bid]['meta']['user']['id'] === (int) $viewer['id'],
                ],
            ];
        }
        return $out;
    }

    /** Status ringkas satu pengajuan: yang masih "hidup" didahulukan. @param array<string,int> $c */
    private static function overall(array $c): string
    {
        foreach (['pending', 'approved', 'returned', 'rejected', 'cancelled'] as $s) {
            if ($c[$s] > 0) {
                return $s;
            }
        }
        return 'cancelled';
    }
}
