-- Hapus seluruh data uji Impor Instrumen Guru Wali (pasangan uji-instrumen-guru-wali-seed.sql).
-- Jalankan: mysql --force -uroot simantap < docs/patches/uji-instrumen-guru-wali-cleanup.sql
-- Tabel yang dibuat saat pertama dipakai mungkin belum ada; barisnya gagal (abaikan dengan --force).
-- Periode aktif (system_config) dikembalikan oleh skrip uji API sendiri (nilai sebelum uji dibaca di awal).
-- Kolom/tabel/nilai enum baru TIDAK dihapus (aditif, dipakai produksi).
DELETE FROM student_instrumen_respons WHERE student_id LIKE 's\_igw\_%';
DELETE FROM student_asesmen           WHERE student_id LIKE 's\_igw\_%';
DELETE FROM student_biodata           WHERE student_id LIKE 's\_igw\_%';
DELETE FROM student_import_log        WHERE oleh LIKE 'uji\_igw\_%';
DELETE FROM guru_wali_penugasan       WHERE student_id LIKE 's\_igw\_%';
DELETE FROM students                  WHERE id LIKE 's\_igw\_%';
DELETE FROM classes                   WHERE id IN ('UJI_IGW_A', 'UJI_IGW_B');
DELETE FROM users                     WHERE uid LIKE 'uji\_igw\_%';
