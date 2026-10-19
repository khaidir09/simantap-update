<?php
// Uji unit helper jenjang sekolah (tanpa database): jenjangParseLevel & jenjangDeteksi.
// Tabel kasus dibagi dengan uji JS (uji-jenjang-sekolah-parser.mjs) agar parser PHP dan bundle identik.
// Pemakaian (dari root deploy): php docs/patches/uji-jenjang-sekolah-unit.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/jenjang_sekolah.php';

$kasus = json_decode(file_get_contents(__DIR__ . '/uji-jenjang-sekolah-kasus.json'), true);
$daftar = jenjangDaftar();
$gagal = 0;
$total = 0;

foreach ($kasus['parse'] as [$teks, $jenjang, $harap]) {
    $total++;
    $hasil = jenjangParseLevel($teks, $daftar[$jenjang]);
    if ($hasil !== $harap) {
        $gagal++;
        echo 'GAGAL parse ' . json_encode($teks) . " ($jenjang): dapat " . json_encode($hasil) . ', harap ' . json_encode($harap) . PHP_EOL;
    }
}

foreach ($kasus['deteksi'] as [$nama, $harap]) {
    $total++;
    $hasil = jenjangDeteksi($nama);
    if ($hasil !== $harap) {
        $gagal++;
        echo 'GAGAL deteksi ' . json_encode($nama) . ": dapat $hasil, harap $harap" . PHP_EOL;
    }
}

echo ($gagal ? "GAGAL $gagal" : 'LULUS') . " dari $total kasus" . PHP_EOL;
exit($gagal ? 1 : 0);
