// Uji API Penugasan Guru Wali (docs/specs/penugasan-guru-wali.md §10.2).
// HANYA untuk dev/staging. Prasyarat: uji-guru-wali-cleanup.sql lalu uji-guru-wali-seed.sql sudah dijalankan.
// UJI_MYSQL wajib (pemeriksaan/manipulasi DB langsung), bawaan: Laragon MySQL 5.7 tanpa password.
// Jalankan: BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=uji12345 node docs/patches/uji-guru-wali-api.mjs
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const PASSWORD = process.env.UJI_PASSWORD || 'uji12345';
const MYSQL = process.env.UJI_MYSQL || 'E:/laragon/bin/mysql/mysql-5.7.33-winx64/bin/mysql.exe -uroot simantap';
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
  return execFileSync(parts[0], [...parts.slice(1), '-N', '-e', query], { encoding: 'utf8' }).trim();
}
const q = (params) => Object.entries(params).map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('');
const post = (who, action, body) => call(who, action, { method: 'POST', body });
const get = (who, action, params = {}) => call(who, action, { query: q(params) });
const brief = (r) => `${r.status} ${r.text.slice(0, 300)}`;

// Tanggal lokal server (Asia/Makassar)
const fmt = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar' }).format(d);
const hari = (n) => fmt(new Date(Date.now() + n * 86400000));
const D0 = hari(0), D_1 = hari(-1), D_2 = hari(-2), D_5 = hari(-5), D_10 = hari(-10), BESOK = hari(1);
const aktifId = (sid) => sql(`SELECT id FROM guru_wali_penugasan WHERE student_id='${sid}' AND status='AKTIF'`);
const aktifGuru = (sid) => sql(`SELECT guru_uid FROM guru_wali_penugasan WHERE student_id='${sid}' AND status='AKTIF'`);

async function main() {
  const semua = ['uji_gw_admin', 'uji_gw_ks', 'uji_gw_tu', 'uji_gw_1', 'uji_gw_2', 'uji_gw_3', 'uji_gw_4'];
  for (const who of semua) await login(who);
  const ada = sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guru_wali_penugasan'");
  if (ada === '1' && sql("SELECT COUNT(*) FROM guru_wali_penugasan WHERE student_id LIKE 's\\_gw\\_%'") !== '0') {
    throw new Error('Data uji guru wali sudah ada. Jalankan uji-guru-wali-cleanup.sql lalu seed ulang.');
  }
  const cfgAwal = await get('uji_gw_admin', 'get_system_config');
  const kuotaAwal = cfgAwal.json?.guru_wali_kuota_peringatan?.value ?? '20';

  // ---- T1 Otorisasi ----
  const baca = ['guru_wali_ringkasan', 'guru_wali_murid', 'guru_wali_riwayat', 'guru_wali_template'];
  const tulis = ['guru_wali_tugaskan', 'guru_wali_akhiri', 'guru_wali_tutup_nonaktif', 'guru_wali_batalkan', 'guru_wali_impor'];
  for (const a of baca) {
    for (const [who, st] of [['uji_gw_admin', 200], ['uji_gw_ks', 200], ['uji_gw_1', 403], ['uji_gw_3', 403], ['uji_gw_tu', 403], [null, 401]]) {
      const r = await get(who, a);
      check(`T1 ${a} ${who ?? 'anonim'} -> ${st}`, r.status === st, brief(r));
    }
  }
  for (const a of tulis) {
    for (const [who, st] of [['uji_gw_1', 403], ['uji_gw_tu', 403], [null, 401]]) {
      const r = await post(who, a, {});
      check(`T1 ${a} ${who ?? 'anonim'} -> ${st}`, r.status === st, brief(r));
    }
    const r = await get('uji_gw_admin', a);
    check(`T1 GET ${a} -> 405`, r.status === 405, brief(r));
  }
  let r = await get('uji_gw_tu', 'guru_wali_dampingan_saya');
  check('T1 dampingan_saya TU -> 403', r.status === 403, brief(r));
  r = await get('uji_gw_4', 'guru_wali_dampingan_saya');
  check('T1 dampingan_saya GURU tanpa dampingan -> 200 kosong', r.status === 200 && r.json.murid.length === 0, brief(r));
  r = await get('uji_gw_admin', 'guru_wali_dampingan_saya');
  check('T1 dampingan_saya ADMIN tanpa guru_uid -> kosong', r.status === 200 && r.json.guru === null && r.json.murid.length === 0, brief(r));

  // ---- T2 Tugaskan ----
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['s_gw_1', 's_gw_3', 's_gw_4'], tanggal_mulai: D_10 });
  check('T2 tugaskan 3 murid lintas kelas -> dibuat 3', r.status === 200 && r.json.dibuat === 3 && r.json.diganti === 0, brief(r));
  r = await post('uji_gw_ks', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['s_gw_1', 's_gw_3', 's_gw_4'], tanggal_mulai: D_10 });
  check('T2 ulang (KS) -> sama 3', r.status === 200 && r.json.dibuat === 0 && r.json.sama.length === 3, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['s_gw_5'] });
  check('T2 murid Lulus -> 400', r.status === 400 && r.json.nonaktif?.[0]?.student_id === 's_gw_5', brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['s_gw_8'], tanggal_mulai: BESOK });
  check('T2 tanggal besok -> 400', r.status === 400, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_tu', student_ids: ['s_gw_8'] });
  check('T2 guru = TU -> 400', r.status === 400 && /bukan akun guru/.test(r.json?.error || ''), brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'tidak_ada', student_ids: ['s_gw_8'] });
  check('T2 guru tidak ada -> 404', r.status === 404, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['tidak_ada'] });
  check('T2 murid tidak ada -> 400', r.status === 400 && r.json.tidak_ditemukan?.[0] === 'tidak_ada', brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: Array.from({ length: 201 }, (_, i) => `x${i}`) });
  check('T2 > 200 murid -> 400', r.status === 400, brief(r));

  // ---- T3 Ganti ----
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: ['s_gw_1'], tanggal_mulai: D_1 });
  check('T3 ganti tanpa konfirmasi -> 409 perlu_konfirmasi', r.status === 409 && r.json.perlu_konfirmasi?.[0]?.guru_lama_nama === 'Uji GW Satu', brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: ['s_gw_1'], tanggal_mulai: D_1, ganti: true });
  check('T3 ganti tanpa alasan -> 400', r.status === 400 && /Alasan/.test(r.json?.error || ''), brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: ['s_gw_1'], tanggal_mulai: D_1, ganti: true, alasan: 'LAINNYA' });
  check('T3 alasan Lainnya tanpa catatan -> 400', r.status === 400, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: ['s_gw_1'], tanggal_mulai: D_10, ganti: true, alasan: 'BEBAN_KERJA' });
  check('T3 tanggal = mulai lama -> 400 (arahkan Batalkan)', r.status === 400 && /Batalkan/.test(r.json?.error || ''), brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: ['s_gw_1'], tanggal_mulai: D_1, ganti: true, alasan: 'Beban kerja', catatan: 'Uji' });
  check('T3 ganti sah (alasan label) -> diganti 1', r.status === 200 && r.json.diganti === 1, brief(r));
  const lama = sql(`SELECT CONCAT(status,'|',tanggal_selesai,'|',alasan_selesai,'|',guru_uid) FROM guru_wali_penugasan WHERE student_id='s_gw_1' AND status<>'AKTIF'`);
  check('T3 lama SELESAI pada T-1 dengan alasan', lama === `SELESAI|${D_2}|BEBAN_KERJA|uji_gw_1`, lama);
  check('T3 baru aktif gw4', aktifGuru('s_gw_1') === 'uji_gw_4', aktifGuru('s_gw_1'));

  // ---- T4 Satu aktif (paralel) ----
  const [p1, p2] = await Promise.all([
    post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['s_gw_2'], tanggal_mulai: D_5 }),
    post('uji_gw_ks', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: ['s_gw_2'], tanggal_mulai: D_5 }),
  ]);
  const st = [p1.status, p2.status].sort().join(',');
  check('T4 paralel -> tepat satu 200, satu 409', st === '200,409', `${brief(p1)} || ${brief(p2)}`);
  check('T4 DB satu baris aktif', sql("SELECT COUNT(*) FROM guru_wali_penugasan WHERE student_id='s_gw_2' AND status='AKTIF'") === '1');
  const guruS2 = aktifGuru('s_gw_2');
  const adaUnique = sql("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='guru_wali_penugasan' AND INDEX_NAME='uq_gw_aktif'");
  check('T4 unique key uq_gw_aktif ada', adaUnique !== '0', adaUnique);

  // ---- T5 Batalkan ----
  const idGw4 = aktifId('s_gw_1');
  r = await post('uji_gw_admin', 'guru_wali_batalkan', { penugasan_id: idGw4 });
  check('T5 batalkan tanpa alasan -> 400', r.status === 400, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_batalkan', { penugasan_id: idGw4, alasan_batal: 'Salah pilih guru' });
  check('T5 batalkan -> pulihkan gw1', r.status === 200 && r.json.dipulihkan?.guru_nama === 'Uji GW Satu', brief(r));
  check('T5 gw1 aktif lagi (tanggal_selesai kosong)', sql("SELECT CONCAT(guru_uid,'|',IFNULL(tanggal_selesai,'-')) FROM guru_wali_penugasan WHERE student_id='s_gw_1' AND status='AKTIF'") === 'uji_gw_1|-');
  r = await get('uji_gw_admin', 'guru_wali_riwayat', { student_id: 's_gw_1' });
  check('T5 riwayat resmi sembunyikan dibatalkan', r.status === 200 && r.json.total === 1 && r.json.baris[0].guru_uid === 'uji_gw_1', brief(r));
  r = await get('uji_gw_admin', 'guru_wali_riwayat', { student_id: 's_gw_1', termasuk_batal: '1' });
  check('T5 termasuk_batal tampilkan pembatal', r.status === 200 && r.json.total === 2 && r.json.baris.some((b) => b.status === 'DIBATALKAN' && b.dibatalkan_oleh_nama === 'Uji GW Admin'), brief(r));
  r = await post('uji_gw_admin', 'guru_wali_batalkan', { penugasan_id: idGw4, alasan_batal: 'lagi' });
  check('T5 batalkan yang sudah dibatalkan -> 409', r.status === 409, brief(r));

  // ---- T6 Akhiri & menggantung ----
  const idS4 = aktifId('s_gw_4');
  r = await post('uji_gw_admin', 'guru_wali_akhiri', { penugasan_ids: [idS4], tanggal_selesai: D_1 });
  check('T6 akhiri tanpa alasan -> 400', r.status === 400, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_akhiri', { penugasan_ids: [idS4], tanggal_selesai: hari(-20), alasan: 'MUTASI_GURU' });
  check('T6 akhiri sebelum tanggal mulai -> 400', r.status === 400, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_akhiri', { penugasan_ids: [idS4], tanggal_selesai: D_1, alasan: 'MUTASI_GURU' });
  check('T6 akhiri -> 200', r.status === 200 && r.json.diakhiri === 1, brief(r));
  r = await get('uji_gw_admin', 'guru_wali_murid', { class_id: 'UJI_GW_B', status_penugasan: 'BELUM' });
  check('T6 murid diakhiri muncul di BELUM', r.status === 200 && r.json.murid.some((m) => m.id === 's_gw_4'), brief(r));
  r = await post('uji_gw_admin', 'guru_wali_batalkan', { penugasan_id: idS4, alasan_batal: 'x' });
  check('T6 batalkan penugasan SELESAI -> 409', r.status === 409, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_akhiri', { penugasan_ids: [idS4], alasan: 'MUTASI_GURU' });
  check('T6 akhiri ulang -> 409', r.status === 409, brief(r));
  r = await get('uji_gw_admin', 'guru_wali_ringkasan');
  const gantungAwal = r.json.penugasan_menggantung;
  sql("UPDATE students SET status='Lulus' WHERE id='s_gw_3'");
  r = await get('uji_gw_1', 'guru_wali_dampingan_saya');
  check('T6 murid Lulus hilang dari dampingan_saya', r.status === 200 && !r.json.murid.some((m) => m.id === 's_gw_3') && r.json.murid.some((m) => m.id === 's_gw_1'), brief(r));
  r = await get('uji_gw_admin', 'guru_wali_ringkasan');
  check('T6 menggantung +1', r.json.penugasan_menggantung === gantungAwal + 1, brief(r));
  const g1 = r.json.guru.find((g) => g.uid === 'uji_gw_1');
  check('T6 hitungan gw1 tidak memuat murid Lulus', g1 && g1.jumlah_dampingan === (guruS2 === 'uji_gw_1' ? 2 : 1), JSON.stringify(g1));
  r = await post('uji_gw_admin', 'guru_wali_tutup_nonaktif', {});
  check('T6 tutup_nonaktif -> ditutup >= 1', r.status === 200 && r.json.ditutup >= 1, brief(r));
  check('T6 alasan MURID_NONAKTIF', sql("SELECT alasan_selesai FROM guru_wali_penugasan WHERE student_id='s_gw_3'") === 'MURID_NONAKTIF');
  r = await get('uji_gw_admin', 'guru_wali_ringkasan');
  check('T6 menggantung 0', r.json.penugasan_menggantung === 0, brief(r));
  sql("UPDATE students SET status='Aktif' WHERE id='s_gw_3'");
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['s_gw_3'], tanggal_mulai: D0 });
  check('T6 mulai = selesai sebelumnya -> 400 tumpang tindih', r.status === 400 && /akhir penugasan sebelumnya/.test(r.json?.error || ''), brief(r));

  // ---- T7 Kuota ----
  r = await get('uji_gw_admin', 'guru_wali_ringkasan');
  const nGw1 = r.json.guru.find((g) => g.uid === 'uji_gw_1').jumlah_dampingan;
  r = await call('uji_gw_admin', 'save_system_config', { method: 'POST', body: { guru_wali_kuota_peringatan: String(nGw1) } });
  check('T7 set kuota -> 200', r.status === 200, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_1', student_ids: ['s_gw_7'], tanggal_mulai: D_5 });
  check('T7 melebihi kuota -> 200 + peringatan', r.status === 200 && r.json.dibuat === 1 && r.json.peringatan_kuota?.jumlah === nGw1 + 1, brief(r));
  r = await get('uji_gw_admin', 'guru_wali_ringkasan');
  check('T7 ringkasan tandai melebihi_kuota', r.json.guru.find((g) => g.uid === 'uji_gw_1').melebihi_kuota === true, brief(r));
  r = await call('uji_gw_admin', 'save_config', { method: 'POST', body: { config_key: 'guru_wali_kuota_peringatan', config_value: '5' } });
  check('T7 save_config kuota -> 400', r.status === 400, brief(r));
  for (const v of [0, 101, 'abc']) {
    r = await call('uji_gw_admin', 'save_system_config', { method: 'POST', body: { guru_wali_kuota_peringatan: v } });
    check(`T7 save_system_config kuota ${v} -> 400`, r.status === 400, brief(r));
  }
  r = await call('uji_gw_ks', 'save_system_config', { method: 'POST', body: { guru_wali_kuota_peringatan: 10 } });
  check('T7 KS ubah kuota -> 403', r.status === 403, brief(r));
  r = await get('uji_gw_admin', 'get_config');
  check('T7 get_config memuat kuota efektif', r.status === 200 && r.json.guru_wali_kuota_peringatan === String(nGw1), brief(r));

  // ---- T8 Impor ----
  const guruS2Email = `${guruS2}@uji.test`;
  const barisImpor = [
    { baris: 2, nisn: '0098000066', email: 'uji_gw_2@uji.test', nip: '' },          // NISN ganda di DB
    { baris: 3, nisn: '98000005', email: 'uji_gw_2@uji.test', nip: '' },            // Lulus (NISN tanpa nol depan)
    { baris: 4, nisn: '0098000004', email: 'UJI_GW_2@uji.test ', nip: '' },         // BARU (diakhiri kemarin)
    { baris: 5, nisn: '0098000001', email: 'uji_gw_2@uji.test', nip: '' },          // GANTI dari gw1
    { baris: 6, nisn: '0098000002', email: guruS2Email, nip: '' },                  // SAMA
    { baris: 7, nisn: '0098000003', email: '', nip: '' },                           // KOSONG
    { baris: 8, nisn: '0000000000', email: 'uji_gw_2@uji.test', nip: '' },          // tidak ditemukan
    { baris: 9, nisn: '0098000009', email: 'uji_gw_tu@uji.test', nip: '' },         // bukan guru
    { baris: 10, nisn: '0098000008', email: 'uji_gw_2@uji.test', nip: '' },         // berulang
    { baris: 11, nisn: '98000008', email: 'uji_gw_4@uji.test', nip: '' },           // berulang (normalisasi)
    { baris: 12, nisn: '9.8000010E+7', email: '', nip: '19900202 202012 2 002' },  // BARU lewat NIP + notasi ilmiah
  ];
  const jmlSebelum = sql("SELECT COUNT(*) FROM guru_wali_penugasan WHERE student_id LIKE 's\\_gw\\_%'");
  r = await post('uji_gw_admin', 'guru_wali_impor', { baris: barisImpor, pratinjau: true, tanggal_mulai: D0 });
  const stImpor = (res) => Object.fromEntries((res.json?.baris || []).map((b) => [b.baris, b.status]));
  const s8 = stImpor(r);
  const harapImpor = { 2: 'GALAT', 3: 'GALAT', 4: 'BARU', 5: 'GANTI', 6: 'SAMA', 7: 'KOSONG', 8: 'GALAT', 9: 'GALAT', 10: 'GALAT', 11: 'GALAT', 12: 'BARU' };
  check('T8 pratinjau status per baris', r.status === 200 && JSON.stringify(s8) === JSON.stringify(Object.fromEntries(Object.entries(harapImpor))), `${JSON.stringify(s8)} ${brief(r)}`);
  const pesan = Object.fromEntries((r.json?.baris || []).map((b) => [b.baris, b.pesan || '']));
  check('T8 pesan galat spesifik', /lebih dari satu murid/.test(pesan[2]) && /tidak aktif/.test(pesan[3]) && /tidak ditemukan/.test(pesan[8])
    && /bukan akun guru/.test(pesan[9]) && /lebih dari sekali/.test(pesan[10]) && /lebih dari sekali/.test(pesan[11]), JSON.stringify(pesan));
  check('T8 GANTI menyebut guru lama', r.json?.baris?.find((b) => b.baris === 5)?.guru_lama_nama === 'Uji GW Satu', brief(r));
  check('T8 ringkasan', JSON.stringify(r.json?.ringkasan) === JSON.stringify({ BARU: 2, GANTI: 1, SAMA: 1, KOSONG: 1, GALAT: 6 }), JSON.stringify(r.json?.ringkasan));
  check('T8 pratinjau tidak menulis DB', sql("SELECT COUNT(*) FROM guru_wali_penugasan WHERE student_id LIKE 's\\_gw\\_%'") === jmlSebelum);
  r = await post('uji_gw_admin', 'guru_wali_impor', { baris: barisImpor, pratinjau: false, tanggal_mulai: D0 });
  check('T8 simpan tanpa ganti -> dibuat 2, diganti 0', r.status === 200 && r.json.tersimpan?.dibuat === 2 && r.json.tersimpan?.diganti === 0, brief(r));
  check('T8 GANTI dilewati (s_gw_1 tetap gw1)', aktifGuru('s_gw_1') === 'uji_gw_1');
  check('T8 s_gw_10 lewat NIP -> gw2', aktifGuru('s_gw_10') === 'uji_gw_2');
  r = await post('uji_gw_admin', 'guru_wali_impor', { baris: barisImpor, pratinjau: false, tanggal_mulai: D0, ganti: true });
  check('T8 ganti tanpa alasan -> 400', r.status === 400, brief(r));
  r = await post('uji_gw_admin', 'guru_wali_impor', { baris: barisImpor, pratinjau: false, tanggal_mulai: D0, ganti: true, alasan: 'KOREKSI_DATA' });
  check('T8 simpan dengan ganti -> diganti 1', r.status === 200 && r.json.tersimpan?.diganti === 1 && r.json.tersimpan?.dibuat === 0, brief(r));
  check('T8 s_gw_1 -> gw2', aktifGuru('s_gw_1') === 'uji_gw_2');
  check('T8 sumber IMPOR dengan impor_id', sql("SELECT COUNT(*) FROM guru_wali_penugasan WHERE student_id LIKE 's\\_gw\\_%' AND sumber='IMPOR' AND impor_id IS NULL") === '0'
    && sql("SELECT COUNT(DISTINCT impor_id) FROM guru_wali_penugasan WHERE student_id IN ('s_gw_4','s_gw_10')") === '1');
  r = await post('uji_gw_admin', 'guru_wali_impor', { baris: Array.from({ length: 1501 }, () => ({ nisn: '1' })), pratinjau: true });
  check('T8 > 1500 baris -> 400', r.status === 400, brief(r));
  r = await get('uji_gw_admin', 'guru_wali_template', { class_id: 'UJI_GW_A' });
  check('T8 template kelas A berisi email guru wali aktif', r.status === 200 && r.json.header[3] === 'Email Guru Wali'
    && r.json.baris.some((b) => b[0] === '0098000001' && b[3] === 'uji_gw_2@uji.test') && !r.json.baris.some((b) => b[0] === '0098000005'), brief(r));

  // ---- T9 Profil Murid 360° ----
  const profil = (who, sid) => get(who, 'profil_murid_get', { student_id: sid });
  r = await profil('uji_gw_1', 's_gw_7');
  check('T9 gw1 buka dampingan kelas B -> 200 DAMPINGAN baca saja tersamar', r.status === 200 && r.json.akses.mode === 'DAMPINGAN'
    && r.json.akses.bisa_edit_asesmen === false && r.json.akses.disamarkan === true && r.json.murid.nik !== '6372015503090777'
    && r.json.guru_wali?.aktif?.guru_nama === 'Uji GW Satu', brief(r));
  r = await post('uji_gw_1', 'profil_murid_asesmen_simpan', { student_id: 's_gw_7', cita_cita: 'Dokter' });
  check('T9 gw1 simpan asesmen dampingan -> 403', r.status === 403, brief(r));
  r = await profil('uji_gw_1', 's_gw_9');
  check('T9 gw1 murid bukan dampingan -> 403', r.status === 403, brief(r));
  r = await get('uji_gw_1', 'profil_murid_daftar', { class_id: 'UJI_GW_B' });
  check('T9 gw1 profil_murid_daftar -> 403', r.status === 403, brief(r));
  r = await get('uji_gw_1', 'profil_murid_daftar', { q: 'Uji GW' });
  check('T9 gw1 pencarian -> 403', r.status === 403, brief(r));
  r = await get('uji_gw_1', 'profil_murid_asesmen_template', { class_id: 'UJI_GW_B' });
  check('T9 gw1 template asesmen -> 403', r.status === 403, brief(r));
  r = await get('uji_gw_1', 'cetak_profil_murid', { student_id: 's_gw_7' });
  check('T9 gw1 cetak dampingan -> 200 memuat Guru Wali', r.status === 200 && /Guru Wali/.test(r.text) && /Uji GW Satu/.test(r.text) && !/6372015503090777/.test(r.text), r.status);
  r = await profil('uji_gw_2', 's_gw_4');
  check('T9 gw2 (wali A) buka dampingan kelas B -> 200 baca saja', r.status === 200 && r.json.akses.mode === 'CLASS' && r.json.akses.bisa_edit_asesmen === false, brief(r));
  r = await post('uji_gw_2', 'profil_murid_asesmen_simpan', { student_id: 's_gw_4', cita_cita: 'Dokter' });
  check('T9 gw2 simpan asesmen dampingan kelas lain -> 403', r.status === 403, brief(r));
  r = await profil('uji_gw_2', 's_gw_2');
  check('T9 gw2 murid kelasnya -> bisa edit', r.status === 200 && r.json.akses.bisa_edit_asesmen === true, brief(r));
  r = await post('uji_gw_2', 'profil_murid_asesmen_simpan', { student_id: 's_gw_2', cita_cita: 'Dokter' });
  check('T9 gw2 simpan asesmen murid kelasnya -> 200', r.status === 200, brief(r));
  r = await profil('uji_gw_2', 's_gw_9');
  check('T9 gw2 murid B bukan dampingan -> 403', r.status === 403, brief(r));
  r = await profil('uji_gw_3', 's_gw_9');
  check('T9 BK -> ALL, tidak tersamar', r.status === 200 && r.json.akses.mode === 'ALL' && r.json.akses.disamarkan === false, brief(r));
  r = await profil('uji_gw_admin', 's_gw_1');
  check('T9 riwayat guru wali di profil (tanpa yang dibatalkan)', r.status === 200 && r.json.guru_wali.aktif.guru_uid === 'uji_gw_2'
    && r.json.guru_wali.riwayat.length === 2 && r.json.guru_wali.riwayat[1].alasan_label === 'Koreksi data', brief(r));
  r = await post('uji_gw_admin', 'guru_wali_akhiri', { penugasan_ids: [aktifId('s_gw_7')], alasan: 'BEBAN_KERJA' });
  check('T9 akhiri penugasan s_gw_7', r.status === 200, brief(r));
  r = await profil('uji_gw_1', 's_gw_7');
  check('T9 akses hilang tanpa login ulang -> 403', r.status === 403, brief(r));

  // ---- T12 Regresi ----
  r = await get('uji_gw_1', 'get_class_grade_overview', { class_id: 'UJI_GW_B' });
  check('T12 Pantau Nilai gw1 (guru wali biasa) tetap 403', r.status === 403, brief(r));
  r = await get('uji_gw_2', 'get_class_grade_overview', { class_id: 'UJI_GW_A' });
  check('T12 Pantau Nilai gw2 wali kelas A tetap 200', r.status === 200, brief(r));
  r = await get('uji_gw_2', 'get_class_grade_overview', { class_id: 'UJI_GW_B' });
  check('T12 Pantau Nilai gw2 kelas B tetap 403', r.status === 403, brief(r));
  r = await get('uji_gw_2', 'profil_murid_daftar', { class_id: 'UJI_GW_A' });
  check('T12 wali kelas daftar kelasnya tetap 200', r.status === 200 && r.json.mode === 'CLASS', brief(r));
  r = await profil('uji_gw_4', 's_gw_1');
  check('T12 GURU tanpa dampingan/wali tetap 403', r.status === 403, brief(r));

  // ---- T11 Dashboard Kelas Saya ----
  r = await get('uji_gw_2', 'get_homeroom_dashboard', { class_id: 'UJI_GW_A' });
  const ps1 = r.json?.per_siswa?.find((p) => p.student_id === 's_gw_1');
  check('T11 dashboard login: guru_wali_nama', r.status === 200 && ps1?.guru_wali_nama === 'Uji GW Dua', brief(r));
  r = await get(null, 'get_homeroom_dashboard', { class_id: 'UJI_GW_A' });
  check('T11 dashboard tanpa login: tanpa field guru wali', r.status === 200 && !(r.json?.per_siswa || []).some((p) => 'guru_wali_nama' in p), brief(r));

  // ---- T10 Hapus / ubah peran guru ----
  r = await get('uji_gw_admin', 'delete_user', { uid: 'uji_gw_2' });
  check('T10 delete_user guru wali -> 409', r.status === 409 && r.json.jumlah_dampingan >= 1, brief(r));
  r = await get('uji_gw_admin', 'gtk_delete', { id: 'uji_gw_2' });
  check('T10 gtk_delete guru wali -> 409', r.status === 409, brief(r));
  check('T10 akun masih ada', sql("SELECT COUNT(*) FROM users WHERE uid='uji_gw_2'") === '1');
  const ubah = { uid: 'uji_gw_2', email: 'uji_gw_2@uji.test', displayName: 'Uji GW Dua', role: 'TATA_USAHA' };
  r = await post('uji_gw_admin', 'save_user_v2', ubah);
  check('T10 save_user_v2 role -> TU ditolak 409', r.status === 409, brief(r));
  r = await post('uji_gw_admin', 'save_user', ubah);
  check('T10 save_user role -> TU ditolak 409', r.status === 409, brief(r));
  check('T10 role tetap GURU', sql("SELECT role FROM users WHERE uid='uji_gw_2'") === 'GURU');
  r = await post('uji_gw_admin', 'save_user', { ...ubah, role: 'GURU' });
  check('T10 save_user tanpa ubah role -> 200', r.status === 200, brief(r));
  r = await get('uji_gw_admin', 'guru_wali_murid', { guru_uid: 'uji_gw_2' });
  const pindah = r.json.murid.map((m) => m.id);
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: pindah, tanggal_mulai: D0, ganti: true, alasan: 'MUTASI_GURU' });
  check('T10 pindahkan semua dampingan gw2 -> gw4 ditolak (mulai hari ini sama)', r.status === 400, brief(r));
  sql("UPDATE guru_wali_penugasan SET tanggal_mulai = DATE_SUB(tanggal_mulai, INTERVAL 3 DAY) WHERE guru_uid='uji_gw_2' AND status='AKTIF'");
  sql(`UPDATE guru_wali_penugasan SET tanggal_selesai = '${hari(-4)}' WHERE student_id IN ('s_gw_1','s_gw_4') AND status='SELESAI' AND tanggal_selesai >= '${hari(-3)}'`);
  r = await post('uji_gw_admin', 'guru_wali_tugaskan', { guru_uid: 'uji_gw_4', student_ids: pindah, tanggal_mulai: D0, ganti: true, alasan: 'MUTASI_GURU' });
  check('T10 pindahkan semua dampingan gw2 -> gw4', r.status === 200 && r.json.diganti === pindah.length, brief(r));
  r = await get('uji_gw_admin', 'delete_user', { uid: 'uji_gw_2' });
  check('T10 delete_user setelah dipindah -> 200', r.status === 200, brief(r));
  r = await get('uji_gw_admin', 'guru_wali_riwayat', { guru_uid: 'uji_gw_2' });
  check('T10 riwayat tetap memakai nama snapshot', r.status === 200 && r.json.total >= 1 && r.json.baris.every((b) => b.guru_nama === 'Uji GW Dua' && b.akun_dihapus), brief(r));

  // Kembalikan ambang kuota
  r = await call('uji_gw_admin', 'save_system_config', { method: 'POST', body: { guru_wali_kuota_peringatan: String(kuotaAwal) } });
  check('Pemulihan kuota', r.status === 200, brief(r));

  console.log(`\nSelesai: ${pass} lulus, ${failed} gagal`);
  process.exit(failed ? 1 : 0);
}
main().catch((e) => { console.error(e); process.exit(2); });
