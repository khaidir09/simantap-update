// Uji API Kelola Guru: login terakhir, status mapel/jadwal di get_users, dan aturan ubah email.
// HANYA untuk dev/staging. Jalankan uji-kelola-guru-seed.sql dulu (akun kg_uji_*), lalu
// uji-kelola-guru-cleanup.sql setelahnya.
// Jalankan dari root deploy: BASE=http://localhost/simantap/api/index.php node docs/patches/uji-kelola-guru-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const PASS = process.env.UJI_PASSWORD || 'uji12345';
let pass = 0, failed = 0;
const jars = {};

async function call(who, action, { method = 'GET', body = null } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
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
const loginAs = (who, email, password = PASS) => call(who, 'login', { method: 'POST', body: { email, password } });
async function login(who, email) {
  const r = await loginAs(who, email);
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
}
const users = async () => (await call('admin', 'get_users')).json;
const cari = (list, uid) => list.find((u) => u.uid === uid);
// Payload save_user(_v2) dari baris get_users, dengan override
const payload = (u, o = {}) => ({ uid: u.uid, email: u.email, displayName: u.displayName, nip: u.nip || '', schoolName: u.schoolName, role: u.role, class_homeroom: u.class_homeroom || '', is_bk: u.is_bk, ...o });
const simpan = (who, body, action = 'save_user_v2') => call(who, action, { method: 'POST', body });

(async () => {
  await login('admin', 'kg_uji_admin@uji.test');
  await login('ks', 'kg_uji_ks@uji.test');
  await login('tu', 'kg_uji_tu@uji.test');
  await login('guru1', 'kg_uji_guru1@uji.test');

  // A1 login mencatat last_login_at tanpa mengubah lastLogin lama
  let d = await users();
  let g1 = cari(d, 'u_kg_uji_guru1');
  check('A1 login sukses mengisi last_login_at (hari ini)', g1 && g1.last_login_at && g1.last_login_hari === 0, JSON.stringify(g1));
  check('A1 lastLogin lama tidak berubah', g1 && String(g1.lastLogin).startsWith('2020-01-01'), g1 && g1.lastLogin);

  // A2 login gagal tidak menulis apa pun
  const salah = await loginAs('anon', 'kg_uji_guru2@uji.test', 'salah-password');
  check('A2 login password salah ditolak', salah.status === 400, String(salah.status));
  d = await users();
  check('A2 last_login_at guru2 tetap null', cari(d, 'u_kg_uji_guru2').last_login_at === null && cari(d, 'u_kg_uji_guru2').last_login_hari === null);

  // A3 field tambahan get_users
  const semuaPunyaField = d.every((u) => 'mapel_count' in u && 'jadwal_count' in u && 'last_login_at' in u && 'last_login_hari' in u && 'lastLogin' in u);
  check('A3 semua baris punya field baru + field lama', semuaPunyaField);
  g1 = cari(d, 'u_kg_uji_guru1');
  const g2 = cari(d, 'u_kg_uji_guru2'), g3 = cari(d, 'u_kg_uji_guru3');
  check('A3 guru1: 2 mapel, 5 sesi', g1.mapel_count === 2 && g1.jadwal_count === 5, `${g1.mapel_count}/${g1.jadwal_count}`);
  check('A3 guru2: 0 mapel, 0 sesi (tanpa gtk)', g2.mapel_count === 0 && g2.jadwal_count === 0, `${g2.mapel_count}/${g2.jadwal_count}`);
  check('A13 guru3: jadwal rusak -> null', g3.jadwal_count === null, String(g3.jadwal_count));

  // A4 GURU tidak boleh membaca get_users
  check('A4 GURU get_users 403', (await call('guru1', 'get_users')).status === 403);

  // A6 KS/TU tidak boleh mengubah email (field lain di body juga tidak tersimpan)
  for (const who of ['ks', 'tu']) {
    const r = await simpan(who, payload(g2, { email: `kg_uji_${who}_ubah@uji.test`, displayName: 'Nama Diubah' }));
    check(`A6 ${who.toUpperCase()} ubah email -> 403`, r.status === 403, `${r.status} ${r.text}`);
  }
  d = await users();
  check('A6 email & nama guru2 tidak berubah', cari(d, 'u_kg_uji_guru2').email === 'kg_uji_guru2@uji.test' && cari(d, 'u_kg_uji_guru2').displayName === 'Guru Dua Uji KG');

  // A7 email sama beda kapital/spasi bukan perubahan
  let r = await simpan('ks', payload(g2, { email: '  KG_UJI_GURU2@UJI.TEST ' }));
  check('A7 KS simpan dengan email sama (beda kapital) -> 200', r.status === 200 && r.json.email_berubah === false, `${r.status} ${r.text}`);

  // A8 duplikat (case-insensitive)
  r = await simpan('admin', payload(g2, { email: 'KG_UJI_GURU1@UJI.TEST' }));
  check('A8 email milik akun lain -> 409', r.status === 409, `${r.status} ${r.text}`);

  // A9 format tidak valid
  r = await simpan('admin', payload(g2, { email: 'bukan-email' }));
  check('A9 format email salah -> 400', r.status === 400, `${r.status} ${r.text}`);

  // A10 akun baru dengan email terpakai
  r = await simpan('admin', { uid: 'u_kg_uji_baru', email: 'kg_uji_guru3@uji.test', displayName: 'Baru', role: 'GURU' });
  check('A10 buat akun dengan email terpakai -> 409 (bukan 500)', r.status === 409, `${r.status} ${r.text}`);

  // A5 ADMIN ubah email guru1
  r = await simpan('admin', payload(g1, { email: 'kg_uji_guru1b@uji.test' }));
  check('A5 ADMIN ubah email -> 200, email_berubah', r.status === 200 && r.json.email_berubah === true, `${r.status} ${r.text}`);
  check('A5 login email baru berhasil', (await loginAs('x1', 'kg_uji_guru1b@uji.test')).status === 200);
  check('A5 login email lama gagal', (await loginAs('x2', 'kg_uji_guru1@uji.test')).status !== 200);
  const gtk = (await call('admin', 'gtk_list')).json || [];
  check('A5 gtk_pegawai.email ikut berubah', (gtk.find((x) => x.id === 'u_kg_uji_guru1') || {}).email === 'kg_uji_guru1b@uji.test');
  let reg = ((await call('admin', 'get_teacher_registry')).json || []).map((x) => x.email);
  check('A5 teacher_registry dipindah ke email baru', reg.includes('kg_uji_guru1b@uji.test') && !reg.includes('kg_uji_guru1@uji.test'));

  // A11 save_user (v1) memakai aturan yang sama
  r = await simpan('ks', payload(g2, { email: 'kg_uji_guru2b@uji.test' }), 'save_user');
  check('A11 v1: KS ubah email -> 403', r.status === 403, `${r.status} ${r.text}`);
  r = await simpan('admin', payload(g2, { email: 'kg_uji_guru2b@uji.test' }), 'save_user');
  check('A11 v1: ADMIN ubah email -> 200', r.status === 200 && r.json.email_berubah === true, `${r.status} ${r.text}`);
  reg = ((await call('admin', 'get_teacher_registry')).json || []).map((x) => x.email);
  check('A11 v1: registry email lama terhapus, baru ada', reg.includes('kg_uji_guru2b@uji.test') && !reg.includes('kg_uji_guru2@uji.test'));

  // A12 ADMIN ubah email sendiri -> sesi ikut diperbarui
  const adm = cari(await users(), 'u_kg_uji_admin');
  r = await simpan('admin', payload(adm, { email: 'kg_uji_admin2@uji.test' }));
  check('A12 ADMIN ubah email sendiri -> 200', r.status === 200, `${r.status} ${r.text}`);
  const ses = await call('admin', 'session');
  check('A12 email di sesi = email baru', ses.json && ses.json.user && ses.json.user.email === 'kg_uji_admin2@uji.test', ses.text.slice(0, 200));

  console.log(`\n${pass} lulus, ${failed} gagal`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
