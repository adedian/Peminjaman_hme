<?php
declare(strict_types=1);

use App\Core\Database;

/** @return array<string,mixed> */
function ePqa(Client $c, int $item, array $o = []): array
{
    $r = $c->api('POST', '/pqa', $o + ['item_id' => $item, 'ppic' => 'Naufal', 'location' => 'PT E2E', 'planned_install_at' => dueAt(1), 'install_team' => 'Rian, Vega', 'target_days' => 7]);
    eq(201, $r['status'], $r['body']);
    return $r['json']['data'];
}

// ---------------------------------------------------------------------------
section('E2E PQA — otorisasi');

t('Anonim: semua endpoint PQA 401; halaman → redirect /login', function () {
    eLoanReset();
    $c = new Client();
    foreach ([['GET', '/pqa/units'], ['GET', '/pqa'], ['GET', '/pqa/1'], ['POST', '/pqa'], ['POST', '/pqa/1/extend'], ['POST', '/pqa/1/remove'],
              ['POST', '/pqa/1/approve'], ['POST', '/pqa/1/reject'], ['POST', '/pqa/1/extension/approve'], ['POST', '/pqa/1/extension/reject'],
              ['POST', '/pqa/1/removal/approve'], ['POST', '/pqa/1/removal/reject']] as [$m, $p]) {
        eq(401, $c->api($m, $p, $m === 'GET' ? null : [])['status'], "{$m} {$p}");
    }
    $r = $c->get('/pqa');
    eq([302, '/login'], [$r['status'], loc($r)]);
});

t('Peran: staff → semua keputusan admin 403 (data tidak berubah); admin → aksi staff 403; CSRF diwajibkan', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    $u1 = iMake(['name' => 'PQA Otorisasi 1', 'is_pqa' => '1'])['id']; $u2 = iMake(['name' => 'PQA Otorisasi 2', 'is_pqa' => '1'])['id'];
    $pend = ePqa($staff, $u1);
    $act = ePqa($staff, $u2);
    $admin->api('POST', "/pqa/{$act['id']}/approve");
    $staff->api('POST', "/pqa/{$act['id']}/extend", ['days' => 3]);
    $act2 = $staff->api('POST', "/pqa/{$act['id']}/remove", ['team' => 'Tim Cabut']);
    eq('Req Cabut', $act2['json']['data']['status']);

    foreach ([["/pqa/{$pend['id']}/approve"], ["/pqa/{$pend['id']}/reject"], ["/pqa/{$act['id']}/extension/approve"], ["/pqa/{$act['id']}/extension/reject"],
              ["/pqa/{$act['id']}/removal/approve"], ["/pqa/{$act['id']}/removal/reject"]] as [$p]) {
        $r = $staff->api('POST', $p, []);
        eq(403, $r['status'], $p); eq('forbidden', $r['json']['error']['code'], $p);
    }
    eq('Pending', qRow($pend['id'])['status']);
    eq(['Req Cabut', 3], [qRow($act['id'])['status'], (int) qRow($act['id'])['pending_extension_days']]);

    $u3 = iMake(['name' => 'PQA Otorisasi 3', 'is_pqa' => '1'])['id'];
    foreach ([['/pqa', ['item_id' => $u3, 'ppic' => 'Admin', 'location' => 'PT X', 'planned_install_at' => dueAt(1), 'install_team' => 'Tim', 'target_days' => 5]],
              ["/pqa/{$act['id']}/extend", ['days' => 2]], ["/pqa/{$act['id']}/remove", ['team' => 'Tim']]] as [$p, $b]) {
        $r = $admin->api('POST', $p, $b);
        eq(403, $r['status'], "admin {$p}"); eq('forbidden', $r['json']['error']['code'], "admin {$p}");
    }
    eq(0, (int) Database::value('SELECT COUNT(*) FROM pqa_installations WHERE item_id = ?', [$u3]));

    eq('csrf_invalid', $staff->api('POST', '/pqa', ['item_id' => $u3], '')['json']['error']['code']);
    eq('csrf_origin', $staff->api('POST', '/pqa', ['item_id' => $u3], $staff->csrf, ['Origin' => 'https://evil.example'])['json']['error']['code']);
});

t('Mass-assignment: status, installed_at, total_days, requested_by, decided_by dari input diabaikan', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff');
    $other = (int) Database::value("SELECT id FROM users WHERE username = 'e_staff2'");
    $u = iMake(['name' => 'PQA Mass', 'is_pqa' => '1'])['id'];
    $r = $staff->api('POST', '/pqa', ['item_id' => $u, 'ppic' => 'Naufal', 'location' => 'PT E2E', 'planned_install_at' => dueAt(1), 'install_team' => 'Tim', 'target_days' => 7,
        'status' => 'Terpasang', 'installed_at' => '2020-01-01 00:00:00', 'total_days' => 999, 'requested_by' => $other, 'decided_by' => 1, 'pending_extension_days' => 50, 'removal_team' => 'x']);
    eq(201, $r['status'], $r['body']);
    $row = qRow($r['json']['data']['id']);
    eq(['Pending', null, null, 0, null], [$row['status'], $row['installed_at'], $row['total_days'], (int) $row['pending_extension_days'], $row['removal_team']]);
    ok((int) $row['requested_by'] !== $other && $row['decided_by'] === null);
});

t('Wajib ganti password: akun sementara terkunci dari API PQA', function () {
    eLoanReset();
    aUser('e_forced_staff', 'Rahasia-Uji-1', 'staff', 1, 1);
    $c = e2eLogin('e_forced_staff');
    foreach ([['GET', '/pqa'], ['GET', '/pqa/units'], ['POST', '/pqa']] as [$m, $p]) {
        $r = $c->api($m, $p, $m === 'GET' ? null : []);
        eq([403, 'password_change_required'], [$r['status'], $r['json']['error']['code']], $p);
    }
    Database::run("UPDATE users SET is_active = 0 WHERE username = 'e_forced_staff'");
});

// ---------------------------------------------------------------------------
section('E2E PQA — alur lengkap');

t('Alur: ajukan → admin setujui → staff lain tambah waktu → setujui → request cabut → ditolak → ajukan lagi → dicabut → unit tersedia', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $b = e2eLogin('e_staff2'); $admin = e2eAdmin();
    $u = iMake(['name' => 'PQA Alur', 'is_pqa' => '1'])['id'];
    ok(in_array($u, array_column($a->api('GET', '/pqa/units')['json']['data'], 'id'), true));

    $p = ePqa($a, $u, ['target_days' => 7, 'ppic' => 'Naufal']);
    eq('Pending', $p['status']);
    ok(!in_array($u, array_column($b->api('GET', '/pqa/units')['json']['data'], 'id'), true), 'unit hilang dari daftar untuk semua orang');
    eq('item_unavailable', $b->api('POST', '/requests', ['item_ids' => [$u], 'due_at' => dueAt(), 'location' => 'Pinjam biasa'])['json']['error']['code'], 'tak bisa dipinjam biasa');

    $seen = $b->api('GET', "/pqa/{$p['id']}");
    eq(200, $seen['status'], 'staff lain melihat tracker bersama');
    ok(!$seen['json']['data']['can']['approve'] && $seen['json']['data']['can']['extend'] === false, 'belum terpasang: belum bisa tambah waktu');

    $ok = $admin->api('POST', "/pqa/{$p['id']}/approve");
    eq('Terpasang', $ok['json']['data']['status']);
    eq(409, $admin->api('POST', "/pqa/{$p['id']}/approve")['status']);

    $ext = $b->api('POST', "/pqa/{$p['id']}/extend", ['days' => 5]);
    eq(5, $ext['json']['data']['extension_pending_days']);
    eq('extension_pending', $b->api('POST', "/pqa/{$p['id']}/extend", ['days' => 2])['json']['error']['code']);
    $ap = $admin->api('POST', "/pqa/{$p['id']}/extension/approve");
    eq([12, 0], [$ap['json']['data']['target_days'], $ap['json']['data']['extension_pending_days']]);

    $rm = $a->api('POST', "/pqa/{$p['id']}/remove", ['team' => 'Niko']);
    eq('Req Cabut', $rm['json']['data']['status']);
    eq('Terpasang', $admin->api('POST', "/pqa/{$p['id']}/removal/reject")['json']['data']['status']);
    $a->api('POST', "/pqa/{$p['id']}/remove", ['team' => 'Niko, Adil']);
    qAgo($p['id'], '9 DAY - INTERVAL 3 HOUR');
    $done = $admin->api('POST', "/pqa/{$p['id']}/removal/approve");
    eq(['Dicabut', 9], [$done['json']['data']['status'], $done['json']['data']['actual_days']]);

    ok(in_array($u, array_column($a->api('GET', '/pqa/units')['json']['data'], 'id'), true), 'unit tersedia lagi');
    eq(201, $b->api('POST', '/requests', ['item_ids' => [$u], 'due_at' => dueAt(), 'location' => 'Sekarang boleh'])['status']);
    eLoanReset();

    $det = $a->api('GET', "/pqa/{$p['id']}")['json']['data'];
    eq(['remove', 'remove', 'extend'], array_column($det['history'], 'type'), 'riwayat permintaan lengkap');
});

t('Daftar & validasi via API: grup, pencarian, paginasi, 422 per field, 404', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $admin = e2eAdmin();
    $tag = 'pq' . bin2hex(random_bytes(3));
    $p1 = ePqa($a, iMake(['name' => "{$tag} satu", 'is_pqa' => '1'])['id'], ['location' => "Lok {$tag}"]);
    $p2 = ePqa($a, iMake(['name' => "{$tag} dua", 'is_pqa' => '1'])['id'], ['location' => "Lok {$tag}"]);
    $admin->api('POST', "/pqa/{$p2['id']}/approve");

    $pend = $a->api('GET', "/pqa?group=pending&q={$tag}")['json']['data'];
    $act = $a->api('GET', "/pqa?group=active&q={$tag}&limit=1")['json']['data'];
    eq([[$p1['id']], [$p2['id']]], [array_column($pend['items'], 'id'), array_column($act['items'], 'id')]);
    eq(1, $act['total']);
    foreach (['group=semua', 'group[]=pending', 'q[]=x'] as $bad) { eq(422, $a->api('GET', "/pqa?{$bad}")['status'], $bad); }
    eq(404, $a->api('GET', '/pqa/99999999')['status']);

    $r = $a->api('POST', '/pqa', ['item_id' => 'abc', 'ppic' => '', 'location' => '', 'install_team' => '', 'target_days' => 0]);
    eq(422, $r['status']);
    foreach (['item_id', 'ppic', 'location', 'install_team', 'target_days'] as $f) { ok(isset($r['json']['error']['fields'][$f]), $f); }
    eq(422, $a->api('POST', "/pqa/{$p2['id']}/extend", ['days' => 0])['status']);
    eq(422, $a->api('POST', "/pqa/{$p2['id']}/remove", ['team' => ''])['status']);
    eq(409, $a->api('POST', "/pqa/{$p1['id']}/extend", ['days' => 2])['status'], 'belum terpasang');
    lacks('password', strtolower($a->api('GET', '/pqa')['body']));
});

// ---------------------------------------------------------------------------
section('E2E PQA — halaman & navigasi');

t('Halaman /pqa: staff punya tombol pasang baru; admin tidak; skrip eksternal; nama menu & lencana', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    $sp = $staff->get('/pqa');
    eq(200, $sp['status']);
    foreach (['id="btn-new-pqa"', 'id="sheet-pqa-new"', 'id="pqa-active"', 'pqa.js', 'data-role="staff"', 'href="/pqa"'] as $n) { contains($n, $sp['body'], $n); }
    ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $sp['body']), 'tanpa skrip inline');

    ePqa($staff, iMake(['name' => 'PQA Lencana', 'is_pqa' => '1'])['id']);
    $ap = $admin->get('/pqa');
    eq(200, $ap['status']);
    lacks('id="btn-new-pqa"', $ap['body']);
    contains('data-role="admin"', $ap['body']);
    ok((bool) preg_match('#href="/pqa"[^>]*>.*?<span class="count">\d+</span>#s', $ap['body']), 'lencana jumlah perlu tindakan');
    contains('Lainnya', $ap['body']);
});

t('Menu Lainnya (admin): 200 berisi Pengguna & Profil; staff → 403', function () {
    eLoanReset();
    $admin = e2eAdmin(); $staff = e2eLogin('e_staff');
    $r = $admin->get('/more');
    eq(200, $r['status']);
    contains('href="/users"', $r['body']); contains('href="/profile"', $r['body']);
    eq(403, $staff->get('/more')['status']);
    $r = $admin->get('/users');
    ok((bool) preg_match('#bottom-nav.*?aria-current="page"[^>]*>.{0,300}Lainnya#s', $r['body']), 'tab Lainnya aktif di halaman Pengguna');
});
