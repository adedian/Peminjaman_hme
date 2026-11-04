<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use PDOException;
use Throwable;

/**
 * Master barang (BR-13, BR-14, keputusan Q3 & Q6).
 *  - ID barang unik; tidak bisa diubah bila barang sudah punya riwayat (loan/PQA).
 *  - Tidak ada hapus permanen: barang dinonaktifkan, dan ditolak bila sedang dipakai.
 *  - master_status (Tersedia/Dipinjam) TIDAK dapat diubah dari sini; hanya service peminjaman/PQA yang menyetelnya.
 *  - Flag is_pqa tidak boleh diubah saat unit punya pemasangan PQA aktif.
 */
final class ItemService
{
    private const ACTIVE_LOAN = "('pending','approved')";
    private const ACTIVE_PQA = "('Pending','Terpasang','Req Cabut')";

    private const RULES = [
        'code'        => 'required|string|min:2|max:50|regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
        'name'        => 'required|string|min:2|max:150',
        'category_id' => 'required|int|min:1',
        'finance_ref' => 'nullable|string|max:100',
        'is_pqa'      => 'nullable|boolean',
    ];
    private const LABELS = ['code' => 'ID barang', 'name' => 'Nama barang', 'category_id' => 'Kategori', 'finance_ref' => 'Kode ref', 'is_pqa' => 'Unit PQA'];

    private static function select(): string
    {
        return "SELECT i.id, i.code, i.name, i.category_id, c.name AS category_name, i.photo_path, i.finance_ref, i.is_pqa,
                       i.master_status, i.is_active, i.created_at, i.updated_at,
                       (EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id)
                        OR EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id)) AS has_history,
                       (EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status IN " . self::ACTIVE_LOAN . ")
                        OR EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN " . self::ACTIVE_PQA . ")) AS in_use
                FROM items i JOIN categories c ON c.id = i.category_id";
    }

    /** @return array<int,array{id:int,name:string}> */
    public static function categories(): array
    {
        return array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            Database::all('SELECT id, name FROM categories ORDER BY sort_order, name')
        );
    }

    /**
     * @param array<string,mixed> $filters q, category_id, status, active(all|1|0)
     * @return array{items:array<int,array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public static function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $f = Validator::make($filters, [
            'q'           => 'nullable|string|max:100',
            'category_id' => 'nullable|int|min:1',
            'status'      => 'nullable|in:Tersedia,Dipinjam',
            'active'      => 'nullable|in:all,1,0',
        ])->validate();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        if ($f['q'] !== null && $f['q'] !== '') {
            // % _ \ dari pengguna dianggap huruf biasa, bukan wildcard
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $where[] = "(i.name LIKE ? ESCAPE '\\\\' OR i.code LIKE ? ESCAPE '\\\\' OR i.finance_ref LIKE ? ESCAPE '\\\\')";
            array_push($params, $like, $like, $like);
        }
        if ($f['category_id'] !== null) {
            $where[] = 'i.category_id = ?';
            $params[] = (int) $f['category_id'];
        }
        if ($f['status'] !== null) {
            $where[] = 'i.master_status = ?';
            $params[] = $f['status'];
        }
        if ($f['active'] !== null && $f['active'] !== 'all') {
            $where[] = 'i.is_active = ?';
            $params[] = (int) $f['active'];
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::value('SELECT COUNT(*) FROM items i' . $w, $params);
        $rows = Database::all(
            self::select() . $w . ' ORDER BY i.is_active DESC, c.sort_order, i.name, i.id LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        );
        return ['items' => array_map([self::class, 'present'], $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $r = Database::one(self::select() . ' WHERE i.id = ?', [$id]);
        return $r === null ? null : self::present($r);
    }

    /**
     * @param array<string,mixed> $input
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $file
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function create(array $input, ?array $file, array $actor, Request $request): array
    {
        $data = self::validated($input);
        $photo = $file !== null ? ImageService::store($file) : null;

        try {
            $id = Database::transaction(function () use ($data, $photo, $actor): int {
                $id = Database::insert('items', [
                    'code'        => $data['code'],
                    'name'        => $data['name'],
                    'category_id' => $data['category_id'],
                    'finance_ref' => $data['finance_ref'],
                    'is_pqa'      => $data['is_pqa'] ? 1 : 0,
                    'photo_path'  => $photo,
                ]);
                ChangeFeed::emit('item', $id, 'created', (int) $actor['id']);
                return $id;
            });
        } catch (Throwable $e) {
            ImageService::delete($photo);
            throw self::translate($e);
        }

        AuditService::log('item.created', (int) $actor['id'], 'item', $id, null,
            ['code' => $data['code'], 'name' => $data['name'], 'category_id' => $data['category_id'], 'is_pqa' => $data['is_pqa'], 'photo' => $photo !== null], $request);
        return self::find($id) ?? [];
    }

    /**
     * @param array<string,mixed> $input  boleh memuat remove_photo=1
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $file
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function update(int $id, array $input, ?array $file, array $actor, Request $request): array
    {
        $data = self::validated($input);
        $remove = in_array($input['remove_photo'] ?? null, [true, 1, '1', 'true'], true);
        $newPhoto = $file !== null ? ImageService::store($file) : null;

        try {
            $before = Database::transaction(function () use ($id, $data, $newPhoto, $remove, $actor): array {
                $row = self::lock($id);
                if ($row['code'] !== $data['code'] && self::hasHistory($id)) {
                    throw new HttpException(409, 'code_locked', 'ID barang tidak dapat diubah karena barang ini sudah memiliki riwayat transaksi.');
                }
                if (((int) $row['is_pqa'] === 1) !== $data['is_pqa'] && self::hasActivePqa($id)) {
                    throw new HttpException(409, 'pqa_active', 'Status unit PQA tidak dapat diubah selama barang ini memiliki pemasangan PQA yang aktif.');
                }
                $photo = $row['photo_path'];
                if ($newPhoto !== null) {
                    $photo = $newPhoto;
                } elseif ($remove) {
                    $photo = null;
                }
                Database::update('items', [
                    'code'        => $data['code'],
                    'name'        => $data['name'],
                    'category_id' => $data['category_id'],
                    'finance_ref' => $data['finance_ref'],
                    'is_pqa'      => $data['is_pqa'] ? 1 : 0,
                    'photo_path'  => $photo,
                ], ['id' => $id]);
                ChangeFeed::emit('item', $id, 'updated', (int) $actor['id']);
                return $row;
            });
        } catch (Throwable $e) {
            ImageService::delete($newPhoto);
            throw self::translate($e);
        }

        $after = self::find($id) ?? [];
        // Foto lama dihapus SETELAH perubahan berhasil dikomit
        if ($before['photo_path'] !== null && ($after['photo_url'] ?? null) !== self::photoUrl($before['photo_path'])) {
            ImageService::delete((string) $before['photo_path']);
        }
        AuditService::log('item.updated', (int) $actor['id'], 'item', $id,
            ['code' => $before['code'], 'name' => $before['name'], 'category_id' => (int) $before['category_id'], 'is_pqa' => (int) $before['is_pqa'] === 1],
            ['code' => $data['code'], 'name' => $data['name'], 'category_id' => $data['category_id'], 'is_pqa' => $data['is_pqa'], 'photo_changed' => $newPhoto !== null || $remove], $request);
        return $after;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public static function setActive(int $id, bool $active, array $actor, Request $request): array
    {
        Database::transaction(function () use ($id, $active, $actor): void {
            $row = self::lock($id);
            if ((int) $row['is_active'] === ($active ? 1 : 0)) {
                return; // idempoten
            }
            if (!$active && (self::hasOpenLoan($id) || self::hasActivePqa($id) || $row['master_status'] === 'Dipinjam')) {
                throw new HttpException(409, 'item_in_use', 'Barang sedang dipinjam atau memiliki pengajuan/pemasangan aktif. Selesaikan terlebih dahulu.');
            }
            Database::run('UPDATE items SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
            ChangeFeed::emit('item', $id, $active ? 'activated' : 'deactivated', (int) $actor['id']);
        });
        AuditService::log($active ? 'item.activated' : 'item.deactivated', (int) $actor['id'], 'item', $id, null, null, $request);
        return self::find($id) ?? [];
    }

    // ------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $input @return array{code:string,name:string,category_id:int,finance_ref:?string,is_pqa:bool} */
    private static function validated(array $input): array
    {
        $d = Validator::make($input, self::RULES, self::LABELS)->validate();
        if (Database::value('SELECT id FROM categories WHERE id = ?', [(int) $d['category_id']]) === null) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['category_id' => 'Kategori tidak valid.']);
        }
        return [
            'code'        => (string) $d['code'],
            'name'        => (string) $d['name'],
            'category_id' => (int) $d['category_id'],
            'finance_ref' => $d['finance_ref'] === null ? null : (string) $d['finance_ref'],
            'is_pqa'      => (bool) ($d['is_pqa'] ?? false),
        ];
    }

    /** @return array<string,mixed> */
    private static function lock(int $id): array
    {
        $row = Database::one('SELECT id, code, name, category_id, photo_path, is_pqa, is_active, master_status FROM items WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Barang tidak ditemukan.');
        }
        return $row;
    }

    private static function hasHistory(int $id): bool
    {
        return (bool) Database::value('SELECT EXISTS (SELECT 1 FROM loans WHERE item_id = ?) OR EXISTS (SELECT 1 FROM pqa_installations WHERE item_id = ?)', [$id, $id]);
    }

    private static function hasOpenLoan(int $id): bool
    {
        return (bool) Database::value('SELECT EXISTS (SELECT 1 FROM loans WHERE item_id = ? AND status IN ' . self::ACTIVE_LOAN . ')', [$id]);
    }

    private static function hasActivePqa(int $id): bool
    {
        return (bool) Database::value('SELECT EXISTS (SELECT 1 FROM pqa_installations WHERE item_id = ? AND status IN ' . self::ACTIVE_PQA . ')', [$id]);
    }

    private static function translate(Throwable $e): Throwable
    {
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['code' => 'ID barang sudah digunakan.']);
        }
        return $e;
    }

    private static function photoUrl(?string $name): ?string
    {
        return $name === null ? null : url('/media/items/' . $name);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function present(array $r): array
    {
        $photo = $r['photo_path'] === null ? null : (string) $r['photo_path'];
        unset($r['photo_path']);
        $r['id'] = (int) $r['id'];
        $r['category_id'] = (int) $r['category_id'];
        $r['is_pqa'] = (int) $r['is_pqa'] === 1;
        $r['is_active'] = (int) $r['is_active'] === 1;
        $r['has_history'] = (int) $r['has_history'] === 1;
        $r['in_use'] = (int) $r['in_use'] === 1;
        $r['photo_url'] = self::photoUrl($photo);
        $r['thumb_url'] = $photo === null ? null : self::photoUrl(ImageService::thumbName($photo));
        return $r;
    }
}
