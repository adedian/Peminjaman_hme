<?php
declare(strict_types=1);

use App\Core\Database;

// ---------------------------------------------------------------------------
section('E2E Realtime — /sync');

t('Anonim 401; since tidak valid → 422; since=0 → kursor awal; tanpa perubahan → 204 tanpa badan', function () {
    eLoanReset();
    eq(401, (new Client())->api('GET', '/sync?since=1')['status']);
    $a = e2eAdmin();
    foreach (['abc', '-1', '1.5', '99999999999999999999'] as $bad) {
        eq(422, $a->api('GET', '/sync?since=' . rawurlencode($bad))['status'], $bad);
    }
    eq(422, $a->api('GET', '/sync?since[]=1')['status']);
    $r = $a->api('GET', '/sync?since=0');
    eq(200, $r['status']);
    $cur = $r['json']['data']['cursor'];
    ok(is_int($cur) && $cur > 0);
    $r = $a->api('GET', '/sync?since=' . (int) Database::value('SELECT MAX(id) FROM change_events'));
    eq([204, ''], [$r['status'], $r['body']]);
});

t('Dua akun: staff mengajukan → admin langsung melihat ID pengajuan + lencana + notifikasi; staff lain tidak melihat apa pun tentangnya', function () {
    eLoanReset();
    $admin = e2eAdmin(); $staff = e2eLogin('e_staff'); $other = e2eLogin('e_staff2');
    $c0 = (int) Database::value('SELECT MAX(id) FROM change_events');
    $req = eSubmit($staff, [iMake(['name' => 'Alat Sync E2E'])['id']]);

    $d = $admin->api('GET', '/sync?since=' . $c0)['json']['data'];
    eq([$req['id']], $d['changes']['request']);
    ok($d['counts']['requests'] >= 1);
    ok(count($d['notifications']) >= 1 && $d['notifications'][0]['title'] === 'Permohonan baru dari E_staff');

    $o = $other->api('GET', '/sync?since=' . $c0);
    $oc = $o['status'] === 204 ? [] : $o['json']['data']['changes'];
    ok(!isset($oc['request']) && !isset($oc['notification']), 'staff lain tidak melihat pengajuan/notifikasi');
    lacks((string) $req['id'], json_encode($oc['request'] ?? []));

    $admin->api('POST', "/requests/{$req['id']}/approve");
    $sd = $staff->api('GET', '/sync?since=' . $d['cursor'])['json']['data'];
    eq([$req['id']], $sd['changes']['request']);
    eq('Peminjaman disetujui', $sd['notifications'][0]['title']);
    eq(1, $sd['counts']['notifications']);
});

t('Akun dinonaktifkan saat sedang polling → 401 (klien kembali ke login); halaman memuat kursor, interval, skrip realtime, dan atribut lencana', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff');
    $page = $staff->get('/');
    ok((bool) preg_match('#<meta name="sync-cursor" content="\d+">#', $page['body']));
    contains('<meta name="sync-interval" content="1500">', $page['body']);
    contains('js/realtime.js', $page['body']);
    contains('data-nav="requests"', $page['body']);
    Database::run("UPDATE users SET is_active = 0 WHERE username = 'e_staff'");
    eq(401, $staff->api('GET', '/sync?since=1')['status']);
    Database::run("UPDATE users SET is_active = 1 WHERE username = 'e_staff'");

    aUser('e_forced_staff', 'Rahasia-Uji-1', 'staff', 1, 1);
    $f = e2eLogin('e_forced_staff');
    $p = $f->get('/profile/password');
    lacks('realtime.js', $p['body'], 'halaman wajib ganti password tanpa polling');
    eq([403, 'password_change_required'], [$f->api('GET', '/sync?since=1')['status'], $f->api('GET', '/sync?since=1')['json']['error']['code']]);
    lacks('innerHTML', $staff->get('/assets/js/realtime.js')['body']);
});
