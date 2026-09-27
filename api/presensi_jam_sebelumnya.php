<?php
/**
 * Presensi jam sebelumnya untuk form jurnal: siswa yang SAKIT/IZIN di jam pelajaran sebelumnya
 * pada hari yang sama, agar guru jam berikutnya tidak perlu menandai ulang.
 *
 * - Sumber utama: jurnal non-draf kelas itu yang jamnya selesai SEBELUM jam jurnal yang sedang
 *   diisi. Yang dipakai hanya jurnal terakhir sebelum jam ini: siswa IZIN di jam 1 lalu HADIR
 *   di jam 3 tidak diisi IZIN untuk jam 5. ALFA tidak diteruskan (siswa bisa kembali ke kelas;
 *   kasus bolos ditangani insiden TINGGAL_JP di presensi_harian.php).
 * - Cadangan: presensi harian MANUAL wali kelas berstatus SAKIT/IZIN, bila belum ada jurnal
 *   sebelumnya yang mencatat siswa itu.
 * - Siswa yang dispensasinya mencakup jam ini dilewati (form mengisi D lewat dispensasi_jurnal).
 * - Hanya saran pengisian awal di form jurnal BARU; guru tetap bisa mengubahnya.
 *
 * Bergantung pada presensi_harian.php, dispensasi.php, dan kesiswaan_access.php.
 * Hanya boleh dimuat lewat api/index.php.
 */
if (!defined('SIMANTAP_API')) exit;

/**
 * Status saran dari jurnal-jurnal pada jam terakhir sebelum jam ini (fungsi murni).
 * $entri: list ['status' => ..., 'label' => ...] dari jurnal dengan jam akhir yang sama.
 * Ada HADIR/DISPENSASI -> null; lalu SAKIT, lalu IZIN; seluruhnya ALFA -> null.
 * Hasil: ['status' => 'SAKIT'|'IZIN', 'label' => [...]] atau null.
 */
function presensiSaranDariJamTerakhir(array $entri): ?array
{
    $statuses = array_column($entri, 'status');
    if (in_array('HADIR', $statuses, true) || in_array('DISPENSASI', $statuses, true)) return null;
    foreach (['SAKIT', 'IZIN'] as $st) {
        if (!in_array($st, $statuses, true)) continue;
        $label = [];
        foreach ($entri as $e) {
            if ($e['status'] === $st && $e['label'] !== '') $label[] = $e['label'];
        }
        return ['status' => $st, 'label' => array_values(array_unique($label))];
    }
    return null;
}

/**
 * GET ?action=presensi_jam_sebelumnya&class_id=&date=Y-m-d&jam=4-5
 * Terbuka untuk semua pengguna yang login (guru mapel mana pun mengisi jurnal).
 * Hasil: {items: {student_id: {status, sumber: 'JURNAL'|'MANUAL', keterangan}}}
 */
function handlePresensiJamSebelumnya(mysqli $db): void
{
    dispensasiWajibMetode('GET');
    requireAuth();
    $kelas = trim((string)($_GET['class_id'] ?? ''));
    $date  = (string)($_GET['date'] ?? '');
    if ($kelas === '' || !dispensasiTanggalValid($date)) jsonResponse(['items' => new stdClass()]);

    $kunciKelas = normalizeClassKey($kelas);
    $cocokKelas = fn($id, $nama) => normalizeClassKey((string)$id) === $kunciKelas
        || normalizeClassKey((string)$nama) === $kunciKelas;
    $jamIni  = presensiAngkaJam((string)($_GET['jam'] ?? ''));
    $awalIni = $jamIni ? min($jamIni) : null;

    // Jurnal kelas ini yang selesai sebelum jam ini, dikelompokkan per siswa dan jam akhir
    $perSiswa = [];
    if ($awalIni !== null) {
        $stmt = $db->prepare(
            "SELECT classId, className, subjectName, period, jam_ke, studentAttendance
             FROM journal_entries WHERE date = ? AND isDraft = 0"
        );
        $stmt->bind_param("s", $date);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $j) {
            if (!$cocokKelas($j['classId'], $j['className'])) continue;
            $jamKe = trim((string)($j['jam_ke'] ?? ''));
            $angka = presensiAngkaJam($jamKe);
            // Jam jurnal yang tidak terbaca tidak bisa diurutkan, jadi dilewati
            if (!$angka || max($angka) >= $awalIni) continue;
            $akhir = max($angka);
            $label = 'jam ' . $jamKe
                . (trim((string)$j['subjectName']) !== '' ? ' (' . trim((string)$j['subjectName']) . ')' : '');
            foreach (presensiParseJurnal($j['studentAttendance']) as $sid => $status) {
                $perSiswa[$sid][$akhir][] = ['status' => $status, 'label' => $label];
            }
        }
    }

    $items = [];
    foreach ($perSiswa as $sid => $perJam) {
        krsort($perJam);
        $saran = presensiSaranDariJamTerakhir(reset($perJam));
        if ($saran !== null) {
            $items[$sid] = ['status' => $saran['status'], 'sumber' => 'JURNAL', 'keterangan' => implode(', ', $saran['label'])];
        }
    }

    // Keputusan wali kelas (MANUAL) untuk siswa yang belum tercatat di jurnal sebelumnya
    $stmt = $db->prepare(
        "SELECT student_id, class_id, class_name, status, recorder_name FROM attendance_records
         WHERE date = ? AND source = 'MANUAL' AND status IN ('SAKIT', 'IZIN')"
    );
    $stmt->bind_param("s", $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $sid = (string)$r['student_id'];
        if (isset($perSiswa[$sid]) || !$cocokKelas($r['class_id'], $r['class_name'])) continue;
        $pencatat = trim((string)$r['recorder_name']);
        $items[$sid] = [
            'status'     => strtoupper($r['status']),
            'sumber'     => 'MANUAL',
            'keterangan' => 'presensi harian wali kelas' . ($pencatat !== '' ? " ($pencatat)" : ''),
        ];
    }

    // Dispensasi yang mencakup jam ini didahulukan
    if ($items) {
        foreach (presensiDispensasiTanggal($db, $date, array_map('strval', array_keys($items))) as $sid => $daftar) {
            foreach ($daftar as $d) {
                if (presensiDispensasiMencakup($d, $jamIni)) { unset($items[$sid]); break; }
            }
        }
    }

    jsonResponse(['items' => $items ?: new stdClass()]);
}
