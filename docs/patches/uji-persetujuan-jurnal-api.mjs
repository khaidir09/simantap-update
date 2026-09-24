// Uji API persetujuan massal jurnal bulanan (docs/specs/persetujuan-jurnal-bulanan.md §12.4).
// HANYA untuk dev/staging: skrip membuat, mengubah, memverifikasi, dan menghapus jurnal uji.
// Data uji ditaruh di bulan terisolasi TEST_MONTH (default 2020-01); skrip berhenti jika bulan itu
// sudah berisi jurnal, agar jurnal nyata sekolah tidak ikut disetujui.
// Prasyarat: akun uji_admin (ADMIN), uji_ks (KEPALA_SEKOLAH), uji_tu (TATA_USAHA), uji_guru dan
// uji_wali (GURU), email <uid>@uji.test, password UJI_PASSWORD — lihat docs/patches/persetujuan-jurnal-bulanan.md.
// Jalankan: BASE=... TEST_MONTH=2020-01 UJI_PASSWORD=... node docs/patches/uji-persetujuan-jurnal-api.mjs
const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const TEST_MONTH = process.env.TEST_MONTH || '2020-01';
let pass = 0, failed = 0;
const jars = {};
const dibuat = new Set(); // id jurnal uji, dihapus di akhir

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
  else { failed++; console.log(`FAIL ${id} ${info}`); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `${who}@uji.test`, password: process.env.UJI_PASSWORD || 'uji12345' } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text}`);
  return r.json.user;
}
let seq = 0;
async function buatJurnal(who, date, extra = {}) {
  const id = `j_uji_setuju_${Date.now()}_${++seq}`;
  const r = await call(who, 'save_journal', { method: 'POST', body: {
    id, date, classId: 'UJI', className: 'Kelas Uji', subjectId: 'UJI', subjectName: 'Mapel Uji',
    time: '07:30', topic: `Topik uji ${seq}`, activities: 'uji', attendance: {}, isDraft: false, isVerified: false, ...extra,
  } });
  if (r.status !== 200) throw new Error(`save_journal ${who} gagal: ${r.status} ${r.text.slice(0, 300)}`);
  dibuat.add(id);
  return id;
}
const preview = (who, month) => call(who, 'preview_journal_month_approval', { query: `&month=${encodeURIComponent(month)}` });
const approve = (who, month, ids) => call(who, 'approve_journals_month', { method: 'POST', body: { month, ids } });
async function jurnalMap() {
  const r = await call('uji_ks', 'get_journals');
  return new Map((r.json || []).map((j) => [j.id, j]));
}
const tgl = (d) => `${TEST_MONTH}-${String(d).padStart(2, '0')}`;

const akun = ['uji_admin', 'uji_ks', 'uji_tu', 'uji_guru', 'uji_wali'];
const uid = {};
for (const u of akun) uid[u] = (await login(u)).uid;

try {
  // T0 bulan uji harus kosong
  let r = await preview('uji_ks', TEST_MONTH);
  if (r.status !== 200) throw new Error(`preview T0 gagal: ${r.status} ${r.text}`);
  if (r.json.eligible.count || r.json.alreadyVerified || r.json.excluded.draft || r.json.excluded.futureDated) {
    console.error(`BERHENTI: ${TEST_MONTH} sudah berisi jurnal. Pilih TEST_MONTH lain.`);
    process.exit(2);
  }
  check('T0 bulan uji kosong', true);

  // T1 data uji
  const guruIds = [await buatJurnal('uji_guru', tgl(5)), await buatJurnal('uji_guru', tgl(6)), await buatJurnal('uji_guru', tgl(7))];
  const waliIds = [await buatJurnal('uji_wali', tgl(5)), await buatJurnal('uji_wali', tgl(8))];
  const drafId = await buatJurnal('uji_guru', tgl(9), { isDraft: true });
  check('T1 data uji dibuat', true);

  // T2 guru tidak bisa memverifikasi sendiri lewat body
  const palsuId = await buatJurnal('uji_guru', tgl(10), { isVerified: true, verifiedBy: 'uji_guru', verifiedAt: '2020-01-10 10:00:00' });
  let jm = await jurnalMap();
  check('T2 isVerified dari body diabaikan', jm.get(palsuId) && jm.get(palsuId).isVerified === false && !jm.get(palsuId).verifiedBy, JSON.stringify(jm.get(palsuId) && { v: jm.get(palsuId).isVerified, by: jm.get(palsuId).verifiedBy }));

  // T3 preview
  r = await preview('uji_ks', TEST_MONTH);
  const pv = r.json;
  const layak = [...guruIds, palsuId, ...waliIds];
  const bt = Object.fromEntries((pv.eligible.byTeacher || []).map((g) => [g.teacherId, g.count]));
  check('T3 preview jumlah layak 6', r.status === 200 && pv.eligible.count === 6 && layak.every((id) => pv.eligible.ids.includes(id)), r.text.slice(0, 300));
  check('T3b draf dikecualikan', pv.excluded.draft === 1 && !pv.eligible.ids.includes(drafId));
  check('T3c rincian per guru', bt[uid.uji_guru] === 4 && bt[uid.uji_wali] === 2 && pv.eligible.byTeacher.length === 2, JSON.stringify(pv.eligible.byTeacher));
  check('T3d rentang bulan lampau sampai akhir bulan', pv.start === `${TEST_MONTH}-01` && pv.isCurrentMonth === false && pv.cutoff.startsWith(TEST_MONTH), `${pv.start} ${pv.cutoff}`);

  // T4 otorisasi
  check('T4 preview TU 403', (await preview('uji_tu', TEST_MONTH)).status === 403);
  check('T4 preview GURU 403', (await preview('uji_guru', TEST_MONTH)).status === 403);
  check('T4 preview tanpa sesi 401', (await preview(null, TEST_MONTH)).status === 401);
  check('T4 approve TU 403', (await approve('uji_tu', TEST_MONTH, pv.eligible.ids)).status === 403);
  check('T4 approve GURU 403', (await approve('uji_guru', TEST_MONTH, pv.eligible.ids)).status === 403);
  check('T4 approve tanpa sesi 401', (await approve(null, TEST_MONTH, pv.eligible.ids)).status === 401);

  // T5 validasi bulan
  const d = new Date();
  const bulanDepan = new Date(d.getFullYear(), d.getMonth() + 1, 1);
  const ymDepan = `${bulanDepan.getFullYear()}-${String(bulanDepan.getMonth() + 1).padStart(2, '0')}`;
  for (const m of ['2020-13', '2020-1', 'abc', '', ymDepan]) {
    const rr = await preview('uji_ks', m);
    check(`T5 preview month="${m}" 400`, rr.status === 400, `${rr.status} ${rr.text}`);
  }
  check('T5b approve bulan depan 400', (await approve('uji_ks', ymDepan, ['x'])).status === 400);

  // T6 jurnal baru setelah preview
  const baruId = await buatJurnal('uji_wali', tgl(11));

  // T7 verifikasi satuan oleh admin di sela waktu
  r = await call('uji_admin', 'verify_journal', { method: 'POST', body: { id: guruIds[0] } });
  check('T7 admin verifikasi satuan', r.status === 200, r.text);

  // T8 approve dengan id preview + id asing
  r = await approve('uji_ks', TEST_MONTH, [...pv.eligible.ids, 'j_tidak_ada_xyz']);
  check('T8 approve: 5 disetujui, 2 dilewati', r.status === 200 && r.json.approved === 5 && r.json.skipped === 2 && !!r.json.batchId, r.text.slice(0, 300));
  const hasilT8 = r.json;

  // T9 status di DB
  jm = await jurnalMap();
  const disetujui = layak.filter((id) => id !== guruIds[0]);
  const semuaKs = disetujui.every((id) => jm.get(id).isVerified && jm.get(id).verifiedBy === uid.uji_ks);
  const waktuSama = new Set(disetujui.map((id) => jm.get(id).verifiedAt)).size === 1;
  check('T9 5 jurnal verifiedBy uji_ks, verifiedAt sama', semuaKs && waktuSama && hasilT8.approvedIds.length === 5);
  check('T9b verifikasi satuan admin tidak ditimpa', jm.get(guruIds[0]).verifiedBy === uid.uji_admin);
  check('T9c jurnal baru & draf tetap pending', jm.get(baruId).isVerified === false && jm.get(drafId).isVerified === false);

  // T10 idempoten
  r = await approve('uji_ks', TEST_MONTH, pv.eligible.ids);
  check('T10 approve ulang: 0, tanpa batch', r.status === 200 && r.json.approved === 0 && !r.json.batchId, r.text.slice(0, 200));

  // T11 / T12 kunci jurnal terverifikasi untuk GURU
  r = await call('uji_guru', 'save_journal', { method: 'POST', body: { id: guruIds[1], date: tgl(6), classId: 'UJI', className: 'Kelas Uji', subjectId: 'UJI', subjectName: 'Mapel Uji', topic: 'ubah', isDraft: false } });
  check('T11 guru edit jurnal terverifikasi 403', r.status === 403, `${r.status} ${r.text}`);
  r = await call('uji_guru', 'delete_journal', { method: 'DELETE', query: `&id=${guruIds[1]}` });
  check('T12 guru hapus jurnal terverifikasi 403', r.status === 403, `${r.status} ${r.text}`);

  // T13 admin edit jurnal terverifikasi: status dipertahankan
  const sebelum = (await jurnalMap()).get(guruIds[2]);
  r = await call('uji_admin', 'save_journal', { method: 'POST', body: { id: guruIds[2], date: tgl(7), classId: 'UJI', className: 'Kelas Uji', subjectId: 'UJI', subjectName: 'Mapel Uji', topic: 'Diedit admin', isDraft: false, isVerified: false } });
  const sesudah = (await jurnalMap()).get(guruIds[2]);
  check('T13 admin edit, verifikasi dipertahankan', r.status === 200 && sesudah.isVerified === true && sesudah.verifiedBy === sebelum.verifiedBy && sesudah.verifiedAt === sebelum.verifiedAt && sesudah.topic === 'Diedit admin', r.text);

  // T14 verify_journal satuan
  check('T14 verifikasi draf 400', (await call('uji_ks', 'verify_journal', { method: 'POST', body: { id: drafId } })).status === 400);
  check('T14b verifikasi id tidak ada 404', (await call('uji_ks', 'verify_journal', { method: 'POST', body: { id: 'j_tidak_ada_xyz' } })).status === 404);
  const vAt = (await jurnalMap()).get(guruIds[1]).verifiedAt;
  r = await call('uji_admin', 'verify_journal', { method: 'POST', body: { id: guruIds[1] } });
  const setelahV = (await jurnalMap()).get(guruIds[1]);
  check('T14c verifikasi ulang idempoten', r.status === 200 && setelahV.verifiedAt === vAt && setelahV.verifiedBy === uid.uji_ks);

  // T15 ids kosong / tidak valid
  check('T15 approve ids kosong 400', (await approve('uji_ks', TEST_MONTH, [])).status === 400);
  check('T15b approve ids bukan array 400', (await approve('uji_ks', TEST_MONTH, 'j_x')).status === 400);

  // T16 bulan berjalan: jurnal bertanggal setelah hari ini tidak ikut (read-only)
  const ymKini = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  r = await preview('uji_ks', ymKini);
  const cutoff = r.json && r.json.cutoff;
  const besok = cutoff ? new Date(new Date(`${cutoff}T00:00:00Z`).getTime() + 86400000).toISOString().slice(0, 10) : '';
  if (r.status === 200 && r.json.isCurrentMonth && besok.startsWith(ymKini)) {
    const futId = await buatJurnal('uji_guru', besok);
    const r2 = await preview('uji_ks', ymKini);
    check('T16 jurnal besok tidak layak', !r2.json.eligible.ids.includes(futId) && r2.json.excluded.futureDated === r.json.excluded.futureDated + 1, JSON.stringify(r2.json.excluded));
    const del = await call('uji_guru', 'delete_journal', { method: 'DELETE', query: `&id=${futId}` });
    if (del.status === 200) dibuat.delete(futId);
  } else {
    console.log(`SKIP T16 (hari ini akhir bulan atau preview gagal: ${r.status})`);
  }

  // T17 approve paralel
  for (let i = 0; i < 3; i++) await buatJurnal('uji_wali', tgl(20 + i));
  const pv17 = (await preview('uji_ks', TEST_MONTH)).json;
  const [a1, a2] = await Promise.all([approve('uji_ks', TEST_MONTH, pv17.eligible.ids), approve('uji_admin', TEST_MONTH, pv17.eligible.ids)]);
  const total = (a1.json ? a1.json.approved : -99) + (a2.json ? a2.json.approved : -99);
  const irisan = (a1.json.approvedIds || []).filter((id) => (a2.json.approvedIds || []).includes(id));
  check('T17 approve paralel: tiap jurnal tepat sekali', a1.status === 200 && a2.status === 200 && total === pv17.eligible.count && pv17.eligible.count === 4 && irisan.length === 0, `${a1.text.slice(0, 120)} | ${a2.text.slice(0, 120)}`);

  // T18–T21 kepemilikan
  const milikWali = await buatJurnal('uji_wali', tgl(25));
  r = await call('uji_guru', 'save_journal', { method: 'POST', body: { id: milikWali, date: tgl(25), classId: 'UJI', className: 'Kelas Uji', subjectId: 'UJI', subjectName: 'Mapel Uji', topic: 'DIBAJAK', isDraft: false } });
  jm = await jurnalMap();
  check('T18 guru edit jurnal guru lain 403', r.status === 403 && jm.get(milikWali).teacherId === uid.uji_wali && jm.get(milikWali).topic !== 'DIBAJAK', `${r.status} ${r.text}`);
  r = await call('uji_guru', 'delete_journal', { method: 'DELETE', query: `&id=${milikWali}` });
  check('T19 guru hapus jurnal guru lain 403', r.status === 403 && (await jurnalMap()).has(milikWali), `${r.status} ${r.text}`);
  r = await call('uji_wali', 'save_journal', { method: 'POST', body: { id: milikWali, date: tgl(25), classId: 'UJI', className: 'Kelas Uji', subjectId: 'UJI', subjectName: 'Mapel Uji', topic: 'Revisi wali', isDraft: false } });
  check('T20 pemilik edit jurnal sendiri', r.status === 200 && (await jurnalMap()).get(milikWali).topic === 'Revisi wali', r.text);
  const milikWali2 = await buatJurnal('uji_wali', tgl(26));
  r = await call('uji_wali', 'delete_journal', { method: 'DELETE', query: `&id=${milikWali2}` });
  check('T20b pemilik hapus jurnal sendiri', r.status === 200 && !(await jurnalMap()).has(milikWali2), r.text);
  if (r.status === 200) dibuat.delete(milikWali2);
  r = await call('uji_ks', 'delete_journal', { method: 'DELETE', query: `&id=${milikWali}` });
  check('T21 KS hapus jurnal guru', r.status === 200 && !(await jurnalMap()).has(milikWali), r.text);
  if (r.status === 200) dibuat.delete(milikWali);
} finally {
  // Bersih-bersih: ADMIN boleh menghapus jurnal terverifikasi milik siapa pun
  let gagal = 0;
  for (const id of dibuat) {
    const r = await call('uji_admin', 'delete_journal', { method: 'DELETE', query: `&id=${id}` });
    if (r.status !== 200) gagal++;
  }
  console.log(`\nBersih: ${dibuat.size - gagal}/${dibuat.size} jurnal uji dihapus.`);
  console.log(`Log batch uji dibersihkan manual (harus 2 baris sebelum dihapus):`);
  console.log(`  SELECT COUNT(*) FROM journal_approval_batches WHERE month = '${TEST_MONTH}';`);
  console.log(`  DELETE FROM journal_approval_batches WHERE month = '${TEST_MONTH}';`);
}

console.log(`\n${pass} PASS, ${failed} FAIL`);
process.exit(failed ? 1 : 0);
