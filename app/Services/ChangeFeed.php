<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Feed perubahan untuk realtime (change_events). Dipanggil oleh service DI DALAM transaksi
 * yang sama dengan perubahan datanya, sehingga feed selalu konsisten dengan data.
 * Endpoint /sync dan frontend memakainya di Tahap 5.
 */
final class ChangeFeed
{
    /** @param int|string $entityId */
    public static function emit(string $entityType, $entityId, string $action, ?int $actorId, ?int $targetUserId = null): void
    {
        Database::insert('change_events', [
            'entity_type' => $entityType,
            'entity_id'   => substr((string) $entityId, 0, 40),
            'action'      => $action,
            'actor_id'    => $actorId,
            'user_id'     => $targetUserId,
        ]);
    }
}
