<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use PDOException;

/**
 * Tracker PQA — pemasangan unit PQA di lokasi proyek (BR-18..BR-25, BR-29; perilaku dari Code.gs lama).
 *
 * Siklus:  Pending ──setuju──► Terpasang ──(staff) minta cabut──► Req Cabut ──setuju──► Dicabut
 *             └─tolak─► Ditolak                    ▲                     └─tolak──► kembali Terpasang
 *   Tambah waktu (saat Terpasang): staff mengajukan +N hari → admin setuju (target += N) atau tolak.
 *
 * Perilaku yang DIPERTAHANKAN dari sistem lama:
 *  - Semua staff melihat seluruh unit PQA (tracker operasional bersama) dan dapat mengajukan tambah waktu / pencabutan
 *    untuk unit mana pun; yang memutuskan tetap admin. Pencatat (requested_by) kini tersimpan.
 *  - Permintaan tambah waktu dan permintaan cabut berdiri sendiri (boleh bersamaan).
 *  - Durasi = floor((sekarang − tanggal pasang) / 24 jam); tanggal pasang = saat DISETUJUI.
 *  - Pengajuan → barang 'Dipinjam'; ditolak/dicabut → status barang dihitung ulang (AvailabilityService).
 *
 * Kunci: barang (FOR UPDATE, via AvailabilityService::lockItems) → baris pemasangan. Sama dengan LoanService sehingga
 * balapan lintas modul (pinjam vs pasang PQA pada unit yang sama) diserialkan oleh kunci barang.
 */
final class PqaService
{
    private const MAX_DAYS = 3650;

    private const SELECT = "SELECT p.id, p.item_id, p.requested_by, p.location, p.ppic, p.install_team, p.submitted_at, p.installed_at,
            p.status, p.removal_team, p.removed_at, p.total_days, p.target_days, p.pending_extension_days, p.decided_at,
            p.planned_install_at, p.due_at, TIMESTAMPDIFF(SECOND, NOW(), p.due_at) AS due_in, p.removal_destination, p.destination_site,
            p.move_target_days, p.previous_installation_id, p.next_installation_id, p.notes,
            i.code AS item_code, i.name AS item_name, i.photo_path, u.name AS requester_name,
            CASE WHEN p.status IN ('Terpasang','Req Cabut') THEN FLOOR(TIMESTAMPDIFF(SECOND, p.installed_at, NOW()) / 86400) ELSE p.total_days END AS actual_days
         FROM pqa_installations p JOIN items i ON i.id = p.item_id LEFT JOIN users u ON u.id = p.requested_by";

    // ======================================================================================
    //  Pembacaan
    // ======================================================================================

    /**
     * Unit PQA yang dapat dipasang sekarang (flag is_pqa, aktif, bebas dari loan & PQA aktif).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function availableUnits(): array
    {
        $rows = Database::all(
            "SELECT i.id, i.code, i.name, i.photo_path
             FROM items i
             WHERE i.is_pqa = 1 AND i.is_active = 1 AND i.master_status = 'Tersedia'
               AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status IN ('pending','approved'))
               AND NOT EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut'))
             ORDER BY i.name, i.id"
        );
        return array_map(static function (array $r): array {
            $photo = $r['photo_path'] === null ? null : (string) $r['photo_path'];
            return ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'thumb_url' => $photo === null ? null : url('/media/items/' . ImageService::thumbName($photo))];
        }, $rows);
    }

    /** Perlu tindakan admin: pengajuan pasang + permintaan tambah waktu + permintaan cabut (lencana navigasi). */
    public static function pendingActionCount(): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM pqa_installations
             WHERE status = 'Pending' OR (status IN ('Terpasang','Req Cabut') AND pending_extension_days > 0) OR status = 'Req Cabut'"
        );
    }

    /**
     * @param array<string,mixed> $filters group (pending|active|history), q
     * @return array{items:array<int,array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public static function list(array $viewer, array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $f = Validator::make($filters, ['group' => 'nullable|in:pending,active,history', 'q' => 'nullable|string|max:100'])->validate();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        $map = ['pending' => "p.status = 'Pending'", 'active' => "p.status IN ('Terpasang','Req Cabut')", 'history' => "p.status IN ('Dicabut','Ditolak')"];
        if ($f['group'] !== null) {
            $where[] = $map[$f['group']];
        }
        if ($f['q'] !== null && $f['q'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $where[] = "(i.name LIKE ? ESCAPE '\\\\' OR i.code LIKE ? ESCAPE '\\\\' OR p.location LIKE ? ESCAPE '\\\\' OR p.ppic LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like, $like);
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::value('SELECT COUNT(*) FROM pqa_installations p JOIN items i ON i.id = p.item_id' . $w, $params);
        $rows = Database::all(self::SELECT . $w . ' ORDER BY p.id DESC LIMIT ? OFFSET ?', array_merge($params, [$limit, $offset]));
        return ['items' => array_map(fn(array $r): array => self::present($r, $viewer), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @param array<string,mixed> $viewer @return array<string,mixed>|null */
    public static function find(int $id, array $viewer, bool $withHistory = false): ?array
    {
        $r = Database::one(self::SELECT . ' WHERE p.id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $out = self::present($r, $viewer);
        if ($withHistory) {
            $out['history'] = array_map(static fn(array $h): array => [
                'type' => (string) $h['type'], 'days' => $h['days'] === null ? null : (int) $h['days'], 'team' => $h['team'],
                'status' => (string) $h['status'], 'requested_by' => $h['requester'], 'requested_at' => (string) $h['requested_at'],
                'decided_at' => $h['decided_at'], 'old_due_at' => $h['old_due_at'], 'new_due_at' => $h['new_due_at'],
                'old_target_days' => $h['old_target_days'] === null ? null : (int) $h['old_target_days'],
                'new_target_days' => $h['new_target_days'] === null ? null : (int) $h['new_target_days'],
            ], Database::all(
                'SELECT r.type, r.days, r.team, r.status, r.requested_at, r.decided_at, r.old_due_at, r.new_due_at, r.old_target_days, r.new_target_days, u.name AS requester
                 FROM pqa_requests r LEFT JOIN users u ON u.id = r.requested_by WHERE r.installation_id = ? ORDER BY r.id DESC',
                [$id]
            ));
            $out['locations'] = self::locationHistory((int) $out['item']['id']);
        }
        return $out;
    }

    // ======================================================================================
    //  Aksi staff
    // ======================================================================================

    /**
     * Ajukan pemasangan PQA (staff). Unit langsung 'Dipinjam' (BR-21) walau belum disetujui.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input item_id, ppic, location, install_team, target_days
     * @return array<string,mixed>
     */
    public static function submit(array $user, array $input, Request $request): array
    {
        self::assertRole($user, 'staff');
        $d = Validator::make($input, [
            'item_id'      => 'required|int|min:1',
            'ppic'         => 'required|string|min:2|max:100',
            'location'     => 'required|string|min:2|max:255',
            'install_team' => 'required|string|min:2|max:255',
            'target_days'  => 'required|int|min:1|max:' . self::MAX_DAYS,
            'notes'        => 'nullable|string|max:2000',
        ], ['item_id' => 'Unit PQA', 'ppic' => 'PPIC / penanggung jawab', 'location' => 'Lokasi pemasangan', 'install_team' => 'Tim pemasang', 'target_days' => 'Target waktu pasang (hari)', 'notes' => 'Catatan'])->validate();
        $itemId = (int) $d['item_id'];
        [$planned, $plannedErr] = self::parseDateTime($input['planned_install_at'] ?? null, 'Tanggal & jam pemasangan', -1, 90);
        if ($plannedErr !== null) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['planned_install_at' => $plannedErr]);
        }

        try {
            $id = Database::transaction(function () use ($d, $itemId, $user, $planned): int {
                $items = AvailabilityService::lockItems([$itemId]);
                $it = $items[$itemId] ?? null;
                if ($it === null || (int) $it['is_pqa'] !== 1) {
                    throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['item_id' => 'Pilih unit PQA yang valid.']);
                }
                if (!AvailabilityService::isFree($it)) {
                    throw self::unavailable((string) $it['name']);
                }
                $id = Database::insert('pqa_installations', [
                    'item_id' => $itemId, 'requested_by' => (int) $user['id'], 'location' => $d['location'], 'ppic' => $d['ppic'],
                    'install_team' => $d['install_team'], 'target_days' => (int) $d['target_days'], 'submitted_at' => date('Y-m-d H:i:s'),
                    'planned_install_at' => $planned, 'notes' => $d['notes'], 'status' => 'Pending',
                ]);
                AvailabilityService::sync($itemId);
                ChangeFeed::emit('item', $itemId, 'availability', (int) $user['id']);
                ChangeFeed::emit('pqa', $id, 'submitted', (int) $user['id']);
                return $id;
            });
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw self::unavailable(null);
            }
            throw $e;
        }
        AuditService::log('pqa.submitted', (int) $user['id'], 'pqa', $id, null, ['item_id' => $itemId, 'location' => $d['location'], 'target_days' => (int) $d['target_days']], $request);
        return self::done($id, $user, 'submitted');
    }

    /**
     * Ajukan tambah waktu (+N hari). Hanya saat Terpasang dan tanpa permintaan tambah waktu lain yang menunggu.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function requestExtension(int $id, $days, array $user, Request $request): array
    {
        self::assertRole($user, 'staff');
        $n = self::days($days, 'days');
        Database::transaction(function () use ($id, $n, $user): void {
            $row = self::lock($id);
            if ($row['status'] !== 'Terpasang') {
                throw new HttpException(409, 'invalid_state', 'Tambah waktu hanya dapat diajukan untuk unit yang sedang terpasang.');
            }
            if ((int) $row['pending_extension_days'] > 0) {
                throw new HttpException(409, 'extension_pending', 'Sudah ada permintaan tambah waktu yang menunggu persetujuan.');
            }
            Database::run('UPDATE pqa_installations SET pending_extension_days = ? WHERE id = ?', [$n, $id]);
            Database::insert('pqa_requests', ['installation_id' => $id, 'type' => 'extend', 'days' => $n, 'status' => 'pending', 'requested_by' => (int) $user['id']]);
            ChangeFeed::emit('pqa', $id, 'extension_requested', (int) $user['id']);
        });
        AuditService::log('pqa.extension_requested', (int) $user['id'], 'pqa', $id, null, ['days' => $n], $request);
        return self::done($id, $user, 'extension_requested');
    }

    /**
     * Ajukan pencabutan (status → Req Cabut). Boleh bersamaan dengan permintaan tambah waktu (seperti sistem lama).
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function requestRemoval(int $id, $team, array $user, Request $request, array $extra = []): array
    {
        self::assertRole($user, 'staff');
        $v = Validator::make(['team' => $team], ['team' => 'required|string|min:2|max:255'], ['team' => 'Tim pencabut'])->validate();
        // Tujuan akhir (revisi V2): 'office' = kembali ke kantor (bawaan), 'site' = pindah langsung ke site lain.
        $dest = $extra['destination'] ?? 'office';
        if (!is_string($dest) || !in_array($dest, ['office', 'site'], true)) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['destination' => 'Pilih tujuan: kembali ke kantor atau pindah site.']);
        }
        $site = null;
        $moveDays = null;
        if ($dest === 'site') {
            $sv = Validator::make(
                ['destination_site' => $extra['destination_site'] ?? null, 'move_target_days' => $extra['move_target_days'] ?? null],
                ['destination_site' => 'required|string|min:2|max:255', 'move_target_days' => 'required|int|min:1|max:' . self::MAX_DAYS],
                ['destination_site' => 'Site tujuan', 'move_target_days' => 'Target waktu pasang di site tujuan (hari)']
            )->validate();
            $site = (string) $sv['destination_site'];
            $moveDays = (int) $sv['move_target_days'];
        }
        Database::transaction(function () use ($id, $v, $user, $dest, $site, $moveDays): void {
            $row = self::lock($id);
            if ($row['status'] !== 'Terpasang') {
                throw new HttpException(409, 'invalid_state', 'Pencabutan hanya dapat diajukan untuk unit yang sedang terpasang.');
            }
            if ($dest === 'site' && mb_strtolower(trim((string) $row['location'])) === mb_strtolower(trim((string) $site))) {
                throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['destination_site' => 'Site tujuan sama dengan lokasi sekarang. Pilih "Kembali ke kantor" atau site lain.']);
            }
            Database::run(
                "UPDATE pqa_installations SET status = 'Req Cabut', removal_team = ?, removal_destination = ?, destination_site = ?, move_target_days = ? WHERE id = ?",
                [$v['team'], $dest, $site, $moveDays, $id]
            );
            Database::insert('pqa_requests', ['installation_id' => $id, 'type' => 'remove', 'team' => $v['team'], 'status' => 'pending', 'requested_by' => (int) $user['id']]);
            ChangeFeed::emit('pqa', $id, 'removal_requested', (int) $user['id']);
        });
        AuditService::log('pqa.removal_requested', (int) $user['id'], 'pqa', $id, null, ['team' => $v['team'], 'destination' => $dest, 'site' => $site, 'move_target_days' => $moveDays], $request);
        return self::done($id, $user, 'removal_requested');
    }

    // ======================================================================================
    //  Keputusan admin
    // ======================================================================================

    /** Setujui (→ Terpasang, tanggal pasang = sekarang) atau tolak (→ Ditolak, unit dibebaskan) pengajuan pemasangan. @param array<string,mixed> $admin */
    public static function decideInstall(int $id, bool $approve, array $admin, Request $request): array
    {
        self::assertRole($admin, 'admin');
        Database::transaction(function () use ($id, $approve, $admin): void {
            $row = self::lock($id);
            if ($row['status'] !== 'Pending') {
                throw new HttpException(409, 'invalid_state', 'Pengajuan pemasangan ini sudah diproses.');
            }
            $full = Database::one('SELECT location, previous_installation_id FROM pqa_installations WHERE id = ?', [$id]) ?? [];
            $isMove = $full['previous_installation_id'] !== null;
            if ($approve) {
                Database::run("UPDATE pqa_installations SET status = 'Terpasang', installed_at = NOW(), decided_by = ?, decided_at = NOW() WHERE id = ?", [(int) $admin['id'], $id]);
                self::history((int) $row['item_id'], $id, $isMove ? 'moved_in' : 'installed', (string) $full['location'], null, (int) $admin['id'], null);
            } else {
                Database::run("UPDATE pqa_installations SET status = 'Ditolak', decided_by = ?, decided_at = NOW() WHERE id = ?", [(int) $admin['id'], $id]);
                if ($isMove) {   // pemindahan ditolak → unit dibebaskan, tercatat kembali di kantor
                    self::history((int) $row['item_id'], $id, 'removed_office', (string) $full['location'], 'Kantor', (int) $admin['id'], 'Pemasangan di site tujuan ditolak; unit kembali ke kantor');
                }
            }
            AvailabilityService::sync((int) $row['item_id']);
            ChangeFeed::emit('item', (int) $row['item_id'], 'availability', (int) $admin['id']);
            ChangeFeed::emit('pqa', $id, $approve ? 'approved' : 'rejected', (int) $admin['id'], $row['requested_by'] === null ? null : (int) $row['requested_by']);
        });
        AuditService::log($approve ? 'pqa.approved' : 'pqa.rejected', (int) $admin['id'], 'pqa', $id, ['status' => 'Pending'], ['status' => $approve ? 'Terpasang' : 'Ditolak'], $request);
        return self::done($id, $admin, $approve ? 'approved' : 'rejected');
    }

    /** Setujui (target += N) atau tolak permintaan tambah waktu. @param array<string,mixed> $admin */
    public static function decideExtension(int $id, bool $approve, array $admin, Request $request): array
    {
        self::assertRole($admin, 'admin');
        $added = Database::transaction(function () use ($id, $approve, $admin): int {
            $row = self::lock($id);
            $n = (int) $row['pending_extension_days'];
            if (!in_array($row['status'], ['Terpasang', 'Req Cabut'], true) || $n < 1) {
                throw new HttpException(409, 'invalid_state', 'Tidak ada permintaan tambah waktu yang menunggu.');
            }
            $old = Database::one('SELECT target_days, due_at FROM pqa_installations WHERE id = ?', [$id]) ?? [];
            Database::run(
                'UPDATE pqa_installations SET target_days = target_days + ?, pending_extension_days = 0 WHERE id = ?',
                [$approve ? $n : 0, $id]
            );
            $new = Database::one('SELECT target_days, due_at FROM pqa_installations WHERE id = ?', [$id]) ?? [];
            // Riwayat perubahan durasi: jadwal cabut sebelum & sesudah (jadwal baru = dasar pengingat H-1 berikutnya)
            Database::run(
                "UPDATE pqa_requests SET status = ?, decided_by = ?, decided_at = NOW(), old_target_days = ?, new_target_days = ?, old_due_at = ?, new_due_at = ?
                 WHERE installation_id = ? AND type = 'extend' AND status = 'pending'",
                [$approve ? 'approved' : 'rejected', (int) $admin['id'], $old['target_days'], $new['target_days'], $old['due_at'], $new['due_at'], $id]
            );
            ChangeFeed::emit('pqa', $id, $approve ? 'extension_approved' : 'extension_rejected', (int) $admin['id'], $row['requested_by'] === null ? null : (int) $row['requested_by']);
            return $approve ? $n : 0;
        });
        AuditService::log($approve ? 'pqa.extension_approved' : 'pqa.extension_rejected', (int) $admin['id'], 'pqa', $id, null, ['added_days' => $added], $request);
        return self::done($id, $admin, $approve ? 'extension_approved' : 'extension_rejected');
    }

    /**
     * Setujui pencabutan (→ Dicabut, total hari dibekukan, unit dibebaskan) atau tolak (→ kembali Terpasang).
     * Permintaan tambah waktu yang masih menunggu otomatis ditutup (ditolak) saat unit dicabut.
     *
     * @param array<string,mixed> $admin
     */
    public static function decideRemoval(int $id, bool $approve, array $admin, Request $request): array
    {
        self::assertRole($admin, 'admin');
        Database::transaction(function () use ($id, $approve, $admin): void {
            $row = self::lock($id);
            if ($row['status'] !== 'Req Cabut') {
                throw new HttpException(409, 'invalid_state', 'Tidak ada permintaan pencabutan yang menunggu.');
            }
            if ($approve) {
                $cur = Database::one('SELECT location, ppic, removal_team, removal_destination, destination_site, move_target_days FROM pqa_installations WHERE id = ?', [$id]) ?? [];
                Database::run(
                    "UPDATE pqa_installations SET status = 'Dicabut', removed_at = NOW(), removed_by = ?,
                            total_days = FLOOR(TIMESTAMPDIFF(SECOND, installed_at, NOW()) / 86400),
                            pending_extension_days = 0, decided_by = ?, decided_at = NOW() WHERE id = ?",
                    [(int) $admin['id'], (int) $admin['id'], $id]
                );
                self::closeRequest($id, 'extend', 'rejected', (int) $admin['id']);   // permintaan tambah waktu yang menggantung ditutup
                self::closeRequest($id, 'remove', 'approved', (int) $admin['id']);
                if (($cur['removal_destination'] ?? 'office') === 'site') {
                    // Pindah site: unit TIDAK dibebaskan. Pemasangan berikutnya dibuat Pending pada transaksi yang sama
                    // (kunci unik active_item_key menjaga unit tetap milik alur ini) dan baru Terpasang setelah admin mengonfirmasinya.
                    $reqBy = Database::value("SELECT requested_by FROM pqa_requests WHERE installation_id = ? AND type = 'remove' ORDER BY id DESC LIMIT 1", [$id]);
                    $newId = Database::insert('pqa_installations', [
                        'item_id' => (int) $row['item_id'], 'requested_by' => $reqBy === null ? ($row['requested_by'] === null ? null : (int) $row['requested_by']) : (int) $reqBy,
                        'location' => (string) $cur['destination_site'], 'ppic' => (string) $cur['ppic'], 'install_team' => (string) $cur['removal_team'],
                        'target_days' => (int) $cur['move_target_days'], 'submitted_at' => date('Y-m-d H:i:s'), 'planned_install_at' => date('Y-m-d H:i:s'),
                        'status' => 'Pending', 'previous_installation_id' => $id,
                        'notes' => 'Pindah dari ' . (string) $cur['location'],
                    ]);
                    Database::run('UPDATE pqa_installations SET next_installation_id = ? WHERE id = ?', [$newId, $id]);
                    self::history((int) $row['item_id'], $id, 'moved_out', (string) $cur['location'], (string) $cur['destination_site'], (int) $admin['id'], 'Menunggu konfirmasi pemasangan di site tujuan');
                    ChangeFeed::emit('pqa', $newId, 'submitted', (int) $admin['id']);
                } else {
                    self::history((int) $row['item_id'], $id, 'removed_office', (string) $cur['location'], 'Kantor', (int) $admin['id'], null);
                }
            } else {
                Database::run("UPDATE pqa_installations SET status = 'Terpasang', removal_team = NULL, removal_destination = NULL, destination_site = NULL, move_target_days = NULL WHERE id = ?", [$id]);
                self::closeRequest($id, 'remove', 'rejected', (int) $admin['id']);
            }
            AvailabilityService::sync((int) $row['item_id']);
            ChangeFeed::emit('item', (int) $row['item_id'], 'availability', (int) $admin['id']);
            ChangeFeed::emit('pqa', $id, $approve ? 'removal_approved' : 'removal_rejected', (int) $admin['id'], $row['requested_by'] === null ? null : (int) $row['requested_by']);
        });
        AuditService::log($approve ? 'pqa.removal_approved' : 'pqa.removal_rejected', (int) $admin['id'], 'pqa', $id, null, null, $request);
        $result = self::done($id, $admin, $approve ? 'removal_approved' : 'removal_rejected');
        $next = $approve ? Database::value('SELECT next_installation_id FROM pqa_installations WHERE id = ?', [$id]) : null;
        if ($next !== null) {   // pindah site: pemasangan di site tujuan menunggu konfirmasi admin
            PqaNotifier::event(self::find((int) $next, $admin, true) ?? [], 'submitted', (int) $admin['id']);
        }
        return $result;
    }

    // ======================================================================================
    //  Implementasi
    // ======================================================================================

    /** @param array<string,mixed> $viewer @return array<string,mixed> */
    private static function done(int $id, array $viewer, string $event): array
    {
        $pqa = self::find($id, $viewer, true) ?? [];
        PqaNotifier::event($pqa, $event, isset($viewer['id']) ? (int) $viewer['id'] : null);
        return $pqa;
    }

    /** Kunci barang lalu baris pemasangan (urutan seragam dengan LoanService). @return array<string,mixed> */
    private static function lock(int $id): array
    {
        $r = Database::one('SELECT item_id FROM pqa_installations WHERE id = ?', [$id]);
        if ($r === null) {
            throw new HttpException(404, 'not_found', 'Data PQA tidak ditemukan.');
        }
        AvailabilityService::lockItems([(int) $r['item_id']]);
        $row = Database::one('SELECT id, item_id, requested_by, status, pending_extension_days, target_days, location, due_at FROM pqa_installations WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Data PQA tidak ditemukan.');
        }
        return $row;
    }

    /** Catat satu kejadian lokasi unit (histori keberadaan perangkat). */
    private static function history(int $itemId, ?int $installationId, string $event, string $location, ?string $to, ?int $by, ?string $note): void
    {
        Database::insert('pqa_location_history', [
            'item_id' => $itemId, 'installation_id' => $installationId, 'event' => $event, 'location' => $location,
            'to_location' => $to, 'occurred_at' => date('Y-m-d H:i:s'), 'confirmed_by' => $by, 'note' => $note,
        ]);
    }

    /**
     * Histori keberadaan satu unit PQA (terbaru dulu).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function locationHistory(int $itemId): array
    {
        return array_map(static fn(array $h): array => [
            'event' => (string) $h['event'], 'location' => (string) $h['location'], 'to_location' => $h['to_location'],
            'occurred_at' => (string) $h['occurred_at'], 'confirmed_by' => $h['confirmer'], 'note' => $h['note'],
            'installation_id' => $h['installation_id'] === null ? null : (int) $h['installation_id'],
        ], Database::all(
            'SELECT h.event, h.location, h.to_location, h.occurred_at, h.note, h.installation_id, u.name AS confirmer
             FROM pqa_location_history h LEFT JOIN users u ON u.id = h.confirmed_by
             WHERE h.item_id = ? ORDER BY h.occurred_at DESC, h.id DESC',
            [$itemId]
        ));
    }

    /**
     * Tanggal+jam (input datetime-local 'Y-m-dTH:i' atau 'Y-m-d H:i') → 'Y-m-d H:i:00', dibatasi relatif terhadap sekarang.
     *
     * @param mixed $raw
     * @return array{0:?string,1:?string}
     */
    private static function parseDateTime($raw, string $label, int $minDays, int $maxDays): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [null, $label . ' wajib diisi.'];
        }
        $s = str_replace('T', ' ', trim($raw));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s)) {
            return [null, 'Format ' . mb_strtolower($label) . ' tidak valid.'];
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($s, 0, 16));
        $errs = \DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errs !== false && ($errs['warning_count'] > 0 || $errs['error_count'] > 0))) {
            return [null, $label . ' bukan tanggal/jam yang valid.'];
        }
        $now = new \DateTimeImmutable('now');
        if ($dt < $now->modify($minDays . ' days')) {
            return [null, $label . ' terlalu lampau.'];
        }
        if ($dt > $now->modify('+' . $maxDays . ' days')) {
            return [null, $label . ' terlalu jauh ke depan (maks. ' . $maxDays . ' hari).'];
        }
        return [$dt->format('Y-m-d H:i:00'), null];
    }

    private static function closeRequest(int $id, string $type, string $status, int $adminId): void
    {
        Database::run(
            "UPDATE pqa_requests SET status = ?, decided_by = ?, decided_at = NOW() WHERE installation_id = ? AND type = ? AND status = 'pending'",
            [$status, $adminId, $id, $type]
        );
    }

    /** @param mixed $v */
    private static function days($v, string $field): int
    {
        if ((!is_int($v) && !(is_string($v) && ctype_digit($v))) || (int) $v < 1 || (int) $v > self::MAX_DAYS) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', [$field => 'Masukkan jumlah hari 1–' . self::MAX_DAYS . '.']);
        }
        return (int) $v;
    }

    private static function unavailable(?string $name): HttpException
    {
        $msg = $name === null
            ? 'Unit ini baru saja dipakai pengguna lain. Muat ulang daftar unit lalu pilih kembali.'
            : "Unit {$name} sedang tidak tersedia (sedang dipinjam, diajukan, atau sudah terpasang).";
        return new HttpException(409, 'item_unavailable', $msg, ['item_id' => $msg]);
    }

    /** @param array<string,mixed> $user */
    private static function assertRole(array $user, string $role): void
    {
        if (($user['role'] ?? null) !== $role) {
            throw new HttpException(403, 'forbidden', 'Anda tidak memiliki akses ke fitur ini.');
        }
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,mixed> $viewer
     * @return array<string,mixed>
     */
    private static function present(array $r, array $viewer): array
    {
        $isAdmin = ($viewer['role'] ?? '') === 'admin';
        $isStaff = ($viewer['role'] ?? '') === 'staff';
        $status = (string) $r['status'];
        $ext = (int) $r['pending_extension_days'];
        $photo = $r['photo_path'] === null ? null : (string) $r['photo_path'];
        $actual = $r['actual_days'] === null ? null : (int) $r['actual_days'];
        $target = (int) $r['target_days'];
        $active = in_array($status, ['Terpasang', 'Req Cabut'], true);

        return [
            'id'           => (int) $r['id'],
            'item'         => ['id' => (int) $r['item_id'], 'code' => (string) $r['item_code'], 'name' => (string) $r['item_name'],
                               'thumb_url' => $photo === null ? null : url('/media/items/' . ImageService::thumbName($photo))],
            'status'       => $status,
            'location'     => (string) $r['location'],
            'ppic'         => (string) $r['ppic'],
            'install_team' => (string) $r['install_team'],
            'removal_team' => $r['removal_team'],
            'submitted_at' => $r['submitted_at'],
            'installed_at' => $r['installed_at'],
            'removed_at'   => $r['removed_at'],
            'target_days'  => $target,
            'actual_days'  => $actual,
            'over_target'  => $active && $actual !== null && $actual > $target,
            'extension_pending_days' => $ext,
            'planned_install_at' => $r['planned_install_at'],
            'due_at'       => $r['due_at'],
            // Sisa waktu hingga jadwal cabut (detik, dari jam database). Negatif = melewati jadwal.
            'remaining_seconds' => ($active && $r['due_at'] !== null) ? (int) $r['due_in'] : null,
            'overdue'      => $active && $r['due_at'] !== null && (int) $r['due_in'] < 0,
            'removal_destination' => $r['removal_destination'],
            'destination_site'    => $r['destination_site'],
            'move_target_days'    => $r['move_target_days'] === null ? null : (int) $r['move_target_days'],
            'previous_installation_id' => $r['previous_installation_id'] === null ? null : (int) $r['previous_installation_id'],
            'next_installation_id'     => $r['next_installation_id'] === null ? null : (int) $r['next_installation_id'],
            'notes'        => $r['notes'],
            'requested_by' => $r['requested_by'] === null ? null : ['id' => (int) $r['requested_by'], 'name' => (string) ($r['requester_name'] ?? '')],
            'can'          => [
                'approve'            => $isAdmin && $status === 'Pending',
                'reject'             => $isAdmin && $status === 'Pending',
                'extend'             => $isStaff && $status === 'Terpasang' && $ext === 0,
                'remove'             => $isStaff && $status === 'Terpasang',
                'approve_extension'  => $isAdmin && $active && $ext > 0,
                'reject_extension'   => $isAdmin && $active && $ext > 0,
                'approve_removal'    => $isAdmin && $status === 'Req Cabut',
                'reject_removal'     => $isAdmin && $status === 'Req Cabut',
            ],
        ];
    }
}
