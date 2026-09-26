<?php
// Uji unit aturan status harian api/presensi_harian.php (tanpa DB/HTTP).
// Jalankan dari root deploy: php docs/patches/uji-presensi-harian-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/presensi_harian.php';
require __DIR__ . '/../../api/dispensasi.php';

$pass = 0; $failed = 0;
function cek(string $id, bool $cond, string $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id $info\n"; }
}
function jam(string ...$statuses): array
{
    $out = [];
    foreach ($statuses as $i => $st) $out[] = ['status' => $st, 'label' => 'jam ' . ($i + 1) . ' (Mapel' . ($i + 1) . ')'];
    return $out;
}

$h = presensiTurunkanHarian([]);
cek('kosong -> null', $h === null);

$h = presensiTurunkanHarian(jam('HADIR'));
cek('satu jam HADIR', $h['status'] === 'HADIR' && $h['notes'] === null, var_export($h, true));

$h = presensiTurunkanHarian(jam('HADIR', 'ALFA', 'ALFA'));
cek('hadir jam 1 lalu alfa -> HADIR', $h['status'] === 'HADIR', var_export($h, true));
cek('catatan jam yang tidak diikuti', $h['notes'] === 'Dari jurnal, tidak mengikuti jam 2 (Mapel2): ALFA; jam 3 (Mapel3): ALFA', (string)$h['notes']);

$h = presensiTurunkanHarian(jam('ALFA', 'ALFA', 'HADIR'));
cek('urutan tidak berpengaruh (alfa lalu hadir)', $h['status'] === 'HADIR');

$h = presensiTurunkanHarian(jam('HADIR', 'IZIN', 'ALFA'));
cek('hadir + izin + alfa -> HADIR', $h['status'] === 'HADIR' && str_contains($h['notes'], 'jam 2 (Mapel2): IZIN'));

$h = presensiTurunkanHarian(jam('ALFA', 'ALFA'));
cek('seluruh ALFA -> ALFA tanpa catatan', $h['status'] === 'ALFA' && $h['notes'] === null);

$h = presensiTurunkanHarian(jam('IZIN', 'ALFA'));
cek('izin + alfa -> IZIN', $h['status'] === 'IZIN' && $h['notes'] !== null);

$h = presensiTurunkanHarian(jam('IZIN', 'SAKIT', 'ALFA'));
cek('sakit diprioritaskan atas izin', $h['status'] === 'SAKIT');

$h = presensiTurunkanHarian(jam('alpha', 'Alfa'));
cek('ALPHA/huruf kecil dinormalisasi', $h['status'] === 'ALFA' && $h['notes'] === null);

$h = presensiTurunkanHarian(jam('TERLAMBAT', 'HADIR'));
cek('status asing diabaikan', $h['status'] === 'HADIR' && $h['notes'] === null);

$h = presensiTurunkanHarian(jam('TERLAMBAT'));
cek('hanya status asing -> null', $h === null);

// --- Jam ALFA untuk TINGGAL_JP ---
cek('jam ALFA', presensiJamAlfa(jam('HADIR', 'ALFA', 'IZIN', 'alpha')) === ['jam 2 (Mapel2)', 'jam 4 (Mapel4)']);
cek('tanpa jam ALFA', presensiJamAlfa(jam('HADIR', 'SAKIT')) === []);

// --- Dispensasi ---
cek('status D/DISPENSASI dikenali', presensiStatusJam('d') === 'DISPENSASI' && presensiStatusJam('Dispensasi') === 'DISPENSASI');
$h = presensiTurunkanHarian(jam('DISPENSASI', 'DISPENSASI'));
cek('seluruh DISPENSASI -> HADIR', $h['status'] === 'HADIR' && $h['notes'] === null, var_export($h, true));
$h = presensiTurunkanHarian([
    ['status' => 'HADIR', 'label' => 'jam 1 (Mapel1)'],
    ['status' => 'DISPENSASI', 'label' => 'jam 3 (Mapel3)', 'keterangan' => 'Lomba OSN'],
]);
cek('catatan dispensasi dengan kegiatan', $h['status'] === 'HADIR' && $h['notes'] === 'Dari jurnal, tidak mengikuti jam 3 (Mapel3): DISPENSASI (Lomba OSN)', (string)$h['notes']);
$h = presensiTurunkanHarian(jam('DISPENSASI', 'ALFA'));
cek('dispensasi + alfa -> HADIR', $h['status'] === 'HADIR');
cek('jam DISPENSASI bukan jam ALFA', presensiJamAlfa(jam('HADIR', 'DISPENSASI')) === []);
cek('parse jurnal simpan DISPENSASI', presensiParseJurnal('{"a":"DISPENSASI","b":"alpha","c":"X"}') === ['a' => 'DISPENSASI', 'b' => 'ALFA']);

cek('angka jam "1-2"', presensiAngkaJam('1-2') === [1, 2]);
cek('angka jam "3"', presensiAngkaJam('3') === [3]);
cek('angka jam "1, 3"', presensiAngkaJam('1, 3') === [1, 3]);
cek('angka jam "3 s.d. 5"', presensiAngkaJam('3 s.d. 5') === [3, 4, 5]);
cek('angka jam "Jam ke 7 - 8"', presensiAngkaJam('Jam ke 7 - 8') === [7, 8]);
cek('angka jam en dash', presensiAngkaJam("5\u{2013}6") === [5, 6]);
cek('angka jam kosong', presensiAngkaJam('') === [] && presensiAngkaJam('pagi') === []);

$penuh = ['jam_ke_mulai' => null, 'jam_ke_selesai' => null];
$j38   = ['jam_ke_mulai' => 3, 'jam_ke_selesai' => 8];
cek('sehari penuh mencakup semua', presensiDispensasiMencakup($penuh, [1]) && presensiDispensasiMencakup($penuh, []));
cek('jam 3-8 mencakup "2-3"', presensiDispensasiMencakup($j38, [2, 3]));
cek('jam 3-8 tidak mencakup "1-2"', !presensiDispensasiMencakup($j38, [1, 2]));
cek('jam 3-8 tidak mencakup jam tak terbaca', !presensiDispensasiMencakup($j38, []));

$dasar = ['student_ids' => ['s1', 's1', ' s2 '], 'tanggal_mulai' => '2026-09-28', 'tanggal_selesai' => '2026-09-30',
          'jam_ke_mulai' => 3, 'jam_ke_selesai' => 8, 'kegiatan' => ' Lomba OSN ', 'nomor_surat' => '', 'surat_path' => ''];
[$d, $g] = dispensasiValidasi($dasar);
cek('validasi dasar', $g === null && $d['student_ids'] === ['s1', 's2'] && $d['kegiatan'] === 'Lomba OSN' && $d['nomor_surat'] === null && $d['jam_ke_mulai'] === 3, var_export([$d, $g], true));
[$d, $g] = dispensasiValidasi(['sehari_penuh' => true, 'tanggal_selesai' => ''] + $dasar);
cek('validasi sehari penuh + selesai kosong = mulai', $g === null && $d['jam_ke_mulai'] === null && $d['tanggal_selesai'] === '2026-09-28');
cek('validasi tanpa siswa', dispensasiValidasi(['student_ids' => []] + $dasar)[1] !== null);
cek('validasi tanggal terbalik', dispensasiValidasi(['tanggal_selesai' => '2026-09-01'] + $dasar)[1] !== null);
cek('validasi tanggal tidak ada', dispensasiValidasi(['tanggal_mulai' => '2026-02-30'] + $dasar)[1] !== null);
cek('validasi rentang > 31 hari', dispensasiValidasi(['tanggal_selesai' => '2026-11-30'] + $dasar)[1] !== null);
cek('validasi jam terbalik', dispensasiValidasi(['jam_ke_mulai' => 8, 'jam_ke_selesai' => 3] + $dasar)[1] !== null);
cek('validasi jam kosong tanpa sehari penuh', dispensasiValidasi(['jam_ke_mulai' => null] + $dasar)[1] !== null);
cek('validasi kegiatan kosong', dispensasiValidasi(['kegiatan' => '  '] + $dasar)[1] !== null);
cek('validasi path surat asing ditolak', dispensasiValidasi(['surat_path' => 'uploads/../api/db.php'] + $dasar)[1] !== null);
cek('validasi path surat sah', dispensasiValidasi(['surat_path' => 'uploads/tu/dispensasi/202609/' . str_repeat('a', 32) . '.pdf'] + $dasar)[1] === null);
cek('label cakupan', dispensasiLabelCakupan(null, null) === 'Sehari penuh' && dispensasiLabelCakupan(3, 8) === 'Jam ke-3 s.d. 8' && dispensasiLabelCakupan(4, 4) === 'Jam ke-4');
cek('rentang tanggal', dispensasiRentangTanggal('2026-09-29', '2026-10-01') === ['2026-09-29', '2026-09-30', '2026-10-01']);

echo "\n$pass lulus, $failed gagal\n";
exit($failed ? 1 : 0);
