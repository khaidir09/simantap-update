<?php
// Uji unit helper murni api/jadwal_kbm.php (parser jam lama, teks turunan, irisan, efektif) tanpa DB/HTTP.
// Jalankan dari root deploy: php docs/patches/uji-jadwal-kbm-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/jadwal_kbm.php';

$pass = 0; $failed = 0;
function cek(string $id, bool $cond, string $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id $info\n"; }
}
function parse(string $t, $a, $b, $m, $s): void
{
    $h = jkParseJamKe($t);
    $ok = $h['jamKeMulai'] === $a && $h['jamKeSelesai'] === $b && $h['jamMulai'] === $m && $h['jamSelesai'] === $s;
    cek('parse "' . $t . '"', $ok, json_encode($h));
}

// Contoh spesifikasi §3.4 dan data nyata
parse('08 - 11 (13.15 - 16.30)', 8, 11, '13:15', '16:30');
parse('01 - 03 (07.30 - 09.45)', 1, 3, '07:30', '09:45');
parse('3 (09:00-09:45)', 3, 3, '09:00', '09:45');
parse('1-2', 1, 2, null, null);
parse('pagi', null, null, null, null);
parse('3 s.d. 5 (10.00-12.15)', 3, 5, '10:00', '12:15');
parse('11-8 (13:00 - 12:00)', 8, 11, null, null);          // jam ke ditukar, waktu terbalik invalid
parse('12 (07.30 - 08.10)', null, null, '07:30', '08:10'); // jam ke di luar rentang 1-11
parse('25:00', null, null, null, null);
parse('06 - 09 (11.30 - 14.45)', 6, 9, '11:30', '14:45');
parse("1\u{2013}4 (7.30 - 10.45)", 1, 4, '07:30', '10:45');
parse('0 - 2 (07.00 - 08.00)', null, null, '07:00', '08:00');

// Normalisasi jam
cek('jam 7.30 -> 07:30', jkNormJam('7.30') === '07:30');
cek('jam 07:30:00 -> 07:30', jkNormJam('07:30:00') === '07:30');
cek('jam 24:00 invalid', jkNormJam('24:00') === null);
cek('jam 10:60 invalid', jkNormJam('10:60') === null);
cek('jam kosong invalid', jkNormJam('') === null && jkNormJam(null) === null);

// Jam ke valid
cek('jam ke 1 dan 11 valid', jkJamKeValid(1) === 1 && jkJamKeValid('11') === 11);
cek('jam ke 0/12/teks invalid', jkJamKeValid(0) === null && jkJamKeValid(12) === null && jkJamKeValid('a') === null && jkJamKeValid(2.5) === null);

// Teks turunan
cek('teks rentang', jkTeksJamKe(1, 4, '07:30', '10:45') === '1 - 4 (07:30 - 10:45)');
cek('teks tunggal', jkTeksJamKe(3, 3, '09:00', '09:45') === '3 (09:00 - 09:45)');
$rt = jkParseJamKe(jkTeksJamKe(2, 5, '08:10', '11:30'));
cek('teks turunan ter-parse ulang', $rt === ['jamKeMulai' => 2, 'jamKeSelesai' => 5, 'jamMulai' => '08:10', 'jamSelesai' => '11:30'], json_encode($rt));

// Irisan
cek('1-3 vs 3-4 beririsan', jkRentangBeririsan(1, 3, 3, 4));
cek('1-2 vs 3-4 tidak beririsan', !jkRentangBeririsan(1, 2, 3, 4));
cek('5 vs 1-11 beririsan', jkRentangBeririsan(5, 5, 1, 11));
cek('label jam ke', jkLabelJamKe(1, 4) === "jam ke-1\u{2013}4" && jkLabelJamKe(3, 3) === 'jam ke-3');

// Hari & versi
cek('hari kanonik', jkHari('senin') === 'Senin' && jkHari('Sabtu') === null && jkHari(null) === null);
cek('versi kosong = versi []', jkVersi('') === jkVersi('[]') && jkVersi('  ') === sha1('[]'));

// Nilai efektif
$kelas = ['rows' => [], 'byId' => [], 'byKey' => []];
foreach ([['XII_TKJ_2', 'XII TKJ 2', 'XII'], ['X_AKT_1', 'X AKT 1', 'X']] as [$id, $nm, $lv]) {
    $row = ['id' => $id, 'name' => $nm, 'level' => $lv];
    $kelas['rows'][] = $row;
    $kelas['byId'][mb_strtolower($id)] = $row;
    $kelas['byKey'][normalizeClassKey($id)] = $row;
    $kelas['byKey'][normalizeClassKey($nm)] = $row;
}
$e = jkEfektif(['id' => 'JM-1', 'hari' => 'Senin', 'kelas' => 'XII TKJ 2', 'jamKe' => '08 - 11 (13.15 - 16.30)', 'ruang' => 'R1'], $kelas);
cek('legacy lengkap', $e['legacy'] && !$e['perluDilengkapi'] && $e['efektif']['classId'] === 'XII_TKJ_2'
    && $e['efektif']['jamKeMulai'] === 8 && $e['efektif']['jamSelesai'] === '16:30', json_encode($e));
$e = jkEfektif(['id' => 'JM-2', 'hari' => 'Selasa', 'kelas' => 'XII TKJ 9', 'jamKe' => 'pagi'], $kelas);
cek('legacy perlu dilengkapi (kelas + jam)', $e['perluDilengkapi'] && count($e['alasan']) === 2, json_encode($e['alasan']));
$e = jkEfektif(['id' => 'JM-3', 'hari' => 'Rabu', 'kelas' => 'X AKT 1', 'jamKe' => '1-2'], $kelas);
cek('legacy tanpa waktu perlu dilengkapi', $e['perluDilengkapi'] && $e['alasan'] === ['Jam mulai/selesai belum diisi'], json_encode($e['alasan']));
$e = jkEfektif(['id' => 'JM-4', 'hari' => 'Kamis', 'classId' => 'x_akt_1', 'kelas' => 'X AKT 1', 'subjectCode' => 'MTK', 'mapel' => 'Matematika',
    'jamKeMulai' => 3, 'jamKeSelesai' => 4, 'jamMulai' => '09:00', 'jamSelesai' => '10:30', 'jamKe' => '3 - 4 (09:00 - 10:30)'], $kelas);
cek('format baru lengkap', !$e['legacy'] && !$e['perluDilengkapi'] && $e['efektif']['classId'] === 'X_AKT_1' && $e['efektif']['subjectCode'] === 'MTK', json_encode($e));
$e = jkEfektif(['id' => 'JM-5', 'hari' => 'Sabtu', 'classId' => 'X_AKT_1', 'jamKeMulai' => 5, 'jamKeSelesai' => 3, 'jamMulai' => '09:00', 'jamSelesai' => '08:00'], $kelas);
cek('format baru invalid', $e['perluDilengkapi'] && in_array('Hari tidak valid', $e['alasan'], true)
    && in_array('Jam ke tidak valid', $e['alasan'], true) && in_array('Jam mulai/selesai tidak valid', $e['alasan'], true), json_encode($e['alasan']));
$e = jkEfektif(['hari' => 'Senin', 'kelas' => 'XII TKJ 2', 'jamKe' => '1-2 (07.30-08.50)'], null);
cek('tabel classes belum ada', $e['perluDilengkapi'] && in_array('Data kelas belum tersedia', $e['alasan'], true));

// Kunci efektif: item tersimpan apa adanya = item yang sama dikirim ulang dengan tambahan field respons
$lama = ['id' => 'JM-1', 'hari' => 'Senin', 'kelas' => 'XII TKJ 2', 'jamKe' => '08 - 11 (13.15 - 16.30)', 'ruang' => 'R1'];
$kirim = array_merge($lama, jkEfektif($lama, $kelas));
cek('kunci efektif stabil', jkKunciEfektif(jkEfektif($lama, $kelas)['efektif']) === jkKunciEfektif(jkEfektif($kirim, $kelas)['efektif']));

// Kelas diizinkan dari penugasan
$pen = [['subject_code' => 'MTK', 'subject_name' => 'Matematika', 'class_level' => 'X', 'class_ids' => []]];
cek('penugasan per level', jkKelasDiizinkan($pen, $kelas) === ['x_akt_1' => true]);
cek('tanpa penugasan = semua', jkKelasDiizinkan([], $kelas) === null);
$pen[] = ['subject_code' => 'BIN', 'subject_name' => 'B. Indonesia', 'class_level' => '', 'class_ids' => []];
cek('penugasan tanpa level & kelas = semua', jkKelasDiizinkan($pen, $kelas) === null);

// Urutan respons
$urut = jkUrutkan([
    ['id' => 'a', 'efektif' => ['hari' => 'Rabu', 'jamKeMulai' => 1]],
    ['id' => 'b', 'efektif' => ['hari' => 'Senin', 'jamKeMulai' => null]],
    ['id' => 'c', 'efektif' => ['hari' => 'Senin', 'jamKeMulai' => 3]],
    ['id' => 'd', 'efektif' => ['hari' => null, 'jamKeMulai' => 1]],
]);
cek('urut hari lalu jam ke (null di akhir)', array_column($urut, 'id') === ['c', 'b', 'a', 'd'], json_encode(array_column($urut, 'id')));

echo "\n$pass PASS, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
