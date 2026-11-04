<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Setting;

/** Tunggu baris baru di mail.log (email dikirim server SETELAH respons). @return array<int,array<string,mixed>> */
function eWaitMail(int $before, float $timeout = 5.0): array
{
    $end = microtime(true) + $timeout;
    do {
        $log = mailLog();
        if (count($log) > $before) {
            return array_slice($log, $before);
        }
        usleep(100000);
    } while (microtime(true) < $end);
    return [];
}

// ---------------------------------------------------------------------------
section('E2E Notifikasi, log & pengaturan — otorisasi');

t('Anonim 401 (API) / redirect (halaman); staff → log & pengaturan 403; mass-assignment pengaturan diabaikan; CSRF wajib', function () {
    eLoanReset();
    $anon = new Client();
    foreach ([['GET', '/notifications'], ['GET', '/notifications/count'], ['POST', '/notifications/read-all'], ['POST', '/notifications/1/read'],
              ['GET', '/logs'], ['GET', '/settings'], ['PUT', '/settings'], ['POST', '/settings/test-email']] as [$m, $p]) {
        eq(401, $anon->api($m, $p, $m === 'GET' ? null : [])['status'], "{$m} {$p}");
    }
    foreach (['/notifications', '/logs', '/settings'] as $p) {
        eq([302, '/login'], [$anon->get($p)['status'], loc($anon->get($p))], $p);
    }

    $staff = e2eLogin('e_staff');
    $before = Setting::get('admin_notification_email');
    foreach ([['GET', '/logs'], ['GET', '/settings'], ['PUT', '/settings'], ['POST', '/settings/test-email']] as [$m, $p]) {
        $r = $staff->api($m, $p, $m === 'GET' ? null : ['admin_notification_email' => 'jahat@evil.example']);
        eq([403, 'forbidden'], [$r['status'], $r['json']['error']['code'] ?? null], "staff {$m} {$p}");
    }
    eq($before, Setting::get('admin_notification_email'));
    eq(403, $staff->get('/logs')['status']); eq(403, $staff->get('/settings')['status']);
    eq(200, $staff->get('/notifications')['status'], 'staff boleh membuka notifikasinya');

    $admin = e2eAdmin();
    $seq = Setting::get('loan_seq');
    $r = $admin->api('PUT', '/settings', ['admin_notification_email' => 'gudang@hme.test', 'loan_seq' => '999999', 'mail_password' => 'x']);
    eq(200, $r['status'], $r['body']);
    eq([$seq, 'gudang@hme.test'], [Setting::get('loan_seq'), Setting::get('admin_notification_email')], 'hanya kunci yang diizinkan');
    eq('csrf_invalid', $admin->api('PUT', '/settings', ['admin_notification_email' => 'x@hme.test'], '')['json']['error']['code']);
    eq('gudang@hme.test', Setting::get('admin_notification_email'));
});

t('IDOR: staff tidak dapat menandai notifikasi staff lain (404, tetap belum dibaca); hitungan hanya miliknya', function () {
    eLoanReset();
    Database::run('DELETE FROM notifications');
    $s2 = (int) Database::value("SELECT id FROM users WHERE username = 'e_staff2'");
    \App\Services\NotificationService::notify([$s2], 'test', 'Rahasia staff 2');
    $id = (int) Database::value('SELECT id FROM notifications WHERE user_id = ?', [$s2]);
    $staff = e2eLogin('e_staff');
    $r = $staff->api('POST', "/notifications/{$id}/read");
    eq([404, 'not_found'], [$r['status'], $r['json']['error']['code']]);
    eq(null, Database::value('SELECT read_at FROM notifications WHERE id = ?', [$id]));
    $l = $staff->api('GET', '/notifications');
    eq([[], 0], [$l['json']['data']['items'], $l['json']['data']['unread']]);
    $staff->api('POST', '/notifications/read-all');
    eq(null, Database::value('SELECT read_at FROM notifications WHERE id = ?', [$id]), 'read-all hanya milik sendiri');
    eq(1, e2eLogin('e_staff2')->api('GET', '/notifications/count')['json']['data']['unread']);
});

// ---------------------------------------------------------------------------
section('E2E Notifikasi — alur lengkap');

t('Staff mengajukan → respons langsung, email terkirim SETELAH respons (mail.log), admin melihat lonceng & daftar; disetujui → staff diberi tahu; baca → hitungan turun', function () {
    eLoanReset();
    Database::run('DELETE FROM notifications');
    Setting::set('admin_notification_email', 'gudang@hme.test');
    $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    $item = iMake(['name' => 'Alat E2E Notif'])['id'];
    $mails = count(mailLog());

    $t0 = microtime(true);
    $req = eSubmit($staff, [$item], ['location' => 'Proyek <i>E2E</i>']);
    ok(microtime(true) - $t0 < 5, 'respons tidak menunggu email');
    $new = eWaitMail($mails);
    eq(1, count($new), 'email permohonan baru terkirim oleh server');
    eq([['gudang@hme.test'], '[INVENTORY HME] Permohonan Baru'], [$new[0]['to'], $new[0]['subject']]);
    contains('Proyek <i>E2E</i>', $new[0]['text']);
    eq('sent', Database::value("SELECT status FROM mail_outbox WHERE event = 'request.submitted' ORDER BY id DESC LIMIT 1"));

    $home = $admin->get('/');
    ok((bool) preg_match('#id="nav-bell"[^>]*aria-label="Notifikasi \(1 belum dibaca\)"#', $home['body']), 'lonceng admin menampilkan 1');
    contains('<span class="bell-count num" data-unread>1</span>', $home['body']);
    $l = $admin->api('GET', '/notifications')['json']['data'];
    eq(['Permohonan baru dari E_staff', '/requests?open=' . $req['id']], [$l['items'][0]['title'], $l['items'][0]['link']]);

    eq(200, $admin->api('POST', "/requests/{$req['id']}/approve")['status']);
    $sl = $staff->api('GET', '/notifications')['json']['data'];
    eq(['Peminjaman disetujui', 1], [$sl['items'][0]['title'], $sl['unread']]);
    eq(0, $staff->api('POST', '/notifications/' . $sl['items'][0]['id'] . '/read')['json']['data']['unread']);
    lacks('data-unread', $staff->get('/')['body'], 'lonceng staff tanpa angka setelah dibaca');

    $mails = count(mailLog());
    eq(200, $staff->api('POST', "/requests/{$req['id']}/return")['status']);
    $ret = eWaitMail($mails);
    eq('[INVENTORY HME] Barang Kembali', $ret[0]['subject'] ?? null);
    contains('E_staff telah mengembalikan Alat E2E Notif', $ret[0]['text'] ?? '');
    eq(2, $admin->api('GET', '/notifications/count')['json']['data']['unread']);
    eq(['unread' => 0, 'marked' => 2], $admin->api('POST', '/notifications/read-all')['json']['data']);
});

// ---------------------------------------------------------------------------
section('E2E Log aktivitas & pengaturan');

t('Admin: log memuat login lewat HTTP (IP & perangkat), filter bekerja, filter salah 422; pengaturan + email uji', function () {
    eLoanReset();
    Setting::set('admin_notification_email', 'gudang@hme.test');
    $admin = e2eAdmin();
    $r = $admin->api('GET', '/logs?group=auth&q=auth.login&limit=5');
    eq(200, $r['status'], $r['body']);
    $it = $r['json']['data']['items'][0];
    eq(['auth.login', '127.0.0.1'], [$it['action'], $it['ip']]);
    ok($it['user'] !== null && $it['user_agent'] !== null);
    foreach (['/logs?group=nope', '/logs?from=2026-02-30', '/logs?user_id=x', '/logs?group[]=auth'] as $p) {
        eq(422, $admin->api('GET', $p)['status'], $p);
    }
    $s = $admin->api('GET', '/settings')['json']['data'];
    eq(['log', true], [$s['mail']['driver'], $s['mail']['configured']]);
    $bad = $admin->api('PUT', '/settings', ['admin_notification_email' => 'a@hme.test, salah']);
    eq(422, $bad['status']); contains('salah', $bad['json']['error']['fields']['admin_notification_email']);
    $mails = count(mailLog());
    $t = $admin->api('POST', '/settings/test-email');
    eq([200, ['gudang@hme.test']], [$t['status'], $t['json']['data']['sent_to'] ?? null], $t['body']);
    eq('[INVENTORY HME] Email Uji', mailLog()[$mails]['subject'] ?? null, 'email uji dikirim langsung');
});

t('Halaman: /notifications (staff & admin), /logs & /settings (admin) — skrip eksternal tanpa inline; menu Lainnya memuat Log & Pengaturan', function () {
    eLoanReset();
    $admin = e2eAdmin(); $staff = e2eLogin('e_staff');
    foreach ([['/notifications', 'notifications.js', 'id="nt-list"'], ['/logs', 'logs.js', 'id="log-form"'], ['/settings', 'settings.js', 'id="set-form"']] as [$p, $js, $el]) {
        $r = $admin->get($p);
        eq(200, $r['status'], $p);
        contains($js, $r['body'], $p); contains($el, $r['body'], $p);
        ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $r['body']), "{$p} tanpa skrip inline");
        ok((bool) preg_match('#bottom-nav.*?aria-current="page"[^>]*>.{0,300}Lainnya#s', $r['body']), "{$p}: tab Lainnya aktif");
    }
    contains('id="nt-list"', $staff->get('/notifications')['body']);
    $more = $admin->get('/more')['body'];
    contains('href="/logs"', $more); contains('href="/settings"', $more);
    lacks('href="/logs"', $staff->get('/')['body']);
    foreach (['notifications.js', 'logs.js', 'settings.js'] as $js) {
        lacks('innerHTML', $admin->get('/assets/js/' . $js)['body'], $js);
    }
});
