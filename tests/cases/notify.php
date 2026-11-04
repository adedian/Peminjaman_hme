<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\LoanService;
use App\Services\MailService;
use App\Services\NotificationService;
use App\Services\PqaService;
use App\Services\SettingsService;

// ---------------------------------------------------------------- pembantu --------------------

/** Ganti konfigurasi email sementara (driver none/smtp) lalu kembalikan. @param array<string,mixed> $mail */
function withMail(array $mail, callable $fn): void
{
    $cfg = require HME_ROOT . '/app/Config/app.php';
    $cfg['mail'] = $mail + $cfg['mail'];
    $f = tempnam(sys_get_temp_dir(), 'cfg');
    file_put_contents($f, '<?php return ' . var_export($cfg, true) . ';');
    Config::load($f);
    try {
        $fn();
    } finally {
        Config::load();
        @unlink($f);
    }
}

function mailLogPath(): string
{
    return rtrim((string) Config::get('paths.logs'), '/\\') . '/mail.log';
}

/** @return array<int,array<string,mixed>> email yang "terkirim" lewat driver log */
function mailLog(): array
{
    $out = [];
    foreach (@file(mailLogPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) {
            $out[] = $j;
        }
    }
    return $out;
}

function nReset(): void
{
    Database::run('DELETE FROM notifications');
    Database::run('DELETE FROM mail_outbox');
    @unlink(mailLogPath());
    Setting::set('admin_notification_email', 'gudang@hme.test, Wahyu@HME.test');
}

/** @return array<int,array<string,mixed>> */
function nOf(int $userId): array
{
    return Database::all('SELECT type, title, body, link, read_at FROM notifications WHERE user_id = ? ORDER BY id', [$userId]);
}

function nErr(callable $fn): HttpException
{
    /** @var HttpException $e */
    $e = throws($fn, HttpException::class);
    return $e;
}

// ---------------------------------------------------------------------------
section('Email admin — antrean & isi (BR-12, Q8)');

t('Daftar penerima: pemisah koma/titik koma/spasi, huruf kecil, unik, alamat salah dibuang, maks. 10', function () {
    eq(['a@x.co', 'b@y.co'], MailService::parseList(' A@x.co; b@y.co,,a@x.co  bukan-email '));
    eq([], MailService::parseList(''));
    eq(10, count(MailService::parseList(implode(',', array_map(fn($i) => "u{$i}@x.co", range(1, 15))))));
});

t('Pengajuan baru: notifikasi in-app ke semua admin aktif (bukan pengaju) + email "[INVENTORY HME] Permohonan Baru" ber-escape; terkirim setelah drain', function () {
    nReset();
    $s = lStaff('n_staff1'); $adm = lAdmin(); lReset($s);
    $off = aUser('n_admin_off', 'Admin-Uji-123', 'admin', 0);
    $a = lItem('Drone <script>alert(1)</script>'); $b = lItem('Meteran & Co');
    $req = lSubmit($s, [$a, $b], ['location' => 'PT <b>Uji</b>']);

    $n = nOf($adm['id']);
    eq(1, count($n));
    eq(['request.submitted', 'Permohonan baru dari N_staff1'], [$n[0]['type'], $n[0]['title']]);
    contains('2 barang', (string) $n[0]['body']);
    eq('/requests?open=' . $req['id'], $n[0]['link']);
    eq([], nOf($s['id']), 'pengaju tidak menerima notifikasinya sendiri');
    eq([], nOf($off), 'admin nonaktif tidak menerima');

    $m = Database::one("SELECT * FROM mail_outbox WHERE event = 'request.submitted'");
    eq(['pending', 'gudang@hme.test,wahyu@hme.test', '[INVENTORY HME] Permohonan Baru'], [$m['status'], $m['recipients'], $m['subject']]);
    $html = (string) $m['html_body'];
    contains('Ada pengajuan dari <b>N_staff1</b>', $html);
    contains('<li>Drone &lt;script&gt;alert(1)&lt;/script&gt; (', $html);
    contains('Meteran &amp; Co', $html);
    contains('Lokasi: PT &lt;b&gt;Uji&lt;/b&gt;', $html);
    lacks('<script>', $html); lacks('<b>Uji</b>', $html);
    contains('Sistem Peminjaman HME', $html, 'bingkai email sama dengan sistem lama');
    contains('Lokasi: PT <b>Uji</b>', (string) $m['text_body'], 'versi teks tanpa entitas');

    eq(['sent' => 1, 'failed' => 0, 'retry' => 0], MailService::drain());
    eq('sent', Database::value('SELECT status FROM mail_outbox WHERE id = ?', [$m['id']]));
    $log = mailLog();
    eq(1, count($log));
    eq([['gudang@hme.test', 'wahyu@hme.test'], '[INVENTORY HME] Permohonan Baru'], [$log[0]['to'], $log[0]['subject']]);
    eq(['sent' => 0, 'failed' => 0, 'retry' => 0], MailService::drain(), 'tidak terkirim dua kali');
    lReset($s);
});

t('Disetujui/ditolak: hanya staff pemilik (in-app, tanpa email); keputusan sebagian hanya menyebut barang yang diputuskan', function () {
    nReset();
    $s = lStaff('n_staff1'); $adm = lAdmin(); lReset($s);
    $a = lItem('Alat Setuju'); $b = lItem('Alat Tolak');
    $req = lSubmit($s, [$a, $b]);
    Database::run('DELETE FROM notifications'); Database::run('DELETE FROM mail_outbox');
    $loanB = (int) Database::value('SELECT id FROM loans WHERE batch_id = ? AND item_id = ?', [$req['id'], $b]);
    LoanService::rejectLoan($loanB, $adm, ureq());
    LoanService::approveRequest($req['id'], $adm, ureq());
    $n = nOf($s['id']);
    eq(['request.rejected', 'request.approved'], array_column($n, 'type'));
    eq(['Peminjaman ditolak', 'Peminjaman disetujui'], array_column($n, 'title'));
    contains('Alat Tolak', (string) $n[0]['body']); lacks('Alat Setuju', (string) $n[0]['body']);
    contains('Alat Setuju', (string) $n[1]['body']); lacks('Alat Tolak', (string) $n[1]['body']);
    eq([], nOf($adm['id']), 'admin pemutus tidak menerima notifikasi');
    eq(0, (int) Database::value('SELECT COUNT(*) FROM mail_outbox'), 'tidak ada email untuk keputusan (sama dengan sistem lama)');
    lReset($s);
});

t('Pengembalian: admin in-app + email "[INVENTORY HME] Barang Kembali" berisi barang yang dikembalikan saja; pembatalan: admin in-app tanpa email', function () {
    nReset();
    $s = lStaff('n_staff2'); $adm = lAdmin(); lReset($s);
    $a = lItem('Kunci Kembali'); $b = lItem('Tang Masih Dibawa');
    $req = lSubmit($s, [$a, $b]);
    LoanService::approveRequest($req['id'], $adm, ureq());
    Database::run('DELETE FROM notifications'); Database::run('DELETE FROM mail_outbox');
    $loanA = (int) Database::value('SELECT id FROM loans WHERE batch_id = ? AND item_id = ?', [$req['id'], $a]);
    LoanService::returnLoan($loanA, $s, ureq());
    $n = nOf($adm['id']);
    eq(['request.returned', 'Barang dikembalikan', 'N_staff2 telah mengembalikan Kunci Kembali'], [$n[0]['type'], $n[0]['title'], $n[0]['body']]);
    $m = Database::one("SELECT subject, html_body FROM mail_outbox WHERE event = 'request.returned'");
    eq('[INVENTORY HME] Barang Kembali', $m['subject']);
    contains('<b>N_staff2</b> telah mengembalikan <b>Kunci Kembali</b>.', (string) $m['html_body']);
    lacks('Tang Masih Dibawa', (string) $m['html_body']);
    lReset($s);

    nReset();
    $c = lSubmit($s, [lItem('Alat Batal')]);
    LoanService::cancelRequest($c['id'], $s, ureq());
    $n = nOf($adm['id']);
    eq(['request.submitted', 'request.cancelled'], array_column($n, 'type'));
    eq('N_staff2 membatalkan Alat Batal', $n[1]['body']);
    eq(['request.submitted'], array_column(Database::all('SELECT event FROM mail_outbox'), 'event'), 'pembatalan tanpa email');
});

t('SMTP belum dikonfigurasi: email "skipped" + dicatat, transaksi tetap berhasil; alamat admin kosong → tanpa antrean', function () {
    nReset();
    MailService::drain();   // kosongkan penanda "ada antrean" dari tes sebelumnya
    $s = lStaff('n_staff1'); lReset($s);
    withMail(['driver' => 'none'], function () use ($s) {
        eq('none', MailService::driver());
        $r = lSubmit($s, [lItem('Alat Tanpa SMTP')]);
        ok($r['id'] > 0);
        eq(['skipped', 0], [Database::value('SELECT status FROM mail_outbox ORDER BY id DESC LIMIT 1'), (int) Database::value('SELECT attempts FROM mail_outbox ORDER BY id DESC LIMIT 1')]);
        eq(false, MailService::hasQueued(), 'tidak ada yang perlu dikirim setelah respons');
        eq(['sent' => 0, 'failed' => 0, 'retry' => 0], MailService::drain());
    });
    lReset($s);
    Setting::set('admin_notification_email', 'bukan-email');
    $before = (int) Database::value('SELECT COUNT(*) FROM mail_outbox');
    ok(lSubmit($s, [lItem('Alat Tanpa Alamat')])['id'] > 0);
    eq($before, (int) Database::value('SELECT COUNT(*) FROM mail_outbox'));
    lReset($s);
    nReset();
});

t('SMTP gagal: dicoba ulang dengan jeda, tanpa membocorkan password, gagal permanen setelah 5 kali; baris yang sedang diklaim tidak dikirim ganda', function () {
    nReset();
    withMail(['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'username' => 'u@hme.test', 'password' => 'Rahasia-SMTP-999', 'timeout' => 3], function () {
        $id = MailService::queue(['admin@hme.test'], 'Uji', '<b>x</b>', 'test');
        $t0 = microtime(true);
        eq(['sent' => 0, 'failed' => 0, 'retry' => 1], MailService::drain());
        ok(microtime(true) - $t0 < 10, 'tidak menggantung lebih dari batas waktu');
        $m = Database::one('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
        eq(['pending', 1], [$m['status'], (int) $m['attempts']]);
        ok(strtotime((string) $m['next_attempt_at']) > time() + 60, 'dijadwalkan ulang ke depan');
        lacks('Rahasia-SMTP-999', (string) $m['last_error']);
        eq(['sent' => 0, 'failed' => 0, 'retry' => 0], MailService::drain(), 'belum jatuh tempo');

        Database::run('UPDATE mail_outbox SET attempts = 4, next_attempt_at = NOW() WHERE id = ?', [$id]);
        eq(['sent' => 0, 'failed' => 1, 'retry' => 0], MailService::drain());
        eq('failed', Database::value('SELECT status FROM mail_outbox WHERE id = ?', [$id]));
    });
    $id = MailService::queue(['admin@hme.test'], 'Diklaim', 'x', 'test');
    Database::run('UPDATE mail_outbox SET locked_until = NOW() + INTERVAL 1 MINUTE WHERE id = ?', [$id]);
    eq(0, MailService::drain()['sent'], 'sedang diklaim proses lain');
    Database::run('UPDATE mail_outbox SET locked_until = NOW() - INTERVAL 1 SECOND WHERE id = ?', [$id]);
    eq(1, MailService::drain()['sent'], 'klaim kedaluwarsa → dikirim');
    $lines = array_column(mailLog(), 'subject');
    eq(1, count(array_keys($lines, '[INVENTORY HME] Diklaim', true)), 'tepat satu kali');
    eq([], Database::all("SELECT id FROM mail_outbox WHERE status = 'pending'"));
});

t('Kegagalan notifikasi tidak menggagalkan transaksi (tabel notifikasi tak tersedia)', function () {
    nReset();
    $s = lStaff('n_staff1'); lReset($s);
    Database::run('RENAME TABLE notifications TO notifications_off');
    try {
        $r = lSubmit($s, [lItem('Alat Notif Rusak')]);
        eq('pending', $r['status']);
        eq(1, (int) Database::value("SELECT COUNT(*) FROM mail_outbox WHERE event = 'request.submitted'"), 'email tetap masuk antrean');
    } finally {
        Database::run('RENAME TABLE notifications_off TO notifications');
    }
    lReset($s);
});

// ---------------------------------------------------------------------------
section('Notifikasi in-app — PQA, daftar & kepemilikan');

t('PQA: permintaan staff → admin; keputusan → pengaju pemasangan + pengaju permintaan (tracker bersama); tanpa email', function () {
    nReset();
    $s = lStaff('n_staff1'); $v = lStaff('n_staff2'); $adm = lAdmin();
    $u = qUnit('PQA Notif');
    $p = qSubmit($s, $u, ['location' => 'PT Notif', 'target_days' => 7]);
    PqaService::decideInstall($p['id'], true, $adm, ureq());
    PqaService::requestExtension($p['id'], 3, $v, ureq());
    PqaService::decideExtension($p['id'], true, $adm, ureq());
    PqaService::requestRemoval($p['id'], 'Tim Cabut', $v, ureq());
    PqaService::decideRemoval($p['id'], true, $adm, ureq());

    eq(['pqa.submitted', 'pqa.extension_requested', 'pqa.removal_requested'], array_column(nOf($adm['id']), 'type'));
    eq(['pqa.approved', 'pqa.extension_approved', 'pqa.removal_approved'], array_column(nOf($s['id']), 'type'), 'pengaju pemasangan');
    eq(['pqa.extension_approved', 'pqa.removal_approved'], array_column(nOf($v['id']), 'type'), 'pengaju permintaan (staff lain)');
    $ext = nOf($s['id'])[1];
    eq('Tambah waktu PQA disetujui', $ext['title']);
    contains('target menjadi 10 hari', (string) $ext['body']);
    eq('/pqa?open=' . $p['id'], $ext['link']);
    contains('N_staff2', (string) nOf($adm['id'])[1]['body'], 'nama peminta tercantum');
    eq(0, (int) Database::value('SELECT COUNT(*) FROM mail_outbox'), 'PQA tanpa email (sama dengan sistem lama)');
});

t('Daftar, jumlah belum dibaca, filter, paginasi, tandai dibaca (satu & semua); milik orang lain → 404 dan tidak berubah', function () {
    nReset();
    $s = lStaff('n_staff1'); $v = lStaff('n_staff2');
    for ($i = 1; $i <= 25; $i++) {
        NotificationService::notify([$s['id']], 'test', "Notif {$i}", null, $i % 2 ? '/pqa' : null);
    }
    NotificationService::notify([$v['id']], 'test', 'Milik staff 2');
    eq(25, NotificationService::unreadCount($s['id']));
    $l = NotificationService::list($s['id'], false, 20, 0);
    eq([20, true, 'Notif 25', 25], [count($l['items']), $l['has_more'], $l['items'][0]['title'], $l['unread']]);
    eq(url('/pqa'), $l['items'][0]['link'], 'tautan diberi base path');
    eq(null, $l['items'][1]['link']);
    eq(5, count(NotificationService::list($s['id'], false, 20, 20)['items']));
    eq(25, count(NotificationService::list($s['id'], false, 500, 0)['items']), 'limit besar dibatasi tanpa error');

    $first = $l['items'][0]['id'];
    eq(['unread' => 24], NotificationService::markRead($first, $s['id']));
    eq(['unread' => 24], NotificationService::markRead($first, $s['id']), 'idempoten');
    eq(24, count(NotificationService::list($s['id'], true, 50)['items']), 'filter belum dibaca');

    $other = (int) Database::value('SELECT id FROM notifications WHERE user_id = ?', [$v['id']]);
    eq(404, nErr(fn() => NotificationService::markRead($other, $s['id']))->status());
    eq(null, Database::value('SELECT read_at FROM notifications WHERE id = ?', [$other]));
    eq(['unread' => 0, 'marked' => 24], NotificationService::markAllRead($s['id']));
    eq(1, NotificationService::unreadCount($v['id']), 'milik orang lain tidak ikut ditandai');
    eq(0, NotificationService::notify([$s['id']], 'x', 'diri sendiri', null, null, $s['id']), 'pelaku tidak menerima notifikasinya sendiri');
    ok((int) Database::value("SELECT COUNT(*) FROM change_events WHERE entity_type = 'notification' AND user_id = ?", [$v['id']]) >= 1, 'feed realtime untuk lonceng');
});

// ---------------------------------------------------------------------------
section('Pengaturan email & log aktivitas');

t('Pengaturan: alamat dinormalisasi & diaudit (sebelum/sesudah); input salah 422 tanpa mengubah data', function () {
    $adm = lAdmin();
    Setting::set('admin_notification_email', 'lama@hme.test');
    $r = SettingsService::update(['admin_notification_email' => ' A@HME.test ; b@hme.test, a@hme.test '], $adm, ureq());
    eq('a@hme.test, b@hme.test', $r['admin_notification_email']);
    eq(['a@hme.test', 'b@hme.test'], $r['recipients']);
    eq('log', $r['mail']['driver']);
    $log = Database::one("SELECT before_json, after_json FROM activity_logs WHERE action = 'settings.updated' ORDER BY id DESC LIMIT 1");
    contains('lama@hme.test', (string) $log['before_json']); contains('b@hme.test', (string) $log['after_json']);
    foreach (['', '   ', 'bukan email', 'a@hme.test, x@', str_repeat('a', 501), implode(',', array_map(fn($i) => "u{$i}@x.co", range(1, 11)))] as $bad) {
        ok(isset(nErr(fn() => SettingsService::update(['admin_notification_email' => $bad], $adm, ureq()))->fields()['admin_notification_email']), json_encode($bad));
    }
    foreach ([null, ['a@hme.test'], 5] as $bad) {
        eq(422, nErr(fn() => SettingsService::update(['admin_notification_email' => $bad], $adm, ureq()))->status());
    }
    eq('a@hme.test, b@hme.test', Setting::get('admin_notification_email'));
});

t('Email uji: driver log → terkirim langsung & diaudit; SMTP kosong → 502 dengan pesan jelas', function () {
    $adm = lAdmin();
    @unlink(mailLogPath());
    eq(['a@hme.test', 'b@hme.test'], SettingsService::testEmail($adm, ureq())['sent_to']);
    eq('[INVENTORY HME] Email Uji', mailLog()[0]['subject']);
    withMail(['driver' => 'none'], function () use ($adm) {
        $e = nErr(fn() => SettingsService::testEmail($adm, ureq()));
        eq([502, 'mail_failed'], [$e->status(), $e->errorCode()]);
        contains('SMTP belum dikonfigurasi', $e->getMessage());
    });
    ok((int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'settings.test_email'") >= 1);
    ok((int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'settings.test_email_failed'") >= 1);
});

t('Log aktivitas: filter kelompok/pelaku/kata kunci/tanggal, rincian sebelum/sesudah, paginasi; filter salah → 422', function () {
    $adm = lAdmin();
    $all = AuditService::list([], 100);
    ok(count($all['items']) === 100 && $all['has_more']);
    ok($all['items'][0]['id'] > $all['items'][1]['id'], 'terbaru dulu');
    foreach (AuditService::list(['group' => 'pqa'], 100)['items'] as $r) { ok(strpos($r['action'], 'pqa.') === 0, $r['action']); }
    foreach (AuditService::list(['group' => 'loan'], 100)['items'] as $r) { ok(preg_match('/^(request|loan)\./', $r['action']) === 1, $r['action']); }
    foreach (AuditService::list(['user_id' => (string) $adm['id']], 50)['items'] as $r) { eq($adm['id'], $r['user']['id']); }
    $set = AuditService::list(['q' => 'settings.updated'], 5)['items'];
    ok($set !== [] && is_array($set[0]['after']) && isset($set[0]['after']['admin_notification_email']), 'JSON sesudah didekode');
    eq('10.8.0.1', $set[0]['ip']);
    eq([], AuditService::list(['from' => '2099-01-01'])['items']);
    ok(AuditService::list(['from' => date('Y-m-d'), 'to' => date('Y-m-d')], 5)['items'] !== [], 'hari ini (inklusif)');
    eq([], AuditService::list(['q' => '%_%'])['items'], 'karakter wildcard dianggap literal');
    foreach ([['group' => 'x'], ['user_id' => 'abc'], ['from' => '2026-13-01'], ['to' => '01-01-2026'], ['q' => str_repeat('q', 101)], ['group' => ['pqa']]] as $bad) {
        eq(422, nErr(fn() => AuditService::list($bad))->status(), json_encode($bad));
    }
    // Rahasia tidak pernah tersimpan
    eq(0, (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE before_json LIKE '%Rahasia-Uji%' OR after_json LIKE '%Rahasia-Uji%' OR after_json LIKE '%Admin-Uji-123%'"));
});

t('Cakupan audit: setiap jenis aksi tulis yang diuji sejauh ini tercatat di activity_logs', function () {
    $expected = [
        'auth.login', 'auth.login_failed', 'auth.password_changed', 'auth.password_change_failed',
        'user.created', 'user.updated', 'user.activated', 'user.deactivated', 'user.password_reset',
        'item.created', 'item.updated', 'item.activated', 'item.deactivated', 'item.deleted',
        'request.submitted', 'loan.approved', 'loan.rejected', 'loan.cancelled', 'loan.returned', 'loan.swapped',
        'pqa.submitted', 'pqa.approved', 'pqa.rejected', 'pqa.extension_requested', 'pqa.extension_approved', 'pqa.extension_rejected',
        'pqa.removal_requested', 'pqa.removal_approved', 'pqa.removal_rejected',
        'report.exported', 'settings.updated', 'settings.test_email',
    ];
    $have = array_column(Database::all('SELECT DISTINCT action FROM activity_logs'), 'action');
    eq([], array_values(array_diff($expected, $have)), 'aksi tanpa log');
    eq(0, (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'loan.%' AND (user_id IS NULL OR ip IS NULL)"), 'pelaku & IP tercatat');
});
