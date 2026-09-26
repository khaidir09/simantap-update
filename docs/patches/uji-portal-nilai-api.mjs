// Uji API Portal Nilai Orang Tua (docs/specs/portal-nilai-orang-tua.md §10.2).
// HANYA untuk dev/staging: skrip membuat jurnal ber-penilaian di kelas UJI, membuat/mereset PIN siswa uji,
// dan mengubah saklar portal (dikembalikan ke nilai semula di akhir). Jurnal uji dihapus di akhir.
// Prasyarat: docs/patches/uji-portal-nilai-seed.sql sudah dijalankan. Bersihkan dengan uji-portal-nilai-cleanup.sql.
// P21 memblokir IP mesin uji selama 15 menit; cleanup SQL menghapus catatannya.
// Jalankan: BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=uji12345 node docs/patches/uji-portal-nilai-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const PORTAL = BASE.replace(/api\/index\.php$/, 'nilai/');
const ORIGIN = new URL(BASE).origin;
const PASSWORD = process.env.UJI_PASSWORD || 'uji12345';
const RAHASIA = 'CATATAN_RAHASIA_PNK';
let pass = 0, failed = 0;
const jars = {};
const dibuat = [];
let saklarAwal = null;
let gagalLogin = 0; // jumlah login portal gagal (401) dari IP ini selama skrip

function simpanCookie(who, res) {
  const all = typeof res.headers.getSetCookie === 'function' ? res.headers.getSetCookie() : [res.headers.get('set-cookie')].filter(Boolean);
  for (const c of all) {
    const [pair, ...attrs] = c.split(';');
    const [name, ...rest] = pair.split('=');
    const value = rest.join('=');
    const expired = value === '' || value === 'deleted' || attrs.some((x) => /max-age=0/i.test(x) || (/expires=/i.test(x) && Date.parse(x.split('=')[1]) < Date.now()));
    jars[who] ??= {};
    if (expired) delete jars[who][name.trim()];
    else jars[who][name.trim()] = value;
  }
}
const cookieHeader = (who) => Object.entries(jars[who] || {}).map(([k, v]) => `${k}=${v}`).join('; ');

async function call(who, action, { method = 'GET', body = null, query = '', form = null, headers = {} } = {}) {
  const h = { ...headers };
  if (body) h['Content-Type'] = 'application/json';
  if (form) h['Content-Type'] = 'application/x-www-form-urlencoded';
  if (who && jars[who]) h.Cookie = cookieHeader(who);
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers: h, body: body ? JSON.stringify(body) : form ? form.toString() : undefined, redirect: 'manual' });
  if (who) simpanCookie(who, res);
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text, headers: res.headers };
}
async function portal(who, { method = 'GET', form = null, query = '' } = {}) {
  const h = {};
  if (form) h['Content-Type'] = 'application/x-www-form-urlencoded';
  if (jars[who]) h.Cookie = cookieHeader(who);
  const res = await fetch(PORTAL + query, { method, headers: h, body: form ? new URLSearchParams(form).toString() : undefined, redirect: 'manual' });
  simpanCookie(who, res);
  return { status: res.status, text: await res.text(), headers: res.headers };
}
async function masuk(who, nisn, pin) {
  const r = await portal(who, { method: 'POST', form: { aksi: 'masuk', nisn, pin } });
  if (r.status === 401) gagalLogin++;
  return r;
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${info}`); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `${who}@uji.test`, password: PASSWORD } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text.slice(0, 200)}`);
}
const q = (params) => Object.entries(params).map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('');
const status = (who, params = {}) => call(who, 'portal_nilai_status', { query: q(params) });
const saklar = (who, aktif) => call(who, 'portal_nilai_set_aktif', { method: 'POST', body: { aktif } });
function kartu(who, classId, mode, ids = [], headers = { Origin: ORIGIN, Referer: `${ORIGIN}/` }) {
  const form = new URLSearchParams({ class_id: classId, mode });
  ids.forEach((id) => form.append('student_ids[]', id));
  return call(who, 'cetak_kartu_akses', { method: 'POST', form, headers });
}
// NISN -> PIN dari halaman kartu
const bacaPin = (html) => Object.fromEntries([...html.matchAll(/<b>NISN<\/b> (\d+)<\/div>\s*<div><b>PIN<\/b> <span class="pin">([A-Z0-9]{4}-[A-Z0-9]{4})<\/span>/g)].map((m) => [m[1], m[2]]));
const pinStatus = (r, sid) => r.json.students.find((s) => s.student_id === sid)?.pin_status;

async function main() {
  for (const who of ['uji_admin', 'uji_ks', 'uji_tu', 'uji_guru', 'uji_wali', 'uji_wali2', 'uji_bk']) await login(who);

  const awal = await status('uji_admin', { class_id: 'UJI' });
  if (awal.status !== 200) throw new Error(`status awal gagal: ${awal.status} ${awal.text.slice(0, 200)}`);
  saklarAwal = awal.json.aktif;
  if (awal.json.students.some((s) => ['s_uji_1', 's_uji_2', 's_uji_3'].includes(s.student_id) && s.pin_status !== 'BELUM')) {
    throw new Error('Siswa uji sudah punya PIN. Jalankan uji-portal-nilai-cleanup.sql lalu seed ulang.');
  }

  // ---- Data nilai (catatan guru berisi penanda rahasia) ----
  const tanggal = new Date(); tanggal.setDate(tanggal.getDate() - 3);
  const tgl = tanggal.toISOString().slice(0, 10);
  const id = `j_uji_pon_${Date.now()}`;
  const nilai = (sid, tugas, kuis, notes = '') => ({ student_id: sid, student_name: sid, tugas_score: tugas, kuis_score: kuis, notes });
  const rj = await call('uji_guru', 'save_journal', { method: 'POST', body: {
    id, date: tgl, classId: 'UJI', className: 'Kelas Uji', subjectId: 'UJI_A', subjectName: 'Mapel Uji A',
    time: '07:30', topic: 'Topik portal uji', activities: 'uji', isDraft: false,
    attendance: { s_uji_1: 'HADIR', s_uji_2: 'HADIR', s_uji_3: 'SAKIT' },
    has_assessment: 1, assessment_data: JSON.stringify([nilai('s_uji_1', 80, '', RAHASIA), nilai('s_uji_2', 70, '', RAHASIA), nilai('s_uji_3', '', '')]),
  } });
  if (rj.status !== 200) throw new Error(`save_journal gagal: ${rj.status} ${rj.text.slice(0, 200)}`);
  dibuat.push(['uji_guru', id]);

  // ---- P1 anonim ----
  const a1 = await status(null, { class_id: 'UJI' });
  const a2 = await kartu(null, 'UJI', 'baru');
  const a3 = await call(null, 'portal_nilai_set_aktif', { method: 'POST', body: { aktif: true } });
  check('P1 anonim -> 401', a1.status === 401 && a2.status === 401 && a2.text.includes('<html') && a3.status === 401, `${a1.status} ${a2.status} ${a3.status}`);

  // ---- P2 peran lain ----
  for (const who of ['uji_ks', 'uji_tu', 'uji_bk']) {
    const r = await status(who, { class_id: 'UJI' });
    check(`P2 ${who} status -> 403`, r.status === 403, `${r.status}`);
  }

  // ---- P3 wali ----
  const w1 = await status('uji_wali', { class_id: 'UJI' });
  const w2 = await status('uji_wali');
  const w3 = await status('uji_wali', { class_id: 'UJI2' });
  check('P3 wali UJI / default / UJI2', w1.status === 200 && w2.status === 200 && w2.json.class.id === 'UJI' && w3.status === 403,
    `${w1.status} ${w2.status} ${w3.status}`);

  // ---- P4 saklar ----
  const s1 = await saklar('uji_wali', true);
  check('P4 wali set_aktif -> 403', s1.status === 403, `${s1.status}`);
  const s2 = await saklar('uji_admin', false);
  const s3 = await call('uji_admin', 'portal_nilai_set_aktif', { method: 'POST', body: { aktif: 'ya' } });
  check('P4 admin set_aktif -> 200; nilai non-boolean -> 400', s2.status === 200 && s3.status === 400, `${s2.status} ${s3.status}`);

  // ---- P5 status awal ----
  // NISN ganda tidak dapat dibuat (UNIQUE idx_nisn), jadi status NISN_GANDA tidak diuji lewat data
  check('P5 NISN kosong / belum / siswa pindah tidak tampil', pinStatus(w1, 's_uji_5') === 'NISN_KOSONG' && pinStatus(w1, 's_uji_6') === 'BELUM'
    && pinStatus(w1, 's_uji_1') === 'BELUM' && !w1.json.students.some((s) => s.student_id === 's_uji_4'),
    JSON.stringify(w1.json.students.map((s) => [s.student_id, s.pin_status])));
  check('P5 tanpa pin_hash', !w1.text.includes('pin_hash') && !w1.text.includes('$2y$'));
  check('P5 alamat portal', w1.json.portal_url === PORTAL, w1.json.portal_url);

  // ---- P6/P7 metode & asal ----
  const k6 = await call('uji_wali', 'cetak_kartu_akses', { query: '&class_id=UJI&mode=baru' });
  check('P6 cetak GET -> 405', k6.status === 405, `${k6.status}`);
  const k7 = await kartu('uji_wali', 'UJI', 'baru', [], { Origin: 'https://evil.example' });
  const w7 = await status('uji_wali');
  check('P7 asal lain -> 403, PIN tidak dibuat', k7.status === 403 && pinStatus(w7, 's_uji_1') === 'BELUM', `${k7.status}`);

  // ---- P8 buat PIN ----
  const k8 = await kartu('uji_wali', 'UJI', 'baru');
  const pins = bacaPin(k8.text);
  check('P8 4 kartu (s1-s3, s6), tanpa s5 NISN kosong', k8.status === 200 && Object.keys(pins).sort().join() === '9900000001,9900000002,9900000003,9900000006'
    && !k8.text.includes('Uji Siswa Lima'), `${k8.status} ${JSON.stringify(pins)}`);
  check('P8 kartu memuat alamat portal & tanpa cache', k8.text.includes(PORTAL) && /no-store/.test(k8.headers.get('cache-control') || ''));
  const w8 = await status('uji_wali');
  check('P8 status AKTIF', ['s_uji_1', 's_uji_2', 's_uji_3'].every((sid) => pinStatus(w8, sid) === 'AKTIF'));

  // ---- P9 mode baru lagi ----
  const k9 = await kartu('uji_wali', 'UJI', 'baru');
  check('P9 tidak ada yang perlu dibuatkan', k9.status === 200 && k9.text.includes('Tidak ada siswa yang perlu dibuatkan PIN'));

  // ---- P10 siswa kelas lain ----
  const k10 = await kartu('uji_wali', 'UJI', 'reset', ['s_uji2_1']);
  const w10 = await status('uji_admin', { class_id: 'UJI2' });
  check('P10 siswa kelas lain dilewati', k10.status === 200 && !k10.text.includes('9900000021') && pinStatus(w10, 's_uji2_1') === 'BELUM', `${k10.status}`);

  // ---- P11 portal nonaktif ----
  const p11a = await portal('ortu');
  const p11b = await masuk('ortu', '9900000001', pins['9900000001']);
  check('P11 portal nonaktif -> 503', p11a.status === 503 && p11b.status === 503 && p11a.text.includes('belum dibuka'), `${p11a.status} ${p11b.status}`);
  await saklar('uji_admin', true);

  // ---- P12 pesan generik ----
  const p12a = await masuk('ortu', '9900000001', 'AAAA-AAAA');
  const p12b = await masuk('ortu', '9900009999', 'AAAA-AAAA');
  check('P12 PIN salah & NISN tak dikenal -> 401 generik', p12a.status === 401 && p12b.status === 401
    && p12a.text.includes('NISN atau PIN salah.') && p12b.text.includes('NISN atau PIN salah.'), `${p12a.status} ${p12b.status}`);

  // ---- P13 kunci per NISN ----
  for (let i = 0; i < 4; i++) await masuk('ortu', '9900000001', 'BBBB-BBBB'); // total 5 gagal untuk s1
  const p13 = await masuk('ortu', '9900000001', pins['9900000001']);
  const w13 = await status('uji_wali');
  check('P13 terkunci -> 429 & status TERKUNCI', p13.status === 429 && p13.text.includes('Terlalu banyak percobaan') && pinStatus(w13, 's_uji_1') === 'TERKUNCI',
    `${p13.status} ${pinStatus(w13, 's_uji_1')}`);

  // ---- P14 reset membuka kunci ----
  const k14 = await kartu('uji_wali', 'UJI', 'reset', ['s_uji_1']);
  const pinBaru1 = bacaPin(k14.text)['9900000001'];
  const p14 = await masuk('ortu', '9900000001', pinBaru1.toLowerCase().replace('-', ' '));
  check('P14 reset -> login PIN baru 303 + cookie', pinBaru1 && pinBaru1 !== pins['9900000001'] && p14.status === 303 && !!jars.ortu?.simantap_nilai,
    `${p14.status} ${JSON.stringify(jars.ortu)}`);
  const p14lama = await masuk('ortu2', '9900000001', pins['9900000001']);
  check('P14 PIN lama tidak berlaku', p14lama.status === 401, `${p14lama.status}`);

  // ---- P15 halaman nilai ----
  const p15 = await portal('ortu');
  check('P15 halaman nilai s1', p15.status === 200 && ['Uji Siswa Satu', 'Ringkasan Semester', 'Tugas/Kuis yang Belum Ada Nilainya',
    'Nilai per Mata Pelajaran', 'Mapel Uji A', 'Lihat per pertemuan', 'Topik portal uji'].every((t) => p15.text.includes(t)), `${p15.status}`);
  check('P15 tanpa siswa lain & catatan guru', !['Uji Siswa Dua', 'Uji Siswa Tiga', 'Uji Siswa Enam', RAHASIA, 'pas_foto'].some((t) => p15.text.includes(t)));

  // ---- P16 semester ----
  const p16 = await portal('ortu', { query: '?semester=2' });
  check('P16 semester Genap', p16.status === 200 && p16.text.includes('Ringkasan Semester Genap'), `${p16.status}`);

  // ---- P22 header keamanan ----
  const hdr = p15.headers;
  check('P22 header keamanan', /frame-ancestors 'none'/.test(hdr.get('content-security-policy') || '') && hdr.get('x-frame-options') === 'DENY'
    && /no-store/.test(hdr.get('cache-control') || '') && /noindex/.test(hdr.get('x-robots-tag') || ''));
  const setC = p14.headers.get('set-cookie') || '';
  check('P22 cookie HttpOnly, SameSite, path /nilai/', /HttpOnly/i.test(setC) && /SameSite=Lax/i.test(setC) && /path=[^;]*\/nilai\//i.test(setC), setC);

  // ---- P17 reset membatalkan sesi ----
  const p17a = await masuk('ortu3', '9900000002', pins['9900000002']);
  const p17b = await portal('ortu3');
  await kartu('uji_wali', 'UJI', 'reset', ['s_uji_2']);
  const p17c = await portal('ortu3');
  check('P17 sesi s2 batal setelah reset', p17a.status === 303 && p17b.text.includes('Uji Siswa Dua') && p17c.text.includes('name="nisn"') && !p17c.text.includes('Uji Siswa Dua'),
    `${p17a.status} ${p17c.status}`);

  // ---- P18 portal dinonaktifkan ----
  await saklar('uji_admin', false);
  const p18 = await portal('ortu');
  await saklar('uji_admin', true);
  const p18b = await portal('ortu');
  check('P18 nonaktif -> 503, aktif lagi -> sesi berlanjut', p18.status === 503 && p18b.status === 200 && p18b.text.includes('Uji Siswa Satu'), `${p18.status} ${p18b.status}`);

  // ---- P19 cookie dirusak ----
  const asli = jars.ortu.simantap_nilai;
  jars.ortu4 = { simantap_nilai: asli.slice(0, -1) + (asli.endsWith('a') ? 'b' : 'a') };
  const p19 = await portal('ortu4');
  check('P19 cookie rusak -> form + cookie dihapus', p19.status === 200 && p19.text.includes('name="nisn"') && !jars.ortu4.simantap_nilai, `${p19.status}`);

  // ---- P20 siswa pindah & siswa tanpa PIN ----
  const p20a = await masuk('ortu5', '9900000021', 'ABCD-2345'); // s_uji2_1 belum punya PIN
  const p20b = await masuk('ortu5', '9900000004', 'ABCD-2345'); // PIN benar (seed), siswa pindah
  check('P20 tanpa PIN / siswa pindah -> 401 generik', p20a.status === 401 && p20b.status === 401 && p20b.text.includes('NISN atau PIN salah.'),
    `${p20a.status} ${p20b.status}`);

  // ---- P23 keluar ----
  const p23 = await portal('ortu', { method: 'POST', form: { aksi: 'keluar' } });
  const p23b = await portal('ortu');
  check('P23 keluar -> 303, cookie dihapus', p23.status === 303 && !jars.ortu.simantap_nilai && p23b.text.includes('name="nisn"'), `${p23.status}`);

  // ---- P21 batas per IP (terakhir: IP diblok 15 menit) ----
  let hasil429 = null;
  for (let i = 0; i < 25 && hasil429 === null; i++) {
    const r = await masuk('ortu6', `99000090${String(i).padStart(2, '0')}`, 'CCCC-CCCC');
    if (r.status === 429) hasil429 = gagalLogin;
  }
  check('P21 percobaan setelah 20 gagal per IP -> 429', hasil429 === 20, `429 setelah ${hasil429} gagal`);
}

try {
  await main();
} catch (e) {
  failed++;
  console.log('ERROR ' + e.message);
} finally {
  for (const [who, id] of dibuat) await call(who, 'delete_journal', { query: `&id=${encodeURIComponent(id)}` });
  if (saklarAwal !== null) {
    const r = await saklar('uji_admin', saklarAwal);
    console.log(`Saklar portal dikembalikan ke ${saklarAwal ? 'aktif' : 'nonaktif'} (${r.status}).`);
  }
  console.log(`\nHasil: ${pass} lulus, ${failed} gagal`);
  console.log('P24 (regresi tahap 1): jalankan uji-pantau-nilai-api.mjs dengan seed-nya sendiri.');
  process.exit(failed ? 1 : 0);
}
