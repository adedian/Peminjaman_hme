<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use Throwable;

/**
 * Notifikasi peminjaman. Dipanggil LoanService SETELAH transaksi berhasil dikomit; kegagalan di sini hanya dicatat
 * dan tidak pernah menggagalkan transaksi bisnis.
 *
 *  Kejadian            In-app (Q8)            Email admin (BR-12, sama seperti sistem lama)
 *  pengajuan baru      semua admin aktif      "[INVENTORY HME] Permohonan Baru"
 *  disetujui/ditolak   staff pemilik          —
 *  dikembalikan        semua admin aktif      "[INVENTORY HME] Barang Kembali"
 *  dibatalkan (Q5)     semua admin aktif      —
 */
final class LoanNotifier
{
    /** @param array<string,mixed> $request hasil LoanService::findRequest */
    public static function submitted(array $request): void
    {
        self::guard('submitted', static function () use ($request): void {
            $who = (string) $request['user']['name'];
            $loans = $request['loans'] ?? [];
            $names = array_map(static fn(array $l): string => (string) $l['item']['name'], $loans);
            NotificationService::notify(
                NotificationService::adminIds(), 'request.submitted', 'Permohonan baru dari ' . $who,
                count($loans) . ' barang: ' . implode(', ', $names) . ' · ' . $request['location'],
                '/requests?open=' . (int) $request['id'], (int) $request['user']['id']
            );
            $list = '<ul>';
            foreach ($loans as $l) {
                $list .= '<li>' . self::e((string) $l['item']['name']) . ' (' . self::e((string) $l['item']['code']) . ')</li>';
            }
            $list .= '</ul>';
            MailService::queue(MailService::adminRecipients(), 'Permohonan Baru',
                'Ada pengajuan dari <b>' . self::e($who) . '</b>:<br>' . $list . '<br>Lokasi: ' . self::e((string) $request['location']),
                'request.submitted');
        });
    }

    /**
     * @param array<string,mixed> $request
     * @param string $decision approved|rejected
     * @param array<int,int> $loanIds loan yang diputuskan pada aksi ini
     */
    public static function decided(array $request, string $decision, array $loanIds = [], ?int $actorId = null): void
    {
        if ($decision === 'approved') {
            // "Barang dipinjam" → admin (selain admin yang menyetujui): siapa, apa, dan batas pengembaliannya
            self::guard('approved_admin', static function () use ($request, $loanIds, $actorId): void {
                $ids = $loanIds === [] ? array_map(static fn(array $l): int => (int) $l['id'], $request['loans'] ?? []) : $loanIds;
                sort($ids);
                NotificationService::notify(
                    NotificationService::adminIds(), 'loan.approved', 'Barang dipinjam',
                    $request['user']['name'] . ': ' . implode(', ', self::names($request, $ids)) . ' · batas kembali ' . \App\Helpers\Idn::dateTime($request['due_at'] ?? null),
                    '/requests?open=' . (int) $request['id'], $actorId,
                    ['dedupe_key' => 'loan.approved:batch:' . (int) $request['id'] . ':' . implode('-', $ids)]
                );
            });
        }
        self::guard('decided', static function () use ($request, $decision, $loanIds, $actorId): void {
            $names = self::names($request, $loanIds);
            $ok = $decision === 'approved';
            NotificationService::notify(
                [(int) $request['user']['id']], $ok ? 'request.approved' : 'request.rejected',
                $ok ? 'Peminjaman disetujui' : 'Peminjaman ditolak',
                implode(', ', $names) . ' · ' . $request['location'],
                '/requests?open=' . (int) $request['id'], $actorId
            );
        });
    }

    /** @param array<string,mixed> $request @param array<int,int> $loanIds */
    public static function returned(array $request, array $loanIds = [], ?int $actorId = null): void
    {
        self::guard('returned', static function () use ($request, $loanIds, $actorId): void {
            $who = (string) $request['user']['name'];
            $names = self::names($request, $loanIds);
            NotificationService::notify(
                NotificationService::adminIds(), 'request.returned', 'Barang dikembalikan',
                $who . ' telah mengembalikan ' . implode(', ', $names),
                '/requests?open=' . (int) $request['id'], $actorId
            );
            MailService::queue(MailService::adminRecipients(), 'Barang Kembali',
                '<b>' . self::e($who) . '</b> telah mengembalikan <b>' . self::e(implode(', ', $names)) . '</b>.',
                'request.returned');
        });
    }

    /** @param array<string,mixed> $request @param array<int,int> $loanIds */
    public static function cancelled(array $request, array $loanIds = [], ?int $actorId = null): void
    {
        self::guard('cancelled', static function () use ($request, $loanIds, $actorId): void {
            NotificationService::notify(
                NotificationService::adminIds(), 'request.cancelled', 'Pengajuan dibatalkan',
                $request['user']['name'] . ' membatalkan ' . implode(', ', self::names($request, $loanIds)),
                '/requests?open=' . (int) $request['id'], $actorId
            );
        });
    }

    /** Nama barang dari loan yang terkena aksi (semua loan bila daftar kosong). @return array<int,string> */
    private static function names(array $request, array $loanIds): array
    {
        $out = [];
        foreach ($request['loans'] ?? [] as $l) {
            if ($loanIds === [] || in_array((int) $l['id'], $loanIds, true)) {
                $out[] = (string) $l['item']['name'];
            }
        }
        return $out;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function guard(string $event, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Logger::error('Notifikasi peminjaman gagal', ['event' => $event, 'err' => $e->getMessage()]);
        }
    }
}
