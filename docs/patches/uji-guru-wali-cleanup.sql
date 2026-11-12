-- Hapus seluruh data uji Penugasan Guru Wali (pasangan uji-guru-wali-seed.sql).
-- Jalankan: mysql --force -uroot simantap < docs/patches/uji-guru-wali-cleanup.sql
-- Tabel guru_wali_penugasan/student_asesmen dibuat saat pertama dipakai; bila belum ada, barisnya gagal (abaikan dengan --force).
-- Ambang kuota dikembalikan oleh skrip uji API sendiri (nilai sebelum uji dibaca di awal).
DELETE FROM guru_wali_penugasan WHERE student_id LIKE 's\_gw\_%';
DELETE FROM student_asesmen     WHERE student_id LIKE 's\_gw\_%';
DELETE FROM students            WHERE id LIKE 's\_gw\_%';
DELETE FROM classes             WHERE id IN ('UJI_GW_A', 'UJI_GW_B');
DELETE FROM users               WHERE uid LIKE 'uji\_gw\_%';
