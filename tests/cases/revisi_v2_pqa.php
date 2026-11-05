<?php
declare(strict_types=1);

/**
 * Revisi V2 — R4: PQA (jadwal pencabutan, perpanjangan, pencabutan ke kantor / pindah site, histori lokasi).
 * Memakai helper dari tests/cases/pqa.php dan loans.php.
 */

use App\Core\Database;
use App\Services\PqaService;

function v2Item(int $id): string
{
    return (string) Database::value('SELECT master_status FROM items WHERE id = ?', [$id]);
}

section('V2-R4 — PQA: jadwal, perpanjangan, pencabutan');

t('Pengajuan PQA: tanggal & jam pemasangan wajib dan wajar; catatan tersimpan', function () {
    $s = lStaff('l_staff1'); lReset($s);
    $u = qUnit('PQA V2 A');
    $base = ['item_id' => $u, 'ppic' => 'Naufal', 'location' => 'Site A', 'install_team' => 'Tim', 'target_days' => 7];
    foreach ([null, '', 'besok', '2026-02-30T10:00', date('Y-m-d\TH:i', time() - 5 * 86400), date('Y-m-d\TH:i', time() + 200 * 86400), ['x']] as $bad) {
        $e = qErr(fn() => PqaService::submit($s, $base + ['planned_install_at' => $bad], ureq()));
        ok(isset($e->fields()['planned_install_at']), 'ditolak: ' . json_encode($bad));
    }
    eq(0, (int) Database::value('SELECT COUNT(*) FROM pqa_installations WHERE item_id = ?', [$u]));
    $p = PqaService::submit($s, $base + ['planned_install_at' => dueAt(1), 'notes' => 'Bawa kabel'], ureq());
    eq('Pending', $p['status']);
    eq('Bawa kabel', $p['notes']);
    ok($p['planned_install_at'] !== null);
    eq(null, $p['due_at'], 'jadwal cabut baru ada setelah dikonfirmasi');
});

t('Konfirmasi pemasangan: jadwal cabut = waktu pasang aktual + 7 x 24 jam; histori lokasi "installed"', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $p = qInstalled($s, $adm, qUnit('PQA V2 B'));
    $r = qRow($p['id']);
    eq(date('Y-m-d H:i:s', strtotime($r['installed_at'] . ' +7 days')), $r['due_at']);
    ok($p['remaining_seconds'] > 7 * 86400 - 120 && $p['remaining_seconds'] <= 7 * 86400, 'sisa waktu ~7 hari');
    eq(false, $p['overdue']);
    eq(['installed'], array_column(PqaService::locationHistory($p['item']['id']), 'event'));
});

t('Perpanjangan: jadwal bergeser N x 24 jam, riwayat durasi menyimpan jadwal lama & baru; ditolak tidak menggeser', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $p = qInstalled($s, $adm, qUnit('PQA V2 C'));
    $oldDue = qRow($p['id'])['due_at'];
    PqaService::requestExtension($p['id'], 3, $s, ureq());
    PqaService::decideExtension($p['id'], false, $adm, ureq());
    eq($oldDue, qRow($p['id'])['due_at'], 'ditolak: jadwal tetap');
    PqaService::requestExtension($p['id'], 3, $s, ureq());
    $res = PqaService::decideExtension($p['id'], true, $adm, ureq());
    $new = qRow($p['id'])['due_at'];
    eq(date('Y-m-d H:i:s', strtotime($oldDue . ' +3 days')), $new);
    eq(10, $res['target_days']);
    $h = array_values(array_filter($res['history'], fn($x) => $x['type'] === 'extend' && $x['status'] === 'approved'));
    eq(1, count($h));
    eq([7, 10, $oldDue, $new], [$h[0]['old_target_days'], $h[0]['new_target_days'], $h[0]['old_due_at'], $h[0]['new_due_at']]);
});

t('Melewati jadwal cabut: overdue=true & sisa waktu negatif; tidak lagi setelah dicabut', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $p = qInstalled($s, $adm, qUnit('PQA V2 D'));
    qAgo($p['id'], '8 DAY');
    $x = PqaService::find($p['id'], $adm);
    eq(true, $x['overdue']);
    ok($x['remaining_seconds'] < 0);
    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq());
    eq(true, PqaService::find($p['id'], $adm)['overdue'], 'Req Cabut tetap dihitung aktif');
    PqaService::decideRemoval($p['id'], true, $adm, ureq());
    eq(false, PqaService::find($p['id'], $adm)['overdue'], 'sudah dicabut tidak terlambat');
});

t('Pencabutan A — kembali ke kantor: Dicabut, admin & waktu aktual tercatat, unit Tersedia, histori lokasi', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $p = qInstalled($s, $adm, qUnit('PQA V2 E'), ['location' => 'Site Asal']);
    $item = $p['item']['id'];
    PqaService::requestRemoval($p['id'], 'Tim Cabut', $s, ureq(), ['destination' => 'office']);
    eq('office', PqaService::find($p['id'], $adm)['removal_destination']);
    $res = PqaService::decideRemoval($p['id'], true, $adm, ureq());
    eq('Dicabut', $res['status']);
    ok($res['removed_at'] !== null);
    eq((int) $adm['id'], (int) qRow($p['id'])['removed_by']);
    eq('Tersedia', v2Item($item));
    eq(null, qRow($p['id'])['next_installation_id']);
    $hist = PqaService::locationHistory($item);
    eq(['removed_office', 'installed'], array_column($hist, 'event'));
    eq(['Site Asal', 'Kantor'], [$hist[0]['location'], $hist[0]['to_location']]);
});

t('Pencabutan B — pindah site: validasi; unit tidak dibebaskan; pemasangan berikutnya Pending & terhubung; konfirmasi → Terpasang', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $p = qInstalled($s, $adm, qUnit('PQA V2 F'), ['location' => 'Site Satu']);
    $item = $p['item']['id'];
    $e = fn(array $x) => qErr(fn() => PqaService::requestRemoval($p['id'], 'Tim', $s, ureq(), $x))->fields();
    ok(isset($e(['destination' => 'langit'])['destination']), 'tujuan tidak dikenal');
    ok(isset($e(['destination' => 'site'])['destination_site']), 'site tujuan wajib');
    ok(isset($e(['destination' => 'site', 'destination_site' => 'Site Dua'])['move_target_days']), 'target hari wajib');
    ok(isset($e(['destination' => 'site', 'destination_site' => ' site satu ', 'move_target_days' => 5])['destination_site']), 'site sama ditolak');
    eq('Terpasang', PqaService::find($p['id'], $adm)['status'], 'validasi gagal tidak mengubah status');

    PqaService::requestRemoval($p['id'], 'Tim Pindah', $s, ureq(), ['destination' => 'site', 'destination_site' => 'Site Dua', 'move_target_days' => 5]);
    $res = PqaService::decideRemoval($p['id'], true, $adm, ureq());
    eq('Dicabut', $res['status']);
    $oldRow = qRow($p['id']);
    ok($oldRow['next_installation_id'] !== null, 'terhubung ke pemasangan berikutnya');
    $new = PqaService::find((int) $oldRow['next_installation_id'], $adm);
    eq(['Pending', 'Site Dua', 5, $p['id']], [$new['status'], $new['location'], $new['target_days'], $new['previous_installation_id']]);
    eq($p['ppic'], $new['ppic']);
    eq('Dipinjam', v2Item($item), 'unit tidak tersedia selama pemindahan');
    ok(!in_array($item, array_column(PqaService::availableUnits(), 'id'), true));
    eq(409, qErr(fn() => PqaService::submit($s, ['item_id' => $item, 'ppic' => 'Xan', 'location' => 'Yoyo', 'install_team' => 'Zed', 'target_days' => 3, 'planned_install_at' => dueAt(1)], ureq()))->status());
    eq(['moved_out', 'installed'], array_column(PqaService::locationHistory($item), 'event'));

    $ok = PqaService::decideInstall($new['id'], true, $adm, ureq());
    eq('Terpasang', $ok['status']);
    eq(date('Y-m-d H:i:s', strtotime(qRow($new['id'])['installed_at'] . ' +5 days')), qRow($new['id'])['due_at']);
    $hist = PqaService::locationHistory($item);
    eq(['moved_in', 'moved_out', 'installed'], array_column($hist, 'event'));
    eq(['Site Dua', 'Site Satu', 'Site Satu'], array_column($hist, 'location'));
    eq('Site Dua', $hist[1]['to_location']);
});

t('Pindah site ditolak admin → unit dibebaskan & tercatat kembali ke kantor; tolak permintaan cabut membersihkan tujuan', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $p = qInstalled($s, $adm, qUnit('PQA V2 G'), ['location' => 'Site Tiga']);
    $item = $p['item']['id'];
    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq(), ['destination' => 'site', 'destination_site' => 'Site Empat', 'move_target_days' => 4]);
    PqaService::decideRemoval($p['id'], false, $adm, ureq());
    $r = qRow($p['id']);
    eq(['Terpasang', null, null, null], [$r['status'], $r['removal_destination'], $r['destination_site'], $r['move_target_days']]);

    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq(), ['destination' => 'site', 'destination_site' => 'Site Empat', 'move_target_days' => 4]);
    PqaService::decideRemoval($p['id'], true, $adm, ureq());
    $new = (int) qRow($p['id'])['next_installation_id'];
    PqaService::decideInstall($new, false, $adm, ureq());
    eq('Ditolak', qRow($new)['status']);
    eq('Tersedia', v2Item($item));
    eq('removed_office', PqaService::locationHistory($item)[0]['event']);
});

t('Konsistensi: tidak ada unit dengan dua pemasangan aktif; rantai pemindahan saling menunjuk', function () {
    eq(0, (int) Database::value("SELECT COUNT(*) FROM (SELECT item_id FROM pqa_installations WHERE status IN ('Pending','Terpasang','Req Cabut') GROUP BY item_id HAVING COUNT(*) > 1) x"));
    eq(0, (int) Database::value('SELECT COUNT(*) FROM pqa_installations p WHERE p.next_installation_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM pqa_installations n WHERE n.id = p.next_installation_id AND n.previous_installation_id = p.id)'));
    eq(0, (int) Database::value('SELECT COUNT(*) FROM pqa_installations p WHERE p.previous_installation_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM pqa_installations n WHERE n.id = p.previous_installation_id AND n.next_installation_id = p.id)'));
});
