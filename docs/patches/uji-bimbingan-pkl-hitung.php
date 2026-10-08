<?php
// Pembantu uji-bimbingan-pkl-api.mjs: cetak jumlah baris tabel yang TIDAK boleh berubah
// oleh jurnal bimbingan PKL (presensi, insiden, poin, panggilan ortu, jurnal mengajar).
// Jalankan dari root deploy: php docs/patches/uji-bimbingan-pkl-hitung.php
define('SIMANTAP_API', true);
require __DIR__ . '/../../api/db.php';

$db = getDB();
$hasil = [];
$kueri = [
    'attendance_records' => 'SELECT COUNT(*) FROM attendance_records',
    'incidents'          => 'SELECT COUNT(*) FROM incidents',
    'parent_calls'       => 'SELECT COUNT(*) FROM parent_calls',
    'journal_entries'    => 'SELECT COUNT(*) FROM journal_entries',
    'poin_siswa'         => 'SELECT COALESCE(SUM(total_violation_points), 0) FROM students',
];
foreach ($kueri as $k => $sql) {
    try {
        $hasil[$k] = (int)$db->query($sql)->fetch_row()[0];
    } catch (Throwable $e) {
        $hasil[$k] = null;
    }
}
echo json_encode($hasil);
