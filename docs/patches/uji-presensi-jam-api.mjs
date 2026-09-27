// Uji API presensi_jam_sebelumnya (isi otomatis Sakit/Izin dari jam sebelumnya di form jurnal).
// HANYA untuk dev/staging. Jalankan uji-presensi-harian-seed.sql dulu, lalu cleanup setelahnya.
// Jalankan dari root deploy: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-presensi-jam-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const TGL = '2099-02-10';
const S1 = 's_uji_ph_1', S2 = 's_uji_ph_2';
let pass = 0, failed = 0;
const jars = {};

async function call(who, action, { method = 'GET', body = null, query = '' } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) jars[who] = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text };
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${info}`); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `uji_ph_${who}@uji.test`, password: process.env.UJI_PASSWORD || 'uji12345' } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
}
async function jurnal(id, jam, att) {
  const r = await call('guru', 'save_journal', { method: 'POST', body: {
    id, date: TGL, classId: 'k_uji_ph', className: 'X UJI PH', subjectId: 'm_uji', subjectName: `Mapel${jam}`,
    time: '07:30', jam: String(jam), topic: 'uji', activities: 'uji', attendance: att, isDraft: false,
  } });
  if (r.status !== 200) throw new Error(`save_journal ${id}: ${r.status} ${r.text.slice(0, 300)}`);
}
async function saran(jam, kelas = 'k_uji_ph', who = 'guru') {
  const r = await call(who, 'presensi_jam_sebelumnya', { query: `&class_id=${encodeURIComponent(kelas)}&date=${TGL}&jam=${encodeURIComponent(jam)}` });
  if (r.status !== 200) throw new Error(`presensi_jam_sebelumnya ${jam}: ${r.status} ${r.text}`);
  return r.json.items || {};
}
const kosong = (o) => Object.keys(o).length === 0;

for (const u of ['admin', 'wali', 'guru']) await login(u);

try {
  // --- Akses & validasi ---
  let r = await call('anon', 'presensi_jam_sebelumnya', { query: `&class_id=k_uji_ph&date=${TGL}&jam=4` });
  check('A1 tanpa login ditolak', r.status === 401, `${r.status} ${r.text}`);
  r = await call('guru', 'presensi_jam_sebelumnya', { method: 'POST', query: `&class_id=k_uji_ph&date=${TGL}&jam=4` });
  check('A2 POST ditolak 405', r.status === 405, `${r.status}`);
  r = await call('guru', 'presensi_jam_sebelumnya', { query: `&class_id=k_uji_ph&date=bukan-tanggal&jam=4` });
  check('A3 tanggal tidak valid -> kosong', r.status === 200 && kosong(r.json.items), r.text);

  // --- Jurnal jam 1-3: S1 IZIN, S2 HADIR ---
  await jurnal('j_uji_ph_pj1', '1-3', { [S1]: 'IZIN', [S2]: 'HADIR' });
  let it = await saran('4');
  check('J1 jam 4: S1 diisi IZIN dari jurnal', it[S1] && it[S1].status === 'IZIN' && it[S1].sumber === 'JURNAL', JSON.stringify(it));
  check('J2 keterangan menyebut jam & mapel', it[S1] && it[S1].keterangan === 'jam 1-3 (Mapel1-3)', JSON.stringify(it[S1]));
  check('J3 S2 (HADIR) tidak disarankan', !it[S2], JSON.stringify(it));
  check('J4 jam 1: belum ada jam sebelumnya', kosong(await saran('1')));
  check('J5 jam 2 (tumpang tindih 1-3): kosong', kosong(await saran('2')));
  check('J6 jam kosong: jurnal tidak dipakai', kosong(await saran('')));
  it = await saran('4', 'X UJI PH');
  check('J7 kelas lewat nama juga cocok', it[S1] && it[S1].status === 'IZIN', JSON.stringify(it));
  check('J8 kelas lain: kosong', kosong(await saran('4', 'k_uji_lain')));

  // --- Jam 4 S1 kembali HADIR: jam 5 tidak diisi ---
  await jurnal('j_uji_ph_pj4', '4', { [S1]: 'HADIR', [S2]: 'HADIR' });
  check('J9 jam 5: S1 sudah hadir di jam 4', !(await saran('5'))[S1]);
  it = await saran('4');
  check('J10 jam 4 tetap melihat jam 1-3', it[S1] && it[S1].status === 'IZIN', JSON.stringify(it));

  // --- Jam 5 SAKIT diteruskan, jam 6 ALFA tidak ---
  await jurnal('j_uji_ph_pj5', '5', { [S1]: 'SAKIT', [S2]: 'HADIR' });
  it = await saran('6');
  check('J11 jam 6: S1 SAKIT dari jam 5', it[S1] && it[S1].status === 'SAKIT' && it[S1].keterangan === 'jam 5 (Mapel5)', JSON.stringify(it));
  await jurnal('j_uji_ph_pj6', '6', { [S1]: 'ALFA', [S2]: 'HADIR' });
  check('J12 jam 7: ALFA tidak diteruskan', !(await saran('7'))[S1]);

  // --- Presensi MANUAL wali kelas (cadangan) ---
  r = await call('wali', 'save_attendance_records', { method: 'POST', body: { academic_year: '2098/2099', records: [
    { student_id: S2, student_name: 'Siswa Uji PH Dua', class_id: 'k_uji_ph', class_name: 'X UJI PH', date: TGL, status: 'SAKIT' },
  ] } });
  check('M0 wali menyimpan presensi manual', r.status === 200, `${r.status} ${r.text.slice(0, 200)}`);
  it = await saran('1');
  check('M1 jam 1: S2 SAKIT dari presensi wali kelas', it[S2] && it[S2].status === 'SAKIT' && it[S2].sumber === 'MANUAL', JSON.stringify(it));
  check('M2 jam 4: jurnal sebelumnya (HADIR) didahulukan', !(await saran('4'))[S2]);

  // --- Dispensasi yang mencakup jam ini didahulukan ---
  r = await call('admin', 'dispensasi_simpan', { method: 'POST', body: {
    student_ids: [S2], tanggal_mulai: TGL, tanggal_selesai: TGL, jam_ke_mulai: 1, jam_ke_selesai: 2, kegiatan: 'Lomba Uji PJ',
  } });
  check('D0 dispensasi tersimpan', r.status === 200, `${r.status} ${r.text.slice(0, 200)}`);
  check('D1 jam 1: S2 dilewati (dispensasi)', !(await saran('1'))[S2]);
} catch (e) {
  failed++;
  console.log('ERROR ' + e.message);
}

console.log(`\n${pass} lulus, ${failed} gagal`);
process.exit(failed ? 1 : 0);
