<?php
/**
 * Label Media / Alat Pembelajaran untuk form jurnal: guru cukup klik label, tidak perlu mengetik ulang.
 *
 * - Label bawaan: daftar media paling umum (LABEL_MEDIA_BAWAAN).
 * - Label milik guru: label yang pernah diketik manual (disimpan lewat media_label_simpan) dan
 *   isian alat_media dari jurnal guru itu sendiri (dipisah koma), diurutkan dari yang paling sering.
 * - Guru dapat menyembunyikan label dari daftarnya (media_label_hapus); label muncul lagi bila
 *   diketik ulang.
 * - Tabel journal_media_labels dibuat saat pertama dipakai. Isi jurnal tetap teks biasa dipisah
 *   koma di journal_entries.alat_media, jadi tampilan/cetak jurnal tidak berubah.
 *
 * Bergantung pada teacher_subject_access.php (tsRequireMethod). Hanya boleh dimuat lewat api/index.php.
 */
if (!defined('SIMANTAP_API')) exit;

const LABEL_MEDIA_BAWAAN = [
    'Papan Tulis', 'Spidol', 'Buku Paket', 'LKPD', 'Modul Ajar', 'LCD Proyektor', 'Laptop',
    'PowerPoint', 'Video Pembelajaran', 'Internet', 'HP (Smartphone)', 'Alat Peraga',
    'Gambar & Poster', 'Speaker', 'Lab Komputer', 'Lingkungan Sekitar',
];
const LABEL_MEDIA_MAKS_PANJANG = 60;
const LABEL_MEDIA_MAKS_JURNAL = 300;

function labelMediaSiapkanTabel(mysqli $db): void
{
    static $siap = false;
    if ($siap) return;
    $db->query("CREATE TABLE IF NOT EXISTS journal_media_labels (
        teacher_id VARCHAR(128) NOT NULL,
        label_key VARCHAR(191) NOT NULL,
        label VARCHAR(100) NOT NULL,
        hidden TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (teacher_id, label_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $siap = true;
}

/** Rapikan satu label: spasi dirapatkan, dipotong ke panjang maksimal. '' bila tidak valid. */
function labelMediaBersihkan($label): string
{
    $label = trim(preg_replace('/\s+/u', ' ', (string)$label) ?? '');
    $label = trim($label, " ,;");
    if ($label === '') return '';
    return mb_substr($label, 0, LABEL_MEDIA_MAKS_PANJANG);
}

/** Kunci pembanding label (tidak peka huruf besar/kecil). */
function labelMediaKunci(string $label): string
{
    return mb_strtolower($label);
}

/** Pecah isian alat_media ("Proyektor, Modul") menjadi daftar label (fungsi murni). */
function labelMediaPecah(?string $teks): array
{
    $hasil = [];
    foreach (preg_split('/[,;\n]+/u', (string)$teks) ?: [] as $bagian) {
        $label = labelMediaBersihkan($bagian);
        if ($label !== '') $hasil[] = $label;
    }
    return $hasil;
}

/**
 * Susun daftar label (fungsi murni).
 * $tersimpan: list ['label', 'hidden'] milik guru; $isianJurnal: list teks alat_media jurnal guru.
 * Hasil: ['bawaan' => [label...], 'milik' => [label...]] tanpa label tersembunyi dan tanpa duplikat.
 */
function labelMediaSusun(array $tersimpan, array $isianJurnal): array
{
    $sembunyi = [];
    $milik = [];   // kunci => ['label', 'jumlah']
    foreach ($tersimpan as $row) {
        $label = labelMediaBersihkan($row['label'] ?? '');
        if ($label === '') continue;
        $k = labelMediaKunci($label);
        if (!empty($row['hidden'])) { $sembunyi[$k] = true; continue; }
        $milik[$k] = ['label' => $label, 'jumlah' => 0];
    }
    foreach ($isianJurnal as $teks) {
        foreach (labelMediaPecah($teks) as $label) {
            $k = labelMediaKunci($label);
            if (!isset($milik[$k])) $milik[$k] = ['label' => $label, 'jumlah' => 0];
            $milik[$k]['jumlah']++;
        }
    }

    $bawaan = [];
    $kunciBawaan = [];
    foreach (LABEL_MEDIA_BAWAAN as $label) {
        $k = labelMediaKunci($label);
        $kunciBawaan[$k] = true;
        if (!isset($sembunyi[$k])) $bawaan[] = $label;
    }

    $daftar = [];
    foreach ($milik as $k => $item) {
        if (isset($sembunyi[$k]) || isset($kunciBawaan[$k])) continue;
        $daftar[] = $item;
    }
    usort($daftar, fn($a, $b) => [$b['jumlah'], $a['label']] <=> [$a['jumlah'], $b['label']]);

    return ['bawaan' => $bawaan, 'milik' => array_column($daftar, 'label')];
}

// GET: daftar label untuk guru yang login.
function handleMediaLabelList(mysqli $db): void
{
    $user = requireAuth();
    labelMediaSiapkanTabel($db);
    $uid = (string)$user['uid'];

    $stmt = $db->prepare("SELECT label, hidden FROM journal_media_labels WHERE teacher_id = ?");
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $tersimpan = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt = $db->prepare("SELECT alat_media FROM journal_entries
        WHERE teacherId = ? AND alat_media IS NOT NULL AND alat_media <> ''
        ORDER BY date DESC LIMIT " . LABEL_MEDIA_MAKS_JURNAL);
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $isian = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'alat_media');

    jsonResponse(labelMediaSusun($tersimpan, $isian));
}

// POST {label}: simpan label yang diketik manual (atau tampilkan lagi label yang pernah disembunyikan).
function handleMediaLabelSimpan(mysqli $db): void
{
    $user = requireAuth();
    tsRequireMethod(['POST']);
    $label = labelMediaBersihkan(getRequestBody()['label'] ?? '');
    if ($label === '') errorResponse('Label media wajib diisi');
    labelMediaSiapkanTabel($db);

    $uid = (string)$user['uid'];
    $kunci = labelMediaKunci($label);
    $stmt = $db->prepare("INSERT INTO journal_media_labels (teacher_id, label_key, label, hidden)
        VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE label = VALUES(label), hidden = 0");
    $stmt->bind_param('sss', $uid, $kunci, $label);
    $stmt->execute();
    jsonResponse(['success' => true, 'label' => $label]);
}

// POST {label}: sembunyikan label dari daftar guru yang login (jurnal lama tidak berubah).
function handleMediaLabelHapus(mysqli $db): void
{
    $user = requireAuth();
    tsRequireMethod(['POST']);
    $label = labelMediaBersihkan(getRequestBody()['label'] ?? '');
    if ($label === '') errorResponse('Label media wajib diisi');
    labelMediaSiapkanTabel($db);

    $uid = (string)$user['uid'];
    $kunci = labelMediaKunci($label);
    $stmt = $db->prepare("INSERT INTO journal_media_labels (teacher_id, label_key, label, hidden)
        VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE hidden = 1");
    $stmt->bind_param('sss', $uid, $kunci, $label);
    $stmt->execute();
    jsonResponse(['success' => true]);
}
