<?php
/**
 * SIMANTAP Installer - Library Helper
 * Semua fungsi pendukung wizard instalasi first-run.
 */

const INSTALL_LOCK_FILE = __DIR__ . '/../api/install.lock';
const LOCAL_CONFIG_FILE = __DIR__ . '/../api/config.local.php';
const SCHEMA_FILE = __DIR__ . '/schema.sql';

// ── Lock ──────────────────────────────────────────────

function installerLocked(): bool {
    return file_exists(INSTALL_LOCK_FILE);
}

/**
 * Tulis file lock secara atomik (mode 'x' gagal bila file sudah ada,
 * mencegah dua proses finalisasi berlomba).
 */
function writeInstallLock(array $meta): bool {
    $fh = @fopen(INSTALL_LOCK_FILE, 'x');
    if ($fh === false) {
        return false;
    }
    fwrite($fh, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fclose($fh);
    return true;
}

// ── Pengecekan kebutuhan server ───────────────────────

function parseIniSize(string $val): int {
    $val = trim($val);
    if ($val === '' || $val === '-1') return PHP_INT_MAX;
    $unit = strtolower(substr($val, -1));
    $num = (float) $val;
    return (int) match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
}

/**
 * @return array{items: array<int,array{label:string,ok:bool,mandatory:bool,detail:string}>, all_ok: bool}
 */
function checkRequirements(): array {
    $items = [];

    $items[] = [
        'label' => 'Versi PHP ≥ 8.0',
        'ok' => PHP_VERSION_ID >= 80000,
        'mandatory' => true,
        'detail' => 'Terpasang: PHP ' . PHP_VERSION,
    ];

    foreach (['mysqli' => 'Koneksi database MySQL/MariaDB',
              'fileinfo' => 'Deteksi tipe file unggahan',
              'json' => 'Pemrosesan data API',
              'session' => 'Sesi login pengguna'] as $ext => $why) {
        $items[] = [
            'label' => "Ekstensi PHP: {$ext}",
            'ok' => extension_loaded($ext),
            'mandatory' => true,
            'detail' => $why,
        ];
    }

    $items[] = [
        'label' => 'Akses HTTP keluar (cURL atau allow_url_fopen)',
        'ok' => function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
        'mandatory' => true,
        'detail' => 'Dibutuhkan untuk aktivasi token ke Portal SIMANTAP BTIKP',
    ];

    $items[] = [
        'label' => 'Ekstensi PHP: zip',
        'ok' => class_exists('ZipArchive'),
        'mandatory' => false,
        'detail' => 'Dibutuhkan untuk fitur update otomatis dari portal',
    ];

    $writableDirs = [
        '../api' => 'Menulis konfigurasi database & penanda instalasi',
        '../uploads/journals' => 'Foto bukti jurnal mengajar',
        '../uploads/logos' => 'Logo kop surat',
        '../uploads/tu' => 'Berkas tata usaha',
    ];
    foreach ($writableDirs as $rel => $why) {
        $path = __DIR__ . '/' . $rel;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $items[] = [
            'label' => 'Folder dapat ditulis: ' . str_replace('../', '', $rel) . '/',
            'ok' => is_dir($path) && is_writable($path),
            'mandatory' => true,
            'detail' => $why,
        ];
    }

    // Update otomatis menimpa file di luar api/ juga (app.html, index.php,
    // assets/) — semuanya harus writable oleh user PHP, kalau tidak `apply`
    // akan gagal di tengah jalan meski instalasi awal sukses.
    $rootDir = dirname(__DIR__);

    $items[] = [
        'label' => 'Folder dapat ditulis: / (root aplikasi)',
        'ok' => is_writable($rootDir),
        'mandatory' => true,
        'detail' => 'Update otomatis menulis file baru di root aplikasi',
    ];
    $items[] = [
        'label' => 'Folder dapat ditulis: assets/',
        // Bila belum ada, updater membuatnya sendiri — cukup root yang writable
        'ok' => is_dir($rootDir . '/assets') ? is_writable($rootDir . '/assets') : is_writable($rootDir),
        'mandatory' => true,
        'detail' => 'Update otomatis mengganti bundle frontend',
    ];

    // File yang ditimpa langsung oleh update: writable per-file, bukan cuma foldernya
    $overwritten = array_merge(
        [$rootDir . '/app.html', $rootDir . '/index.php'],
        glob($rootDir . '/api/*.php') ?: []
    );
    $notWritable = [];
    foreach ($overwritten as $f) {
        if (is_file($f) && !is_writable($f)) {
            $notWritable[] = ltrim(str_replace($rootDir, '', $f), '/');
        }
    }
    $items[] = [
        'label' => 'Semua file aplikasi dapat ditimpa',
        'ok' => empty($notWritable),
        'mandatory' => true,
        'detail' => empty($notWritable)
            ? 'File inti (app.html, index.php, api/*.php) dapat ditimpa oleh update otomatis'
            : 'Tidak dapat ditulis: ' . implode(', ', array_slice($notWritable, 0, 5))
                . (count($notWritable) > 5 ? sprintf(' (+%d lainnya)', count($notWritable) - 5) : '')
                . '. Jalankan: chown -R <user-php> <folder-simantap>',
    ];

    $uploadMax = parseIniSize((string) ini_get('upload_max_filesize'));
    $postMax = parseIniSize((string) ini_get('post_max_size'));
    $min = 3 * 1024 * 1024;
    $items[] = [
        'label' => 'Batas unggah PHP ≥ 3 MB',
        'ok' => $uploadMax >= $min && $postMax >= $min,
        'mandatory' => false,
        'detail' => sprintf('upload_max_filesize=%s, post_max_size=%s (disarankan ≥ 3M untuk pas foto)',
            ini_get('upload_max_filesize'), ini_get('post_max_size')),
    ];

    $allOk = true;
    foreach ($items as $it) {
        if ($it['mandatory'] && !$it['ok']) {
            $allOk = false;
            break;
        }
    }

    return ['items' => $items, 'all_ok' => $allOk];
}

// ── Database ──────────────────────────────────────────

function connectDb(array $cfg, bool $selectDb = true): mysqli|string {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'],
        $selectDb ? $cfg['name'] : '', (int) $cfg['port']);
    if ($conn->connect_error) {
        return $conn->connect_error;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

/** @return array{ok: bool, error: ?string, created: bool} */
function testDbConnection(array $cfg): array {
    // Coba langsung ke database tujuan
    $conn = connectDb($cfg);
    if ($conn instanceof mysqli) {
        $conn->close();
        return ['ok' => true, 'error' => null, 'created' => false];
    }

    // Gagal — mungkin database belum ada. Coba tanpa database lalu buat.
    $server = connectDb($cfg, false);
    if (!$server instanceof mysqli) {
        return ['ok' => false, 'error' => 'Tidak dapat terhubung ke server database: ' . $server, 'created' => false];
    }
    $dbName = str_replace('`', '', $cfg['name']);
    $created = $server->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->close();
    if (!$created) {
        return ['ok' => false, 'error' => "Server terhubung, tetapi database '{$dbName}' tidak ada dan gagal dibuat. Buat database secara manual melalui panel hosting Anda.", 'created' => false];
    }

    $conn = connectDb($cfg);
    if ($conn instanceof mysqli) {
        $conn->close();
        return ['ok' => true, 'error' => null, 'created' => true];
    }
    return ['ok' => false, 'error' => 'Database dibuat tetapi tidak dapat diakses: ' . $conn, 'created' => true];
}

/**
 * Pecah isi file SQL menjadi statement individual.
 * State machine sederhana untuk kutip tunggal/ganda/backtick + escape,
 * cukup untuk dump phpMyAdmin (tanpa stored routine / DELIMITER).
 * @return string[]
 */
function splitSqlStatements(string $sql): array {
    // Buang komentar baris penuh dan directive dump
    $lines = preg_split('/\r?\n/', $sql);
    $kept = [];
    foreach ($lines as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) continue;
        if (preg_match('/^(SET\s+(SQL_MODE|time_zone|@OLD_)|START TRANSACTION|COMMIT|\/\*!)/i', $t)) continue;
        $kept[] = $line;
    }
    $sql = implode("\n", $kept);

    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $inQuote = null; // ' " `
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $inQuote !== '`') {
                // karakter berikutnya di-escape
                if ($i + 1 < $len) {
                    $buf .= $sql[++$i];
                }
            } elseif ($ch === $inQuote) {
                $inQuote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inQuote = $ch;
            $buf .= $ch;
            continue;
        }
        if ($ch === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') $statements[] = $stmt;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $stmt = trim($buf);
    if ($stmt !== '') $statements[] = $stmt;

    return $statements;
}

/** Tulis api/config.local.php dari kredensial database. */
function writeLocalConfig(array $cfg, string $timezone): bool {
    $content = "<?php\n"
        . "// Dihasilkan otomatis oleh installer SIMANTAP — jangan diubah manual.\n"
        . "define('DB_HOST', " . var_export($cfg['host'], true) . ");\n"
        . "define('DB_USER', " . var_export($cfg['user'], true) . ");\n"
        . "define('DB_PASS', " . var_export($cfg['pass'], true) . ");\n"
        . "define('DB_NAME', " . var_export($cfg['name'], true) . ");\n"
        . "define('DB_PORT', " . var_export((int) $cfg['port'], true) . ");\n"
        . "define('APP_TIMEZONE', " . var_export($timezone, true) . ");\n";
    return file_put_contents(LOCAL_CONFIG_FILE, $content) !== false;
}

/** Simpan lisensi bertanda tangan dari portal ke api/license.json. */
function writeLicenseFile(string $license): bool {
    $content = json_encode([
        'license' => $license,
        'saved_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = file_put_contents(__DIR__ . '/../api/license.json', $content) !== false;
    if ($ok) {
        // Lisensi baru menganulir penanda pencabutan lama (kasus aktivasi ulang)
        @unlink(__DIR__ . '/../api/.license_revoked');
        @unlink(__DIR__ . '/../api/.heartbeat');
    }
    return $ok;
}

// ── HTTP client (aktivasi ke portal) ──────────────────

/** @return array{http_code: int, body: ?array, transport_error: ?string} */
function httpPostJson(string $url, array $payload, int $timeout = 20): array {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            return ['http_code' => 0, 'body' => null, 'transport_error' => $err ?: 'Koneksi gagal'];
        }
        $body = json_decode($raw, true);
        return ['http_code' => $code, 'body' => is_array($body) ? $body : null, 'transport_error' => null];
    }

    // Fallback: stream (allow_url_fopen)
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $json,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['http_code' => 0, 'body' => null, 'transport_error' => 'Koneksi gagal (allow_url_fopen)'];
    }
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
    }
    $body = json_decode($raw, true);
    return ['http_code' => $code, 'body' => is_array($body) ? $body : null, 'transport_error' => null];
}

// ── Util ──────────────────────────────────────────────

/** URL root deploy aplikasi ini, dihitung dari request saat ini. */
function detectInstalledUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // SCRIPT_NAME = /<root>/install/index.php  →  root deploy = dua level di atas
    $root = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return "{$scheme}://{$host}{$root}";
}

function installerJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function installerError(string $message, int $status = 400): void {
    installerJson(['success' => false, 'error' => $message], $status);
}
