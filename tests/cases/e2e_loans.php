<?php
declare(strict_types=1);

use App\Core\Database;

/** Bersihkan pinjaman terbuka milik akun uji E2E agar BR-02 tidak terpengaruh tes lain. */
function eLoanReset(): void
{
    e2eReset();
    Database::run("UPDATE loans SET status = 'returned', returned_at = NOW() WHERE user_id IN (SELECT id FROM users WHERE username IN ('e_staff','e_staff2')) AND status IN ('pending','approved')");
}

/** @return array<string,mixed> pengajuan */
function eSubmit(Client $c, array $itemIds, array $o = []): array
{
    $r = $c->api('POST', '/requests', $o + ['item_ids' => $itemIds, 'due_at' => dueAt(), 'location' => 'Proyek E2E', 'team' => 'Tim A']);
    eq(201, $r['status'], $r['body']);
    return $r['json']['data'];
}

// ---------------------------------------------------------------------------
section('E2E Peminjaman — otorisasi & IDOR');

t('Anonim: semua endpoint peminjaman 401; halaman → redirect /login', function () {
    eLoanReset();
    $c = new Client();
    foreach ([['GET', '/items/available'], ['GET', '/loans/summary'], ['GET', '/requests'], ['GET', '/requests/1'], ['POST', '/requests'],
              ['POST', '/requests/1/cancel'], ['POST', '/requests/1/return'], ['POST', '/requests/1/approve'], ['POST', '/requests/1/reject'],
              ['POST', '/loans/1/approve'], ['POST', '/loans/1/reject'], ['POST', '/loans/1/cancel'], ['POST', '/loans/1/return'], ['POST', '/loans/1/swap']] as [$m, $p]) {
        eq(401, $c->api($m, $p, $m === 'GET' ? null : [])['status'], "{$m} {$p}");
    }
    foreach (['/requests', '/requests/new'] as $p) {
        $r = $c->get($p);
        eq(302, $r['status'], $p);
        eq('/login', loc($r), $p);
    }
});

t('Peran ditegakkan server: staff → aksi admin 403; admin → aksi staff 403 (dengan CSRF benar); data tidak berubah', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    $item = iMake()['id'];
    $req = eSubmit($staff, [$item]);
    $lid = $req['loans'][0]['id'];

    foreach ([['POST', "/requests/{$req['id']}/approve"], ['POST', "/requests/{$req['id']}/reject"],
              ['POST', "/loans/{$lid}/approve"], ['POST', "/loans/{$lid}/reject"]] as [$m, $p]) {
        $r = $staff->api($m, $p, []);
        eq(403, $r['status'], $p); eq('forbidden', $r['json']['error']['code'], $p);
    }
    eq('pending', lStatus($lid), 'staff tidak bisa menyetujui miliknya sendiri');

    foreach ([['POST', '/requests', ['item_ids' => [$item], 'location' => 'x']], ['POST', "/requests/{$req['id']}/cancel", []],
              ['POST', "/requests/{$req['id']}/return", []], ['POST', "/loans/{$lid}/cancel", []], ['POST', "/loans/{$lid}/return", []],
              ['POST', "/loans/{$lid}/swap", ['item_id' => $item]]] as [$m, $p, $b]) {
        $r = $admin->api($m, $p, $b);
        eq(403, $r['status'], "admin {$p}"); eq('forbidden', $r['json']['error']['code'], "admin {$p}");
    }
    eq('pending', lStatus($lid), 'admin tidak bisa bertindak sebagai staff');
    eq(403, $admin->get('/requests/new')['status'], 'halaman form pinjam hanya untuk staff');
});

t('IDOR: staff lain mendapat 404 untuk detail, batal, kembali, ganti unit; milik korban tidak berubah', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $b = e2eLogin('e_staff2'); $admin = e2eAdmin();
    $item = iMake()['id']; $spare = iMake()['id'];
    $req = eSubmit($a, [$item]);
    $lid = $req['loans'][0]['id'];
    eq(200, $admin->api('POST', "/requests/{$req['id']}/approve")['status']);

    foreach ([['GET', "/requests/{$req['id']}", null], ['POST', "/requests/{$req['id']}/cancel", []], ['POST', "/requests/{$req['id']}/return", []],
              ['POST', "/loans/{$lid}/cancel", []], ['POST', "/loans/{$lid}/return", []], ['POST', "/loans/{$lid}/swap", ['item_id' => $spare]]] as [$m, $p, $body]) {
        $r = $b->api($m, $p, $body);
        eq(404, $r['status'], "{$m} {$p}");
        eq('not_found', $r['json']['error']['code'], "{$m} {$p}");
    }
    eq('approved', lStatus($lid), 'pinjaman korban utuh');
    eq('Dipinjam', lMaster($item));
    ok(!in_array($req['id'], array_column($b->api('GET', '/requests?limit=100')['json']['data']['requests'], 'id'), true), 'tidak muncul di daftar staff lain');
    $spy = $b->api('GET', '/requests?user_id=' . $a->api('GET', '/me')['json']['data']['user']['id']);
    eq(200, $spy['status']);
    eq(0, $spy['json']['data']['total'], 'filter user_id tidak membuka data orang lain');
    eq(200, $admin->api('GET', "/requests/{$req['id']}")['status'], 'admin dapat melihat');
});

t('Mass-assignment ditolak: status/user_id/decided_by/nama barang dari input diabaikan', function () {
    eLoanReset();
    $a = e2eLogin('e_staff');
    $other = (int) Database::value("SELECT id FROM users WHERE username = 'e_staff2'");
    $item = iMake(['name' => 'Nama Asli'])['id'];
    $r = $a->api('POST', '/requests', ['item_ids' => [$item], 'due_at' => dueAt(), 'location' => 'Proyek X', 'status' => 'approved', 'user_id' => $other,
        'decided_by' => 1, 'item_name_snapshot' => 'Palsu', 'seq_no' => 1, 'legacy_id' => 'HME-0', 'returned_at' => '2020-01-01 00:00:00']);
    eq(201, $r['status'], $r['body']);
    $d = $r['json']['data'];
    eq('pending', $d['status']);
    $row = Database::one('SELECT user_id, status, decided_by, item_name_snapshot, seq_no, legacy_id, returned_at FROM loans WHERE id = ?', [$d['loans'][0]['id']]);
    eq('Nama Asli', $row['item_name_snapshot']);
    ok((int) $row['user_id'] !== $other && $row['decided_by'] === null && $row['returned_at'] === null && $row['status'] === 'pending');
    ok($row['seq_no'] > 1 && $row['legacy_id'] !== 'HME-0');
});

t('CSRF pada peminjaman: tanpa/ salah/ Origin asing → 403 dan tidak ada pengajuan', function () {
    eLoanReset();
    $a = e2eLogin('e_staff');
    $item = iMake()['id'];
    $before = (int) Database::value('SELECT COUNT(*) FROM loan_batches');
    $body = ['item_ids' => [$item], 'location' => 'CSRF'];
    eq('csrf_invalid', $a->api('POST', '/requests', $body, '')['json']['error']['code']);
    eq('csrf_invalid', $a->api('POST', '/requests', $body, 'salah')['json']['error']['code']);
    eq('csrf_origin', $a->api('POST', '/requests', $body, $a->csrf, ['Origin' => 'https://evil.example'])['json']['error']['code']);
    eq($before, (int) Database::value('SELECT COUNT(*) FROM loan_batches'));
});

t('Wajib ganti password: akun admin/staff sementara terkunci dari API peminjaman', function () {
    eLoanReset();
    aUser('e_forced_staff', 'Rahasia-Uji-1', 'staff', 1, 1);
    $c = e2eLogin('e_forced_staff');
    foreach ([['GET', '/requests'], ['GET', '/items/available'], ['POST', '/requests']] as [$m, $p]) {
        $r = $c->api($m, $p, $m === 'GET' ? null : ['item_ids' => [1], 'location' => 'x']);
        eq(403, $r['status'], $p); eq('password_change_required', $r['json']['error']['code'], $p);
    }
    Database::run("UPDATE users SET is_active = 0 WHERE username = 'e_forced_staff'");
});

// ---------------------------------------------------------------------------
section('E2E Peminjaman — alur lengkap dua akun');

t('Alur: ajukan 3 barang → admin melihat → setujui → rebutan ditolak → kembalikan → tersedia lagi', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $b = e2eLogin('e_staff2'); $admin = e2eAdmin();
    [$i1, $i2, $i3] = [iMake(['name' => 'Alat Satu'])['id'], iMake(['name' => 'Alat Dua'])['id'], iMake(['name' => 'Alat Tiga'])['id']];

    $avail = array_column($b->api('GET', '/items/available')['json']['data'], 'id');
    foreach ([$i1, $i2, $i3] as $i) { ok(in_array($i, $avail, true)); }

    $req = eSubmit($a, [$i1, $i2, $i3], ['notes' => 'Survey lokasi']);
    eq(['pending', 3, 'E_staff'], [$req['status'], count($req['loans']), $req['user']['name']]);

    $avail = array_column($b->api('GET', '/items/available')['json']['data'], 'id');
    foreach ([$i1, $i2, $i3] as $i) { ok(!in_array($i, $avail, true), 'barang yang diajukan hilang dari daftar tersedia untuk orang lain'); }
    $r = $b->api('POST', '/requests', ['item_ids' => [$i2], 'due_at' => dueAt(), 'location' => 'Rebutan']);
    eq(409, $r['status']); eq('item_unavailable', $r['json']['error']['code']);
    contains('Alat Dua', $r['json']['error']['message']);

    $pending = $admin->api('GET', '/requests?status=pending&limit=100')['json']['data'];
    $mine = array_values(array_filter($pending['requests'], fn($x) => $x['id'] === $req['id']));
    eq(1, count($mine));
    eq([true, false], [$mine[0]['can']['approve'], $mine[0]['can']['cancel']]);

    $ok = $admin->api('POST', "/requests/{$req['id']}/approve");
    eq(200, $ok['status']);
    eq('approved', $ok['json']['data']['status']);
    foreach ([$i1, $i2, $i3] as $i) { eq('Dipinjam', lMaster($i)); }
    eq(409, $admin->api('POST', "/requests/{$req['id']}/approve")['status'], 'tak bisa diproses dua kali');

    $blocked = $a->api('POST', '/requests', ['item_ids' => [iMake()['id']], 'due_at' => dueAt(), 'location' => 'Lagi']);
    eq(409, $blocked['status']); eq('has_active_loan', $blocked['json']['error']['code']);

    $first = $req['loans'][0]['id'];
    $partial = $a->api('POST', "/loans/{$first}/return");
    eq(200, $partial['status']);
    eq(['approved' => 2, 'returned' => 1], lc($partial['json']['data']['counts']));
    eq('Tersedia', lMaster($i1));

    $done = $a->api('POST', "/requests/{$req['id']}/return");
    eq('returned', $done['json']['data']['status']);
    foreach ([$i1, $i2, $i3] as $i) { eq('Tersedia', lMaster($i)); }
    $avail = array_column($b->api('GET', '/items/available')['json']['data'], 'id');
    foreach ([$i1, $i2, $i3] as $i) { ok(in_array($i, $avail, true), 'tersedia lagi'); }
    eq(201, $b->api('POST', '/requests', ['item_ids' => [$i2], 'due_at' => dueAt(), 'location' => 'Sekarang bisa'])['status']);
    eLoanReset();
});

t('Penolakan, pembatalan, dan ganti unit via API', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $admin = e2eAdmin();
    [$x, $y, $z] = [iMake(['name' => 'Unit X'])['id'], iMake(['name' => 'Unit Y'])['id'], iMake(['name' => 'Unit Z'])['id']];

    $req = eSubmit($a, [$x]);
    $sw = $a->api('POST', '/loans/' . $req['loans'][0]['id'] . '/swap', ['item_id' => $y]);
    eq(200, $sw['status']);
    eq('Unit Y', $sw['json']['data']['loans'][0]['item']['name']);
    eq(422, $a->api('POST', '/loans/' . $req['loans'][0]['id'] . '/swap', ['item_id' => 'abc'])['status']);
    eq('Cancelled', ucfirst($a->api('POST', "/requests/{$req['id']}/cancel")['json']['data']['status']));
    eq(409, $a->api('POST', "/requests/{$req['id']}/cancel")['status'], 'tak ada lagi yang pending');

    $req2 = eSubmit($a, [$z]);
    $rej = $admin->api('POST', "/requests/{$req2['id']}/reject");
    eq('rejected', $rej['json']['data']['status']);
    eq('Tersedia', lMaster($z));
    eq(0, (int) Database::value("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status IN ('pending','approved')", [(int) $a->api('GET', '/me')['json']['data']['user']['id']]));
});

t('Daftar API: filter status/q, paginasi, validasi 422, ringkasan, tanpa data sensitif', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $admin = e2eAdmin();
    $tag = 'ee' . bin2hex(random_bytes(3));
    $r1 = eSubmit($a, [iMake(['name' => "{$tag} satu"])['id']], ['location' => "Lok {$tag}"]);
    $admin->api('POST', "/requests/{$r1['id']}/reject");
    $r2 = eSubmit($a, [iMake(['name' => "{$tag} dua"])['id']], ['location' => "Lok {$tag}"]);

    $l = $a->api('GET', "/requests?q={$tag}&limit=1");
    eq(200, $l['status']);
    eq([2, 1, $r2['id']], [$l['json']['data']['total'], count($l['json']['data']['requests']), $l['json']['data']['requests'][0]['id']]);
    eq($r1['id'], $a->api('GET', "/requests?q={$tag}&limit=1&offset=1")['json']['data']['requests'][0]['id']);
    eq(1, $a->api('GET', "/requests?q={$tag}&status=rejected")['json']['data']['total']);
    foreach (['status=hilang', 'user_id=abc', 'q[]=x', 'status[]=pending'] as $bad) {
        eq(422, $a->api('GET', "/requests?{$bad}")['status'], $bad);
    }
    $sum = $a->api('GET', '/loans/summary')['json']['data'];
    ok($sum['pending'] >= 1 && $sum['rejected'] >= 1);
    lacks('password', strtolower($l['body']));
    lacks('photo_path', $l['body']);
    eLoanReset();
});

t('Validasi pengajuan via API: 422 dengan pesan per field; tidak ada loan terbentuk', function () {
    eLoanReset();
    $a = e2eLogin('e_staff');
    $count = (int) Database::value('SELECT COUNT(*) FROM loans');
    $r = $a->api('POST', '/requests', ['item_ids' => [], 'location' => '']);
    eq(422, $r['status']);
    ok(isset($r['json']['error']['fields']['item_ids'], $r['json']['error']['fields']['location']));
    foreach ([['item_ids' => 'x'], ['item_ids' => [['a']]], ['item_ids' => [1, 1]], ['item_ids' => [1], 'location' => ['x']], ['item_ids' => [1], 'location' => 'Ok', 'borrow_date' => '99/99/9999']] as $b) {
        $x = $a->api('POST', '/requests', $b + ['location' => 'Ok']);
        ok($x['status'] === 422, json_encode($b) . ' → ' . $x['status']);
    }
    eq($count, (int) Database::value('SELECT COUNT(*) FROM loans'));
});

// ---------------------------------------------------------------------------
section('E2E Peminjaman — halaman');

t('Beranda & halaman staff: ringkasan, kontainer daftar, skrip eksternal, navigasi staff', function () {
    eLoanReset();
    $a = e2eLogin('e_staff');
    $home = $a->get('/');
    eq(200, $home['status']);
    foreach (['id="req-config"', 'data-role="staff"', 'Pinjam Barang', 'id="feed-active"', 'id="feed-recent"', 'requests.js', 'href="/requests/new"', 'href="/requests"', 'id="sheet-request"'] as $n) {
        contains($n, $home['body'], $n);
    }
    lacks('href="/items"', $home['body']);
    lacks('href="/users"', $home['body']);
    ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $home['body']), 'tanpa skrip inline');

    $list = $a->get('/requests');
    eq(200, $list['status']);
    foreach (['id="req-list"', 'id="req-filter"', 'data-status="approved"', 'id="req-q"'] as $n) { contains($n, $list['body'], $n); }
    $new = $a->get('/requests/new');
    eq(200, $new['status']);
    foreach (['id="pick-list"', 'id="rn-form"', 'name="location"', 'request-new.js', 'type="date"'] as $n) { contains($n, $new['body'], $n); }
});

t('Form pinjam: staff yang masih punya barang aktif melihat penjelasan (BR-02), bukan formulir', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $admin = e2eAdmin();
    $req = eSubmit($a, [iMake()['id']]);
    $admin->api('POST', "/requests/{$req['id']}/approve");
    $page = $a->get('/requests/new');
    eq(200, $page['status']);
    contains('Kembalikan barang dulu', $page['body']);
    lacks('id="rn-form"', $page['body']);
    eLoanReset();
});

t('Admin: beranda berisi kontainer permohonan; menu Permohonan dengan lencana jumlah menunggu', function () {
    eLoanReset();
    $a = e2eLogin('e_staff'); $admin = e2eAdmin();
    eSubmit($a, [iMake()['id']]);
    $home = $admin->get('/');
    eq(200, $home['status']);
    foreach (['data-role="admin"', 'id="feed-pending"', 'id="dash"', 'dashboard.js', 'href="/requests"', 'Permohonan'] as $n) { contains($n, $home['body'], $n); }
    ok((bool) preg_match('#href="/requests"[^>]*>.*?Permohonan<span class="count">\d+</span>#s', $home['body']), 'lencana jumlah menunggu');
    $list = $admin->get('/requests?status=pending');
    eq(200, $list['status']);
    contains('data-initial="pending"', $list['body']);
    eLoanReset();
});

t('Parameter status pada halaman tidak dapat menyuntikkan HTML (di-escape di atribut)', function () {
    eLoanReset();
    $a = e2eLogin('e_staff');
    $p = $a->get('/requests?status=' . urlencode('"><script>alert(1)</script>'));
    eq(200, $p['status']);
    lacks('<script>alert(1)', $p['body']);
    contains('&lt;script&gt;', $p['body']);
});
