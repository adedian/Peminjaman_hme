<?php
declare(strict_types=1);

/**
 * Proses anak untuk tes konkurensi: menjalankan SATU aksi LoanService pada waktu yang ditentukan induk,
 * sehingga beberapa proses PHP benar-benar berebut pada saat yang sama.
 *
 *   php tests/child_loan.php <aksi> <startEpoch> <json-argumen>
 * Mencetak satu baris JSON: {"ok":true} atau {"ok":false,"status":409,"code":"item_unavailable"}.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\LoanService;
use App\Services\PqaService;

[, $action, $start, $json] = $argv + [null, '', '0', '{}'];
$a = json_decode((string) $json, true) ?: [];

$wait = (float) $start - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1e6));
}

$user = Database::one('SELECT id, name, role FROM users WHERE id = ?', [(int) ($a['user_id'] ?? 0)]) ?? [];
$req = new Request('POST', '/', [], [], ['User-Agent' => 'child'], ['REMOTE_ADDR' => '10.0.0.9']);

try {
    switch ($action) {
        case 'submit':
            $r = LoanService::submit($user, ['item_ids' => $a['item_ids'], 'location' => 'Lokasi paralel'], $req);
            echo json_encode(['ok' => true, 'request' => $r['id']]);
            break;
        case 'approve':
            LoanService::approveRequest((int) $a['request_id'], $user, $req);
            echo json_encode(['ok' => true]);
            break;
        case 'cancel':
            LoanService::cancelRequest((int) $a['request_id'], $user, $req);
            echo json_encode(['ok' => true]);
            break;
        case 'swap':
            LoanService::swapItem((int) $a['loan_id'], $a['item_id'], $user, $req);
            echo json_encode(['ok' => true]);
            break;
        case 'pqa_submit':
            PqaService::submit($user, ['item_id' => $a['item_id'], 'ppic' => 'PPIC Paralel', 'location' => 'Lokasi paralel', 'install_team' => 'Tim', 'target_days' => 7], $req);
            echo json_encode(['ok' => true]);
            break;
        case 'pqa_approve_extension':
            PqaService::decideExtension((int) $a['id'], true, $user, $req);
            echo json_encode(['ok' => true]);
            break;
        case 'pqa_approve_removal':
            PqaService::decideRemoval((int) $a['id'], true, $user, $req);
            echo json_encode(['ok' => true]);
            break;
        default:
            echo json_encode(['ok' => false, 'code' => 'bad_action']);
    }
} catch (HttpException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status(), 'code' => $e->errorCode()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'status' => 500, 'code' => 'exception', 'msg' => get_class($e) . ': ' . $e->getMessage()]);
}
