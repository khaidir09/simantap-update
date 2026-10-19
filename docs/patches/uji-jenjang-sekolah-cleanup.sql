-- Pembersih data uji Jenjang Sekolah (uji-jenjang-sekolah-seed.sql / uji-jenjang-sekolah-api.mjs).
-- Memulihkan school_level / kop_sekolah / kop_telp dari key uji_jj_backup:
-- nilai cadangan ada → ditulis ulang (deskripsi baris dipertahankan); null → key semula tidak ada, dihapus.
-- Tanpa key uji_jj_backup (seed belum dijalankan) konfigurasi tidak disentuh.
SET @b = (SELECT config_value FROM system_config WHERE config_key = 'uji_jj_backup');

INSERT INTO system_config (config_key, config_value)
SELECT k.kunci, JSON_UNQUOTE(JSON_EXTRACT(@b, CONCAT('$.', k.kunci)))
FROM (SELECT 'school_level' AS kunci UNION ALL SELECT 'kop_sekolah' UNION ALL SELECT 'kop_telp') k
WHERE @b IS NOT NULL AND JSON_TYPE(JSON_EXTRACT(@b, CONCAT('$.', k.kunci))) <> 'NULL'
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

DELETE FROM system_config
WHERE @b IS NOT NULL
  AND config_key IN ('school_level', 'kop_sekolah', 'kop_telp')
  AND JSON_TYPE(JSON_EXTRACT(@b, CONCAT('$.', config_key))) = 'NULL';

DELETE FROM system_config WHERE config_key = 'uji_jj_backup';
DELETE FROM students WHERE id LIKE 'UJI\_JJ\_%';
DELETE FROM classes WHERE id LIKE 'UJI\_JJ\_%';
DELETE FROM teacher_subjects WHERE teacher_id LIKE 'u\_jj\_uji\_%';
DELETE FROM gtk_pegawai WHERE id LIKE 'u\_jj\_uji\_%';
DELETE FROM users WHERE uid LIKE 'u\_jj\_uji\_%';
