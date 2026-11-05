<?php
declare(strict_types=1);

/**
 * Revisi V2 — penjadwal pengingat (peminjaman H-1/terlambat, PQA hari ke-6 dari 7 / perpanjangan / terlambat),
 * idempotensi, pembatalan pengingat basi, penguncian putaran, dan cron sungguhan tanpa aplikasi dibuka.
 * Pengingat = notifikasi aplikasi (lonceng) + push ke HP. Waktu disimulasikan dengan menggeser kolom waktu di database.
 */

use App\Core\Database;
use App\Models\Setting;
use App\Services\LoanService;
use App\Services\PqaService;
use App\Services\SchedulerService;

/** Peminjam baru (satu peminjaman aktif per orang, BR-02). */
function scUser(string $name): array
{
    $u = lStaff($name);
    lReset($u);
    return $u;
}

function scReset(): array
{
    pushReset();
    // buang sisa transaksi aktif dari tes lain agar penjadwal hanya melihat data tes ini
    Database::run("UPDATE loans SET status = 'returned', returned_at = NOW() WHERE status IN ('approved','pending') AND borrowed_at BETWEEN (NOW() - INTERVAL 3 DAY) AND (NOW() + INTERVAL 1 DAY)");
    Database::run("UPDATE pqa_installations SET status = 'Dicabut', removed_at = NOW(), total_days = 0 WHERE status IN ('Terpasang','Req Cabut','Pending') AND COALESCE(installed_at, submitted_at) >= (NOW() - INTERVAL 40 DAY)");
    Database::run("UPDATE items i SET i.master_status = IF(EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status = 'approved') OR EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut')), 'Dipinjam', 'Tersedia')");
    Database::run("DELETE FROM notifications WHERE type IN ('loan.due_soon','loan.overdue','pqa.due_soon','pqa.overdue')");
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    Setting::set('loan_reminder_hours', '24');
    Setting::set('pqa_reminder_hours', '24');
    return [$s, $adm];
}

/** Peminjaman disetujui dengan batas kembali dan waktu disetujui yang diatur langsung di DB. */
function scLoan(array $s, array $adm, string $dueExpr, string $decidedExpr = 'NOW() - INTERVAL 5 DAY'): int
{
    lReset($s);
    $r = lSubmit($s, [lItem('Alat ' . uniqid())]);
    LoanService::approveRequest($r['id'], $adm, ureq());
    Database::run("UPDATE loans SET due_at = {$dueExpr}, decided_at = {$decidedExpr} WHERE batch_id = ?", [$r['id']]);
    return $r['id'];
}

/** Notifikasi pengingat (tipe tertentu) → baris. @return array<int,array<string,mixed>> */
function scNotifs(string $type): array
{
    return Database::all('SELECT n.*, u.username FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.type = ? ORDER BY n.id', [$type]);
}

section('V2-R6 — penjadwal: peminjaman');

t('Pengingat jatuh tempo: hanya dalam jendela 24 jam, satu kali, ke peminjam; belum waktunya → tidak ada', function () {
    [$s, $adm] = scReset();
    $s2 = scUser('sc_far');
    $far = scLoan($s2, $adm, 'NOW() + INTERVAL 30 HOUR');
    $near = scLoan($s, $adm, 'NOW() + INTERVAL 23 HOUR');
    $r = SchedulerService::tick('test');
    eq(true, $r['ran']);
    $rows = scNotifs('loan.due_soon');
    eq(1, count($rows));
    eq([(int) $s['id'], $near, 'Pengingat pengembalian'], [(int) $rows[0]['user_id'], (int) $rows[0]['entity_id'], $rows[0]['title']]);
    contains('harus dikembalikan paling lambat', $rows[0]['body']);
    contains('/requests?open=' . $near, $rows[0]['link']);
    // putaran berikutnya (cron 5 menit kemudian) tidak menggandakan
    SchedulerService::tick('test'); SchedulerService::tick('test');
    eq(1, count(scNotifs('loan.due_soon')));
    // 30 jam lagi → 10 jam kemudian masuk jendela
    Database::run('UPDATE loans SET due_at = NOW() + INTERVAL 20 HOUR WHERE batch_id = ?', [$far]);
    SchedulerService::tick('test');
    eq(2, count(scNotifs('loan.due_soon')));
});

t('Peminjaman singkat (lebih pendek dari jendela): pengingat menunggu titik tengah, tidak langsung setelah disetujui', function () {
    [$s, $adm] = scReset();
    scLoan($s, $adm, 'NOW() + INTERVAL 2 HOUR', 'NOW() - INTERVAL 1 HOUR');   // total 3 jam → ambang 1,5 jam; sisa 2 jam
    SchedulerService::tick('test');
    eq(0, count(scNotifs('loan.due_soon')));
    Database::run("UPDATE loans SET due_at = NOW() + INTERVAL 1 HOUR WHERE status = 'approved' AND user_id = ?", [$s['id']]);   // sisa 1 jam, total 2 jam → ambang 1 jam
    SchedulerService::tick('test');
    eq(1, count(scNotifs('loan.due_soon')));
});

t('Terlambat: peringatan ke peminjam tepat sekali; sudah dikembalikan / tanpa batas (pinjaman lama) tidak diperingatkan', function () {
    [$s, $adm] = scReset();
    $late = scLoan($s, $adm, 'NOW() - INTERVAL 3 HOUR');
    $s2 = scUser('l_staff2');
    $legacy = scLoan($s2, $adm, 'NULL');
    SchedulerService::tick('test'); SchedulerService::tick('test');
    $rows = scNotifs('loan.overdue');
    eq(1, count($rows));
    eq([(int) $s['id'], $late], [(int) $rows[0]['user_id'], (int) $rows[0]['entity_id']]);
    contains('terlambat 3 jam', $rows[0]['body']);
    eq('Peminjaman terlambat', $rows[0]['title']);
    eq(0, count(scNotifs('loan.due_soon')));
    LoanService::returnRequest($legacy, $s2, ureq());
    $r = lSubmit($s2, [lItem('Dikembalikan')]);
    LoanService::approveRequest($r['id'], $adm, ureq());
    Database::run('UPDATE loans SET due_at = NOW() - INTERVAL 1 HOUR WHERE batch_id = ?', [$r['id']]);
    LoanService::returnRequest($r['id'], $s2, ureq());
    SchedulerService::tick('test');
    eq(1, count(scNotifs('loan.overdue')), 'yang sudah dikembalikan tidak diperingatkan');
});

t('Pengingat berjadwal ikut diteruskan sebagai push ke HP peminjam (tanpa pengguna membuka aplikasi); setelah dikembalikan push tertunda dibatalkan', function () {
    [$s, $adm] = scReset();
    $dev = pushDevice($s);
    $b = scLoan($s, $adm, 'NOW() + INTERVAL 10 HOUR');
    Database::run('DELETE FROM push_outbox');                           // buang push dari persetujuan; yang diuji hanya pengingat
    $fake = pushFake([[503, 'sibuk', []], [201, '', []]]);
    SchedulerService::tick('test');
    eq(1, count($fake->calls), 'percobaan pertama (gagal sementara)');
    $p = json_decode(pushDecrypt($fake->calls[0]['body'], $dev['d'], $dev['pub'], $dev['auth']), true);
    eq('Pengingat pengembalian', $p['title']);
    eq('pending', Database::value("SELECT o.status FROM push_outbox o JOIN notifications n ON n.id = o.notification_id WHERE n.type = 'loan.due_soon'"));
    LoanService::returnRequest($b, $s, ureq());                         // dikembalikan sebelum push terkirim
    Database::run('UPDATE push_outbox SET next_attempt_at = NOW() - INTERVAL 1 SECOND');
    $r = SchedulerService::tick('test');
    eq('cancelled', Database::value("SELECT o.status FROM push_outbox o JOIN notifications n ON n.id = o.notification_id WHERE n.type = 'loan.due_soon'"));
    ok($r['cancelled'] >= 1);
    eq(1, count($fake->calls), 'tidak ada pengiriman tambahan');
});

section('V2-R6 — penjadwal: PQA');

function scPqa(array $s, array $adm, int $daysAgo = 0, int $target = 7): array
{
    $p = qInstalled($s, $adm, qUnit('PQA Jadwal ' . uniqid()), ['target_days' => $target, 'location' => 'Site Jadwal']);
    if ($daysAgo > 0) {
        Database::run("UPDATE pqa_installations SET installed_at = NOW() - INTERVAL {$daysAgo} DAY WHERE id = ?", [$p['id']]);
    }
    return $p;
}

t('Contoh Anda: PQA 7 hari — hari ke-1..5 tidak ada pesan, hari ke-6 (H-1) pengingat ke admin & pemohon sekali, lewat jadwal → peringatan terlambat', function () {
    [$s, $adm] = scReset();
    $p = scPqa($s, $adm, 0);
    SchedulerService::tick('test');
    eq(0, count(scNotifs('pqa.due_soon')) + count(scNotifs('pqa.overdue')), 'baru dipasang');
    qAgo($p['id'], '5 DAY');
    SchedulerService::tick('test');
    eq(0, count(scNotifs('pqa.due_soon')), 'hari ke-5 belum');
    qAgo($p['id'], '6 DAY');
    SchedulerService::tick('test'); SchedulerService::tick('test');
    $rows = scNotifs('pqa.due_soon');
    $admins = count(\App\Services\NotificationService::adminIds());
    eq($admins + 1, count($rows), 'semua admin + pemohon, sekali');
    ok(in_array((int) $s['id'], array_map('intval', array_column($rows, 'user_id')), true), 'pemohon termasuk');
    contains('harus dicabut pada', $rows[0]['body']);
    contains('Site Jadwal', $rows[0]['body']);
    eq(0, count(scNotifs('pqa.overdue')));
    qAgo($p['id'], '170 HOUR');
    SchedulerService::tick('test'); SchedulerService::tick('test');
    $od = scNotifs('pqa.overdue');
    eq($admins + 1, count($od));
    contains('seharusnya dicabut', $od[0]['body']);
});

t('Perpanjangan: push pengingat lama (belum terkirim) dibatalkan; H-1 dihitung ulang dari jadwal baru; tidak ada pengingat dari tanggal lama', function () {
    [$s, $adm] = scReset();
    $devS = pushDevice($s);
    $p = scPqa($s, $adm, 6);
    Database::run('DELETE FROM push_outbox');
    $fake = pushFake(array_fill(0, 10, [503, 'sibuk', []]));            // percobaan pertama gagal sementara → tetap menunggu
    SchedulerService::tick('test');
    $mine = fn() => Database::all("SELECT o.status FROM push_outbox o JOIN notifications n ON n.id = o.notification_id WHERE n.type = 'pqa.due_soon' AND n.entity_id = ?", [$p['id']]);
    eq(['pending'], array_column($mine(), 'status'));
    $oldDue = qRow($p['id'])['due_at'];

    PqaService::requestExtension($p['id'], 7, $s, ureq());
    PqaService::decideExtension($p['id'], true, $adm, ureq());
    $newDue = qRow($p['id'])['due_at'];
    ok($newDue > $oldDue);
    $calls0 = count($fake->calls);

    Database::run("UPDATE push_outbox o JOIN notifications n ON n.id = o.notification_id SET o.next_attempt_at = NOW() - INTERVAL 1 SECOND WHERE n.type = 'pqa.due_soon'");
    SchedulerService::tick('test');
    eq(['cancelled'], array_column($mine(), 'status'), 'pengingat jadwal lama dibatalkan');
    $stale = 0;
    foreach (array_slice($fake->calls, $calls0) as $c) {
        if ($c['url'] !== $devS['endpoint']) { continue; }
        $pl = json_decode(pushDecrypt($c['body'], $devS['d'], $devS['pub'], $devS['auth']), true);
        $stale += ($pl['title'] ?? '') === 'Pengingat pencabutan PQA' ? 1 : 0;
    }
    eq(0, $stale, 'tidak ada pengingat H-1 dari jadwal lama yang terkirim setelah diperpanjang');

    $n0 = count(scNotifs('pqa.due_soon'));
    SchedulerService::tick('test');
    eq($n0, count(scNotifs('pqa.due_soon')), 'jadwal baru masih jauh → tidak ada pengingat baru');
    // geser waktu: jadwal baru tinggal 20 jam → pengingat baru dengan kunci baru
    Database::run('UPDATE pqa_installations SET installed_at = NOW() - INTERVAL 14 DAY + INTERVAL 20 HOUR WHERE id = ?', [$p['id']]);
    SchedulerService::tick('test'); SchedulerService::tick('test');
    $dueNow = qRow($p['id'])['due_at'];
    $fresh = array_values(array_filter(scNotifs('pqa.due_soon'), fn($r) => $r['due_ref'] === $dueNow));
    ok(count($fresh) >= 2, 'pengingat baru memakai jadwal baru: ' . count($fresh));
    eq(0, count(scNotifs('pqa.overdue')), 'tidak pernah terlambat untuk jadwal lama');
});

t('PQA yang sudah dicabut tidak diperingatkan', function () {
    [$s, $adm] = scReset();
    $p = scPqa($s, $adm, 6);
    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq());
    PqaService::decideRemoval($p['id'], true, $adm, ureq());
    SchedulerService::tick('test');
    eq(0, count(scNotifs('pqa.due_soon')) + count(scNotifs('pqa.overdue')));
});

section('V2-R6 — penjadwal: kunci, log, cron nyata');

t('Putaran tumpang tindih: bila proses lain memegang kunci, putaran dilewati (tidak ganda); log putaran tersimpan', function () {
    [$s, $adm] = scReset();
    $other = \App\Core\Database::connect('hme_peminjaman_test');
    $other->query("SELECT GET_LOCK('hme_scheduler', 0)")->fetchColumn();
    $r = SchedulerService::tick('test');
    eq(false, $r['ran']);
    contains('sedang berjalan', $r['detail']);
    $other->query("SELECT RELEASE_LOCK('hme_scheduler')")->fetchColumn();
    $r = SchedulerService::tick('test');
    eq(true, $r['ran']);
    $run = Database::one('SELECT * FROM scheduler_runs ORDER BY id DESC LIMIT 1');
    eq('test', $run['trigger_by']);
    ok($run['finished_at'] !== null && $run['error'] === null);
    eq(true, SchedulerService::health()['ok']);
});

t('Jendela pengingat dapat diatur: loan_reminder_hours=48 memajukan pengingat', function () {
    [$s, $adm] = scReset();
    scLoan($s, $adm, 'NOW() + INTERVAL 40 HOUR');
    SchedulerService::tick('test');
    eq(0, count(scNotifs('loan.due_soon')));
    Setting::set('loan_reminder_hours', '48');
    SchedulerService::tick('test');
    eq(1, count(scNotifs('loan.due_soon')));
    Setting::set('loan_reminder_hours', '24');
});

t('CRON NYATA tanpa aplikasi dibuka: proses CLI tools/scheduler.php menyusun pengingat, exit 0, tidak ganda pada putaran kedua, dan --status', function () {
    [$s, $adm] = scReset();
    scLoan($s, $adm, 'NOW() + INTERVAL 6 HOUR');
    scLoan(scUser('sc_cron2'), $adm, 'NOW() - INTERVAL 2 HOUR');
    Database::run('DELETE FROM scheduler_runs');
    $logDir = sys_get_temp_dir() . '/hme_test_logs';
    $run = function (array $args) use ($logDir): array {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/tools/scheduler.php'], $args);
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_NAME' => 'hme_peminjaman_test', 'LOG_DIR' => $logDir]);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    };
    $r = $run([]);
    eq(0, $r['code'], $r['err'] . $r['out']);
    contains('loanDueSoon=1', $r['out']);
    eq(1, count(scNotifs('loan.due_soon')));
    eq(1, count(scNotifs('loan.overdue')));
    $run2 = $run([]);
    eq(0, $run2['code']);
    contains('loanDueSoon=0', $run2['out']);
    eq(2, count(scNotifs('loan.due_soon')) + count(scNotifs('loan.overdue')), 'putaran kedua tidak menggandakan');
    eq(2, (int) Database::value("SELECT COUNT(*) FROM scheduler_runs WHERE trigger_by = 'cron'"));
    $st = $run(['--status']);
    eq(0, $st['code'], $st['out']);
    contains('sehat', $st['out']);
    contains('Notifikasi push', $st['out']);
});

t('Pembersihan: status transaksi & barang dikembalikan netral untuk tes berikutnya', function () {
    scReset();
    Database::run("DELETE FROM notifications WHERE type IN ('loan.due_soon','loan.overdue','pqa.due_soon','pqa.overdue')");
    eq(0, (int) Database::value('SELECT COUNT(*) FROM push_outbox'));
});
