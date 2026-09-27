// Uji API jadwal KBM (jadwal_kbm_get, jadwal_kbm_save, gtk_update_atribut, gtk_update).
// HANYA untuk dev/staging. Jalankan uji-jadwal-kbm-seed.sql dulu, lalu uji-jadwal-kbm-cleanup.sql setelahnya.
// Jalankan dari root deploy: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-jadwal-kbm-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const A = 'u_uji_jk_a', B = 'u_uji_jk_b', C = 'u_uji_jk_c';
const K1 = 'k_uji_jk_1', K2 = 'k_uji_jk_2', K3 = 'k_uji_jk_3', K4 = 'k_uji_jk_4';
const M1 = 'm_uji_jk_1', M2 = 'm_uji_jk_2', M0 = 'm_uji_jk_0';
const LAMA1 = { id: 'JM-lama1', hari: 'Senin', kelas: 'X UJI JK 1', jamKe: '01 - 02 (07.30 - 08.50)', ruang: 'R1' };
const LAMA2 = { id: 'JM-lama2', hari: 'Selasa', kelas: 'X UJI JK 2', jamKe: 'pagi', ruang: 'R2' };
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
  else { failed++; console.log(`FAIL ${id} ${typeof info === 'string' ? info : JSON.stringify(info)}`); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `uji_jk_${who}@uji.test`, password: process.env.UJI_PASSWORD || 'uji12345' } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
}
const get = (who, gtk = null) => call(who, 'jadwal_kbm_get', { query: gtk ? `&gtk_id=${gtk}` : '' });
const save = (who, gtk_id, versi, items) => call(who, 'jadwal_kbm_save', { method: 'POST', body: { gtk_id, versi, items } });
// Item respons -> item tersimpan apa adanya (buang field turunan respons).
const bersih = (it) => { const { efektif, legacy, perluDilengkapi, alasan, ...rest } = it; return rest; };
const sesi = (extra) => ({ hari: 'Senin', classId: K2, subjectCode: M1, jamKeMulai: 3, jamKeSelesai: 4, jamMulai: '08:50', jamSelesai: '10:10', ...extra });
const sama = (x, y) => JSON.stringify(Object.entries(x).sort()) === JSON.stringify(Object.entries(y).sort());

async function main() {
  for (const w of ['a', 'b', 'c', 'tu', 'admin', 'ks']) await login(w);

  // --- Akses baca ---
  let r = await call(null, 'jadwal_kbm_get');
  check('E17 tanpa login -> 401', r.status === 401, r.status);

  r = await get('a');
  check('A baca milik sendiri (default uid) -> 200', r.status === 200 && r.json.gtk_id === A && r.json.gtkAda === true && r.json.items.length === 2, r.text);
  const l1 = r.json.items.find((i) => i.id === 'JM-lama1');
  const l2 = r.json.items.find((i) => i.id === 'JM-lama2');
  check('sesi lama ter-parse (efektif)', l1 && l1.legacy && !l1.perluDilengkapi && l1.efektif.classId === K1
    && l1.efektif.jamKeMulai === 1 && l1.efektif.jamKeSelesai === 2 && l1.efektif.jamMulai === '07:30' && l1.efektif.jamSelesai === '08:50', l1);
  check('sesi lama "pagi" perlu dilengkapi', l2 && l2.perluDilengkapi && l2.alasan.some((a) => a.includes('pagi')), l2);
  check('item tersimpan dikembalikan apa adanya (ruang tetap)', sama(bersih(l1), LAMA1), bersih(l1));
  let versiA = r.json.versi;

  r = await get('a', B);
  check('A baca milik B -> 403', r.status === 403, r.status);
  r = await get('c');
  check('E12 C tanpa GTK -> gtkAda false', r.status === 200 && r.json.gtkAda === false && r.json.items.length === 0, r.text);
  r = await save('c', C, 'x', []);
  check('C simpan tanpa GTK -> 400', r.status === 400 && /GTK/.test(r.json?.error), r.text);
  r = await get('ks', A);
  check('E17 KS baca A -> 200', r.status === 200 && r.json.items.length === 2, r.status);
  r = await get('tu', A);
  check('TU baca A -> 200', r.status === 200, r.status);

  // --- Akses tulis ---
  const lamaItems = [LAMA1, LAMA2];
  r = await save('ks', A, versiA, lamaItems);
  check('E17 KS simpan -> 403', r.status === 403, r.status);
  r = await call('a', 'jadwal_kbm_save', { query: '' });
  check('E16 simpan via GET -> 405', r.status === 405, r.status);
  r = await save('a', B, versiA, []);
  check('E13 A simpan milik B -> 403', r.status === 403, r.status);
  r = await save('a', A, 'versi-lama', lamaItems);
  check('E15 versi basi -> 409', r.status === 409, r.status);
  r = await save('a', A, versiA, { bukan: 'array' });
  check('items bukan array -> 400', r.status === 400, r.status);
  r = await save('a', A, versiA, Array.from({ length: 101 }, () => LAMA1));
  check('items > 100 -> 400', r.status === 400 && /100/.test(r.json?.error), r.text);

  // --- Validasi sesi baru (GURU) ---
  const tolak = async (id, extra, pola) => {
    const res = await save('a', A, versiA, [...lamaItems, sesi(extra)]);
    check(id, res.status === 400 && pola.test(res.json?.error || ''), `${res.status} ${res.text}`);
  };
  await tolak('E3 bentrok dengan sesi lama (jam ke 2-3)', { jamKeMulai: 2, jamKeSelesai: 3 }, /bentrok.*Senin jam ke-1.2 .*X UJI JK 1/i);
  await tolak('E14 kelas di luar penugasan', { classId: K4, subjectCode: '' }, /tidak termasuk penugasan/);
  await tolak('E14 mapel tidak ditugaskan untuk kelas', { classId: K1, subjectCode: M2 }, /tidak ditugaskan untuk kelas X UJI JK 1/);
  await tolak('E14 jam ke terbalik', { jamKeMulai: 5, jamKeSelesai: 3 }, /Jam ke tidak valid/);
  await tolak('jam ke 12 di luar rentang', { jamKeMulai: 10, jamKeSelesai: 12 }, /Jam ke tidak valid/);
  await tolak('E14 jam mulai >= selesai', { jamMulai: '10:00', jamSelesai: '09:00' }, /lebih awal/);
  await tolak('format jam invalid', { jamMulai: '25:00' }, /Format jam/);
  await tolak('mapel nonaktif untuk GURU', { subjectCode: M0 }, /tidak ditemukan atau tidak aktif/);
  await tolak('hari Sabtu', { hari: 'Sabtu' }, /Hari tidak valid/);
  await tolak('kelas tidak ada', { classId: 'k_tidak_ada' }, /tidak ditemukan/);
  r = await save('a', A, versiA, [...lamaItems, sesi({ jamKeMulai: 5, jamKeSelesai: 6 }), sesi({ jamKeMulai: 6, jamKeSelesai: 7, jamMulai: '11:00', jamSelesai: '12:00' })]);
  check('dua sesi baru beririsan -> 400', r.status === 400 && /bentrok/i.test(r.json?.error), r.text);
  r = await get('a');
  check('data A tidak berubah setelah penolakan', r.json.versi === versiA && r.json.items.length === 2, r.json.versi);

  // --- Simpan valid (E3) ---
  const kirimLama = (await get('a')).json.items; // dikirim lengkap dengan field respons
  r = await save('a', A, versiA, [...kirimLama, sesi({ kelas: 'PALSU', mapel: 'PALSU', jamKe: 'PALSU', ruang: 'PALSU', jamMulai: '8.50' })]);
  check('E3 simpan sesi baru -> 200', r.status === 200 && r.json.success && r.json.items.length === 3 && Array.isArray(r.json.peringatan), r.text);
  const baru = r.json.items.find((i) => !['JM-lama1', 'JM-lama2'].includes(i.id));
  check('sesi baru format terstruktur dari master', baru && /^JM-/.test(baru.id) && baru.classId === K2 && baru.kelas === 'X UJI JK 2'
    && baru.subjectCode === M1 && baru.mapel === 'Mapel Uji JK Satu' && baru.jamKeMulai === 3 && baru.jamKeSelesai === 4
    && baru.jamMulai === '08:50' && baru.jamSelesai === '10:10' && baru.jamKe === '3 - 4 (08:50 - 10:10)' && !('ruang' in baru), baru);
  const versiA2 = r.json.versi;
  check('versi berubah', versiA2 && versiA2 !== versiA);
  r = await get('a');
  check('sesi lama tersimpan identik (termasuk ruang)', sama(bersih(r.json.items.find((i) => i.id === 'JM-lama1')), LAMA1)
    && sama(bersih(r.json.items.find((i) => i.id === 'JM-lama2')), LAMA2), r.json.items.map(bersih));
  check('urutan respons Senin (1-2, 3-4) lalu Selasa', r.json.items.map((i) => i.id).join() === ['JM-lama1', baru.id, 'JM-lama2'].join(), r.json.items.map((i) => i.id));

  // Simpan ulang daftar yang sama: id dan isi tidak berubah
  r = await save('a', A, versiA2, (await get('a')).json.items.map(bersih));
  check('round-trip tanpa perubahan', r.status === 200 && r.json.items.map(bersih).every((it) => (it.id === baru.id ? sama(it, bersih(baru)) : true)), r.text);
  versiA = r.json.versi;

  // --- Peringatan antar guru (E11) & guru tanpa penugasan (E10) ---
  let rb = await get('b');
  r = await save('b', B, rb.json.versi, [sesi({ classId: K2, subjectCode: M2, jamKeMulai: 4, jamKeSelesai: 5, jamMulai: '09:30', jamSelesai: '11:00' })]);
  check('E10/E11 B tanpa penugasan simpan + peringatan', r.status === 200
    && r.json.peringatan.some((p) => p.includes('X UJI JK 2') && p.includes('Guru Uji JK A') && p.includes('Senin')), r.text);
  rb = await get('b');
  r = await save('b', B, rb.json.versi, [...rb.json.items.map(bersih), sesi({ classId: K4, subjectCode: '', hari: 'Rabu' })]);
  check('B tanpa penugasan boleh kelas mana pun, tanpa peringatan', r.status === 200 && r.json.peringatan.length === 0, r.text);

  // --- TU / ADMIN (E19, E20, E22) ---
  let rt = await get('tu', A);
  const tanpaLama2 = rt.json.items.filter((i) => i.id !== 'JM-lama2').map(bersih);
  r = await save('tu', A, rt.json.versi, [...tanpaLama2, sesi({ hari: 'Selasa', classId: K4, subjectCode: M0, jamKeMulai: 1, jamKeSelesai: 2, jamMulai: '07:30', jamSelesai: '08:50' })]);
  check('E19 TU hapus sesi lama + tambah (tanpa batas penugasan, mapel nonaktif boleh)', r.status === 200 && r.json.items.length === 3
    && !r.json.items.some((i) => i.id === 'JM-lama2'), r.text);
  r = await save('a', A, versiA, (await get('a')).json.items.map(bersih));
  check('E20 A simpan dengan versi sebelum perubahan TU -> 409', r.status === 409, r.status);

  let ra = await get('admin', B);
  r = await save('admin', B, ra.json.versi, ra.json.items.filter((i) => i.efektif.hari !== 'Rabu').map(bersih));
  check('E22 ADMIN hapus sesi B', r.status === 200 && r.json.items.length === 1, r.text);

  // --- gtk_update_atribut & gtk_update (E16, E21) ---
  r = await call('a', 'gtk_update_atribut', { method: 'POST', body: { id: A, is_bk: 1 } });
  check('E16 GURU gtk_update_atribut milik sendiri -> 403', r.status === 403, r.status);
  r = await call('a', 'gtk_update_atribut', { method: 'POST', body: { id: B, class_homeroom: 'X' } });
  check('E16 GURU gtk_update_atribut milik B -> 403', r.status === 403, r.status);
  r = await call('ks', 'gtk_update_atribut', { method: 'POST', body: { id: B, is_bk: 1 } });
  check('KS gtk_update_atribut -> 403', r.status === 403, r.status);
  r = await call('tu', 'gtk_update_atribut', { query: `&id=${B}` });
  check('TU gtk_update_atribut via GET -> 405', r.status === 405, r.status);
  r = await call('tu', 'gtk_update_atribut', { method: 'POST', body: { id: B, beban_mengajar: '24', is_bk: 0 } });
  check('TU gtk_update_atribut POST -> 200', r.status === 200, r.text);

  const sebelum = (await get('tu', A)).json;
  r = await call('tu', 'gtk_update', { method: 'POST', body: { id: A, nama: 'Guru Uji JK A', email: 'uji_jk_a@uji.test', nomor_hp: '0811', jadwal_mengajar_json: [] } });
  check('E21 TU gtk_update biodata -> 200', r.status === 200, r.text);
  const sesudah = (await get('tu', A)).json;
  check('E21 gtk_update tidak menyentuh jadwal', sesudah.versi === sebelum.versi && sesudah.items.length === sebelum.items.length, [sebelum.versi, sesudah.versi]);

  // Jalur legacy: TU (bundle lama) masih boleh menulis jadwal lewat gtk_update_atribut
  r = await call('tu', 'gtk_update_atribut', { method: 'POST', body: { id: B, jadwal_mengajar_json: [{ id: 'JM-legacy', hari: 'Kamis', kelas: 'XI UJI JK 3', jamKe: '5 (10.00 - 10.45)', ruang: 'R9' }] } });
  rb = await get('b');
  check('jalur legacy gtk_update_atribut terbaca sebagai sesi lama lengkap', r.status === 200 && rb.json.items.length === 1
    && rb.json.items[0].legacy && !rb.json.items[0].perluDilengkapi && rb.json.items[0].efektif.classId === K3, rb.text);

  console.log(`\n${pass} PASS, ${failed} FAIL`);
  process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
