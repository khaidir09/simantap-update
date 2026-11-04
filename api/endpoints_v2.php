<?php
// endpoints_v2.php - Extended API routes for SIMANTAP
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

$action = $_GET['action'] ?? '';

switch ($action) {
    // --- SUBJECTS ---
    case 'get_subjects':
        $res = $db->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        $subjects = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $subjects[] = $row;
            }
        }
        jsonResponse($subjects);
        break;

    // --- STUDENTS ---
    case 'get_students':
        $classId = $_GET['classId'] ?? '';
        $year = $_GET['year'] ?? '';
        
        $sql = "SELECT * FROM students WHERE status = 'AKTIF'";
        $params = [];
        $types = "";
        
        if (!empty($classId)) {
            $sql .= " AND class_id = ?";
            $params[] = $classId;
            $types .= "s";
        }
        if (!empty($year)) {
            $sql .= " AND academic_year = ?";
            $params[] = $year;
            $types .= "s";
        }
        $sql .= " ORDER BY class_name ASC, name ASC";
        
        $stmt = $db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        
        $students = [];
        while ($row = $res->fetch_assoc()) {
            $row['total_violation_points'] = (int)$row['total_violation_points'];
            $students[] = $row;
        }
        jsonResponse($students);
        break;

    case 'save_student':
        requireRole(['ADMIN', 'KEPALA_SEKOLAH', 'TATA_USAHA']);
        $body = getRequestBody();
        $id = $body['id'] ?? uniqid('std_');
        $nisn = $body['nisn'] ?? null;
        $name = trim($body['name'] ?? '');
        $classId = $body['class_id'] ?? '';
        $className = $body['class_name'] ?? '';
        $year = trim((string)($body['academic_year'] ?? '')) ?: getActiveAcademicPeriod($db)['academic_year'];
        $enrollment_year = $body['enrollment_year'] ?? null;
        $status = $body['status'] ?? 'AKTIF';
        $parentName = $body['parent_name'] ?? null;
        $parentPhone = $body['parent_phone'] ?? null;
        
        if (empty($name) || empty($classId)) {
            errorResponse('Nama dan Kelas wajib diisi');
        }
        
        $stmt = $db->prepare("INSERT INTO students (id, nisn, name, class_id, class_name, academic_year, enrollment_year, status, parent_name, parent_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nisn=VALUES(nisn), name=VALUES(name), class_id=VALUES(class_id), class_name=VALUES(class_name), academic_year=VALUES(academic_year), enrollment_year=VALUES(enrollment_year), status=VALUES(status), parent_name=VALUES(parent_name), parent_phone=VALUES(parent_phone)");
        $stmt->bind_param("ssssssssss", $id, $nisn, $name, $classId, $className, $year, $enrollment_year, $status, $parentName, $parentPhone);
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            errorResponse('Gagal menyimpan siswa');
        }
        break;

    case 'delete_student':
        requireRole(['ADMIN', 'TATA_USAHA']);
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM students WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus siswa');
        break;

    case 'import_students':
        requireRole(['ADMIN', 'TATA_USAHA']);
        $body = getRequestBody();
        $students = $body['students'] ?? [];
        $year = trim((string)($body['academic_year'] ?? '')) ?: getActiveAcademicPeriod($db)['academic_year'];

        if (empty($students)) errorResponse('Data siswa kosong');

        // Validasi format tahun ajaran
        if (!preg_match('/^\d{4}\/\d{4}$/', $year)) {
            errorResponse('Format tahun ajaran tidak valid. Gunakan format YYYY/YYYY');
        }

        $db->begin_transaction();
        try {
            // Sertakan enrollment_year agar data tahun masuk tersimpan
            $stmt = $db->prepare(
                "INSERT INTO students (id, nisn, name, class_id, class_name, academic_year, enrollment_year)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   name=VALUES(name), class_id=VALUES(class_id), class_name=VALUES(class_name),
                   academic_year=VALUES(academic_year), enrollment_year=VALUES(enrollment_year)"
            );

            foreach ($students as $s) {
                $id = !empty($s['nisn']) ? 'std_' . $s['nisn'] : uniqid('std_');
                $nisn = $s['nisn'] ?? null;
                $name = $s['name'] ?? '';
                $className = $s['kelas'] ?? 'Umum';
                $classId = str_replace(' ', '_', strtoupper($className));

                // Ambil tahun pertama dari academic_year sebagai enrollment_year (misal 2025/2026 -> 2025)
                $enrollYear = explode('/', $year)[0];

                $stmt->bind_param("sssssss", $id, $nisn, $name, $classId, $className, $year, $enrollYear);
                $stmt->execute();
            }
            $db->commit();
            jsonResponse(['success' => true, 'count' => count($students)]);
        } catch (Exception $e) {
            $db->rollback();
            errorResponse('Import gagal: ' . $e->getMessage());
        }
        break;

    // --- VIOLATION TYPES ---
    case 'get_violation_types':
        $res = $db->query("SELECT * FROM violation_types ORDER BY category DESC, points DESC");
        $types = [];
        while ($row = $res->fetch_assoc()) {
            $row['points'] = (int)$row['points'];
            $row['auto_parent_call'] = (bool)$row['auto_parent_call'];
            $types[] = $row;
        }
        jsonResponse($types);
        break;
        
    case 'save_violation_type':
        requireRole(['ADMIN']);
        $body = getRequestBody();
        $code = $body['violation_code'] ?? '';
        $name = $body['violation_name'] ?? '';
        $points = (int)($body['points'] ?? 10);
        $category = $body['category'] ?? 'RINGAN';
        $desc = $body['description'] ?? '';
        $auto = (int)($body['auto_parent_call'] ?? 0);
        
        if (empty($code) || empty($name)) errorResponse('Kode dan Nama wajib diisi');
        
        $stmt = $db->prepare("INSERT INTO violation_types (violation_code, violation_name, points, category, description, auto_parent_call) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE violation_name=VALUES(violation_name), points=VALUES(points), category=VALUES(category), description=VALUES(description), auto_parent_call=VALUES(auto_parent_call)");
        $stmt->bind_param("ssissi", $code, $name, $points, $category, $desc, $auto);
        
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menyimpan jenis pelanggaran');
        break;

    case 'delete_violation_type':
        requireRole(['ADMIN']);
        $code = $_GET['code'] ?? '';
        $stmt = $db->prepare("DELETE FROM violation_types WHERE violation_code = ?");
        $stmt->bind_param("s", $code);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus');
        break;

    // --- CONFIG & SYSTEM ---
    case 'get_config':
        vjPastikanMigrasi($db);
        $res = $db->query("SELECT * FROM system_config");
        $config = [];
        while ($row = $res->fetch_assoc()) {
            $config[$row['config_key']] = $row['config_value'];
        }
        // Jenjang efektif (pengaturan atau deteksi dari nama sekolah) — lihat jenjang_sekolah.php
        $jenjang = jenjangAktif($db);
        $config['school_level'] = $jenjang['jenjang'];
        $config['school_level_sumber'] = $jenjang['sumber'];
        // Mode verifikasi jurnal efektif (verifikasi_jurnal.php)
        $config[VJ_KEY_MODE] = vjMode($db);
        jsonResponse($config);
        break;

    case 'save_config':
        requireRole(['ADMIN']);
        $body = getRequestBody();
        $key = $body['config_key'] ?? '';
        $val = $body['config_value'] ?? '';
        
        if(empty($key)) errorResponse('Key required');
        // Mode verifikasi jurnal hanya lewat save_system_config (validasi + konfirmasi + migrasi)
        if (in_array($key, [VJ_KEY_MODE, VJ_KEY_MIGRASI], true)) {
            errorResponse('Gunakan Pengaturan Sistem untuk mengubah mode verifikasi jurnal.');
        }

        $stmt = $db->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
        $stmt->bind_param("ss", $val, $key);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Failed');
        break;
        
    // --- PARENT CALLS ---
    case 'save_parent_call':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? uniqid('pcall_');
        $studentId = $body['student_id'] ?? '';
        $studentName = $body['student_name'] ?? '';
        $status = $body['status'] ?? 'PARENT_INVITED';
        $notes = $body['notes'] ?? '';
        $handledBy = $user['uid'];
        $handlerName = $user['displayName'];
        
        // This is usually an update to existing call status
        $stmt = $db->prepare("UPDATE parent_calls SET status = ?, notes = ?, handled_by = ?, handler_name = ? WHERE id = ?");
        $stmt->bind_param("sssss", $status, $notes, $handledBy, $handlerName, $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal update');
        break;
        

    // --- ATTENDANCE RECORDS ---
    case 'get_attendance_records':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'attendance_read');
        $classId  = $_GET['classId']  ?? '';
        $date     = $_GET['date']     ?? date('Y-m-d');
        if (!classInScope($scope, $classId)) errorResponse(KESISWAAN_MSG_KELAS, 403);

        // Parameter year/semester sengaja diabaikan: id record sudah unik per siswa per tanggal,
        // sedangkan frontend masih mengirim tahun ajaran hardcoded.
        $sql = "SELECT ar.*, s.nisn, s.parent_name, s.parent_phone, JSON_UNQUOTE(JSON_EXTRACT(s.docs_json, '$.pas_foto')) AS pas_foto
                FROM attendance_records ar
                LEFT JOIN students s ON s.id = ar.student_id
                WHERE ar.class_id = ? AND ar.date = ?
                ORDER BY ar.student_name ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $classId, $date);
        $stmt->execute();
        $res = $stmt->get_result();
        $records = [];
        while ($row = $res->fetch_assoc()) {
            // Frontend memakai ALPHA, DB menyimpan ALFA
            if ($row['status'] === 'ALFA') $row['status'] = 'ALPHA';
            $records[] = $row;
        }
        jsonResponse($records);
        break;

    case 'save_attendance_records':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'attendance_write');
        $body  = getRequestBody();
        $records = $body['records'] ?? [];
        // Tahun ajaran & semester selalu dari system_config, nilai body diabaikan
        [$academicYear, $semester] = activeAcademicPeriod($db);

        if (empty($records) || !is_array($records)) errorResponse('Data presensi kosong');

        // Validasi seluruh record sebelum transaksi: satu pelanggaran membatalkan semuanya
        foreach ($records as $i => $r) {
            if (!is_array($r) || empty($r['student_id']) || empty($r['date'])) {
                errorResponse('Data presensi tidak lengkap (student_id dan date wajib diisi)');
            }
            if ($scope['mode'] === 'CLASS'
                && (!classInScope($scope, $r['class_id'] ?? '') || !studentInScope($db, $scope, $r['student_id']))) {
                errorResponse(KESISWAAN_MSG_KELAS, 403);
            }
            $records[$i]['status'] = normalizeAttendanceStatus($r['status'] ?? 'HADIR');
        }

        $opsiInsiden = presensiKonfigInsiden($db) + [
            'reporter_id'        => $user['uid'],
            'reporter_name'      => $user['displayName'],
            'parent_call_status' => 'PARENT_INVITED',
        ];

        $db->begin_transaction();
        try {
            $stmtUpsert = $db->prepare(
                "INSERT INTO attendance_records
                    (id, student_id, student_name, class_id, class_name, date, semester, academic_year, status, recorded_by, recorder_name, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'MANUAL')
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    recorded_by = VALUES(recorded_by),
                    recorder_name = VALUES(recorder_name),
                    source = 'MANUAL'"
            );

            $sinkron = [];
            foreach ($records as $r) {
                $rId          = 'att_' . $r['student_id'] . '_' . $r['date'];
                $studentId    = $r['student_id'];
                $studentName  = $r['student_name'];
                $classId      = $r['class_id'];
                $className    = $r['class_name'];
                $date         = $r['date'];
                $status       = $r['status'] ?? 'HADIR';
                $recordedBy   = $user['uid'];
                $recorderName = $user['displayName'];

                $stmtUpsert->bind_param(
                    "sssssssssss",
                    $rId, $studentId, $studentName, $classId, $className,
                    $date, $semester, $academicYear, $status, $recordedBy, $recorderName
                );
                $stmtUpsert->execute();

                // Insiden ALFA mengikuti status MANUAL yang baru disimpan
                $getNisn = $db->prepare("SELECT nisn FROM students WHERE id = ?");
                $getNisn->bind_param("s", $studentId);
                $getNisn->execute();
                $sInfo = $getNisn->get_result()->fetch_assoc();
                presensiSinkronInsidenAlfa($db, [
                    'id'         => $studentId,
                    'name'       => $studentName,
                    'nisn'       => $sInfo['nisn'] ?? null,
                    'class_name' => $className,
                ], $date, $status === 'ALFA', $opsiInsiden + [
                    'details' => "Tidak hadir tanpa keterangan (dari presensi $date)",
                ]);
                $sinkron[$date][] = $studentId;
            }

            // Insiden TINGGAL_JP (hadir tetapi ada jam pelajaran ALFA) mengikuti status MANUAL baru
            foreach ($sinkron as $tgl => $ids) {
                presensiSinkronHarian($db, (string)$tgl, $ids, $user);
            }

            $db->commit();
            jsonResponse(['success' => true, 'count' => count($records)]);
        } catch (Exception $e) {
            $db->rollback();
            errorResponse('Gagal simpan presensi: ' . $e->getMessage());
        }
        break;

    case 'get_attendance_summary':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'attendance_read');
        [$activeYear, $activeSemester] = activeAcademicPeriod($db);
        $classId      = $_GET['classId']  ?? '';
        $academicYear = !empty($_GET['year'])     ? $_GET['year']     : $activeYear;
        $semester     = !empty($_GET['semester']) ? $_GET['semester'] : $activeSemester;

        if ($scope['mode'] === 'CLASS') {
            if (empty($classId)) $classId = $scope['classId'];
            if (!classInScope($scope, $classId)) errorResponse(KESISWAAN_MSG_KELAS, 403);
        }

        $sql = "SELECT
                    ar.student_id,
                    ar.student_name,
                    ar.class_name,
                    SUM(CASE WHEN ar.status='HADIR' THEN 1 ELSE 0 END) AS total_hadir,
                    SUM(CASE WHEN ar.status='SAKIT' THEN 1 ELSE 0 END) AS total_sakit,
                    SUM(CASE WHEN ar.status='IZIN'  THEN 1 ELSE 0 END) AS total_izin,
                    SUM(CASE WHEN ar.status='ALFA'  THEN 1 ELSE 0 END) AS total_alfa,
                    COUNT(*) AS total_hari
                FROM attendance_records ar
                WHERE ar.academic_year = ? AND ar.semester = ?";
        $params = [$academicYear, $semester];
        $types  = "ss";
        if (!empty($classId)) { $sql .= " AND ar.class_id = ?"; $params[] = $classId; $types .= "s"; }
        $sql .= " GROUP BY ar.student_id, ar.student_name, ar.class_name ORDER BY ar.student_name ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $summary = [];
        while ($row = $res->fetch_assoc()) {
            $row['total_hadir']  = (int)$row['total_hadir'];
            $row['total_sakit']  = (int)$row['total_sakit'];
            $row['total_izin']   = (int)$row['total_izin'];
            $row['total_alfa']   = (int)$row['total_alfa'];
            $row['total_hari']   = (int)$row['total_hari'];
            $summary[] = $row;
        }
        jsonResponse($summary);
        break;

    // --- PARENT CALLS (full view) ---
    case 'get_parent_calls':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'parent_call');
        $status = $_GET['status'] ?? '';
        $classId = $_GET['classId'] ?? '';
        if (!empty($classId) && !classInScope($scope, $classId)) errorResponse(KESISWAAN_MSG_KELAS, 403);

        $sql = "SELECT pc.*, s.parent_name, s.parent_phone, s.nisn, s.class_id AS scope_student_class_id
                FROM parent_calls pc
                LEFT JOIN students s ON s.id = pc.student_id
                WHERE 1=1";
        $params = [];
        $types  = "";
        if (!empty($status))  { $sql .= " AND pc.status = ?";   $params[] = $status;  $types .= "s"; }
        if (!empty($classId)) { $sql .= " AND pc.student_id IN (SELECT id FROM students WHERE class_id = ?)"; $params[] = $classId; $types .= "s"; }
        $sql .= " ORDER BY pc.created_at DESC";

        $stmt = $db->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $calls = [];
        while ($row = $res->fetch_assoc()) {
            $inScope = parentCallInScope($scope, $row['scope_student_class_id'], $row['class_name']);
            unset($row['scope_student_class_id']);
            if (!$inScope) continue;
            $row['total_points'] = (int)$row['total_points'];
            $row['logs'] = !empty($row['logs']) ? json_decode($row['logs'], true) : [];
            $calls[] = $row;
        }
        jsonResponse($calls);
        break;

    case 'update_parent_call':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'parent_call');
        $body = getRequestBody();
        $callId  = $body['id']     ?? '';
        $status  = $body['status'] ?? '';
        $notes   = $body['notes']  ?? '';
        $schDate = $body['scheduled_date'] ?? null;

        if (empty($callId) || empty($status)) errorResponse('ID dan status wajib diisi');

        // Fetch existing logs
        $stmtGet = $db->prepare(
            "SELECT pc.logs, pc.class_name, s.class_id AS student_class_id
             FROM parent_calls pc LEFT JOIN students s ON s.id = pc.student_id
             WHERE pc.id = ?"
        );
        $stmtGet->bind_param("s", $callId);
        $stmtGet->execute();
        $existing = $stmtGet->get_result()->fetch_assoc();
        if (!$existing) errorResponse('Panggilan tidak ditemukan');
        if (!parentCallInScope($scope, $existing['student_class_id'], $existing['class_name'])) {
            errorResponse(KESISWAAN_MSG_KELAS, 403);
        }

        $logs = json_decode($existing['logs'] ?? '[]', true) ?: [];
        $logs[] = [
            'status'    => $status,
            'actorName' => $user['displayName'],
            'actorRole' => $user['role'],
            'timestamp' => date('Y-m-d H:i:s'),
            'comment'   => $notes ?: "Status diubah ke $status",
        ];
        $logsJson = json_encode($logs);

        $stmt = $db->prepare(
            "UPDATE parent_calls SET status = ?, logs = ?, notes = ?, scheduled_date = ?, handled_by = ?, handler_name = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param("sssssss", $status, $logsJson, $notes, $schDate, $user['uid'], $user['displayName'], $callId);
        if ($stmt->execute()) jsonResponse(['success' => true, 'logs' => $logs]);
        else errorResponse('Gagal update panggilan');
        break;

    case 'save_parent_call_manual':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'parent_call');
        $body = getRequestBody();
        $callId     = 'pcall_' . uniqid();
        $studentId  = $body['student_id']   ?? '';
        $studentName= $body['student_name'] ?? '';
        $className  = $body['class_name']   ?? '';
        $year       = trim((string)($body['academic_year'] ?? '')) ?: getActiveAcademicPeriod($db)['academic_year'];
        $semester   = $body['semester']     ?? 'GANJIL';
        $notes      = $body['notes']        ?? '';
        $schDate    = $body['scheduled_date']?? null;

        if (empty($studentId) || empty($studentName)) errorResponse('Data siswa wajib diisi');
        if (!studentInScope($db, $scope, $studentId)) errorResponse(KESISWAAN_MSG_KELAS, 403);

        // Get current total points
        $res = $db->prepare("SELECT total_violation_points FROM students WHERE id = ?");
        $res->bind_param("s", $studentId);
        $res->execute();
        $sRow = $res->get_result()->fetch_assoc();
        $totalPts = (int)($sRow['total_violation_points'] ?? 0);

        // Initialize logs
        $logs = [[
            'status'    => 'PARENT_INVITED',
            'actorName' => $user['displayName'],
            'actorRole' => $user['role'],
            'timestamp' => date('Y-m-d H:i:s'),
            'comment'   => $notes ?: "Panggilan Orang Tua Dibuat",
        ]];
        $logsJson = json_encode($logs);

        $stmt = $db->prepare(
            "INSERT INTO parent_calls (id, student_id, student_name, class_name, academic_year, semester, total_points, trigger_type, status, logs, notes, scheduled_date, handled_by, handler_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'MANUAL', 'PARENT_INVITED', ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssssssisssss", $callId, $studentId, $studentName, $className, $year, $semester, $totalPts, $logsJson, $notes, $schDate, $user['uid'], $user['displayName']);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $callId, 'logs' => $logs]);
        else errorResponse('Gagal membuat panggilan ortu');
        break;



    default:
        // Fallback to endpoints_v3.php
        $handled = require __DIR__ . '/endpoints_v3.php';
        if (!$handled) return false;
        break;
}
return true; // Handled
