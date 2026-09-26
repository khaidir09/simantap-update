<?php
// Uji integrasi presensi harian dari jurnal (api/presensi_harian.php) terhadap DB lokal.
// Semua data uji dibuat di dalam satu transaksi yang selalu di-ROLLBACK di akhir.
// Jalankan dari root deploy: php docs/patches/uji-presensi-harian-db.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/db.php';
require __DIR__ . '/../../api/kesiswaan_access.php';
require __DIR__ . '/../../api/pantau_nilai.php';
require __DIR__ . '/../../api/presensi_harian.php';

$db = getDB();
$pass = 0; $failed = 0;
function cek(string $id, bool $cond, string $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id $info\n"; }
}

const TGL  = '2099-01-05';
const S1   = 's_uji_ph_1';
const S2   = 's_uji_ph_2';
const KLS  = 'k_uji_ph';
$aktor = ['uid' => 'u_uji_ph', 'displayName' => 'Guru Uji'];

function jurnal(mysqli $db, string $id, string $jamKe, array $att, int $isDraft = 0): void
{
    $json = json_encode($att);
    $stmt = $db->prepare(
        "REPLACE INTO journal_entries (id, teacherId, teacherName, date, classId, className, subjectId, subjectName, period,
            topic, learningObjectives, method, media, activities, studentAttendance, isDraft, jam_ke)
         VALUES (?, 'u_uji_ph', 'Guru Uji', ?, ?, 'X UJI', 'm_uji', ?, '', 'uji', '[]', '', '[]', '', ?, ?, ?)"
    );
    $tgl = TGL; $kls = KLS; $mapel = 'Mapel' . $jamKe;
    $stmt->bind_param("sssssis", $id, $tgl, $kls, $mapel, $json, $isDraft, $jamKe);
    $stmt->execute();
}
function hapusJurnal(mysqli $db, string $id): void
{
    $db->query("DELETE FROM journal_entries WHERE id = '" . $db->real_escape_string($id) . "'");
}
function rekaman(mysqli $db, string $sid): ?array
{
    $r = $db->query("SELECT status, source, notes FROM attendance_records WHERE student_id = '$sid' AND date = '" . TGL . "'");
    return $r->fetch_assoc() ?: null;
}
function insiden(mysqli $db, string $sid): bool
{
    return $db->query("SELECT 1 FROM incidents WHERE id = 'inc_att_{$sid}_" . TGL . "'")->num_rows > 0;
}
function poin(mysqli $db, string $sid): int
{
    return (int)$db->query("SELECT total_violation_points p FROM students WHERE id = '$sid'")->fetch_assoc()['p'];
}
function sinkron(mysqli $db, array $ids): array
{
    global $aktor;
    return presensiSinkronHarian($db, TGL, $ids, $aktor);
}

function insidenTjp(mysqli $db, string $sid): ?array
{
    $r = $db->query("SELECT points, details, violation_code FROM incidents WHERE id = 'inc_tjp_{$sid}_" . TGL . "'");
    return $r->fetch_assoc() ?: null;
}
function manual(mysqli $db, string $sid, string $status): void
{
    // Sama dengan urutan save_attendance_records: simpan MANUAL, insiden ALFA, lalu sinkron harian
    global $aktor;
    $db->query("UPDATE attendance_records SET status = '$status', source = 'MANUAL' WHERE student_id = '$sid' AND date = '" . TGL . "'");
    $opsi = presensiKonfigInsiden($db) + ['reporter_id' => 'u_wali', 'reporter_name' => 'Wali', 'details' => 'uji', 'parent_call_status' => 'PARENT_INVITED'];
    presensiSinkronInsidenAlfa($db, ['id' => $sid, 'name' => 'Siswa', 'nisn' => null, 'class_name' => 'X UJI'], TGL, $status === 'ALFA', $opsi);
    presensiSinkronHarian($db, TGL, [$sid], $aktor);
}

$db->begin_transaction();
try {
    foreach ([S1, S2] as $sid) {
        $db->query("INSERT INTO students (id, nisn, name, class_id, class_name) VALUES ('$sid', '99$sid', 'Siswa $sid', '" . KLS . "', 'X UJI')");
    }
    $konfig   = presensiKonfigInsiden($db);
    $alfaPoin = $konfig['alfa_points'];
    $tjpPoin  = $konfig['tjp_points'];
    $jenis = $db->query("SELECT violation_name FROM violation_types WHERE violation_code = 'TINGGAL_JP'")->fetch_assoc();
    cek('T0 jenis pelanggaran TINGGAL_JP tersedia', $jenis !== null && $tjpPoin > 0, var_export($jenis, true));

    // 1. Jurnal jam 1: HADIR
    jurnal($db, 'j_uji_ph_1', '1', [S1 => 'HADIR']);
    sinkron($db, [S1]);
    $r = rekaman($db, S1);
    cek('T1 jam 1 HADIR -> harian HADIR/JURNAL', $r && $r['status'] === 'HADIR' && $r['source'] === 'JURNAL', var_export($r, true));
    cek('T1 tanpa insiden', !insiden($db, S1) && insidenTjp($db, S1) === null && poin($db, S1) === 0);

    // 2. Jurnal jam 3: ALFA -> tetap HADIR, pelanggaran meninggalkan jam pelajaran
    jurnal($db, 'j_uji_ph_3', '3', [S1 => 'ALFA']);
    $ubah = sinkron($db, [S1]);
    $r = rekaman($db, S1);
    $tjp = insidenTjp($db, S1);
    cek('T2 jam 3 ALFA tidak menimpa HADIR', $r['status'] === 'HADIR', var_export($r, true));
    cek('T2 catatan jam 3', str_contains((string)$r['notes'], 'jam 3 (Mapel3): ALFA'), (string)$r['notes']);
    cek('T2 tanpa insiden ALFA', !insiden($db, S1));
    cek('T2 insiden TINGGAL_JP dibuat', $tjp && $tjp['violation_code'] === 'TINGGAL_JP' && str_contains($tjp['details'], 'jam 3 (Mapel3)'), var_export($tjp, true));
    cek('T2 poin TINGGAL_JP', poin($db, S1) === $tjpPoin);
    cek('T2 perubahan dilaporkan', ($ubah[S1]['tinggal_jp'] ?? null) === 'dibuat', var_export($ubah, true));

    // 2b. Jam 4 juga ALFA -> tetap satu insiden, rincian diperbarui, poin tidak bertambah
    jurnal($db, 'j_uji_ph_4', '4', [S1 => 'ALFA']);
    sinkron($db, [S1]);
    $tjp = insidenTjp($db, S1);
    cek('T2b satu insiden per hari, rincian jam 3 & 4', $tjp && str_contains($tjp['details'], 'jam 3 (Mapel3), jam 4 (Mapel4)'), var_export($tjp, true));
    cek('T2b poin tidak bertambah', poin($db, S1) === $tjpPoin);
    hapusJurnal($db, 'j_uji_ph_4');
    sinkron($db, [S1]);

    // 3. Jurnal jam 1 dihapus -> tinggal ALFA: TINGGAL_JP diganti insiden ALFA
    hapusJurnal($db, 'j_uji_ph_1');
    sinkron($db, [S1]);
    $r = rekaman($db, S1);
    cek('T3 hanya ALFA -> harian ALFA', $r['status'] === 'ALFA' && $r['notes'] === null, var_export($r, true));
    cek('T3 insiden ALFA dibuat, TINGGAL_JP dicabut', insiden($db, S1) && insidenTjp($db, S1) === null);
    cek('T3 poin hanya ALFA', poin($db, S1) === $alfaPoin, (string)poin($db, S1));

    // 4. Jurnal jam 1 kembali sebagai IZIN -> IZIN, semua insiden & poin dicabut
    jurnal($db, 'j_uji_ph_1', '1', [S1 => 'IZIN']);
    sinkron($db, [S1]);
    $r = rekaman($db, S1);
    cek('T4 izin + alfa -> IZIN', $r['status'] === 'IZIN', var_export($r, true));
    cek('T4 tanpa insiden + poin kembali', !insiden($db, S1) && insidenTjp($db, S1) === null && poin($db, S1) === 0);

    // 5. Wali kelas MANUAL ALFA, lalu jurnal jam 5 HADIR (bug lama: insiden terhapus)
    manual($db, S1, 'ALFA');
    jurnal($db, 'j_uji_ph_5', '5', [S1 => 'HADIR']);
    sinkron($db, [S1]);
    $r = rekaman($db, S1);
    cek('T5 MANUAL ALFA dipertahankan', $r['status'] === 'ALFA' && $r['source'] === 'MANUAL', var_export($r, true));
    cek('T5 insiden MANUAL tidak ikut terhapus, tanpa TINGGAL_JP', insiden($db, S1) && insidenTjp($db, S1) === null && poin($db, S1) === $alfaPoin);

    // 6. Wali kelas ubah MANUAL ke HADIR, jurnal jam 1 & 5 ALFA (jam 3 masih ALFA)
    manual($db, S1, 'HADIR');
    jurnal($db, 'j_uji_ph_1', '1', [S1 => 'ALFA']);
    jurnal($db, 'j_uji_ph_5', '5', [S1 => 'ALFA']);
    sinkron($db, [S1]);
    $r = rekaman($db, S1);
    $tjp = insidenTjp($db, S1);
    cek('T6 MANUAL HADIR tidak ditimpa jurnal ALFA', $r['status'] === 'HADIR' && $r['source'] === 'MANUAL', var_export($r, true));
    cek('T6 tanpa insiden ALFA', !insiden($db, S1));
    cek('T6 MANUAL HADIR + jam ALFA -> TINGGAL_JP', $tjp && str_contains($tjp['details'], 'jam 1 (Mapel1), jam 3 (Mapel3), jam 5 (Mapel5)'), var_export($tjp, true));
    cek('T6 poin TINGGAL_JP', poin($db, S1) === $tjpPoin, (string)poin($db, S1));

    // 6b. Wali kelas ubah MANUAL ke IZIN (pulang dengan izin) -> TINGGAL_JP dicabut
    manual($db, S1, 'IZIN');
    cek('T6b MANUAL IZIN mencabut TINGGAL_JP', insidenTjp($db, S1) === null && !insiden($db, S1) && poin($db, S1) === 0);
    manual($db, S1, 'HADIR');

    // 7. Semua jurnal dihapus: baris MANUAL tetap ada, TINGGAL_JP dicabut
    foreach (['j_uji_ph_1', 'j_uji_ph_3', 'j_uji_ph_5'] as $j) hapusJurnal($db, $j);
    sinkron($db, [S1]);
    cek('T7 baris MANUAL tetap setelah jurnal habis', rekaman($db, S1)['source'] === 'MANUAL');
    cek('T7 TINGGAL_JP dicabut', insidenTjp($db, S1) === null && poin($db, S1) === 0);

    // 8. Siswa 2: jurnal draf tidak dihitung, jurnal terbit lalu dihapus -> baris JURNAL dihapus
    jurnal($db, 'j_uji_ph_d', '2', [S2 => 'ALFA'], 1);
    sinkron($db, [S2]);
    cek('T8 jurnal draf tidak membuat presensi', rekaman($db, S2) === null && !insiden($db, S2));
    jurnal($db, 'j_uji_ph_d', '2', [S2 => 'ALFA'], 0);
    sinkron($db, [S2]);
    cek('T8 jurnal terbit ALFA -> ALFA + insiden', rekaman($db, S2)['status'] === 'ALFA' && insiden($db, S2));
    hapusJurnal($db, 'j_uji_ph_d');
    $ubah = sinkron($db, [S2]);
    cek('T8 jurnal dihapus -> presensi & insiden dihapus', rekaman($db, S2) === null && !insiden($db, S2) && poin($db, S2) === 0);
    cek('T8 perubahan dilaporkan', ($ubah[S2]['dari'] ?? '') === 'ALFA' && $ubah[S2]['ke'] === null && $ubah[S2]['insiden'] === 'dihapus', var_export($ubah, true));

    // 9. Format presensi array + ALPHA
    jurnal($db, 'j_uji_ph_a', '4', [['studentId' => S2, 'status' => 'ALPHA']]);
    sinkron($db, [S2]);
    cek('T9 format array + ALPHA -> ALFA', rekaman($db, S2)['status'] === 'ALFA');

    // 10. Jam SAKIT/IZIN bukan meninggalkan jam pelajaran
    jurnal($db, 'j_uji_ph_b', '5', [S2 => 'HADIR']);
    jurnal($db, 'j_uji_ph_a', '4', [S2 => 'SAKIT']);
    sinkron($db, [S2]);
    cek('T10 HADIR + SAKIT tanpa TINGGAL_JP', rekaman($db, S2)['status'] === 'HADIR' && insidenTjp($db, S2) === null);
} catch (Throwable $e) {
    $failed++;
    echo "ERROR " . $e->getMessage() . "\n";
} finally {
    $db->rollback();
}

echo "\n$pass lulus, $failed gagal (semua data uji di-rollback)\n";
exit($failed ? 1 : 0);
