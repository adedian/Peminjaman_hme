<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Notifikasi Tracker PQA (in-app saja — sistem lama tidak mengirim email untuk aksi PQA, dan Q8 tidak menambahnya).
 * Dipanggil PqaService SETELAH transaksi dikomit; kegagalan hanya dicatat.
 *
 *  Permintaan staff (pasang, tambah waktu, cabut)  → semua admin aktif
 *  Keputusan admin                                 → staff pengaju pemasangan + staff yang mengajukan permintaan itu
 *                                                    (tracker dipakai bersama, jadi keduanya bisa berbeda orang)
 */
final class PqaNotifier
{
    private const TITLES = [
        'submitted'           => 'Pengajuan pasang PQA',
        'extension_requested' => 'Permintaan tambah waktu PQA',
        'removal_requested'   => 'Permintaan cabut PQA',
        'approved'            => 'Pemasangan PQA disetujui',
        'rejected'            => 'Pemasangan PQA ditolak',
        'extension_approved'  => 'Tambah waktu PQA disetujui',
        'extension_rejected'  => 'Tambah waktu PQA ditolak',
        'removal_approved'    => 'Pencabutan PQA disetujui',
        'removal_rejected'    => 'Pencabutan PQA ditolak',
    ];

    /**
     * @param array<string,mixed> $pqa hasil PqaService::find
     * @param string $event submitted|approved|rejected|extension_requested|extension_approved|extension_rejected|removal_requested|removal_approved|removal_rejected
     */
    public static function event(array $pqa, string $event, ?int $actorId = null): void
    {
        try {
            if (!isset(self::TITLES[$event]) || !isset($pqa['id'])) {
                return;
            }
            $id = (int) $pqa['id'];
            $unit = (string) $pqa['item']['name'];
            $loc = (string) $pqa['location'];
            $link = '/pqa?open=' . $id;
            $actorName = $actorId === null ? '' : (string) Database::value('SELECT name FROM users WHERE id = ?', [$actorId]);

            switch ($event) {
                case 'submitted':
                    $body = $actorName . ' · ' . $unit . ' di ' . $loc . ' (target ' . $pqa['target_days'] . ' hari)';
                    break;
                case 'extension_requested':
                    $body = $actorName . ' · ' . $unit . ' di ' . $loc . ' +' . $pqa['extension_pending_days'] . ' hari';
                    break;
                case 'removal_requested':
                    $body = $actorName . ' · ' . $unit . ' di ' . $loc . ' (tim cabut: ' . $pqa['removal_team'] . ')';
                    break;
                case 'approved':
                    $body = $unit . ' di ' . $loc . ' kini Terpasang (target ' . $pqa['target_days'] . ' hari) · jadwal cabut ' . \App\Helpers\Idn::dateTime($pqa['due_at'] ?? null);
                    break;
                case 'extension_approved':
                    $body = $unit . ' di ' . $loc . ' · target menjadi ' . $pqa['target_days'] . ' hari · jadwal cabut baru ' . \App\Helpers\Idn::dateTime($pqa['due_at'] ?? null);
                    break;
                case 'removal_approved':
                    $body = $unit . ' di ' . $loc . ' telah dicabut' . ($pqa['actual_days'] === null ? '' : ' (' . $pqa['actual_days'] . ' hari)')
                        . (($pqa['removal_destination'] ?? null) === 'site' ? ' · pindah ke ' . $pqa['destination_site'] : ($pqa['removal_destination'] === 'office' ? ' · kembali ke kantor' : ''));
                    break;
                default:
                    $body = $unit . ' di ' . $loc;
            }

            if (in_array($event, ['submitted', 'extension_requested', 'removal_requested'], true)) {
                $to = NotificationService::adminIds();
            } else {
                $to = [];
                if (isset($pqa['requested_by']['id'])) {
                    $to[] = (int) $pqa['requested_by']['id'];
                }
                $type = strpos($event, 'extension') === 0 ? 'extend' : (strpos($event, 'removal') === 0 ? 'remove' : null);
                if ($type !== null) {
                    $req = Database::value('SELECT requested_by FROM pqa_requests WHERE installation_id = ? AND type = ? ORDER BY id DESC LIMIT 1', [$id, $type]);
                    if ($req !== null) {
                        $to[] = (int) $req;
                    }
                }
            }
            // Keputusan admin disinkronkan juga ke admin lain (jadwal pasang/perpanjangan/pencabutan harus sama di semua pihak)
            if (in_array($event, ['approved', 'extension_approved', 'removal_approved'], true)) {
                $to = array_merge($to, NotificationService::adminIds());
            }
            NotificationService::notify($to, 'pqa.' . $event, self::TITLES[$event], $body, $link, $actorId);
        } catch (Throwable $e) {
            Logger::error('Notifikasi PQA gagal', ['event' => $event, 'err' => $e->getMessage()]);
        }
    }
}
