<?php
/**
 * Presensi harian siswa (attendance_records) yang diturunkan dari jurnal mengajar.
 *
 * Satu siswa hanya punya satu baris presensi per tanggal, sedangkan jurnal mencatat
 * presensi per jam pelajaran. Status harian dihitung ulang dari SEMUA jurnal non-draf
 * pada tanggal itu, bukan ditimpa oleh jurnal yang terakhir disimpan:
 *   1. baris MANUAL (disimpan wali kelas) tidak pernah diubah;
 *   2. ada minimal satu jam HADIR            -> HADIR;
 *   3. tidak ada HADIR, ada SAKIT            -> SAKIT;
 *   4. tidak ada HADIR/SAKIT, ada IZIN       -> IZIN;
 *   5. seluruh jam ALFA                      -> ALFA.
 * Jam yang tidak diikuti dicatat di kolom notes agar wali kelas tetap melihatnya.
 *
 * Insiden otomatis selalu mengikuti status harian FINAL, termasuk status MANUAL,
 * dan masing-masing paling banyak satu per siswa per hari:
 *   - ALFA       (id inc_att_<siswa>_<tanggal>): final ALFA;
 *   - TINGGAL_JP (id inc_tjp_<siswa>_<tanggal>): final HADIR tetapi minimal satu jam
 *     pelajaran ALFA di jurnal (meninggalkan jam pelajaran tanpa izin).
 * Insiden yang syaratnya tidak lagi terpenuhi dihapus dan poinnya dikembalikan.
 *
 * Parser presensi jurnal (pantauNilaiParseAttendance/pantauNilaiAttendanceStatus)
 * berasal dari api/pantau_nilai.php.
 */
if (!defined('SIMANTAP_API')) exit;

/**
 * Turunkan status harian dari daftar presensi per jam (fungsi murni).
 * $jam: list ['status' => ..., 'label' => 'jam 3 (Matematika)'] berurutan menurut jam.
 * Hasil: ['status' => ..., 'notes' => string|null], atau null bila daftar kosong.
 */
function presensiTurunkanHarian(array $jam): ?array
{
    $statuses = [];
    foreach ($jam as $j) {
        $st = pantauNilaiAttendanceStatus($j['status'] ?? null);
        if ($st !== null) $statuses[] = $st;
    }
    if (empty($statuses)) return null;

    $status = 'ALFA';
    foreach (['HADIR', 'SAKIT', 'IZIN'] as $prioritas) {
        if (in_array($prioritas, $statuses, true)) { $status = $prioritas; break; }
    }

    // Catatan hanya bila status per jam tidak seragam
    $notes = null;
    if (count(array_unique($statuses)) > 1) {
        $bagian = [];
        foreach ($jam as $j) {
            $st = pantauNilaiAttendanceStatus($j['status'] ?? null);
            if ($st === null || $st === 'HADIR') continue;
            $bagian[] = trim((string)($j['label'] ?? '')) . ': ' . $st;
        }
        $notes = 'Dari jurnal, tidak mengikuti ' . implode('; ', $bagian);
    }
    return ['status' => $status, 'notes' => $notes];
}

/**
 * Label jam yang ditandai ALFA di jurnal (fungsi murni), untuk insiden TINGGAL_JP.
 */
function presensiJamAlfa(array $jam): array
{
    $label = [];
    foreach ($jam as $j) {
        if (pantauNilaiAttendanceStatus($j['status'] ?? null) === 'ALFA') {
            $label[] = trim((string)($j['label'] ?? ''));
        }
    }
    return $label;
}

/**
 * Pastikan jenis pelanggaran TINGGAL_JP ada, lalu kembalikan poinnya. Poin dapat diubah
 * sekolah lewat menu jenis pelanggaran; bila dihapus, jenis ini dibuat ulang dengan default.
 */
function presensiPoinTinggalJp(mysqli $db): int
{
    $res = $db->query("SELECT points FROM violation_types WHERE violation_code = 'TINGGAL_JP'");
    $row = $res ? $res->fetch_assoc() : null;
    if ($row) return (int)$row['points'];

    $db->query(
        "INSERT IGNORE INTO violation_types (violation_code, violation_name, points, category, description, auto_parent_call)
         VALUES ('TINGGAL_JP', 'Meninggalkan Jam Pelajaran Tanpa Izin', 10, 'RINGAN',
                 'Otomatis dari jurnal: hadir di sekolah tetapi tidak mengikuti jam pelajaran tanpa keterangan', 0)"
    );
    return 10;
}

/**
 * Konfigurasi yang dipakai sinkronisasi insiden presensi.
 */
function presensiKonfigInsiden(mysqli $db): array
{
    [$academicYear, $semester] = activeAcademicPeriod($db);

    $resThresh = $db->query("SELECT config_value FROM system_config WHERE config_key = 'violation_threshold_parentcall'");
    $threshRow = $resThresh ? $resThresh->fetch_assoc() : null;

    $resAlfa = $db->query("SELECT points FROM violation_types WHERE violation_code = 'ALFA'");
    $alfaRow = $resAlfa ? $resAlfa->fetch_assoc() : null;

    return [
        'academic_year' => $academicYear,
        'semester'      => $semester,
        'threshold'     => (int)($threshRow['config_value'] ?? 75),
        'alfa_points'   => (int)($alfaRow['points'] ?? 15),
        'tjp_points'    => presensiPoinTinggalJp($db),
    ];
}

/**
 * Samakan insiden ALFA harian dengan status harian final.
 * $siswa: ['id', 'name', 'nisn', 'class_name']
 * $opsi : konfigurasi dari presensiKonfigInsiden + 'reporter_id', 'reporter_name',
 *         'details', 'parent_call_status'.
 * Hasil: 'dibuat' | 'dihapus' | null (tidak berubah).
 */
function presensiSinkronInsidenAlfa(mysqli $db, array $siswa, string $date, bool $alfa, array $opsi): ?string
{
    return presensiSinkronInsiden($db, 'inc_att_', 'ALFA', $siswa, $date, $alfa,
        $opsi + ['points' => $opsi['alfa_points']]);
}

/**
 * Samakan satu insiden otomatis harian (id <prefix><siswa>_<tanggal>) dengan syaratnya.
 * $aktif: syarat insiden terpenuhi. $opsi['points'] = poin saat insiden dibuat.
 * Insiden yang sudah ada tidak diubah poinnya; rinciannya diperbarui hanya bila
 * $opsi['perbarui_rincian'] bernilai true.
 * Hasil: 'dibuat' | 'dihapus' | null (tidak berubah/hanya rincian).
 */
function presensiSinkronInsiden(mysqli $db, string $prefix, string $kode, array $siswa, string $date, bool $aktif, array $opsi): ?string
{
    $studentId = (string)$siswa['id'];
    $incId = $prefix . $studentId . '_' . $date;

    $stmt = $db->prepare("SELECT points, details FROM incidents WHERE id = ?");
    $stmt->bind_param("s", $incId);
    $stmt->execute();
    $insiden = $stmt->get_result()->fetch_assoc();

    if (!$aktif) {
        if (!$insiden) return null;
        $stmt = $db->prepare("DELETE FROM incidents WHERE id = ?");
        $stmt->bind_param("s", $incId);
        $stmt->execute();
        // Kurangi poin sebesar yang dulu ditambahkan, bukan poin yang berlaku sekarang
        $poin = (int)($insiden['points'] ?? 0);
        $stmt = $db->prepare("UPDATE students SET total_violation_points = GREATEST(0, total_violation_points - ?) WHERE id = ?");
        $stmt->bind_param("is", $poin, $studentId);
        $stmt->execute();
        return 'dihapus';
    }

    $details = (string)$opsi['details'];
    if ($insiden) {
        if (!empty($opsi['perbarui_rincian']) && (string)$insiden['details'] !== $details) {
            $stmt = $db->prepare("UPDATE incidents SET details = ? WHERE id = ?");
            $stmt->bind_param("ss", $details, $incId);
            $stmt->execute();
        }
        return null;
    }

    $studentName  = (string)($siswa['name'] ?? '');
    $studentNisn  = $siswa['nisn'] ?? null;
    $className    = (string)($siswa['class_name'] ?? '');
    $academicYear = $opsi['academic_year'];
    $semester     = $opsi['semester'];
    $reporterId   = (string)$opsi['reporter_id'];
    $reporterName = (string)$opsi['reporter_name'];
    $points       = (int)$opsi['points'];

    $stmt = $db->prepare(
        "INSERT INTO incidents (id, type, date, studentId, student_nisn, studentName, className, academic_year, semester, reporterId, reporterName, details, violation_code, points, status, parent_call_triggered)
         VALUES (?, 'VIOLATION', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'REPORTED', 0)"
    );
    $stmt->bind_param(
        "ssssssssssssi",
        $incId, $date, $studentId, $studentNisn, $studentName, $className, $academicYear, $semester,
        $reporterId, $reporterName, $details, $kode, $points
    );
    $stmt->execute();

    $stmt = $db->prepare("UPDATE students SET total_violation_points = total_violation_points + ? WHERE id = ?");
    $stmt->bind_param("is", $points, $studentId);
    $stmt->execute();

    $stmt = $db->prepare("SELECT total_violation_points FROM students WHERE id = ?");
    $stmt->bind_param("s", $studentId);
    $stmt->execute();
    $sRow = $stmt->get_result()->fetch_assoc();
    $totalPts = (int)($sRow['total_violation_points'] ?? 0);

    if ($totalPts >= (int)$opsi['threshold']) {
        $callId = 'pcall_' . $studentId . '_' . time();
        $callStatus = (string)$opsi['parent_call_status'];
        $stmt = $db->prepare(
            "INSERT IGNORE INTO parent_calls (id, student_id, student_name, class_name, academic_year, semester, total_points, trigger_type, trigger_incident_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'AUTO', ?, ?)"
        );
        $stmt->bind_param("ssssssiss", $callId, $studentId, $studentName, $className, $academicYear, $semester, $totalPts, $incId, $callStatus);
        $stmt->execute();
    }
    return 'dibuat';
}

/**
 * Hitung ulang presensi harian sejumlah siswa pada satu tanggal dari semua jurnal
 * non-draf, lalu samakan insiden ALFA dan TINGGAL_JP-nya. Dipanggil di dalam transaksi pemanggil.
 * $aktor: user sesi (uid, displayName) yang memicu perhitungan ulang.
 * Hasil: [student_id => ['dari' => status|null, 'ke' => status|null, 'sumber' => ...,
 *         'insiden' => 'dibuat'|'dihapus'|null, 'tinggal_jp' => 'dibuat'|'dihapus'|null]]
 * hanya untuk siswa yang berubah.
 */
function presensiSinkronHarian(mysqli $db, string $date, array $studentIds, array $aktor): array
{
    $studentIds = array_values(array_unique(array_filter(array_map('strval', $studentIds), fn($s) => $s !== '')));
    if (empty($studentIds) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
    $target = array_flip($studentIds);

    // Presensi per jam dari seluruh jurnal non-draf pada tanggal itu
    $stmt = $db->prepare(
        "SELECT classId, className, subjectName, period, jam_ke, studentAttendance
         FROM journal_entries WHERE date = ? AND isDraft = 0"
    );
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $jurnal = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    usort($jurnal, fn($a, $b) =>
        [(int)$a['jam_ke'], (string)$a['period']] <=> [(int)$b['jam_ke'], (string)$b['period']]);

    $jamPerSiswa = [];
    $kelasJurnal = [];
    foreach ($jurnal as $j) {
        $jamKe = trim((string)($j['jam_ke'] ?? ''));
        $label = ($jamKe !== '' ? 'jam ' . $jamKe : trim((string)$j['period']))
            . (trim((string)$j['subjectName']) !== '' ? ' (' . trim((string)$j['subjectName']) . ')' : '');
        foreach (pantauNilaiParseAttendance($j['studentAttendance']) as $sid => $status) {
            if (!isset($target[$sid])) continue;
            $jamPerSiswa[$sid][] = ['status' => $status, 'label' => trim($label)];
            $kelasJurnal[$sid] = ['class_id' => $j['classId'], 'class_name' => $j['className']];
        }
    }

    $ph    = implode(',', array_fill(0, count($studentIds), '?'));
    $types = str_repeat('s', count($studentIds));

    $stmt = $db->prepare("SELECT id, name, nisn, class_id, class_name FROM students WHERE id IN ($ph)");
    $stmt->bind_param($types, ...$studentIds);
    $stmt->execute();
    $siswaMap = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $s) $siswaMap[$s['id']] = $s;

    $stmt = $db->prepare("SELECT id, student_id, student_name, class_name, status, source FROM attendance_records WHERE date = ? AND student_id IN ($ph)");
    $stmt->bind_param('s' . $types, $date, ...$studentIds);
    $stmt->execute();
    $lamaMap = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $lamaMap[$r['student_id']] = $r;

    $konfig = presensiKonfigInsiden($db);
    $opsiInsiden = $konfig + [
        'reporter_id'        => $aktor['uid'] ?? '',
        'reporter_name'      => $aktor['displayName'] ?? '',
        'details'            => "Tidak hadir tanpa keterangan (dari Jurnal $date)",
        'parent_call_status' => 'PENDING',
    ];

    $stmtUpsert = $db->prepare(
        "INSERT INTO attendance_records
            (id, student_id, student_name, class_id, class_name, date, semester, academic_year, status, recorded_by, recorder_name, source, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'JURNAL', ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status), recorded_by = VALUES(recorded_by), recorder_name = VALUES(recorder_name),
            source = 'JURNAL', notes = VALUES(notes)"
    );
    $stmtHapus = $db->prepare("DELETE FROM attendance_records WHERE date = ? AND student_id = ? AND source <> 'MANUAL'");

    $perubahan = [];
    foreach ($studentIds as $sid) {
        $lama   = $lamaMap[$sid] ?? null;
        $siswa  = $siswaMap[$sid] ?? null;
        $kelas  = $kelasJurnal[$sid] ?? null;
        $hasil  = presensiTurunkanHarian($jamPerSiswa[$sid] ?? []);
        $sumber = $lama['source'] ?? null;

        if ($lama && $lama['source'] === 'MANUAL') {
            // Keputusan wali kelas selalu menang; hanya insiden yang disamakan
            $final = $lama['status'];
        } elseif ($hasil === null) {
            // Tidak ada lagi jurnal yang mencatat siswa ini (jurnal dihapus/jadi draf)
            if ($lama) {
                $stmtHapus->bind_param("ss", $date, $sid);
                $stmtHapus->execute();
            }
            $final  = null;
            $sumber = null;
        } else {
            $final        = $hasil['status'];
            $notes        = $hasil['notes'];
            $sumber       = 'JURNAL';
            $rId          = $lama['id'] ?? ('att_' . $sid . '_' . $date);
            $studentName  = $siswa['name'] ?? ($lama['student_name'] ?? 'Unknown Student');
            $classId      = $siswa['class_id'] ?? ($kelas['class_id'] ?? '');
            $className    = $siswa['class_name'] ?? ($kelas['class_name'] ?? '');
            $academicYear = $konfig['academic_year'];
            $semester     = $konfig['semester'];
            $recordedBy   = $opsiInsiden['reporter_id'];
            $recorderName = $opsiInsiden['reporter_name'];
            $stmtUpsert->bind_param(
                "ssssssssssss",
                $rId, $sid, $studentName, $classId, $className, $date, $semester, $academicYear,
                $final, $recordedBy, $recorderName, $notes
            );
            $stmtUpsert->execute();
        }

        $dataSiswa = [
            'id'         => $sid,
            'name'       => $siswa['name'] ?? ($lama['student_name'] ?? 'Unknown Student'),
            'nisn'       => $siswa['nisn'] ?? null,
            'class_name' => $siswa['class_name'] ?? ($kelas['class_name'] ?? ($lama['class_name'] ?? '')),
        ];
        $insiden = presensiSinkronInsidenAlfa($db, $dataSiswa, $date, $final === 'ALFA', $opsiInsiden);

        // Hadir di sekolah (final HADIR, termasuk MANUAL) tetapi ada jam pelajaran ALFA
        $jamAlfa = $final === 'HADIR' ? presensiJamAlfa($jamPerSiswa[$sid] ?? []) : [];
        $tinggalJp = presensiSinkronInsiden($db, 'inc_tjp_', 'TINGGAL_JP', $dataSiswa, $date, !empty($jamAlfa), [
            'points'           => $konfig['tjp_points'],
            'perbarui_rincian' => true,
            'details'          => "Meninggalkan jam pelajaran tanpa izin (dari Jurnal $date): " . implode(', ', $jamAlfa),
        ] + $opsiInsiden);

        $dari = $lama['status'] ?? null;
        if ($dari !== $final || ($lama['source'] ?? null) !== $sumber || $insiden !== null || $tinggalJp !== null) {
            $perubahan[$sid] = ['dari' => $dari, 'ke' => $final, 'sumber' => $sumber, 'insiden' => $insiden, 'tinggal_jp' => $tinggalJp];
        }
    }
    return $perubahan;
}
