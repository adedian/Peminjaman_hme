<?php
declare(strict_types=1);

/**
 * Revisi V2b — logika service worker, diuji di Node dengan lingkungan ServiceWorker tiruan (tests/sw.test.js).
 * Dilewati (dengan catatan) bila Node tidak terpasang. Tidak membuktikan pengiriman push sungguhan oleh browser.
 */

section('V2b-SW — logika service worker (Node)');

t('Service worker: push/klik/fetch/cache berperilaku benar (dijalankan di Node)', function () {
    $node = null;
    foreach (['node', 'node.exe'] as $cand) {
        $out = [];
        $code = 1;
        @exec($cand . ' --version 2>&1', $out, $code);
        if ($code === 0) { $node = $cand; break; }
    }
    if ($node === null) {
        echo "      (dilewati: Node tidak terpasang — uji logika SW tidak dijalankan)\n";
        ok(true);
        return;
    }
    $sw = dirname(__DIR__, 2) . '/app/Views/pwa/sw.js';
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__) . '/sw.test.js') . ' ' . escapeshellarg($sw) . ' 2>&1';
    $lines = [];
    exec($cmd, $lines, $code);
    $bad = [];
    $n = 0;
    foreach ($lines as $l) {
        $r = json_decode($l, true);
        if (!is_array($r)) { $bad[] = 'keluaran tak terduga: ' . $l; continue; }
        $n++;
        if (!$r['ok']) { $bad[] = $r['name'] . ' → ' . ($r['msg'] ?? ''); }
    }
    eq([], $bad);
    ok($n >= 8, "kasus SW dijalankan: {$n}");
});
