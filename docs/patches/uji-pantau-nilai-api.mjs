// Uji API Pantau Nilai Kelas (docs/specs/pantau-nilai-wali-kelas.md §10.1).
// HANYA untuk dev/staging: skrip membuat jurnal ber-penilaian dan nilai ujian di kelas uji UJI, lalu
// menghapus jurnalnya di akhir. Skrip berhenti bila kelas UJI sudah berisi jurnal saat mulai.
// Prasyarat: docs/patches/uji-pantau-nilai-seed.sql sudah dijalankan (akun uji_*, kelas UJI/UJI2,
// mapel UJI_A/UJI_B/UJI_C, siswa s_uji_*). Bersihkan dengan uji-pantau-nilai-cleanup.sql.
// T21 butuh akses SQL: isi UJI_MYSQL, mis. "C:/laragon/bin/mysql/mysql-5.7.33-winx64/bin/mysql.exe -uroot simantap".
// Tanpa UJI_MYSQL, T21 dilewati (SKIP). UJI_KEEP=1 membiarkan jurnal uji (untuk verifikasi manual §10.3).
// Jalankan: BASE=http://localhost/simantap/api/index.php UJI_PASSWORD=uji12345 node docs/patches/uji-pantau-nilai-api.mjs
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE || 'http://localhost/simantap/api/index.php';
const PASSWORD = process.env.UJI_PASSWORD || 'uji12345';
let pass = 0, failed = 0, skipped = 0;
const jars = {};
const dibuat = []; // [pemilik, id jurnal]

async function call(who, action, { method = 'GET', body = null, query = '' } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (who && jars[who]) headers.Cookie = jars[who];
  const res = await fetch(`${BASE}?action=${action}${query}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const setCookie = res.headers.get('set-cookie');
  if (who && setCookie) jars[who] = setCookie.split(';')[0];
  const text = await res.text();
  let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text, type: res.headers.get('content-type') || '' };
}
function check(id, cond, info = '') {
  if (cond) { pass++; console.log(`PASS ${id}`); }
  else { failed++; console.log(`FAIL ${id} ${info}`); }
}
async function login(who) {
  const r = await call(who, 'login', { method: 'POST', body: { email: `${who}@uji.test`, password: PASSWORD } });
  if (r.status !== 200) throw new Error(`login ${who} gagal: ${r.status} ${r.text.slice(0, 200)}`);
}
function sql(query) {
  const parts = process.env.UJI_MYSQL.split(' ').filter(Boolean);
  return execFileSync(parts[0], [...parts.slice(1), '-N', '-e', query], { encoding: 'utf8' });
}

const q = (params) => Object.entries(params).map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('');
const overview = (who, params = {}) => call(who, 'get_class_grade_overview', { query: q(params) });
const detail = (who, params) => call(who, 'get_class_subject_grade_detail', { query: q(params) });
const cetak = (who, params) => call(who, 'cetak_rekap_nilai_siswa', { query: q(params) });

let seq = 0;
async function jurnal(who, date, subjectId, assessment, extra = {}) {
  const id = `j_uji_pnk_${Date.now()}_${++seq}`;
  const r = await call(who, 'save_journal', { method: 'POST', body: {
    id, date, classId: 'UJI', className: 'Kelas Uji', subjectId, subjectName: `Mapel ${subjectId}`,
    time: '07:30', topic: `Topik uji ${seq}`, activities: 'uji', isDraft: false,
    attendance: { s_uji_1: 'HADIR', s_uji_2: 'HADIR', s_uji_3: 'HADIR' },
    has_assessment: 1, assessment_data: JSON.stringify(assessment), ...extra,
  } });
  if (r.status !== 200) throw new Error(`save_journal ${who} gagal: ${r.status} ${r.text.slice(0, 300)}`);
  dibuat.push([who, id]);
  return id;
}
const nilai = (sid, tugas, kuis, notes = '') => ({ student_id: sid, student_name: sid, tugas_score: tugas, kuis_score: kuis, notes });

async function main() {
  for (const who of ['uji_admin', 'uji_ks', 'uji_tu', 'uji_guru', 'uji_guru2', 'uji_wali', 'uji_bk', 'uji_lain']) await login(who);

  // Pengaman: kelas UJI harus kosong
  const awal = await overview('uji_admin', { class_id: 'UJI', semester: '1' });
  if (awal.status !== 200) throw new Error(`overview awal gagal: ${awal.status} ${awal.text.slice(0, 200)}`);
  const adaData = awal.json.subjects.some((s) => s.status === 'ADA_DATA');
  if (adaData) throw new Error('Kelas UJI sudah berisi jurnal/nilai ujian. Jalankan uji-pantau-nilai-cleanup.sql lalu seed ulang.');
  const semester = awal.json.semester;
  const [mulai] = awal.json.date_range || ['2026-07-01'];
  const tgl = (hari) => { const d = new Date(`${mulai}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + hari); return d.toISOString().slice(0, 10); };
  const semParam = semester === 'GENAP' ? '2' : '1';

  // ---- Data uji ----
  const XSS = '<script>alert(1)</script>';
  const A1 = await jurnal('uji_guru', tgl(30), 'UJI_A',
    [nilai('s_uji_1', 80, ''), nilai('s_uji_2', '', '', XSS), nilai('s_uji_3', 0, ''), nilai('s_uji_4', 70, '')],
    { attendance: { s_uji_1: 'HADIR', s_uji_2: 'SAKIT', s_uji_3: 'HADIR' } });
  const A2 = await jurnal('uji_guru', tgl(37), 'UJI_A', [nilai('s_uji_1', '', 90)]);
  await jurnal('uji_guru', tgl(44), 'UJI_A', [nilai('s_uji_1', 10, 10), nilai('s_uji_2', 10, 10), nilai('s_uji_3', 10, 10)], { isDraft: true });
  await jurnal('uji_guru', tgl(51), 'UJI_A', [nilai('s_uji_1', 70, 80), nilai('s_uji_2', 60, 70), nilai('s_uji_3', 50, 40)]);
  await jurnal('uji_guru2', tgl(31), 'UJI_B', [nilai('s_uji_1', 90, ''), nilai('s_uji_2', 85, ''), nilai('s_uji_3', 88, '')]);
  for (const [tipe, scores] of [['UTS', [['s_uji_1', 60], ['s_uji_2', 80]]], ['UAS', [['s_uji_1', 70]]]]) {
    const r = await call('uji_guru', 'save_ujian_score', { method: 'POST', body: {
      class_id: 'UJI', subject_id: 'UJI_A', semester, ujian_type: tipe,
      scores: scores.map(([student_id, score]) => ({ student_id, score })),
    } });
    if (r.status !== 200) throw new Error(`save_ujian_score ${tipe} gagal: ${r.status} ${r.text.slice(0, 200)}`);
  }

  // ---- T1 anonim ----
  for (const [act, fn] of [['overview', () => overview(null, { class_id: 'UJI' })], ['detail', () => detail(null, { class_id: 'UJI', subject_id: 'UJI_A' })]]) {
    const r = await fn();
    check(`T1 anonim ${act} -> 401 JSON`, r.status === 401 && r.json !== null, `${r.status}`);
  }
  const anonCetak = await cetak(null, { student_id: 's_uji_1' });
  check('T1 anonim cetak -> 401 HTML', anonCetak.status === 401 && anonCetak.type.includes('text/html'), `${anonCetak.status} ${anonCetak.type}`);

  // ---- T2 ditolak ----
  for (const who of ['uji_tu', 'uji_lain', 'uji_guru']) {
    const r = await overview(who, { class_id: 'UJI' });
    check(`T2 ${who} overview UJI -> 403`, r.status === 403, `${r.status}`);
  }

  // ---- T3 wali kelas ----
  const w1 = await overview('uji_wali', { class_id: 'UJI', semester: semParam });
  check('T3 wali overview UJI -> 200', w1.status === 200, `${w1.status} ${w1.text.slice(0, 150)}`);
  const w2 = await overview('uji_wali', { semester: semParam });
  check('T3 wali tanpa class_id -> kelas UJI', w2.status === 200 && w2.json.class.id === 'UJI', `${w2.status} ${w2.text.slice(0, 150)}`);
  const w3 = await overview('uji_wali', { class_id: 'UJI2' });
  check('T3 wali overview UJI2 -> 403', w3.status === 403, `${w3.status}`);

  // ---- T4 semua kelas ----
  for (const who of ['uji_bk', 'uji_ks', 'uji_admin']) {
    const r = await overview(who, { class_id: 'UJI2' });
    check(`T4 ${who} overview UJI2 -> 200`, r.status === 200 && r.json.students.length === 1, `${r.status}`);
  }

  // ---- T5 metode ----
  const post = await call('uji_admin', 'get_class_grade_overview', { method: 'POST', query: '&class_id=UJI', body: {} });
  check('T5 POST overview -> 405', post.status === 405, `${post.status}`);

  const ov = w1.json;
  const siswa = Object.fromEntries(ov.students.map((s) => [s.student_id, s]));
  const miss = (sid) => siswa[sid].missing;

  // ---- T6 tugas kosong vs nilai 0 ----
  check('T6 s2 tertinggal tugas A1', miss('s_uji_2').some((m) => m.journal_id === A1 && m.type === 'TUGAS'));
  check('T6 s3 nilai 0 bukan tertinggal', !miss('s_uji_3').some((m) => m.journal_id === A1 && m.type === 'TUGAS'));
  check('T6 s1 tidak tertinggal', siswa.s_uji_1.total_missing === 0, JSON.stringify(miss('s_uji_1')));

  // ---- T7 jurnal hanya kuis ----
  check('T7 A2 kuis: s2 & s3 tertinggal kuis',
    miss('s_uji_2').some((m) => m.journal_id === A2 && m.type === 'KUIS') && miss('s_uji_3').some((m) => m.journal_id === A2 && m.type === 'KUIS'));
  check('T7 A2 tidak ada tertinggal tugas', !ov.students.some((s) => s.missing.some((m) => m.journal_id === A2 && m.type === 'TUGAS')));
  check('T7 total tertinggal s2=2, s3=1', siswa.s_uji_2.total_missing === 2 && siswa.s_uji_3.total_missing === 1,
    `s2=${siswa.s_uji_2.total_missing} s3=${siswa.s_uji_3.total_missing}`);

  // ---- T8 draf diabaikan ----
  const mapelA = ov.subjects.find((s) => s.subject_id === 'UJI_A');
  check('T8 jurnal draf tidak dihitung', mapelA && mapelA.tugas_meetings === 2 && mapelA.kuis_meetings === 2,
    JSON.stringify(mapelA));

  // ---- T9 presensi sebagai keterangan ----
  const butirS2 = miss('s_uji_2').find((m) => m.journal_id === A1);
  check('T9 butir tertinggal s2 berpresensi SAKIT', butirS2 && butirS2.attendance_status === 'SAKIT', JSON.stringify(butirS2));

  // ---- T10 siswa nonaktif ----
  check('T10 s_uji_4 tidak tampil', !siswa.s_uji_4 && ov.students.length === 3, `${ov.students.length} siswa`);

  // ---- T11 mapel tanpa data ----
  const mapelC = ov.subjects.find((s) => s.subject_id === 'UJI_C');
  check('T11 UJI_C BELUM_ADA_PENILAIAN', mapelC && mapelC.status === 'BELUM_ADA_PENILAIAN', JSON.stringify(mapelC));
  check('T11 ringkasan mapel belum dinilai', ov.summary.subjects_without_data >= 1);

  // ---- T12 kesamaan angka dengan get_student_grades ----
  for (const mapel of ['UJI_A', 'UJI_B']) {
    const g = await call('uji_admin', 'get_student_grades', { query: q({ class_id: 'UJI', subject_id: mapel, semester }) });
    const beda = [];
    for (const s of g.json.summaries) {
      const x = siswa[s.student_id].grades[mapel];
      for (const f of ['avg_tugas', 'avg_kuis', 'ujian_uts', 'ujian_uas', 'final_score', 'status']) {
        if (x[f] !== s[f]) beda.push(`${s.student_id}.${f}: ${x[f]} vs ${s[f]}`);
      }
    }
    check(`T12 ${mapel} identik dengan get_student_grades`, g.status === 200 && beda.length === 0, beda.join('; '));
  }

  // ---- T13 nilai acuan sementara di bawah KKM ----
  const s3A = siswa.s_uji_3.grades.UJI_A;
  check('T13 s3 UJI_A sementara & di bawah KKM',
    s3A.ref_is_provisional === true && s3A.below_kkm === true && s3A.ref_score === 32.5 && siswa.s_uji_3.needs_attention === true,
    JSON.stringify(s3A));
  const s1A = siswa.s_uji_1.grades.UJI_A;
  check('T13 s1 UJI_A pakai nilai akhir', s1A.ref_is_provisional === false && s1A.ref_score === s1A.final_score, JSON.stringify(s1A));

  // ---- T14 detail mapel ----
  const d = await detail('uji_wali', { class_id: 'UJI', subject_id: 'UJI_A', semester: semParam });
  const tanggal = (d.json?.meetings || []).map((m) => m.date);
  check('T14 detail 200, 3 pertemuan terurut', d.status === 200 && tanggal.length === 3 && tanggal.join() === [...tanggal].sort().join(), tanggal.join());
  check('T14 cells sejajar meetings', d.json.students.every((s) => s.cells.length === d.json.meetings.length
    && s.cells.every((c, i) => c.journal_id === d.json.meetings[i].journal_id)));
  const dLain = await detail('uji_wali', { class_id: 'UJI', subject_id: 'TIDAK_ADA', semester: semParam });
  check('T14 mapel tak dikenal -> 200 kosong', dLain.status === 200 && dLain.json.meetings.length === 0, `${dLain.status}`);
  const dKosong = await detail('uji_wali', { class_id: 'UJI', subject_id: '' });
  check('T14 subject_id kosong -> 400', dKosong.status === 400, `${dKosong.status}`);

  // ---- T15 presensi format map (format array diuji di uji-pantau-nilai-parser.php) ----
  const selS2 = d.json.students.find((s) => s.student_id === 's_uji_2').cells[0];
  const selS1 = d.json.students.find((s) => s.student_id === 's_uji_1').cells[0];
  check('T15 presensi map terbaca', selS2.attendance_status === 'SAKIT' && selS1.attendance_status === 'HADIR', JSON.stringify([selS1, selS2]));

  // ---- T16 cetak ----
  const c1 = await cetak('uji_wali', { student_id: 's_uji_2', semester: semParam });
  check('T16 cetak s2 oleh wali -> 200 HTML', c1.status === 200 && c1.type.includes('text/html'), `${c1.status}`);
  check('T16 isi dokumen lengkap', ['Laporan Perkembangan Nilai Peserta Didik', 'Uji Siswa Dua', 'A. Nilai per Mata Pelajaran',
    'B. Tugas/Kuis yang Belum Ada Nilainya', 'C. Ringkasan Presensi Semester', 'Orang Tua/Wali', 'Uji Wali Kelas'].every((t) => c1.text.includes(t)));
  const cBk = await cetak('uji_bk', { student_id: 's_uji2_1' });
  check('T16 cetak oleh BK kelas lain -> 200', cBk.status === 200, `${cBk.status}`);

  // ---- T17 cetak di luar cakupan / nonaktif ----
  const c2 = await cetak('uji_wali', { student_id: 's_uji2_1' });
  check('T17 wali cetak siswa UJI2 -> 403', c2.status === 403 && c2.type.includes('text/html'), `${c2.status}`);
  const c3 = await cetak('uji_wali', { student_id: 's_uji_4' });
  check('T17 wali cetak siswa nonaktif -> 404', c3.status === 404, `${c3.status}`);
  const c4 = await cetak('uji_lain', { student_id: 's_uji_1' });
  check('T17 guru biasa cetak -> 403', c4.status === 403, `${c4.status}`);

  // ---- T18 escaping ----
  check('T18 catatan guru di-escape', c1.text.includes('&lt;script&gt;alert(1)&lt;/script&gt;') && !c1.text.includes(XSS));

  // ---- T19/T20 regresi endpoint lama ----
  const g19 = await call('uji_wali', 'get_student_grades', { query: q({ class_id: 'UJI', subject_id: 'UJI_A', semester }) });
  check('T19 wali get_student_grades mapel lain -> 403', g19.status === 403, `${g19.status}`);
  const s19 = await call('uji_wali', 'save_ujian_score', { method: 'POST', body: {
    class_id: 'UJI', subject_id: 'UJI_A', semester, ujian_type: 'UTS', scores: [{ student_id: 's_uji_3', score: 99 }] } });
  check('T19 wali save_ujian_score mapel lain -> 403', s19.status === 403, `${s19.status}`);
  const g20 = await call('uji_guru', 'get_student_grades', { query: q({ class_id: 'UJI', subject_id: 'UJI_A', semester }) });
  check('T20 guru pengampu get_student_grades -> 200', g20.status === 200, `${g20.status}`);

  // ---- T21 perubahan wali di DB berlaku tanpa login ulang ----
  if (!process.env.UJI_MYSQL) {
    skipped++; console.log('SKIP T21 (UJI_MYSQL tidak diisi)');
  } else {
    sql("UPDATE users SET class_homeroom = NULL WHERE uid = 'uji_wali'");
    try {
      const r = await overview('uji_wali', { class_id: 'UJI' });
      check('T21 wali dicabut -> 403 tanpa login ulang', r.status === 403, `${r.status}`);
    } finally {
      sql("UPDATE users SET class_homeroom = 'UJI' WHERE uid = 'uji_wali'");
    }
  }
}

try {
  await main();
} catch (e) {
  failed++;
  console.log('ERROR ' + e.message);
} finally {
  if (process.env.UJI_KEEP === '1') {
    console.log(`UJI_KEEP=1: ${dibuat.length} jurnal uji dibiarkan untuk verifikasi manual di browser.`);
    dibuat.length = 0;
  }
  for (const [who, id] of dibuat) {
    const r = await call(who, 'delete_journal', { query: `&id=${encodeURIComponent(id)}` });
    if (r.status !== 200) console.log(`PERINGATAN: jurnal uji ${id} gagal dihapus (${r.status})`);
  }
  if (dibuat.length) console.log(`${dibuat.length} jurnal uji dihapus. Nilai ujian & presensi uji dihapus lewat uji-pantau-nilai-cleanup.sql.`);
  console.log(`\nHasil: ${pass} lulus, ${failed} gagal, ${skipped} dilewati`);
  process.exit(failed ? 1 : 0);
}
