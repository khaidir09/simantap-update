<?php
// Uji unit fungsi murni api/guru_wali.php (tanpa DB/HTTP). Spesifikasi: docs/specs/penugasan-guru-wali.md §10.3
// Jalankan dari root deploy: php docs/patches/uji-guru-wali-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/guru_wali.php';

$pass = 0; $failed = 0;
function cek(string $id, bool $cond, $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id " . (is_string($info) ? $info : json_encode($info, JSON_UNESCAPED_UNICODE)) . "\n"; }
}

// --- Validasi tanggal ---
$h = '2026-11-12';
cek('tanggal sah', guruWaliValidasiTanggal('2026-11-12', $h) === null);
cek('tanggal lampau sah', guruWaliValidasiTanggal('2024-02-29', $h) === null);
cek('tanggal masa depan', guruWaliValidasiTanggal('2026-11-13', $h) === 'Tanggal tidak boleh di masa depan.');
cek('bukan kabisat', guruWaliValidasiTanggal('2025-02-29', $h) !== null);
cek('format salah', guruWaliValidasiTanggal('12/11/2026', $h) !== null);
cek('bukan string', guruWaliValidasiTanggal(20261112, $h) !== null);
cek('bulan 13', guruWaliValidasiTanggal('2026-13-01', $h) !== null);
cek('tahun < 2000', guruWaliValidasiTanggal('1999-12-31', $h) !== null);

// --- Tanggal sebelum ---
cek('T-1 biasa', guruWaliTanggalSebelum('2026-11-12') === '2026-11-11');
cek('T-1 awal bulan', guruWaliTanggalSebelum('2026-03-01') === '2026-02-28');
cek('T-1 kabisat', guruWaliTanggalSebelum('2028-03-01') === '2028-02-29');
cek('T-1 awal tahun', guruWaliTanggalSebelum('2027-01-01') === '2026-12-31');

// --- Alasan ---
cek('alasan kode', guruWaliNormalisasiAlasan('MUTASI_GURU') === 'MUTASI_GURU');
cek('alasan kode kecil', guruWaliNormalisasiAlasan('beban_kerja') === 'BEBAN_KERJA');
cek('alasan label', guruWaliNormalisasiAlasan('Permintaan murid/orang tua') === 'PERMINTAAN_MURID_ORTU');
cek('alasan label spasi', guruWaliNormalisasiAlasan('  koreksi   data ') === 'KOREKSI_DATA');
cek('alasan tidak dikenal', guruWaliNormalisasiAlasan('Cuti') === null);
cek('alasan kosong', guruWaliNormalisasiAlasan('') === null && guruWaliNormalisasiAlasan(null) === null && guruWaliNormalisasiAlasan([]) === null);

// --- NISN / NIP ---
cek('nisn 10 digit', guruWaliNormalisasiNisn('0098000001') === '0098000001');
cek('nisn 8 digit dipad', guruWaliNormalisasiNisn('98000001') === '0098000001');
cek('nisn angka', guruWaliNormalisasiNisn(98000001) === '0098000001');
cek('nisn float', guruWaliNormalisasiNisn(98000001.0) === '0098000001');
cek('nisn apostrof & spasi', guruWaliNormalisasiNisn(" '0098 000 001 ") === '0098000001');
cek('nisn notasi ilmiah', guruWaliNormalisasiNisn('9.8000010E+7') === '0098000010');
cek('nisn notasi ilmiah koma', guruWaliNormalisasiNisn('9,8000010E+7') === '0098000010');
cek('nisn kosong', guruWaliNormalisasiNisn('') === '' && guruWaliNormalisasiNisn(null) === '');
cek('nisn huruf saja', guruWaliNormalisasiNisn('abc') === '');
cek('nisn 7 digit tidak dipad', guruWaliNormalisasiNisn('1234567') === '1234567');
cek('nip spasi', guruWaliNormalisasiNip('19900202 202012 2 002') === '199002022020122002');

// --- Kuota ---
cek('kuota bawaan kosong', guruWaliKuota(null) === 20 && guruWaliKuota('') === 20);
cek('kuota 0 tidak sah', guruWaliKuotaValid(0) === null && guruWaliKuota('0') === 20);
cek('kuota 101 tidak sah', guruWaliKuotaValid('101') === null);
cek('kuota teks', guruWaliKuotaValid('abc') === null && guruWaliKuotaValid('1.5') === null);
cek('kuota string sah', guruWaliKuotaValid(' 15 ') === 15 && guruWaliKuota('15') === 15);
cek('kuota batas', guruWaliKuotaValid(1) === 1 && guruWaliKuotaValid(100) === 100);
cek('kuota bool', guruWaliKuotaValid(true) === null);

// --- Klasifikasi baris impor ---
$ctx = [
    'murid' => [
        '0098000001' => [['id' => 's1', 'name' => 'Satu', 'class_name' => 'X A', 'aktif' => true]],
        '0098000002' => [['id' => 's2', 'name' => 'Dua', 'class_name' => 'X A', 'aktif' => true]],
        '0098000003' => [['id' => 's3', 'name' => 'Tiga', 'class_name' => 'X A', 'aktif' => false]],
        '0098000004' => [['id' => 's4', 'name' => 'Empat', 'class_name' => 'X B', 'aktif' => true], ['id' => 's4b', 'name' => 'Empat B', 'class_name' => 'X B', 'aktif' => true]],
        '0098000005' => [['id' => 's5', 'name' => 'Lima', 'class_name' => 'X B', 'aktif' => true]],
    ],
    'email' => [
        'g1@x.id' => ['uid' => 'g1', 'displayName' => 'Guru Satu', 'role' => 'GURU'],
        'g2@x.id' => ['uid' => 'g2', 'displayName' => 'Guru Dua', 'role' => 'GURU'],
        'tu@x.id' => ['uid' => 'tu', 'displayName' => 'TU', 'role' => 'TATA_USAHA'],
    ],
    'nip' => [
        '111' => [['uid' => 'g1', 'displayName' => 'Guru Satu', 'role' => 'GURU']],
        '222' => [['uid' => 'g2', 'displayName' => 'Guru Dua', 'role' => 'GURU'], ['uid' => 'g9', 'displayName' => 'Guru Sembilan', 'role' => 'GURU']],
    ],
    'berulang' => ['0098000005' => true],
    'aktif' => [
        's1' => ['id' => 'p1', 'guru_uid' => 'g1', 'guru_nama' => 'Guru Satu', 'tanggal_mulai' => '2026-07-14'],
    ],
];
$k = fn(array $b) => guruWaliKlasifikasiBaris($b + ['baris' => 2], $ctx);
$r = $k(['nisn' => '98000002', 'email' => 'G1@X.ID ']);
cek('impor BARU (email beda huruf, nisn dipad)', $r['status'] === 'BARU' && $r['student_id'] === 's2' && $r['guru_uid'] === 'g1', $r);
$r = $k(['nisn' => '0098000001', 'email' => 'g2@x.id']);
cek('impor GANTI', $r['status'] === 'GANTI' && $r['guru_lama_nama'] === 'Guru Satu' && $r['penugasan_lama_id'] === 'p1', $r);
$r = $k(['nisn' => '0098000001', 'email' => 'g1@x.id']);
cek('impor SAMA', $r['status'] === 'SAMA', $r);
$r = $k(['nisn' => '0098000001', 'email' => '', 'nip' => '']);
cek('impor KOSONG', $r['status'] === 'KOSONG', $r);
$r = $k(['nisn' => '', 'email' => '', 'nip' => '']);
cek('impor KOSONG walau NISN kosong', $r['status'] === 'KOSONG', $r);
$r = $k(['nisn' => '', 'email' => 'g1@x.id']);
cek('impor NISN kosong', $r['status'] === 'GALAT' && str_contains($r['pesan'], 'NISN kosong'), $r);
$r = $k(['nisn' => '0098000009', 'email' => 'g1@x.id']);
cek('impor NISN tidak ditemukan', $r['status'] === 'GALAT' && $r['pesan'] === 'NISN tidak ditemukan', $r);
$r = $k(['nisn' => '0098000004', 'email' => 'g1@x.id']);
cek('impor NISN ganda di DB', $r['status'] === 'GALAT' && str_contains($r['pesan'], 'lebih dari satu murid'), $r);
$r = $k(['nisn' => '0098000005', 'email' => 'g1@x.id']);
cek('impor NISN berulang di file', $r['status'] === 'GALAT' && str_contains($r['pesan'], 'lebih dari sekali'), $r);
$r = $k(['nisn' => '0098000003', 'email' => 'g1@x.id']);
cek('impor murid nonaktif', $r['status'] === 'GALAT' && $r['pesan'] === 'Murid tidak aktif', $r);
$r = $k(['nisn' => '0098000002', 'email' => 'tidakada@x.id']);
cek('impor email tidak ada', $r['status'] === 'GALAT' && str_contains($r['pesan'], 'tidak ditemukan'), $r);
$r = $k(['nisn' => '0098000002', 'email' => 'tu@x.id']);
cek('impor bukan guru', $r['status'] === 'GALAT' && str_contains($r['pesan'], 'bukan akun guru'), $r);
$r = $k(['nisn' => '0098000002', 'email' => '', 'nip' => '1 1 1']);
cek('impor lewat NIP', $r['status'] === 'BARU' && $r['guru_uid'] === 'g1', $r);
$r = $k(['nisn' => '0098000002', 'email' => '', 'nip' => '222']);
cek('impor NIP ganda', $r['status'] === 'GALAT' && str_contains($r['pesan'], 'lebih dari satu akun'), $r);
$r = $k(['nisn' => '0098000002', 'email' => '', 'nip' => '999']);
cek('impor NIP tidak ada', $r['status'] === 'GALAT' && str_contains($r['pesan'], 'NIP 999'), $r);
$r = $k(['nisn' => '0098000002', 'email' => 'g2@x.id', 'nip' => '111']);
cek('impor email didahulukan dari NIP', $r['status'] === 'BARU' && $r['guru_uid'] === 'g2', $r);

// --- Status efektif ---
cek('efektif aktif', guruWaliStatusEfektif('AKTIF', true) === 'AKTIF');
cek('efektif menggantung', guruWaliStatusEfektif('AKTIF', false) === 'SELESAI_MURID_NONAKTIF');
cek('efektif selesai', guruWaliStatusEfektif('SELESAI', false) === 'SELESAI');

echo "\nSelesai: $pass lulus, $failed gagal\n";
exit($failed ? 1 : 0);
