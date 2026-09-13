<?php
/**
 * SIMANTAP Installer - Wizard Instalasi First-Run
 *
 * Alur: 1) Cek kebutuhan server  2) Database (tes + impor)
 *       3) Aktivasi token ke Portal SIMANTAP BTIKP (wajib online)
 *       4) Finalisasi (identitas sekolah + akun admin) → tulis install.lock
 *
 * Keamanan: file lock dicek pada SETIAP request; langkah divalidasi
 * berurutan server-side via session; aktivasi token adalah gerbang
 * ke langkah finalisasi.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/installer_config.php';

error_reporting(0);
ini_set('display_errors', '0');

session_name('SIMANTAP_INSTALL');
session_start();

$step = $_GET['step'] ?? null;

// ── Lock: instalasi selesai ───────────────────────────
if (installerLocked()) {
    // Lisensi masih valid? → installer benar-benar terkunci.
    // Lisensi tidak valid (mis. pindah domain/hosting) → izinkan HANYA
    // aktivasi ulang token ke portal, tanpa menyentuh database.
    $licenseOk = true;
    $licenseMsg = '';
    if (is_readable(__DIR__ . '/../api/license_guard.php')) {
        require_once __DIR__ . '/../api/license_guard.php';
        $licStatus = simantapLicenseStatus();
        $licenseOk = $licStatus['ok'];
        $licenseMsg = $licStatus['message'];
    }

    if (!$licenseOk && $step === 'reactivate') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') installerError('Metode tidak diizinkan.', 405);
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) $body = [];
        $npsn = trim((string)($body['npsn'] ?? ''));
        $token = strtoupper(trim((string)($body['token'] ?? '')));
        if (!preg_match('/^\d{8}$/', $npsn)) installerError('Format NPSN tidak valid (harus 8 digit angka).');
        if (!preg_match('/^SMTP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $token)) {
            installerError('Format token tidak valid. Contoh: SMTP-K7X2-9BF4-LM8Q');
        }

        $installedUrl = detectInstalledUrl();
        $res = httpPostJson(PORTAL_ACTIVATE_URL, [
            'npsn' => $npsn,
            'token' => $token,
            'installed_url' => $installedUrl,
            'app_version' => SIMANTAP_VERSION,
            'php_version' => PHP_VERSION,
        ]);
        if ($res['transport_error'] !== null || $res['http_code'] === 0) {
            installerError('Tidak dapat menghubungi Portal SIMANTAP BTIKP. (' . ($res['transport_error'] ?? 'tanpa respons') . ')', 502);
        }
        if (!is_array($res['body']) || empty($res['body']['success'])) {
            installerError($res['body']['error'] ?? 'Aktivasi ulang ditolak oleh portal.');
        }
        $licenseStr = (string)($res['body']['data']['license'] ?? '');
        if ($licenseStr === '' || !writeLicenseFile($licenseStr)) {
            installerError('Gagal menyimpan lisensi baru. Periksa izin tulis folder api/.', 500);
        }
        installerJson(['success' => true, 'message' => 'Aktivasi ulang berhasil. Aplikasi aktif kembali di ' . $installedUrl]);
    }

    if ($step !== null) {
        installerError('Instalasi sudah selesai. Installer terkunci.', 403);
    }

    if (!$licenseOk) {
        // ── Halaman aktivasi ulang ──
        ?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIMANTAP — Aktivasi Ulang</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:36px;max-width:460px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
h1{font-size:19px;color:#0f172a;margin:0 0 8px}p{color:#475569;font-size:13px;line-height:1.6}
.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:10px;font-size:12px;margin-bottom:14px}
label{display:block;font-size:12px;font-weight:700;color:#334155;margin:12px 0 4px}
input{width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;background:#f8fafc}
button{width:100%;margin-top:18px;border:0;border-radius:10px;padding:11px;font-size:14px;font-weight:700;cursor:pointer;background:#059669;color:#fff}
button:disabled{background:#cbd5e1}
.msg{border-radius:10px;padding:10px 14px;font-size:13px;margin-top:14px;display:none}
.msg.err{display:block;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.msg.good{display:block;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.mono{font-family:Consolas,Menlo,monospace;letter-spacing:1px;text-transform:uppercase}</style></head>
<body><div class="card">
<h1>🔑 Aktivasi Ulang Diperlukan</h1>
<div class="warn"><?= htmlspecialchars($licenseMsg, ENT_QUOTES, 'UTF-8') ?></div>
<p>Masukkan NPSN dan token akses sekolah Anda dari Portal SIMANTAP BTIKP. Bila token sudah kadaluwarsa, minta token baru ke BTIKP.</p>
<label>NPSN Sekolah</label><input id="npsn" maxlength="8" inputmode="numeric" placeholder="8 digit angka">
<label>Token Akses</label><input id="token" class="mono" maxlength="19" placeholder="SMTP-XXXX-XXXX-XXXX">
<div class="msg" id="msg"></div>
<button id="btn" onclick="reactivate()">Aktivasi Ulang</button>
<script>
function reactivate(){
  var b=document.getElementById('btn'),m=document.getElementById('msg');
  b.disabled=true;m.className='msg good';m.textContent='Menghubungi Portal SIMANTAP BTIKP…';
  fetch('index.php?step=reactivate',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({npsn:document.getElementById('npsn').value,token:document.getElementById('token').value})})
  .then(function(r){return r.json()}).then(function(res){
    b.disabled=false;
    if(res.success){m.className='msg good';m.textContent=res.message+' Mengalihkan…';setTimeout(function(){location.href='../'},1200);}
    else{m.className='msg err';m.textContent=res.error;}
  }).catch(function(){b.disabled=false;m.className='msg err';m.textContent='Gagal menghubungi installer.';});
}
</script>
</div></body></html>
        <?php
        exit;
    }

    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIMANTAP — Sudah Terinstal</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f5f9;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:40px;max-width:460px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.06)}
h1{font-size:20px;color:#0f172a}p{color:#475569;font-size:14px;line-height:1.6}
a{display:inline-block;margin-top:16px;background:#059669;color:#fff;text-decoration:none;padding:10px 24px;border-radius:10px;font-weight:700;font-size:14px}
.warn{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px;font-size:13px;margin-top:16px}</style></head>
<body><div class="card">
<h1>✅ Aplikasi SIMANTAP sudah terinstal</h1>
<p>Wizard instalasi telah dikunci karena proses instalasi sudah selesai.</p>
<div class="warn">Demi keamanan, <b>hapus folder <code>/install</code></b> dari server Anda.</div>
<a href="../">Buka Aplikasi SIMANTAP</a>
</div></body></html>
    <?php
    exit;
}

// ══════════════════════════════════════════════════════
// POST HANDLERS (AJAX per langkah)
// ══════════════════════════════════════════════════════
if ($step !== null) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        installerError('Metode tidak diizinkan.', 405);
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $state = &$_SESSION['simantap_install'];
    if (!is_array($state)) $state = [];

    switch ($step) {

        // ── Langkah 1: Cek kebutuhan ──
        case 'requirements':
            $req = checkRequirements();
            if ($req['all_ok']) $state['requirements_ok'] = true;
            installerJson(['success' => true, 'data' => $req]);
            break;

        // ── Langkah 2a: Tes koneksi database ──
        case 'db_test':
            if (empty($state['requirements_ok'])) installerError('Selesaikan pengecekan kebutuhan server terlebih dahulu.');
            $cfg = [
                'host' => trim((string)($body['host'] ?? '')),
                'port' => (int)($body['port'] ?? 3306),
                'user' => trim((string)($body['user'] ?? '')),
                'pass' => (string)($body['pass'] ?? ''),
                'name' => trim((string)($body['name'] ?? '')),
            ];
            if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['name'] === '') {
                installerError('Host, user, dan nama database wajib diisi.');
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $cfg['name'])) {
                installerError('Nama database hanya boleh huruf, angka, dan garis bawah.');
            }
            $test = testDbConnection($cfg);
            if (!$test['ok']) installerError($test['error']);
            $state['db'] = $cfg;
            $state['imported'] = false;
            installerJson(['success' => true, 'message' => $test['created']
                ? 'Koneksi berhasil. Database baru telah dibuat.'
                : 'Koneksi database berhasil.']);
            break;

        // ── Langkah 2b: Impor skema (chunked) ──
        case 'db_import':
            if (empty($state['db'])) installerError('Lakukan tes koneksi database terlebih dahulu.');
            $conn = connectDb($state['db']);
            if (!$conn instanceof mysqli) installerError('Koneksi database gagal: ' . $conn);

            if (!is_readable(SCHEMA_FILE)) installerError('File schema.sql tidak ditemukan di folder install.');
            $statements = splitSqlStatements(file_get_contents(SCHEMA_FILE));
            $total = count($statements);
            $offset = max(0, (int)($body['offset'] ?? 0));

            if ($offset === 0) {
                // Tolak bila database sudah berisi instalasi SIMANTAP
                $check = $conn->query("SELECT COUNT(*) AS c FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = 'users'");
                if ($check && (int)$check->fetch_assoc()['c'] > 0) {
                    $adm = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'ADMIN'");
                    if ($adm && (int)$adm->fetch_assoc()['c'] > 0) {
                        installerError('Database ini sudah berisi instalasi SIMANTAP (akun ADMIN ditemukan). Gunakan database kosong.');
                    }
                }
            }

            $conn->query('SET FOREIGN_KEY_CHECKS=0');
            $deadline = microtime(true) + 1.5;
            $i = $offset;
            for (; $i < $total && $i < $offset + 40 && microtime(true) < $deadline; $i++) {
                if (!$conn->query($statements[$i])) {
                    $preview = substr(preg_replace('/\s+/', ' ', $statements[$i]), 0, 90);
                    installerError("Impor gagal pada pernyataan ke-" . ($i + 1) . ": {$conn->error} — {$preview}…");
                }
            }
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            $conn->close();

            $done = $i >= $total;
            if ($done) $state['imported'] = true;
            installerJson(['success' => true, 'data' => [
                'done' => $done, 'next_offset' => $i, 'total' => $total,
            ]]);
            break;

        // ── Langkah 3: Aktivasi token ke portal (wajib online) ──
        case 'activate':
            if (empty($state['imported'])) installerError('Selesaikan impor database terlebih dahulu.');
            $npsn = trim((string)($body['npsn'] ?? ''));
            $token = strtoupper(trim((string)($body['token'] ?? '')));
            if (!preg_match('/^\d{8}$/', $npsn)) installerError('Format NPSN tidak valid (harus 8 digit angka).');
            if (!preg_match('/^SMTP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $token)) {
                installerError('Format token tidak valid. Contoh: SMTP-K7X2-9BF4-LM8Q');
            }

            $installedUrl = detectInstalledUrl();
            $res = httpPostJson(PORTAL_ACTIVATE_URL, [
                'npsn' => $npsn,
                'token' => $token,
                'installed_url' => $installedUrl,
                'app_version' => SIMANTAP_VERSION,
                'php_version' => PHP_VERSION,
            ]);

            if ($res['transport_error'] !== null || $res['http_code'] === 0) {
                installerError('Tidak dapat menghubungi Portal SIMANTAP BTIKP. Pastikan server ini memiliki akses internet keluar, lalu coba lagi. (' . ($res['transport_error'] ?? 'tanpa respons') . ')', 502);
            }
            if (!is_array($res['body'])) {
                installerError('Respons portal tidak dikenali (HTTP ' . $res['http_code'] . '). Coba lagi atau hubungi BTIKP.', 502);
            }
            if (empty($res['body']['success'])) {
                installerError($res['body']['error'] ?? 'Aktivasi ditolak oleh portal.');
            }

            // Simpan lisensi bertanda tangan — diverifikasi aplikasi di setiap request
            $licenseStr = (string)($res['body']['data']['license'] ?? '');
            if ($licenseStr === '') {
                installerError('Portal tidak mengirimkan lisensi instalasi. Hubungi BTIKP.', 502);
            }
            if (!writeLicenseFile($licenseStr)) {
                installerError('Gagal menulis api/license.json. Periksa izin tulis folder api/.', 500);
            }

            // Simpan jejak aktivasi ke system_config agar tahan terhadap sesi hilang
            $conn = connectDb($state['db']);
            if ($conn instanceof mysqli) {
                $stmt = $conn->prepare("INSERT INTO system_config (config_key, config_value, description)
                    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                foreach ([
                    ['install_npsn', $npsn, 'NPSN sekolah (aktivasi installer)'],
                    ['portal_activated_at', date('Y-m-d H:i:s'), 'Waktu aktivasi ke Portal SIMANTAP'],
                    ['install_url', $installedUrl, 'URL deploy aplikasi'],
                ] as [$k, $v, $d]) {
                    $stmt->bind_param('sss', $k, $v, $d);
                    $stmt->execute();
                }
                $stmt->close();
                $conn->close();
            }

            $state['activated'] = ['npsn' => $npsn, 'at' => date('Y-m-d H:i:s')];
            installerJson(['success' => true, 'message' => $res['body']['message'] ?? 'Aktivasi berhasil.', 'data' => [
                'nama_sekolah' => $res['body']['data']['nama_sekolah'] ?? '',
                'installed_url' => $installedUrl,
            ]]);
            break;

        // ── Langkah 4: Finalisasi ──
        case 'finish':
            if (empty($state['activated'])) installerError('Selesaikan aktivasi token terlebih dahulu.');

            $schoolName = trim((string)($body['school_name'] ?? ''));
            $adminName = trim((string)($body['admin_name'] ?? ''));
            $adminEmail = trim((string)($body['admin_email'] ?? ''));
            $adminPass = (string)($body['admin_password'] ?? '');
            $adminPass2 = (string)($body['admin_password2'] ?? '');
            $timezone = (string)($body['timezone'] ?? 'Asia/Makassar');

            if ($schoolName === '') installerError('Nama sekolah wajib diisi.');
            if ($adminName === '') installerError('Nama administrator wajib diisi.');
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) installerError('Email administrator tidak valid.');
            if (strlen($adminPass) < 8) installerError('Password administrator minimal 8 karakter.');
            if ($adminPass !== $adminPass2) installerError('Konfirmasi password tidak cocok.');
            if (!in_array($timezone, ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'], true)) {
                $timezone = 'Asia/Makassar';
            }

            $conn = connectDb($state['db']);
            if (!$conn instanceof mysqli) installerError('Koneksi database gagal: ' . $conn);

            // Identitas sekolah → system_config
            $year = date('n') >= 7 ? date('Y') . '/' . (date('Y') + 1) : (date('Y') - 1) . '/' . date('Y');
            $configs = [
                ['school_name', $schoolName, 'Nama sekolah'],
                ['kop_sekolah', strtoupper($schoolName), 'Nama sekolah pada kop surat'],
                ['kop_instansi', strtoupper(trim((string)($body['kop_instansi'] ?? 'PEMERINTAH PROVINSI KALIMANTAN SELATAN'))), 'Instansi pada kop surat'],
                ['kop_dinas', strtoupper(trim((string)($body['kop_dinas'] ?? 'DINAS PENDIDIKAN DAN KEBUDAYAAN'))), 'Dinas pada kop surat'],
                ['kop_alamat', trim((string)($body['kop_alamat'] ?? '')), 'Alamat pada kop surat'],
                ['kop_telp', trim((string)($body['kop_telp'] ?? '')), 'Telepon pada kop surat'],
                ['kop_email', trim((string)($body['kop_email'] ?? '')), 'Email pada kop surat'],
                ['kop_website', trim((string)($body['kop_website'] ?? '')), 'Website pada kop surat'],
                ['active_academic_year', trim((string)($body['academic_year'] ?? $year)), 'Tahun ajaran aktif'],
                ['active_semester', in_array(strtoupper((string)($body['semester'] ?? '')), ['GANJIL', 'GENAP'], true) ? strtoupper($body['semester']) : 'GANJIL', 'Semester aktif'],
                ['timezone', $timezone, 'Zona waktu aplikasi'],
            ];
            $stmt = $conn->prepare("INSERT INTO system_config (config_key, config_value, description)
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
            foreach ($configs as [$k, $v, $d]) {
                $stmt->bind_param('sss', $k, $v, $d);
                $stmt->execute();
            }
            $stmt->close();

            // Akun ADMIN
            $uid = bin2hex(random_bytes(16));
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (uid, email, password, displayName, role, schoolId, schoolName)
                VALUES (?, ?, ?, ?, 'ADMIN', 'DEFAULT_SCHOOL', ?)");
            $stmt->bind_param('sssss', $uid, $adminEmail, $hash, $adminName, $schoolName);
            if (!$stmt->execute()) {
                $err = $conn->errno === 1062 ? 'Email tersebut sudah terdaftar.' : 'Gagal membuat akun admin: ' . $conn->error;
                $stmt->close();
                $conn->close();
                installerError($err);
            }
            $stmt->close();
            $conn->close();

            // Tulis konfigurasi database permanen + kunci installer
            if (!writeLocalConfig($state['db'], $timezone)) {
                installerError('Gagal menulis api/config.local.php. Periksa izin tulis folder api/.', 500);
            }
            if (!writeInstallLock([
                'installed_at' => date('c'),
                'npsn' => $state['activated']['npsn'],
                'version' => SIMANTAP_VERSION,
            ])) {
                installerError('Gagal menulis penanda instalasi (install.lock sudah ada?).', 500);
            }

            unset($_SESSION['simantap_install']);
            installerJson(['success' => true, 'message' => 'Instalasi selesai! Aplikasi SIMANTAP siap digunakan.']);
            break;

        default:
            installerError('Langkah tidak dikenal.', 404);
    }
    exit;
}

// ══════════════════════════════════════════════════════
// GET: Halaman wizard
// ══════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalasi SIMANTAP</title>
<style>
:root{--green:#059669;--green-d:#065f46;--slate:#475569;--border:#e2e8f0}
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;margin:0;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:32px 16px}
.wrap{width:100%;max-width:640px}
.head{text-align:center;margin-bottom:24px}
.head h1{font-size:22px;color:#0f172a;margin:0 0 4px}
.head p{color:var(--slate);font-size:13px;margin:0}
.steps{display:flex;gap:8px;margin-bottom:20px}
.steps span{flex:1;height:6px;border-radius:99px;background:#e2e8f0;transition:.3s}
.steps span.on{background:var(--green)}
.card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:28px;box-shadow:0 8px 24px rgba(0,0,0,.05)}
.card h2{font-size:16px;color:#0f172a;margin:0 0 6px}
.card .sub{font-size:13px;color:var(--slate);margin:0 0 18px}
table{width:100%;border-collapse:collapse;font-size:13px}
td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top}
td.st{width:26px;font-size:15px}
.detail{color:#94a3b8;font-size:11px}
.ok{color:var(--green)}.bad{color:#dc2626}.warn{color:#d97706}
label{display:block;font-size:12px;font-weight:700;color:#334155;margin:12px 0 4px}
input,select{width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;font-size:14px;background:#f8fafc}
input:focus,select:focus{outline:2px solid var(--green);border-color:transparent}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
.btns{display:flex;gap:10px;justify-content:flex-end;margin-top:22px}
button{border:0;border-radius:10px;padding:10px 22px;font-size:14px;font-weight:700;cursor:pointer;transition:.2s}
.primary{background:var(--green);color:#fff}
.primary:hover{background:var(--green-d)}
.primary:disabled{background:#cbd5e1;cursor:not-allowed}
.ghost{background:#f1f5f9;color:#334155}
.msg{border-radius:10px;padding:10px 14px;font-size:13px;margin-top:14px;display:none}
.msg.err{display:block;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.msg.good{display:block;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.bar{height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-top:14px}
.bar i{display:block;height:100%;width:0;background:var(--green);transition:.2s}
.token-in{font-family:Consolas,Menlo,monospace;letter-spacing:1px;text-transform:uppercase}
.hide{display:none}
.final{text-align:center;padding:12px 0}
.final .big{font-size:40px}
.note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:10px;font-size:12px;margin-top:16px}
a.applink{display:inline-block;margin-top:14px;background:var(--green);color:#fff;text-decoration:none;padding:11px 28px;border-radius:10px;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>Instalasi Aplikasi SIMANTAP</h1>
    <p>Jurnal Mengajar Digital — BTIKP Disdikbud Prov. Kalimantan Selatan</p>
  </div>
  <div class="steps"><span id="s1" class="on"></span><span id="s2"></span><span id="s3"></span><span id="s4"></span></div>

  <!-- STEP 1 -->
  <div class="card" id="step1">
    <h2>1. Pengecekan Kebutuhan Server</h2>
    <p class="sub">Pastikan hosting/VPS Anda memenuhi kebutuhan berikut.</p>
    <table id="req-table"><tr><td>Memeriksa…</td></tr></table>
    <div class="msg err" id="msg1"></div>
    <div class="btns">
      <button class="ghost" onclick="loadRequirements()">Periksa Ulang</button>
      <button class="primary" id="next1" disabled onclick="goto(2)">Lanjut</button>
    </div>
  </div>

  <!-- STEP 2 -->
  <div class="card hide" id="step2">
    <h2>2. Pengaturan Database</h2>
    <p class="sub">Masukkan kredensial database MySQL/MariaDB dari panel hosting Anda.</p>
    <div class="row">
      <div><label>Host Database</label><input id="db-host" value="localhost"></div>
      <div><label>Port</label><input id="db-port" value="3306" type="number"></div>
    </div>
    <label>Username</label><input id="db-user" autocomplete="off">
    <label>Password</label><input id="db-pass" type="password" autocomplete="new-password">
    <label>Nama Database</label><input id="db-name" value="simantap">
    <div class="msg" id="msg2"></div>
    <div class="bar hide" id="import-bar"><i id="import-fill"></i></div>
    <div class="btns">
      <button class="ghost" onclick="testDb()" id="btn-test">Tes Koneksi</button>
      <button class="primary" id="next2" disabled onclick="startImport()">Impor Database &amp; Lanjut</button>
    </div>
  </div>

  <!-- STEP 3 -->
  <div class="card hide" id="step3">
    <h2>3. Aktivasi Token</h2>
    <p class="sub">Masukkan NPSN dan token akses yang Anda terima melalui email dari Portal SIMANTAP BTIKP. Server ini harus memiliki akses internet.</p>
    <label>NPSN Sekolah</label><input id="act-npsn" maxlength="8" inputmode="numeric" placeholder="8 digit angka">
    <label>Token Akses</label><input id="act-token" class="token-in" maxlength="19" placeholder="SMTP-XXXX-XXXX-XXXX">
    <div class="msg" id="msg3"></div>
    <div class="btns">
      <button class="primary" id="next3" onclick="activate()">Aktivasi</button>
    </div>
  </div>

  <!-- STEP 4 -->
  <div class="card hide" id="step4">
    <h2>4. Finalisasi</h2>
    <p class="sub">Identitas sekolah dan akun administrator pertama.</p>
    <label>Nama Sekolah</label><input id="f-school" placeholder="mis. SMAN 1 Banjarmasin">
    <div class="row">
      <div><label>Tahun Ajaran Aktif</label><input id="f-year" placeholder="2026/2027"></div>
      <div><label>Semester</label><select id="f-semester"><option>Ganjil</option><option>Genap</option></select></div>
    </div>
    <div class="row">
      <div><label>Zona Waktu</label><select id="f-tz">
        <option value="Asia/Makassar" selected>WITA (Kalimantan Selatan)</option>
        <option value="Asia/Jakarta">WIB</option>
        <option value="Asia/Jayapura">WIT</option>
      </select></div>
      <div><label>Alamat Sekolah (Kop Surat)</label><input id="f-alamat"></div>
    </div>
    <hr style="border:0;border-top:1px solid #f1f5f9;margin:18px 0">
    <label>Nama Administrator</label><input id="f-aname">
    <label>Email Administrator (untuk login)</label><input id="f-aemail" type="email">
    <div class="row">
      <div><label>Password (min. 8 karakter)</label><input id="f-apass" type="password" autocomplete="new-password"></div>
      <div><label>Konfirmasi Password</label><input id="f-apass2" type="password" autocomplete="new-password"></div>
    </div>
    <div class="msg" id="msg4"></div>
    <div class="btns">
      <button class="primary" id="next4" onclick="finishInstall()">Selesaikan Instalasi</button>
    </div>
  </div>

  <!-- DONE -->
  <div class="card hide" id="stepDone">
    <div class="final">
      <div class="big">🎉</div>
      <h2>Instalasi Berhasil!</h2>
      <p class="sub">Status sekolah Anda di Portal SIMANTAP kini <b>Terimplementasi</b>.</p>
      <a class="applink" href="../">Buka Aplikasi SIMANTAP</a>
      <div class="note">Demi keamanan, <b>hapus folder <code>/install</code></b> dari server Anda setelah selesai.</div>
    </div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
let CUR = 1;

function goto(n) {
  ['step1','step2','step3','step4'].forEach((s,i) => $(s).classList.toggle('hide', i !== n-1));
  $('stepDone').classList.add('hide');
  for (let i = 1; i <= 4; i++) $('s'+i).classList.toggle('on', i <= n);
  CUR = n;
}

function post(step, body) {
  return fetch('index.php?step=' + step, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(body || {})
  }).then(r => r.json());
}

function showMsg(el, text, good) {
  el.textContent = text;
  el.className = 'msg ' + (good ? 'good' : 'err');
}

// ── Step 1 ──
function loadRequirements() {
  $('req-table').innerHTML = '<tr><td>Memeriksa…</td></tr>';
  post('requirements').then(res => {
    if (!res.success) { showMsg($('msg1'), res.error, false); return; }
    const rows = res.data.items.map(it => {
      const icon = it.ok ? '<span class="ok">✔</span>' : (it.mandatory ? '<span class="bad">✘</span>' : '<span class="warn">⚠</span>');
      return `<tr><td class="st">${icon}</td><td><b>${it.label}</b><br><span class="detail">${it.detail}</span></td></tr>`;
    }).join('');
    $('req-table').innerHTML = rows;
    $('next1').disabled = !res.data.all_ok;
    $('msg1').className = 'msg';
    if (!res.data.all_ok) showMsg($('msg1'), 'Penuhi kebutuhan bertanda ✘ lalu klik "Periksa Ulang".', false);
  }).catch(() => showMsg($('msg1'), 'Gagal memeriksa server.', false));
}

// ── Step 2 ──
function dbPayload() {
  return { host: $('db-host').value, port: $('db-port').value, user: $('db-user').value,
           pass: $('db-pass').value, name: $('db-name').value };
}
function testDb() {
  $('btn-test').disabled = true;
  post('db_test', dbPayload()).then(res => {
    $('btn-test').disabled = false;
    if (res.success) { showMsg($('msg2'), res.message, true); $('next2').disabled = false; }
    else { showMsg($('msg2'), res.error, false); $('next2').disabled = true; }
  }).catch(() => { $('btn-test').disabled = false; showMsg($('msg2'), 'Gagal terhubung ke installer.', false); });
}
function startImport(offset = 0) {
  $('next2').disabled = true; $('btn-test').disabled = true;
  $('import-bar').classList.remove('hide');
  post('db_import', { offset }).then(res => {
    if (!res.success) {
      showMsg($('msg2'), res.error, false);
      $('next2').disabled = false; $('btn-test').disabled = false;
      return;
    }
    const d = res.data;
    $('import-fill').style.width = Math.round(d.next_offset / d.total * 100) + '%';
    showMsg($('msg2'), `Mengimpor basis data… ${d.next_offset}/${d.total} pernyataan`, true);
    if (d.done) { showMsg($('msg2'), 'Impor database selesai.', true); setTimeout(() => goto(3), 600); }
    else startImport(d.next_offset);
  }).catch(() => {
    showMsg($('msg2'), 'Koneksi terputus saat impor. Klik "Impor Database" untuk melanjutkan.', false);
    $('next2').disabled = false; $('btn-test').disabled = false;
  });
}

// ── Step 3 ──
function activate() {
  $('next3').disabled = true;
  showMsg($('msg3'), 'Menghubungi Portal SIMANTAP BTIKP…', true);
  post('activate', { npsn: $('act-npsn').value, token: $('act-token').value }).then(res => {
    $('next3').disabled = false;
    if (res.success) {
      showMsg($('msg3'), res.message + (res.data.nama_sekolah ? ' (' + res.data.nama_sekolah + ')' : ''), true);
      if (res.data.nama_sekolah) $('f-school').value = res.data.nama_sekolah;
      setTimeout(() => goto(4), 800);
    } else {
      showMsg($('msg3'), res.error, false);
    }
  }).catch(() => { $('next3').disabled = false; showMsg($('msg3'), 'Gagal menghubungi installer.', false); });
}

// ── Step 4 ──
function finishInstall() {
  $('next4').disabled = true;
  post('finish', {
    school_name: $('f-school').value,
    academic_year: $('f-year').value,
    semester: $('f-semester').value,
    timezone: $('f-tz').value,
    kop_alamat: $('f-alamat').value,
    admin_name: $('f-aname').value,
    admin_email: $('f-aemail').value,
    admin_password: $('f-apass').value,
    admin_password2: $('f-apass2').value
  }).then(res => {
    $('next4').disabled = false;
    if (res.success) {
      ['step1','step2','step3','step4'].forEach(s => $(s).classList.add('hide'));
      $('stepDone').classList.remove('hide');
      for (let i = 1; i <= 4; i++) $('s'+i).classList.add('on');
    } else {
      showMsg($('msg4'), res.error, false);
    }
  }).catch(() => { $('next4').disabled = false; showMsg($('msg4'), 'Gagal menghubungi installer.', false); });
}

loadRequirements();
</script>
</body>
</html>
