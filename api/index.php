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
require_once __DIR__ . '/ekskul_access.php';
require_once __DIR__ . '/lesson_access.php';
require_once __DIR__ . '/teacher_subject_access.php';
require_once __DIR__ . '/rekap_bulanan.php';
require_once __DIR__ . '/pantau_nilai.php';
require_once __DIR__ . '/portal_nilai.php';
require_once __DIR__ . '/persetujuan_jurnal.php';

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

            // Nama kelas perwalian untuk tampilan (class_homeroom tetap id kelas)
            $user['class_homeroom_name'] = !empty($user['class_homeroom']) ? resolveClassRef($db, $user['class_homeroom'])['name'] : '';
            // Penanda menu Ekstrakurikuler (pembina / wali kelas terkait)
            $user = array_merge($user, ekskulMenuCounts($db, $user));

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
            // Nama kelas perwalian untuk tampilan (class_homeroom tetap id kelas)
            $user['class_homeroom_name'] = !empty($user['class_homeroom']) ? resolveClassRef($db, $user['class_homeroom'])['name'] : '';
            // Dihitung ulang dari DB agar menu ekskul mengikuti penugasan terbaru saat halaman dimuat
            $user = array_merge($user, ekskulMenuCounts($db, $user));
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
            // Pembina ekskul wajib akun GURU
            if ($role !== 'GURU') clearCoachAssignment($db, $uid);
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
            // Kosongkan penugasan pembina ekskul milik akun ini
            clearCoachAssignment($db, $uid);

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
        // NIP guru diambil dari users, cadangan dari gtk_pegawai (gtk_pegawai.id = users.uid)
        $selJournal = "SELECT j.*, COALESCE(NULLIF(u.nip, ''), NULLIF(g.nip, ''), '') AS teacherNip"
            . " FROM journal_entries j"
            . " LEFT JOIN users u ON u.uid = j.teacherId"
            . " LEFT JOIN gtk_pegawai g ON g.id = j.teacherId";
        if ($user['role'] === 'GURU') {
            $stmt = $db->prepare($selJournal . " WHERE j.teacherId = ? ORDER BY j.date DESC, j.createdAt DESC");
            $stmt->bind_param("s", $user['uid']);
        } else {
            // ADMIN / KEPALA_SEKOLAH see all
            $stmt = $db->prepare($selJournal . " ORDER BY j.date DESC, j.createdAt DESC");
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
            $row['teacherNip']         = $row['teacherNip'] ?? '';
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

        // Cek kepemilikan dan kunci verifikasi sebelum transaksi, agar presensi/insiden
        // tidak ikut tertulis saat ditolak. Selain ADMIN/KEPALA_SEKOLAH hanya boleh mengubah
        // jurnal miliknya sendiri yang belum diverifikasi.
        $stmtOld = $db->prepare("SELECT teacherId, isVerified, verifiedBy, verifiedAt FROM journal_entries WHERE id = ?");
        $stmtOld->bind_param("s", $id);
        $stmtOld->execute();
        $existing = $stmtOld->get_result()->fetch_assoc();
        $isPimpinan = in_array($user['role'], ['ADMIN', 'KEPALA_SEKOLAH']);
        if ($existing && !$isPimpinan) {
            if ($existing['teacherId'] !== $user['uid']) {
                errorResponse('Anda tidak berhak mengubah jurnal guru lain', 403);
            }
            if ((int)$existing['isVerified'] === 1) {
                errorResponse('Jurnal sudah diverifikasi dan tidak dapat diubah', 403);
            }
        }

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
        // Status verifikasi tidak pernah diambil dari body: jurnal baru selalu belum
        // terverifikasi, edit mempertahankan status lama.
        $isVerified  = $existing ? (int)$existing['isVerified'] : 0;
        $verifiedBy  = $existing ? $existing['verifiedBy'] : null;
        $verifiedAt  = $existing ? $existing['verifiedAt'] : null;
        if ($isVerified) $isDraft = 0;
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

        // Selain ADMIN/KEPALA_SEKOLAH hanya boleh menghapus jurnal miliknya sendiri
        // yang belum diverifikasi.
        if (!in_array($user['role'], ['ADMIN', 'KEPALA_SEKOLAH'])) {
            $stmtOwner = $db->prepare("SELECT teacherId, isVerified FROM journal_entries WHERE id = ?");
            $stmtOwner->bind_param("s", $id);
            $stmtOwner->execute();
            $existing = $stmtOwner->get_result()->fetch_assoc();
            if ($existing && $existing['teacherId'] !== $user['uid']) {
                errorResponse('Anda tidak berhak menghapus jurnal guru lain', 403);
            }
            if ($existing && (int)$existing['isVerified'] === 1) {
                errorResponse('Jurnal sudah diverifikasi dan tidak dapat dihapus', 403);
            }
        }

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
            $conditions[] = "(LOWER(REPLACE(REPLACE(REPLACE(REPLACE(i.className, ' ', ''), '_', ''), '-', ''), '.', '')) = '$safeCleanClass')";
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
        // Spesifikasi: docs/specs/pembina-ekstrakurikuler.md
        $user = requireAuth();
        $ctx = ekskulContext($db, $user);
        requireEkskulModule($ctx);

        $memberCounts = [];
        $resCount = $db->query("SELECT extracurricular_id, COUNT(*) AS c FROM extracurricular_members WHERE status = 'AKTIF' GROUP BY extracurricular_id");
        while ($resCount && ($c = $resCount->fetch_assoc())) $memberCounts[$c['extracurricular_id']] = (int)$c['c'];

        $res = $db->query("SELECT e.*, u.displayName AS coach_display_name, u.role AS coach_role
                           FROM extracurriculars e
                           LEFT JOIN users u ON u.uid = e.coachId
                           ORDER BY e.name ASC");
        $extras = [];
        while ($row = $res->fetch_assoc()) {
            $level = ekskulAccessLevel($db, $ctx, $row);
            if ($level === 'NONE') continue;

            $coachValid = (string)$row['coachId'] !== '' && $row['coach_role'] === 'GURU';
            $row['coachNameLegacy'] = $row['coachName'];
            $row['coachName'] = $coachValid ? $row['coach_display_name'] : EKSKUL_LABEL_TANPA_PEMBINA;
            $row['coachValid'] = $coachValid;
            unset($row['coach_display_name'], $row['coach_role']);

            $row['students'] = json_decode($row['students'] ?? '', true) ?: [];
            $row['activities'] = json_decode($row['activities'] ?? '', true) ?: [];
            $row['access'] = ekskulAccessFlags($level);
            $row['memberCount'] = $level === 'HOMEROOM'
                ? (ekskulHomeroomData($db, $ctx)['ekskulCounts'][$row['id']] ?? 0)
                : ($memberCounts[$row['id']] ?? 0);
            $extras[] = $row;
        }
        jsonResponse($extras);
        break;

    case 'get_ekskul_journals':
        // Parameter uid diabaikan; data dibatasi ke ekskul yang boleh diakses user
        $user = requireAuth();
        $ctx = ekskulContext($db, $user);
        requireEkskulModule($ctx);

        $levels = [];
        $resE = $db->query("SELECT id, coachId FROM extracurriculars");
        while ($resE && ($e = $resE->fetch_assoc())) $levels[$e['id']] = ekskulAccessLevel($db, $ctx, $e);
        $fallbackLevel = $ctx['role'] === 'ADMIN' ? 'ADMIN' : ($ctx['role'] === 'KEPALA_SEKOLAH' ? 'MONITOR' : 'NONE');

        $sql = "SELECT ea.*, e.name as ekskulName FROM extracurricular_activities ea LEFT JOIN extracurriculars e ON ea.extracurricular_id = e.id ORDER BY ea.date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $res = $stmt->get_result();
        $journals = [];
        while ($row = $res->fetch_assoc()) {
            $level = $levels[$row['extracurricular_id']] ?? $fallbackLevel;
            if ($level === 'NONE') continue;

            $att = json_decode($row['attendance'] ?? '', true);
            $rate = 100;
            if ($level === 'HOMEROOM') {
                // Wali kelas: presensi dihitung dari siswa perwaliannya saja
                $att = filterAttendanceToStudents($att, ekskulHomeroomData($db, $ctx)['studentIds']);
                if (count($att) === 0) $rate = null;
            }
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
                'instructorName' => $row['reporter_name'],
                'canManage' => ekskulAccessFlags($level)['canManageActivities']
            ];
        }
        jsonResponse($journals);
        break;

    case 'save_ekskul_journal':
        // Legacy (tidak dipanggil bundle): hanya Admin / pembina ekskul, pelapor dari session
        requireMutationMethod();
        $user = requireAuth();
        $ctx = ekskulContext($db, $user);
        $body = getRequestBody();
        $id = uniqid('ea_');
        $ekId = trim((string)($body['ekskulId'] ?? ''));
        if ($ekId === '') errorResponse('ID ekskul wajib diisi');
        requireEkskulLevel($db, $ctx, $ekId, ['ADMIN', 'COACH'], true);
        $date = $body['date'] ?? date('Y-m-d');
        $title = $body['topic'] ?? '';
        $desc = $body['activities'] ?? '';
        $reporterId = $user['uid'];
        $reporterName = $user['displayName'];

        $stmt = $db->prepare("REPLACE INTO extracurricular_activities (id, extracurricular_id, date, title, description, reporter_id, reporter_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $id, $ekId, $date, $title, $desc, $reporterId, $reporterName);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menyimpan jurnal');
        break;

    case 'verify_ekskul_journal':
        jsonResponse(['success' => true]);
        break;

    case 'save_extracurricular':
        // Buat baru: hanya Admin. Ubah: Admin semua field; pembina hanya jadwal, ruang, deskripsi.
        requireMutationMethod();
        $user = requireAuth();
        $ctx = ekskulContext($db, $user);
        requireEkskulModule($ctx);
        $body = getRequestBody();

        $id = trim((string)($body['id'] ?? ''));
        $existing = $id !== '' ? loadEkskulRow($db, $id) : null;
        $level = $existing ? ekskulAccessLevel($db, $ctx, $existing) : ($ctx['role'] === 'ADMIN' ? 'ADMIN' : 'NONE');
        if (!in_array($level, ['ADMIN', 'COACH'], true)) {
            errorResponse($existing ? EKSKUL_MSG_KELOLA : 'Hanya Admin yang dapat menambah ekstrakurikuler.', 403);
        }

        // Field yang tidak dikirim mempertahankan nilai lama
        $pick = fn(string $key) => array_key_exists($key, $body) ? (string)($body[$key] ?? '') : (string)($existing[$key] ?? '');
        $schedule = trim($pick('schedule'));
        $description = $pick('description');
        $room = trim($pick('room'));

        if ($level === 'ADMIN') {
            $name = trim($pick('name'));
            if ($name === '') errorResponse('Nama ekstrakurikuler wajib diisi');
            [$coachId, $coachName] = resolveCoachAssignment($db, $pick('coachId'));
        }

        if (!$existing) {
            if ($id === '') $id = uniqid('extra_');
            $students = json_encode($body['students'] ?? []);
            $activities = json_encode($body['activities'] ?? []);
            $stmt = $db->prepare("INSERT INTO extracurriculars (id, name, coachId, coachName, schedule, description, room, students, activities) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssss", $id, $name, $coachId, $coachName, $schedule, $description, $room, $students, $activities);
        } elseif ($level === 'ADMIN') {
            $stmt = $db->prepare("UPDATE extracurriculars SET name = ?, coachId = ?, coachName = ?, schedule = ?, description = ?, room = ? WHERE id = ?");
            $stmt->bind_param("sssssss", $name, $coachId, $coachName, $schedule, $description, $room, $id);
        } else {
            // Pembina: nama dan pembina dari body diabaikan
            $stmt = $db->prepare("UPDATE extracurriculars SET schedule = ?, description = ?, room = ? WHERE id = ?");
            $stmt->bind_param("ssss", $schedule, $description, $room, $id);
        }
        $stmt->execute();
        jsonResponse(['success' => true, 'id' => $id]);
        break;

    case 'delete_extracurricular':
        requireMutationMethod();
        $user = requireAuth();
        $id = $_GET['id'] ?? '';
        if (!$id) errorResponse('ID tidak valid');
        requireEkskulLevel($db, ekskulContext($db, $user), $id, ['ADMIN'], true);
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
        // GURU hanya melihat dokumen miliknya; KEPALA_SEKOLAH & ADMIN melihat semua
        $user = lessonUser($db, ['GURU', 'KEPALA_SEKOLAH', 'ADMIN']);
        $isGuru = $user['role'] === 'GURU';
        $sql = "
            SELECT r.*, r.status as db_status, r.verified_by, r.verified_by_name, r.verified_at, g.nip, g.nuptk, g.nik
            FROM lesson_rpps r
            LEFT JOIN gtk_pegawai g ON r.authorId = g.id COLLATE utf8mb4_unicode_ci
            " . ($isGuru ? "WHERE r.authorId = ?" : "") . "
            ORDER BY r.createdAt DESC
        ";
        $stmt = $db->prepare($sql);
        if ($isGuru) $stmt->bind_param("s", $user['uid']);
        $stmt->execute();
        $res = $stmt->get_result();
        $rpps = [];
        while ($row = $res->fetch_assoc()) {
            $fileUrl = $row['file_url'] ?? null;
            $payloadData = !empty($row['payload']) ? (json_decode($row['payload'], true) ?: []) : [];

            if (!empty($row['payload'])) {
                $item = array_merge($row, $payloadData);
            } else {
                $item = $row;
                $item['objectives'] = json_decode($row['objectives'], true) ?: [];
                $item['activities'] = json_decode($row['activities'], true) ?: [];
                $item['isCustom'] = (bool)$row['isCustom'];
            }
            unset($item['payload'], $item['db_status']);

            // Kolom DB selalu otoritatif atas isi payload kiriman klien
            $type = strtoupper(trim((string)($row['type'] ?? '')));
            $locked = lessonIsLocked(['status' => $row['db_status']]);
            $isOwner = $isGuru && lessonIsOwner($user, $row);
            $item['id'] = $row['id'];
            $item['type'] = $type !== '' ? $type : 'RPP';
            $item['authorId'] = $row['authorId'];
            $item['authorName'] = $row['authorName'];
            $item['teacherId'] = $row['authorId'];
            $item['teacherName'] = $row['authorName'];
            if ($fileUrl) $item['fileUrl'] = $fileUrl;
            $item['nip'] = $row['nip'] ?? '';
            $item['nuptk'] = $row['nuptk'] ?? '';
            $item['nik'] = $row['nik'] ?? '';
            $item['status'] = $row['db_status'];
            $item['verifiedBy'] = $row['verified_by'];
            $item['verifiedByName'] = $row['verified_by_name'];
            $item['verifiedAt'] = $row['verified_at'];
            $item['isInteractive'] = !(($payloadData['isInteractive'] ?? null) === false || !empty($fileUrl));
            $item['canEdit'] = $isOwner && !$locked;
            $item['canDelete'] = ($isOwner && !$locked) || $user['role'] === 'ADMIN';
            $rpps[] = $item;
        }
        jsonResponse($rpps);
        break;

    case 'save_lesson_rpp':
        // Hanya GURU yang membuat/mengubah dokumen miliknya; status tidak bisa diatur dari body
        $user = lessonUser($db, ['GURU']);
        lessonRequireMethod(['POST']);
        $body = getRequestBody();

        $id = trim((string)($body['id'] ?? ''));
        $existing = $id !== '' ? lessonAssertMutable($db, $user, $id) : null;
        if (!$existing) {
            $id = uniqid('rpp_');
            lessonEnsureIndex($db);
        }

        $title = $body['title'] ?? '';
        $subject = $body['subject'] ?? $body['description'] ?? '';
        $classLevel = $body['classSession'] ?? $body['classLevel'] ?? '';
        $topic = $body['topic'] ?? $body['description'] ?? $title;
        // Tipe: field eksplisit diutamakan, lalu deteksi dari isi payload (silabus interaktif)
        $type = strtoupper(trim((string)($body['type'] ?? (isset($body['capaian']) || isset($body['rows']) ? 'SILABUS' : 'RPP'))));
        if (!in_array($type, LESSON_TYPES, true)) errorResponse('Tipe dokumen tidak valid');

        $fileUrl = isset($body['fileUrl']) && $body['fileUrl'] !== '' ? (string)$body['fileUrl'] : null;
        if ($fileUrl !== null && !lessonValidFileUrl($id, $fileUrl)) errorResponse('Lokasi berkas tidak valid');

        $status = $existing ? ($existing['status'] ?: 'PENDING') : 'PENDING';
        $authorId = $existing ? $existing['authorId'] : $user['uid'];
        $authorName = $existing ? $existing['authorName'] : $user['displayName'];

        // Identitas & status di payload mengikuti nilai server
        $payload = $body;
        unset($payload['verifiedBy'], $payload['verifiedByName'], $payload['verifiedAt'], $payload['canEdit'], $payload['canDelete']);
        $payload['id'] = $id;
        $payload['type'] = $type;
        $payload['status'] = $status;
        $payload['authorId'] = $authorId;
        $payload['authorName'] = $authorName;
        $payload['teacherId'] = $authorId;
        $payload['teacherName'] = $authorName;
        $payloadJson = json_encode($payload);
        $isCustom = isset($body['isCustom']) ? (int)$body['isCustom'] : 1;

        if ($existing) {
            if ($fileUrl !== null) {
                $stmt = $db->prepare("UPDATE lesson_rpps SET title=?, subject=?, classLevel=?, topic=?, isCustom=?, type=?, payload=?, file_url=? WHERE id=?");
                $stmt->bind_param("ssssissss", $title, $subject, $classLevel, $topic, $isCustom, $type, $payloadJson, $fileUrl, $id);
            } else {
                $stmt = $db->prepare("UPDATE lesson_rpps SET title=?, subject=?, classLevel=?, topic=?, isCustom=?, type=?, payload=? WHERE id=?");
                $stmt->bind_param("ssssisss", $title, $subject, $classLevel, $topic, $isCustom, $type, $payloadJson, $id);
            }
        } else {
            $stmt = $db->prepare("INSERT INTO lesson_rpps (id, title, subject, classLevel, topic, objectives, activities, authorId, authorName, isCustom, type, payload, file_url, status) VALUES (?, ?, ?, ?, ?, '[]', '[]', ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssissss", $id, $title, $subject, $classLevel, $topic, $authorId, $authorName, $isCustom, $type, $payloadJson, $fileUrl, $status);
        }

        try {
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('SIMANTAP save_lesson_rpp: ' . $e->getMessage());
            errorResponse('Gagal menyimpan Perangkat Ajar', 500);
        }
        jsonResponse(['success' => true, 'id' => $id, 'newId' => $id]);
        break;

    case 'delete_lesson_rpp':
        // GURU: miliknya & belum VERIFIED; ADMIN: dokumen apa pun
        $user = lessonUser($db, ['GURU', 'ADMIN']);
        lessonRequireMethod(['DELETE', 'POST']);
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') errorResponse('ID wajib disertakan');
        lessonAssertMutable($db, $user, $id, true);

        $stmt = $db->prepare("DELETE FROM lesson_rpps WHERE id = ?");
        $stmt->bind_param("s", $id);
        try {
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('SIMANTAP delete_lesson_rpp: ' . $e->getMessage());
            errorResponse('Gagal menghapus dokumen', 500);
        }
        lessonDeleteFiles($id);
        jsonResponse(['success' => true]);
        break;

    case 'verify_lesson_rpp':
        $user = lessonUser($db, ['KEPALA_SEKOLAH', 'ADMIN']);
        lessonRequireMethod(['POST']);
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        if (empty($id)) errorResponse('ID RPP wajib disertakan');
        if (!lessonLoad($db, (string)$id)) errorResponse(LESSON_MSG_NOT_FOUND, 404);

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
        $tsActor   = tsUser($db, ['GURU', 'ADMIN', 'KEPALA_SEKOLAH', 'TATA_USAHA']);
        $teacherId = trim($_GET['teacher_id'] ?? '');
        if ($tsActor['role'] === 'GURU') {
            // Guru hanya boleh membaca penugasan miliknya sendiri.
            if ($teacherId === '') $teacherId = (string)$tsActor['uid'];
            if ($teacherId !== (string)$tsActor['uid']) errorResponse(TS_MSG_OWNER_READ, 403);
        }
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
        tsUser($db, ['ADMIN']);
        tsRequireMethod(['POST']);
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
        $tsActor = tsUser($db, ['GURU', 'ADMIN']);
        tsRequireMethod(['POST']);
        $body = getRequestBody();
        $teacherId = trim($body['teacher_id'] ?? '');
        $subjects  = $body['subjects'] ?? [];

        if (empty($teacherId)) errorResponse('teacher_id wajib diisi');
        if (!is_array($subjects)) errorResponse('Format penugasan tidak valid');

        if ($tsActor['role'] === 'GURU' && (string)$tsActor['uid'] !== $teacherId) {
            errorResponse(TS_MSG_OWNER_WRITE, 403);
        }

        // Validasi dijalankan sebelum transaksi. Item yang identik dengan baris
        // tersimpan (data warisan) dilewati aturan baru agar guru tidak terkunci.
        $tsStored = tsLoadRows($db, $teacherId);
        $tsFinal  = tsValidateItems($db, $tsActor['role'], $subjects, $tsStored);

        $db->begin_transaction();
        try {
            $delStmt = $db->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
            if (!$delStmt) throw new Exception("Prepare delete failed: " . $db->error);
            $delStmt->bind_param("s", $teacherId);
            if (!$delStmt->execute()) throw new Exception("Execute delete failed: " . $delStmt->error);

            if (!empty($tsFinal)) {
                $insStmt = $db->prepare("INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES (?, ?, ?, ?, ?)");
                if (!$insStmt) {
                    throw new Exception("Prepare insert failed. Pastikan kolom class_ids sudah ada di tabel teacher_subjects. Error: " . $db->error);
                }

                foreach ($tsFinal as $sub) {
                    $subCode  = $sub['subject_code'];
                    $subName  = $sub['subject_name'];
                    $classLvl = $sub['class_level'];
                    $classIds = json_encode($sub['class_ids']);

                    $insStmt->bind_param("sssss", $teacherId, $subCode, $subName, $classLvl, $classIds);
                    if (!$insStmt->execute()) throw new Exception("Execute insert failed: " . $insStmt->error);
                }
            }

            // === SYNC: teacher_subjects -> gtk_pegawai (termasuk saat daftar kosong) ===
            tsSyncGtk($db, $teacherId, $tsFinal);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            error_log('save_teacher_subjects_bulk gagal: ' . $e->getMessage());
            errorResponse('Gagal menyimpan penugasan mata pelajaran', 500);
        }

        jsonResponse([
            'success' => true,
            'count'   => count($tsFinal),
            'items'   => tsFetchForResponse($db, $teacherId),
        ]);
        break;

    case 'delete_teacher_subject':
        tsUser($db, ['ADMIN']);
        tsRequireMethod(['POST', 'DELETE']);
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
        // Rekap nilai kelas+mapel+semester. Daftar siswa diambil dari tabel students (siswa aktif),
        // lalu digabung dengan assessment_data jurnal dan nilai ujian.
        $user = requireAuth();
        $activePeriod = getActiveAcademicPeriod($db);
        $classId      = $_GET['class_id']      ?? '';
        $subjectId    = $_GET['subject_id']     ?? '';
        $academicYear = $_GET['academic_year']  ?? $activePeriod['academic_year'];
        $semester     = $_GET['semester']       ?? $activePeriod['semester'];

        if (empty($classId) || empty($subjectId)) errorResponse('class_id dan subject_id wajib diisi');

        requireGradeAccess($db, $user, $classId, $subjectId);

        $classStudents = getActiveClassStudents($db, $classId);
        $studentMap = []; // student_id => {name, tugas_scores:[], kuis_scores:[]}
        foreach ($classStudents as $sid => $st) {
            $studentMap[$sid] = [
                'student_id'   => $sid,
                'student_name' => $st['name'],
                'tugas_scores' => [],
                'kuis_scores'  => [],
            ];
        }

        // Jurnal ber-penilaian untuk kelas+mapel ini, dibatasi rentang tanggal semester
        $sqlJ = "
            SELECT id, date, topic, assessment_data, has_assessment
            FROM journal_entries
            WHERE classId = ? AND subjectId = ? AND has_assessment = 1 AND isDraft = 0";
        $paramsJ = [$classId, $subjectId];
        $range = getSemesterDateRange($academicYear, $semester);
        if ($range) {
            $sqlJ .= " AND date BETWEEN ? AND ?";
            $paramsJ[] = $range[0];
            $paramsJ[] = $range[1];
        }
        $sqlJ .= " ORDER BY date ASC";
        $stmt = $db->prepare($sqlJ);
        $stmt->bind_param(str_repeat('s', count($paramsJ)), ...$paramsJ);
        $stmt->execute();
        $res = $stmt->get_result();

        $journalAssessments = []; // [{journal_id, date, topic, students: [{id, name, tugas, kuis, notes}]}]

        while ($row = $res->fetch_assoc()) {
            $assessData = json_decode($row['assessment_data'], true) ?: [];
            $journalAssessments[] = [
                'journal_id' => $row['id'],
                'date'       => $row['date'],
                'topic'      => $row['topic'],
                'students'   => $assessData
            ];

            foreach ($assessData as $sa) {
                $sid = $sa['student_id'] ?? '';
                // Siswa yang sudah tidak aktif / pindah kelas tidak ikut direkap
                if (empty($sid) || !isset($studentMap[$sid])) continue;
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
            $ujianMap[$sid][$u['ujian_type']] = $u['score'] !== null ? (float)$u['score'] : null;
        }

        // Get system config for weights
        $resW = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('bobot_tugas','bobot_kuis','bobot_ujian','kkm_default')");
        $weights = ['bobot_tugas' => 30, 'bobot_kuis' => 30, 'bobot_ujian' => 40, 'kkm_default' => 75];
        while ($w = $resW->fetch_assoc()) {
            $weights[$w['config_key']] = (int)$w['config_value'];
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
                'pas_foto'        => $classStudents[$sid]['pas_foto'] ?? null,
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
        $user = requireAuth();
        $classId   = $_GET['class_id']   ?? '';
        $subjectId = $_GET['subject_id'] ?? '';
        $dateParam = $_GET['date']        ?? '';

        if (empty($classId) || empty($subjectId) || empty($dateParam)) {
            errorResponse('class_id, subject_id, dan date wajib diisi');
        }

        requireGradeAccess($db, $user, $classId, $subjectId);

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

        // Daftar siswa dari tabel students (siswa aktif), nilai pertemuan digabung ke dalamnya
        $classStudents = getActiveClassStudents($db, $classId);
        $assessMap = [];
        foreach ($assessData as $sa) {
            $sid = $sa['student_id'] ?? '';
            if ($sid !== '') $assessMap[$sid] = $sa;
        }

        $students = [];
        foreach ($classStudents as $sid => $st) {
            $sa = $assessMap[$sid] ?? [];
            $students[] = [
                'student_id'        => $sid,
                'student_name'      => $st['name'],
                'pas_foto'          => $st['pas_foto'],
                'tugas_score'       => isset($sa['tugas_score']) && $sa['tugas_score'] !== '' ? (float)$sa['tugas_score'] : null,
                'kuis_score'        => isset($sa['kuis_score'])  && $sa['kuis_score']  !== '' ? (float)$sa['kuis_score']  : null,
                'attendance_status' => $attendMap[$sid] ?? 'HADIR',
                'notes'             => $sa['notes'] ?? '',
            ];
        }

        jsonResponse([
            'journal_found' => true,
            'date'          => $journal['date'],
            'topic'         => $journal['topic'] ?? '',
            'students'      => $students,
        ]);
        break;

    // --- MONTHLY GRADES: Agregasi nilai per bulan ---
    case 'get_monthly_grades':
        $user = requireAuth();
        $classId    = $_GET['class_id']   ?? '';
        $subjectId  = $_GET['subject_id'] ?? '';
        $monthParam = $_GET['month']       ?? ''; // Format: YYYY-MM

        if (empty($classId) || empty($subjectId) || empty($monthParam)) {
            errorResponse('class_id, subject_id, dan month wajib diisi');
        }

        requireGradeAccess($db, $user, $classId, $subjectId);

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

        // Daftar siswa dari tabel students (siswa aktif), nilai jurnal digabung ke dalamnya
        $classStudents = getActiveClassStudents($db, $classId);
        $studentMap = [];
        foreach ($classStudents as $sid => $st) {
            $studentMap[$sid] = [
                'student_id'   => $sid,
                'student_name' => $st['name'],
                'tugas_scores' => [],
                'kuis_scores'  => [],
            ];
        }
        $meetingCount = 0;
        while ($row = $res->fetch_assoc()) {
            $meetingCount++;
            $assessData = json_decode($row['assessment_data'], true) ?: [];
            foreach ($assessData as $sa) {
                $sid = $sa['student_id'] ?? '';
                // Siswa yang sudah tidak aktif / pindah kelas tidak ikut direkap
                if (empty($sid) || !isset($studentMap[$sid])) continue;
                if (isset($sa['tugas_score']) && $sa['tugas_score'] !== null && $sa['tugas_score'] !== '') {
                    $studentMap[$sid]['tugas_scores'][] = (float)$sa['tugas_score'];
                }
                if (isset($sa['kuis_score']) && $sa['kuis_score'] !== null && $sa['kuis_score'] !== '') {
                    $studentMap[$sid]['kuis_scores'][] = (float)$sa['kuis_score'];
                }
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
                'pas_foto'     => $classStudents[$sid]['pas_foto'] ?? null,
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
            'has_data'       => $meetingCount > 0,
        ]);
        break;

    // --- UJIAN SCORES ---

    case 'save_ujian_score':
        $user = requireAuth();
        $body = getRequestBody();
        // Support bulk save: scores is array of {student_id, student_name, score}
        $classId      = $body['class_id']      ?? '';
        $subjectId    = $body['subject_id']     ?? '';
        $activePeriod = getActiveAcademicPeriod($db);
        $academicYear = $body['academic_year']  ?? $activePeriod['academic_year'];
        $semester     = $body['semester']       ?? $activePeriod['semester'];
        $ujianType    = $body['ujian_type']     ?? 'UAS';
        $scores       = $body['scores']         ?? [];

        if (empty($classId) || empty($subjectId) || empty($scores)) {
            errorResponse('class_id, subject_id, dan scores wajib diisi');
        }

        requireGradeAccess($db, $user, $classId, $subjectId);

        // Hanya siswa aktif kelas ini yang boleh diberi nilai; nama diambil dari tabel students
        $classStudents = getActiveClassStudents($db, $classId);

        $db->begin_transaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO ujian_scores (student_id, student_name, class_id, subject_id, academic_year, semester, ujian_type, score, recorded_by, recorder_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE score = VALUES(score), recorded_by = VALUES(recorded_by), recorder_name = VALUES(recorder_name)
            ");
            $saved = 0;
            foreach ($scores as $s) {
                $sid    = is_scalar($s['student_id'] ?? null) ? (string)$s['student_id'] : '';
                if ($sid === '' || !isset($classStudents[$sid])) continue;
                $sname  = $classStudents[$sid]['name'];
                $score  = isset($s['score']) && $s['score'] !== '' ? (float)$s['score'] : null;
                $stmt->bind_param("sssssssdss",
                    $sid, $sname, $classId, $subjectId, $academicYear, $semester, $ujianType, $score, $user['uid'], $user['displayName']
                );
                $stmt->execute();
                $saved++;
            }
            $db->commit();
            jsonResponse(['success' => true, 'saved' => $saved]);
        } catch (Exception $e) {
            $db->rollback();
            errorResponse('Gagal menyimpan nilai ujian: ' . $e->getMessage());
        }
        break;

    case 'get_ujian_scores':
        $user = requireAuth();
        $classId      = $_GET['class_id']      ?? '';
        $subjectId    = $_GET['subject_id']     ?? '';
        $activePeriod = getActiveAcademicPeriod($db);
        $academicYear = $_GET['academic_year']  ?? $activePeriod['academic_year'];
        $semester     = $_GET['semester']       ?? $activePeriod['semester'];

        if (empty($classId) || empty($subjectId)) errorResponse('class_id dan subject_id wajib diisi');

        requireGradeAccess($db, $user, $classId, $subjectId);

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

        $stmt = $db->prepare("SELECT isDraft, isVerified FROM journal_entries WHERE id = ?");
        $stmt->bind_param("s", $journalId);
        $stmt->execute();
        $jurnal = $stmt->get_result()->fetch_assoc();
        if (!$jurnal) errorResponse('Jurnal tidak ditemukan', 404);
        if ((int)$jurnal['isDraft'] === 1) errorResponse('Jurnal masih draf dan belum dapat diverifikasi');
        // Sudah terverifikasi: jangan timpa verifiedBy/verifiedAt yang lama
        if ((int)$jurnal['isVerified'] === 1) jsonResponse(['success' => true]);

        $verifiedAt = date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE journal_entries SET isVerified = 1, verifiedBy = ?, verifiedAt = ? WHERE id = ?");
        $stmt->bind_param("sss", $user['uid'], $verifiedAt, $journalId);
        if ($stmt->execute()) {
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal memverifikasi jurnal');
        }
        break;

    // --- PERSETUJUAN MASSAL JURNAL BULANAN (docs/specs/persetujuan-jurnal-bulanan.md) ---
    case 'preview_journal_month_approval':
        requireRole(['KEPALA_SEKOLAH', 'ADMIN']);
        jsonResponse(persetujuanJurnalPreview($db, (string)($_GET['month'] ?? '')));
        break;

    case 'approve_journals_month':
        $user = requireRole(['KEPALA_SEKOLAH', 'ADMIN']);
        $body = getRequestBody();
        jsonResponse(persetujuanJurnalSetujui($db, $user, (string)($body['month'] ?? ''), $body['ids'] ?? null));
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

    // --- REKAP BULANAN WALI KELAS (halaman HTML siap cetak, lihat api/rekap_bulanan.php) ---
    case 'cetak_rekap_bulanan':
        handleCetakRekapBulanan($db);
        break;

    // --- PANTAU NILAI KELAS: wali kelas & guru BK, hanya baca (docs/specs/pantau-nilai-wali-kelas.md) ---
    case 'get_class_grade_overview':
        handleGetClassGradeOverview($db);
        break;

    case 'get_class_subject_grade_detail':
        handleGetClassSubjectGradeDetail($db);
        break;

    case 'cetak_rekap_nilai_siswa':
        handleCetakRekapNilaiSiswa($db);
        break;

    // --- PORTAL NILAI ORANG TUA: kelola PIN & saklar (docs/specs/portal-nilai-orang-tua.md) ---
    case 'portal_nilai_status':
        handlePortalNilaiStatus($db);
        break;

    case 'cetak_kartu_akses':
        handleCetakKartuAkses($db);
        break;

    case 'portal_nilai_set_aktif':
        handlePortalNilaiSetAktif($db);
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
