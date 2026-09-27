<?php
// endpoints_tu.php — SIMANTAP API (Modul Tata Usaha Phase B & C)
// Dipanggil dari index.php sebagai fallback
// Hanya boleh dimuat lewat api/index.php (yang sudah melewati gerbang lisensi)
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

$action = $_GET['action'] ?? '';

switch ($action) {

    // ============================================================
    // 1. KEPEGAWAIAN (GTK)
    // ============================================================
    case 'gtk_list':
        // Read directly from gtk_pegawai as the primary source of truth.
        // LEFT JOIN users to get linked account info (class_homeroom, is_bk) if it exists.
        $query = "
            SELECT 
                p.id,
                p.nip,
                p.nuptk,
                p.nama,                    
                COALESCE(p.email, u.email, '') as email,
                p.pekerjaan_kategori,
                COALESCE(p.status_kepegawaian, 'ASN') as status_kepegawaian,
                COALESCE(u.class_homeroom, p.class_homeroom, '') as class_homeroom,
                COALESCE(u.is_bk, p.is_bk, 0) as is_bk,
                p.mapel,
                p.kelas_ajar,
                p.beban_mengajar,
                p.docs_json,
                p.jadwal_mengajar_json,
                p.nuptk, p.pangkat, p.golongan, p.jabatan, p.tempat_lahir, p.tanggal_lahir, p.jenis_kelamin, p.pendidikan_terakhir, p.nomor_hp, p.nik, p.agama, p.alamat, p.sertifikasi
            FROM gtk_pegawai p
            LEFT JOIN users u ON u.uid = p.id
            ORDER BY p.nama ASC
        ";
        $res = $db->query($query);
        $data = [];
        
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $docsRaw = json_decode($row['docs_json'] ?? '{}', true) ?: [];

                // Merge paths from gtk_files table so berkas always show even if docs_json is stale
                $filesRes = $db->query("SELECT kategori, path_file FROM gtk_files WHERE gtk_id = '" . $db->real_escape_string($row['id']) . "' ORDER BY id DESC");
                if ($filesRes) {
                    while ($fRow = $filesRes->fetch_assoc()) {
                        $cat = $fRow['kategori'];
                        // Only set if not already in docs_json or docs_json has false/empty
                        if (empty($docsRaw[$cat])) {
                            $docsRaw[$cat] = $fRow['path_file'];
                        }
                    }
                }
                $row['docs_json'] = $docsRaw;
                $row['jadwal_mengajar_json'] = json_decode($row['jadwal_mengajar_json'] ?? '[]', true) ?: [];
                
                // Jika mapel atau kelas_ajar kosong di gtk_pegawai, ambil dari teacher_subjects
                if (empty($row['mapel']) || empty($row['kelas_ajar'])) {
                    $tsRes = $db->query("SELECT subject_name, class_ids FROM teacher_subjects WHERE teacher_id = '" . $db->real_escape_string($row['id']) . "'");
                    $mapels = [];
                    $kelas = [];
                    if ($tsRes) {
                        while($tsRow = $tsRes->fetch_assoc()) {
                            $mapels[] = $tsRow['subject_name'];
                            if (!empty($tsRow['class_ids'])) {
                                $k = json_decode($tsRow['class_ids'], true);
                                if (is_array($k)) {
                                    $kelas = array_merge($kelas, $k);
                                }
                            }
                        }
                    }
                    if (empty($row['mapel']) && count($mapels) > 0) {
                        $row['mapel'] = implode(', ', array_unique($mapels));
                    }
                    if (empty($row['kelas_ajar']) && count($kelas) > 0) {
                        $row['kelas_ajar'] = implode(', ', array_unique($kelas));
                    }
                }
                
                $data[] = $row;
            }
        }
        
        jsonResponse($data);
        break;

    case 'gtk_create':
    case 'gtk_update':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? uniqid('gtk_');
        $nama = $body['nama'] ?? '';
        $nip = $body['nip'] ?? '';
        $nuptk = $body['nuptk'] ?? '';
        $status_kepegawaian = $body['status_kepegawaian'] ?? 'ASN';
        $pangkat = $body['pangkat'] ?? '';
        $golongan = $body['golongan'] ?? '';
        $jabatan = $body['jabatan'] ?? '';
        $mapel = $body['mapel'] ?? '';
        $beban_mengajar = $body['beban_mengajar'] ?? '';
        $kelas_ajar = $body['kelas_ajar'] ?? '';
        $class_homeroom = $body['class_homeroom'] ?? '';
        $is_bk = isset($body['is_bk']) ? (int)$body['is_bk'] : 0;
        
        // Biodata baru
        $nik = $body['nik'] ?? '';
        $tempat_lahir = $body['tempat_lahir'] ?? '';
        $tanggal_lahir = empty($body['tanggal_lahir']) ? null : $body['tanggal_lahir'];
        $jenis_kelamin = $body['jenis_kelamin'] ?? 'Laki-laki';
        $agama = $body['agama'] ?? 'Islam';
        $pendidikan_terakhir = $body['pendidikan_terakhir'] ?? '';
        $sertifikasi = $body['sertifikasi'] ?? 'Belum Sertifikasi';
        $email = $body['email'] ?? '';
        $nomor_hp = $body['nomor_hp'] ?? '';
        $alamat = $body['alamat'] ?? '';
        $pekerjaan_kategori = $body['pekerjaan_kategori'] ?? 'Guru';

        $docs_json = json_encode($body['docs_json'] ?? new stdClass());
        $jadwal_mengajar_json = json_encode($body['jadwal_mengajar_json'] ?? []);

        if (empty($nama)) errorResponse('Nama GTK wajib diisi');

        if ($action === 'gtk_create' && empty($body['id'])) {
            $id = uniqid('gtk_');
        } else {
            $id = $body['id'] ?? uniqid('gtk_');
        }

        $query = "INSERT INTO gtk_pegawai (
                    id, nama, nip, nuptk, status_kepegawaian, pangkat, golongan, jabatan, mapel, 
                    beban_mengajar, kelas_ajar, class_homeroom, is_bk, docs_json, jadwal_mengajar_json,
                    tempat_lahir, tanggal_lahir, jenis_kelamin, agama, pendidikan_terakhir,
                    sertifikasi, email, nomor_hp, alamat, pekerjaan_kategori, nik
                  ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) 
                  ON DUPLICATE KEY UPDATE 
                  nama=VALUES(nama), nip=VALUES(nip), nuptk=VALUES(nuptk), status_kepegawaian=VALUES(status_kepegawaian), 
                  pangkat=VALUES(pangkat), golongan=VALUES(golongan), jabatan=VALUES(jabatan), mapel=VALUES(mapel), 
                  beban_mengajar=VALUES(beban_mengajar), kelas_ajar=VALUES(kelas_ajar), 
                  class_homeroom=VALUES(class_homeroom), is_bk=VALUES(is_bk), 
                  docs_json=VALUES(docs_json),
                  tempat_lahir=VALUES(tempat_lahir), tanggal_lahir=VALUES(tanggal_lahir),
                  jenis_kelamin=VALUES(jenis_kelamin), agama=VALUES(agama),
                  pendidikan_terakhir=VALUES(pendidikan_terakhir), sertifikasi=VALUES(sertifikasi),
                  email=VALUES(email), nomor_hp=VALUES(nomor_hp), alamat=VALUES(alamat),
                  pekerjaan_kategori=VALUES(pekerjaan_kategori), nik=VALUES(nik)";
                  
        $stmt = $db->prepare($query);
        $stmt->bind_param("sssssssssssssissssssssssss", 
            $id, $nama, $nip, $nuptk, $status_kepegawaian, $pangkat, $golongan, $jabatan, $mapel, 
            $beban_mengajar, $kelas_ajar, $class_homeroom, $is_bk, $docs_json, $jadwal_mengajar_json,
            $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $agama, $pendidikan_terakhir,
            $sertifikasi, $email, $nomor_hp, $alamat, $pekerjaan_kategori, $nik
        );
        
        if ($stmt->execute()) {
            // =====================================================================
            // SINKRONISASI GTK -> USERS (Dua Arah)
            // =====================================================================
            if ($action === 'gtk_create') {
                // Cek apakah sudah ada user dengan uid=id ini (seharusnya belum)
                $chkUser = $db->prepare("SELECT uid FROM users WHERE uid = ?");
                $chkUser->bind_param("s", $id);
                $chkUser->execute();
                $userAlreadyExists = $chkUser->get_result()->num_rows > 0;

                if (!$userAlreadyExists && !empty($email)) {
                    // Tentukan role berdasarkan pekerjaan_kategori
                    $autoRole = 'GURU';
                    if (strtolower($pekerjaan_kategori) === 'kepala sekolah') {
                        $autoRole = 'KEPALA_SEKOLAH';
                    } elseif (strtolower($pekerjaan_kategori) === 'tenaga kependidikan') {
                        $autoRole = 'TATA_USAHA';
                    }

                    // Password dari body jika ada, default simantap123
                    $gtkPassword = $body['password'] ?? '';
                    $defaultPass = !empty($gtkPassword)
                        ? password_hash($gtkPassword, PASSWORD_BCRYPT)
                        : password_hash('simantap123', PASSWORD_BCRYPT);

                    $schoolDefault = 'SMAN 1 BANJARBARU';
                    $stmtUser = $db->prepare(
                        "INSERT IGNORE INTO users 
                            (uid, email, password, displayName, role, nip, schoolName, class_homeroom, is_bk) 
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    );
                    $stmtUser->bind_param(
                        "ssssssssi",
                        $id, $email, $defaultPass, $nama, $autoRole,
                        $nip, $schoolDefault, $class_homeroom, $is_bk
                    );
                    $stmtUser->execute();
                }
            } else {
                // gtk_update: cek apakah sudah ada akun users untuk GTK ini
                $chkUserUpd = $db->prepare("SELECT uid FROM users WHERE uid = ?");
                $chkUserUpd->bind_param("s", $id);
                $chkUserUpd->execute();
                $userExists = $chkUserUpd->get_result()->num_rows > 0;

                if ($userExists) {
                    // Akun sudah ada → UPDATE field yang relevan saja
                    $db->query(
                        "UPDATE users SET 
                            displayName='" . $db->real_escape_string($nama) . "',
                            email='"       . $db->real_escape_string($email) . "',
                            nip='"         . $db->real_escape_string($nip) . "',
                            class_homeroom='" . $db->real_escape_string($class_homeroom) . "',
                            is_bk=$is_bk
                         WHERE uid='" . $db->real_escape_string($id) . "'"
                    );
                } elseif (!empty($email)) {
                    // Akun belum ada tapi email baru ditambahkan → buat akun otomatis
                    $autoRole = 'GURU';
                    if (strtolower($pekerjaan_kategori) === 'kepala sekolah') {
                        $autoRole = 'KEPALA_SEKOLAH';
                    } elseif (strtolower($pekerjaan_kategori) === 'tenaga kependidikan') {
                        $autoRole = 'TATA_USAHA';
                    }
                    $defaultPass  = password_hash('simantap123', PASSWORD_BCRYPT);
                    $schoolDefault = 'SMAN 1 BANJARBARU';
                    $stmtNewUser  = $db->prepare(
                        "INSERT IGNORE INTO users
                            (uid, email, password, displayName, role, nip, schoolName, class_homeroom, is_bk)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    );
                    $stmtNewUser->bind_param(
                        "ssssssssi",
                        $id, $email, $defaultPass, $nama, $autoRole,
                        $nip, $schoolDefault, $class_homeroom, $is_bk
                    );
                    $stmtNewUser->execute();
                }
                // Jika email masih kosong dan akun belum ada → tidak buat akun (tidak ada cara login)
            }

            jsonResponse(['success' => true, 'id' => $id]);
        }
        else errorResponse('Gagal menyimpan GTK: ' . $db->error);
        break;

    // Partial update — only docs_json (prevents overwriting biodata with empty values)
    case 'gtk_update_docs':
        $user = requireAuth();
        $body = getRequestBody();
        $gtk_id   = $body['id'] ?? '';
        $docsData = $body['docs_json'] ?? null;
        if (empty($gtk_id)) errorResponse('ID GTK wajib diisi');

        // Read existing docs_json first, then merge
        $curRes = $db->prepare("SELECT docs_json FROM gtk_pegawai WHERE id=?");
        $curRes->bind_param("s", $gtk_id);
        $curRes->execute();
        $curRow = $curRes->get_result()->fetch_assoc();
        $existing = json_decode($curRow['docs_json'] ?? '{}', true) ?: [];
        if (is_array($docsData)) {
            $merged = array_merge($existing, $docsData);
        } else {
            $merged = $existing;
        }
        $newJson = json_encode($merged);

        $upd = $db->prepare("UPDATE gtk_pegawai SET docs_json=? WHERE id=?");
        $upd->bind_param("ss", $newJson, $gtk_id);
        if ($upd->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal update docs GTK');
        break;

    // Partial update — only teaching attribute fields (no biodata risk)
    case 'gtk_update_atribut':
        // Hanya TU/ADMIN (role dibaca ulang dari DB). Jadwal tetap diterima sebagai jalur legacy
        // bundle lama; bundle baru memakai jadwal_kbm_save (docs/specs/jadwal-kbm-mandiri-guru.md).
        $user = loadFreshUser($db, requireAuth());
        if (!in_array($user['role'], ['TATA_USAHA', 'ADMIN'], true)) {
            errorResponse('Anda tidak memiliki akses untuk mengubah atribut mengajar GTK.', 403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') errorResponse('Metode tidak diizinkan', 405);
        $body = getRequestBody();
        $gtk_id            = $body['id'] ?? '';
        $beban_mengajar    = $body['beban_mengajar']     ?? null;
        $kelas_ajar        = $body['kelas_ajar']         ?? null;
        $mapel             = $body['mapel']              ?? null;
        $class_homeroom_a  = $body['class_homeroom']     ?? null;
        $is_bk_a           = isset($body['is_bk']) ? (int)$body['is_bk'] : null;
        $jadwal_json_a     = isset($body['jadwal_mengajar_json']) ? json_encode($body['jadwal_mengajar_json']) : null;
        if (empty($gtk_id)) errorResponse('ID GTK wajib diisi');

        $setClauses = [];
        $params     = [];
        $types      = '';
        if ($beban_mengajar !== null)   { $setClauses[] = 'beban_mengajar=?';       $params[] = $beban_mengajar;   $types .= 's'; }
        if ($kelas_ajar !== null)       { $setClauses[] = 'kelas_ajar=?';           $params[] = $kelas_ajar;       $types .= 's'; }
        if ($mapel !== null)            { $setClauses[] = 'mapel=?';                $params[] = $mapel;            $types .= 's'; }
        if ($class_homeroom_a !== null) { $setClauses[] = 'class_homeroom=?';       $params[] = $class_homeroom_a; $types .= 's'; }
        if ($is_bk_a !== null)          { $setClauses[] = 'is_bk=?';               $params[] = $is_bk_a;          $types .= 'i'; }
        if ($jadwal_json_a !== null)    { $setClauses[] = 'jadwal_mengajar_json=?'; $params[] = $jadwal_json_a;   $types .= 's'; }

        if (empty($setClauses)) { jsonResponse(['success' => true]); break; }
        $params[] = $gtk_id;
        $types   .= 's';

        $sql = 'UPDATE gtk_pegawai SET ' . implode(', ', $setClauses) . ' WHERE id=?';
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            // Sync homeroom to users table if provided
            if ($class_homeroom_a !== null) {
                $syncU = $db->prepare("UPDATE users SET class_homeroom=? WHERE uid=?");
                $syncU->bind_param("ss", $class_homeroom_a, $gtk_id);
                $syncU->execute();
            }
            if ($is_bk_a !== null) {
                $syncU = $db->prepare("UPDATE users SET is_bk=? WHERE uid=?");
                $syncU->bind_param("is", $is_bk_a, $gtk_id);
                $syncU->execute();
            }

            // === TWO-WAY SYNC: gtk_pegawai -> teacher_subjects ===
            // Only sync when mapel or kelas_ajar changed
            if ($mapel !== null || $kelas_ajar !== null) {
                // Read current values from gtk_pegawai in case only one field changed
                $curGTK = $db->prepare("SELECT mapel, kelas_ajar FROM gtk_pegawai WHERE id=?");
                $curGTK->bind_param("s", $gtk_id);
                $curGTK->execute();
                $curRow = $curGTK->get_result()->fetch_assoc();
                $syncMapel   = $mapel    ?? ($curRow['mapel']      ?? '');
                $syncKelas   = $kelas_ajar ?? ($curRow['kelas_ajar'] ?? '');

                // Derive class_level from first kelas (e.g. "X 1, X 2, XI 1" -> "X")
                $kelasList = array_filter(array_map('trim', explode(',', $syncKelas)));
                $firstKelas = $kelasList[0] ?? '';
                $classLevel = preg_match('/^(X{1,3}|XI{0,2}|IX|VIII|VII|XII|XI|X)\b/i', $firstKelas, $m) ? strtoupper($m[1]) : '';

                // class_ids array from kelas list
                $classIdsJson = json_encode(array_values(array_unique($kelasList)));

                // Rebuild teacher_subjects: delete old rows, insert one per mapel entry
                $mapelList = array_filter(array_map('trim', explode(',', $syncMapel)));
                if (!empty($mapelList)) {
                    $db->query("DELETE FROM teacher_subjects WHERE teacher_id='" . $db->real_escape_string($gtk_id) . "'");
                    $insTS = $db->prepare("INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_level, class_ids) VALUES (?,?,?,?,?)");
                    foreach ($mapelList as $mp) {
                        $subCode = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $mp));
                        $insTS->bind_param("sssss", $gtk_id, $subCode, $mp, $classLevel, $classIdsJson);
                        $insTS->execute();
                    }
                }
            }

            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal update atribut GTK: ' . $db->error);
        }
        break;

    // Return NIP + Nama list from gtk_pegawai for autocomplete in Add User modal
    case 'gtk_nip_list':
        $nipRes = $db->query("SELECT nip, nama, email FROM gtk_pegawai WHERE nip IS NOT NULL AND nip != '' ORDER BY nama ASC");
        $nipData = [];
        if ($nipRes) {
            while ($r = $nipRes->fetch_assoc()) {
                $nipData[] = ['nip' => $r['nip'], 'nama' => $r['nama'], 'email' => $r['email']];
            }
        }
        jsonResponse($nipData);
        break;

    case 'gtk_delete':
        $user = requireAuth();
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM gtk_pegawai WHERE id=?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) {
            // Hapus akun user yang terhubung (uid = gtk id)
            $db->query("DELETE FROM users WHERE uid='" . $db->real_escape_string($id) . "'");
            // Hapus data terkait
            $db->query("DELETE FROM teacher_subjects WHERE teacher_id='" . $db->real_escape_string($id) . "'");
            $db->query("DELETE FROM gtk_files WHERE gtk_id='" . $db->real_escape_string($id) . "'");
            $db->query("DELETE FROM gtk_riwayat WHERE gtk_id='" . $db->real_escape_string($id) . "'");
            // Kosongkan penugasan pembina ekskul milik akun ini
            clearCoachAssignment($db, $id);
            jsonResponse(['success' => true]);
        }
        else errorResponse('Gagal menghapus GTK');
        break;

    // ============================================================
    // 2. DATA SISWA
    // ============================================================
    case 'student_list':
        // Cek migrasi sebelum mengambil data
        $checkCol = $db->query("SHOW COLUMNS FROM students LIKE 'nik'");
        if ($checkCol && $checkCol->num_rows === 0) {
            $db->query("ALTER TABLE students ADD COLUMN nik VARCHAR(20) DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN tempat_lahir VARCHAR(100) DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN tanggal_lahir DATE DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN jenis_kelamin VARCHAR(20) DEFAULT 'Laki-laki'");
            $db->query("ALTER TABLE students ADD COLUMN agama VARCHAR(30) DEFAULT 'Islam'");
            $db->query("ALTER TABLE students ADD COLUMN alamat TEXT DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN docs_json TEXT DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN pekerjaan_ortu VARCHAR(100) DEFAULT NULL");
        }
        $checkColYear = $db->query("SHOW COLUMNS FROM students LIKE 'enrollment_year'");
        if ($checkColYear && $checkColYear->num_rows === 0) {
            $db->query("ALTER TABLE students ADD COLUMN enrollment_year VARCHAR(9) DEFAULT NULL");
        }

        // Auto-migration: ubah ENUM status menjadi VARCHAR agar bisa menerima 'Pindah'
        $checkStatus = $db->query("SHOW COLUMNS FROM students LIKE 'status'");
        if ($checkStatus && ($colStatus = $checkStatus->fetch_assoc()) && stripos($colStatus['Type'], 'enum') === 0) {
            $db->query("ALTER TABLE students MODIFY COLUMN status VARCHAR(50) DEFAULT 'Aktif'");
        }

        $res = $db->query("SELECT *, name as nama, class_id as kelas_id, status as status_siswa, parent_name as nama_ortu, parent_phone as kontak_ortu FROM students ORDER BY name ASC");
        
        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['docs_json'] = json_decode($row['docs_json'] ?? '{}', true) ?: new stdClass();
                $data[] = $row;
            }
        }
        jsonResponse($data);
        break;

    case 'student_create':
    case 'student_update':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? uniqid('std_');
        $nisn = $body['nisn'] ?? '';
        $nik = $body['nik'] ?? '';
        $nama = $body['nama'] ?? '';
        $tempat_lahir = $body['tempat_lahir'] ?? '';
        $tanggal_lahir = !empty($body['tanggal_lahir']) ? $body['tanggal_lahir'] : null;
        $jenis_kelamin = $body['jenis_kelamin'] ?? 'Laki-laki';
        $agama = $body['agama'] ?? 'Islam';
        $alamat = $body['alamat'] ?? '';
        $status_siswa = $body['status_siswa'] ?? 'Aktif';
        $kelas_id = $body['kelas_id'] ?? '';
        $nama_ortu = $body['nama_ortu'] ?? '';
        $pekerjaan_ortu = $body['pekerjaan_ortu'] ?? '';
        $kontak_ortu = $body['kontak_ortu'] ?? '';
        $enrollment_year = $body['enrollment_year'] ?? '';
        $docs_json = json_encode($body['docs_json'] ?? new stdClass());

        if (empty($nama)) errorResponse('Nama Siswa wajib diisi');

        // Cari class_name dari tabel classes
        // Jika tidak ketemu (kelas_id mungkin bukan ID tabel classes, tapi nama kelas langsung),
        // gunakan nilai kelas_id itu sendiri sebagai class_name (fallback).
        $class_name = $kelas_id; // default: gunakan kelas_id sebagai nama
        if (!empty($kelas_id)) {
            $cRes = $db->query("SELECT name FROM classes WHERE id = '" . $db->real_escape_string($kelas_id) . "'");
            if ($cRes && $cRes->num_rows > 0) {
                $class_name = $cRes->fetch_assoc()['name'];
            } else {
                // Coba cari via name (kalau kelas_id ternyata adalah nama kelas)
                $cRes2 = $db->query("SELECT id, name FROM classes WHERE name = '" . $db->real_escape_string($kelas_id) . "'");
                if ($cRes2 && $cRes2->num_rows > 0) {
                    $row2 = $cRes2->fetch_assoc();
                    $class_name = $row2['name'];
                    // Jangan ubah kelas_id — biarkan tetap dengan nilai yang dikirim
                }
                // else: kelas tidak ada di tabel classes, pakai kelas_id apa adanya
            }
        }

        // Auto-migration: check if new columns exist
        $checkCol = $db->query("SHOW COLUMNS FROM students LIKE 'nik'");
        if ($checkCol && $checkCol->num_rows === 0) {
            $db->query("ALTER TABLE students ADD COLUMN nik VARCHAR(20) DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN tempat_lahir VARCHAR(100) DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN tanggal_lahir DATE DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN jenis_kelamin VARCHAR(20) DEFAULT 'Laki-laki'");
            $db->query("ALTER TABLE students ADD COLUMN agama VARCHAR(30) DEFAULT 'Islam'");
            $db->query("ALTER TABLE students ADD COLUMN alamat TEXT DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN docs_json TEXT DEFAULT NULL");
            $db->query("ALTER TABLE students ADD COLUMN pekerjaan_ortu VARCHAR(100) DEFAULT NULL");
        }
        $checkColYear = $db->query("SHOW COLUMNS FROM students LIKE 'enrollment_year'");
        if ($checkColYear && $checkColYear->num_rows === 0) {
            $db->query("ALTER TABLE students ADD COLUMN enrollment_year VARCHAR(9) DEFAULT NULL");
        }

        // Auto-migration: ubah ENUM status menjadi VARCHAR agar bisa menerima 'Pindah'
        $checkStatus = $db->query("SHOW COLUMNS FROM students LIKE 'status'");
        if ($checkStatus && ($colStatus = $checkStatus->fetch_assoc()) && stripos($colStatus['Type'], 'enum') === 0) {
            $db->query("ALTER TABLE students MODIFY COLUMN status VARCHAR(50) DEFAULT 'Aktif'");
        }

        if ($action === 'student_create') {
            $stmt = $db->prepare("INSERT INTO students (id, nisn, nik, name, tempat_lahir, tanggal_lahir, jenis_kelamin, agama, alamat, status, class_id, class_name, parent_name, pekerjaan_ortu, parent_phone, enrollment_year, docs_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssssssssssssss", $id, $nisn, $nik, $nama, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $agama, $alamat, $status_siswa, $kelas_id, $class_name, $nama_ortu, $pekerjaan_ortu, $kontak_ortu, $enrollment_year, $docs_json);
        } else {
            $id = $body['id'];
            if (empty($id)) errorResponse('ID siswa wajib ada untuk update');

            // Jika kelas_id kosong, ambil data kelas dari database agar tidak tertimpa kosong
            if (empty($kelas_id)) {
                $existRes = $db->query("SELECT class_id, class_name FROM students WHERE id = '" . $db->real_escape_string($id) . "'");
                if ($existRes && $existRes->num_rows > 0) {
                    $existRow = $existRes->fetch_assoc();
                    $kelas_id  = $existRow['class_id']  ?? '';
                    $class_name = $existRow['class_name'] ?? '';
                }
            }

            $stmt = $db->prepare("UPDATE students SET nisn=?, nik=?, name=?, tempat_lahir=?, tanggal_lahir=?, jenis_kelamin=?, agama=?, alamat=?, status=?, class_id=?, class_name=?, parent_name=?, pekerjaan_ortu=?, parent_phone=?, enrollment_year=?, docs_json=? WHERE id=?");
            $stmt->bind_param("sssssssssssssssss", $nisn, $nik, $nama, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $agama, $alamat, $status_siswa, $kelas_id, $class_name, $nama_ortu, $pekerjaan_ortu, $kontak_ortu, $enrollment_year, $docs_json, $id);
        }
        
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal menyimpan siswa: ' . $db->error);
        break;

    case 'student_delete':
        $user = requireAuth();
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM students WHERE id=?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus siswa');
        break;

    // ============================================================
    // 3. FILE UPLOAD (Generic untuk GTK, Siswa, Surat, Legalisir, Arsip)
    // ============================================================
    case 'tu_file_upload':
        $user = requireAuth();
        // Cek $_POST karena form multipart
        $module = $_POST['module'] ?? ''; // gtk, siswa, surat, legalisir, arsip
        $recordId = $_POST['record_id'] ?? 'umum';
        $kategori = $_POST['kategori'] ?? 'dokumen';

        if (empty($module) || !isset($_FILES['file'])) {
            errorResponse('Module dan file wajib diisi');
        }

        // Perangkat Belajar: hanya GURU pemilik dokumen yang belum VERIFIED (docs/specs/perangkat-belajar-per-guru.md)
        if ($module === 'lesson') {
            $lessonUser = lessonUser($db, ['GURU']);
            lessonAssertMutable($db, $lessonUser, (string)$recordId);
        }

        $file = $_FILES['file'];

        // === VALIDASI & CLEANUP KHUSUS PAS FOTO ===
        if ($kategori === 'pas_foto') {
            // Validasi tipe MIME — hanya gambar
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            $ftype = mime_content_type($file['tmp_name']);
            if (!in_array($ftype, $allowedMimes)) {
                errorResponse('Pas foto hanya menerima format JPG, PNG, atau WebP');
            }
            // Batasi ukuran: max 3MB
            if ($file['size'] > 3 * 1024 * 1024) {
                errorResponse('Ukuran pas foto maksimal 3MB');
            }
            // Hapus foto lama dari disk + DB agar tidak duplikasi
            if ($module === 'gtk' && $recordId !== 'umum') {
                $oldRes = $db->prepare("SELECT path_file FROM gtk_files WHERE gtk_id=? AND kategori='pas_foto' ORDER BY id DESC LIMIT 1");
                $oldRes->bind_param("s", $recordId);
                $oldRes->execute();
                $oldRow = $oldRes->get_result()->fetch_assoc();
                if ($oldRow && !empty($oldRow['path_file'])) {
                    $absOld = __DIR__ . '/../' . $oldRow['path_file'];
                    if (file_exists($absOld)) @unlink($absOld);
                }
                $db->query("DELETE FROM gtk_files WHERE gtk_id='" . $db->real_escape_string($recordId) . "' AND kategori='pas_foto'");
            } elseif ($module === 'siswa' && $recordId !== 'umum') {
                $oldRes = $db->prepare("SELECT path_file FROM siswa_files WHERE siswa_id=? AND kategori='pas_foto' ORDER BY id DESC LIMIT 1");
                $oldRes->bind_param("s", $recordId);
                $oldRes->execute();
                $oldRow = $oldRes->get_result()->fetch_assoc();
                if ($oldRow && !empty($oldRow['path_file'])) {
                    $absOld = __DIR__ . '/../' . $oldRow['path_file'];
                    if (file_exists($absOld)) @unlink($absOld);
                }
                $db->query("DELETE FROM siswa_files WHERE siswa_id='" . $db->real_escape_string($recordId) . "' AND kategori='pas_foto'");
            }
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $kategori . '_' . time() . '.' . $ext;
        
        $safeRecordId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $recordId);
        $uploadDir = __DIR__ . '/../uploads/tu/' . $module . '/' . $safeRecordId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $filePath = $uploadDir . $filename;
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $webPath = 'uploads/tu/' . $module . '/' . $safeRecordId . '/' . $filename;
            
            // Catat ke DB jika ada tabel files
            $size = round($file['size'] / 1024) . ' KB';
            $tgl = date('Y-m-d');

            if ($module === 'gtk' && $recordId !== 'umum') {
                $stmt = $db->prepare("INSERT INTO gtk_files (gtk_id, kategori, nama_file, path_file, ukuran, tanggal_upload, uploaded_by) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param("sssssss", $recordId, $kategori, $file['name'], $webPath, $size, $tgl, $user['uid']);
                $stmt->execute();
            } else if ($module === 'siswa' && $recordId !== 'umum') {
                $stmt = $db->prepare("INSERT INTO siswa_files (siswa_id, kategori, nama_file, path_file, ukuran, tanggal_upload, uploaded_by) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param("sssssss", $recordId, $kategori, $file['name'], $webPath, $size, $tgl, $user['uid']);
                $stmt->execute();
            } else if ($module === 'arsip') {
                $idArsip = uniqid('ar_');
                $stmt = $db->prepare("INSERT INTO arsip_digital (id, kategori, tipe, nama_arsip, tanggal_arsip, file_path, file_size, uploaded_by) VALUES (?,?,?,?,?,?,?,?)");
                // Gunakan recordId sebagai kategori arsip (Kepegawaian/Siswa/Surat/Sekolah)
                $stmt->bind_param("ssssssss", $idArsip, $recordId, $kategori, $file['name'], $tgl, $webPath, $size, $user['uid']);
                $stmt->execute();
            } else if ($module === 'legalisir' && $recordId !== 'umum') {
                // Update path kolom di tabel legalisir sesuai kategori
                $field = ($kategori === 'ktp') ? 'ktp_path' : 'ijazah_path';
                $stmt = $db->prepare("UPDATE legalisir SET {$field} = ? WHERE id = ?");
                $stmt->bind_param("ss", $webPath, $recordId);
                $stmt->execute();
            }

            jsonResponse(['success' => true, 'path' => $webPath, 'nama_file' => $file['name']]);
        } else {
            errorResponse('Gagal mengupload file');
        }
        break;

    // ============================================================
    // 3b. FILE DELETE (hapus file dari disk + DB)
    // ============================================================
    case 'tu_file_delete':
        $user = requireAuth();
        $body = getRequestBody();
        $module    = $body['module'] ?? '';    // gtk | siswa
        $record_id = $body['record_id'] ?? '';
        $kategori  = $body['kategori'] ?? '';  // ktp, kk, ijazah, dst.
        $file_path = $body['file_path'] ?? ''; // relative web path disimpan di docs_json

        if (empty($module) || empty($record_id) || empty($kategori)) {
            errorResponse('module, record_id, kategori wajib diisi');
        }

        // Hapus file fisik dari disk
        if (!empty($file_path)) {
            $abs = __DIR__ . '/../' . $file_path;
            if (file_exists($abs)) @unlink($abs);
        }

        // Hapus record dari tabel files jika ada
        if ($module === 'gtk') {
            $stmt = $db->prepare("DELETE FROM gtk_files WHERE gtk_id=? AND kategori=?");
            $stmt->bind_param("ss", $record_id, $kategori);
            $stmt->execute();

            // Kosongkan kolom docs_json untuk kategori ini
            $docsRes = $db->prepare("SELECT docs_json FROM gtk_pegawai WHERE id=?");
            $docsRes->bind_param("s", $record_id);
            $docsRes->execute();
            $docsRow = $docsRes->get_result()->fetch_assoc();
            if ($docsRow) {
                $docs = json_decode($docsRow['docs_json'] ?? '{}', true) ?: [];
                $docs[$kategori] = false;
                $docsJson = json_encode($docs);
                $upd = $db->prepare("UPDATE gtk_pegawai SET docs_json=? WHERE id=?");
                $upd->bind_param("ss", $docsJson, $record_id);
                $upd->execute();
            }
        } elseif ($module === 'siswa') {
            $stmt = $db->prepare("DELETE FROM siswa_files WHERE siswa_id=? AND kategori=?");
            $stmt->bind_param("ss", $record_id, $kategori);
            $stmt->execute();

            // Kosongkan kolom docs_json untuk kategori ini
            $docsRes = $db->prepare("SELECT docs_json FROM siswa WHERE id=?");
            $docsRes->bind_param("s", $record_id);
            $docsRes->execute();
            $docsRow = $docsRes->get_result()->fetch_assoc();
            if ($docsRow) {
                $docs = json_decode($docsRow['docs_json'] ?? '{}', true) ?: [];
                $docs[$kategori] = false;
                $docsJson = json_encode($docs);
                $upd = $db->prepare("UPDATE siswa SET docs_json=? WHERE id=?");
                $upd->bind_param("ss", $docsJson, $record_id);
                $upd->execute();
            }
        }

        jsonResponse(['success' => true]);
        break;

    // ============================================================
    // 4. SURAT & DISPOSISI
    // ============================================================
    case 'surat_masuk_list':
        $res = $db->query("SELECT * FROM surat_masuk ORDER BY tanggal_surat DESC");
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;
        
    case 'surat_masuk_create':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? uniqid('sm_');
        $nomor_surat = $body['nomor_surat'] ?? '';
        $tanggal_surat = $body['tanggal_surat'] ?? date('Y-m-d');
        $pengirim = $body['pengirim'] ?? '';
        $perihal = $body['perihal'] ?? '';
        $lampiran = $body['lampiran'] ?? '';
        
        $stmt = $db->prepare("INSERT INTO surat_masuk (id, nomor_surat, tanggal_surat, pengirim, perihal, lampiran, created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssss", $id, $nomor_surat, $tanggal_surat, $pengirim, $perihal, $lampiran, $user['uid']);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal menyimpan surat masuk');
        break;

    case 'surat_keluar_list':
        $res = $db->query("SELECT * FROM surat_keluar ORDER BY tanggal DESC");
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'surat_keluar_create':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? uniqid('sk_');
        $nomor_surat = $body['nomor_surat'] ?? '';
        $jenis = $body['jenis'] ?? 'Surat Keterangan';
        $tanggal = $body['tanggal'] ?? date('Y-m-d');
        $perihal = $body['perihal'] ?? '';
        $penerima = $body['penerima'] ?? '';
        
        $stmt = $db->prepare("INSERT INTO surat_keluar (id, nomor_surat, jenis, tanggal, perihal, penerima, created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssss", $id, $nomor_surat, $jenis, $tanggal, $perihal, $penerima, $user['uid']);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal menyimpan surat keluar');
        break;

    // ============================================================
    // 5. LEGALISIR ONLINE
    // ============================================================
    case 'legalisir_list':
        $res = $db->query("SELECT * FROM legalisir ORDER BY created_at DESC");
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'legalisir_history':
        $legalisir_id = $_GET['id'] ?? '';
        if (empty($legalisir_id)) errorResponse('ID legalisir wajib diisi');
        $stmt = $db->prepare("SELECT *, tanggal AS created_at FROM legalisir_history WHERE legalisir_id = ? ORDER BY tanggal ASC");
        $stmt->bind_param("s", $legalisir_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'legalisir_update_status':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        $status = $body['status'] ?? '';
        $catatan = $body['catatan'] ?? '';

        if (empty($id) || empty($status)) errorResponse('ID dan status wajib diisi');

        $stmt = $db->prepare("UPDATE legalisir SET status = ? WHERE id = ?");
        $stmt->bind_param("ss", $status, $id);
        if ($stmt->execute()) {
            $stmtHist = $db->prepare("INSERT INTO legalisir_history (legalisir_id, status, catatan, processed_by, processed_by_name) VALUES (?,?,?,?,?)");
            $stmtHist->bind_param("sssss", $id, $status, $catatan, $user['uid'], $user['displayName']);
            $stmtHist->execute();
            jsonResponse(['success' => true]);
        } else {
            errorResponse('Gagal update status legalisir');
        }
        break;

    // ============================================================
    // 6. SARPRAS & BOOKING
    // ============================================================
    case 'inventaris_list':
        $res = $db->query("SELECT * FROM inventaris ORDER BY nama_barang ASC");
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'ruangan_list':
        $res = $db->query("SELECT * FROM ruangan ORDER BY nama_ruang ASC");
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'booking_list':
        $res = $db->query("SELECT * FROM booking_ruangan ORDER BY tanggal DESC");
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'booking_create':
        $user = requireAuth();
        $body = getRequestBody();
        $ruangan_id = $body['ruangan_id'] ?? '';
        $acara = $body['acara'] ?? '';
        $tanggal = $body['tanggal'] ?? '';
        $jam_mulai = $body['jam_mulai'] ?? '';
        $jam_selesai = $body['jam_selesai'] ?? '';
        $pic = $body['pic'] ?? $user['displayName'];
        
        $stmt = $db->prepare("INSERT INTO booking_ruangan (ruangan_id, acara, tanggal, jam_mulai, jam_selesai, pic, created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssss", $ruangan_id, $acara, $tanggal, $jam_mulai, $jam_selesai, $pic, $user['uid']);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menyimpan booking');
        break;

    case 'booking_update_status':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        $status = $body['status'] ?? ''; // Disetujui, Ditolak
        
        $stmt = $db->prepare("UPDATE booking_ruangan SET status=? WHERE id=?");
        $stmt->bind_param("ss", $status, $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal update status booking');
        break;

    // ============================================================
    // 7. ARSIP DIGITAL
    // ============================================================
    case 'arsip_list':
        $res = $db->query("SELECT * FROM arsip_digital ORDER BY tanggal_arsip DESC");
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'arsip_delete':
        $user = requireAuth();
        $id = $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM arsip_digital WHERE id=?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus arsip');
        break;

    // ============================================================
    // 8. GTK RIWAYAT KARIR
    // ============================================================
    case 'gtk_riwayat_list':
        $gtk_id = $_GET['gtk_id'] ?? '';
        if (empty($gtk_id)) errorResponse('gtk_id wajib diisi');
        $stmt = $db->prepare("SELECT * FROM gtk_riwayat WHERE gtk_id = ? ORDER BY tanggal DESC");
        $stmt->bind_param("s", $gtk_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'gtk_riwayat_add':
        $user = requireAuth();
        $body = getRequestBody();
        $gtk_id    = $body['gtk_id'] ?? '';
        $kategori  = $body['kategori'] ?? 'pangkat';
        $tanggal   = $body['tanggal'] ?? date('Y-m-d');
        $judul     = $body['judul'] ?? '';
        $keterangan = $body['keterangan'] ?? '';
        if (empty($gtk_id) || empty($judul)) errorResponse('gtk_id dan judul wajib diisi');
        $stmt = $db->prepare("INSERT INTO gtk_riwayat (gtk_id, kategori, tanggal, judul, keterangan) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $gtk_id, $kategori, $tanggal, $judul, $keterangan);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $db->insert_id]);
        else errorResponse('Gagal menambah riwayat GTK');
        break;

    case 'gtk_riwayat_delete':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM gtk_riwayat WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus riwayat GTK');
        break;

    case 'gtk_riwayat_update':
        $user = requireAuth();
        $body = getRequestBody();
        $id         = $body['id'] ?? '';
        $kategori   = $body['kategori'] ?? '';
        $tanggal    = $body['tanggal'] ?? date('Y-m-d');
        $judul      = $body['judul'] ?? '';
        $keterangan = $body['keterangan'] ?? '';
        if (empty($id) || empty($judul)) errorResponse('ID dan judul wajib diisi');
        $stmt = $db->prepare("UPDATE gtk_riwayat SET kategori=?, tanggal=?, judul=?, keterangan=? WHERE id=?");
        $stmt->bind_param("ssssi", $kategori, $tanggal, $judul, $keterangan, $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal mengupdate riwayat GTK');
        break;

    // ============================================================
    // 9. STUDENT RIWAYAT (Prestasi, Pelanggaran, Beasiswa)
    // ============================================================
    case 'student_riwayat_list':
        $siswa_id = $_GET['siswa_id'] ?? '';
        if (empty($siswa_id)) errorResponse('siswa_id wajib diisi');
        $stmt = $db->prepare("SELECT * FROM siswa_riwayat WHERE siswa_id = ? ORDER BY tanggal DESC");
        $stmt->bind_param("s", $siswa_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'student_riwayat_add':
        $user = requireAuth();
        $body = getRequestBody();
        $siswa_id  = $body['siswa_id'] ?? '';
        $kategori  = $body['kategori'] ?? 'prestasi';
        $tanggal   = $body['tanggal'] ?? date('Y-m-d');
        $judul     = $body['judul'] ?? '';
        $keterangan = $body['keterangan'] ?? '';
        if (empty($siswa_id) || empty($judul)) errorResponse('siswa_id dan judul wajib diisi');
        $stmt = $db->prepare("INSERT INTO siswa_riwayat (siswa_id, kategori, tanggal, judul, keterangan) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $siswa_id, $kategori, $tanggal, $judul, $keterangan);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $db->insert_id]);
        else errorResponse('Gagal menambah riwayat siswa');
        break;

    case 'student_riwayat_delete':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM siswa_riwayat WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus riwayat siswa');
        break;

    // ============================================================
    // 10. DISPOSISI SURAT MASUK
    // ============================================================
    case 'disposisi_list':
        $surat_id = $_GET['surat_id'] ?? '';
        if (empty($surat_id)) errorResponse('surat_id wajib diisi');
        $stmt = $db->prepare("SELECT * FROM disposisi WHERE surat_id = ? ORDER BY created_at ASC");
        $stmt->bind_param("s", $surat_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        jsonResponse($data);
        break;

    case 'disposisi_add':
        $user = requireAuth();
        $body = getRequestBody();
        $surat_id  = $body['surat_id'] ?? '';
        $from_role = $body['from_role'] ?? $user['role'];
        $to_unit   = $body['to_unit'] ?? '';
        $tanggal   = $body['tanggal'] ?? date('Y-m-d');
        $catatan   = $body['catatan'] ?? '';
        if (empty($surat_id) || empty($to_unit)) errorResponse('surat_id dan to_unit wajib diisi');
        $stmt = $db->prepare("INSERT INTO disposisi (surat_id, from_role, to_unit, tanggal, catatan, created_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("ssssss", $surat_id, $from_role, $to_unit, $tanggal, $catatan, $user['uid']);
        if ($stmt->execute()) {
            // Tandai surat masuk sebagai sudah didisposisi
            $db->query("UPDATE surat_masuk SET is_disposed=1 WHERE id='" . $db->real_escape_string($surat_id) . "'");
            jsonResponse(['success' => true, 'id' => $db->insert_id]);
        } else errorResponse('Gagal menambah disposisi');
        break;

    // ============================================================
    // 11. LEGALISIR CREATE (Publik — tanpa requireAuth)
    // ============================================================
    case 'legalisir_create':
        $body = getRequestBody();
        $id           = uniqid('leg_');
        $nik          = $body['nik'] ?? '';
        $nama         = $body['nama'] ?? '';
        $tahun_lulus  = $body['tahun_lulus'] ?? '';
        $nomor_ijazah = $body['nomor_ijazah'] ?? '';
        $email        = $body['email'] ?? '';
        $nomor_wa     = $body['nomor_wa'] ?? '';
        if (empty($nik) || empty($nama)) errorResponse('NIK dan Nama wajib diisi');
        $stmt = $db->prepare("INSERT INTO legalisir (id, nik, nama, tahun_lulus, nomor_ijazah, email, nomor_wa) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssss", $id, $nik, $nama, $tahun_lulus, $nomor_ijazah, $email, $nomor_wa);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal membuat pengajuan legalisir');
        break;

    // ============================================================
    // 12. INVENTARIS CRUD
    // ============================================================
    case 'inventaris_create':
        $user = requireAuth();
        $body = getRequestBody();
        $id              = uniqid('inv_');
        $kode_barang     = $body['kode_barang'] ?? '';
        $nama_barang     = $body['nama_barang'] ?? '';
        $merk            = $body['merk'] ?? '';
        $tahun_pengadaan = $body['tahun_pengadaan'] ?? date('Y');
        $kondisi         = $body['kondisi'] ?? 'Baik';
        $lokasi          = $body['lokasi'] ?? '';
        $kategori        = $body['kategori'] ?? 'Lainnya';
        if (empty($nama_barang)) errorResponse('Nama barang wajib diisi');
        $stmt = $db->prepare("INSERT INTO inventaris (id, kode_barang, nama_barang, merk, tahun_pengadaan, kondisi, lokasi, kategori) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssss", $id, $kode_barang, $nama_barang, $merk, $tahun_pengadaan, $kondisi, $lokasi, $kategori);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal menambah inventaris');
        break;

    case 'inventaris_update':
        $user = requireAuth();
        $body = getRequestBody();
        $id              = $body['id'] ?? '';
        $kode_barang     = $body['kode_barang'] ?? '';
        $nama_barang     = $body['nama_barang'] ?? '';
        $merk            = $body['merk'] ?? '';
        $tahun_pengadaan = $body['tahun_pengadaan'] ?? '';
        $kondisi         = $body['kondisi'] ?? 'Baik';
        $lokasi          = $body['lokasi'] ?? '';
        $kategori        = $body['kategori'] ?? 'Lainnya';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("UPDATE inventaris SET kode_barang=?, nama_barang=?, merk=?, kondisi=?, lokasi=?, tahun_pengadaan=?, kategori=? WHERE id=?");
        $stmt->bind_param("ssssssss", $kode_barang, $nama_barang, $merk, $kondisi, $lokasi, $tahun_pengadaan, $kategori, $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal update inventaris');
        break;

    case 'inventaris_delete':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM inventaris WHERE id=?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus inventaris');
        break;

    // ============================================================
    // 13. RUANGAN CRUD
    // ============================================================
    case 'ruangan_create':
        $user = requireAuth();
        $body = getRequestBody();
        $id         = uniqid('r_');
        $nama_ruang = $body['nama_ruang'] ?? '';
        $tipe       = $body['tipe'] ?? 'Ruang Kelas';
        $kapasitas  = (int)($body['kapasitas'] ?? 30);
        $kondisi    = $body['kondisi'] ?? 'Baik';
        if (empty($nama_ruang)) errorResponse('Nama ruang wajib diisi');
        $stmt = $db->prepare("INSERT INTO ruangan (id, nama_ruang, tipe, kapasitas, kondisi) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $id, $nama_ruang, $tipe, $kapasitas, $kondisi);
        if ($stmt->execute()) jsonResponse(['success' => true, 'id' => $id]);
        else errorResponse('Gagal menambah ruangan');
        break;

    case 'ruangan_update':
        $user = requireAuth();
        $body = getRequestBody();
        $id         = $body['id'] ?? '';
        $nama_ruang = $body['nama_ruang'] ?? '';
        $tipe       = $body['tipe'] ?? 'Ruang Kelas';
        $kapasitas  = (int)($body['kapasitas'] ?? 30);
        $kondisi    = $body['kondisi'] ?? 'Baik';
        if (empty($id) || empty($nama_ruang)) errorResponse('ID dan Nama ruang wajib diisi');
        $stmt = $db->prepare("UPDATE ruangan SET nama_ruang=?, tipe=?, kapasitas=?, kondisi=? WHERE id=?");
        $stmt->bind_param("sssss", $nama_ruang, $tipe, $kapasitas, $kondisi, $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal update ruangan');
        break;

    case 'ruangan_delete':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM ruangan WHERE id=?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus ruangan');
        break;

    case 'booking_update':
        $user = requireAuth();
        $body = getRequestBody();
        $id          = $body['id'] ?? '';
        $ruangan_id  = $body['ruangan_id'] ?? '';
        $acara       = $body['acara'] ?? '';
        $tanggal     = $body['tanggal'] ?? '';
        $jam_mulai   = $body['jam_mulai'] ?? '';
        $jam_selesai = $body['jam_selesai'] ?? '';
        $pic         = $body['pic'] ?? $user['displayName'];
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("UPDATE booking_ruangan SET ruangan_id=?, acara=?, tanggal=?, jam_mulai=?, jam_selesai=?, pic=? WHERE id=?");
        $stmt->bind_param("sssssss", $ruangan_id, $acara, $tanggal, $jam_mulai, $jam_selesai, $pic, $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal update booking');
        break;

    case 'booking_delete':
        $user = requireAuth();
        $body = getRequestBody();
        $id = $body['id'] ?? $_GET['id'] ?? '';
        if (empty($id)) errorResponse('ID wajib diisi');
        $stmt = $db->prepare("DELETE FROM booking_ruangan WHERE id=?");
        $stmt->bind_param("s", $id);
        if ($stmt->execute()) jsonResponse(['success' => true]);
        else errorResponse('Gagal menghapus booking');
        break;

    // ============================================================
    // 14. PRESENSI KEPEGAWAIAN (Staff Attendance)
    // ============================================================

    /**
     * GET ?action=staff_attendance_list
     * Query params:
     *   tanggal   = YYYY-MM-DD  (filter by hari)
     *   bulan     = YYYY-MM     (filter by bulan, untuk rekap)
     *   gtk_id    = string      (filter by pegawai tertentu)
     *
     * Response: array presensi, termasuk pegawai yang belum absen (status null)
     */
    case 'staff_attendance_list':
        $tanggal   = $_GET['tanggal'] ?? '';
        $bulan     = $_GET['bulan']   ?? '';
        $gtkFilter = $_GET['gtk_id']  ?? '';

        // Auto-migration: buat tabel jika belum ada
        $db->query("CREATE TABLE IF NOT EXISTS `staff_attendance` (
            `id` VARCHAR(128) NOT NULL,
            `gtk_id` VARCHAR(128) NOT NULL,
            `gtk_nama` VARCHAR(255) NOT NULL,
            `nip` VARCHAR(50) DEFAULT NULL,
            `jabatan` VARCHAR(100) DEFAULT NULL,
            `pekerjaan_kategori` VARCHAR(100) DEFAULT NULL,
            `tanggal` DATE NOT NULL,
            `jam_masuk` TIME DEFAULT NULL,
            `jam_keluar` TIME DEFAULT NULL,
            `status` ENUM('HADIR','TERLAMBAT','IZIN','SAKIT','ALPHA') NOT NULL DEFAULT 'HADIR',
            `keterangan` TEXT DEFAULT NULL,
            `lokasi` VARCHAR(100) DEFAULT 'Sekolah',
            `recorded_by` VARCHAR(128) DEFAULT NULL,
            `recorded_by_name` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_gtk_tanggal` (`gtk_id`, `tanggal`),
            KEY `idx_sa_tanggal` (`tanggal`),
            KEY `idx_sa_gtk_id` (`gtk_id`),
            KEY `idx_sa_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Jika filter by tanggal: LEFT JOIN gtk_pegawai dengan presensi hari itu
        // sehingga pegawai yang belum absen juga muncul (status = null)
        if (!empty($tanggal)) {
            $safeTgl = $db->real_escape_string($tanggal);
            $whereGtk = !empty($gtkFilter) ? "AND p.id = '" . $db->real_escape_string($gtkFilter) . "'" : '';
            $sql = "
                SELECT
                    p.id AS gtk_id,
                    p.nama AS gtk_nama,
                    p.nip,
                    p.jabatan,
                    p.pekerjaan_kategori,
                    JSON_UNQUOTE(JSON_EXTRACT(p.docs_json, '$.pas_foto')) AS pas_foto,
                    sa.id         AS attendance_id,
                    sa.tanggal,
                    sa.jam_masuk,
                    sa.jam_keluar,
                    sa.status,
                    sa.keterangan,
                    sa.lokasi,
                    sa.recorded_by,
                    sa.recorded_by_name
                FROM gtk_pegawai p
                LEFT JOIN staff_attendance sa
                    ON sa.gtk_id = p.id AND sa.tanggal = '$safeTgl'
                WHERE 1=1 $whereGtk
                ORDER BY p.nama ASC
            ";
            $res = $db->query($sql);
            $data = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $row['tanggal'] = $row['tanggal'] ?? $tanggal;
                    $data[] = $row;
                }
            }
            jsonResponse($data);
        }

        // Jika filter by bulan: ambil semua record presensi di bulan itu
        if (!empty($bulan)) {
            $safeBulan = $db->real_escape_string($bulan);
            $whereGtk  = !empty($gtkFilter) ? "AND sa.gtk_id = '" . $db->real_escape_string($gtkFilter) . "'" : '';
            $sql = "
                SELECT sa.*, p.jabatan, p.pekerjaan_kategori, JSON_UNQUOTE(JSON_EXTRACT(p.docs_json, '$.pas_foto')) AS pas_foto
                FROM staff_attendance sa
                LEFT JOIN gtk_pegawai p ON p.id = sa.gtk_id
                WHERE DATE_FORMAT(sa.tanggal, '%Y-%m') = '$safeBulan'
                $whereGtk
                ORDER BY sa.tanggal DESC, sa.gtk_nama ASC
            ";
            $res = $db->query($sql);
            $data = [];
            while ($row = $res->fetch_assoc()) $data[] = $row;
            jsonResponse($data);
        }

        // Default: ambil presensi hari ini
        $today = date('Y-m-d');
        $sql = "
            SELECT
                p.id AS gtk_id, p.nama AS gtk_nama, p.nip, p.jabatan, p.pekerjaan_kategori,
                JSON_UNQUOTE(JSON_EXTRACT(p.docs_json, '$.pas_foto')) AS pas_foto,
                sa.id AS attendance_id, sa.tanggal,
                sa.jam_masuk, sa.jam_keluar, sa.status,
                sa.keterangan, sa.lokasi, sa.recorded_by, sa.recorded_by_name
            FROM gtk_pegawai p
            LEFT JOIN staff_attendance sa ON sa.gtk_id = p.id AND sa.tanggal = '$today'
            ORDER BY p.nama ASC
        ";
        $res = $db->query($sql);
        $data = [];
        if ($res) while ($row = $res->fetch_assoc()) { $row['tanggal'] = $today; $data[] = $row; }
        jsonResponse($data);
        break;

    /**
     * POST ?action=staff_attendance_save
     * Body: { gtk_id, tanggal, jam_masuk?, jam_keluar?, status, keterangan?, lokasi? }
     *
     * Dipakai untuk:
     *  - Clock In mandiri oleh GURU (hanya set jam_masuk & status)
     *  - Clock Out mandiri oleh GURU (hanya update jam_keluar)
     *  - Input tunggal oleh Admin/TU
     */
    case 'staff_attendance_save':
        $user = requireAuth();
        $body = getRequestBody();
        $gtk_id   = $body['gtk_id']  ?? '';
        $tanggal  = $body['tanggal'] ?? date('Y-m-d');
        $jam_masuk  = !empty($body['jam_masuk'])  ? $body['jam_masuk']  : null;
        $jam_keluar = !empty($body['jam_keluar']) ? $body['jam_keluar'] : null;
        $status   = $body['status']   ?? 'HADIR';
        $keterangan = $body['keterangan'] ?? null;
        $lokasi   = $body['lokasi']   ?? 'Sekolah';

        if (empty($gtk_id)) errorResponse('gtk_id wajib diisi');

        // Auto-migration
        $db->query("CREATE TABLE IF NOT EXISTS `staff_attendance` (
            `id` VARCHAR(128) NOT NULL,
            `gtk_id` VARCHAR(128) NOT NULL,
            `gtk_nama` VARCHAR(255) NOT NULL,
            `nip` VARCHAR(50) DEFAULT NULL,
            `jabatan` VARCHAR(100) DEFAULT NULL,
            `pekerjaan_kategori` VARCHAR(100) DEFAULT NULL,
            `tanggal` DATE NOT NULL,
            `jam_masuk` TIME DEFAULT NULL,
            `jam_keluar` TIME DEFAULT NULL,
            `status` ENUM('HADIR','TERLAMBAT','IZIN','SAKIT','ALPHA') NOT NULL DEFAULT 'HADIR',
            `keterangan` TEXT DEFAULT NULL,
            `lokasi` VARCHAR(100) DEFAULT 'Sekolah',
            `recorded_by` VARCHAR(128) DEFAULT NULL,
            `recorded_by_name` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_gtk_tanggal` (`gtk_id`, `tanggal`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Ambil info GTK
        $gtkRes = $db->prepare("SELECT nama, nip, jabatan, pekerjaan_kategori FROM gtk_pegawai WHERE id = ?");
        $gtkRes->bind_param("s", $gtk_id);
        $gtkRes->execute();
        $gtkRow = $gtkRes->get_result()->fetch_assoc();
        if (!$gtkRow) errorResponse('Data GTK tidak ditemukan untuk gtk_id: ' . $gtk_id);

        $gtk_nama   = $gtkRow['nama'];
        $nip        = $gtkRow['nip'] ?? '';
        $jabatan    = $gtkRow['jabatan'] ?? '';
        $pek_kat    = $gtkRow['pekerjaan_kategori'] ?? '';
        $recorded_by      = $user['uid'];
        $recorded_by_name = $user['displayName'];

        $id = 'sa_' . $gtk_id . '_' . str_replace('-', '', $tanggal);

        // Cek apakah sudah ada record untuk hari ini
        $existing = $db->prepare("SELECT id, jam_masuk FROM staff_attendance WHERE gtk_id = ? AND tanggal = ?");
        $existing->bind_param("ss", $gtk_id, $tanggal);
        $existing->execute();
        $existRow = $existing->get_result()->fetch_assoc();

        if ($existRow) {
            // UPDATE — jika clock out, hanya update jam_keluar + status
            // Jika clock in ulang, update jam_masuk dan status
            $setClauses = ["status=?", "keterangan=?", "lokasi=?", "recorded_by=?", "recorded_by_name=?"];
            $params = [$status, $keterangan, $lokasi, $recorded_by, $recorded_by_name];
            $types = "sssss";

            if ($jam_masuk !== null) {
                $setClauses[] = "jam_masuk=?";
                $params[] = $jam_masuk;
                $types .= "s";
            }
            if ($jam_keluar !== null) {
                $setClauses[] = "jam_keluar=?";
                $params[] = $jam_keluar;
                $types .= "s";
            }
            $params[] = $gtk_id;
            $params[] = $tanggal;
            $types .= "ss";

            $sql = "UPDATE staff_attendance SET " . implode(", ", $setClauses) . " WHERE gtk_id=? AND tanggal=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
        } else {
            // INSERT baru
            $stmt = $db->prepare("INSERT INTO staff_attendance (id, gtk_id, gtk_nama, nip, jabatan, pekerjaan_kategori, tanggal, jam_masuk, jam_keluar, status, keterangan, lokasi, recorded_by, recorded_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssssssssssss",
                $id, $gtk_id, $gtk_nama, $nip, $jabatan, $pek_kat,
                $tanggal, $jam_masuk, $jam_keluar,
                $status, $keterangan, $lokasi,
                $recorded_by, $recorded_by_name
            );
        }

        if ($stmt->execute()) {
            jsonResponse(['success' => true, 'id' => $existRow ? $existRow['id'] : $id]);
        } else {
            errorResponse('Gagal menyimpan presensi: ' . $db->error);
        }
        break;

    /**
     * POST ?action=staff_attendance_bulk_save
     * Body: { tanggal: "YYYY-MM-DD", records: [{ gtk_id, status, jam_masuk?, jam_keluar?, keterangan?, lokasi? }] }
     *
     * Dipakai Admin/TU untuk input presensi banyak pegawai sekaligus dalam satu tanggal.
     */
    case 'staff_attendance_bulk_save':
        $user = requireAuth();
        $body    = getRequestBody();
        $tanggal = $body['tanggal'] ?? date('Y-m-d');
        $records = $body['records'] ?? [];

        if (empty($records) || !is_array($records)) {
            errorResponse('records wajib diisi sebagai array');
        }

        // Auto-migration
        $db->query("CREATE TABLE IF NOT EXISTS `staff_attendance` (
            `id` VARCHAR(128) NOT NULL,
            `gtk_id` VARCHAR(128) NOT NULL,
            `gtk_nama` VARCHAR(255) NOT NULL,
            `nip` VARCHAR(50) DEFAULT NULL,
            `jabatan` VARCHAR(100) DEFAULT NULL,
            `pekerjaan_kategori` VARCHAR(100) DEFAULT NULL,
            `tanggal` DATE NOT NULL,
            `jam_masuk` TIME DEFAULT NULL,
            `jam_keluar` TIME DEFAULT NULL,
            `status` ENUM('HADIR','TERLAMBAT','IZIN','SAKIT','ALPHA') NOT NULL DEFAULT 'HADIR',
            `keterangan` TEXT DEFAULT NULL,
            `lokasi` VARCHAR(100) DEFAULT 'Sekolah',
            `recorded_by` VARCHAR(128) DEFAULT NULL,
            `recorded_by_name` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_gtk_tanggal` (`gtk_id`, `tanggal`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $recorded_by      = $user['uid'];
        $recorded_by_name = $user['displayName'];
        $saved = 0;
        $errors = [];

        $stmtGTK = $db->prepare("SELECT nama, nip, jabatan, pekerjaan_kategori FROM gtk_pegawai WHERE id = ?");

        foreach ($records as $rec) {
            $gtk_id     = $rec['gtk_id'] ?? '';
            $status     = $rec['status'] ?? 'HADIR';
            $jam_masuk  = !empty($rec['jam_masuk'])  ? $rec['jam_masuk']  : null;
            $jam_keluar = !empty($rec['jam_keluar']) ? $rec['jam_keluar'] : null;
            $keterangan = $rec['keterangan'] ?? null;
            $lokasi     = $rec['lokasi']     ?? 'Sekolah';

            if (empty($gtk_id)) continue;

            $stmtGTK->bind_param("s", $gtk_id);
            $stmtGTK->execute();
            $gtkRow = $stmtGTK->get_result()->fetch_assoc();
            if (!$gtkRow) { $errors[] = "GTK tidak ditemukan: $gtk_id"; continue; }

            $gtk_nama = $gtkRow['nama'];
            $nip      = $gtkRow['nip'] ?? '';
            $jabatan  = $gtkRow['jabatan'] ?? '';
            $pek_kat  = $gtkRow['pekerjaan_kategori'] ?? '';
            $id       = 'sa_' . $gtk_id . '_' . str_replace('-', '', $tanggal);

            $upsert = $db->prepare("
                INSERT INTO staff_attendance
                    (id, gtk_id, gtk_nama, nip, jabatan, pekerjaan_kategori, tanggal, jam_masuk, jam_keluar, status, keterangan, lokasi, recorded_by, recorded_by_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    jam_masuk=IF(VALUES(jam_masuk) IS NOT NULL, VALUES(jam_masuk), jam_masuk),
                    jam_keluar=IF(VALUES(jam_keluar) IS NOT NULL, VALUES(jam_keluar), jam_keluar),
                    keterangan=VALUES(keterangan),
                    lokasi=VALUES(lokasi),
                    recorded_by=VALUES(recorded_by),
                    recorded_by_name=VALUES(recorded_by_name)
            ");
            $upsert->bind_param("ssssssssssssss",
                $id, $gtk_id, $gtk_nama, $nip, $jabatan, $pek_kat,
                $tanggal, $jam_masuk, $jam_keluar,
                $status, $keterangan, $lokasi,
                $recorded_by, $recorded_by_name
            );

            if ($upsert->execute()) {
                $saved++;
            } else {
                $errors[] = "Gagal simpan GTK $gtk_id: " . $db->error;
            }
        }

        jsonResponse(['success' => true, 'saved' => $saved, 'errors' => $errors]);
        break;

    default:
        return false;
}
return true;
