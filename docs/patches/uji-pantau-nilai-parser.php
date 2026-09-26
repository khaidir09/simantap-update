<?php
// Uji unit fungsi murni api/pantau_nilai.php (tanpa DB/HTTP).
// Mencakup T15 format array studentAttendance yang tidak bisa dibuat lewat save_journal.
// Jalankan dari root deploy: php docs/patches/uji-pantau-nilai-parser.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/pantau_nilai.php';

$pass = 0; $failed = 0;
function cek(string $id, bool $cond, string $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id $info\n"; }
}

// --- Presensi ---
$map = pantauNilaiParseAttendance('{"s1":"HADIR","s2":"sakit","s3":"ALPHA","s4":"LAINNYA"}');
cek('presensi map', $map === ['s1' => 'HADIR', 's2' => 'SAKIT', 's3' => 'ALFA'], json_encode($map));
$arr = pantauNilaiParseAttendance('[{"studentId":"s1","status":"IZIN"},{"student_id":"s2","status":"HADIR"},{"status":"SAKIT"},"x"]');
cek('presensi array', $arr === ['s1' => 'IZIN', 's2' => 'HADIR'], json_encode($arr));
$num = pantauNilaiParseAttendance('{"123":"HADIR"}');
cek('presensi id numerik', ($num['123'] ?? null) === 'HADIR', json_encode($num));
cek('presensi rusak', pantauNilaiParseAttendance('bukan json') === [] && pantauNilaiParseAttendance(null) === []);

// --- Semester ---
cek('semester 1/2/nama', pantauNilaiSemester('1', 'GENAP') === 'GANJIL' && pantauNilaiSemester('genap', 'GANJIL') === 'GENAP');
cek('semester default aktif', pantauNilaiSemester('', 'GENAP') === 'GENAP');
cek('semester tidak valid', pantauNilaiSemester('3', 'GANJIL') === null);

// --- Nilai ada/kosong ---
cek('nilai 0 dianggap ada', pantauNilaiHasScore(['tugas_score' => 0], 'tugas_score'));
cek('nilai "" / null kosong', !pantauNilaiHasScore(['tugas_score' => ''], 'tugas_score') && !pantauNilaiHasScore(['tugas_score' => null], 'tugas_score') && !pantauNilaiHasScore([], 'tugas_score'));

// --- Ringkasan ---
$w = ['bobot_tugas' => 30, 'bobot_kuis' => 30, 'bobot_ujian' => 40, 'kkm_default' => 75];
$g = pantauNilaiSummary([80, 70], [90, 80], ['UTS' => 60.0, 'UAS' => 70.0], 0, 0, $w);
cek('nilai akhir', $g['final_score'] == 74.0 && $g['status'] === 'REMEDI' && $g['below_kkm'] && !$g['ref_is_provisional'], json_encode($g));
$g = pantauNilaiSummary([0, 50], [40], [], 1, 1, $w);
cek('acuan sementara', $g['final_score'] === null && $g['ref_score'] == 32.5 && $g['ref_is_provisional'] && $g['below_kkm'] && $g['status'] === 'BELUM_LENGKAP', json_encode($g));
$g = pantauNilaiSummary([], [], ['SUMATIF_AKHIR' => 90.0], 0, 0, $w);
cek('SUMATIF_AKHIR = UAS', $g['ujian_uas'] == 90.0 && $g['final_score'] == 36.0, json_encode($g));
$g = pantauNilaiSummary([], [], [], 0, 0, $w);
cek('tanpa data', $g['ref_score'] === null && !$g['below_kkm'] && $g['status'] === 'BELUM_LENGKAP');

// --- Format angka dokumen ---
cek('format angka', pantauNilaiAngka(null) === '-' && pantauNilaiAngka(80.0) === '80' && pantauNilaiAngka(68.25) === '68,3');

echo "\nHasil: $pass lulus, $failed gagal\n";
exit($failed ? 1 : 0);
