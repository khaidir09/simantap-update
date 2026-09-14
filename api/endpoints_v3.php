<?php
// endpoints_v3.php — SIMANTAP API v3 (New endpoints for Phase 1)
// Dipanggil dari endpoints_v2.php sebagai fallback
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

$action = $_GET['action'] ?? '';

switch ($action) {

    // ============================================================
    // GET_HOMEROOM_INFO — Cari wali kelas berdasarkan class_id
    // ============================================================
    case 'get_homeroom_info':
        $classId = $_GET['class_id'] ?? '';
        if (empty($classId)) { jsonResponse(null); break; }

        $normalizedClassId = preg_replace('/[\s_\.-]/', '', strtolower($classId));
        $stmt = $db->prepare("SELECT uid, displayName, nip, email, class_homeroom FROM users WHERE REPLACE(REPLACE(REPLACE(REPLACE(LOWER(class_homeroom), ' ', ''), '_', ''), '-', ''), '.', '') = ? LIMIT 1");
        $stmt->bind_param("s", $normalizedClassId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        jsonResponse($row ?: null);
        break;

    // ============================================================
    // GET_CLASS_ATTENDANCE_SUMMARY — Rekap presensi per bulan untuk wali kelas
    // ============================================================
    case 'get_class_attendance_summary':
        [$activeYear, $activeSemester] = activeAcademicPeriod($db);
        $classId      = resolveClassRef($db, $_GET['class_id'] ?? '')['id'];
        $academicYear = trim((string)($_GET['year'] ?? '')) ?: $activeYear;
        $rawSemester  = trim((string)($_GET['semester'] ?? '')) ?: $activeSemester;
        $semester     = ($rawSemester === '1' || strtoupper($rawSemester) === 'GANJIL') ? 'GANJIL' : 'GENAP';
        $month        = $_GET['month']     ?? '';

        if (empty($classId)) { jsonResponse([]); break; }

        $sql = "SELECT
                    ar.student_id,
                    ar.student_name,
                    MONTH(ar.date) AS bulan,
                    YEAR(ar.date)  AS tahun,
                    SUM(CASE WHEN ar.status='HADIR' THEN 1 ELSE 0 END) AS total_hadir,
                    SUM(CASE WHEN ar.status='SAKIT' THEN 1 ELSE 0 END) AS total_sakit,
                    SUM(CASE WHEN ar.status='IZIN'  THEN 1 ELSE 0 END) AS total_izin,
                    SUM(CASE WHEN ar.status='ALFA'  THEN 1 ELSE 0 END) AS total_alfa,
                    COUNT(*) AS total_hari
                FROM attendance_records ar
                WHERE ar.class_id = ? AND ar.academic_year = ? AND ar.semester = ?";
        $params = [$classId, $academicYear, $semester];
        $types  = "sss";

        if (!empty($month)) {
            $sql .= " AND MONTH(ar.date) = ?";
            $params[] = (int)$month;
            $types   .= "i";
        }
        $sql .= " GROUP BY ar.student_id, ar.student_name, MONTH(ar.date), YEAR(ar.date) ORDER BY ar.student_name ASC, bulan ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['total_hadir'] = (int)$row['total_hadir'];
            $row['total_sakit'] = (int)$row['total_sakit'];
            $row['total_izin']  = (int)$row['total_izin'];
            $row['total_alfa']  = (int)$row['total_alfa'];
            $row['total_hari']  = (int)$row['total_hari'];
            $rows[] = $row;
        }
        jsonResponse($rows);
        break;

    // ============================================================
    // GET_HOMEROOM_DASHBOARD — Summary lengkap untuk dashboard wali kelas
    // ============================================================
    case 'get_homeroom_dashboard':
        // Frontend mengirim class_homeroom (mis. "X-AKT-1") tanpa tahun ajaran,
        // padahal presensi disimpan dengan classes.id ("X_AKT_1") dan tahun ajaran aktif.
        [$activeYear, $activeSemester] = activeAcademicPeriod($db);
        $classRef     = resolveClassRef($db, $_GET['class_id'] ?? '');
        $classId      = $classRef['id'];
        $academicYear = trim((string)($_GET['year'] ?? '')) ?: $activeYear;
        $rawSemester  = trim((string)($_GET['semester'] ?? '')) ?: $activeSemester;
        $semester     = ($rawSemester === '1' || strtoupper($rawSemester) === 'GANJIL') ? 'GANJIL' : 'GENAP';
        if (empty($classId)) { jsonResponse([]); break; }

        // Total siswa
        $stmtS = $db->prepare("SELECT COUNT(*) AS total FROM students WHERE class_id = ? AND status = 'AKTIF'");
        $stmtS->bind_param("s", $classId);
        $stmtS->execute();
        $totalSiswa = (int)$stmtS->get_result()->fetch_assoc()['total'];

        // Rekap hadir hari ini
        $today = date('Y-m-d');
        $stmtT = $db->prepare("SELECT status, COUNT(*) AS c FROM attendance_records WHERE class_id = ? AND date = ? GROUP BY status");
        $stmtT->bind_param("ss", $classId, $today);
        $stmtT->execute();
        $todayRes = $stmtT->get_result();
        $todayStats = ['HADIR'=>0,'SAKIT'=>0,'IZIN'=>0,'ALFA'=>0];
        while ($r = $todayRes->fetch_assoc()) $todayStats[$r['status']] = (int)$r['c'];

        // Rekap semester total per siswa
        $stmtA = $db->prepare("SELECT s.id AS student_id, s.name AS student_name,
            JSON_UNQUOTE(JSON_EXTRACT(s.docs_json, '$.pas_foto')) AS pas_foto,
            COALESCE(SUM(CASE WHEN ar.status='HADIR' THEN 1 ELSE 0 END), 0) AS hadir,
            COALESCE(SUM(CASE WHEN ar.status='SAKIT' THEN 1 ELSE 0 END), 0) AS sakit,
            COALESCE(SUM(CASE WHEN ar.status='IZIN'  THEN 1 ELSE 0 END), 0) AS izin,
            COALESCE(SUM(CASE WHEN ar.status='ALFA'  THEN 1 ELSE 0 END), 0) AS alfa
            FROM students s
            LEFT JOIN attendance_records ar ON ar.student_id = s.id AND ar.academic_year = ? AND ar.semester = ?
            WHERE s.class_id = ? AND s.status = 'AKTIF'
            GROUP BY s.id, s.name ORDER BY s.name ASC");
        $stmtA->bind_param("sss", $academicYear, $semester, $classId);
        $stmtA->execute();
        $res = $stmtA->get_result();
        $perSiswa = [];
        while ($row = $res->fetch_assoc()) {
            $row['hadir'] = (int)$row['hadir']; $row['sakit'] = (int)$row['sakit'];
            $row['izin']  = (int)$row['izin'];  $row['alfa']  = (int)$row['alfa'];
            $perSiswa[] = $row;
        }

        // Total poin pelanggaran kelas (incidents.className menyimpan nama kelas, cocokkan via kunci)
        $stmtV = $db->prepare("SELECT COALESCE(SUM(points),0) AS total FROM incidents
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(LOWER(className), ' ', ''), '_', ''), '-', ''), '.', '') IN (?, ?)
              AND status != 'REJECTED'");
        $keyId   = normalizeClassKey($classRef['id']);
        $keyName = normalizeClassKey($classRef['name']);
        $stmtV->bind_param("ss", $keyId, $keyName);
        $stmtV->execute();
        $totalPoin = (int)$stmtV->get_result()->fetch_assoc()['total'];

        // Rekap presensi per bulan (untuk grafik)
        $stmtM = $db->prepare("SELECT MONTH(date) AS bulan,
            SUM(CASE WHEN status='HADIR' THEN 1 ELSE 0 END) AS hadir,
            SUM(CASE WHEN status='SAKIT' THEN 1 ELSE 0 END) AS sakit,
            SUM(CASE WHEN status='IZIN'  THEN 1 ELSE 0 END) AS izin,
            SUM(CASE WHEN status='ALFA'  THEN 1 ELSE 0 END) AS alfa
            FROM attendance_records WHERE class_id = ? AND academic_year = ? AND semester = ?
            GROUP BY MONTH(date) ORDER BY bulan ASC");
        $stmtM->bind_param("sss", $classId, $academicYear, $semester);
        $stmtM->execute();
        $resM = $stmtM->get_result();
        $perBulan = [];
        while ($row = $resM->fetch_assoc()) {
            $row['hadir'] = (int)$row['hadir']; $row['sakit'] = (int)$row['sakit'];
            $row['izin']  = (int)$row['izin'];  $row['alfa']  = (int)$row['alfa'];
            $perBulan[] = $row;
        }

        // Ekskul yang ada siswa dari kelas ini
        $stmtE = $db->prepare("SELECT DISTINCT e.id, e.name, e.schedule, e.coachName,
            (SELECT COUNT(*) FROM extracurricular_members em2 WHERE em2.extracurricular_id=e.id AND em2.class_id=? AND em2.status='AKTIF') AS siswa_kelas
            FROM extracurriculars e
            INNER JOIN extracurricular_members em ON em.extracurricular_id = e.id AND em.class_id = ? AND em.status='AKTIF'");
        $stmtE->bind_param("ss", $classId, $classId);
        $stmtE->execute();
        $resE = $stmtE->get_result();
        $ekskul = [];
        while ($row = $resE->fetch_assoc()) { $row['siswa_kelas'] = (int)$row['siswa_kelas']; $ekskul[] = $row; }

        jsonResponse([
            'total_siswa'  => $totalSiswa,
            'today'        => $todayStats,
            'total_poin'   => $totalPoin,
            'per_siswa'    => $perSiswa,
            'per_bulan'    => $perBulan,
            'ekskul'       => $ekskul,
        ]);
        break;

    // ============================================================
    // EXTRACURRICULAR MEMBERS
    // ============================================================
    case 'get_extracurricular_members':
        $ekId    = $_GET['extracurricular_id'] ?? '';
        $classId = $_GET['class_id'] ?? '';

        $sql = "SELECT em.*, s.nisn, s.parent_name, s.parent_phone
                FROM extracurricular_members em
                LEFT JOIN students s ON s.id = em.student_id
                WHERE 1=1";
        $params = []; $types = "";
        if (!empty($ekId))    { $sql .= " AND em.extracurricular_id = ?"; $params[] = $ekId;    $types .= "s"; }
        if (!empty($classId)) { $sql .= " AND em.class_id = ?";           $params[] = $classId; $types .= "s"; }
        $sql .= " ORDER BY em.student_name ASC";

        $stmt = $db->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $members = [];
        while ($row = $res->fetch_assoc()) $members[] = $row;
        jsonResponse($members);
        break;

    case 'save_extracurricular_member':
        $user = requireAuth();
        $body = getRequestBody();
        $ekId       = $body['extracurricular_id'] ?? '';
        $studentId  = $body['student_id']   ?? '';
        $studentName= $body['student_name'] ?? '';
        $classId    = $body['class_id']     ?? '';
        $className  = $body['class_name']   ?? '';
        $joinedAt   = $body['joined_at']    ?? date('Y-m-d');

        if (empty($ekId) || empty($studentId)) errorResponse('ID ekskul dan ID siswa wajib diisi');

        $stmt = $db->prepare("INSERT INTO extracurricular_members (extracurricular_id, student_id, student_name, class_id, class_name, joined_at, status) VALUES (?,?,?,?,?,?,'AKTIF') ON DUPLICATE KEY UPDATE status='AKTIF', joined_at=VALUES(joined_at)");
        $stmt->bind_param("ssssss", $ekId, $studentId, $studentName, $classId, $className, $joinedAt);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menambah anggota: ' . $db->error);
        break;

    case 'delete_extracurricular_member':
        $user = requireAuth();
        $ekId      = $_GET['extracurricular_id'] ?? '';
        $studentId = $_GET['student_id']         ?? '';
        if (empty($ekId) || empty($studentId)) errorResponse('ID wajib diisi');

        $stmt = $db->prepare("DELETE FROM extracurricular_members WHERE extracurricular_id = ? AND student_id = ?");
        $stmt->bind_param("ss", $ekId, $studentId);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus anggota');
        break;

    // ============================================================
    // EXTRACURRICULAR ACTIVITIES
    // ============================================================
    case 'get_extracurricular_activities':
        $ekId = $_GET['extracurricular_id'] ?? '';
        if (empty($ekId)) { jsonResponse([]); break; }

        $stmt = $db->prepare("SELECT * FROM extracurricular_activities WHERE extracurricular_id = ? ORDER BY date DESC");
        $stmt->bind_param("s", $ekId);
        $stmt->execute();
        $res = $stmt->get_result();
        $acts = [];
        while ($row = $res->fetch_assoc()) {
            $row['attendance'] = !empty($row['attendance']) ? json_decode($row['attendance'], true) : [];
            $acts[] = $row;
        }
        jsonResponse($acts);
        break;

    case 'save_extracurricular_activity':
        $user = requireAuth();
        $body = getRequestBody();
        $id         = $body['id']                  ?? uniqid('ea_');
        $ekId       = $body['extracurricular_id']  ?? '';
        $date       = $body['date']                ?? date('Y-m-d');
        $title      = $body['title']               ?? '';
        $desc       = $body['description']         ?? '';
        $attendance = json_encode($body['attendance'] ?? []);

        if (empty($ekId) || empty($title)) errorResponse('ID ekskul dan judul wajib diisi');

        $stmt = $db->prepare("REPLACE INTO extracurricular_activities (id, extracurricular_id, date, title, description, attendance, reporter_id, reporter_name) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssss", $id, $ekId, $date, $title, $desc, $attendance, $user['uid'], $user['displayName']);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal menyimpan aktivitas');
        break;

    case 'delete_extracurricular_activity':
        $user = requireAuth();
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');

        $stmt = $db->prepare("DELETE FROM extracurricular_activities WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus aktivitas');
        break;

    // ============================================================
    // GENERAL ACTIVITIES — UPDATE & DELETE (tambahan CRUD)
    // ============================================================
    case 'update_general_activity':
        $user = requireAuth();
        $body = getRequestBody();
        $id             = $body['id']                ?? '';
        $title          = $body['title']             ?? '';
        $description    = $body['description']       ?? '';
        $participantsCount = (int)($body['participantsCount'] ?? 0);
        $date           = $body['date']              ?? '';
        $photos         = json_encode($body['photos'] ?? []);
        $classId        = $body['class_id']          ?? null;

        if (empty($id)) errorResponse('ID wajib diisi');

        // Only the reporter or admin can edit
        $checkOwner = $db->prepare("SELECT reporterId FROM general_activities WHERE id = ?");
        $checkOwner->bind_param("s", $id);
        $checkOwner->execute();
        $ownerRow = $checkOwner->get_result()->fetch_assoc();
        if ($ownerRow && $ownerRow['reporterId'] !== $user['uid'] && !in_array($user['role'], ['ADMIN','KEPALA_SEKOLAH'])) {
            errorResponse('Anda tidak memiliki akses untuk mengedit catatan ini');
        }

        $stmt = $db->prepare("UPDATE general_activities SET title=?, description=?, participantsCount=?, date=?, photos=?, class_id=? WHERE id=?");
        $stmt->bind_param("ssissss", $title, $description, $participantsCount, $date, $photos, $classId, $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal memperbarui: ' . $db->error);
        break;

    case 'delete_general_activity':
        $user = requireAuth();
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');

        // Only reporter or admin can delete
        $checkOwner = $db->prepare("SELECT reporterId FROM general_activities WHERE id = ?");
        $checkOwner->bind_param("s", $id);
        $checkOwner->execute();
        $ownerRow = $checkOwner->get_result()->fetch_assoc();
        if ($ownerRow && $ownerRow['reporterId'] !== $user['uid'] && !in_array($user['role'], ['ADMIN','KEPALA_SEKOLAH'])) {
            errorResponse('Anda tidak memiliki akses untuk menghapus catatan ini');
        }

        $stmt = $db->prepare("DELETE FROM general_activities WHERE id = ?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus');
        break;

    // ============================================================
    // UPDATE INCIDENT (v2 — mendukung logs, resolved_level, homeroom info)
    // ============================================================
    case 'update_incident_status':
        $user  = requireAuth();
        $scope = requireKesiswaanScope($db, $user, 'incident');
        $body = getRequestBody();
        $id            = $body['id']            ?? '';
        $newStatus     = $body['status']        ?? '';
        $note          = $body['note']          ?? '';
        $resolvedLevel = $body['resolved_level']?? null;

        if (empty($id) || empty($newStatus)) errorResponse('ID dan status wajib diisi');

        // Fetch existing logs
        $stmtGet = $db->prepare("SELECT logs, status, className FROM incidents WHERE id = ?");
        $stmtGet->bind_param("s", $id);
        $stmtGet->execute();
        $existing = $stmtGet->get_result()->fetch_assoc();
        if (!$existing) errorResponse('Insiden tidak ditemukan');
        if (!classInScope($scope, $existing['className'])) errorResponse(KESISWAAN_MSG_KELAS, 403);

        $logs = json_decode($existing['logs'] ?? '[]', true) ?: [];
        $logs[] = [
            'status'    => $newStatus,
            'actorName' => $user['displayName'],
            'actorRole' => $user['role'],
            'timestamp' => date('Y-m-d H:i:s'),
            'comment'   => $note,
        ];
        $logsJson = json_encode($logs);

        $stmt = $db->prepare("UPDATE incidents SET status=?, logs=?, actionTaken=CONCAT(COALESCE(actionTaken,''), '\n[', NOW(), '] ', ?), resolved_level=? WHERE id=?");
        $stmt->bind_param("sssss", $newStatus, $logsJson, $note, $resolvedLevel, $id);
        if ($stmt->execute()) jsonResponse(['success' => true, 'logs' => $logs]);
        else errorResponse('Gagal memperbarui status: ' . $db->error);
        break;

    // ============================================================
    // SAVE USER (v2) — tambah support is_bk
    // ============================================================
    case 'save_user_v2':
        requireRole(['ADMIN', 'KEPALA_SEKOLAH', 'TATA_USAHA']);
        $body          = getRequestBody();
        $uid           = $body['uid']           ?? '';
        $email         = strtolower(trim($body['email']        ?? ''));
        $displayName   = trim($body['displayName']             ?? '');
        $role          = strtoupper($body['role']              ?? 'GURU');
        $nip           = trim($body['nip']                     ?? '');
        $schoolName    = trim($body['schoolName']              ?? 'SMAN 1 BANJARBARU');
        $password      = $body['password']                     ?? '';
        $classHomeroom = $body['class_homeroom']               ?? null;
        $isBk          = (int)($body['is_bk']                 ?? 0);

        if (empty($uid) || empty($email) || empty($displayName)) errorResponse('UID, email, dan nama wajib diisi');

        $hashed = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : null;

        $stmtCheck = $db->prepare("SELECT uid FROM users WHERE uid = ?");
        $stmtCheck->bind_param("s", $uid);
        $stmtCheck->execute();
        $exists = $stmtCheck->get_result()->num_rows > 0;

        if ($exists) {
            if ($hashed) {
                $stmt = $db->prepare("UPDATE users SET email=?, password=?, displayName=?, role=?, nip=?, schoolName=?, class_homeroom=?, is_bk=? WHERE uid=?");
                $stmt->bind_param("sssssssss", $email, $hashed, $displayName, $role, $nip, $schoolName, $classHomeroom, $isBk, $uid);
            } else {
                $stmt = $db->prepare("UPDATE users SET email=?, displayName=?, role=?, nip=?, schoolName=?, class_homeroom=?, is_bk=? WHERE uid=?");
                $stmt->bind_param("ssssssss", $email, $displayName, $role, $nip, $schoolName, $classHomeroom, $isBk, $uid);
            }
        } else {
            $hashed = $hashed ?? password_hash('simantap123', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (uid, email, password, displayName, role, nip, schoolName, class_homeroom, is_bk) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssssssi", $uid, $email, $hashed, $displayName, $role, $nip, $schoolName, $classHomeroom, $isBk);
        }

        if ($stmt->execute()) {
            // Update session jika yang diubah adalah diri sendiri
            if (isset($_SESSION['user']) && $_SESSION['user']['uid'] === $uid) {
                $_SESSION['user']['class_homeroom'] = $classHomeroom;
                $_SESSION['user']['is_bk'] = $isBk;
                $_SESSION['user']['role']  = $role;
            }

            // =====================================================================
            // SINKRONISASI USERS -> GTK (Dua Arah)
            // =====================================================================
            // Hanya untuk role yang relevan sebagai staf sekolah
            $rolesWithGTK = ['GURU', 'KEPALA_SEKOLAH', 'TATA_USAHA', 'OPERATOR'];
            if (in_array($role, $rolesWithGTK)) {
                // Tentukan pekerjaan_kategori dari role
                $pekKat = 'Guru';
                if ($role === 'KEPALA_SEKOLAH') {
                    $pekKat = 'Kepala Sekolah';
                } elseif (in_array($role, ['TATA_USAHA', 'OPERATOR'])) {
                    $pekKat = 'Tenaga Kependidikan';
                }

                // Cek apakah GTK dengan id=uid sudah ada
                $chkGtk = $db->prepare("SELECT id FROM gtk_pegawai WHERE id = ?");
                $chkGtk->bind_param("s", $uid);
                $chkGtk->execute();
                $gtkRow = $chkGtk->get_result()->fetch_assoc();

                if ($gtkRow) {
                    // Update existing GTK — sinkronkan field dasar tanpa timpa biodata lainnya
                    $stmtGtk = $db->prepare(
                        "UPDATE gtk_pegawai SET 
                            nama=?, email=?, nip=?, pekerjaan_kategori=?,
                            class_homeroom=?, is_bk=?
                         WHERE id=?"
                    );
                    $stmtGtk->bind_param("sssssis", $displayName, $email, $nip, $pekKat, $classHomeroom, $isBk, $uid);
                    $stmtGtk->execute();
                } else {
                    // Cek apakah ada GTK lain dengan NIP yang sama (data lama sebelum sinkronisasi)
                    $gtkByNip = null;
                    if (!empty($nip)) {
                        $chkNip = $db->prepare("SELECT id FROM gtk_pegawai WHERE nip = ? AND nip != '' LIMIT 1");
                        $chkNip->bind_param("s", $nip);
                        $chkNip->execute();
                        $gtkByNip = $chkNip->get_result()->fetch_assoc();
                    }

                    if ($gtkByNip) {
                        // Ada GTK lama dengan NIP sama — update email & pekerjaan_kategori saja, biarkan id-nya
                        $stmtGtk = $db->prepare(
                            "UPDATE gtk_pegawai SET 
                                email=?, pekerjaan_kategori=?, class_homeroom=?, is_bk=?
                             WHERE id=?"
                        );
                        $oldGtkId = $gtkByNip['id'];
                        $stmtGtk->bind_param("sssiss", $email, $pekKat, $classHomeroom, $isBk, $oldGtkId);
                        if (!$stmtGtk->execute()) {
                            error_log('[SIMANTAP] gtk_pegawai UPDATE (NIP match) gagal: ' . $stmtGtk->error . ' | oldId=' . $oldGtkId);
                        }
                    } else {
                        // Buat GTK baru dengan id = uid (fresh insert)
                        $docsDefault = json_encode(['ktp' => true, 'ijazah' => true, 'kk' => false, 'skCpns' => false, 'skPpk' => false, 'skPangkat' => false, 'skBerkala' => false, 'sertifikat' => false]);
                        $jadwalDefault = json_encode([]);
                        $stmtGtk = $db->prepare(
                            "INSERT IGNORE INTO gtk_pegawai 
                                (id, nama, email, nip, pekerjaan_kategori, status_kepegawaian, 
                                 class_homeroom, is_bk, docs_json, jadwal_mengajar_json) 
                             VALUES (?,?,?,?,?,'ASN',?,?,?,?)"
                        );
                        // 9 parameter: s s s s s s i s s
                        // id, nama, email, nip, pekKat, classHomeroom, isBk, docsDefault, jadwalDefault
                        $stmtGtk->bind_param(
                            "ssssssiss",
                            $uid, $displayName, $email, $nip, $pekKat,
                            $classHomeroom, $isBk, $docsDefault, $jadwalDefault
                        );
                        if (!$stmtGtk->execute()) {
                            error_log('[SIMANTAP] gtk_pegawai INSERT IGNORE gagal: ' . $stmtGtk->error . ' | uid=' . $uid);
                        }
                    }
                }
            }

            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menyimpan: ' . $db->error);
        }
        break;

    // ============================================================
    // CLASS MANAGEMENT
    // ============================================================
    case 'get_classes':
        $db->query("CREATE TABLE IF NOT EXISTS classes (
            id VARCHAR(50) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            level VARCHAR(50),
            homeroom_teacher VARCHAR(128) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Coba insert data awal dari students jika classes kosong
        $res = $db->query("SELECT COUNT(*) as c FROM classes");
        if ($res && $res->fetch_assoc()['c'] == 0) {
            $db->query("INSERT IGNORE INTO classes (id, name, level) 
                        SELECT DISTINCT class_id, class_name, SUBSTRING_INDEX(class_id, ' ', 1) 
                        FROM students WHERE class_id IS NOT NULL AND class_id != ''");
        }
        
        $sql = "SELECT c.*, 
                (SELECT uid FROM users WHERE REPLACE(REPLACE(REPLACE(REPLACE(LOWER(class_homeroom), ' ', ''), '_', ''), '-', ''), '.', '') = REPLACE(REPLACE(REPLACE(REPLACE(LOWER(c.id), ' ', ''), '_', ''), '-', ''), '.', '') LIMIT 1) as sync_teacher_id
                FROM classes c ORDER BY c.level ASC, c.name ASC";
        $res = $db->query($sql);
        $classes = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['sync_teacher_id'])) {
                    $row['homeroom_teacher'] = $row['sync_teacher_id'];
                }
                unset($row['sync_teacher_id']);
                $classes[] = $row;
            }
        }
        jsonResponse($classes);
        break;

    case 'save_class':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        $name = $body['name'] ?? '';
        $level = $body['level'] ?? '';
        $homeroom_teacher = $body['homeroom_teacher'] ?? null;
        
        if (empty($id) || empty($name)) errorResponse('ID dan Nama kelas wajib diisi');
        
        $stmt = $db->prepare("REPLACE INTO classes (id, name, level, homeroom_teacher) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $id, $name, $level, $homeroom_teacher);
        if ($stmt->execute()) {
            // Sinkronkan ke tabel users (class_homeroom) agar 'Kelola Guru' juga terupdate
            $stmtReset = $db->prepare("UPDATE users SET class_homeroom = NULL WHERE REPLACE(REPLACE(REPLACE(REPLACE(LOWER(class_homeroom), ' ', ''), '_', ''), '-', ''), '.', '') = REPLACE(REPLACE(REPLACE(REPLACE(LOWER(?), ' ', ''), '_', ''), '-', ''), '.', '')");
            $stmtReset->bind_param("s", $id);
            $stmtReset->execute();

            if (!empty($homeroom_teacher)) {
                $stmtSync = $db->prepare("UPDATE users SET class_homeroom = ? WHERE uid = ?");
                $stmtSync->bind_param("ss", $id, $homeroom_teacher);
                $stmtSync->execute();
            }
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal menyimpan kelas: ' . $db->error);
        }
        break;

    case 'delete_class':
        $user = requireAuth();
        requireRole(['ADMIN', 'TATA_USAHA', 'KEPALA_SEKOLAH']);
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID kelas wajib diisi');
        
        $stmt = $db->prepare("DELETE FROM classes WHERE id=?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus kelas');
        break;

    // ============================================================
    // PARENT CALLS — Panggilan Orang Tua
    // ============================================================
    case 'get_parent_calls':
        $sql = "SELECT p.*, JSON_UNQUOTE(JSON_EXTRACT(s.docs_json, '$.pas_foto')) AS pas_foto 
                FROM parent_calls p 
                LEFT JOIN students s ON p.student_id = s.id WHERE 1=1";
        $params = []; $types = "";

        $statusFilter = $_GET['status'] ?? '';
        $classFilter  = $_GET['classId'] ?? '';
        if (!empty($statusFilter)) { $sql .= " AND p.status = ?"; $params[] = $statusFilter; $types .= "s"; }
        if (!empty($classFilter))  { $sql .= " AND p.class_name = ?"; $params[] = $classFilter; $types .= "s"; }
        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $db->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['logs'])) $row['logs'] = json_decode($row['logs'], true);
            $rows[] = $row;
        }
        jsonResponse($rows);
        break;

    case 'save_parent_call_manual':
        $user = requireAuth();
        $body = getRequestBody();

        $id             = $body['id']             ?? uniqid('pc_');
        $studentId      = $body['student_id']     ?? '';
        $studentName    = trim($body['student_name']  ?? '');
        $className      = trim($body['class_name']    ?? '');
        $notes          = trim($body['notes']          ?? '');
        $scheduledDate  = $body['scheduled_date'] ?? null;
        $status         = $body['status']         ?? 'PENDING';

        if (empty($studentName)) errorResponse('Nama siswa wajib diisi');

        $stmt = $db->prepare("INSERT INTO parent_calls (id, student_id, student_name, class_name, notes, status, scheduled_date, handled_by, handler_name, trigger_type) VALUES (?,?,?,?,?,?,?,?,?,'MANUAL')");
        $stmt->bind_param("sssssssss", $id, $studentId, $studentName, $className, $notes, $status, $scheduledDate, $user['uid'], $user['displayName']);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal menyimpan panggilan: ' . $db->error);
        break;

    case 'update_parent_call':
        $user = requireAuth();
        $body = getRequestBody();
        $id     = $body['id']     ?? '';
        $status = $body['status'] ?? '';
        $notes  = $body['notes']  ?? null;

        if (empty($id)) errorResponse('ID wajib diisi');

        // Ambil logs lama
        $stmtGet = $db->prepare("SELECT logs FROM parent_calls WHERE id = ?");
        $stmtGet->bind_param("s", $id);
        $stmtGet->execute();
        $existing = $stmtGet->get_result()->fetch_assoc();
        if (!$existing) errorResponse('Data panggilan tidak ditemukan');

        $logs = json_decode($existing['logs'] ?? '[]', true) ?: [];
        $logs[] = [
            'status'    => $status,
            'actorName' => $user['displayName'],
            'actorRole' => $user['role'],
            'timestamp' => date('Y-m-d H:i:s'),
            'comment'   => $notes,
        ];
        $logsJson = json_encode($logs);

        if (!empty($notes)) {
            $stmt = $db->prepare("UPDATE parent_calls SET status=?, notes=?, logs=? WHERE id=?");
            $stmt->bind_param("ssss", $status, $notes, $logsJson, $id);
        } else {
            $stmt = $db->prepare("UPDATE parent_calls SET status=?, logs=? WHERE id=?");
            $stmt->bind_param("sss", $status, $logsJson, $id);
        }
        if ($stmt->execute()) jsonResponse(['success' => true, 'logs' => $logs]);
        else errorResponse('Gagal memperbarui: ' . $db->error);
        break;

    default:
        return false;
}
return true;
