<?php
// Central API Router for SIMANTAP
// Suppress PHP notices/warnings so they don't corrupt JSON output
error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Tolak semua request API sebelum instalasi selesai
if (!file_exists(__DIR__ . '/install.lock') && is_dir(__DIR__ . '/../install')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Aplikasi belum diinstal. Buka /install untuk memulai instalasi.']));
}

// Maintenance mode: update patch sedang berlangsung
if (is_file(__DIR__ . '/.updating')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 30');
    die(json_encode(['error' => 'Pembaruan aplikasi sedang berlangsung. Coba lagi sebentar lagi.']));
}

// Verifikasi lisensi Portal SIMANTAP (dev mode: SIMANTAP_DEV_MODE di config.local.php)
require_once __DIR__ . '/license_guard.php';
simantapLicenseGuard();

// Penanda bahwa request sudah melewati gerbang — dicek file endpoints_*.php
define('SIMANTAP_API', true);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/kesiswaan_access.php';

$action = $_GET['action'] ?? '';

// Konfigurasi session cookie agar berfungsi di HTTPS produksi
// SameSite=Lax cukup untuk same-site requests; ubah ke None jika cross-site.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
           || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,   // 24 jam
        'path'     => '/',
        'secure'   => $isHttps, // Hanya kirim via HTTPS jika memang HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$db = getDB();

switch ($action) {
    case 'login':
        $body = getRequestBody();
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            errorResponse('Email dan password wajib diisi');
        }
        
        // Find user by email
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        
        if ($user && password_verify($password, $user['password'])) {
            unset($user['password']); // security
            
            // Check for pas_foto in gtk_pegawai
            $stmtGtk = $db->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')) AS pas_foto FROM gtk_pegawai WHERE email = ? OR id = ?");
            $stmtGtk->bind_param("ss", $user['email'], $user['uid']);
            $stmtGtk->execute();
            $resGtk = $stmtGtk->get_result();
            if ($rowGtk = $resGtk->fetch_assoc()) {
                if (!empty($rowGtk['pas_foto'])) {
                    $user['photoURL'] = $rowGtk['pas_foto'];
                }
            }

            $_SESSION['user'] = $user;
            jsonResponse(['user' => $user]);
        } else {
            errorResponse('Email atau password salah');
        }
        break;

    case 'logout':
        session_destroy();
        jsonResponse(['success' => true]);
        break;

    case 'app_info':
        // Versi bundle terpasang + info patch update (penanda ditulis saat heartbeat)
        $updateInfo = json_decode((string)@file_get_contents(__DIR__ . '/.update_available'), true);
        jsonResponse([
            'version' => defined('SIMANTAP_APP_VERSION') ? SIMANTAP_APP_VERSION : 'dev',
            'update' => (is_array($updateInfo) && !empty($updateInfo['version'])) ? $updateInfo : null,
        ]);
        break;

    case 'session':
        $user = getCurrentUser();
        if ($user) {
            // Selalu perbarui pas_foto di session untuk berjaga-jaga jika ada upload baru
            $stmtGtk = $db->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')) AS pas_foto FROM gtk_pegawai WHERE email = ? OR id = ?");
            $stmtGtk->bind_param("ss", $user['email'], $user['uid']);
            $stmtGtk->execute();
            $resGtk = $stmtGtk->get_result();
            if ($rowGtk = $resGtk->fetch_assoc()) {
                if (!empty($rowGtk['pas_foto'])) {
                    $user['photoURL'] = $rowGtk['pas_foto'];
                    $_SESSION['user'] = $user;
                }
            }
        }
        jsonResponse(['user' => $user]);
        break;

    case 'register_guru':
        $body = getRequestBody();
        $email = trim($body['email'] ?? '');
        $fullName = trim($body['fullName'] ?? '');
        $nip = trim($body['nip'] ?? '');
        $schoolName = trim($body['schoolName'] ?? '');
        $password = $body['password'] ?? 'simantap123'; // Default password
        
        if (empty($email) || empty($fullName)) {
            errorResponse('Email dan Nama Lengkap wajib diisi');
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $uid = uniqid('guru_');

        // Check if teacher_registry has this email
        $stmt = $db->prepare("SELECT * FROM teacher_registry WHERE LOWER(email) = LOWER(?)");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $hasRegistry = $stmt->get_result()->num_rows > 0;
        
        $role = $hasRegistry ? 'GURU' : 'GURU'; // We can default to GURU role

        $stmt = $db->prepare("INSERT INTO users (uid, email, password, displayName, role, nip, schoolName) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $uid, $email, $hashedPassword, $fullName, $role, $nip, $schoolName);
        
        if ($stmt->execute()) {
            $user = [
                'uid' => $uid,
                'email' => $email,
                'displayName' => $fullName,
                'role' => $role,
                'nip' => $nip,
                'schoolId' => 'DEFAULT_SCHOOL',
                'schoolName' => $schoolName
            ];
            $_SESSION['user'] = $user;
            jsonResponse(['user' => $user]);
        } else {
            errorResponse('Gagal mendaftarkan user. Email mungkin sudah terdaftar.');
        }
        break;

    case 'get_active_teachers':
        // Endpoint publik untuk landing page: hanya nama & sekolah guru, tanpa data sensitif
        $res = $db->query("SELECT displayName, schoolName FROM users WHERE role = 'GURU' ORDER BY lastLogin DESC LIMIT 5");
        $teachers = [];
        while ($row = $res->fetch_assoc()) {
            $teachers[] = $row;
        }
        jsonResponse($teachers);
        break;

    case 'get_users':
        requireRole(['ADMIN', 'KEPALA_SEKOLAH', 'TATA_USAHA']);
        $res = $db->query("SELECT uid, email, displayName, role, nip, schoolId, schoolName, lastLogin, class_homeroom, is_bk FROM users ORDER BY createdAt DESC");
        $users = [];
        while ($row = $res->fetch_assoc()) {
            $row['is_bk'] = (int)($row['is_bk'] ?? 0);
            $users[] = $row;
        }
        jsonResponse($users);
        break;

    case 'save_user':
        requireRole(['ADMIN', 'KEPALA_SEKOLAH', 'TATA_USAHA']);
        $body = getRequestBody();
        $uid = $body['uid'] ?? '';
        $email = strtolower(trim($body['email'] ?? ''));
        $displayName = trim($body['displayName'] ?? '');
        $role = strtoupper($body['role'] ?? 'GURU');
        $nip = trim($body['nip'] ?? '');
        $schoolName = trim($body['schoolName'] ?? 'SMAN 1 BANJARBARU');
        $password = $body['password'] ?? '';
        $classHomeroom = $body['class_homeroom'] ?? null;
        $isBk = (int)($body['is_bk'] ?? 0);

        if (empty($uid) || empty($email) || empty($displayName)) {
            errorResponse('UID, email, dan displayName wajib diisi');
        }

        $hashed = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : null;

        // Check if user already exists
        $stmt = $db->prepare("SELECT uid FROM users WHERE uid = ?");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;

        if ($exists) {
            if ($hashed) {
                $stmt = $db->prepare("UPDATE users SET email = ?, password = ?, displayName = ?, role = ?, nip = ?, schoolName = ?, class_homeroom = ?, is_bk = ? WHERE uid = ?");
                $stmt->bind_param("sssssssss", $email, $hashed, $displayName, $role, $nip, $schoolName, $classHomeroom, $isBk, $uid);
            } else {
                $stmt = $db->prepare("UPDATE users SET email = ?, displayName = ?, role = ?, nip = ?, schoolName = ?, class_homeroom = ?, is_bk = ? WHERE uid = ?");
                $stmt->bind_param("ssssssss", $email, $displayName, $role, $nip, $schoolName, $classHomeroom, $isBk, $uid);
            }
        } else {
            $hashed = $hashed ?? password_hash('simantap123', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (uid, email, password, displayName, role, nip, schoolName, class_homeroom, is_bk) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssi", $uid, $email, $hashed, $displayName, $role, $nip, $schoolName, $classHomeroom, $isBk);
        }

        if ($stmt->execute()) {
            // Synchronize with teacher_registry
            if ($role === 'GURU') {
                $regStmt = $db->prepare("REPLACE INTO teacher_registry (email, fullName, nip, schoolName) VALUES (?, ?, ?, ?)");
                $regStmt->bind_param("ssss", $email, $displayName, $nip, $schoolName);
                $regStmt->execute();
            } else {
                // If role changed from GURU, clean up registry
                $regDel = $db->prepare("DELETE FROM teacher_registry WHERE email = ?");
                $regDel->bind_param("s", $email);
                $regDel->execute();
            }
            // Update session if modifying own account
            if (isset($_SESSION['user']) && $_SESSION['user']['uid'] === $uid) {
                $_SESSION['user']['class_homeroom'] = $classHomeroom;
                $_SESSION['user']['is_bk'] = $isBk;
            }
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menyimpan user: ' . $db->error);
        }
        break;

    case 'delete_user':
        requireRole(['ADMIN', 'KEPALA_SEKOLAH']);
        $uid = $_GET['uid'] ?? '';
        if (empty($uid)) errorResponse('UID wajib disertakan');

        // Fetch user email first to clean up teacher_registry
        $stmt = $db->prepare("SELECT email FROM users WHERE uid = ?");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $email = '';
        if ($row = $res->fetch_assoc()) {
            $email = $row['email'];
        }

        $stmt = $db->prepare("DELETE FROM users WHERE uid = ?");
        $stmt->bind_param("s", $uid);
        if ($stmt->execute()) {
            if (!empty($email)) {
                // Clean up teacher_registry
                $regDel = $db->prepare("DELETE FROM teacher_registry WHERE email = ?");
                $regDel->bind_param("s", $email);
                $regDel->execute();
            }
            // Clean up teacher_subjects
            $subDel = $db->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
            $subDel->bind_param("s", $uid);
            $subDel->execute();

            // Clean up linked GTK record (gtk_pegawai.id === users.uid)
            $gtkDel = $db->prepare("DELETE FROM gtk_pegawai WHERE id = ?");
            $gtkDel->bind_param("s", $uid);
            $gtkDel->execute();
            // Clean up gtk_files & gtk_riwayat terkait
            $db->query("DELETE FROM gtk_files WHERE gtk_id='" . $db->real_escape_string($uid) . "'");
            $db->query("DELETE FROM gtk_riwayat WHERE gtk_id='" . $db->real_escape_string($uid) . "'");

            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menghapus user');
        }
        break;

    case 'get_teacher_registry':
        $res = $db->query("SELECT * FROM teacher_registry");
        $registry = [];
        while ($row = $res->fetch_assoc()) {
            $registry[] = $row;
        }
        jsonResponse($registry);
        break;

    case 'save_teacher_registry':
        requireRole(['ADMIN']);
        $body = getRequestBody();
        $email = strtolower(trim($body['email'] ?? ''));
        $fullName = trim($body['fullName'] ?? '');
        $nip = trim($body['nip'] ?? '');
        $schoolName = trim($body['schoolName'] ?? '');

        if (empty($email) || empty($fullName)) {
            errorResponse('Email dan Nama Lengkap wajib diisi');
        }

        $stmt = $db->prepare("REPLACE INTO teacher_registry (email, fullName, nip, schoolName) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $email, $fullName, $nip, $schoolName);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menyimpan ke registry');
        }
        break;

    case 'get_student_registry':
        $res = $db->query("SELECT * FROM student_registry");
        $registry = [];
        while ($row = $res->fetch_assoc()) {
            $row['students'] = json_decode($row['students'], true) ?: [];
            $registry[] = $row;
        }
        jsonResponse($registry);
        break;

    case 'save_student_registry':
        $body = getRequestBody();
        $classId = trim($body['classId'] ?? '');
        $students = $body['students'] ?? [];

        if (empty($classId)) {
            errorResponse('ClassId wajib diisi');
        }

        $studentsJson = json_encode($students);
        $stmt = $db->prepare("REPLACE INTO student_registry (classId, students) VALUES (?, ?)");
        $stmt->bind_param("ss", $classId, $studentsJson);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menyimpan registry siswa');
        }
        break;

    // --- JOURNAL STATS (for Dashboard chart, uid-based, no session required) ---
    case 'get_journal_stats':
        $uid  = trim($_GET['uid']  ?? ($_POST['uid']  ?? ''));
        $role = trim($_GET['role'] ?? ($_POST['role'] ?? ''));
        if (empty($uid)) {
            errorResponse('uid required', 400);
        }
        if ($role === 'GURU') {
            $stmt = $db->prepare("SELECT date FROM journal_entries WHERE teacherId = ? ORDER BY date DESC");
            $stmt->bind_param("s", $uid);
        } else {
            // ADMIN / KEPALA_SEKOLAH — all journals
            $stmt = $db->prepare("SELECT date FROM journal_entries ORDER BY date DESC");
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $dates = [];
        while ($row = $res->fetch_assoc()) {
            $dates[] = $row['date']; // "YYYY-MM-DD"
        }
        jsonResponse($dates);
        break;

    // --- JOURNALS ---
    case 'get_journals':
        $user = requireAuth();
        // GURU can only see their own journals; ADMIN/KEPALA_SEKOLAH see all
        if ($user['role'] === 'GURU') {
            $stmt = $db->prepare("SELECT * FROM journal_entries WHERE teacherId = ? ORDER BY date DESC, createdAt DESC");
            $stmt->bind_param("s", $user['uid']);
        } else {
            // ADMIN / KEPALA_SEKOLAH see all
            $stmt = $db->prepare("SELECT * FROM journal_entries ORDER BY date DESC, createdAt DESC");
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $journals = [];
        while ($row = $res->fetch_assoc()) {
            $row['learningObjectives'] = !empty($row['learningObjectives']) ? json_decode($row['learningObjectives'], true) : [];
            $row['media']              = !empty($row['media'])              ? json_decode($row['media'], true)              : [];
            $row['studentAttendance']  = !empty($row['studentAttendance'])  ? json_decode($row['studentAttendance'], true)  : [];
            $row['attendance']         = $row['studentAttendance']; // frontend alias
            $row['photos']             = !empty($row['photos'])             ? json_decode($row['photos'], true)             : [];
            $row['evidence']           = $row['photos']; // frontend alias
            $row['materials']          = !empty($row['materials'])          ? json_decode($row['materials'], true)          : [];
            // NOTE: 'grades' column does not exist in journal_entries; kept as empty for frontend compat
            $row['grades']             = [];
            $row['assessment_data']    = !empty($row['assessment_data'])    ? json_decode($row['assessment_data'], true)    : [];
            $row['isDraft']            = (bool)($row['isDraft'] ?? false);
            $row['isVerified']         = (bool)($row['isVerified'] ?? false);
            $row['has_assessment']     = (bool)($row['has_assessment'] ?? false);
            // Map DB column names -> frontend field names
            $row['metode']             = $row['method']     ?? '';
            $row['kendala']            = $row['obstacles']  ?? '';
            $row['tindakLanjut']       = $row['followUp']   ?? '';
            $row['alatMedia']          = $row['alat_media'] ?? '';
            $row['jam']                = $row['jam_ke']     ?? '';
            $row['jamMulai']           = $row['jam_mulai']  ?? '';
            $row['jamSelesai']         = $row['jam_selesai']?? '';
            $row['time']               = $row['period']     ?? '';
            // Compute attendanceRate from stored value or recalculate from attendance data
            $storedRate = isset($row['attendanceRate']) ? (int)$row['attendanceRate'] : 0;
            if ($storedRate > 0) {
                $row['attendanceRate'] = $storedRate;
            } else {
                $att = $row['studentAttendance'];
                $totalSt   = is_array($att) ? count($att) : 0;
                $presentSt = is_array($att) ? count(array_filter($att, fn($v) => $v === 'HADIR')) : 0;
                $row['attendanceRate'] = $totalSt > 0 ? (int)round(($presentSt / $totalSt) * 100) : 0;
            }
            $journals[] = $row;
        }
        jsonResponse($journals);
        break;

    case 'save_journal':
        $user = requireAuth();
        $body = getRequestBody();
        
        $id          = $body['id'] ?? uniqid('j_');
        $teacherId   = $user['uid'];
        $teacherName = $user['displayName'];
        $date        = $body['date'] ?? date('Y-m-d');
        $classId     = $body['classId'] ?? '';
        $className   = $body['className'] ?? '';
        $subjectId   = $body['subjectId'] ?? '';
        $subjectName = $body['subjectName'] ?? '';
        // time / period
        $period      = $body['time'] ?? $body['waktu'] ?? $body['period'] ?? '';
        $jam_ke      = $body['jam'] ?? $body['jam_ke'] ?? null;
        $jam_mulai   = $body['jamMulai'] ?? $body['jam_mulai'] ?? null;
        $jam_selesai = $body['jamSelesai'] ?? $body['jam_selesai'] ?? null;
        $topic       = $body['topic'] ?? $body['topik'] ?? '';
        $kategori    = $body['kategori'] ?? 'Rencana Pembelajaran';
        $learningObjectives = json_encode($body['learningObjectives'] ?? []);
        $method      = $body['metode'] ?? $body['method'] ?? '';
        $alat_media  = $body['alatMedia'] ?? $body['alat_media'] ?? null;
        $media_json  = json_encode($body['media'] ?? []);
        $activities  = $body['activities'] ?? $body['aktivitas'] ?? '';
        $attendanceRaw    = $body['attendance'] ?? $body['studentAttendance'] ?? [];
        $studentAttendance = json_encode($attendanceRaw);
        $notes       = $body['notes'] ?? null;
        $obstacles   = $body['kendala'] ?? $body['obstacles'] ?? null;
        $followUp    = $body['tindakLanjut'] ?? $body['followUp'] ?? null;
        $isDraft     = isset($body['isDraft']) ? (int)(bool)$body['isDraft'] : 1;
        $isVerified  = isset($body['isVerified']) ? (int)(bool)$body['isVerified'] : 0;
        $verifiedBy  = $body['verifiedBy'] ?? null;
        $verifiedAt  = $body['verifiedAt'] ?? null;
        $assessment_data = $body['assessment_data'] ?? null;
        $has_assessment  = isset($body['has_assessment']) ? (int)$body['has_assessment'] : 0;
        $materials       = json_encode($body['materials'] ?? []);

        // ---- Handle evidence: save server paths (already-uploaded) vs filter out blob: URLs ----
        $evidenceRaw = $body['evidence'] ?? $body['photos'] ?? [];
        $photosToStore = [];
        if (is_array($evidenceRaw)) {
            foreach ($evidenceRaw as $ev) {
                $url = $ev['url'] ?? '';
                // Keep only actual server paths, not blob: URLs
                if (!empty($url) && !str_starts_with($url, 'blob:') && !str_starts_with($url, 'data:')) {
                    $photosToStore[] = $ev;
                }
                // base64 encoded images (from upload_journal_photo endpoint, already processed server-side)
            }
        }
        $photos = json_encode($photosToStore);

        // ---- Attendance rate calculation ----
        $totalSt   = is_array($attendanceRaw) ? count($attendanceRaw) : 0;
        $presentSt = is_array($attendanceRaw) ? count(array_filter($attendanceRaw, fn($v) => $v === 'HADIR')) : 0;
        $attendanceRateVal = $totalSt > 0 ? (int)round(($presentSt / $totalSt) * 100) : 0;
        // Use caller-provided rate if it looks valid
        if (isset($body['attendanceRate']) && is_numeric($body['attendanceRate'])) {
            $attendanceRateVal = (int)$body['attendanceRate'];
        }

        $db->begin_transaction();
        try {
            $stmt = $db->prepare("REPLACE INTO journal_entries 
                (id, teacherId, teacherName, date, classId, className, subjectId, subjectName,
                 period, jam_ke, jam_mulai, jam_selesai,
                 topic, kategori, learningObjectives, method, alat_media, media, activities,
                 studentAttendance, notes, obstacles, followUp, photos, materials,
                 isDraft, isVerified, verifiedBy, verifiedAt, assessment_data, has_assessment, attendanceRate) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("sssssssssssssssssssssssssiisssii", 
                $id, $teacherId, $teacherName, $date, $classId, $className, $subjectId, $subjectName,
                $period, $jam_ke, $jam_mulai, $jam_selesai,
                $topic, $kategori, $learningObjectives, $method, $alat_media, $media_json, $activities,
                $studentAttendance, $notes, $obstacles, $followUp, $photos, $materials,
                $isDraft, $isVerified, $verifiedBy, $verifiedAt, $assessment_data, $has_assessment, $attendanceRateVal
            );
            if (!$stmt->execute()) {
                throw new Exception("Gagal insert journal_entries: " . $stmt->error);
            }

            // Insert into attendance_records if not draft and attendance exists
            if (!$isDraft && is_array($attendanceRaw) && !empty($attendanceRaw)) {
                // Get active year/semester
                $resYear = $db->query("SELECT config_value FROM system_config WHERE config_key = 'active_academic_year'");
                $yearRow = $resYear->fetch_assoc();
                $academicYear = $yearRow['config_value'] ?? '2025/2026';

                $resSem = $db->query("SELECT config_value FROM system_config WHERE config_key = 'active_semester'");
                $semRow = $resSem->fetch_assoc();
                $semester = $semRow['config_value'] ?? 'GANJIL';

                $resThresh = $db->query("SELECT config_value FROM system_config WHERE config_key = 'violation_threshold_parentcall'");
                $threshRow = $resThresh->fetch_assoc();
                $threshold = (int)($threshRow['config_value'] ?? 75);

                $resAlfa = $db->query("SELECT points FROM violation_types WHERE violation_code = 'ALFA'");
                $alfaRow = $resAlfa ? $resAlfa->fetch_assoc() : null;
                $alfaPoints = (int)($alfaRow['points'] ?? 15);

                $stmtUpsert = $db->prepare(
                    "INSERT INTO attendance_records
                        (id, student_id, student_name, class_id, class_name, date, semester, academic_year, status, recorded_by, recorder_name, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'JURNAL')
                     ON DUPLICATE KEY UPDATE
                        status = IF(attendance_records.source = 'MANUAL', attendance_records.status, VALUES(status)),
                        recorded_by = IF(attendance_records.source = 'MANUAL', attendance_records.recorded_by, VALUES(recorded_by)),
                        recorder_name = IF(attendance_records.source = 'MANUAL', attendance_records.recorder_name, VALUES(recorder_name)),
                        source = IF(attendance_records.source = 'MANUAL', 'MANUAL', 'JURNAL')"
                );

                // Need student names, let's fetch all students for this class
                $resStudents = $db->prepare("SELECT id, name, nisn FROM students WHERE class_id = ?");
                $resStudents->bind_param("s", $classId);
                $resStudents->execute();
                $studentsArr = [];
                $sResult = $resStudents->get_result();
                while ($s = $sResult->fetch_assoc()) {
                    $studentsArr[$s['id']] = ['name' => $s['name'], 'nisn' => $s['nisn']];
                }

                foreach ($attendanceRaw as $studentId => $status) {
                    $sInfo = $studentsArr[$studentId] ?? null;
                    $studentName = $sInfo ? $sInfo['name'] : 'Unknown Student';
                    $studentNisn = $sInfo ? $sInfo['nisn'] : null;
                    $rId = 'att_' . $studentId . '_' . $date;

                    $stmtUpsert->bind_param(
                        "sssssssssss",
                        $rId, $studentId, $studentName, $classId, $className,
                        $date, $semester, $academicYear, $status, $teacherId, $teacherName
                    );
                    $stmtUpsert->execute();

                    // Auto-trigger violation for ALFA
                    $incId = 'inc_att_' . $studentId . '_' . $date;
                    if ($status === 'ALFA' || $status === 'ALPHA') {
                        $checkInc = $db->query("SELECT id FROM incidents WHERE id = '$incId'");
                        if ($checkInc->num_rows === 0) {
                            $details = "Tidak hadir tanpa keterangan (dari Jurnal $date)";
                            $stmtInc = $db->prepare(
                                "INSERT INTO incidents (id, type, date, studentId, student_nisn, studentName, className, academic_year, semester, reporterId, reporterName, details, violation_code, points, status, parent_call_triggered)
                                 VALUES (?, 'VIOLATION', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ALFA', ?, 'REPORTED', 0)"
                            );
                            $stmtInc->bind_param(
                                "sssssssssssi",
                                $incId, $date, $studentId, $studentNisn, $studentName, $className, $academicYear, $semester,
                                $teacherId, $teacherName, $details, $alfaPoints
                            );
                            $stmtInc->execute();

                            $db->query("UPDATE students SET total_violation_points = total_violation_points + $alfaPoints WHERE id = '" . $db->real_escape_string($studentId) . "'");

                            $res2 = $db->query("SELECT total_violation_points FROM students WHERE id = '" . $db->real_escape_string($studentId) . "'");
                            $sRow = $res2->fetch_assoc();
                            $totalPts = (int)($sRow['total_violation_points'] ?? 0);

                            if ($totalPts >= $threshold) {
                                $callId = 'pcall_' . $studentId . '_' . time();
                                $stmtCall = $db->prepare(
                                    "INSERT IGNORE INTO parent_calls (id, student_id, student_name, class_name, academic_year, semester, total_points, trigger_type, trigger_incident_id, status)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, 'AUTO', ?, 'PENDING')"
                                );
                                $stmtCall->bind_param("ssssssis", $callId, $studentId, $studentName, $className, $academicYear, $semester, $totalPts, $incId);
                                $stmtCall->execute();
                            }
                        }
                    } else {
                        $checkInc = $db->query("SELECT id FROM incidents WHERE id = '$incId'");
                        if ($checkInc->num_rows > 0) {
                            $db->query("DELETE FROM incidents WHERE id = '$incId'");
                            $db->query("UPDATE students SET total_violation_points = GREATEST(0, total_violation_points - $alfaPoints) WHERE id = '" . $db->real_escape_string($studentId) . "'");
                        }
                    }
                }
            }

            $db->commit();
            jsonResponse(['success' => true, 'id' => $id]);
        } catch (Throwable $e) {
            $db->rollback();
            errorResponse('Gagal menyimpan jurnal: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
        }
        break;

    case 'delete_journal':
        $user = requireAuth();
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID Jurnal wajib diisi');

        $stmt = $db->prepare("DELETE FROM journal_entries WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menghapus jurnal');
        }
        break;

    case 'upload_journal_photo':
        // Endpoint to upload a journal photo from base64 and store to server
        $user = requireAuth();
        $body = getRequestBody();
        $dataUrl = $body['dataUrl'] ?? '';
        $journalIdForPhoto = $body['journalId'] ?? 'tmp';
        $fileType = $body['fileType'] ?? 'jpeg'; // jpeg or png

        if (empty($dataUrl)) {
            errorResponse('Data gambar kosong');
        }

        // Strip data URL prefix: data:image/jpeg;base64,...
        if (strpos($dataUrl, ';base64,') !== false) {
            [, $base64Data] = explode(';base64,', $dataUrl);
        } else {
            $base64Data = $dataUrl;
        }

        $imageData = base64_decode($base64Data);
        if ($imageData === false) {
            errorResponse('Format gambar tidak valid');
        }

        // Build per-teacher folder: uploads/journals/{teacherId}/
        $teacherFolder = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user['uid']);
        $uploadDir = __DIR__ . '/../uploads/journals/' . $teacherFolder . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = ($fileType === 'png') ? 'png' : 'jpg';
        $filename = 'photo_' . uniqid() . '.' . $ext;
        $filePath = $uploadDir . $filename;

        if (file_put_contents($filePath, $imageData) === false) {
            errorResponse('Gagal menyimpan foto ke server');
        }

        // Return path relatif terhadap root deploy (frontend me-resolve via asset())
        $webPath = 'uploads/journals/' . $teacherFolder . '/' . $filename;
        jsonResponse(['success' => true, 'url' => $webPath, 'filename' => $filename]);
        break;


    case 'get_forum_posts':
        $res = $db->query("SELECT * FROM forum_posts ORDER BY timestamp DESC");
        $posts = [];
        while ($row = $res->fetch_assoc()) {
            $row['likes'] = json_decode($row['likes'], true) ?: [];
            $row['attachments'] = json_decode($row['attachments'], true) ?: [];
            $row['tags'] = json_decode($row['tags'], true) ?: [];
            $posts[] = $row;
        }
        jsonResponse($posts);
        break;

    case 'save_forum_post':
        $user = requireAuth();
        $body = getRequestBody();
        
        $id = $body['id'] ?? uniqid('post_');
        $authorId = $user['uid'];
        $authorName = $user['displayName'];
        $authorRole = $user['role'];
        $authorAvatar = $user['photoURL'] ?? null;
        $content = $body['content'] ?? '';
        $likes = json_encode($body['likes'] ?? []);
        $repliesCount = (int)($body['repliesCount'] ?? 0);
        $attachments = json_encode($body['attachments'] ?? []);
        $tags = json_encode($body['tags'] ?? []);

        $stmt = $db->prepare("REPLACE INTO forum_posts (id, authorId, authorName, authorRole, authorAvatar, content, likes, repliesCount, attachments, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssisss", $id, $authorId, $authorName, $authorRole, $authorAvatar, $content, $likes, $repliesCount, $attachments, $tags);
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            errorResponse('Gagal menyimpan forum post');
        }
        break;

    case 'get_forum_replies':
        $postId = $_GET['postId'] ?? '';
        if (empty($postId)) errorResponse('Post ID wajib diisi');

        $stmt = $db->prepare("SELECT * FROM forum_replies WHERE postId = ? ORDER BY timestamp ASC");
        $stmt->bind_param("s", $postId);
        $stmt->execute();
        $res = $stmt->get_result();
        $replies = [];
        while ($row = $res->fetch_assoc()) {
            $row['likes'] = json_decode($row['likes'], true) ?: [];
            $replies[] = $row;
        }
        jsonResponse($replies);
        break;

    case 'save_forum_reply':
        $user = requireAuth();
        $body = getRequestBody();

        $id = $body['id'] ?? uniqid('rep_');
        $postId = $body['postId'] ?? '';
        $authorId = $user['uid'];
        $authorName = $user['displayName'];
        $authorAvatar = $user['photoURL'] ?? null;
        $content = $body['content'] ?? '';
        $likes = json_encode($body['likes'] ?? []);

        if (empty($postId) || empty($content)) {
            errorResponse('PostID dan konten reply wajib diisi');
        }

        $stmt = $db->prepare("REPLACE INTO forum_replies (id, postId, authorId, authorName, authorAvatar, content, likes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $id, $postId, $authorId, $authorName, $authorAvatar, $content, $likes);
        
        if ($stmt->execute()) {
            // Update replies count in main post
            $db->query("UPDATE forum_posts SET repliesCount = repliesCount + 1 WHERE id = '" . $db->real_escape_string($postId) . "'");
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            errorResponse('Gagal menyimpan reply');
        }
        break;

    // --- INCIDENTS ---
    case 'get_incidents':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'incident');
        $classId = $_GET['class'] ?? $_GET['classId'] ?? '';
        if (!empty($classId) && !classInScope($scope, $classId)) errorResponse(KESISWAAN_MSG_KELAS, 403);
        $rawSemester = $_GET['semester'] ?? '';
        $semester = '';
        if ($rawSemester === '1') $semester = 'GANJIL';
        elseif ($rawSemester === '2') $semester = 'GENAP';
        else $semester = $rawSemester;

        $conditions = [];
        if (!empty($classId)) {
            $cleanClass = preg_replace('/[\s\-_.]/', '', strtolower($classId));
            $safeCleanClass = $db->real_escape_string($cleanClass);
            $conditions[] = "(LOWER(REPLACE(REPLACE(REPLACE(i.className, ' ', ''), '-', ''), '.', '')) = '$safeCleanClass')";
        }
        if (!empty($semester)) {
            $conditions[] = "i.semester = '" . $db->real_escape_string($semester) . "'";
        }

        $sql = "SELECT i.*, JSON_UNQUOTE(JSON_EXTRACT(s.docs_json, '$.pas_foto')) AS pas_foto 
                FROM incidents i 
                LEFT JOIN students s ON i.studentId = s.id";
        if (count($conditions) > 0) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql .= " ORDER BY i.date DESC, i.createdAt DESC";
        $res = $db->query($sql);
        $incidents = [];
        while ($row = $res->fetch_assoc()) {
            // Wali kelas: hanya insiden kelas perwaliannya
            if (!classInScope($scope, $row['className'])) continue;
            $row['points'] = (int)$row['points'];
            $incidents[] = $row;
        }
        jsonResponse($incidents);
        break;

    case 'save_incident':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'incident');
        $body = getRequestBody();

        $id = $body['id'] ?? uniqid('inc_');

        // Check if updating an existing incident
        $checkExisting = $db->query("SELECT * FROM incidents WHERE id = '" . $db->real_escape_string($id) . "'");
        $existing = $checkExisting->fetch_assoc();

        if ($existing) {
            if (!classInScope($scope, $existing['className'])) errorResponse(KESISWAAN_MSG_KELAS, 403);

            // Partial Update
            $status = $body['status'] ?? $existing['status'];
            $notes = $body['notes'] ?? $body['actionTaken'] ?? $existing['actionTaken'];
            
            // Optionally store logs as JSON in actionTaken if logs are provided
            if (isset($body['logs'])) {
                $logsJson = json_encode($body['logs']);
                $actionTaken = $notes . "\n---\nLogs: " . $logsJson;
            } else {
                $actionTaken = $notes;
            }

            $stmt = $db->prepare("UPDATE incidents SET status = ?, actionTaken = ? WHERE id = ?");
            $stmt->bind_param("sss", $status, $actionTaken, $id);
            $success = $stmt->execute();
            
            if ($success) {
                jsonResponse(['success' => true, 'id' => $id]);
            } else {
                errorResponse('Gagal memperbarui insiden: ' . $db->error);
            }
            exit; // Don't run auto-escalation again for status updates
        }

        // --- NEW INSERT LOGIC ---
        $type = $body['type'] ?? 'VIOLATION';
        $date = $body['date'] ?? date('Y-m-d');
        $studentId    = $body['studentId']   ?? '';
        $studentName  = $body['studentName'] ?? '';
        $studentNisn  = $body['studentNisn'] ?? null;
        $className    = $body['className']   ?? $body['class'] ?? '';
        $classId      = $body['classId']     ?? '';
        $academicYear = $body['academicYear'] ?? '2025/2026';
        $semester     = $body['semester']    ?? 'GANJIL';
        $reporterId   = $user['uid'];
        $reporterName = $user['displayName'];
        $details      = $body['details']     ?? $body['category'] ?? '';
        $violationCode= $body['violationCode']?? $body['violation_code'] ?? null;
        $points       = (int)($body['points'] ?? 0);
        $status       = $body['status']      ?? 'REPORTED';
        $actionTaken  = $body['actionTaken'] ?? $body['notes'] ?? null;

        // Auto-lookup student_id from students table if not provided
        if (empty($studentId) && !empty($studentName)) {
            $qSql = "SELECT id, nisn, class_name, class_id FROM students WHERE name = ?";
            $qParams = [$studentName];
            $qTypes = "s";
            if (!empty($classId)) {
                $qSql .= " AND class_id = ?"; $qParams[] = $classId; $qTypes .= "s";
            } elseif (!empty($className)) {
                $qSql .= " AND class_name = ?"; $qParams[] = $className; $qTypes .= "s";
            }
            $qSql .= " LIMIT 1";
            $stmtLookup = $db->prepare($qSql);
            $stmtLookup->bind_param($qTypes, ...$qParams);
            $stmtLookup->execute();
            $found = $stmtLookup->get_result()->fetch_assoc();
            if ($found) {
                $studentId   = $found['id'];
                $studentNisn = $found['nisn'];
                if (empty($className))  $className = $found['class_name'];
                if (empty($classId))    $classId   = $found['class_id'];
            }
        }

        // Wali kelas: kelas insiden dan kelas siswa harus kelas perwaliannya
        if ($scope['mode'] === 'CLASS') {
            $classOk   = classInScope($scope, $classId) || classInScope($scope, $className);
            $studentOk = empty($studentId) || studentInScope($db, $scope, $studentId);
            if (!$classOk || !$studentOk) errorResponse(KESISWAAN_MSG_KELAS, 403);
        }

        // Auto-lookup points from violation_types if violation_code given
        if (!empty($violationCode) && $points === 0) {
            $vtRes = $db->prepare("SELECT points FROM violation_types WHERE violation_code = ?");
            $vtRes->bind_param("s", $violationCode);
            $vtRes->execute();
            $vtRow = $vtRes->get_result()->fetch_assoc();
            if ($vtRow) $points = (int)$vtRow['points'];
        }

        $stmt = $db->prepare("INSERT INTO incidents (id, type, date, studentId, student_nisn, studentName, className, academic_year, semester, reporterId, reporterName, details, violation_code, points, status, actionTaken) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssssssiss", $id, $type, $date, $studentId, $studentNisn, $studentName, $className, $academicYear, $semester, $reporterId, $reporterName, $details, $violationCode, $points, $status, $actionTaken);
        
        if ($stmt->execute()) {
            // Logic for auto-escalation (Only for VIOLATION and LATE which have points)
            if (($type === 'VIOLATION' || $type === 'LATE') && $points > 0 && !empty($studentId)) {
                // Update total points in students table
                $db->query("UPDATE students SET total_violation_points = total_violation_points + $points WHERE id = '" . $db->real_escape_string($studentId) . "'");
                
                // Get new total
                $res = $db->query("SELECT total_violation_points FROM students WHERE id = '" . $db->real_escape_string($studentId) . "'");
                $studentRow = $res->fetch_assoc();
                $totalPoints = (int)($studentRow['total_violation_points'] ?? 0);
                
                // Get threshold
                $resThreshold = $db->query("SELECT config_value FROM system_config WHERE config_key = 'violation_threshold_parentcall'");
                $thresholdRow = $resThreshold->fetch_assoc();
                $threshold = (int)($thresholdRow['config_value'] ?? 75);
                
                if ($totalPoints >= $threshold) {
                    // Check if a parent call already exists for this student recently
                    $checkCall = $db->prepare("SELECT id FROM parent_calls WHERE student_id = ? AND status IN ('PENDING','SCHEDULED') LIMIT 1");
                    $checkCall->bind_param("s", $studentId);
                    $checkCall->execute();
                    $existingCall = $checkCall->get_result()->fetch_assoc();

                    if (!$existingCall) {
                        $callId = uniqid('pcall_');
                        $stmtCall = $db->prepare("INSERT INTO parent_calls (id, student_id, student_name, student_nisn, class_name, academic_year, semester, total_points, trigger_type, trigger_incident_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'AUTO', ?, 'PENDING')");
                        $stmtCall->bind_param("sssssssis", $callId, $studentId, $studentName, $studentNisn, $className, $academicYear, $semester, $totalPoints, $id);
                        $stmtCall->execute();
                        
                        // Mark incident as triggered
                        $db->query("UPDATE incidents SET parent_call_triggered = 1, parent_call_id = '$callId' WHERE id = '$id'");
                        
                        // Notify Admin & Kepsek
                        $notifyTitle = "Panggilan Ortu Otomatis";
                        $notifyMsg = "Siswa $studentName ($className) telah mencapai $totalPoints poin (Batas: $threshold).";
                        $db->query("INSERT INTO notifications (user_id, title, message, type) SELECT uid, '$notifyTitle', '$notifyMsg', 'SYSTEM' FROM users WHERE role IN ('ADMIN', 'KEPALA_SEKOLAH')");
                    }
                }
            }
            jsonResponse(['success' => true, 'id' => $id, 'studentId' => $studentId, 'points' => $points]);
        } else {
            errorResponse('Gagal menyimpan insiden');
        }
        break;

    case 'delete_incident':
        $user = requireAuth();
        requireKesiswaanScope($db, $user, 'incident_delete');
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib disertakan');

        $stmt = $db->prepare("DELETE FROM incidents WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menghapus insiden');
        }
        break;

    // --- GENERAL ACTIVITIES ---
    case 'get_general_activities':
        $type = $_GET['type'] ?? '';
        $sql = "SELECT * FROM general_activities";
        if (!empty($type)) {
            $sql .= " WHERE type = '" . $db->real_escape_string($type) . "'";
        }
        $sql .= " ORDER BY date DESC";
        $res = $db->query($sql);
        $activities = [];
        while ($row = $res->fetch_assoc()) {
            $row['participantsCount'] = (int)$row['participantsCount'];
            $row['score'] = $row['participantsCount']; // Map back to score for frontend
            $row['class'] = $row['class_id'] ?? '';
            $activities[] = $row;
        }
        jsonResponse($activities);
        break;

    case 'save_general_activity':
        $user = requireAuth();
        $body = getRequestBody();

        $id = $body['id'] ?? uniqid('ga_');
        $type = $body['type'] ?? 'CEREMONY';
        $date = $body['date'] ?? date('Y-m-d');
        $title = $body['title'] ?? '';
        $description = $body['description'] ?? '';
        $participantsCount = isset($body['score']) ? (int)$body['score'] : (int)($body['participantsCount'] ?? 0);
        $reporterId = $user['uid'];
        $reporterName = $user['displayName'];
        $classId = $body['class'] ?? '';

        $stmt = $db->prepare("REPLACE INTO general_activities (id, type, date, title, description, participantsCount, reporterId, reporterName, class_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssisss", $id, $type, $date, $title, $description, $participantsCount, $reporterId, $reporterName, $classId);
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            errorResponse('Gagal menyimpan aktivitas umum');
        }
        break;

    case 'delete_general_activity':
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib disertakan');

        $stmt = $db->prepare("DELETE FROM general_activities WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menghapus aktivitas');
        }
        break;

    // --- EXTRACURRICULARS ---
    case 'get_extracurriculars':
        $res = $db->query("SELECT * FROM extracurriculars ORDER BY name ASC");
        $extras = [];
        while ($row = $res->fetch_assoc()) {
            $row['students'] = json_decode($row['students'], true) ?: [];
            $row['activities'] = json_decode($row['activities'], true) ?: [];
            $extras[] = $row;
        }
        jsonResponse($extras);
        break;

    case 'get_ekskul_journals':
        $uid = $_GET['uid'] ?? '';
        $sql = "SELECT ea.*, e.name as ekskulName FROM extracurricular_activities ea LEFT JOIN extracurriculars e ON ea.extracurricular_id = e.id ORDER BY ea.date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $res = $stmt->get_result();
        $journals = [];
        while ($row = $res->fetch_assoc()) {
            $att = json_decode($row['attendance'], true);
            $rate = 100;
            if (is_array($att) && count($att) > 0) {
                $total = count($att);
                $present = 0;
                foreach ($att as $studentId => $isHadir) {
                    if ($isHadir === true || $isHadir === 'true' || $isHadir === 1 || $isHadir === '1' || (is_array($isHadir) && isset($isHadir['status']) && $isHadir['status'] === 'HADIR')) {
                        $present++;
                    }
                }
                $rate = round(($present / $total) * 100);
            }
            $journals[] = [
                'id' => $row['id'],
                'ekskulId' => $row['extracurricular_id'],
                'ekskulName' => $row['ekskulName'] ?? 'Unknown',
                'date' => $row['date'],
                'topic' => $row['title'],
                'activities' => $row['description'] ?? '',
                'attendanceRate' => $rate,
                'isVerified' => true,
                'instructorId' => $row['reporter_id'],
                'instructorName' => $row['reporter_name']
            ];
        }
        jsonResponse($journals);
        break;

    case 'save_ekskul_journal':
        $body = getRequestBody();
        $id = uniqid('ea_');
        $ekId = $body['ekskulId'] ?? '';
        $date = $body['date'] ?? date('Y-m-d');
        $title = $body['topic'] ?? '';
        $desc = $body['activities'] ?? '';
        $reporterId = $body['instructorId'] ?? '';
        $reporterName = $body['instructorName'] ?? '';
        
        $stmt = $db->prepare("REPLACE INTO extracurricular_activities (id, extracurricular_id, date, title, description, reporter_id, reporter_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $id, $ekId, $date, $title, $desc, $reporterId, $reporterName);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menyimpan jurnal');
        break;

    case 'verify_ekskul_journal':
        jsonResponse(['success' => true]);
        break;

    case 'save_extracurricular':
        $body = getRequestBody();
        
        $id = $body['id'] ?? uniqid('extra_');
        $name = $body['name'] ?? '';
        $coachId = $body['coachId'] ?? '';
        $coachName = $body['coachName'] ?? '';
        $schedule = $body['schedule'] ?? '';
        $description = $body['description'] ?? '';
        $room = $body['room'] ?? '';
        $students = json_encode($body['students'] ?? []);
        $activities = json_encode($body['activities'] ?? []);

        $stmt = $db->prepare("REPLACE INTO extracurriculars (id, name, coachId, coachName, schedule, description, room, students, activities) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", $id, $name, $coachId, $coachName, $schedule, $description, $room, $students, $activities);
        
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            errorResponse('Gagal menyimpan ekstrakurikuler');
        }
        break;

    case 'delete_extracurricular':
        $id = $_GET['id'] ?? '';
        if (!$id) errorResponse('ID tidak valid');
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("DELETE FROM extracurricular_members WHERE extracurricular_id = ?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            
            $stmt = $db->prepare("DELETE FROM extracurricular_activities WHERE extracurricular_id = ?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            
            $stmt = $db->prepare("DELETE FROM extracurriculars WHERE id = ?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            
            $db->commit();
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            $db->rollback();
            errorResponse('Gagal menghapus ekskul');
        }
        break;

    // --- LESSON RPPS (LESSON KIT) ---
    case 'get_lesson_rpps':
        $res = $db->query("
            SELECT r.*, r.status as db_status, r.verified_by, r.verified_by_name, r.verified_at, g.nip, g.nuptk, g.nik
            FROM lesson_rpps r
            LEFT JOIN gtk_pegawai g ON r.authorId = g.id COLLATE utf8mb4_unicode_ci
            ORDER BY r.createdAt DESC
        ");
        $rpps = [];
        while ($row = $res->fetch_assoc()) {
            $fileUrl = $row['file_url'] ?? null;
            $nip = $row['nip'] ?? '';
            $nuptk = $row['nuptk'] ?? '';
            $nik = $row['nik'] ?? '';

            if (!empty($row['payload'])) {
                $payloadData = json_decode($row['payload'], true) ?: [];
                $merged = array_merge($row, $payloadData);
                // Ensure file_url and GTK identifiers from the DB column are always authoritative
                if ($fileUrl) $merged['fileUrl'] = $fileUrl;
                $merged['nip'] = $nip;
                $merged['nuptk'] = $nuptk;
                $merged['nik'] = $nik;
                // Merge status and verified details from DB
                $merged['status'] = $row['db_status'];
                $merged['verifiedBy'] = $row['verified_by'];
                $merged['verifiedByName'] = $row['verified_by_name'];
                $merged['verifiedAt'] = $row['verified_at'];
                $rpps[] = $merged;
            } else {
                $row['objectives'] = json_decode($row['objectives'], true) ?: [];
                $row['activities'] = json_decode($row['activities'], true) ?: [];
                $row['isCustom'] = (bool)$row['isCustom'];
                if ($fileUrl) $row['fileUrl'] = $fileUrl;
                $row['nip'] = $nip;
                $row['nuptk'] = $nuptk;
                $row['nik'] = $nik;
                $row['status'] = $row['db_status'];
                $row['verifiedBy'] = $row['verified_by'];
                $row['verifiedByName'] = $row['verified_by_name'];
                $row['verifiedAt'] = $row['verified_at'];
                $rpps[] = $row;
            }
        }
        jsonResponse($rpps);
        break;

    case 'save_lesson_rpp':
        $user = requireAuth();
        $body = getRequestBody();

        $id = $body['id'] ?? uniqid('rpp_');
        $title = $body['title'] ?? '';
        $subject = $body['subject'] ?? $body['description'] ?? '';
        $classLevel = $body['classSession'] ?? $body['classLevel'] ?? '';
        $topic = $body['topic'] ?? $body['description'] ?? $title;
        // Determine type: explicit field takes priority, then detect by payload keys
        $type = $body['type'] ?? (isset($body['capaian']) || isset($body['rows']) ? 'SILABUS' : 'RPP');
        $fileUrl = $body['fileUrl'] ?? null;

        $payloadJson = json_encode($body);
        $authorId = $user['uid'];
        $authorName = $user['displayName'];
        $isCustom = isset($body['isCustom']) ? (int)$body['isCustom'] : 1;
        $status = $body['status'] ?? 'PENDING';

        if ($fileUrl) {
            // When file_url is provided (second pass after upload), update that field
            $stmt = $db->prepare("INSERT INTO lesson_rpps (id, title, subject, classLevel, topic, objectives, activities, authorId, authorName, isCustom, type, payload, file_url, status) VALUES (?, ?, ?, ?, ?, '[]', '[]', ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), subject=VALUES(subject), classLevel=VALUES(classLevel), topic=VALUES(topic), authorId=VALUES(authorId), authorName=VALUES(authorName), isCustom=VALUES(isCustom), type=VALUES(type), payload=VALUES(payload), file_url=VALUES(file_url), status=VALUES(status)");
            $stmt->bind_param("sssssssissss", $id, $title, $subject, $classLevel, $topic, $authorId, $authorName, $isCustom, $type, $payloadJson, $fileUrl, $status);
        } else {
            $stmt = $db->prepare("INSERT INTO lesson_rpps (id, title, subject, classLevel, topic, objectives, activities, authorId, authorName, isCustom, type, payload, status) VALUES (?, ?, ?, ?, ?, '[]', '[]', ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), subject=VALUES(subject), classLevel=VALUES(classLevel), topic=VALUES(topic), authorId=VALUES(authorId), authorName=VALUES(authorName), isCustom=VALUES(isCustom), type=VALUES(type), payload=VALUES(payload), status=VALUES(status)");
            $stmt->bind_param("sssssssisss", $id, $title, $subject, $classLevel, $topic, $authorId, $authorName, $isCustom, $type, $payloadJson, $status);
        }

        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'id' => $id, 'newId' => $id]);
        } else {
            errorResponse('Gagal menyimpan Perangkat Ajar: ' . $db->error);
        }
        break;

    case 'delete_lesson_rpp':
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib disertakan');

        $stmt = $db->prepare("DELETE FROM lesson_rpps WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menghapus RPP');
        }
        break;

    case 'verify_lesson_rpp':
        $user = requireAuth();
        requireRole(['KEPALA_SEKOLAH', 'ADMIN']);
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        if (empty($id)) errorResponse('ID RPP wajib disertakan');

        $verifiedBy = $user['uid'];
        $verifiedByName = $user['displayName'];
        $verifiedAt = date('Y-m-d H:i:s');

        $stmt = $db->prepare("UPDATE lesson_rpps SET status = 'VERIFIED', verified_by = ?, verified_by_name = ?, verified_at = ? WHERE id = ?");
        $stmt->bind_param("ssss", $verifiedBy, $verifiedByName, $verifiedAt, $id);

        if ($stmt->execute()) {
            // Update status inside payload JSON for backward compatibility
            $resPayload = $db->prepare("SELECT payload FROM lesson_rpps WHERE id = ?");
            $resPayload->bind_param("s", $id);
            $resPayload->execute();
            $row = $resPayload->get_result()->fetch_assoc();
            if ($row && !empty($row['payload'])) {
                $payload = json_decode($row['payload'], true) ?: [];
                $payload['status'] = 'VERIFIED';
                $payload['verifiedBy'] = $verifiedBy;
                $payload['verifiedByName'] = $verifiedByName;
                $payload['verifiedAt'] = $verifiedAt;
                $payloadJson = json_encode($payload);
                $stmtUpdate = $db->prepare("UPDATE lesson_rpps SET payload = ? WHERE id = ?");
                $stmtUpdate->bind_param("ss", $payloadJson, $id);
                $stmtUpdate->execute();
            }
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            errorResponse('Gagal memverifikasi RPP: ' . $db->error);
        }
        break;

    // --- NOTIFICATIONS ---
    case 'get_notifications':
        $user = requireAuth();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->bind_param("s", $user['uid']);
        $stmt->execute();
        $res = $stmt->get_result();
        $notifications = [];
        while ($row = $res->fetch_assoc()) {
            $row['is_read'] = (bool)$row['is_read'];
            $notifications[] = $row;
        }
        jsonResponse($notifications);
        break;

    case 'mark_notification_read':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        if (empty($id)) errorResponse('ID Notifikasi wajib diisi');

        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ss", $id, $user['uid']);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menandai notifikasi dibaca');
        }
        break;

    case 'mark_all_notifications_read':
        $user = requireAuth();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param("s", $user['uid']);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menandai semua notifikasi dibaca');
        }
        break;



    // --- SUBJECTS (Master Mata Pelajaran) ---
    case 'get_subjects':
        $activeOnly = $_GET['active_only'] ?? '0';
        if ($activeOnly === '1') {
            $res = $db->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        } else {
            $res = $db->query("SELECT * FROM subjects ORDER BY sort_order ASC, name ASC");
        }
        $subjects = [];
        while ($row = $res->fetch_assoc()) {
            $row['is_active'] = (bool)$row['is_active'];
            $subjects[] = $row;
        }
        jsonResponse($subjects);
        break;

    case 'save_subject':
        requireRole(['ADMIN']);
        $body = getRequestBody();
        $id   = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? '');
        $category = trim($body['category'] ?? 'Umum');
        $description = trim($body['description'] ?? '');
        $isActive = isset($body['is_active']) ? (int)$body['is_active'] : 1;
        $sortOrder = (int)($body['sort_order'] ?? 0);

        if (empty($id) || empty($name)) errorResponse('ID dan Nama mata pelajaran wajib diisi');

        $stmt = $db->prepare("REPLACE INTO subjects (id, name, category, description, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssii", $id, $name, $category, $description, $isActive, $sortOrder);
        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            errorResponse('Gagal menyimpan mata pelajaran: ' . $db->error);
        }
        break;

    case 'delete_subject':
        requireRole(['ADMIN']);
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID mata pelajaran wajib disertakan');
        $stmt = $db->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menghapus mata pelajaran');
        }
        break;

    // --- TEACHER SUBJECTS (Guru ↔ Mata Pelajaran mapping) ---
    case 'get_teacher_subjects':
        $teacherId = $_GET['teacher_id'] ?? '';
        if (!empty($teacherId)) {
            $stmt = $db->prepare("SELECT ts.*, s.name as subject_name_full, s.category FROM teacher_subjects ts LEFT JOIN subjects s ON ts.subject_code COLLATE utf8mb4_unicode_ci = s.id COLLATE utf8mb4_unicode_ci WHERE ts.teacher_id = ? ORDER BY ts.subject_name ASC");
            $stmt->bind_param("s", $teacherId);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $db->query("SELECT ts.*, s.name as subject_name_full, s.category FROM teacher_subjects ts LEFT JOIN subjects s ON ts.subject_code COLLATE utf8mb4_unicode_ci = s.id COLLATE utf8mb4_unicode_ci ORDER BY ts.teacher_id, ts.subject_name ASC");
        }
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $row['class_ids'] = json_decode($row['class_ids'] ?? '[]', true) ?: [];
            $list[] = $row;
        }
        jsonResponse($list);
        break;

    case 'save_teacher_subjects':
        requireRole(['ADMIN', 'KEPALA_SEKOLAH']);
        $body = getRequestBody();
        $teacherId   = trim($body['teacher_id'] ?? '');
        $subjectCode = trim($body['subject_code'] ?? '');
        $subjectName = trim($body['subject_name'] ?? '');
        $classLevel  = trim($body['class_level'] ?? '');
        $classIds    = json_encode($body['class_ids'] ?? []);

        if (empty($teacherId) || empty($subjectCode)) errorResponse('teacher_id dan subject_code wajib diisi');

        $stmt = $db->prepare("REPLACE INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $teacherId, $subjectCode, $subjectName, $classLevel, $classIds);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menyimpan penugasan mapel guru: ' . $db->error);
        }
        break;

    case 'save_teacher_subjects_bulk':
        $user = requireAuth();
        $body = getRequestBody();
        $teacherId = trim($body['teacher_id'] ?? '');
        $subjects  = $body['subjects'] ?? [];

        if (empty($teacherId)) errorResponse('teacher_id wajib diisi');
        
        if ($user['role'] === 'GURU' && $user['uid'] !== $teacherId) {
            errorResponse('Anda hanya dapat mengubah penugasan mata pelajaran milik sendiri');
        }

        // Delete existing assignments for this teacher first
        $db->begin_transaction();
        try {
            $delStmt = $db->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
            if (!$delStmt) throw new Exception("Prepare delete failed: " . $db->error);
            $delStmt->bind_param("s", $teacherId);
            if (!$delStmt->execute()) throw new Exception("Execute delete failed: " . $delStmt->error);

            // Re-insert all subjects
            if (!empty($subjects)) {
                $insStmt = $db->prepare("INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES (?, ?, ?, ?, ?)");
                if (!$insStmt) {
                    throw new Exception("Prepare insert failed. Pastikan kolom class_ids sudah ada di tabel teacher_subjects. Error: " . $db->error);
                }
                
                foreach ($subjects as $sub) {
                    $subCode  = trim($sub['subject_code'] ?? $sub['code'] ?? '');
                    $subName  = trim($sub['subject_name'] ?? $sub['name'] ?? '');
                    $classLvl = trim($sub['class_level'] ?? '');
                    $classIds = json_encode($sub['class_ids'] ?? []);
                    if (empty($subCode)) continue;
                    
                    $insStmt->bind_param("sssss", $teacherId, $subCode, $subName, $classLvl, $classIds);
                    if (!$insStmt->execute()) throw new Exception("Execute insert failed: " . $insStmt->error);
                }
            }
            $db->commit();

            // === TWO-WAY SYNC: teacher_subjects -> gtk_pegawai ===
            // Aggregate all mapel names and class ids from newly saved subjects
            $allMapelNames = [];
            $allClassIds   = [];
            foreach ($subjects as $sub) {
                $sn = trim($sub['subject_name'] ?? $sub['name'] ?? '');
                if (!empty($sn)) $allMapelNames[] = $sn;
                $ids = $sub['class_ids'] ?? [];
                if (is_array($ids)) {
                    foreach ($ids as $cid) {
                        $cid = str_replace('_', ' ', trim($cid));
                        if (!empty($cid) && !in_array($cid, $allClassIds)) {
                            $allClassIds[] = $cid;
                        }
                    }
                }
            }
            $syncMapel = implode(', ', array_unique($allMapelNames));
            $syncKelas = implode(', ', array_unique($allClassIds));

            // Update gtk_pegawai where id = teacherId (after GTK/user decoupling, id == uid)
            if (!empty($syncMapel) || !empty($syncKelas)) {
                $upGTK = $db->prepare("UPDATE gtk_pegawai SET mapel=?, kelas_ajar=? WHERE id=?");
                if ($upGTK) {
                    $upGTK->bind_param("sss", $syncMapel, $syncKelas, $teacherId);
                    $upGTK->execute();
                }
            }

            jsonResponse(['success' => true, 'count' => count($subjects)]);
        } catch (Exception $e) {
            $db->rollback();
            errorResponse('Gagal menyimpan penugasan mata pelajaran: ' . $e->getMessage());
        }
        break;

    case 'delete_teacher_subject':
        requireRole(['ADMIN', 'KEPALA_SEKOLAH']);
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib disertakan');
        $stmt = $db->prepare("DELETE FROM teacher_subjects WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menghapus penugasan mapel guru');
        }
        break;

    // --- SYSTEM CONFIG (Kop Surat & pengaturan lain) ---
    case 'get_system_config':
        $res = $db->query("SELECT config_key, config_value, description FROM system_config");
        $config = [];
        while ($row = $res->fetch_assoc()) {
            $config[$row['config_key']] = [
                'value'       => $row['config_value'],
                'description' => $row['description']
            ];
        }
        jsonResponse($config);
        break;

    case 'save_system_config':
        requireRole(['ADMIN']);
        $body = getRequestBody();
        // $body is expected to be an array of {key, value} pairs or a flat key-value object
        $updates = $body['configs'] ?? $body; // Support both formats

        if (!is_array($updates)) errorResponse('Format data tidak valid');

        $db->begin_transaction();
        try {
            foreach ($updates as $key => $value) {
                if (empty($key) || is_array($value)) continue;
                $valStr = (string)$value;
                $stmt = $db->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $stmt->bind_param("ss", $key, $valStr);
                $stmt->execute();
            }
            $db->commit();
            jsonResponse(['success' => true]);
        } catch (Exception $e) {
            $db->rollback();
            errorResponse('Gagal menyimpan konfigurasi: ' . $e->getMessage());
        }
        break;

    // --- STUDENT GRADES (Rekap nilai dari jurnal) ---
    case 'get_student_grades':
        // Aggregates assessment_data from journal_entries for a class+subject+semester
        $user = requireAuth();
        $classId      = $_GET['class_id']      ?? '';
        $subjectId    = $_GET['subject_id']     ?? '';
        $academicYear = $_GET['academic_year']  ?? '2025/2026';
        $semester     = $_GET['semester']       ?? 'GANJIL';

        if (empty($classId) || empty($subjectId)) errorResponse('class_id dan subject_id wajib diisi');

        // Fetch all journals for this class+subject that have assessment_data
        $stmt = $db->prepare("
            SELECT id, date, topic, assessment_data, has_assessment
            FROM journal_entries
            WHERE classId = ? AND subjectId = ? AND has_assessment = 1 AND isDraft = 0
            ORDER BY date ASC
        ");
        $stmt->bind_param("ss", $classId, $subjectId);
        $stmt->execute();
        $res = $stmt->get_result();

        $journalAssessments = []; // [{journal_id, date, topic, students: [{id, name, tugas, kuis, notes}]}]
        $studentMap = []; // student_id => {name, tugas_scores:[], kuis_scores:[]}

        while ($row = $res->fetch_assoc()) {
            $assessData = json_decode($row['assessment_data'], true) ?: [];
            $journalEntry = [
                'journal_id' => $row['id'],
                'date'       => $row['date'],
                'topic'      => $row['topic'],
                'students'   => $assessData
            ];
            $journalAssessments[] = $journalEntry;

            foreach ($assessData as $sa) {
                $sid = $sa['student_id'] ?? '';
                if (empty($sid)) continue;
                if (!isset($studentMap[$sid])) {
                    $studentMap[$sid] = [
                        'student_id'   => $sid,
                        'student_name' => $sa['student_name'] ?? 'Unknown',
                        'tugas_scores' => [],
                        'kuis_scores'  => [],
                    ];
                }
                if (isset($sa['tugas_score']) && $sa['tugas_score'] !== null && $sa['tugas_score'] !== '') {
                    $studentMap[$sid]['tugas_scores'][] = (float)$sa['tugas_score'];
                }
                if (isset($sa['kuis_score']) && $sa['kuis_score'] !== null && $sa['kuis_score'] !== '') {
                    $studentMap[$sid]['kuis_scores'][] = (float)$sa['kuis_score'];
                }
            }
        }

        // Fetch ujian scores for this class+subject+semester
        $stmtUjian = $db->prepare("
            SELECT student_id, student_name, ujian_type, score
            FROM ujian_scores
            WHERE class_id = ? AND subject_id = ? AND academic_year = ? AND semester = ?
        ");
        $stmtUjian->bind_param("ssss", $classId, $subjectId, $academicYear, $semester);
        $stmtUjian->execute();
        $ujianRes = $stmtUjian->get_result();
        $ujianMap = []; // student_id => {UTS: x, UAS: y}
        while ($u = $ujianRes->fetch_assoc()) {
            $sid = $u['student_id'];
            if (!isset($ujianMap[$sid])) $ujianMap[$sid] = [];
            $ujianMap[$sid][$u['ujian_type']] = (float)$u['score'];
        }

        // Get system config for weights
        $resW = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('bobot_tugas','bobot_kuis','bobot_ujian','kkm_default')");
        $weights = ['bobot_tugas' => 30, 'bobot_kuis' => 30, 'bobot_ujian' => 40, 'kkm_default' => 75];
        while ($w = $resW->fetch_assoc()) {
            $weights[$w['config_key']] = (int)$w['config_value'];
        }

        // Build summary per student
        $studentIds = array_keys($studentMap);
        $studentPhotos = [];
        if (!empty($studentIds)) {
            $inClause = implode(',', array_fill(0, count($studentIds), '?'));
            $stmtPhotos = $db->prepare("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')) AS pas_foto FROM students WHERE id IN ($inClause)");
            $types = str_repeat('s', count($studentIds));
            $stmtPhotos->bind_param($types, ...$studentIds);
            $stmtPhotos->execute();
            $resPhotos = $stmtPhotos->get_result();
            while ($p = $resPhotos->fetch_assoc()) {
                $studentPhotos[$p['id']] = $p['pas_foto'];
            }
        }

        $summaries = [];
        foreach ($studentMap as $sid => $sData) {
            $avgTugas = count($sData['tugas_scores']) > 0
                ? array_sum($sData['tugas_scores']) / count($sData['tugas_scores'])
                : null;
            $avgKuis = count($sData['kuis_scores']) > 0
                ? array_sum($sData['kuis_scores']) / count($sData['kuis_scores'])
                : null;

            $ujianUTS   = $ujianMap[$sid]['UTS'] ?? null;
            $ujianUAS   = $ujianMap[$sid]['UAS'] ?? $ujianMap[$sid]['SUMATIF_AKHIR'] ?? null;

            // Nilai Ujian = rata-rata (UTS + UAS) / 2 jika keduanya ada
            // Jika hanya satu yang ada, gunakan yang tersedia
            if ($ujianUTS !== null && $ujianUAS !== null) {
                $ujianCombined = ($ujianUTS + $ujianUAS) / 2;
            } elseif ($ujianUAS !== null) {
                $ujianCombined = $ujianUAS;
            } elseif ($ujianUTS !== null) {
                $ujianCombined = $ujianUTS;
            } else {
                $ujianCombined = null;
            }

            // Compute final only if we have at least ujian score
            $finalScore = null;
            if ($ujianCombined !== null) {
                $wt = $weights['bobot_tugas'] / 100;
                $wk = $weights['bobot_kuis'] / 100;
                $wu = $weights['bobot_ujian'] / 100;
                $finalScore = round(
                    (($avgTugas ?? 0) * $wt) + (($avgKuis ?? 0) * $wk) + ($ujianCombined * $wu),
                    1
                );
            }

            $summaries[] = [
                'student_id'      => $sid,
                'student_name'    => $sData['student_name'],
                'pas_foto'        => $studentPhotos[$sid] ?? null,
                'avg_tugas'       => $avgTugas !== null ? round($avgTugas, 1) : null,
                'avg_kuis'        => $avgKuis  !== null ? round($avgKuis,  1) : null,
                'tugas_count'     => count($sData['tugas_scores']),
                'kuis_count'      => count($sData['kuis_scores']),
                'ujian_uts'       => $ujianUTS,
                'ujian_uas'       => $ujianUAS,
                'ujian_combined'  => $ujianCombined !== null ? round($ujianCombined, 1) : null,
                'final_score'     => $finalScore,
                'kkm'             => $weights['kkm_default'],
                'status'          => $finalScore !== null ? ($finalScore >= $weights['kkm_default'] ? 'TUNTAS' : 'REMEDI') : 'BELUM_LENGKAP',
            ];
        }

        jsonResponse([
            'summaries'           => $summaries,
            'journal_assessments' => $journalAssessments,
            'weights'             => $weights,
            'total_meetings'      => count($journalAssessments),
        ]);
        break;

    // --- DAILY GRADES: Nilai dari satu pertemuan (satu tanggal) ---
    case 'get_daily_grades':
        requireAuth();
        $classId   = $_GET['class_id']   ?? '';
        $subjectId = $_GET['subject_id'] ?? '';
        $dateParam = $_GET['date']        ?? '';

        if (empty($classId) || empty($subjectId) || empty($dateParam)) {
            errorResponse('class_id, subject_id, dan date wajib diisi');
        }

        $stmt = $db->prepare("
            SELECT id, date, topic, assessment_data, studentAttendance
            FROM journal_entries
            WHERE classId = ? AND subjectId = ? AND date = ? AND isDraft = 0
            LIMIT 1
        ");
        $stmt->bind_param("sss", $classId, $subjectId, $dateParam);
        $stmt->execute();
        $res = $stmt->get_result();
        $journal = $res->fetch_assoc();

        if (!$journal) {
            jsonResponse(['journal_found' => false, 'date' => $dateParam, 'students' => [], 'topic' => '']);
            break;
        }

        $assessData   = json_decode($journal['assessment_data'] ?? '[]', true) ?: [];
        $attendData   = json_decode($journal['studentAttendance'] ?? '[]', true) ?: [];

        // Build attendance lookup: studentId => status
        $attendMap = [];
        foreach ($attendData as $a) {
            $sid = $a['studentId'] ?? $a['student_id'] ?? '';
            if ($sid) $attendMap[$sid] = $a['status'] ?? 'HADIR';
        }

        // Get pas_foto for students in this journal
        $studentIds = array_filter(array_column($assessData, 'student_id'));
        $photoMap = [];
        if (!empty($studentIds)) {
            $inClause = implode(',', array_fill(0, count($studentIds), '?'));
            $stmtP = $db->prepare("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')) AS pas_foto FROM students WHERE id IN ($inClause)");
            $types = str_repeat('s', count($studentIds));
            $stmtP->bind_param($types, ...$studentIds);
            $stmtP->execute();
            $resP = $stmtP->get_result();
            while ($p = $resP->fetch_assoc()) {
                $photoMap[$p['id']] = $p['pas_foto'];
            }
        }

        $students = array_map(function($sa) use ($attendMap, $photoMap) {
            $sid = $sa['student_id'] ?? '';
            return [
                'student_id'        => $sid,
                'student_name'      => $sa['student_name'] ?? 'Unknown',
                'pas_foto'          => $photoMap[$sid] ?? null,
                'tugas_score'       => isset($sa['tugas_score']) && $sa['tugas_score'] !== '' ? (float)$sa['tugas_score'] : null,
                'kuis_score'        => isset($sa['kuis_score'])  && $sa['kuis_score']  !== '' ? (float)$sa['kuis_score']  : null,
                'attendance_status' => $attendMap[$sid] ?? 'HADIR',
                'notes'             => $sa['notes'] ?? '',
            ];
        }, $assessData);

        jsonResponse([
            'journal_found' => true,
            'date'          => $journal['date'],
            'topic'         => $journal['topic'] ?? '',
            'students'      => $students,
        ]);
        break;

    // --- MONTHLY GRADES: Agregasi nilai per bulan ---
    case 'get_monthly_grades':
        requireAuth();
        $classId    = $_GET['class_id']   ?? '';
        $subjectId  = $_GET['subject_id'] ?? '';
        $monthParam = $_GET['month']       ?? ''; // Format: YYYY-MM

        if (empty($classId) || empty($subjectId) || empty($monthParam)) {
            errorResponse('class_id, subject_id, dan month wajib diisi');
        }

        $stmt = $db->prepare("
            SELECT id, date, topic, assessment_data
            FROM journal_entries
            WHERE classId = ? AND subjectId = ?
              AND DATE_FORMAT(date, '%Y-%m') = ?
              AND has_assessment = 1 AND isDraft = 0
            ORDER BY date ASC
        ");
        $stmt->bind_param("sss", $classId, $subjectId, $monthParam);
        $stmt->execute();
        $res = $stmt->get_result();

        $studentMap = [];
        $meetingCount = 0;
        while ($row = $res->fetch_assoc()) {
            $meetingCount++;
            $assessData = json_decode($row['assessment_data'], true) ?: [];
            foreach ($assessData as $sa) {
                $sid = $sa['student_id'] ?? '';
                if (empty($sid)) continue;
                if (!isset($studentMap[$sid])) {
                    $studentMap[$sid] = [
                        'student_id'   => $sid,
                        'student_name' => $sa['student_name'] ?? 'Unknown',
                        'tugas_scores' => [],
                        'kuis_scores'  => [],
                    ];
                }
                if (isset($sa['tugas_score']) && $sa['tugas_score'] !== null && $sa['tugas_score'] !== '') {
                    $studentMap[$sid]['tugas_scores'][] = (float)$sa['tugas_score'];
                }
                if (isset($sa['kuis_score']) && $sa['kuis_score'] !== null && $sa['kuis_score'] !== '') {
                    $studentMap[$sid]['kuis_scores'][] = (float)$sa['kuis_score'];
                }
            }
        }

        // Get pas_foto
        $studentIds = array_keys($studentMap);
        $photoMap = [];
        if (!empty($studentIds)) {
            $inClause = implode(',', array_fill(0, count($studentIds), '?'));
            $stmtP = $db->prepare("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(docs_json, '$.pas_foto')) AS pas_foto FROM students WHERE id IN ($inClause)");
            $types = str_repeat('s', count($studentIds));
            $stmtP->bind_param($types, ...$studentIds);
            $stmtP->execute();
            $resP = $stmtP->get_result();
            while ($p = $resP->fetch_assoc()) {
                $photoMap[$p['id']] = $p['pas_foto'];
            }
        }

        $summaries = [];
        foreach ($studentMap as $sid => $sData) {
            $avgTugas = count($sData['tugas_scores']) > 0
                ? round(array_sum($sData['tugas_scores']) / count($sData['tugas_scores']), 1)
                : null;
            $avgKuis = count($sData['kuis_scores']) > 0
                ? round(array_sum($sData['kuis_scores']) / count($sData['kuis_scores']), 1)
                : null;
            $summaries[] = [
                'student_id'   => $sid,
                'student_name' => $sData['student_name'],
                'pas_foto'     => $photoMap[$sid] ?? null,
                'avg_tugas'    => $avgTugas,
                'avg_kuis'     => $avgKuis,
                'tugas_count'  => count($sData['tugas_scores']),
                'kuis_count'   => count($sData['kuis_scores']),
            ];
        }

        jsonResponse([
            'summaries'      => $summaries,
            'month'          => $monthParam,
            'meeting_count'  => $meetingCount,
            'has_data'       => !empty($summaries),
        ]);
        break;

    // --- UJIAN SCORES ---

    case 'save_ujian_score':
        $user = requireAuth();
        $body = getRequestBody();
        // Support bulk save: scores is array of {student_id, student_name, score}
        $classId      = $body['class_id']      ?? '';
        $subjectId    = $body['subject_id']     ?? '';
        $academicYear = $body['academic_year']  ?? '2025/2026';
        $semester     = $body['semester']       ?? 'GANJIL';
        $ujianType    = $body['ujian_type']     ?? 'UAS';
        $scores       = $body['scores']         ?? [];

        if (empty($classId) || empty($subjectId) || empty($scores)) {
            errorResponse('class_id, subject_id, dan scores wajib diisi');
        }

        $db->begin_transaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO ujian_scores (student_id, student_name, class_id, subject_id, academic_year, semester, ujian_type, score, recorded_by, recorder_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = VALUES(recorded_by), recorder_name = VALUES(recorder_name)
            ");
            foreach ($scores as $s) {
                $sid    = $s['student_id']   ?? '';
                $sname  = $s['student_name'] ?? '';
                $score  = isset($s['score']) && $s['score'] !== '' ? (float)$s['score'] : null;
                if (empty($sid)) continue;
                $stmt->bind_param("sssssssdss",
                    $sid, $sname, $classId, $subjectId, $academicYear, $semester, $ujianType, $score, $user['uid'], $user['displayName']
                );
                $stmt->execute();
            }
            $db->commit();
            jsonResponse(['success' => true, 'saved' => count($scores)]);
        } catch (Exception $e) {
            $db->rollback();
            errorResponse('Gagal menyimpan nilai ujian: ' . $e->getMessage());
        }
        break;

    case 'get_ujian_scores':
        $user = requireAuth();
        $classId      = $_GET['class_id']      ?? '';
        $subjectId    = $_GET['subject_id']     ?? '';
        $academicYear = $_GET['academic_year']  ?? '2025/2026';
        $semester     = $_GET['semester']       ?? 'GANJIL';

        if (empty($classId) || empty($subjectId)) errorResponse('class_id dan subject_id wajib diisi');

        $stmt = $db->prepare("SELECT * FROM ujian_scores WHERE class_id = ? AND subject_id = ? AND academic_year = ? AND semester = ? ORDER BY student_name ASC");
        $stmt->bind_param("ssss", $classId, $subjectId, $academicYear, $semester);
        $stmt->execute();
        $res = $stmt->get_result();
        $scores = [];
        while ($row = $res->fetch_assoc()) {
            $row['score'] = $row['score'] !== null ? (float)$row['score'] : null;
            $scores[] = $row;
        }
        jsonResponse($scores);
        break;

    // --- VERIFY JOURNAL ---
    case 'verify_journal':
        $user = requireAuth();
        if (!in_array($user['role'], ['KEPALA_SEKOLAH', 'ADMIN'])) {
            errorResponse('Akses ditolak');
        }
        $body = getRequestBody();
        $journalId = $body['id'] ?? $_GET['id'] ?? '';
        if (empty($journalId)) errorResponse('Journal ID wajib diisi');

        $verifiedAt = date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE journal_entries SET isVerified = 1, verifiedBy = ?, verifiedAt = ? WHERE id = ?");
        $stmt->bind_param("sss", $user['uid'], $verifiedAt, $journalId);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal memverifikasi jurnal');
        }
        break;

    // --- PROFILE ---
    case 'update_profile':
        $user = requireAuth();
        $body = getRequestBody();
        $uid = $user['uid'];
        $displayName = trim($body['displayName'] ?? '');
        $nip         = trim($body['nip']         ?? '');
        $schoolName  = trim($body['schoolName']  ?? '');

        if (empty($displayName)) errorResponse('Nama lengkap wajib diisi');

        $stmt = $db->prepare("UPDATE users SET displayName = ?, nip = ?, schoolName = ? WHERE uid = ?");
        $stmt->bind_param("ssss", $displayName, $nip, $schoolName, $uid);
        if ($stmt->execute()) {
            // Refresh session
            $_SESSION['user']['displayName'] = $displayName;
            $_SESSION['user']['nip']         = $nip;
            $_SESSION['user']['schoolName']  = $schoolName;
            // Also update teacher_registry if GURU
            if ($user['role'] === 'GURU') {
                $regStmt = $db->prepare("UPDATE teacher_registry SET fullName = ?, nip = ?, schoolName = ? WHERE email = ?");
                $regStmt->bind_param("ssss", $displayName, $nip, $schoolName, $user['email']);
                $regStmt->execute();
            }
            jsonResponse(['success' => true, 'user' => $_SESSION['user']]);
        } else {
            errorResponse('Gagal memperbarui profil: ' . $db->error);
        }
        break;

    case 'change_password':
        $user = requireAuth();
        $body = getRequestBody();
        $uid         = $user['uid'];
        $oldPassword = $body['oldPassword'] ?? '';
        $newPassword = $body['newPassword'] ?? '';

        if (empty($oldPassword) || empty($newPassword)) errorResponse('Password lama dan baru wajib diisi');
        if (strlen($newPassword) < 6) errorResponse('Password baru minimal 6 karakter');

        // Verify old password
        $stmt = $db->prepare("SELECT password FROM users WHERE uid = ?");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !password_verify($oldPassword, $row['password'])) {
            errorResponse('Password lama tidak sesuai');
        }

        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt2 = $db->prepare("UPDATE users SET password = ? WHERE uid = ?");
        $stmt2->bind_param("ss", $hashed, $uid);
        if ($stmt2->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal mengubah password: ' . $db->error);
        }
        break;

    case 'upload_profile_photo':
        $user = requireAuth();
        if (!isset($_FILES['photo'])) {
            errorResponse('File foto tidak ditemukan');
        }

        $file = $_FILES['photo'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $ftype = mime_content_type($file['tmp_name']);
        if (!in_array($ftype, $allowedMimes)) {
            errorResponse('Hanya menerima format JPG, PNG, atau WebP');
        }
        if ($file['size'] > 3 * 1024 * 1024) {
            errorResponse('Ukuran foto maksimal 3MB');
        }

        // Cari GTK ID berdasarkan email atau UID user saat ini (case-insensitive)
        $stmtGtk = $db->prepare("SELECT id FROM gtk_pegawai WHERE LOWER(email) = LOWER(?) OR id = ? LIMIT 1");
        $stmtGtk->bind_param("ss", $user['email'], $user['uid']);
        $stmtGtk->execute();
        $resGtk = $stmtGtk->get_result();
        $gtkRow = $resGtk->fetch_assoc();

        // Jika record GTK belum ada (misal akun baru atau data belum dimigrasikan ke produksi),
        // buat record minimal agar upload foto tetap bisa berjalan.
        if (!$gtkRow) {
            $gtkIdNew   = $user['uid'];  // Gunakan uid sebagai GTK id (standar sistem)
            $namaNew    = $user['displayName'] ?? 'Pengguna';
            $nipNew     = $user['nip'] ?? '';
            $emailNew   = $user['email'] ?? '';
            $schoolNew  = $user['schoolName'] ?? '';
            $roleNew    = $user['role'] ?? 'GURU';

            // Tentukan kategori pekerjaan berdasarkan role
            $katNew = 'Tenaga Pendidik';
            if ($roleNew === 'ADMIN') $katNew = 'Tenaga Kependidikan';
            elseif ($roleNew === 'KEPALA_SEKOLAH') $katNew = 'Tenaga Pendidik';
            elseif ($roleNew === 'TATA_USAHA') $katNew = 'Tenaga Kependidikan';

            $insGtk = $db->prepare(
                "INSERT IGNORE INTO gtk_pegawai (id, nama, nip, email, sekolah, pekerjaan_kategori, docs_json)
                 VALUES (?, ?, ?, ?, ?, ?, '{}')"
            );
            $insGtk->bind_param("ssssss", $gtkIdNew, $namaNew, $nipNew, $emailNew, $schoolNew, $katNew);
            $insGtk->execute();

            // Ambil ulang — jika INSERT IGNORE berhasil, id sekarang ada
            $stmtGtk2 = $db->prepare("SELECT id FROM gtk_pegawai WHERE id = ? LIMIT 1");
            $stmtGtk2->bind_param("s", $gtkIdNew);
            $stmtGtk2->execute();
            $gtkRow = $stmtGtk2->get_result()->fetch_assoc();

            // Jika masih gagal (misalnya constraint), tolak dengan pesan yang lebih informatif
            if (!$gtkRow) {
                errorResponse('Gagal membuat profil GTK otomatis. Silakan hubungi administrator.');
            }
        }

        $gtkId = $gtkRow['id'];

        // Hapus foto lama dari disk & DB (gtk_files)
        $oldRes = $db->prepare("SELECT path_file FROM gtk_files WHERE gtk_id=? AND kategori='pas_foto' ORDER BY id DESC LIMIT 1");
        $oldRes->bind_param("s", $gtkId);
        $oldRes->execute();
        $oldRow = $oldRes->get_result()->fetch_assoc();
        if ($oldRow && !empty($oldRow['path_file'])) {
            $absOld = __DIR__ . '/../' . $oldRow['path_file'];
            if (file_exists($absOld)) @unlink($absOld);
        }
        $db->query("DELETE FROM gtk_files WHERE gtk_id='" . $db->real_escape_string($gtkId) . "' AND kategori='pas_foto'");

        // Simpan file baru
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'pas_foto_' . time() . '.' . $ext;
        $safeRecordId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $gtkId);
        $uploadDir = __DIR__ . '/../uploads/tu/gtk/' . $safeRecordId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filePath = $uploadDir . $filename;
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Path relatif terhadap root deploy (frontend me-resolve via asset())
            $webPath = 'uploads/tu/gtk/' . $safeRecordId . '/' . $filename;

            // Catat di gtk_files
            $size = round($file['size'] / 1024) . ' KB';
            $tgl = date('Y-m-d');
            $stmt = $db->prepare("INSERT INTO gtk_files (gtk_id, kategori, nama_file, path_file, ukuran, tanggal_upload, uploaded_by) VALUES (?, 'pas_foto', ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $gtkId, $file['name'], $webPath, $size, $tgl, $user['uid']);
            $stmt->execute();

            // Update docs_json di gtk_pegawai
            $curRes = $db->prepare("SELECT docs_json FROM gtk_pegawai WHERE id=?");
            $curRes->bind_param("s", $gtkId);
            $curRes->execute();
            $curRow = $curRes->get_result()->fetch_assoc();
            $existing = json_decode($curRow['docs_json'] ?? '{}', true) ?: [];
            $existing['pas_foto'] = $webPath;
            $newJson = json_encode($existing);

            $upd = $db->prepare("UPDATE gtk_pegawai SET docs_json=? WHERE id=?");
            $upd->bind_param("ss", $newJson, $gtkId);
            $upd->execute();

            // Refresh session
            $_SESSION['user']['photoURL'] = $webPath;

            jsonResponse(['success' => true, 'photoURL' => $webPath]);
        } else {
            errorResponse('Gagal mengupload file foto');
        }
        break;

    case 'upload_logo':
        requireRole(['ADMIN']);
        // Handle multipart/form-data upload for logo files
        $position = $_POST['position'] ?? 'kiri'; // 'kiri' or 'kanan'
        if (!in_array($position, ['kiri', 'kanan'])) {
            errorResponse('Posisi logo tidak valid');
        }

        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['logo']['error'] ?? 'no_file';
            errorResponse('File logo tidak valid atau tidak diunggah (error: ' . $errCode . ')');
        }

        $file = $_FILES['logo'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        
        $mimeType = false;
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = @finfo_file($finfo, $file['tmp_name']);
            }
        }
        if (!$mimeType && function_exists('mime_content_type')) {
            $mimeType = @mime_content_type($file['tmp_name']);
        }
        if (!$mimeType) {
            $mimeType = $file['type'];
        }

        if (!in_array($mimeType, $allowedTypes)) {
            errorResponse('Tipe file tidak diizinkan. Gunakan JPG, PNG, GIF, WebP, atau SVG.');
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            errorResponse('Ukuran file maksimal 2MB');
        }

        // Build upload directory
        $uploadDir = __DIR__ . '/../uploads/logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . $position . '_' . time() . '.' . strtolower($ext);
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            errorResponse('Gagal memindahkan file logo ke server');
        }

        // Path relatif terhadap root deploy (frontend me-resolve via asset())
        $webPath = 'uploads/logos/' . $filename;

        // Save config key
        $configKey = 'kop_logo_' . $position;
        $stmt = $db->prepare("INSERT INTO system_config (config_key, config_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
        $desc = 'URL logo ' . $position . ' kop surat';
        $stmt->bind_param("sss", $configKey, $webPath, $desc);
        $stmt->execute();

        jsonResponse(['success' => true, 'url' => $webPath, 'filename' => $filename]);
        break;

    default:

        $handled = require __DIR__ . '/endpoints_v2.php';
        if (!$handled) {
            $handled = require __DIR__ . '/endpoints_v3.php';
        }
        if (!$handled) {
            $handled = require __DIR__ . '/endpoints_tu.php';
        }
        if (!$handled) {
            errorResponse('Endpoint API tidak ditemukan');
        }
        break;
}
