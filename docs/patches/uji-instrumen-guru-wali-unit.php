<?php
// Uji unit fungsi murni api/instrumen_guru_wali.php (tanpa DB/HTTP).
// Spesifikasi: docs/specs/impor-instrumen-guru-wali.md §10.3
// Jalankan dari root deploy: php docs/patches/uji-instrumen-guru-wali-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/profil_murid.php';
require __DIR__ . '/../../api/instrumen_guru_wali.php';

$pass = 0; $failed = 0;
function cek(string $id, bool $cond, $info = ''): void
{
    global $pass, $failed;
    if ($cond) { $pass++; echo "PASS $id\n"; }
    else { $failed++; echo "FAIL $id " . (is_string($info) ? $info : json_encode($info, JSON_UNESCAPED_UNICODE)) . "\n"; }
}

$kasus = json_decode(file_get_contents(__DIR__ . '/uji-instrumen-guru-wali-kasus.json'), true);
$header = $kasus['rows'][0];

// --- Kelengkapan kunci ---
$semua = array_merge(...array_values(IGW_KUNCI_MINAT));
sort($semua);
cek('kunci minat: 1..42 tepat sekali', $semua === range(1, 42), $semua);
cek('kunci minat: 7 per tipe', array_values(array_map('count', IGW_KUNCI_MINAT)) === [7, 7, 7, 7, 7, 7]);
$keg = array_merge(...array_values(IGW_KEGIATAN));
cek('kegiatan: 24 unik, 4 per tipe', count(array_unique($keg)) === 24 && array_values(array_map('count', IGW_KEGIATAN)) === [4, 4, 4, 4, 4, 4]);
$a = $keg; $b = IGW_KEGIATAN_URUT; sort($a); sort($b);
cek('kegiatan: urutan form = isi kunci', $a === $b);
cek('VAK: 5 soal x 3 opsi', count(IGW_VAK) === 5 && array_values(array_map('count', IGW_VAK)) === [3, 3, 3, 3, 3]);

// --- Peta kolom ---
$p = instrumenGwPetaKolom($header);
cek('peta: tanpa galat', $p['galat'] === null, $p['galat']);
cek('peta: 42 kolom minat', count(array_filter(array_keys($p['kolom']), fn($k) => preg_match('/^A4[01]_\d\d$/', $k))) === 42);
cek('peta: cap waktu kolom 0', ($p['kolom']['_cap_waktu'] ?? null) === 0);
cek('peta: A01 kolom 2', ($p['kolom']['A01'] ?? null) === 2);
cek('peta: A40_01 dan A41_42', isset($p['kolom']['A40_01'], $p['kolom']['A41_42']));
cek('peta: judul pernyataan', ($p['judul']['A40_01'] ?? '') === 'Aku suka mengulik peralatan', $p['judul']['A40_01'] ?? null);
cek('peta: tanpa peringatan file', $p['peringatan'] === [], $p['peringatan']);
cek('peta: tidak bergantung urutan', instrumenGwPetaKolom(array_reverse($header))['kolom']['A01'] === count($header) - 3);
$p2 = instrumenGwPetaKolom(['Cap waktu', '[A00] Setuju', '[A01] NISN', '[A99] Baru', '[A40] X [25. salah blok]']);
cek('peta: kode asing + salah blok -> peringatan', $p2['galat'] === null && count(array_filter($p2['peringatan'], fn($x) => str_contains($x, 'A99') || str_contains($x, 'blok'))) === 2, $p2['peringatan']);
cek('peta: tanpa A01 -> galat', instrumenGwPetaKolom(['Cap waktu', '[A00] x'])['galat'] === IGW_MSG_FORMAT);
cek('peta: template SIMANTAP -> galat', instrumenGwPetaKolom(['NISN', 'Nama', 'R', 'I'])['galat'] === IGW_MSG_FORMAT);
cek('peta: kode ganda -> galat', instrumenGwPetaKolom(['Timestamp', '[A00] a', '[A01] b', '[A01] c'])['galat'] !== null);

// --- Cap waktu ---
$sek = '2026-11-14 10:00:00';
$c = instrumenGwCapWaktu('46339.768541458332', $sek);
cek('cap: serial excel', $c === ['waktu' => '2026-11-13 18:26:42', 'asumsi_dmy' => false], $c);
cek('cap: d/m/Y H:i:s', instrumenGwCapWaktu('13/11/2026 18:26:42', $sek) === ['waktu' => '2026-11-13 18:26:42', 'asumsi_dmy' => false]);
cek('cap: m/d/Y terdeteksi', instrumenGwCapWaktu('11/13/2026 18:26', $sek)['waktu'] === '2026-11-13 18:26:00');
cek('cap: ambigu -> d/m + asumsi', instrumenGwCapWaktu('05/11/2026 07:00:00', $sek) === ['waktu' => '2026-11-05 07:00:00', 'asumsi_dmy' => true]);
cek('cap: hari=bulan tidak asumsi', instrumenGwCapWaktu('11/11/2026', $sek)['asumsi_dmy'] === false);
cek('cap: ISO', instrumenGwCapWaktu('2026-11-13 08:00:00', $sek)['waktu'] === '2026-11-13 08:00:00');
cek('cap: tanggal invalid', instrumenGwCapWaktu('31/02/2026', $sek) === null);
cek('cap: masa depan', instrumenGwCapWaktu('20/11/2026', $sek) === null);
cek('cap: teks acak', instrumenGwCapWaktu('kemarin', $sek) === null && instrumenGwCapWaktu('', $sek) === null);
cek('cap: serial di luar rentang', instrumenGwCapWaktu('123', $sek) === null);

// --- Skor tiga respons asli vs harapan independen ---
foreach ($kasus['harapan'] as $i => $h) {
    $row = $kasus['rows'][$i + 1];
    $jawaban = [];
    foreach ($p['kolom'] as $k => $col) if ($k !== '_cap_waktu') $jawaban[$k] = instrumenGwSel($row[$col] ?? '');
    $hasil = instrumenGwPetakan($jawaban);
    $d = $hasil['data'];
    $riasec = []; $komp = [];
    foreach (['r', 'i', 'a', 's', 'e', 'c'] as $t) { $riasec[$t] = $d['riasec_' . $t]; $komp[$t] = $d['kompetensi_' . $t]; }
    $n = $h['nisn'];
    cek("respons $n: skor minat", $riasec == $h['riasec'], $riasec);
    cek("respons $n: kode minat {$h['riasec_kode']}", $d['riasec_kode'] === $h['riasec_kode'], $d['riasec_kode']);
    cek("respons $n: skor kompetensi", $komp == $h['kompetensi'], $komp);
    cek("respons $n: kode kompetensi {$h['kompetensi_kode']}", $d['kompetensi_kode'] === $h['kompetensi_kode'], $d['kompetensi_kode']);
    cek("respons $n: V/A/K", [$d['skor_visual'], $d['skor_auditori'], $d['skor_kinestetik']] == $h['vak'], [$d['skor_visual'], $d['skor_auditori'], $d['skor_kinestetik']]);
    cek("respons $n: gaya {$h['gaya']}", $d['gaya_belajar'] === $h['gaya'], $d['gaya_belajar']);
    cek("respons $n: 8 kegiatan", count($d['kegiatan_dikuasai']) === 8, $d['kegiatan_dikuasai']);
    cek("respons $n: tanpa peringatan", $hasil['peringatan'] === [], $hasil['peringatan']);
}
// Rincian respons 1 (A. Rizki, data buatan)
$row = $kasus['rows'][1];
$jawaban = [];
foreach ($p['kolom'] as $k => $col) if ($k !== '_cap_waktu') $jawaban[$k] = instrumenGwSel($row[$col] ?? '');
$h1 = instrumenGwPetakan($jawaban);
cek('respons 1: rencana KERJA', $h1['data']['rencana_lulus'] === 'KERJA');
cek('respons 1: jarak >10 km = 10', $h1['jarak_km'] === 10.0 && $h1['jarak_kategori'] === 'Lebih dari 10 km');
cek('respons 1: transportasi', $h1['transportasi'] === 'Sepeda motor sendiri');
cek('respons 1: mapel pilihan', $h1['data']['mapel_pilihan'] === ['Matematika']);
cek('respons 1: cita-cita & institusi', $h1['data']['cita_cita'] === 'PLN' && $h1['data']['institusi_tujuan'] === 'Axis');
cek('respons 1: tanpa catatan_karir/jurusan', !isset($h1['data']['catatan_karir']) && !isset($h1['data']['jurusan_tujuan']));
// Respons 3: Belum tahu, A39 kosong tidak menghasilkan field
$row = $kasus['rows'][3];
$jawaban = [];
foreach ($p['kolom'] as $k => $col) if ($k !== '_cap_waktu') $jawaban[$k] = instrumenGwSel($row[$col] ?? '');
$h3 = instrumenGwPetakan($jawaban);
cek('respons 3: BELUM_TAHU', $h3['data']['rencana_lulus'] === 'BELUM_TAHU');
cek('respons 3: A39 kosong -> tidak ditulis', !array_key_exists('mapel_pilihan', $h3['data']));
cek('respons 3: jarak 1–3 km = 2', $h3['jarak_km'] === 2.0);

// --- Minat: seri, butir hilang, jawaban asing ---
$semuaSuka = [];
for ($no = 1; $no <= 42; $no++) $semuaSuka[sprintf('%s_%02d', $no <= 21 ? 'A40' : 'A41', $no)] = 'Suka';
$m = instrumenGwSkorMinat($semuaSuka);
cek('minat: semua suka = 100, kode RIA', $m['skor']['c'] === 100.0 && $m['kode'] === 'RIA', $m);
$x = $semuaSuka; unset($x['A40_01']); $x['A40_07'] = 'Tidak suka';
$m = instrumenGwSkorMinat($x);
cek('minat: butir hilang dihitung dari yang ada', $m['skor']['r'] === round(5 / 6 * 100, 2), $m['skor']);
$x = $semuaSuka; foreach (IGW_KUNCI_MINAT['e'] as $no) $x[sprintf('%s_%02d', $no <= 21 ? 'A40' : 'A41', $no)] = '';
$m = instrumenGwSkorMinat($x);
cek('minat: satu tipe kosong -> tidak diskor', $m['skor'] === null && $m['peringatan'] !== []);
$x = $semuaSuka; $x['A40_02'] = 'Mungkin';
$m = instrumenGwSkorMinat($x);
cek('minat: jawaban asing -> peringatan', $m['skor'] !== null && count($m['peringatan']) === 1);
cek('minat: tanpa kolom -> null tanpa peringatan', instrumenGwSkorMinat(['A01' => '1']) === ['skor' => null, 'kode' => null, 'peringatan' => []]);

// --- Kompetensi ---
$k = instrumenGwSkorKompetensi('Menari, Menggambar, Bermusik, Menulis cerita, Mengajar, Membantu, Menghitung');
cek('kompetensi: 7 pilihan -> peringatan', count($k['kegiatan']) === 7 && str_contains(implode(' ', $k['peringatan']), '7 kegiatan'), $k);
cek('kompetensi: A=100', $k['skor']['a'] === 100.0 && $k['kode'] === 'ASI', $k);
$k = instrumenGwSkorKompetensi('menari,  BERMUSIK, Terbang');
cek('kompetensi: huruf bebas + asing', $k['skor']['a'] === 50.0 && str_contains(implode(' ', $k['peringatan']), 'Terbang'), $k);
cek('kompetensi: kosong', instrumenGwSkorKompetensi('')['skor'] === null);

// --- VAK ---
$v = instrumenGwSkorVak(['A30' => 'Melihat gambar, diagram, atau video tutorial', 'A31' => 'Mengucapkannya berulang-ulang',
    'A32' => 'Memberi praktik atau kegiatan langsung', 'A33' => 'opsi lain', 'A34' => '']);
cek('vak: seri 1-1-1 -> VISUAL, dari 3 jawaban', $v['gaya'] === 'VISUAL' && $v['skor']['skor_visual'] === 33.33, $v);
cek('vak: opsi asing -> peringatan', count($v['peringatan']) === 1);
cek('vak: elipsis/tanda pisah dinormalisasi', instrumenGwSkorVak(['A33' => 'mendengarkan musik/podcast  atau mengobrol'])['gaya'] === 'AUDITORI');
cek('vak: kosong', instrumenGwSkorVak([])['skor'] === null);

// --- Pemetaan lain ---
$x = instrumenGwPetakan(['A20' => 'Kurang dari 1 km', 'A60' => 'Melanjutkan kuliah', 'A37' => 'MTK, IPA; Bahasa, PJOK, Seni', 'A21' => 'Naik perahu']);
cek('petakan: jarak <1 km', $x['jarak_km'] === 0.5);
cek('petakan: KULIAH', $x['data']['rencana_lulus'] === 'KULIAH');
cek('petakan: mapel >3 dipotong', $x['data']['mapel_termudah'] === ['MTK', 'IPA', 'Bahasa'] && str_contains(implode(' ', $x['peringatan']), 'lebih dari 3'));
cek('petakan: transportasi Lainnya', $x['transportasi'] === 'Naik perahu');
foreach (['1-3 km' => 2.0, '3–5 km' => 4.0, '5 – 10 km' => 7.5, 'Lebih dari 10 km' => 10.0] as $kat => $km) {
    cek("petakan: jarak $kat", instrumenGwPetakan(['A20' => $kat])['jarak_km'] === $km);
}
$x = instrumenGwPetakan(['A20' => 'Jauh sekali', 'A60' => 'Menikah']);
cek('petakan: opsi asing -> peringatan, tidak ditulis', $x['jarak_km'] === null && !isset($x['data']['rencana_lulus']) && count($x['peringatan']) === 2);
$x = instrumenGwPetakan(['A61' => str_repeat('a', 200), 'A51' => str_repeat('b', 300)]);
cek('petakan: teks dipotong + peringatan', mb_strlen($x['data']['cita_cita']) === 150 && mb_strlen($x['data']['keterampilan_target']) === 255 && count($x['peringatan']) === 2);
cek('petakan: semua kosong -> data kosong', instrumenGwPetakan(['A00' => 'x', 'A01' => '1'])['data'] === []);
cek('sel: 1000 karakter', mb_strlen(instrumenGwSel(str_repeat('x', 1500))) === 1000);

// --- Nama & perubahan ---
cek('nama: tanda baca/spasi', instrumenGwNormNama('A.Rizki  fiktif') === instrumenGwNormNama('A. Rizki Fiktif'));
cek('kelompok: tidak berubah', instrumenGwKelompokBerubah(['riasec_r' => 57.14, 'mapel_termudah' => ['Pai']],
    ['riasec_r' => '57.14', 'mapel_termudah' => '["Pai"]']) === []);
cek('kelompok: berubah', instrumenGwKelompokBerubah(['riasec_r' => 50.0, 'gaya_belajar' => 'VISUAL'],
    ['riasec_r' => '57.14', 'gaya_belajar' => null]) === ['minat RIASEC', 'gaya belajar']);

echo "\nRingkasan: $pass lulus, $failed gagal\n";
exit($failed > 0 ? 1 : 0);
