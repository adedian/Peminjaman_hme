<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Services\ChangeFeed;
use App\Services\LoanService;
use App\Services\NotificationService;
use App\Services\SyncService;

/** @return array<string,array<int,int>> perubahan (objek kosong → []) */
function sChanges(?array $d): array
{
    return $d === null ? [] : (array) $d['changes'];
}

// ---------------------------------------------------------------------------
section('Realtime — /sync (change-feed)');

t('Tanpa perubahan → null (204); sesudah aksi → delta per jenis, unik, kursor maju; kursor yang sama tidak mengulang', function () {
    $s = lStaff('s_staff1'); $adm = lAdmin(); lReset($s);
    $c0 = SyncService::cursor();
    eq(null, SyncService::poll($adm, $c0));
    $a = lItem('Sync A'); $b = lItem('Sync B');
    $req = lSubmit($s, [$a, $b]);
    $d = SyncService::poll($adm, $c0);
    ok($d !== null && $d['cursor'] === SyncService::cursor() && $d['cursor'] > $c0);
    eq(false, $d['reset']);
    $ch = sChanges($d);
    eq([$req['id']], $ch['request']);
    ok(in_array($a, $ch['item'], true) && in_array($b, $ch['item'], true));
    eq(count($ch['item']), count(array_unique($ch['item'])), 'ID unik');
    ok(isset($ch['notification']), 'admin menerima notifikasi pengajuan baru');
    eq('Permohonan baru dari S_staff1', end($d['notifications'])['title']);
    ok($d['counts']['requests'] >= 1 && isset($d['counts']['pqa'], $d['counts']['notifications']));
    eq(null, SyncService::poll($adm, $d['cursor']));
    lReset($s);
});

t('Penyaringan per peran: staff hanya pengajuan & notifikasi miliknya; akun: admin semua, staff dirinya; barang & PQA dibagi bersama', function () {
    $s = lStaff('s_staff1'); $v = lStaff('s_staff2'); $adm = lAdmin(); lReset($s, $v);
    $c0 = SyncService::cursor();
    $mine = lSubmit($s, [lItem('Sync Milik S')]);
    $other = lSubmit($v, [lItem('Sync Milik V')]);
    ChangeFeed::emit('user', $v['id'], 'updated', $adm['id'], $v['id']);
    ChangeFeed::emit('pqa', 777, 'submitted', $v['id']);
    NotificationService::notify([$v['id']], 'test', 'Untuk V saja');

    $cs = sChanges(SyncService::poll($s, $c0));
    eq([$mine['id']], $cs['request'], 'staff tidak melihat pengajuan staff lain');
    ok(!isset($cs['notification']), 'notifikasi orang lain tidak terlihat');
    ok(!isset($cs['user']), 'perubahan akun orang lain tidak terlihat');
    eq([777], $cs['pqa']);
    ok(count($cs['item']) >= 2, 'ketersediaan barang dibagi bersama');
    eq(['notifications'], array_keys(SyncService::poll($s, $c0)['counts']), 'staff tanpa lencana admin');

    $cv = sChanges(SyncService::poll($v, $c0));
    eq([$other['id']], $cv['request']);
    eq([$v['id']], $cv['user'], 'staff melihat perubahan akunnya sendiri');
    eq(1, count($cv['notification']));

    $ca = sChanges(SyncService::poll($adm, $c0));
    eq([$mine['id'], $other['id']], $ca['request']);
    eq([$v['id']], $ca['user']);
    lReset($s, $v);
});

t('Hanya perubahan yang tidak relevan → kursor tetap maju (bukan 204) tanpa daftar; kursor dari masa depan / terlalu banyak perubahan → reset', function () {
    $s = lStaff('s_staff1'); $v = lStaff('s_staff2'); lReset($s, $v);
    $c0 = SyncService::cursor();
    NotificationService::notify([$v['id']], 'test', 'Bukan untuk S');
    $d = SyncService::poll($s, $c0);
    ok($d !== null && $d['cursor'] > $c0);
    eq([], sChanges($d)); eq(null, $d['counts']);
    eq(null, SyncService::poll($s, $d['cursor']));

    $r = SyncService::poll($s, SyncService::cursor() + 1000);
    eq([true, SyncService::cursor()], [$r['reset'], $r['cursor']]);

    $c1 = SyncService::cursor();
    for ($i = 0; $i <= SyncService::MAX_EVENTS; $i++) {
        ChangeFeed::emit('item', 1, 'availability', null);
    }
    $r = SyncService::poll($s, $c1);
    eq([true, SyncService::cursor()], [$r['reset'], $r['cursor']]);
    ok(isset($r['counts']['notifications']));
});

t('Polling /sync TIDAK memperpanjang timeout idle sesi; request biasa memperpanjangnya', function () {
    $u = lStaff('s_staff1');
    $pass = fn() => Response::ok();
    restartSession();
    Session::start();
    Session::login($u['id'], (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$u['id']]));
    $sid = session_id();
    $old = time() - 600;
    $_SESSION['_last'] = $old;
    restartSession();
    $_COOKIE['HME_SESSION'] = $sid;
    session_id($sid);
    eq(200, (new AuthMiddleware())->handle(new Request('GET', '/api/v1/sync'), $pass)->status());
    eq($old, (int) $_SESSION['_last'], '_last tidak berubah oleh polling');
    restartSession();
    session_id($sid);
    eq(200, (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), $pass)->status());
    ok((int) $_SESSION['_last'] >= time() - 2, 'request biasa memperbarui _last');
    Session::logout();
    unset($_COOKIE['HME_SESSION']);
});
