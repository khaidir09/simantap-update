<?php
// Uji unit fungsi murni api/profil_murid.php (tanpa DB/HTTP). Spesifikasi: docs/specs/profil-murid-360.md §10.2
// Jalankan dari root deploy: php docs/patches/uji-profil-murid-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/profil_murid.php';

$pass = 0; $failed = 0;
function cek(string $id, bool $cond, $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id " . (is_string($info) ? $info : json_encode($info, JSON_UNESCAPED_UNICODE)) . "\n"; }
}

// --- Label ---
cek('label normal', profilMuridNormalLabel('  Jml.  Saudara Kandung ') === 'jml saudara kandung');
cek('label no kps', profilMuridNormalLabel('No. KPS') === 'no kps');

// --- Nomor ---
$r = profilMuridNormalNomor('99000001', 'nisn');
cek('nisn 8 digit dipad', $r['nilai'] === '0099000001' && $r['galat'] === null, $r);
$r = profilMuridNormalNomor(99000001, 'nisn');
cek('nisn angka dipad', $r['nilai'] === '0099000001', $r);
$r = profilMuridNormalNomor("'0012345678", 'nisn');
cek('nisn apostrof', $r['nilai'] === '0012345678', $r);
$r = profilMuridNormalNomor('6.37202E+15', 'nik');
cek('nik notasi ilmiah galat', $r['nilai'] === null && $r['galat'] !== null, $r);
$r = profilMuridNormalNomor('6372 0155 0309 0001', 'nik');
cek('nik spasi', $r['nilai'] === '6372015503090001' && $r['peringatan'] === null, $r);
$r = profilMuridNormalNomor('63720155', 'nik');
cek('nik pendek peringatan', $r['nilai'] === '63720155' && $r['peringatan'] !== null, $r);
cek('nomor kosong', profilMuridNormalNomor('', 'nik')['nilai'] === null);

// --- Tanggal ---
cek('tanggal iso', profilMuridNormalTanggal('2009-03-15') === '2009-03-15');
cek('tanggal iso + jam', profilMuridNormalTanggal('2009-03-15 00:00:00') === '2009-03-15');
cek('tanggal dd/mm/yyyy', profilMuridNormalTanggal('15/03/2009') === '2009-03-15');
cek('tanggal dd-mm-yyyy', profilMuridNormalTanggal('5-3-2009') === '2009-03-05');
cek('tanggal serial excel', profilMuridNormalTanggal(45000) === '2023-03-15', profilMuridNormalTanggal(45000));
cek('tanggal serial 39887', profilMuridNormalTanggal('39887') === '2009-03-15', profilMuridNormalTanggal('39887'));
cek('tanggal tidak valid', profilMuridNormalTanggal('31/02/2009') === null && profilMuridNormalTanggal('besok') === null);

// --- Angka ---
cek('angka koma', profilMuridAngka('3,5') === 3.5 && profilMuridAngka('') === null && profilMuridAngka('abc') === false);

// --- Header Dapodik ---
$rows = json_decode(file_get_contents(__DIR__ . '/uji-profil-murid-dapodik.json'), true);
$h = profilMuridDapodikHeader($rows);
cek('header ditemukan baris ke-5', $h !== null && $h['baris_header'] === 4 && $h['mulai'] === 6, $h ? [$h['baris_header'], $h['mulai']] : null);
$tujuan = [];
foreach ($h['kolom'] as $k) $tujuan[$k['kunci']] = $k['tujuan'];
cek('header ayah dibawa ke kanan', ($tujuan['data ayah nik'] ?? '') === 'o:ayah:nik' && ($tujuan['data ayah penghasilan'] ?? '') === 'o:ayah:penghasilan', $tujuan);
cek('header ibu & wali', ($tujuan['data ibu nama'] ?? '') === 'o:ibu:nama' && ($tujuan['data wali pekerjaan'] ?? '') === 'o:wali:pekerjaan');
cek('kolom dibuang', ($tujuan['bank'] ?? '') === 'buang' && ($tujuan['nomor rekening bank'] ?? '') === 'buang'
    && ($tujuan['rekening atas nama'] ?? '') === 'buang' && ($tujuan['lintang'] ?? '') === 'buang' && ($tujuan['bujur'] ?? '') === 'buang');
cek('kolom lain ke json', ($tujuan['skhun'] ?? '') === 'lain' && ($tujuan['no registrasi akta lahir'] ?? '') === 'lain');
cek('kolom inti', ($tujuan['nisn'] ?? '') === 'nisn' && ($tujuan['jml saudara kandung'] ?? '') === 'b:jml_saudara' && ($tujuan['no kk'] ?? '') === 'b:no_kk');

// Header satu tingkat (tanpa grup orang tua), di baris pertama
$h1 = profilMuridDapodikHeader([['NISN', 'Nama', 'NIK'], ['0012345678', 'A', '6372015503090001']]);
cek('header satu tingkat', $h1 !== null && $h1['mulai'] === 1 && count($h1['kolom']) === 3);
cek('header tidak dikenali', profilMuridDapodikHeader([['Nama', 'Kelas'], ['A', 'X']]) === null);

// --- Pemetaan baris ---
$p = profilMuridPetakanDapodik($h['kolom'], $rows[6]);
cek('baris 1 nisn dipad', $p['nisn'] === '0099000001' && !$p['galat'], $p['galat']);
cek('baris 1 students', $p['students']['jenis_kelamin'] === 'Perempuan' && $p['students']['tanggal_lahir'] === '2009-03-15' && $p['students']['agama'] === 'Islam');
cek('baris 1 biodata angka', $p['biodata']['jarak_rumah_km'] === 3.5 && $p['biodata']['anak_ke'] === 2 && $p['biodata']['berat_badan'] === 48, $p['biodata']);
cek('baris 1 ortu', ($p['ortu']['ayah']['nik'] ?? '') === '6372010101800001' && ($p['ortu']['ibu']['pekerjaan'] ?? '') === 'Guru');
$json = json_encode($p);
cek('baris 1 tanpa rekening/koordinat', !str_contains($json, '0123456789') && !str_contains($json, '114.83') && !str_contains($json, 'BRI'), $json);
cek('baris 1 akta di lain', ($p['lain']['No Registrasi Akta Lahir'] ?? '') === 'AKTA-1', $p['lain']);
$p = profilMuridPetakanDapodik($h['kolom'], $rows[7]);
cek('baris 2 nisn kosong, nik ada, serial', $p['nisn'] === null && $p['nik'] === '6372015503090002' && $p['students']['tanggal_lahir'] === '2009-03-15' && !$p['galat'], $p);
$p = profilMuridPetakanDapodik($h['kolom'], $rows[9]);
cek('baris nik ilmiah galat', count($p['galat']) === 1, $p['galat']);
$p = profilMuridPetakanDapodik($h['kolom'], $rows[11]);
cek('baris tanggal tidak valid -> peringatan', $p['students']['tanggal_lahir'] === null && count($p['peringatan']) >= 1, $p['peringatan']);
cek('baris kosong', profilMuridPetakanDapodik($h['kolom'], $rows[12])['kosong'] === true);
$p = profilMuridPetakanDapodik($h1['kolom'], ['', 'Tanpa Nomor', '']);
cek('nisn & nik kosong galat', $p['galat'] === ['NISN dan NIK kosong'], $p['galat']);

// --- RIASEC ---
cek('kode riasec', profilMuridKodeRiasec(['r' => 10, 'i' => 80, 'a' => 70, 's' => 90, 'e' => 5, 'c' => 1]) === 'SIA');
cek('kode riasec seri', profilMuridKodeRiasec(['r' => 50, 'i' => 50, 'a' => 50, 's' => 50, 'e' => 50, 'c' => 50]) === 'RIA');
cek('kode riasec sebagian', profilMuridKodeRiasec(['e' => 40, 'c' => 60]) === 'CE');

// --- Validasi asesmen ---
$v = profilMuridValidasiAsesmen(['riasec_r' => '10', 'riasec_i' => '80', 'riasec_a' => '70', 'riasec_s' => '90,5', 'riasec_kode' => 'RIA',
    'gaya_belajar' => 'auditorial', 'mapel_termudah' => 'Matematika; Fisika, matematika', 'rencana_lulus' => 'Kuliah sambil kerja']);
cek('validasi ok', !$v['galat'] && $v['data']['riasec_kode'] === 'SIA' && $v['data']['riasec_s'] == 90.5 && $v['data']['gaya_belajar'] === 'AUDITORI'
    && $v['data']['mapel_termudah'] === ['Matematika', 'Fisika'] && $v['data']['rencana_lulus'] === 'KULIAH_KERJA', $v);
cek('validasi peringatan kode beda', count($v['peringatan']) === 1, $v['peringatan']);
$v = profilMuridValidasiAsesmen(['riasec_kode' => 'sec']);
cek('kode dari file tanpa skor', !$v['galat'] && $v['data']['riasec_kode'] === 'SEC', $v);
$v = profilMuridValidasiAsesmen(['riasec_kode' => 'SSA']);
cek('kode ganda galat', count($v['galat']) === 1, $v['galat']);
$v = profilMuridValidasiAsesmen(['skor_visual' => 40, 'skor_auditori' => 70, 'skor_kinestetik' => 70]);
cek('gaya dari skor (seri A,K -> A)', $v['data']['gaya_belajar'] === 'AUDITORI', $v['data']);
$v = profilMuridValidasiAsesmen(['riasec_r' => '101', 'gaya_belajar' => 'membaca', 'mapel_tersulit' => 'a,b,c,d', 'rencana_lulus' => 'nikah']);
cek('validasi galat', count($v['galat']) === 4, $v['galat']);
$v = profilMuridValidasiAsesmen(['cita_cita' => str_repeat('x', 151)]);
cek('cita-cita terlalu panjang', count($v['galat']) === 1, $v['galat']);
$v = profilMuridValidasiAsesmen(['nisn' => '0012345678', 'riasec_r' => '', 'mapel_termudah' => []]);
cek('validasi kosong', $v['kosong'] === true);
$v = profilMuridValidasiAsesmen(['riasec_r' => 0]);
cek('skor 0 bukan kosong', $v['kosong'] === false && $v['data']['riasec_r'] === 0.0 && $v['data']['riasec_kode'] === 'R', $v);

// --- Periode ---
cek('periode juli', profilMuridPeriodeDariTanggal('2026-07-01') === ['2026/2027', 'GANJIL']);
cek('periode desember', profilMuridPeriodeDariTanggal('2026-12-31') === ['2026/2027', 'GANJIL']);
cek('periode januari', profilMuridPeriodeDariTanggal('2027-01-02') === ['2026/2027', 'GENAP']);
cek('periode juni', profilMuridPeriodeDariTanggal('2027-06-30') === ['2026/2027', 'GENAP']);
cek('kunci periode urut', profilMuridKunciPeriode('2025/2026', 'GENAP') < profilMuridKunciPeriode('2026/2027', 'GANJIL'));

// --- Penyamaran ---
cek('samar 16', profilMuridSamarkan('6372015503090001') === '6372********0001');
cek('samar 8', profilMuridSamarkan('12345678') === '********');
cek('samar kosong/null', profilMuridSamarkan('') === '' && profilMuridSamarkan(null) === null);

echo "\nRingkasan: $pass lulus, $failed gagal\n";
exit($failed > 0 ? 1 : 0);
