<?php
// Uji unit aturan status harian api/presensi_harian.php (tanpa DB/HTTP).
// Jalankan dari root deploy: php docs/patches/uji-presensi-harian-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/pantau_nilai.php';
require __DIR__ . '/../../api/presensi_harian.php';

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

echo "\n$pass lulus, $failed gagal\n";
exit($failed ? 1 : 0);
