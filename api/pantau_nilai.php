<?php
// pantau_nilai.php - Pantau Nilai Kelas untuk Wali Kelas dan Guru BK (hanya baca)
// Spesifikasi: docs/specs/pantau-nilai-wali-kelas.md
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi).
// Memakai helper dari db.php, kesiswaan_access.php, dan rekap_bulanan.php.
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

const PANTAU_NILAI_MSG_DENY = 'Anda tidak memiliki akses ke pemantauan nilai kelas.';

/**
 * Cakupan akses pemantauan nilai (user dibaca ulang dari DB).
 * ADMIN, KEPALA_SEKOLAH, GURU BK -> ALL; GURU wali kelas -> CLASS; lainnya -> DENY.
 * Hasil: ['mode' => 'ALL'|'CLASS'|'DENY', 'classKeys' => string[], 'classId' => ?string]
 */
function pantauNilaiScope(mysqli $db, array $sessionUser): array
{
    $fresh = loadFreshUser($db, $sessionUser);
    $deny  = ['mode' => 'DENY', 'classKeys' => [], 'classId' => null];
    $all   = ['mode' => 'ALL',  'classKeys' => [], 'classId' => null];

    switch ($fresh['role']) {
        case 'ADMIN':
        case 'KEPALA_SEKOLAH':
            return $all;

        case 'GURU':
            if ((int)$fresh['is_bk'] === 1) return $all;
            $homeroom = trim((string)($fresh['class_homeroom'] ?? ''));
            if ($homeroom === '') return $deny;
            return homeroomClassScope($db, $homeroom);

        default:
            return $deny;
    }
}

/**
 * Normalisasi parameter semester: 1/GANJIL -> GANJIL, 2/GENAP -> GENAP, kosong -> semester aktif.
 * Nilai lain -> null (tidak valid).
 */
function pantauNilaiSemester(?string $raw, string $activeSemester): ?string
{
    $s = strtoupper(trim((string)$raw));
    if ($s === '') $s = strtoupper(trim($activeSemester));
    if ($s === '1' || $s === 'GANJIL') return 'GANJIL';
    if ($s === '2' || $s === 'GENAP') return 'GENAP';
    return null;
}

/**
 * Normalisasi status presensi dari data jurnal. Tidak pernah menghentikan request
 * (berbeda dengan normalizeAttendanceStatus yang membalas 400 untuk status asing).
 */
function pantauNilaiAttendanceStatus($status): ?string
{
    if (!is_scalar($status)) return null;
    $s = strtoupper(trim((string)$status));
    if ($s === 'ALPHA' || $s === 'ALPA') $s = 'ALFA';
    return in_array($s, ['HADIR', 'SAKIT', 'IZIN', 'ALFA'], true) ? $s : null;
}

/**
 * Presensi per pertemuan dari journal_entries.studentAttendance.
 * Mendukung map {"<student_id>": "HADIR"} dan array [{studentId|student_id, status}].
 * Hasil: student_id => HADIR|SAKIT|IZIN|ALFA
 */
function pantauNilaiParseAttendance(?string $json): array
{
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    $map = [];
    if (array_is_list($data)) {
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            $sid = $row['studentId'] ?? $row['student_id'] ?? $row['id'] ?? '';
            if (!is_scalar($sid) || (string)$sid === '') continue;
            $st = pantauNilaiAttendanceStatus($row['status'] ?? null);
            if ($st !== null) $map[(string)$sid] = $st;
        }
    } else {
        foreach ($data as $sid => $status) {
            if (is_array($status)) $status = $status['status'] ?? null;
            $st = pantauNilaiAttendanceStatus($status);
            if ($st !== null) $map[(string)$sid] = $st;
        }
    }
    return $map;
}

/**
 * Nilai tugas/kuis dianggap ada bila bukan null dan bukan string kosong (sama dengan get_student_grades).
 */
function pantauNilaiHasScore(array $entry, string $field): bool
{
    return isset($entry[$field]) && $entry[$field] !== null && $entry[$field] !== '';
}

/**
 * Penugasan mapel di satu kelas dari teacher_subjects: subject_code => [teacher_id, ...].
 * Aturan pencocokan sama dengan teacherTeachesClassSubject (db.php).
 */
function classSubjectAssignments(mysqli $db, string $classId): array
{
    $classLevel = null;
    $result = [];
    $res = $db->query("SELECT teacher_id, subject_code, class_level, class_ids FROM teacher_subjects");
    while ($res && ($row = $res->fetch_assoc())) {
        $match = false;
        $ids = json_decode($row['class_ids'] ?? '', true);
        if (is_array($ids) && count($ids) > 0) {
            $match = in_array($classId, $ids, true);
        } else {
            $level = trim((string)($row['class_level'] ?? ''));
            if ($level !== '') {
                if (str_starts_with($classId, $level . '_')) {
                    $match = true;
                } else {
                    if ($classLevel === null) {
                        $stmtC = $db->prepare("SELECT level FROM classes WHERE id = ?");
                        $stmtC->bind_param("s", $classId);
                        $stmtC->execute();
                        $classLevel = (string)($stmtC->get_result()->fetch_assoc()['level'] ?? '');
                    }
                    $match = $classLevel !== '' && strcasecmp($classLevel, $level) === 0;
                }
            }
        }
        if (!$match) continue;
        $code = (string)$row['subject_code'];
        if ($code === '') continue;
        $result[$code] ??= [];
        if (!in_array($row['teacher_id'], $result[$code], true)) $result[$code][] = (string)$row['teacher_id'];
    }
    return $result;
}

/**
 * Siswa aktif kelas (aturan sama dengan getActiveClassStudents), ditambah NISN.
 * student_id => ['name', 'nisn', 'pas_foto'], urut nama.
 */
function pantauNilaiStudents(mysqli $db, string $classId): array
{
    $stmt = $db->prepare("
        SELECT id, name, nisn, IF(JSON_VALID(docs_json), JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')), NULL) AS pas_foto
        FROM students
        WHERE class_id = ? AND (status IS NULL OR status = '' OR UPPER(status) = 'AKTIF')
        ORDER BY name ASC
    ");
    $stmt->bind_param("s", $classId);
    $stmt->execute();
    $res = $stmt->get_result();
    $students = [];
    while ($row = $res->fetch_assoc()) {
        $students[(string)$row['id']] = [
            'name'     => (string)$row['name'],
            'nisn'     => (string)($row['nisn'] ?? ''),
            'pas_foto' => $row['pas_foto'],
        ];
    }
    return $students;
}

/**
 * Bobot dan KKM (query dan default sama dengan get_student_grades).
 */
function pantauNilaiWeights(mysqli $db): array
{
    $weights = ['bobot_tugas' => 30, 'bobot_kuis' => 30, 'bobot_ujian' => 40, 'kkm_default' => 75];
    $res = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('bobot_tugas','bobot_kuis','bobot_ujian','kkm_default')");
    while ($res && ($w = $res->fetch_assoc())) {
        $weights[$w['config_key']] = (int)$w['config_value'];
    }
    return $weights;
}

/**
 * Rekap satu siswa x mapel. Rumus identik dengan get_student_grades, ditambah hitungan tertinggal
 * dan nilai acuan (spesifikasi §4.4).
 */
function pantauNilaiSummary(array $tugas, array $kuis, array $ujian, int $missingTugas, int $missingKuis, array $weights): array
{
    $avgTugas = count($tugas) > 0 ? array_sum($tugas) / count($tugas) : null;
    $avgKuis  = count($kuis)  > 0 ? array_sum($kuis)  / count($kuis)  : null;

    $ujianUTS = $ujian['UTS'] ?? null;
    $ujianUAS = $ujian['UAS'] ?? $ujian['SUMATIF_AKHIR'] ?? null;
    if ($ujianUTS !== null && $ujianUAS !== null) {
        $ujianCombined = ($ujianUTS + $ujianUAS) / 2;
    } elseif ($ujianUAS !== null) {
        $ujianCombined = $ujianUAS;
    } elseif ($ujianUTS !== null) {
        $ujianCombined = $ujianUTS;
    } else {
        $ujianCombined = null;
    }

    $finalScore = null;
    if ($ujianCombined !== null) {
        $wt = $weights['bobot_tugas'] / 100;
        $wk = $weights['bobot_kuis'] / 100;
        $wu = $weights['bobot_ujian'] / 100;
        $finalScore = round((($avgTugas ?? 0) * $wt) + (($avgKuis ?? 0) * $wk) + ($ujianCombined * $wu), 1);
    }

    $kkm = $weights['kkm_default'];
    $avgTugasR = $avgTugas !== null ? round($avgTugas, 1) : null;
    $avgKuisR  = $avgKuis  !== null ? round($avgKuis,  1) : null;

    // Nilai acuan: nilai akhir, atau sementara rata-rata nilai harian yang tersedia
    $refScore = $finalScore;
    $provisional = false;
    if ($refScore === null) {
        $harian = array_values(array_filter([$avgTugas, $avgKuis], fn($v) => $v !== null));
        if ($harian) {
            $refScore = round(array_sum($harian) / count($harian), 1);
            $provisional = true;
        }
    }

    return [
        'avg_tugas'          => $avgTugasR,
        'avg_kuis'           => $avgKuisR,
        'tugas_count'        => count($tugas),
        'kuis_count'         => count($kuis),
        'missing_tugas'      => $missingTugas,
        'missing_kuis'       => $missingKuis,
        'ujian_uts'          => $ujianUTS,
        'ujian_uas'          => $ujianUAS,
        'ujian_combined'     => $ujianCombined !== null ? round($ujianCombined, 1) : null,
        'final_score'        => $finalScore,
        'status'             => $finalScore !== null ? ($finalScore >= $kkm ? 'TUNTAS' : 'REMEDI') : 'BELUM_LENGKAP',
        'ref_score'          => $refScore,
        'ref_is_provisional' => $provisional,
        'below_kkm'          => $refScore !== null && $refScore < $kkm,
    ];
}

/**
 * Inti pengumpulan data nilai satu kelas untuk satu semester TA aktif (dipakai ketiga action).
 * $forceSubject: kode mapel yang selalu dimasukkan ke daftar mapel (untuk detail mapel).
 */
function pantauNilaiCollect(mysqli $db, string $classId, string $academicYear, string $semester, ?string $forceSubject = null): array
{
    $students = pantauNilaiStudents($db, $classId);
    $weights  = pantauNilaiWeights($db);
    $range    = getSemesterDateRange($academicYear, $semester);

    // --- Katalog mapel ---
    $catalog = [];
    $res = $db->query("SELECT id, name, sort_order FROM subjects");
    while ($res && ($row = $res->fetch_assoc())) {
        $catalog[(string)$row['id']] = ['name' => (string)$row['name'], 'sort' => (int)$row['sort_order']];
    }

    $subjects = []; // code => meta
    $ensureSubject = function (string $code) use (&$subjects) {
        if (!isset($subjects[$code])) {
            $subjects[$code] = [
                'journal_name'   => '',
                'teachers'       => [], // uid => name
                'tugas_meetings' => 0,
                'kuis_meetings'  => 0,
                'has_ujian'      => false,
                'meetings'       => [],
            ];
        }
    };

    // --- Penugasan (teacher_subjects) ---
    $assignments = classSubjectAssignments($db, $classId);
    $teacherIds = [];
    foreach ($assignments as $code => $uids) {
        $ensureSubject($code);
        foreach ($uids as $uid) $teacherIds[$uid] = true;
    }
    $teacherNames = [];
    if ($teacherIds) {
        $ids = array_keys($teacherIds);
        $stmt = $db->prepare("SELECT uid, displayName FROM users WHERE uid IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
        $stmt->execute();
        $resU = $stmt->get_result();
        while ($u = $resU->fetch_assoc()) $teacherNames[(string)$u['uid']] = (string)$u['displayName'];
    }
    foreach ($assignments as $code => $uids) {
        foreach ($uids as $uid) {
            if (isset($teacherNames[$uid])) $subjects[$code]['teachers'][$uid] = $teacherNames[$uid];
        }
    }

    // --- Akumulator per siswa x mapel ---
    $acc = []; // sid => code => ['tugas' => [], 'kuis' => [], 'mt' => 0, 'mk' => 0]
    $cells = []; // code => sid => [cell, ...] (sejajar dengan meetings)
    $missing = []; // sid => [butir tertinggal]
    $blankAcc = ['tugas' => [], 'kuis' => [], 'mt' => 0, 'mk' => 0];

    // --- Jurnal ber-penilaian (satu query untuk semua mapel) ---
    $sqlJ = "SELECT id, date, topic, subjectId, subjectName, teacherId, teacherName, assessment_data, studentAttendance
             FROM journal_entries
             WHERE classId = ? AND has_assessment = 1 AND isDraft = 0";
    $paramsJ = [$classId];
    if ($range) {
        $sqlJ .= " AND date BETWEEN ? AND ?";
        $paramsJ[] = $range[0];
        $paramsJ[] = $range[1];
    }
    $sqlJ .= " ORDER BY date ASC, createdAt ASC";
    $stmt = $db->prepare($sqlJ);
    $stmt->bind_param(str_repeat('s', count($paramsJ)), ...$paramsJ);
    $stmt->execute();
    $resJ = $stmt->get_result();

    while ($j = $resJ->fetch_assoc()) {
        $code = (string)$j['subjectId'];
        if ($code === '') continue;
        $ensureSubject($code);
        if (trim((string)$j['subjectName']) !== '') $subjects[$code]['journal_name'] = (string)$j['subjectName'];
        if ((string)$j['teacherId'] !== '') {
            $subjects[$code]['teachers'][(string)$j['teacherId']] = (string)$j['teacherName'] !== ''
                ? (string)$j['teacherName']
                : ($subjects[$code]['teachers'][(string)$j['teacherId']] ?? (string)$j['teacherId']);
        }

        $assess = json_decode((string)$j['assessment_data'], true);
        if (!is_array($assess)) $assess = [];

        // Jenis nilai dianggap diberikan bila minimal satu entri punya nilai
        $hasTugas = false; $hasKuis = false;
        $bySid = []; // sid => entri terakhir (untuk sel detail)
        $sidTugas = []; $sidKuis = [];
        foreach ($assess as $sa) {
            if (!is_array($sa)) continue;
            $sid = is_scalar($sa['student_id'] ?? null) ? (string)$sa['student_id'] : '';
            $t = pantauNilaiHasScore($sa, 'tugas_score');
            $k = pantauNilaiHasScore($sa, 'kuis_score');
            if ($t) $hasTugas = true;
            if ($k) $hasKuis = true;
            if ($sid === '') continue;
            $bySid[$sid] = $sa;
            // Rata-rata dihitung dari semua entri siswa aktif, sama dengan get_student_grades
            if (isset($students[$sid])) {
                $acc[$sid][$code] ??= $blankAcc;
                if ($t) { $acc[$sid][$code]['tugas'][] = (float)$sa['tugas_score']; $sidTugas[$sid] = true; }
                if ($k) { $acc[$sid][$code]['kuis'][]  = (float)$sa['kuis_score'];  $sidKuis[$sid]  = true; }
            }
        }

        $attendance = pantauNilaiParseAttendance($j['studentAttendance']);
        $teacherName = (string)$j['teacherName'];
        $subjects[$code]['meetings'][] = [
            'journal_id'   => (string)$j['id'],
            'date'         => (string)$j['date'],
            'topic'        => (string)($j['topic'] ?? ''),
            'teacher_name' => $teacherName,
            'has_tugas'    => $hasTugas,
            'has_kuis'     => $hasKuis,
        ];
        if ($hasTugas) $subjects[$code]['tugas_meetings']++;
        if ($hasKuis)  $subjects[$code]['kuis_meetings']++;

        foreach ($students as $sid => $st) {
            $sid = (string)$sid;
            $sa = $bySid[$sid] ?? [];
            $missT = $hasTugas && !isset($sidTugas[$sid]);
            $missK = $hasKuis  && !isset($sidKuis[$sid]);
            $att   = $attendance[$sid] ?? null;
            $notes = is_scalar($sa['notes'] ?? null) ? trim((string)$sa['notes']) : '';

            $cells[$code][$sid][] = [
                'journal_id'        => (string)$j['id'],
                'tugas_score'       => pantauNilaiHasScore($sa, 'tugas_score') ? (float)$sa['tugas_score'] : null,
                'kuis_score'        => pantauNilaiHasScore($sa, 'kuis_score')  ? (float)$sa['kuis_score']  : null,
                'missing_tugas'     => $missT,
                'missing_kuis'      => $missK,
                'attendance_status' => $att,
                'notes'             => $notes,
            ];

            if ($missT || $missK) {
                $acc[$sid][$code] ??= $blankAcc;
                foreach (['TUGAS' => $missT, 'KUIS' => $missK] as $type => $flag) {
                    if (!$flag) continue;
                    if ($type === 'TUGAS') $acc[$sid][$code]['mt']++; else $acc[$sid][$code]['mk']++;
                    $missing[$sid][] = [
                        'journal_id'        => (string)$j['id'],
                        'date'              => (string)$j['date'],
                        'subject_id'        => $code,
                        'teacher_name'      => $teacherName,
                        'topic'             => (string)($j['topic'] ?? ''),
                        'type'              => $type,
                        'attendance_status' => $att,
                        'notes'             => $notes,
                    ];
                }
            }
        }
    }

    // --- Nilai ujian ---
    $ujian = []; // sid => code => type => score
    $stmtU = $db->prepare("SELECT student_id, subject_id, ujian_type, score FROM ujian_scores WHERE class_id = ? AND academic_year = ? AND semester = ?");
    $stmtU->bind_param("sss", $classId, $academicYear, $semester);
    $stmtU->execute();
    $resUj = $stmtU->get_result();
    while ($u = $resUj->fetch_assoc()) {
        $code = (string)$u['subject_id'];
        if ($code === '') continue;
        $ensureSubject($code);
        $subjects[$code]['has_ujian'] = true;
        $ujian[(string)$u['student_id']][$code][$u['ujian_type']] = $u['score'] !== null ? (float)$u['score'] : null;
    }

    if ($forceSubject !== null && $forceSubject !== '') $ensureSubject($forceSubject);

    // --- Nama dan urutan mapel ---
    $subjectList = [];
    foreach ($subjects as $code => $meta) {
        $code = (string)$code;
        $name = $catalog[$code]['name'] ?? ($meta['journal_name'] !== '' ? $meta['journal_name'] : $code);
        $teachers = [];
        foreach ($meta['teachers'] as $uid => $tname) $teachers[] = ['uid' => (string)$uid, 'name' => (string)$tname];
        usort($teachers, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $hasData = count($meta['meetings']) > 0 || $meta['has_ujian'];
        $subjectList[$code] = [
            'subject_id'     => $code,
            'subject_name'   => $name,
            'teachers'       => $teachers,
            'tugas_meetings' => $meta['tugas_meetings'],
            'kuis_meetings'  => $meta['kuis_meetings'],
            'has_ujian'      => $meta['has_ujian'],
            'status'         => $hasData ? 'ADA_DATA' : 'BELUM_ADA_PENILAIAN',
            '_sort'          => isset($catalog[$code]) ? $catalog[$code]['sort'] : PHP_INT_MAX,
        ];
    }
    uasort($subjectList, fn($a, $b) => [$a['_sort'], strtolower($a['subject_name'])] <=> [$b['_sort'], strtolower($b['subject_name'])]);
    foreach ($subjectList as &$s) unset($s['_sort']);
    unset($s);

    // --- Rekap per siswa ---
    $studentRows = [];
    foreach ($students as $sid => $st) {
        $sid = (string)$sid;
        $grades = [];
        $totalMissing = 0;
        $belowKkm = 0;
        foreach ($subjectList as $code => $_) {
            $a = $acc[$sid][$code] ?? $blankAcc;
            $g = pantauNilaiSummary($a['tugas'], $a['kuis'], $ujian[$sid][$code] ?? [], $a['mt'], $a['mk'], $weights);
            $grades[$code] = $g;
            $totalMissing += $g['missing_tugas'] + $g['missing_kuis'];
            if ($g['below_kkm']) $belowKkm++;
        }
        $miss = $missing[$sid] ?? [];
        foreach ($miss as &$m) $m['subject_name'] = $subjectList[$m['subject_id']]['subject_name'] ?? $m['subject_id'];
        unset($m);
        usort($miss, fn($a, $b) => [$a['date'], strtolower($a['subject_name'])] <=> [$b['date'], strtolower($b['subject_name'])]);

        $studentRows[$sid] = [
            'student_id'      => $sid,
            'student_name'    => $st['name'],
            'nisn'            => $st['nisn'],
            'pas_foto'        => $st['pas_foto'],
            'total_missing'   => $totalMissing,
            'below_kkm_count' => $belowKkm,
            'needs_attention' => $totalMissing > 0 || $belowKkm > 0,
            'grades'          => $grades,
            'missing'         => $miss,
        ];
    }

    $meetings = [];
    foreach ($subjects as $code => $meta) $meetings[(string)$code] = $meta['meetings'];

    return [
        'weights'  => $weights,
        'kkm'      => $weights['kkm_default'],
        'range'    => $range,
        'subjects' => $subjectList,
        'students' => $studentRows,
        'meetings' => $meetings,
        'cells'    => $cells,
    ];
}

/**
 * Validasi parameter bersama (kelas, semester). $fail(int $status, string $message) menghentikan request.
 * Hasil: ['class' => ['id','name'], 'academic_year', 'semester']
 */
function pantauNilaiContext(mysqli $db, array $scope, callable $fail): array
{
    $rawClass = trim((string)($_GET['class_id'] ?? ''));
    if ($scope['mode'] === 'CLASS') {
        if ($rawClass === '') $rawClass = (string)$scope['classId'];
        elseif (!classInScope($scope, $rawClass)) $fail(403, KESISWAAN_MSG_KELAS);
    } elseif ($rawClass === '') {
        $fail(400, 'Parameter class_id wajib diisi.');
    }
    $classRef = resolveClassRef($db, $rawClass);
    // Wali: kelas hasil resolusi juga harus termasuk cakupan
    if ($scope['mode'] === 'CLASS' && !classInScope($scope, $classRef['id'])) $fail(403, KESISWAAN_MSG_KELAS);

    $period   = getActiveAcademicPeriod($db);
    $semester = pantauNilaiSemester($_GET['semester'] ?? '', $period['semester']);
    if ($semester === null) $fail(400, 'Parameter semester tidak valid (gunakan 1, 2, GANJIL, atau GENAP).');

    return ['class' => $classRef, 'academic_year' => $period['academic_year'], 'semester' => $semester];
}

/**
 * Guard bersama endpoint JSON: GET saja, login, dan scope bukan DENY.
 */
function pantauNilaiJsonGuard(mysqli $db): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        errorResponse('Metode tidak diizinkan', 405);
    }
    $user  = requireAuth();
    $scope = pantauNilaiScope($db, $user);
    if ($scope['mode'] === 'DENY') errorResponse(PANTAU_NILAI_MSG_DENY, 403);
    return $scope;
}

/**
 * Action get_class_grade_overview: rekap nilai semua mapel satu kelas.
 */
function handleGetClassGradeOverview(mysqli $db): void
{
    $scope = pantauNilaiJsonGuard($db);
    $ctx   = pantauNilaiContext($db, $scope, fn(int $s, string $m) => errorResponse($m, $s));

    try {
        $data = pantauNilaiCollect($db, $ctx['class']['id'], $ctx['academic_year'], $ctx['semester']);
    } catch (Throwable $e) {
        error_log('[SIMANTAP] get_class_grade_overview: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        errorResponse('Gagal memuat data nilai kelas', 500);
    }

    $students = array_values($data['students']);
    $summary = [
        'student_count'         => count($students),
        'needs_attention_count' => count(array_filter($students, fn($s) => $s['needs_attention'])),
        'total_missing'         => array_sum(array_column($students, 'total_missing')),
        'subjects_without_data' => count(array_filter($data['subjects'], fn($s) => $s['status'] === 'BELUM_ADA_PENILAIAN')),
    ];

    jsonResponse([
        'class'         => $ctx['class'],
        'academic_year' => $ctx['academic_year'],
        'semester'      => $ctx['semester'],
        'date_range'    => $data['range'],
        'weights'       => $data['weights'],
        'kkm'           => $data['kkm'],
        'subjects'      => array_values($data['subjects']),
        'students'      => $students,
        'summary'       => $summary,
    ]);
}

/**
 * Action get_class_subject_grade_detail: nilai per pertemuan satu mapel di satu kelas.
 */
function handleGetClassSubjectGradeDetail(mysqli $db): void
{
    $scope = pantauNilaiJsonGuard($db);
    $ctx   = pantauNilaiContext($db, $scope, fn(int $s, string $m) => errorResponse($m, $s));

    $subjectId = trim((string)($_GET['subject_id'] ?? ''));
    if ($subjectId === '' || mb_strlen($subjectId, 'UTF-8') > 100) {
        errorResponse('Parameter subject_id wajib diisi (maks. 100 karakter).', 400);
    }

    try {
        $data = pantauNilaiCollect($db, $ctx['class']['id'], $ctx['academic_year'], $ctx['semester'], $subjectId);
    } catch (Throwable $e) {
        error_log('[SIMANTAP] get_class_subject_grade_detail: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        errorResponse('Gagal memuat detail nilai mapel', 500);
    }

    $subject = $data['subjects'][$subjectId];
    $students = [];
    foreach ($data['students'] as $sid => $st) {
        $students[] = [
            'student_id'   => $st['student_id'],
            'student_name' => $st['student_name'],
            'nisn'         => $st['nisn'],
            'cells'        => $data['cells'][$subjectId][$sid] ?? [],
            'summary'      => $st['grades'][$subjectId],
        ];
    }

    jsonResponse([
        'class'         => $ctx['class'],
        'academic_year' => $ctx['academic_year'],
        'semester'      => $ctx['semester'],
        'subject'       => [
            'subject_id'   => $subject['subject_id'],
            'subject_name' => $subject['subject_name'],
            'teachers'     => $subject['teachers'],
            'status'       => $subject['status'],
        ],
        'weights'       => $data['weights'],
        'kkm'           => $data['kkm'],
        'meetings'      => $data['meetings'][$subjectId] ?? [],
        'students'      => $students,
    ]);
}

// ============================================================
// CETAK LAPORAN NILAI PER SISWA (HTML A4 siap cetak)
// ============================================================

function pantauNilaiHtmlError(int $status, string $message): void
{
    rekapSendHeaders($status);
    $extra = $status === 401 ? '<p><a href="../">Buka halaman login</a></p>' : '';
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Laporan Nilai - Error ' . $status . '</title>'
        . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:40px 16px}'
        . '.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px}'
        . 'h1{font-size:18px;margin:0 0 8px}p{margin:8px 0;line-height:1.5}a{color:#047857}</style></head><body>'
        . '<div class="box"><h1>Laporan nilai tidak dapat ditampilkan</h1>'
        . '<p>' . rekapEsc($message) . '</p>' . $extra . '</div></body></html>';
    exit;
}

/**
 * Angka nilai untuk dokumen: null -> "-", desimal koma, tanpa ",0".
 */
function pantauNilaiAngka($value): string
{
    if ($value === null) return '-';
    $v = round((float)$value, 1);
    return floor($v) == $v ? (string)(int)$v : number_format($v, 1, ',', '');
}

function pantauNilaiPresensiLabel(?string $status): string
{
    $labels = ['HADIR' => 'Hadir', 'SAKIT' => 'Sakit', 'IZIN' => 'Izin', 'ALFA' => 'Alfa'];
    return $status !== null ? ($labels[$status] ?? $status) : '-';
}

/**
 * Action cetak_rekap_nilai_siswa.
 */
function handleCetakRekapNilaiSiswa(mysqli $db): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        pantauNilaiHtmlError(405, 'Metode tidak diizinkan. Buka laporan melalui tombol Cetak Laporan Nilai.');
    }

    $sessionUser = getCurrentUser();
    if (!$sessionUser || empty($sessionUser['uid'])) {
        pantauNilaiHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
    }

    try {
        // Cek user masih ada (agar 401 tetap berupa HTML, bukan JSON dari loadFreshUser)
        $meRows = rekapFetchAll($db, "SELECT uid, displayName, nip FROM users WHERE uid = ? LIMIT 1", 's', [$sessionUser['uid']]);
        if (!$meRows) pantauNilaiHtmlError(401, 'Sesi Anda telah berakhir. Silakan login ulang.');
        $me = $meRows[0];

        $scope = pantauNilaiScope($db, $sessionUser);
        if ($scope['mode'] === 'DENY') pantauNilaiHtmlError(403, PANTAU_NILAI_MSG_DENY);

        $studentId = trim((string)($_GET['student_id'] ?? ''));
        if ($studentId === '') pantauNilaiHtmlError(400, 'Parameter student_id wajib diisi.');

        $period   = getActiveAcademicPeriod($db);
        $semester = pantauNilaiSemester($_GET['semester'] ?? '', $period['semester']);
        if ($semester === null) pantauNilaiHtmlError(400, 'Parameter semester tidak valid (gunakan 1, 2, GANJIL, atau GENAP).');
        $academicYear = $period['academic_year'];

        $now = new DateTimeImmutable('now');
        $tanggalRaw = trim((string)($_GET['tanggal'] ?? ''));
        $tanggal = $now->format('Y-m-d');
        if ($tanggalRaw !== '') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggalRaw, $tm) || !checkdate((int)$tm[2], (int)$tm[3], (int)$tm[1])) {
                pantauNilaiHtmlError(400, 'Tanggal tanda tangan tidak valid (gunakan YYYY-MM-DD).');
            }
            $tanggal = $tanggalRaw;
        }

        // --- Siswa: ada -> dalam cakupan -> aktif ---
        $stRows = rekapFetchAll($db, "SELECT id, name, nisn, class_id, class_name, status FROM students WHERE id = ? LIMIT 1", 's', [$studentId]);
        if (!$stRows) pantauNilaiHtmlError(404, 'Siswa tidak ditemukan atau tidak aktif.');
        $student = $stRows[0];
        if (!classInScope($scope, $student['class_id']) && !classInScope($scope, $student['class_name'])) {
            pantauNilaiHtmlError(403, KESISWAAN_MSG_KELAS);
        }
        $status = trim((string)($student['status'] ?? ''));
        if ($status !== '' && strtoupper($status) !== 'AKTIF') {
            pantauNilaiHtmlError(404, 'Siswa tidak ditemukan atau tidak aktif.');
        }

        $classId  = (string)$student['class_id'];
        $classRef = resolveClassRef($db, $classId);
        $classKeys = array_values(array_unique(array_filter([
            normalizeClassKey($classId), normalizeClassKey($classRef['id']), normalizeClassKey($classRef['name']),
        ], fn($k) => $k !== '')));

        $data = pantauNilaiCollect($db, $classId, $academicYear, $semester);
        $row  = $data['students'][(string)$student['id']] ?? null;
        if ($row === null) pantauNilaiHtmlError(404, 'Siswa tidak ditemukan atau tidak aktif.');

        // --- Presensi semester (filter tanggal, bukan kolom semester/academic_year) ---
        $range = $data['range'];
        $sqlA = "SELECT status, COUNT(*) AS c FROM attendance_records WHERE student_id = ?";
        $typesA = 's'; $paramsA = [(string)$student['id']];
        if ($range) { $sqlA .= " AND date BETWEEN ? AND ?"; $typesA .= 'ss'; $paramsA[] = $range[0]; $paramsA[] = $range[1]; }
        $sqlA .= " GROUP BY status";
        $presensi = ['HADIR' => 0, 'SAKIT' => 0, 'IZIN' => 0, 'ALFA' => 0];
        foreach (rekapFetchAll($db, $sqlA, $typesA, $paramsA) as $a) {
            $st = pantauNilaiAttendanceStatus($a['status']);
            if ($st !== null) $presensi[$st] += (int)$a['c'];
        }

        // --- Wali kelas: pencetak sendiri bila wali, selain itu wali kelas terdaftar ---
        $homeroom = null;
        if ($scope['mode'] === 'CLASS') {
            $homeroom = ['name' => (string)$me['displayName'], 'nip' => trim((string)$me['nip'])];
        } else {
            $teachers = rekapFetchAll($db,
                "SELECT displayName, nip, class_homeroom FROM users
                 WHERE class_homeroom IS NOT NULL AND class_homeroom <> '' ORDER BY displayName ASC", '', []);
            foreach ($teachers as $t) {
                if (in_array(normalizeClassKey($t['class_homeroom']), $classKeys, true)) {
                    $homeroom = ['name' => (string)$t['displayName'], 'nip' => trim((string)$t['nip'])];
                    break;
                }
            }
        }

        $kop = rekapLoadKop($db);
        $kotaRaw = $_GET['kota'] ?? null;
        $kota = $kotaRaw === null ? ($kop['kop_kota'] ?? '') : trim((string)$kotaRaw);
        $kota = mb_substr($kota, 0, 50, 'UTF-8');

        pantauNilaiRender([
            'kop'           => $kop,
            'student'       => $student,
            'class'         => $classRef,
            'academic_year' => $academicYear,
            'semester'      => $semester,
            'row'           => $row,
            'subjects'      => $data['subjects'],
            'weights'       => $data['weights'],
            'kkm'           => $data['kkm'],
            'presensi'      => $presensi,
            'homeroom'      => $homeroom,
            'kota'          => $kota,
            'tanggal'       => $tanggal,
            'tanggal_input' => $tanggalRaw,
            'today'         => $now->format('Y-m-d'),
            'printed_by'    => (string)$me['displayName'],
            'printed_at'    => $now,
        ]);
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        error_log('[SIMANTAP] cetak_rekap_nilai_siswa: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        pantauNilaiHtmlError(500, 'Gagal menyusun laporan nilai. Coba lagi atau hubungi admin.');
    }
}

function pantauNilaiRender(array $ctx): void
{
    $kop      = $ctx['kop'];
    $student  = $ctx['student'];
    $class    = $ctx['class'];
    $row      = $ctx['row'];
    $weights  = $ctx['weights'];
    $presensi = $ctx['presensi'];
    $homeroom = $ctx['homeroom'];
    $e        = 'rekapEsc';
    $n        = 'pantauNilaiAngka';

    $className = $class['name'] !== '' ? $class['name'] : $class['id'];
    $semLabel  = $ctx['semester'] === 'GENAP' ? 'Genap' : 'Ganjil';
    $fileTitle = preg_replace('/[^A-Za-z0-9_-]/', '_', 'Laporan_Nilai_' . $student['name'] . '_' . $className . '_' . $ctx['semester']);

    $logoKiri  = rekapAssetUrl($kop['kop_logo_kiri'] ?? '');
    $logoKanan = rekapAssetUrl($kop['kop_logo_kanan'] ?? '');
    $kontak = [];
    if (($kop['kop_telp'] ?? '') !== '')    $kontak[] = 'Telp. ' . $kop['kop_telp'];
    if (($kop['kop_email'] ?? '') !== '')   $kontak[] = 'Email: ' . $kop['kop_email'];
    if (($kop['kop_website'] ?? '') !== '') $kontak[] = 'Website: ' . $kop['kop_website'];
    $kopKosong = ($kop['kop_sekolah'] ?? '') === '' && ($kop['kop_instansi'] ?? '') === '' && ($kop['kop_dinas'] ?? '') === '';
    $dots = '(.......................................)';
    $nihil = fn(int $cols) => '<tr><td colspan="' . $cols . '" class="c nihil">Nihil</td></tr>';

    $keterangan = function (array $subject, array $g): string {
        if ($subject['status'] === 'BELUM_ADA_PENILAIAN') return 'Belum ada penilaian';
        $label = ['TUNTAS' => 'Tuntas', 'REMEDI' => 'Remedi', 'BELUM_LENGKAP' => 'Belum lengkap'][$g['status']] ?? $g['status'];
        if ($g['status'] === 'BELUM_LENGKAP' && $g['ref_score'] !== null) {
            $label .= $g['below_kkm'] ? ' (sementara di bawah KKM)' : ' (sementara)';
        }
        return $label;
    };

    $totalPresensi = array_sum($presensi);

    rekapSendHeaders(200);
    ob_start();
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($fileTitle) ?></title>
<style>
  @page { size: A4 portrait; margin: 15mm 15mm 15mm 20mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e2e8f0; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; }
  .panel { max-width: 210mm; margin: 16px auto 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 16px; font-size: 13px; color: #0f172a; }
  .panel h2 { margin: 0 0 10px; font-size: 16px; }
  .panel form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
  .panel label { display: flex; flex-direction: column; gap: 4px; font-weight: bold; font-size: 12px; }
  .panel input { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 0; }
  .panel .btn { padding: 8px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-weight: bold; cursor: pointer; font-size: 13px; }
  .panel .btn-primary { background: #059669; border-color: #059669; color: #fff; }
  .panel .hint { margin: 10px 0 0; color: #475569; line-height: 1.5; }
  .panel .notice { margin: 8px 0 0; padding: 8px 10px; border-radius: 8px; background: #fef3c7; color: #78350f; }
  .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 15mm 15mm 15mm 20mm; background: #fff; box-shadow: 0 4px 16px rgba(15, 23, 42, .15); }
  .kop { width: 100%; border-collapse: collapse; }
  .kop td { border: 0; padding: 0; vertical-align: middle; }
  .kop .logo { width: 24mm; text-align: center; }
  .kop .logo img { max-width: 22mm; max-height: 24mm; }
  .kop .teks { text-align: center; line-height: 1.25; }
  .kop .instansi, .kop .dinas { font-size: 12pt; font-weight: bold; text-transform: uppercase; }
  .kop .sekolah { font-size: 15pt; font-weight: bold; text-transform: uppercase; }
  .kop .alamat { font-size: 9pt; }
  .kop-line { border-top: 3px solid #000; border-bottom: 1px solid #000; height: 4px; margin: 6px 0 12px; }
  h1 { font-size: 11.5pt; text-align: center; margin: 0 0 10px; text-transform: uppercase; }
  h3 { font-size: 10pt; margin: 14px 0 6px; break-after: avoid; page-break-after: avoid; }
  .ident { border-collapse: collapse; margin-bottom: 4px; }
  .ident td { border: 0; padding: 1px 6px 1px 0; font-size: 10pt; vertical-align: top; }
  .tbl-wrap { overflow-x: auto; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th, table.data td { border: 1px solid #000; padding: 2px 4px; font-size: 8.5pt; vertical-align: top; }
  table.data th { background: #e5e7eb; text-align: center; vertical-align: middle; }
  table.data .c { text-align: center; }
  table.data .nihil { font-style: italic; }
  table.data .kurang { font-weight: bold; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; break-inside: avoid; }
  .note { font-size: 8pt; margin: 4px 0 0; }
  .ttd { width: 100%; margin-top: 18px; border-collapse: collapse; page-break-inside: avoid; break-inside: avoid; }
  .ttd td { border: 0; width: 50%; text-align: center; vertical-align: top; font-size: 10pt; padding: 0 8px; }
  .ttd .space { height: 20mm; }
  .ttd .nama { font-weight: bold; text-decoration: underline; }
  .footer { margin-top: 14px; font-size: 7.5pt; color: #444; }
  @media screen and (max-width: 840px) {
    .panel { margin: 12px 16px 0; }
    .sheet { width: auto; min-height: 0; margin: 12px 16px; padding: 16px; }
  }
  @media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    .tbl-wrap { overflow: visible; }
    table.data th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>
<div class="panel no-print">
  <h2>Cetak Laporan Nilai — <?= $e($student['name']) ?> (<?= $e($className) ?>)</h2>
  <form method="get" action="index.php">
    <input type="hidden" name="action" value="cetak_rekap_nilai_siswa">
    <input type="hidden" name="student_id" value="<?= $e($student['id']) ?>">
    <input type="hidden" name="semester" value="<?= $e($ctx['semester']) ?>">
    <label>Kota
      <input type="text" name="kota" maxlength="50" value="<?= $e($ctx['kota']) ?>" placeholder="mis. Banjarbaru">
    </label>
    <label>Tanggal tanda tangan
      <input type="date" name="tanggal" value="<?= $e($ctx['tanggal_input']) ?>">
    </label>
    <button type="submit" class="btn">Terapkan</button>
    <button type="button" class="btn btn-primary" onclick="window.print()">Cetak / Simpan PDF</button>
  </form>
  <p class="hint">Pada dialog cetak, pilih tujuan <b>Simpan sebagai PDF</b>, ukuran kertas <b>A4</b>, dan matikan opsi <b>Header dan footer</b>.
    Kosongkan tanggal untuk memakai tanggal hari ini (<?= $e(rekapTanggalIndo($ctx['today'])) ?>).</p>
  <?php if ($kopKosong): ?>
    <p class="notice">Kop sekolah belum diatur. Minta Admin mengisi Pengaturan Kop Surat.</p>
  <?php endif; ?>
</div>

<div class="sheet">
  <table class="kop">
    <tr>
      <td class="logo"><?php if ($logoKiri !== ''): ?><img src="<?= $e($logoKiri) ?>" alt="" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
      <td class="teks">
        <?php if (($kop['kop_instansi'] ?? '') !== ''): ?><div class="instansi"><?= $e($kop['kop_instansi']) ?></div><?php endif; ?>
        <?php if (($kop['kop_dinas'] ?? '') !== ''): ?><div class="dinas"><?= $e($kop['kop_dinas']) ?></div><?php endif; ?>
        <?php if (($kop['kop_sekolah'] ?? '') !== ''): ?><div class="sekolah"><?= $e($kop['kop_sekolah']) ?></div><?php endif; ?>
        <?php if (($kop['kop_alamat'] ?? '') !== ''): ?><div class="alamat"><?= $e($kop['kop_alamat']) ?></div><?php endif; ?>
        <?php if ($kontak): ?><div class="alamat"><?= $e(implode(' · ', $kontak)) ?></div><?php endif; ?>
      </td>
      <td class="logo"><?php if ($logoKanan !== ''): ?><img src="<?= $e($logoKanan) ?>" alt="" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
    </tr>
  </table>
  <div class="kop-line"></div>

  <h1>Laporan Perkembangan Nilai Peserta Didik</h1>
  <table class="ident">
    <tr><td>Nama</td><td>:</td><td><?= $e($student['name']) ?></td></tr>
    <tr><td>NISN</td><td>:</td><td><?= $e(trim((string)$student['nisn']) !== '' ? $student['nisn'] : '-') ?></td></tr>
    <tr><td>Kelas</td><td>:</td><td><?= $e($className) ?></td></tr>
    <tr><td>Wali Kelas</td><td>:</td><td><?= $e($homeroom['name'] ?? '-') ?></td></tr>
    <tr><td>Tahun Ajaran / Semester</td><td>:</td><td><?= $e($ctx['academic_year'] . ' / ' . $semLabel) ?></td></tr>
    <tr><td colspan="3"><i>Data s.d. tanggal <?= $e(rekapTanggalIndo($ctx['today'])) ?></i></td></tr>
  </table>

  <h3>A. Nilai per Mata Pelajaran</h3>
  <div class="tbl-wrap">
  <table class="data">
    <thead>
      <tr>
        <th style="width:6mm">No</th>
        <th>Mata Pelajaran</th>
        <th style="width:30mm">Guru</th>
        <th style="width:14mm">Rata-rata Tugas (n)</th>
        <th style="width:14mm">Rata-rata Kuis (n)</th>
        <th style="width:9mm">UTS</th>
        <th style="width:9mm">UAS</th>
        <th style="width:11mm">Nilai Akhir</th>
        <th style="width:9mm">KKM</th>
        <th style="width:26mm">Keterangan</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$ctx['subjects']): ?>
        <?= $nihil(10) ?>
      <?php else: $no = 0; foreach ($ctx['subjects'] as $code => $subject): $g = $row['grades'][$code]; $no++; ?>
        <tr>
          <td class="c"><?= $no ?></td>
          <td><?= $e($subject['subject_name']) ?></td>
          <td><?= $e($subject['teachers'] ? implode(', ', array_column($subject['teachers'], 'name')) : '-') ?></td>
          <td class="c"><?= $g['avg_tugas'] === null ? '-' : $e($n($g['avg_tugas']) . ' (' . $g['tugas_count'] . ')') ?></td>
          <td class="c"><?= $g['avg_kuis'] === null ? '-' : $e($n($g['avg_kuis']) . ' (' . $g['kuis_count'] . ')') ?></td>
          <td class="c"><?= $e($n($g['ujian_uts'])) ?></td>
          <td class="c"><?= $e($n($g['ujian_uas'])) ?></td>
          <td class="c<?= $g['below_kkm'] && $g['final_score'] !== null ? ' kurang' : '' ?>"><?= $e($n($g['final_score'])) ?></td>
          <td class="c"><?= $e($n($ctx['kkm'])) ?></td>
          <td><?= $e($keterangan($subject, $g)) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <p class="note">Nilai akhir = Tugas <?= (int)$weights['bobot_tugas'] ?>% + Kuis <?= (int)$weights['bobot_kuis'] ?>% + Ujian <?= (int)$weights['bobot_ujian'] ?>%; dihitung bila nilai ujian sudah ada. Nilai ujian = rata-rata UTS dan UAS bila keduanya ada.
    "Sementara" = belum ada nilai ujian, dinilai dari rata-rata tugas dan kuis. (n) = jumlah nilai.</p>

  <h3>B. Tugas/Kuis yang Belum Ada Nilainya</h3>
  <div class="tbl-wrap">
  <table class="data">
    <thead><tr><th style="width:8mm">No</th><th style="width:18mm">Tanggal</th><th style="width:32mm">Mata Pelajaran</th><th>Topik</th><th style="width:11mm">Jenis</th><th style="width:13mm">Presensi</th><th style="width:40mm">Catatan Guru</th></tr></thead>
    <tbody>
      <?php if (!$row['missing']): ?><?= $nihil(7) ?><?php else: foreach ($row['missing'] as $i => $m): ?>
        <tr>
          <td class="c"><?= $i + 1 ?></td>
          <td class="c"><?= $e(rekapTanggalPendek($m['date'])) ?></td>
          <td><?= $e($m['subject_name']) ?></td>
          <td><?= $e(rekapTruncate($m['topic'])) ?></td>
          <td class="c"><?= $m['type'] === 'TUGAS' ? 'Tugas' : 'Kuis' ?></td>
          <td class="c"><?= $e(pantauNilaiPresensiLabel($m['attendance_status'])) ?></td>
          <td><?= $e(rekapTruncate($m['notes'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <p class="note">Dihitung pada pertemuan yang sudah dinilai guru untuk minimal satu siswa, tetapi siswa ini belum memiliki nilai.</p>

  <h3>C. Ringkasan Presensi Semester</h3>
  <table class="data" style="width:auto">
    <thead><tr><th style="width:18mm">Hadir</th><th style="width:18mm">Sakit</th><th style="width:18mm">Izin</th><th style="width:18mm">Alfa</th><th style="width:22mm">% Hadir</th></tr></thead>
    <tbody>
      <tr>
        <td class="c"><?= $presensi['HADIR'] ?></td>
        <td class="c"><?= $presensi['SAKIT'] ?></td>
        <td class="c"><?= $presensi['IZIN'] ?></td>
        <td class="c"><?= $presensi['ALFA'] ?></td>
        <td class="c"><?= rekapPercent($presensi['HADIR'], $totalPresensi) ?></td>
      </tr>
    </tbody>
  </table>

  <table class="ttd">
    <tr>
      <td>&nbsp;</td>
      <td><?= $e(($ctx['kota'] !== '' ? $ctx['kota'] . ', ' : '') . rekapTanggalIndo($ctx['tanggal'])) ?></td>
    </tr>
    <tr>
      <td>Mengetahui,<br>Orang Tua/Wali</td>
      <td><br>Wali Kelas <?= $e($className) ?></td>
    </tr>
    <tr><td class="space"></td><td class="space"></td></tr>
    <tr>
      <td><?= $dots ?></td>
      <td><?= !empty($homeroom['name']) ? '<span class="nama">' . $e($homeroom['name']) . '</span>' : $dots ?>
        <?php if (!empty($homeroom['nip'])): ?><br>NIP. <?= $e($homeroom['nip']) ?><?php endif; ?></td>
    </tr>
  </table>

  <p class="footer">Dicetak dari SIMANTAP pada <?= $e(rekapTanggalIndo($ctx['printed_at']->format('Y-m-d')) . ' ' . $ctx['printed_at']->format('H:i') . ' ' . rekapTimezoneLabel()) ?> oleh <?= $e($ctx['printed_by']) ?>.</p>
</div>
</body>
</html>
<?php
    echo ob_get_clean();
}
