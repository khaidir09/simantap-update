// Uji API Impor Instrumen Guru Wali (docs/specs/impor-instrumen-guru-wali.md §10.2).
// HANYA untuk dev/staging. Prasyarat: uji-instrumen-guru-wali-cleanup.sql lalu uji-instrumen-guru-wali-seed.sql sudah dijalankan.
// UJI_MYSQL wajib (pemeriksaan/manipulasi DB langsung), bawaan: Laragon MySQL 5.7 tanpa password.
// Periode aktif disetel ke 2026/2027 GANJIL (Cap waktu file contoh 13/11/2026) lalu dikembalikan di akhir.
// Jalankan: BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=uji12345 node docs/patches/uji-instrumen-guru-wali-api.mjs
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const PASSWORD = process.env.UJI_PASSWORD || 'uji12345';
const MYSQL = process.env.UJI_MYSQL || 'E:/laragon/bin/mysql/mysql-5.7.33-winx64/bin/mysql.exe -uroot simantap';
const DIR = path.dirname(fileURLToPath(import.meta.url));
const KASUS = JSON.parse(readFileSync(path.join(DIR, 'uji-instrumen-guru-wali-kasus.json'), 'utf8'));
let pass = 0, failed = 0;
const jars = {};

async function call(who, action, { method = 'GET', body = null, query = '' } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (who && jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const setCookie = res.headers.get('set-cookie');
  if (who && setCookie) jars[who] = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text };
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${typeof info === 'string' ? info : JSON.stringify(info)}`.slice(0, 1500)); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `${who}@uji.test`, password: PASSWORD } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text.slice(0, 200)}`);
}
function sql(query) {
  const parts = MYSQL.split(' ').filter(Boolean);
  return execFileSync(parts[0], [...parts.slice(1), '--default-character-set=utf8mb4', '-N', '-e', query], { encoding: 'utf8' }).trim();
}
const q = (params) => Object.entries(params).map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('');
const post = (who, action, body) => call(who, action, { method: 'POST', body });
const get = (who, action, params = {}) => call(who, action, { query: q(params) });
const brief = (r) => `${r.status} ${r.text.slice(0, 400)}`;
const fmt = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar' }).format(d);
const D_10 = fmt(new Date(Date.now() - 10 * 86400000));
const impor = (who, rows, mode = 'pratinjau') => post(who, 'instrumen_gw_impor', { mode, rows, nama_file: 'uji.xlsx' });

// --- Baris file ---
const HEADER = KASUS.rows[0];
const ASLI = KASUS.rows.slice(1);
const kol = (kode) => {
  if (kode === 'cap') return 0;
  const i = HEADER.findIndex((h) => h.startsWith(`[${kode}]`));
  if (i < 0) throw new Error(`kolom ${kode} tidak ada`);
  return i;
};
const ubah = (row, isi) => { const r = [...row]; for (const [k, v] of Object.entries(isi)) r[kol(k)] = v; return r; };
const DELAPAN_KURANG_SATU = 'Memperbaiki barang, Mencari solusi, Memberi saran, Mengutak-atik barang, Menari, Mengelola acara, Memahami orang lain';
const SINTETIS = [
  ubah(ASLI[0], { A00: '', A01: '0099100004' }),                                   // S1 tanpa persetujuan -> LEWATI
  ubah(ASLI[0], { A01: '0099100005', A02: 'Rahasia' }),                            // S2 dampingan gw2 -> LEWATI tanpa nama DB
  ubah(ASLI[0], { A01: '0099100006' }),                                            // S3 murid nonaktif -> LEWATI
  ubah(ASLI[0], { cap: '45800', A01: '0099100004' }),                              // S4 Mei 2025 -> LEWATI periode
  ubah(ASLI[0], { cap: 'kemarin', A01: '0099100004' }),                            // S5 cap waktu rusak -> GALAT
  ubah(ASLI[0], { cap: '46338.5', A01: '99100004', A02: 'Uji IGW Murid Empat' }),  // S6 lebih lama -> LEWATI diganti
  ubah(ASLI[0], { cap: '46339.9', A01: '0099100004', A02: 'Uji IGW Murid Empat', A45: DELAPAN_KURANG_SATU, A30: 'Opsi lain' }), // S7 BARU + peringatan
  ubah(ASLI[0], { A01: '1.14E+09' }),                                              // S8 NISN notasi ilmiah -> GALAT
];
const FILE = [HEADER, ...ASLI, ...SINTETIS];
const barisNisn = (r, nisn) => (r.json.baris || []).filter((b) => b.nisn === nisn);
const statusBaris = (r, no) => (r.json.baris || []).find((b) => b.baris === no) || {};

async function main() {
  const semua = ['uji_igw_ks', 'uji_igw_adm', 'uji_igw_tu', 'uji_igw_gw1', 'uji_igw_gw2', 'uji_igw_gw0', 'uji_igw_wk'];
  for (const who of semua) await login(who);

  // Periode aktif: simpan nilai awal, setel 2026/2027 GANJIL
  const awal = {};
  for (const k of ['active_academic_year', 'active_semester']) {
    const n = sql(`SELECT COUNT(*) FROM system_config WHERE config_key='${k}'`);
    awal[k] = n === '0' ? null : sql(`SELECT config_value FROM system_config WHERE config_key='${k}'`);
  }
  const setCfg = (k, v) => {
    sql(`DELETE FROM system_config WHERE config_key='${k}'`);
    if (v !== null) sql(`INSERT INTO system_config (config_key, config_value) VALUES ('${k}', '${v.replace(/'/g, "''")}')`);
  };
  setCfg('active_academic_year', '2026/2027');
  setCfg('active_semester', 'GANJIL');
  try {
    await jalankan();
  } finally {
    for (const [k, v] of Object.entries(awal)) setCfg(k, v);
  }
  console.log(`\nSelesai: ${pass} lulus, ${failed} gagal`);
  process.exit(failed ? 1 : 0);
}

async function jalankan() {
  // Persiapan: tabel Profil Murid + instrumen dibuat, penugasan guru wali, murid nonaktif, biodata, asesmen manual
  let r = await get('uji_igw_ks', 'profil_murid_get', { student_id: 's_igw_1' });
  check('P0 profil (membuat tabel)', r.status === 200, brief(r));
  r = await post('uji_igw_ks', 'guru_wali_tugaskan', { guru_uid: 'uji_igw_gw1', student_ids: ['s_igw_1', 's_igw_2', 's_igw_3', 's_igw_4', 's_igw_6'], tanggal_mulai: D_10 });
  check('P1 tugaskan 5 murid ke gw1', r.status === 200 && r.json.dibuat === 5, brief(r));
  r = await post('uji_igw_ks', 'guru_wali_tugaskan', { guru_uid: 'uji_igw_gw2', student_ids: ['s_igw_5'], tanggal_mulai: D_10 });
  check('P2 tugaskan 1 murid ke gw2', r.status === 200 && r.json.dibuat === 1, brief(r));
  sql("UPDATE students SET status='Lulus' WHERE id='s_igw_6'");
  sql("INSERT INTO student_biodata (student_id, alat_transportasi, jarak_rumah_km, diimpor_oleh, diimpor_oleh_nama) VALUES ('s_igw_1', 'Jalan kaki', NULL, 'uji_igw_tu', 'Uji IGW TU')");
  r = await post('uji_igw_wk', 'profil_murid_asesmen_simpan', { student_id: 's_igw_1', semester: 'GANJIL', riasec_r: 10, riasec_i: 20, riasec_a: 30,
    riasec_s: 40, riasec_e: 50, riasec_c: 60, gaya_belajar: 'Kinestetik', cita_cita: 'Lama', jurusan_tujuan: 'TKJ', catatan_karir: 'Catatan wali' });
  check('P3 asesmen manual wali kelas s_igw_1', r.status === 200, brief(r));
  const kolomSkema = sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_asesmen' AND COLUMN_NAME IN ('kompetensi_kode','kegiatan_dikuasai','alasan_rencana','instrumen_respons_id')");
  check('P4 kolom baru student_asesmen ada', kolomSkema === '4', kolomSkema);
  check('P5 enum sumber + jenis diperluas', sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='student_asesmen' AND COLUMN_NAME='sumber') OR (TABLE_NAME='student_import_log' AND COLUMN_NAME='jenis')) AND COLUMN_TYPE LIKE '%INSTRUMEN%'") === '2');

  // 1. Peran dan metode
  for (const who of ['uji_igw_ks', 'uji_igw_adm', 'uji_igw_tu']) {
    r = await impor(who, FILE);
    check(`T1 ${who} -> 403`, r.status === 403, brief(r));
  }
  r = await impor('uji_igw_wk', FILE);
  check('T1 wali kelas tanpa dampingan -> 403', r.status === 403 && /dampingan/.test(r.json?.error || ''), brief(r));
  r = await impor('uji_igw_gw0', FILE);
  check('T1 guru tanpa dampingan -> 403', r.status === 403, brief(r));
  r = await get('uji_igw_gw1', 'instrumen_gw_impor');
  check('T1 GET -> 405', r.status === 405, brief(r));
  r = await call(null, 'instrumen_gw_impor', { method: 'POST', body: { rows: FILE } });
  check('T1 tanpa login -> 401', r.status === 401, brief(r));

  // 2. Format/ukuran
  r = await impor('uji_igw_gw1', [HEADER.filter((h) => !h.startsWith('[A01]')), ...ASLI]);
  check('T2 tanpa [A01] -> 400', r.status === 400 && /Format file/.test(r.json?.error || ''), brief(r));
  r = await impor('uji_igw_gw1', [['NISN', 'Nama', 'R'], ['0099200001', 'x', '50']]);
  check('T2 template SIMANTAP -> 400', r.status === 400, brief(r));
  r = await impor('uji_igw_gw1', [HEADER, ...Array.from({ length: 501 }, () => ASLI[0])]);
  check('T2 > 500 baris -> 400', r.status === 400, brief(r));
  r = await impor('uji_igw_gw1', [Array.from({ length: 121 }, (_, i) => `k${i}`)]);
  check('T2 > 120 kolom -> 400', r.status === 400, brief(r));
  r = await impor('uji_igw_gw1', FILE, 'hapus');
  check('T2 mode tidak valid -> 400', r.status === 400, brief(r));

  // 3. Pratinjau: klasifikasi tanpa menulis
  const sebelum = sql("SELECT COUNT(*) FROM student_instrumen_respons WHERE student_id LIKE 's\\_igw\\_%'");
  r = await impor('uji_igw_gw1', FILE);
  check('T3 pratinjau 200', r.status === 200, brief(r));
  const rk = r.json?.ringkasan || {};
  check('T3 ringkasan baru 3, timpa 1, lewati 5, galat 2, total 11', rk.baru === 3 && rk.timpa === 1 && rk.lewati === 5 && rk.galat === 2 && rk.total === 11, rk);
  check('T3 periode', r.json.academic_year === '2026/2027' && r.json.semester === 'GANJIL', r.json);
  check('T3 tanpa peringatan file', Array.isArray(r.json.peringatan_file) && r.json.peringatan_file.length === 0, r.json.peringatan_file);
  check('T3 tidak menulis DB', sql("SELECT COUNT(*) FROM student_instrumen_respons WHERE student_id LIKE 's\\_igw\\_%'") === sebelum);
  const b1 = barisNisn(r, '0099200001')[0] || {};
  const h1 = KASUS.harapan[0];
  check('T4 s_igw_1 TIMPA + skor', b1.status === 'TIMPA' && b1.riasec_kode === h1.riasec_kode && b1.kompetensi_kode === h1.kompetensi_kode
    && b1.gaya_belajar === h1.gaya && b1.rencana_lulus === 'KERJA', b1);
  check('T4 s_igw_1 pesan Mengganti', (b1.pesan || []).some((p) => /Mengganti: .*minat RIASEC/.test(p)), b1.pesan);
  check('T4 s_igw_1 peringatan nama beda', (b1.peringatan || []).some((p) => /Nama di formulir/.test(p)), b1.peringatan);
  check('T4 s_igw_1 cap waktu', b1.cap_waktu === '2026-11-13 18:26:42', b1.cap_waktu);
  for (const [i, h] of KASUS.harapan.entries()) {
    const b = barisNisn(r, h.nisn)[0] || {};
    check(`T4 respons ${i + 1} kode ${h.riasec_kode}/${h.kompetensi_kode}/${h.gaya}`, b.riasec_kode === h.riasec_kode && b.kompetensi_kode === h.kompetensi_kode && b.gaya_belajar === h.gaya, b);
  }
  const s = (no) => statusBaris(r, no);  // baris file: header=1, asli 2..4, sintetis 5..12
  check('T5 S1 tanpa persetujuan -> LEWATI', s(5).status === 'LEWATI' && /menyetujui/.test(s(5).pesan.join()), s(5));
  check('T5 S2 dampingan guru lain -> LEWATI tanpa nama DB', s(6).status === 'LEWATI' && /Bukan murid dampingan/.test(s(6).pesan.join()) && s(6).nama === null && s(6).student_id === null, s(6));
  check('T5 S3 murid nonaktif -> LEWATI', s(7).status === 'LEWATI' && /Bukan murid dampingan/.test(s(7).pesan.join()), s(7));
  check('T5 S4 luar periode -> LEWATI', s(8).status === 'LEWATI' && /luar periode/.test(s(8).pesan.join()), s(8));
  check('T5 S5 cap waktu rusak -> GALAT', s(9).status === 'GALAT' && /Cap waktu/.test(s(9).pesan.join()), s(9));
  check('T5 S6 respons lebih lama -> LEWATI diganti baris 11', s(10).status === 'LEWATI' && /Diganti respons lebih baru \(baris 11\)/.test(s(10).pesan.join()), s(10));
  check('T5 S7 BARU + peringatan 7 kegiatan & opsi', s(11).status === 'BARU' && /7 kegiatan/.test(s(11).peringatan.join()) && /\[A30\]/.test(s(11).peringatan.join()), s(11));
  check('T5 S8 NISN notasi ilmiah -> GALAT', s(12).status === 'GALAT', s(12));

  // 6. Simpan
  r = await impor('uji_igw_gw1', FILE, 'simpan');
  check('T6 simpan 200, disimpan 4', r.status === 200 && r.json.disimpan === 4 && r.json.impor_id, brief(r));
  const a1 = sql("SELECT CONCAT_WS('|', riasec_kode, kompetensi_kode, gaya_belajar, rencana_lulus, sumber, catatan_karir, jurusan_tujuan, cita_cita, instrumen_respons_id IS NOT NULL) FROM student_asesmen WHERE student_id='s_igw_1' AND academic_year='2026/2027' AND semester='GANJIL'");
  check('T6 s_igw_1: instrumen menimpa, catatan & jurusan tetap', a1 === `${h1.riasec_kode}|${h1.kompetensi_kode}|${h1.gaya}|KERJA|INSTRUMEN|Catatan wali|TKJ|PLN|1`, a1);
  const log1 = JSON.parse(sql("SELECT logs FROM student_asesmen WHERE student_id='s_igw_1' AND academic_year='2026/2027'"));
  const akhir = log1[log1.length - 1];
  check('T6 log INSTRUMEN + field', akhir.aksi === 'INSTRUMEN' && akhir.oleh === 'uji_igw_gw1' && akhir.field.includes('riasec_kode') && !akhir.field.includes('catatan_karir'), akhir);
  check('T6 s_igw_4 kegiatan 7', JSON.parse(sql("SELECT kegiatan_dikuasai FROM student_asesmen WHERE student_id='s_igw_4'")).length === 7);
  check('T6 respons tersimpan 4', sql("SELECT COUNT(*) FROM student_instrumen_respons WHERE student_id LIKE 's\\_igw\\_%'") === '4');
  check('T6 respons s_igw_4 = yang terbaru', sql("SELECT cap_waktu FROM student_instrumen_respons WHERE student_id='s_igw_4'").startsWith('2026-11-13 21:36'));
  check('T6 import log INSTRUMEN', sql("SELECT CONCAT_WS('|', jenis, jml_baris, jml_tulis, jml_lewati, jml_galat) FROM student_import_log WHERE oleh='uji_igw_gw1'") === 'INSTRUMEN|11|4|5|2');
  check('T6 dampingan gw2/nonaktif tidak ditulis', sql("SELECT COUNT(*) FROM student_asesmen WHERE student_id IN ('s_igw_5','s_igw_6')") === '0');

  // 7. Biodata
  check('T7 jarak diisi titik tengah, transportasi Dapodik tetap', sql("SELECT CONCAT_WS('|', jarak_rumah_km, alat_transportasi) FROM student_biodata WHERE student_id='s_igw_1'") === '10.00|Jalan kaki');
  check('T7 murid tanpa biodata tidak dibuatkan baris', sql("SELECT COUNT(*) FROM student_biodata WHERE student_id='s_igw_2'") === '0');

  // 8. Impor ulang & respons lebih lama
  r = await impor('uji_igw_gw1', [HEADER, ...ASLI], 'simpan');
  check('T8 impor ulang idempoten (TIMPA, tanpa perubahan)', r.status === 200 && r.json.ringkasan.timpa === 3
    && r.json.baris.every((b) => b.pesan.includes('Tidak ada perubahan nilai')), brief(r));
  r = await impor('uji_igw_gw1', [HEADER, ubah(ASLI[1], { cap: '46330.5' })]);
  check('T8 respons lebih lama dari tersimpan -> LEWATI', r.status === 200 && r.json.baris[0].status === 'LEWATI' && /lebih baru/.test(r.json.baris[0].pesan.join()), brief(r));
  r = await impor('uji_igw_gw1', [HEADER, ubah(ASLI[1], { cap: '13/11/2026 22:00:00' })]);
  check('T8 cap waktu teks (CSV) terbaca', r.status === 200 && r.json.baris[0].cap_waktu === '2026-11-13 22:00:00' && r.json.baris[0].status === 'TIMPA', brief(r));

  // 9. Pembaca
  r = await get('uji_igw_ks', 'profil_murid_get', { student_id: 's_igw_1' });
  const as = r.json?.asesmen?.[0] || {};
  check('T9 profil KS: kolom baru', r.status === 200 && as.kompetensi_kode === h1.kompetensi_kode && Array.isArray(as.kegiatan_dikuasai) && as.kegiatan_dikuasai.length === 8
    && typeof as.kompetensi_a === 'number' && Array.isArray(as.mapel_pilihan) && as.alasan_rencana, as);
  const ins = r.json?.instrumen?.[0] || {};
  check('T9 profil KS: jawaban mentah', ins.jawaban?.A01 === '0099200001' && ins.jawaban?.A40_01 === 'Tidak suka' && ins.judul?.A40_01 === 'Aku suka mengulik peralatan'
    && ins.guru_nama === 'Uji IGW Guru Wali 1' && ins.jarak_kategori === 'Lebih dari 10 km', ins);
  r = await get('uji_igw_gw1', 'profil_murid_get', { student_id: 's_igw_2' });
  check('T9 profil guru wali: instrumen terbaca', r.status === 200 && r.json.instrumen.length === 1 && r.json.akses.bisa_edit_asesmen === false, brief(r));
  r = await get('uji_igw_wk', 'profil_murid_get', { student_id: 's_igw_1' });
  check('T9 profil wali kelas: instrumen terbaca', r.status === 200 && r.json.instrumen.length === 1, brief(r));
  r = await get('uji_igw_gw1', 'guru_wali_dampingan_saya');
  const dm = Object.fromEntries((r.json?.murid || []).map((m) => [m.id, m]));
  check('T9 dampingan_saya: instrumen_cap_waktu + ringkasan', r.status === 200 && dm.s_igw_1?.instrumen_cap_waktu === '2026-11-13 18:26:42'
    && r.json.ringkasan?.instrumen_terisi === 4, brief(r));
  r = await get('uji_igw_ks', 'cetak_profil_murid', { student_id: 's_igw_1' });
  check('T9 cetak memuat kompetensi & alasan', r.status === 200 && r.text.includes('Kompetensi RIASEC') && r.text.includes('Alasan memilih rencana'), r.text.slice(0, 200));

  // 10. Guru wali tetap baca-saja untuk simpan/hapus manual
  r = await post('uji_igw_gw1', 'profil_murid_asesmen_simpan', { student_id: 's_igw_1', semester: 'GANJIL', cita_cita: 'X' });
  check('T10 guru wali simpan manual -> 403', r.status === 403, brief(r));
  r = await post('uji_igw_gw1', 'profil_murid_asesmen_hapus', { student_id: 's_igw_1', semester: 'GANJIL' });
  check('T10 guru wali hapus manual -> 403', r.status === 403, brief(r));

  // 11. Impor template / simpan manual tidak menyentuh kolom baru
  r = await post('uji_igw_wk', 'profil_murid_asesmen_impor', { mode: 'simpan', class_id: 'UJI_IGW_A', semester: 'GANJIL',
    rows: [['NISN', 'R', 'I', 'A', 'S', 'E', 'C'], ['0099200001', '50', '50', '50', '50', '50', '90']] });
  check('T11 impor template wali kelas 200', r.status === 200, brief(r));
  const a11 = sql("SELECT CONCAT_WS('|', riasec_kode, kompetensi_kode, sumber, alasan_rencana IS NOT NULL, instrumen_respons_id IS NOT NULL) FROM student_asesmen WHERE student_id='s_igw_1' AND academic_year='2026/2027'");
  check('T11 template menimpa RIASEC, kolom baru tetap', a11 === `CRI|${h1.kompetensi_kode}|IMPOR|1|1`, a11);
  r = await post('uji_igw_wk', 'profil_murid_asesmen_simpan', { student_id: 's_igw_2', semester: 'GANJIL', catatan_karir: 'Catatan manual' });
  check('T11 simpan manual s_igw_2 200', r.status === 200, brief(r));
  check('T11 simpan manual: kolom baru tetap', sql("SELECT CONCAT_WS('|', kompetensi_kode IS NOT NULL, bakat IS NOT NULL) FROM student_asesmen WHERE student_id='s_igw_2'") === '1|1');

  // 12. Penugasan berubah antara pratinjau dan simpan
  r = await post('uji_igw_ks', 'guru_wali_tugaskan', { guru_uid: 'uji_igw_gw2', student_ids: ['s_igw_3'], ganti: true, alasan: 'KOREKSI_DATA' });
  check('T12 s_igw_3 dipindah ke gw2', r.status === 200, brief(r));
  r = await impor('uji_igw_gw1', [HEADER, ASLI[2]]);
  check('T12 gw1 tidak bisa lagi menulis s_igw_3', r.status === 200 && r.json.baris[0].status === 'LEWATI', brief(r));
  r = await impor('uji_igw_gw2', [HEADER, ASLI[2]]);
  check('T12 gw2 kini bisa (TIMPA)', r.status === 200 && r.json.baris[0].status === 'TIMPA', brief(r));
}

main().catch((e) => { console.error(e); process.exit(1); });
