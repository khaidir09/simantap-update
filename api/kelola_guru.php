<?php
// kelola_guru.php - Helper halaman Kelola Guru: login terakhir (users.last_login_at),
// status penugasan mapel & jadwal KBM per akun, dan aturan perubahan email (khusus ADMIN).
// Lihat docs/specs/kelola-guru-tabel-modal.md
if (!defined('SIMANTAP_API')) exit;

/**
 * Pastikan kolom users.last_login_at ada (dibuat sekali, idempoten).
 * Kolom lama users.lastLogin ber-ON UPDATE sehingga berubah setiap baris diubah,
 * jadi tidak bisa dipakai sebagai waktu login. Gagal ALTER tidak boleh menggagalkan request.
 */
function kgPastikanKolomLogin(mysqli $db): bool
{
    static $ada = null;
    if ($ada !== null) return $ada;
    try {
        $res = $db->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
        if ($res && $res->num_rows > 0) return $ada = true;
        try {
            $db->query("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER lastLogin");
        } catch (Throwable $e) {
            // Bisa jadi request lain baru saja menambahkan kolom yang sama — cek ulang di bawah.
            error_log('[SIMANTAP] ALTER users.last_login_at: ' . $e->getMessage());
        }
        $res = $db->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
        return $ada = ($res && $res->num_rows > 0);
    } catch (Throwable $e) {
        error_log('[SIMANTAP] cek kolom users.last_login_at gagal: ' . $e->getMessage());
        return $ada = false;
    }
}

/**
 * Catat waktu login sukses. lastLogin = lastLogin mencegah ON UPDATE mengubah kolom lama.
 */
function kgCatatLogin(mysqli $db, string $uid): void
{
    if (!kgPastikanKolomLogin($db)) return;
    try {
        $waktu = date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE users SET last_login_at = ?, lastLogin = lastLogin WHERE uid = ?");
        $stmt->bind_param("ss", $waktu, $uid);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[SIMANTAP] catat last_login_at gagal: ' . $e->getMessage() . ' | uid=' . $uid);
    }
}

/**
 * Selisih hari kalender (zona APP_TIMEZONE) antara hari ini dan waktu login.
 */
function kgHariSejak(?string $waktu): ?int
{
    if (empty($waktu)) return null;
    $tgl = DateTime::createFromFormat('Y-m-d', substr($waktu, 0, 10));
    if (!$tgl) return null;
    $hariIni = new DateTime(date('Y-m-d'));
    $tgl->setTime(0, 0, 0);
    $selisih = (int)$hariIni->diff($tgl)->format('%r%a');
    return $selisih >= 0 ? 0 : -$selisih;
}

/**
 * Tambahkan mapel_count, jadwal_count, last_login_at, last_login_hari ke setiap baris get_users.
 * Tanpa N+1: satu query per sumber data.
 */
function kgStatusGuru(mysqli $db, array $users): array
{
    $mapel = [];
    $res = $db->query("SELECT teacher_id, COUNT(DISTINCT subject_code) AS jml FROM teacher_subjects GROUP BY teacher_id");
    if ($res) while ($r = $res->fetch_assoc()) $mapel[(string)$r['teacher_id']] = (int)$r['jml'];

    // Di-decode di PHP (bukan JSON_LENGTH) agar JSON rusak tidak melempar error.
    $jadwal = [];
    $res = $db->query("SELECT id, jadwal_mengajar_json FROM gtk_pegawai");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $raw = trim((string)($r['jadwal_mengajar_json'] ?? ''));
            if ($raw === '') { $jadwal[(string)$r['id']] = 0; continue; }
            $isi = json_decode($raw, true);
            $jadwal[(string)$r['id']] = (is_array($isi) && array_values($isi) === $isi) ? count($isi) : null;
        }
    }

    $login = [];
    if (kgPastikanKolomLogin($db)) {
        $res = $db->query("SELECT uid, last_login_at FROM users");
        if ($res) while ($r = $res->fetch_assoc()) $login[(string)$r['uid']] = $r['last_login_at'];
    }

    foreach ($users as &$u) {
        $uid = (string)$u['uid'];
        $u['mapel_count']     = $mapel[$uid] ?? 0;
        $u['jadwal_count']    = array_key_exists($uid, $jadwal) ? $jadwal[$uid] : 0;
        $u['last_login_at']   = $login[$uid] ?? null;
        $u['last_login_hari'] = kgHariSejak($u['last_login_at']);
    }
    unset($u);
    return $users;
}

/**
 * Validasi email untuk save_user / save_user_v2 sebelum UPDATE/INSERT.
 * - format email wajib valid (400);
 * - perubahan email akun yang sudah ada hanya oleh ADMIN, role dibaca ulang dari DB (403);
 * - email tidak boleh dipakai akun lain (409).
 * Mengembalikan email lama bila akun sudah ada, null bila akun baru.
 */
function kgValidasiEmail(mysqli $db, string $uid, string $email): ?string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) errorResponse('Format email tidak valid');

    $stmt = $db->prepare("SELECT email FROM users WHERE uid = ? LIMIT 1");
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $emailLama = $row ? strtolower(trim((string)$row['email'])) : null;

    if ($emailLama !== null && $emailLama !== $email) {
        $aktor = loadFreshUser($db, requireAuth());
        if ($aktor['role'] !== 'ADMIN') errorResponse('Hanya ADMIN yang dapat mengubah email pengguna', 403);
    }

    $stmt = $db->prepare("SELECT uid FROM users WHERE email = ? AND uid <> ? LIMIT 1");
    $stmt->bind_param("ss", $email, $uid);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) errorResponse('Email sudah dipakai oleh pengguna lain', 409);

    return $emailLama;
}

/**
 * Setelah email akun berubah: pindahkan baris teacher_registry (PK email) dan perbarui sesi
 * bila yang diubah adalah akun aktor sendiri.
 */
function kgSetelahEmailBerubah(mysqli $db, string $uid, string $emailLama, string $emailBaru): void
{
    try {
        $cek = $db->prepare("SELECT email FROM teacher_registry WHERE email = ? LIMIT 1");
        $cek->bind_param("s", $emailBaru);
        $cek->execute();
        if ($cek->get_result()->num_rows > 0) {
            $stmt = $db->prepare("DELETE FROM teacher_registry WHERE email = ?");
            $stmt->bind_param("s", $emailLama);
        } else {
            $stmt = $db->prepare("UPDATE teacher_registry SET email = ? WHERE email = ?");
            $stmt->bind_param("ss", $emailBaru, $emailLama);
        }
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[SIMANTAP] sinkron teacher_registry email gagal: ' . $e->getMessage() . ' | uid=' . $uid);
    }

    if (isset($_SESSION['user']) && ($_SESSION['user']['uid'] ?? '') === $uid) {
        $_SESSION['user']['email'] = $emailBaru;
    }
}
