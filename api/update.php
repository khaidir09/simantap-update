<?php
/**
 * SIMANTAP - Auto Updater (patch dari Portal SIMANTAP BTIKP)
 *
 * Alur (dipanggil UI, hanya ADMIN):
 *   action=status   → versi terpasang + info update yang tersimpan
 *   action=check    → tanya portal (patch_check) & perbarui penanda
 *   action=download → unduh patch, verifikasi sha256 zip + tanda tangan
 *                     Ed25519 manifest + checksum semua isi
 *   action=apply    → maintenance mode → backup → timpa file (watermark
 *                     distribusi dipertahankan) → migrasi SQL (dicatat di
 *                     schema_migrations, idempoten) → selesai.
 *                     Gagal di tengah → file dipulihkan dari backup.
 *   action=rollback → pulihkan file dari backup terakhir (migrasi DB tidak
 *                     di-revert otomatis).
 *
 * Keamanan: hanya patch yang manifest-nya ditandatangani kunci privat portal
 * (diverifikasi dengan LICENSE_PUBLIC_KEY yang terbake) yang bisa diterapkan.
 */

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/license_guard.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metode tidak diizinkan.', 405);
}

requireRole(['ADMIN']);

// Lisensi harus sehat (kecuali dev mode) — updater bukan jalan pintas
$licSt = simantapLicenseStatus();
if (!$licSt['ok']) {
    errorResponse('Lisensi tidak valid: ' . $licSt['message'], 403);
}

define('UPDATER_ROOT', dirname(__DIR__));
define('UPDATER_TMP', __DIR__ . '/patch_tmp');
define('UPDATER_BACKUP', __DIR__ . '/backups');

function updaterVersion(): string {
    return defined('SIMANTAP_APP_VERSION') ? SIMANTAP_APP_VERSION : 'dev';
}

function updaterLicense(): string {
    $data = json_decode((string)@file_get_contents(SIMANTAP_LICENSE_FILE), true);
    return (string)($data['license'] ?? '');
}

function portalUrl(): string {
    return defined('SIMANTAP_PORTAL_URL') ? rtrim(SIMANTAP_PORTAL_URL, '/') : '';
}

/** @return array{code:int, body:?string, error:?string} */
function portalRequest(string $path, array $payload, ?string $saveTo = null, int $timeout = 120): array {
    if (!function_exists('curl_init')) return ['code' => 0, 'body' => null, 'error' => 'cURL tidak tersedia'];
    $ch = curl_init(portalUrl() . $path);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    $fh = null;
    if ($saveTo !== null) {
        $fh = fopen($saveTo, 'wb');
        if ($fh === false) return ['code' => 0, 'body' => null, 'error' => 'Gagal menulis file sementara'];
        $opts[CURLOPT_FILE] = $fh;
    } else {
        $opts[CURLOPT_RETURNTRANSFER] = true;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($fh) fclose($fh);
    if ($body === false && $saveTo === null) return ['code' => 0, 'body' => null, 'error' => $err ?: 'Koneksi gagal'];
    return ['code' => $code, 'body' => is_string($body) ? $body : null, 'error' => $err ?: null];
}

function readUpdateInfo(): ?array {
    $info = json_decode((string)@file_get_contents(SIMANTAP_UPDATE_INFO_FILE), true);
    return (is_array($info) && !empty($info['version'])) ? $info : null;
}

/** Verifikasi zip patch: tanda tangan manifest + checksum seluruh isi. */
function verifyPatchZip(string $zipPath, string $expectedZipSha, string $signatureB64): array {
    if (!class_exists('ZipArchive')) {
        return [null, 'Ekstensi PHP zip tidak tersedia di server ini. Hubungi penyedia hosting.'];
    }
    if (!is_file($zipPath)) return [null, 'File patch belum diunduh.'];
    if ($expectedZipSha !== '' && hash_file('sha256', $zipPath) !== $expectedZipSha) {
        return [null, 'Checksum file patch tidak cocok (unduhan korup?). Coba unduh ulang.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return [null, 'File patch tidak bisa dibuka.'];
    $manifestRaw = $zip->getFromName('manifest.json');
    if ($manifestRaw === false) { $zip->close(); return [null, 'manifest.json tidak ditemukan di patch.']; }

    if (!defined('LICENSE_PUBLIC_KEY') || LICENSE_PUBLIC_KEY === '') {
        $zip->close(); return [null, 'Kunci publik lisensi tidak tersedia.'];
    }
    $sig = base64_decode($signatureB64, true);
    if ($sig === false
        || !@sodium_crypto_sign_verify_detached($sig, $manifestRaw, (string)base64_decode(LICENSE_PUBLIC_KEY))) {
        $zip->close(); return [null, 'Tanda tangan patch TIDAK SAH — patch ditolak demi keamanan.'];
    }

    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest) || empty($manifest['to'])) { $zip->close(); return [null, 'Manifest patch tidak valid.']; }

    foreach ($manifest['files'] ?? [] as $f) {
        $content = $zip->getFromName('files/' . $f['path']);
        if ($content === false || hash('sha256', $content) !== $f['sha256']) {
            $zip->close(); return [null, "Checksum file '{$f['path']}' tidak cocok — patch ditolak."];
        }
    }
    foreach ($manifest['migrations'] ?? [] as $m) {
        $content = $zip->getFromName('migrations/' . $m['file']);
        if ($content === false || hash('sha256', $content) !== $m['sha256']) {
            $zip->close(); return [null, "Checksum migrasi '{$m['file']}' tidak cocok — patch ditolak."];
        }
    }
    $zip->close();
    return [$manifest, null];
}

/** Path relatif yang boleh ditulis patch — tolak traversal & area sensitif. */
function safePatchPath(string $rel): bool {
    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) return false;
    foreach (['api/config.local.php', 'api/license.json', 'api/install.lock', 'api/license_pubkey.php'] as $blocked) {
        if ($rel === $blocked) return false;
    }
    if (str_starts_with($rel, 'uploads/')) return false;
    return true;
}

/** Pecah dump SQL menjadi statement (kutip-aware, cukup untuk migrasi). */
function splitMigrationSql(string $sql): array {
    $statements = [];
    $buf = '';
    $inQuote = null;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $inQuote !== '`') { if ($i + 1 < $len) $buf .= $sql[++$i]; }
            elseif ($ch === $inQuote) $inQuote = null;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $inQuote = $ch; $buf .= $ch; continue; }
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') { while ($i < $len && $sql[$i] !== "\n") $i++; continue; }
        if ($ch === ';') { $s = trim($buf); if ($s !== '') $statements[] = $s; $buf = ''; continue; }
        $buf .= $ch;
    }
    $s = trim($buf);
    if ($s !== '') $statements[] = $s;
    return $statements;
}

function recursiveCopy(string $src, string $dst): bool {
    @mkdir(dirname($dst), 0755, true);
    return @copy($src, $dst);
}

$action = $_GET['action'] ?? '';
$patchZip = UPDATER_TMP . '/patch.zip';
$patchMeta = UPDATER_TMP . '/patch_meta.json';

switch ($action) {

    case 'status':
        jsonResponse([
            'version' => updaterVersion(),
            'update' => readUpdateInfo(),
            'updating' => is_file(SIMANTAP_UPDATING_FLAG),
        ]);
        break;

    case 'check':
        if (portalUrl() === '') errorResponse('URL portal tidak dikonfigurasi (mode development?).');
        $res = portalRequest('/api/patch_check.php', [
            'license' => updaterLicense(),
            'version' => updaterVersion(),
        ], null, 20);
        if ($res['error'] !== null || $res['body'] === null) {
            errorResponse('Tidak dapat menghubungi portal: ' . ($res['error'] ?? 'tanpa respons'), 502);
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data) || empty($data['success'])) {
            errorResponse($data['error'] ?? 'Respons portal tidak dikenali.', 502);
        }
        if (!empty($data['data']['update_available'])) {
            $p = $data['data']['patch'];
            @mkdir(UPDATER_TMP, 0755, true);
            @file_put_contents(SIMANTAP_UPDATE_INFO_FILE, json_encode([
                'version' => $p['version'], 'notes' => (string)($p['notes'] ?? ''),
            ], JSON_UNESCAPED_UNICODE));
            @file_put_contents($patchMeta, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            jsonResponse(['update_available' => true, 'current' => updaterVersion(), 'patch' => $p]);
        }
        @unlink(SIMANTAP_UPDATE_INFO_FILE);
        jsonResponse(['update_available' => false, 'current' => updaterVersion()]);
        break;

    case 'download':
        $meta = json_decode((string)@file_get_contents($patchMeta), true);
        if (!is_array($meta) || empty($meta['version'])) {
            errorResponse('Jalankan pengecekan update terlebih dahulu.');
        }
        @mkdir(UPDATER_TMP, 0755, true);
        $res = portalRequest('/api/patch_download.php', [
            'license' => updaterLicense(),
            'version' => $meta['version'],
        ], $patchZip, 300);
        if ($res['error'] !== null || $res['code'] !== 200) {
            @unlink($patchZip);
            errorResponse('Gagal mengunduh patch: ' . ($res['error'] ?? 'HTTP ' . $res['code']), 502);
        }
        [$manifest, $err] = verifyPatchZip($patchZip, (string)($meta['sha256'] ?? ''), (string)($meta['signature'] ?? ''));
        if ($err !== null) { @unlink($patchZip); errorResponse($err); }
        jsonResponse([
            'verified' => true,
            'to' => $manifest['to'],
            'files' => count($manifest['files'] ?? []),
            'migrations' => count($manifest['migrations'] ?? []),
            'size' => filesize($patchZip),
        ]);
        break;

    case 'apply':
        $meta = json_decode((string)@file_get_contents($patchMeta), true);
        if (!is_array($meta)) errorResponse('Unduh patch terlebih dahulu.');
        [$manifest, $err] = verifyPatchZip($patchZip, (string)($meta['sha256'] ?? ''), (string)($meta['signature'] ?? ''));
        if ($err !== null) errorResponse($err);

        $current = updaterVersion();
        if (version_compare($manifest['to'], $current, '<=')) {
            errorResponse("Versi terpasang ({$current}) sudah sama/lebih baru dari patch ({$manifest['to']}).");
        }
        if (!empty($manifest['from']) && version_compare($current, $manifest['from'], '<')) {
            errorResponse("Patch ini membutuhkan minimal versi {$manifest['from']} (terpasang: {$current}).");
        }

        // Watermark distribusi dari app.html lama — dipertahankan lintas update
        $distCode = '';
        $oldApp = (string)@file_get_contents(UPDATER_ROOT . '/app.html');
        if (preg_match('/SIMANTAP Distribution ([A-F0-9]{10})/', $oldApp, $m)) $distCode = $m[1];

        @file_put_contents(SIMANTAP_UPDATING_FLAG, $manifest['to']);
        $backupDir = UPDATER_BACKUP . '/patch-' . $manifest['to'] . '-' . date('YmdHis');
        $written = [];

        $zip = new ZipArchive();
        $zip->open($patchZip);
        try {
            // 1. Backup semua file yang akan disentuh
            $touched = array_merge(
                array_map(fn($f) => $f['path'], $manifest['files'] ?? []),
                $manifest['delete'] ?? []
            );
            foreach ($touched as $rel) {
                if (!safePatchPath($rel)) throw new Exception("Path tidak diizinkan di patch: {$rel}");
                $abs = UPDATER_ROOT . '/' . $rel;
                if (is_file($abs) && !recursiveCopy($abs, $backupDir . '/' . $rel)) {
                    throw new Exception("Gagal mem-backup {$rel}. Periksa izin tulis.");
                }
            }

            // 2. Tulis file baru (watermark disuntik ulang)
            foreach ($manifest['files'] ?? [] as $f) {
                $rel = $f['path'];
                $content = $zip->getFromName('files/' . $rel);
                if ($content === false) throw new Exception("Isi '{$rel}' tidak ditemukan di patch.");
                if ($distCode !== '') {
                    if ($rel === 'app.html') {
                        $content = str_replace('</head>',
                            "  <meta name=\"generator\" content=\"SIMANTAP Distribution {$distCode}\">\n</head>", $content);
                    } elseif (preg_match('#^assets/index-[^/]+\.js$#', $rel)) {
                        $content .= "\n//# distId={$distCode}\n";
                    }
                }
                $abs = UPDATER_ROOT . '/' . $rel;
                @mkdir(dirname($abs), 0755, true);
                if (@file_put_contents($abs, $content) === false) {
                    throw new Exception("Gagal menulis {$rel}. Periksa izin tulis.");
                }
                $written[] = $rel;
            }

            // 3. Hapus file usang (aset lama dsb.)
            foreach ($manifest['delete'] ?? [] as $rel) {
                @unlink(UPDATER_ROOT . '/' . $rel);
            }

            // 4. Migrasi SQL — idempoten via schema_migrations
            $db = getDB();
            $db->query("CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                patch_version VARCHAR(20) NOT NULL,
                file VARCHAR(120) NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_file (file)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach ($manifest['migrations'] ?? [] as $mig) {
                $file = $mig['file'];
                $stmt = $db->prepare("SELECT COUNT(*) c FROM schema_migrations WHERE file = ?");
                $stmt->bind_param('s', $file);
                $stmt->execute();
                $done = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
                $stmt->close();
                if ($done) continue;
                $sqlRaw = $zip->getFromName('migrations/' . $file);
                foreach (splitMigrationSql((string)$sqlRaw) as $statement) {
                    if (!$db->query($statement)) {
                        throw new Exception("Migrasi '{$file}' gagal: {$db->error}");
                    }
                }
                $stmt = $db->prepare("INSERT INTO schema_migrations (patch_version, file) VALUES (?, ?)");
                $stmt->bind_param('ss', $manifest['to'], $file);
                $stmt->execute();
                $stmt->close();
            }

            $zip->close();
            @unlink(SIMANTAP_UPDATING_FLAG);
            @unlink(SIMANTAP_UPDATE_INFO_FILE);
            @unlink($patchZip);
            @unlink($patchMeta);
            @unlink(SIMANTAP_HEARTBEAT_STAMP); // heartbeat berikutnya lapor versi baru

            jsonResponse([
                'success' => true,
                'from' => $current,
                'to' => $manifest['to'],
                'backup' => basename($backupDir),
                'message' => "Update ke v{$manifest['to']} berhasil.",
            ]);

        } catch (Exception $e) {
            // Pulihkan file yang sudah tertulis dari backup
            foreach ($written as $rel) {
                $bak = $backupDir . '/' . $rel;
                if (is_file($bak)) @copy($bak, UPDATER_ROOT . '/' . $rel);
                else @unlink(UPDATER_ROOT . '/' . $rel); // file baru yang tidak ada sebelumnya
            }
            $zip->close();
            @unlink(SIMANTAP_UPDATING_FLAG);
            error_log('SIMANTAP Updater Error: ' . $e->getMessage());
            errorResponse('Update gagal & file dipulihkan: ' . $e->getMessage(), 500);
        }
        break;

    case 'rollback':
        $dirs = glob(UPDATER_BACKUP . '/patch-*', GLOB_ONLYDIR) ?: [];
        if (!$dirs) errorResponse('Tidak ada backup untuk dipulihkan.');
        rsort($dirs);
        $latest = $dirs[0];
        $restored = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($latest, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fileInfo) {
            $rel = substr($fileInfo->getPathname(), strlen($latest) + 1);
            if (!safePatchPath($rel)) continue;
            if (recursiveCopy($fileInfo->getPathname(), UPDATER_ROOT . '/' . $rel)) $restored++;
        }
        @unlink(SIMANTAP_UPDATING_FLAG);
        jsonResponse([
            'success' => true,
            'restored' => $restored,
            'backup' => basename($latest),
            'message' => "Pulih dari backup " . basename($latest) . " ({$restored} file). Catatan: migrasi database TIDAK di-revert otomatis.",
        ]);
        break;

    default:
        errorResponse('Aksi tidak dikenal.', 404);
}
