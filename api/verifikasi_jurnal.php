<?php
// verifikasi_jurnal.php - Mode verifikasi jurnal mengajar (OTOMATIS SAH / MANUAL oleh Kepala Sekolah)
// Spesifikasi: docs/specs/verifikasi-jurnal-otomatis.md
// Helper saja (tanpa action). Hanya boleh dimuat lewat api/index.php.
if (!defined('SIMANTAP_API')) { http_response_code(403); exit('Forbidden'); }

// Penanda pengesah otomatis di journal_entries.verifiedBy (hanya di database; UI menampilkan nama kepsek)
const VJ_SISTEM       = 'SISTEM';
const VJ_KEY_MODE     = 'journal_verification_mode';
const VJ_KEY_MIGRASI  = 'journal_verification_migrated';
const VJ_MODE_DEFAULT = 'OTOMATIS';

/** Normalisasi nilai mode; '' bila tidak valid. */
function vjNormalisasi($nilai): string
{
    $v = is_scalar($nilai) ? strtoupper(trim((string)$nilai)) : '';
    return in_array($v, ['OTOMATIS', 'MANUAL'], true) ? $v : '';
}

function vjBacaConfig(mysqli $db, string $key): ?string
{
    $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (string)$row['config_value'] : null;
}

/** Mode efektif: nilai key bila valid, selain itu OTOMATIS. */
function vjMode(mysqli $db): string
{
    $v = vjNormalisasi(vjBacaConfig($db, VJ_KEY_MODE));
    return $v !== '' ? $v : VJ_MODE_DEFAULT;
}

function vjOtomatis(mysqli $db): bool
{
    return vjMode($db) === 'OTOMATIS';
}

/** Jumlah jurnal terkirim (non-draf) yang belum SAH. */
function vjHitungPending(mysqli $db): int
{
    $res = $db->query("SELECT COUNT(*) AS c FROM journal_entries WHERE isDraft = 0 AND isVerified = 0");
    return (int)$res->fetch_assoc()['c'];
}

/**
 * Sahkan semua jurnal non-draf yang belum SAH sebagai SISTEM. Tidak membuka transaksi sendiri.
 * createdAt ikut ter-reset tiap REPLACE INTO, jadi nilainya = waktu simpan terakhir jurnal.
 */
function vjSahkanPending(mysqli $db): int
{
    $sistem = VJ_SISTEM;
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("UPDATE journal_entries
        SET isVerified = 1, verifiedBy = ?, verifiedAt = COALESCE(createdAt, ?)
        WHERE isDraft = 0 AND isVerified = 0");
    $stmt->bind_param("ss", $sistem, $now);
    $stmt->execute();
    return $stmt->affected_rows;
}

function vjTulisConfig(mysqli $db, string $key, string $nilai, string $deskripsi, bool $timpa = true): void
{
    $sql = $timpa
        ? "INSERT INTO system_config (config_key, config_value, description) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        : "INSERT IGNORE INTO system_config (config_key, config_value, description) VALUES (?, ?, ?)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sss", $key, $nilai, $deskripsi);
    $stmt->execute();
}

/**
 * Migrasi awal sekali jalan: bila penanda belum ada dan mode efektif OTOMATIS, sahkan jurnal Pending lama.
 * Aman dipanggil di banyak action; gagal tidak memblokir request (dicoba lagi pada request berikutnya).
 */
function vjPastikanMigrasi(mysqli $db): void
{
    static $selesai = false;
    if ($selesai) return;
    try {
        if (vjBacaConfig($db, VJ_KEY_MIGRASI) !== null) { $selesai = true; return; }

        $res = $db->query("SELECT GET_LOCK('simantap_vj_migrasi', 5) AS l");
        if ((int)($res->fetch_assoc()['l'] ?? 0) !== 1) return; // request lain sedang memigrasi
        try {
            if (vjBacaConfig($db, VJ_KEY_MIGRASI) === null) {
                $db->begin_transaction();
                try {
                    if (vjOtomatis($db)) {
                        vjSahkanPending($db);
                        vjTulisConfig($db, VJ_KEY_MODE, VJ_MODE_DEFAULT, 'Mode verifikasi jurnal (OTOMATIS/MANUAL)', false);
                    }
                    vjTulisConfig($db, VJ_KEY_MIGRASI, date('Y-m-d H:i:s'), 'Penanda migrasi awal verifikasi jurnal otomatis');
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollback();
                    error_log('vjPastikanMigrasi: ' . $e->getMessage());
                    return;
                }
            }
            $selesai = true;
        } finally {
            $db->query("SELECT RELEASE_LOCK('simantap_vj_migrasi')");
        }
    } catch (Throwable $e) {
        error_log('vjPastikanMigrasi: ' . $e->getMessage());
    }
}

/** Nama kepala sekolah untuk jurnal SAH otomatis: kop_kepsek_nama → user KEPALA_SEKOLAH → 'Kepala Sekolah'. */
function vjNamaKepsek(mysqli $db): string
{
    static $nama = null;
    if ($nama !== null) return $nama;
    $kop = trim((string)vjBacaConfig($db, 'kop_kepsek_nama'));
    if ($kop !== '') return $nama = $kop;
    $res = $db->query("SELECT displayName FROM users WHERE role = 'KEPALA_SEKOLAH' AND displayName <> '' ORDER BY displayName LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    return $nama = ($row ? (string)$row['displayName'] : 'Kepala Sekolah');
}
